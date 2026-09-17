<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

/**
 * The single PDF report builder behind every module.
 *
 * Every module prints through resources/views/pdf/record-template.blade.php,
 * so the colours, fonts, spacing and borders can never drift apart between
 * modules: a change to the look is a change in one file. What differs per
 * module is only its data - which fields it owns, which attribute names the
 * record, and whether it keeps repeated sessions or visits at all.
 *
 * Column structure and value formatting still come from the module's
 * AbstractTableExport, so the PDF and the spreadsheet can never disagree about
 * what a field is called or how a value is written.
 *
 * The document is written to mPDF one record at a time rather than as a single
 * string. Handing it the whole report at once failed outright past a few
 * hundred records - "The HTML code size is larger than pcre.backtrack_limit" -
 * because mPDF parses the markup with PCRE, and the size at which it broke
 * depended on the server's php.ini rather than on anything the report knew
 * about. Writing per record also keeps only one record's markup in memory
 * instead of the entire report.
 *
 * Past a few hundred records the mPDF document itself became the problem: it
 * holds every page it has laid out until it is asked for the file, so a report
 * of two and a half thousand children was one object growing without bound.
 * So a long report is not one document at all. It is written as a run of PDFs
 * of CHUNK records each, every part closed onto disk before the next is
 * started, and the parts are delivered as one ZIP. A report that fits in a
 * single part still downloads as the single PDF it always did, under the
 * filename it always had.
 *
 * What none of that could fix is how long the report takes. mPDF lays out a
 * record in something like 70-120ms depending on how many fields the module
 * owns, and that cost is flat - it does not grow with the document, and it
 * does not fall when the parts are made smaller. Two thousand children is
 * therefore minutes of arithmetic however it is packaged, and the report used
 * to spend all of it before the browser was sent a single byte: the download
 * was returned from a Filament action, and Livewire answers an action by
 * buffering the whole response with ob_start(), base64-encoding it and posting
 * it back inside the JSON of the XHR. The user saw a spinner and no file.
 *
 * So the click no longer carries the report. It resolves which records are in
 * scope, parks that list under a one-time ticket and sends the browser to a
 * plain GET route, which streams the archive as it is built: a part is written,
 * pushed into the ZIP, deleted, and the next one started. The browser has a
 * real download from the first part onwards instead of an idle connection.
 *
 * @see \App\Exports\PdfExportTicket                     the parked record list
 * @see \App\Http\Controllers\PdfExportDownloadController the GET route
 */
class PdfExport
{
    /**
     * Records per PDF part. A report of this many or fewer is one PDF and
     * downloads exactly as it used to; a longer one is split at this
     * boundary and the parts are zipped.
     *
     * The split is by record count rather than by file size so the parts are
     * deterministic: the same report always splits in the same places, and a
     * part covers a range that can be named on the file itself.
     */
    public const CHUNK = 500;

    /**
     * Records fetched per round trip.
     *
     * Small enough that a block of records and their relations is a bounded
     * amount of memory, large enough that a report of thousands is tens of
     * queries rather than thousands. Unrelated to CHUNK, which is about how
     * the finished PDF is cut up.
     */
    private const READ = 200;

    /**
     * Park the report and send the browser to the route that streams it.
     *
     * This is what a module's PDF button returns. The heavy work deliberately
     * does not happen here: an action's return value goes back through
     * Livewire, which would buffer and base64 the whole archive into the XHR,
     * and no part of the file would reach the browser until the last record
     * had been laid out.
     *
     * The query is resolved to a list of primary keys now, while the user's
     * filters, search and active tab are still on the component. The route
     * that follows needs no knowledge of any of that - it prints the records
     * on the list, in the order the listing had them.
     *
     * @param  string  $ability  the permission the route re-checks before it
     *         prints anything; the caller has already checked it here.
     * @param  class-string|null  $section  a class with a static section()
     *         describing the module's repeated visits or sessions, for the two
     *         modules that keep them. Named rather than passed as the array
     *         itself because the array holds a closure, and the ticket has to
     *         survive a round trip through the cache.
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     *         Deliberately not narrowed to a RedirectResponse. This is called
     *         from a Livewire action, and Livewire binds its own redirector
     *         there - one that records the redirect as an effect on the
     *         component and hands itself back rather than a response - so the
     *         browser is told to go and collect the report by the same
     *         round trip that asked for it.
     */
    public static function start(
        AbstractTableExport $export,
        string $ability,
        string $filename,
        string $title,
        string $nameField,
        ?string $section = null,
    ) {
        return redirect()->route('exports.pdf', [
            'ticket' => PdfExportTicket::issue($export, $ability, $filename, $title, $nameField, $section),
        ]);
    }

    /**
     * The report itself, as a download that is generated while it is sent.
     *
     * Nothing is laid out before the response is returned. Symfony sends the
     * headers and then calls the callback, so the browser has a named download
     * in hand from the start and receives each part as it is finished, rather
     * than an open connection with nothing on it for several minutes.
     *
     * The consequence is that a failure part way through cannot become an
     * error page - the status line is long gone by then. It ends the archive
     * instead, which is the same trade every streamed download makes.
     *
     * @param  array{title: string, columns: array<int, string>, empty: string, rows: callable}|null  $section
     * @param  int|null  $chunk  records per part; CHUNK unless a caller says
     *         otherwise. No module passes it: it exists so the split can be
     *         exercised at a boundary the test suite can afford to reach.
     * @param  list<int|string>|null  $order  the primary keys to print, in the
     *         order they are to be printed. Given, the export's own query is
     *         read a block of keys at a time rather than through a cursor.
     */
    public static function download(
        AbstractTableExport $export,
        string $filename,
        string $title,
        string $nameField,
        ?array $section = null,
        ?int $chunk = null,
        ?array $order = null,
    ): StreamedResponse {
        $chunk = max(1, $chunk ?? self::CHUNK);

        // Counted rather than discovered while writing, because the content
        // type and the filename are on the response before the first record
        // is laid out. An empty report counts as the one part that says so.
        $total = $order === null ? $export->query()->count() : count($order);

        if ($total <= $chunk) {
            return self::onePdf($export, $filename, $title, $nameField, $section, $order);
        }

        return self::zipped($export, $filename, $title, $nameField, $section, $chunk, $order);
    }

    /**
     * The report's HTML, which is what the mPDF step is handed. Separate from
     * download() so the layout can be exercised without rendering a PDF.
     *
     * The records stay lazy all the way into the template: @forelse walks
     * whatever it is given without counting it first, so nothing here
     * materialises the report the way a ->all() did.
     *
     * @param  array{title: string, columns: array<int, string>, empty: string, rows: callable}|null  $section
     */
    public static function render(
        AbstractTableExport $export,
        string $title,
        string $nameField,
        ?array $section = null,
    ): string {
        return view('pdf.record-template', [
            'title' => $title,
            'records' => self::records($export, $title, $nameField, $section),
            'empty' => __('fields.import_empty_file'),
        ])->render();
    }

    /**
     * A report that fits in one part: the single PDF the modules have always
     * downloaded, under the name they always had.
     *
     * Written to a file and sent from there rather than returned as a string,
     * so the finished document is never a second copy of itself in memory.
     *
     * @param  array{title: string, columns: array<int, string>, empty: string, rows: callable}|null  $section
     * @param  list<int|string>|null  $order
     */
    private static function onePdf(
        AbstractTableExport $export,
        string $filename,
        string $title,
        string $nameField,
        ?array $section,
        ?array $order,
    ): StreamedResponse {
        return response()->streamDownload(static function () use ($export, $title, $nameField, $section, $order): void {
            $directory = self::temporaryDirectory();

            try {
                $pdf = self::document($title);
                $written = 0;

                foreach (self::records($export, $title, $nameField, $section, $order) as $record) {
                    self::write($pdf, $record);
                    $written++;
                }

                // A report that matched nothing still prints, and still says so.
                if ($written === 0) {
                    $pdf->WriteHTML(
                        '<p class="empty">' . e(__('fields.import_empty_file')) . '</p>',
                        HTMLParserMode::HTML_BODY,
                    );
                }

                $path = self::close($pdf, $directory, 0);
                unset($pdf);

                self::emit($path);
            } finally {
                self::remove($directory);
            }
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * A long report: a run of PDFs of $chunk records each, packed into one
     * archive as they are finished.
     *
     * The archive is written straight to the response rather than assembled
     * on disk first. A part is laid out, closed onto disk, pushed into the
     * ZIP, and deleted before the next is opened, so neither the working
     * directory nor PHP's memory ever holds more than one part - and the
     * browser has the first part long before the last is written.
     *
     * The parts go in unaltered: the compression is lossless, so a file taken
     * back out is byte for byte the PDF that was generated.
     *
     * @param  array{title: string, columns: array<int, string>, empty: string, rows: callable}|null  $section
     * @param  list<int|string>|null  $order
     */
    private static function zipped(
        AbstractTableExport $export,
        string $filename,
        string $title,
        string $nameField,
        ?array $section,
        int $chunk,
        ?array $order,
    ): StreamedResponse {
        $base = self::base($filename);

        return response()->streamDownload(static function () use (
            $export, $title, $nameField, $section, $chunk, $order, $base
        ): void {
            $directory = self::temporaryDirectory();

            try {
                $zip = new ZipStream(
                    defaultCompressionMethod: CompressionMethod::DEFLATE,
                    sendHttpHeaders: false,
                    flushOutput: true,
                );

                $pdf = null;
                $written = 0;
                $inPart = 0;
                $index = 0;

                foreach (self::records($export, $title, $nameField, $section, $order) as $record) {
                    $pdf ??= self::document($title);

                    self::write($pdf, $record);

                    $written++;
                    $inPart++;

                    if ($inPart === $chunk) {
                        self::pack($zip, $pdf, $directory, $index++, $written - $inPart + 1, $written, $base);
                        $pdf = null;
                        $inPart = 0;
                    }
                }

                // The tail of the report: however many records were left over.
                if ($pdf !== null) {
                    self::pack($zip, $pdf, $directory, $index, $written - $inPart + 1, $written, $base);
                }

                $zip->finish();
            } finally {
                self::remove($directory);
            }
        }, $base . '.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * One record onto the open document.
     */
    private static function write(Mpdf $pdf, array $record): void
    {
        $pdf->WriteHTML(
            view('pdf.record-block', ['record' => $record])->render(),
            HTMLParserMode::HTML_BODY,
        );
    }

    /**
     * Finish one part, put it in the archive and let go of both.
     *
     * The part is deleted the moment it has been read into the ZIP: the
     * archive is being written to the response as it goes, so a part that is
     * already in it is never needed again.
     */
    private static function pack(
        ZipStream $zip,
        Mpdf $pdf,
        string $directory,
        int $index,
        int $from,
        int $to,
        string $base,
    ): void {
        $path = self::close($pdf, $directory, $index);

        try {
            $zip->addFileFromPath(self::partName($base, $from, $to), $path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A new, empty part: the same page setup, stylesheet and title bar the
     * whole report has always opened with.
     */
    private static function document(string $title): Mpdf
    {
        $pdf = new Mpdf(['format' => 'A4']);

        if (app()->getLocale() === 'ar') {
            // The <html dir> the full-document template carries is not written
            // in the chunked path, so the direction is set on the document.
            $pdf->SetDirectionality('rtl');
        }

        $pdf->WriteHTML(view('pdf.record-styles')->render(), HTMLParserMode::HEADER_CSS);
        $pdf->WriteHTML('<h1>' . e($title) . '</h1>', HTMLParserMode::HTML_BODY);

        return $pdf;
    }

    /**
     * Write one document to its own file and let go of it.
     *
     * Output() to a file rather than to a string, so the finished PDF is
     * never a second copy of itself in PHP memory. cleanup() then removes
     * mPDF's own working files for that document; dropping the last
     * reference releases the pages it laid out.
     */
    private static function close(Mpdf $pdf, string $directory, int $index): string
    {
        $path = $directory . DIRECTORY_SEPARATOR . 'part-' . $index . '.pdf';

        try {
            $pdf->Output($path, Destination::FILE);
        } finally {
            $pdf->cleanup();
        }

        return $path;
    }

    /**
     * Send a finished file to the client a block at a time, so handing it
     * over does not put back the memory the chunking just removed.
     */
    private static function emit(string $path): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The finished PDF could not be read: ' . $path);
        }

        try {
            while (! feof($handle)) {
                echo fread($handle, 8192);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * What one part is called inside the archive: the report's own name and
     * the range of records it holds, so a reader can tell from the file name
     * alone which slice of the report they have opened.
     */
    public static function partName(string $base, int $from, int $to): string
    {
        return $base
            . '_' . str_pad((string) $from, 3, '0', STR_PAD_LEFT)
            . '_' . str_pad((string) $to, 3, '0', STR_PAD_LEFT)
            . '.pdf';
    }

    /**
     * The report's name without its extension: 'follow-up-children.pdf'
     * becomes 'follow-up-children', which is what the archive and every part
     * inside it is named after.
     */
    private static function base(string $filename): string
    {
        return pathinfo($filename, PATHINFO_FILENAME);
    }

    /**
     * A private working directory for one export, under the system temp
     * directory. Removed the moment the download ends, however it ends.
     */
    private static function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf-export-' . bin2hex(random_bytes(8));

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The PDF working directory could not be created: ' . $directory);
        }

        return $directory;
    }

    /**
     * Delete the working directory and everything in it. Never raises: a
     * file that has already gone is the outcome this wanted.
     */
    private static function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach ((array) glob($directory . DIRECTORY_SEPARATOR . '*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($directory);
    }

    /**
     * The records, shaped for the template, one at a time.
     *
     * Lazy on purpose: the writers consume them as they go, so a report of
     * ten thousand children never holds ten thousand rendered blocks at once.
     *
     * @param  array{title: string, columns: array<int, string>, empty: string, rows: callable}|null  $section
     * @param  list<int|string>|null  $order
     * @return LazyCollection<int, array<string, mixed>>
     */
    private static function records(
        AbstractTableExport $export,
        string $title,
        string $nameField,
        ?array $section,
        ?array $order = null,
    ): LazyCollection {
        // The record's own fields, without any per-session column groups the
        // module's map() appends after them for the spreadsheet.
        $fields = $export->fields();
        $labels = array_map(fn (string $field): string => __('fields.' . $field), $fields);

        return self::models($export, $order)->map(function (Model $record) use (
            $export, $fields, $labels, $title, $nameField, $section
        ): array {
            $values = array_slice($export->map($record), 0, count($fields));

            return [
                'title' => trim((string) $record->getAttribute($nameField)) ?: $title,
                'pairs' => array_map(
                    fn (string $label, mixed $value): array => ['label' => $label, 'value' => $value],
                    $labels,
                    $values,
                ),
                'section' => $section === null ? null : [
                    'title' => $section['title'],
                    'columns' => $section['columns'],
                    'empty' => $section['empty'],
                    'rows' => ($section['rows'])($record),
                ],
            ];
        });
    }

    /**
     * The records themselves, read in blocks and handed over one at a time.
     *
     * Read in blocks rather than one by one because a module's PDF may print
     * a relation - the follow-up report prints each child's visits - and a
     * relation read per record is a query per record. A block is fetched with
     * get(), which applies the eager loads the module's query declares, so
     * the visits of two hundred children are two queries rather than two
     * hundred.
     *
     * A cursor cannot do that: Builder::cursor() hydrates straight from the
     * PDO statement and never runs the eager loads, so with() on the module's
     * query was silently doing nothing and every relation was a fresh query.
     *
     * With $order the block is a slice of that key list and the records are
     * handed over in exactly its order, so the report cannot skip or repeat a
     * record however the underlying rows happen to come back. Without it the
     * query's own order stands and the buffer only exists to load relations.
     *
     * @param  list<int|string>|null  $order
     * @return LazyCollection<int, Model>
     */
    private static function models(AbstractTableExport $export, ?array $order): LazyCollection
    {
        $query = $export->query();

        if ($order !== null) {
            return LazyCollection::make(function () use ($query, $order): iterable {
                foreach (array_chunk($order, self::READ) as $keys) {
                    $block = self::block($query, $keys);

                    foreach ($keys as $key) {
                        $record = $block->get((string) $key);

                        // A record deleted between the click and the download
                        // is simply not in the report.
                        if ($record !== null) {
                            yield $record;
                        }
                    }
                }
            });
        }

        $eager = $query->getEagerLoads();

        return LazyCollection::make(function () use ($query, $eager): iterable {
            $buffer = [];

            foreach ($query->cursor() as $record) {
                $buffer[] = $record;

                if (count($buffer) === self::READ) {
                    yield from self::withRelations($buffer, $eager);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                yield from self::withRelations($buffer, $eager);
            }
        });
    }

    /**
     * One block of records, keyed by primary key as a string so the caller
     * can put them back into the order it asked for.
     *
     * @param  list<int|string>  $keys
     * @return EloquentCollection<string, Model>
     */
    private static function block(Builder $query, array $keys): EloquentCollection
    {
        return (clone $query)
            ->whereKey($keys)
            ->get()
            ->keyBy(fn (Model $record): string => (string) $record->getKey());
    }

    /**
     * @param  list<Model>  $buffer
     * @param  array<string, mixed>  $eager
     * @return EloquentCollection<int, Model>
     */
    private static function withRelations(array $buffer, array $eager): EloquentCollection
    {
        $records = $buffer[0]->newCollection($buffer);

        return $eager === [] ? $records : $records->load($eager);
    }
}
