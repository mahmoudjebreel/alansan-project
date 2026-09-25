<?php

namespace App\Exports;

use Illuminate\Support\Facades\File;

/**
 * A module's export as CSV, for a listing too large for the XLSX path.
 *
 * The file is written in full, to a temporary file on this server, BEFORE a
 * byte is sent, and it is sent only once it has been checked:
 *
 *   - every parked key produced exactly one row: a key whose record is gone
 *     is a missing row, a key met twice is a duplicate row, and either one
 *     stops the export;
 *   - every write succeeded, so a full disk cannot leave a short file;
 *   - the rows written equal the rows expected.
 *
 * A failure is an error the user is shown, never a file. And because the
 * finished file is sent with its length, a download cut off on the way is
 * reported by the browser as failed rather than saved as if it were whole.
 *
 * The columns and the values are the module export's own - headings() and
 * map() - so a row reads exactly as it does in the XLSX file.
 *
 * @see \App\Exports\CsvExportTicket
 * @see \App\Http\Controllers\CsvExportDownloadController
 */
class CsvExport
{
    /**
     * Up to this many rows the module's existing XLSX download is used,
     * unchanged; above it, this CSV. It is also Laravel Excel's page size,
     * so the XLSX path never crosses a page boundary.
     */
    public const THRESHOLD = 1000;

    /** Records read per round trip while the file is written. */
    public const CHUNK = 500;

    /**
     * Park the export and send the browser to the route that writes it.
     *
     * Untyped, like PdfExport::start(): inside a Livewire action redirect()
     * hands back Livewire's own redirector rather than a RedirectResponse.
     */
    public static function start(AbstractTableExport $export, string $ability, string $filename)
    {
        return redirect()->route('exports.csv', [
            'ticket' => CsvExportTicket::issue($export, $ability, $filename),
        ]);
    }

    /**
     * Write the export's rows for the given primary keys, in that order, to a
     * temporary file, and return its path once the file is known complete.
     *
     * @param  list<int|string>  $keys
     * @param  int|null  $chunk  records per read; CHUNK unless a test needs a
     *         boundary it can afford to reach
     *
     * @throws IncompleteCsvExportException  and removes the file, when any row
     *         is missing, repeated or not written
     */
    public static function build(AbstractTableExport $export, array $keys, ?int $chunk = null): string
    {
        $directory = storage_path('app/csv-exports');
        File::ensureDirectoryExists($directory);

        $path = tempnam($directory, 'csv-');

        if ($path === false || ($handle = fopen($path, 'wb')) === false) {
            throw IncompleteCsvExportException::unwritable();
        }

        $expected = count($keys);
        $written = 0;
        $seen = [];

        try {
            // The BOM makes Excel read the file as UTF-8, so Arabic opens as
            // typed rather than as mojibake.
            static::write($handle, "\xEF\xBB\xBF");
            static::row($handle, $export->headings());

            $query = $export->query();
            $model = $query->getModel();

            foreach (array_chunk($keys, $chunk ?? self::CHUNK) as $block) {
                $records = (clone $query)
                    ->reorder()
                    ->whereIn($model->getQualifiedKeyName(), $block)
                    ->get()
                    ->keyBy($model->getKeyName());

                foreach ($block as $key) {
                    $record = $records->get($key);

                    // Gone since the click: a row that would be missing.
                    // Met before: a row that would be there twice.
                    if ($record === null || isset($seen[$key])) {
                        throw IncompleteCsvExportException::rows($expected, $written);
                    }

                    $seen[$key] = true;
                    static::row($handle, $export->map($record));
                    $written++;
                }
            }

            if (! fflush($handle) || $written !== $expected || count($seen) !== $expected) {
                throw IncompleteCsvExportException::rows($expected, $written);
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);

            // Whatever stopped it - a missing row, a failed write, the
            // database - the result is the same: no file, and the reason kept.
            throw $e instanceof IncompleteCsvExportException
                ? $e
                : new IncompleteCsvExportException(IncompleteCsvExportException::rows($expected, $written)->getMessage(), 0, $e);
        }

        fclose($handle);

        return $path;
    }

    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $values
     */
    private static function row($handle, array $values): void
    {
        if (fputcsv($handle, $values, ',', '"', '\\', "\r\n") === false) {
            throw IncompleteCsvExportException::unwritable();
        }
    }

    /**
     * @param  resource  $handle
     */
    private static function write($handle, string $bytes): void
    {
        if (fwrite($handle, $bytes) !== strlen($bytes)) {
            throw IncompleteCsvExportException::unwritable();
        }
    }
}
