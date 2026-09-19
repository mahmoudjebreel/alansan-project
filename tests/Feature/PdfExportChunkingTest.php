<?php

namespace Tests\Feature;

use App\Exports\ChildrenExport;
use App\Exports\FollowUpChildPdfExport;
use App\Exports\FollowUpChildrenExport;
use App\Exports\PdfExport;
use App\Models\Child;
use App\Models\FollowUpChild;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * A long PDF report is a run of PDFs, delivered as one ZIP.
 *
 * A report of two and a half thousand children used to be one mPDF document
 * that grew until it was asked for the file, and then a second copy of itself
 * as a PHP string before a byte reached the client. It is now written as parts
 * of PdfExport::CHUNK records each, every part closed onto disk before the
 * next is opened, and the parts are zipped straight off disk.
 *
 * The boundary cases are exercised at a chunk size the suite can afford to
 * reach rather than by seeding five hundred records: the split is the one
 * `$inPart === $chunk` test whatever the number is, so a run that splits at
 * two proves the same arithmetic a run that splits at five hundred does. The
 * five hundred itself is asserted separately, and the single-PDF path is
 * exercised at the real default.
 *
 * @see \App\Exports\PdfExport
 */
class PdfExportChunkingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        $this->assertNoWorkingDirectoriesLeftBehind();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Send a download and hand back its bytes.
     *
     * Collected through an output-buffer callback rather than by reading the
     * buffer at the end, because the download flushes as it goes: a plain
     * ob_get_clean() would hand back only whatever happened to be left after
     * the last flush. The callback is handed each chunk as it is flushed and
     * returns nothing, so the bytes are kept here and none of them escape
     * into the test runner's own output.
     */
    private function sent(StreamedResponse $response): string
    {
        $bytes = '';

        ob_start(function (string $chunk) use (&$bytes): string {
            $bytes .= $chunk;

            return '';
        });

        try {
            $response->sendContent();
        } finally {
            ob_end_flush();
        }

        return $bytes;
    }

    /**
     * Run the export over the seeded children and return the response.
     */
    private function download(?int $chunk = null, string $filename = 'children.pdf'): StreamedResponse
    {
        return PdfExport::download(
            new ChildrenExport(Child::query()->orderBy('id')),
            $filename,
            __('fields.children'),
            'name',
            null,
            $chunk,
        );
    }

    /**
     * The archive's entries, by name, with each entry's bytes.
     *
     * @return array<string, string>
     */
    private function entries(string $zip): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf-zip-');
        file_put_contents($path, $zip);

        try {
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path) === true, 'the download is not a readable ZIP');

            $entries = [];

            for ($i = 0; $i < $archive->numFiles; $i++) {
                $name = $archive->getNameIndex($i);
                $entries[$name] = (string) $archive->getFromIndex($i);
            }

            $archive->close();

            return $entries;
        } finally {
            @unlink($path);
        }
    }

    /**
     * How many pages one PDF part has, read off the page tree.
     */
    private function pages(string $pdf): int
    {
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(1, preg_match('#/Type\s*/Pages[^>]*?/Count\s+(\d+)#s', $pdf, $matches));

        return (int) $matches[1];
    }

    /**
     * Nothing this export wrote may outlive the download.
     */
    private function assertNoWorkingDirectoriesLeftBehind(): void
    {
        $left = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf-export-*');

        $this->assertSame([], (array) $left, 'a PDF working directory was left behind');
    }

    private function seedChildren(int $count): void
    {
        Child::factory()->count($count)->create();
    }

    // -----------------------------------------------------------------
    // The single-PDF path, unchanged
    // -----------------------------------------------------------------

    /**
     * The default is a hundred records per part, which is what every module's
     * download splits at.
     *
     * The size is a first-byte budget, not a throughput one: nothing reaches
     * the browser until the first part is closed, and a proxy in front of the
     * site ends a request it has had no bytes from for a hundred seconds.
     */
    public function test_the_chunk_size_is_one_hundred(): void
    {
        $this->assertSame(100, PdfExport::CHUNK);
    }

    /**
     * A report inside one chunk is the single PDF it has always been: same
     * content type, same filename, no archive.
     */
    public function test_a_report_under_the_chunk_size_is_still_one_pdf(): void
    {
        $this->seedChildren(6);

        $response = $this->download();
        $content = $this->sent($response);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('children.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('.zip', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $content);
    }

    /**
     * An export that matched nothing still downloads one PDF, and still says
     * the report is empty.
     */
    public function test_an_empty_report_is_one_pdf(): void
    {
        $response = $this->download();
        $content = $this->sent($response);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertSame(1, $this->pages($content));
    }

    /**
     * Exactly one chunk's worth is one part, not two: the split happens
     * after the chunk is full, and an empty part is never opened.
     */
    public function test_exactly_one_chunk_is_a_single_pdf(): void
    {
        $this->seedChildren(4);

        $response = $this->download(chunk: 4);
        $content = $this->sent($response);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $content);
    }

    /**
     * One record past the chunk is two parts, and the ZIP names say which
     * records each holds.
     */
    public function test_one_record_past_the_chunk_is_two_parts(): void
    {
        $this->seedChildren(5);

        $response = $this->download(chunk: 4);
        $entries = $this->entries($this->sent($response));

        $this->assertSame(
            ['children_001_004.pdf', 'children_005_005.pdf'],
            array_keys($entries),
        );
    }

    // -----------------------------------------------------------------
    // The archive
    // -----------------------------------------------------------------

    /**
     * A report over the chunk downloads as one ZIP named after the report,
     * holding one PDF per part.
     */
    public function test_a_long_report_downloads_as_one_zip_of_pdf_parts(): void
    {
        $this->seedChildren(7);

        $response = $this->download(chunk: 2, filename: 'follow-up-children.pdf');
        $content = $this->sent($response);

        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'follow-up-children.zip',
            (string) $response->headers->get('Content-Disposition'),
        );

        $entries = $this->entries($content);

        $this->assertSame([
            'follow-up-children_001_002.pdf',
            'follow-up-children_003_004.pdf',
            'follow-up-children_005_006.pdf',
            'follow-up-children_007_007.pdf',
        ], array_keys($entries));

        foreach ($entries as $name => $bytes) {
            $this->assertStringStartsWith('%PDF', $bytes, $name);
        }
    }

    /**
     * The ranges tile the report: every record is in exactly one part, the
     * parts run in order, and none of them overlaps the next.
     */
    public function test_the_part_ranges_cover_every_record_exactly_once(): void
    {
        $this->seedChildren(10);

        $entries = $this->entries($this->sent($this->download(chunk: 3)));

        $covered = [];
        $previousTo = 0;

        foreach (array_keys($entries) as $name) {
            $this->assertSame(1, preg_match('/_(\d+)_(\d+)\.pdf$/', $name, $matches), $name);

            [$from, $to] = [(int) $matches[1], (int) $matches[2]];

            $this->assertSame($previousTo + 1, $from, $name . ' does not start where the previous part ended');
            $this->assertGreaterThanOrEqual($from, $to, $name);

            $covered = array_merge($covered, range($from, $to));
            $previousTo = $to;
        }

        $this->assertSame(range(1, 10), $covered);
        $this->assertSame(10, count(array_unique($covered)));
    }

    /**
     * The parts are not copies of each other: a full part holds more than a
     * part that got the remainder, and the pages say so.
     */
    public function test_a_partial_last_part_holds_fewer_records_than_a_full_one(): void
    {
        $this->seedChildren(7);

        $entries = $this->entries($this->sent($this->download(chunk: 3)));

        $this->assertSame([
            'children_001_003.pdf',
            'children_004_006.pdf',
            'children_007_007.pdf',
        ], array_keys($entries));

        $full = strlen($entries['children_001_003.pdf']);
        $tail = strlen($entries['children_007_007.pdf']);

        $this->assertGreaterThan($tail, $full, 'the remainder part is not smaller than a full part');
        $this->assertGreaterThanOrEqual(
            $this->pages($entries['children_007_007.pdf']),
            $this->pages($entries['children_001_003.pdf']),
        );
    }

    /**
     * Every part is a whole document of its own: its own title page, its own
     * page tree, openable on its own.
     */
    public function test_every_part_is_a_complete_pdf(): void
    {
        $this->seedChildren(5);

        foreach ($this->entries($this->sent($this->download(chunk: 2))) as $name => $bytes) {
            $this->assertStringStartsWith('%PDF', $bytes, $name);
            $this->assertStringContainsString('%%EOF', $bytes, $name);
            $this->assertGreaterThanOrEqual(1, $this->pages($bytes), $name);
        }
    }

    // -----------------------------------------------------------------
    // Ordering, duplication and omission
    // -----------------------------------------------------------------

    /**
     * The records reach the writer once each, in the order the query put
     * them: the split is a position in one cursor, so a record cannot be
     * written into two parts or missed between them.
     */
    public function test_every_record_is_written_once_in_query_order(): void
    {
        $this->seedChildren(10);

        $export = new class(Child::query()->orderBy('id'), $this) extends ChildrenExport
        {
            public function __construct(Builder $query, private PdfExportChunkingTest $test)
            {
                parent::__construct($query);
            }

            public function map($record): array
            {
                $this->test->record((string) $record->getKey());

                return parent::map($record);
            }
        };

        $this->written = [];

        $response = PdfExport::download($export, 'children.pdf', __('fields.children'), 'name', null, 3);
        $this->sent($response);

        $expected = Child::query()->orderBy('id')->pluck('id')->map(strval(...))->all();

        $this->assertSame($expected, $this->written);
        $this->assertSame(count($this->written), count(array_unique($this->written)));
    }

    public function record(string $key): void
    {
        $this->written[] = $key;
    }

    /**
     * The scope the caller passed is the scope that prints: the split never
     * widens or narrows the report.
     */
    public function test_the_filtered_scope_is_preserved_across_the_split(): void
    {
        Child::factory()->count(4)->create(['governorate' => 'gaza']);
        Child::factory()->count(3)->create(['governorate' => 'north_gaza']);

        $response = PdfExport::download(
            new ChildrenExport(Child::query()->where('governorate', 'gaza')->orderBy('id')),
            'children.pdf',
            __('fields.children'),
            'name',
            null,
            2,
        );

        $entries = $this->entries($this->sent($response));

        $this->assertSame([
            'children_001_002.pdf',
            'children_003_004.pdf',
        ], array_keys($entries));
    }

    // -----------------------------------------------------------------
    // Temporary files
    // -----------------------------------------------------------------

    /**
     * Nothing is laid out until the response is being sent, and the working
     * directory goes when the sending ends - the tearDown check says so for
     * every other test in this file too.
     *
     * The report is built inside the stream rather than before it so the
     * browser is handed a real download immediately and filled in as the
     * parts are written, instead of waiting out the whole report on an idle
     * connection with nothing on it.
     */
    public function test_the_report_is_built_while_it_is_sent_and_cleaned_up_after(): void
    {
        $this->seedChildren(5);

        $before = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf-export-*');
        $this->assertSame([], (array) $before);

        $response = $this->download(chunk: 2);

        // Returning the response has written nothing: no working directory
        // exists yet, because no record has been laid out yet.
        $this->assertSame(
            [],
            (array) glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf-export-*'),
            'the report was built before the response was sent',
        );

        $this->sent($response);

        $this->assertSame([], (array) glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf-export-*'));
    }

    /**
     * A failure part way through leaves nothing behind either.
     */
    public function test_a_failure_during_generation_cleans_up(): void
    {
        $this->seedChildren(5);

        $export = new class(Child::query()->orderBy('id')) extends ChildrenExport
        {
            private int $seen = 0;

            public function map($record): array
            {
                if (++$this->seen > 3) {
                    throw new \RuntimeException('the report failed part way through');
                }

                return parent::map($record);
            }
        };

        // The failure surfaces while the response is being sent, because that
        // is when the report is written. By then the status line has long
        // gone, so it cannot become an error page - it ends the download,
        // which is the trade every streamed response makes.
        $response = PdfExport::download($export, 'children.pdf', __('fields.children'), 'name', null, 2);

        try {
            $this->sent($response);
            $this->fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('the report failed part way through', $e->getMessage());
        }

        $this->assertSame([], (array) glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf-export-*'));
    }

    // -----------------------------------------------------------------
    // The printed page
    // -----------------------------------------------------------------

    /**
     * The markup handed to mPDF is the markup it was handed before: the
     * split changed how the report is delivered, not how it looks.
     */
    public function test_the_layout_is_untouched(): void
    {
        Child::factory()->create(['name' => 'طفل التقرير']);

        $html = PdfExport::render(new ChildrenExport(Child::query()), __('fields.children'), 'name');

        $this->assertStringContainsString('<h1>' . __('fields.children') . '</h1>', $html);
        $this->assertStringContainsString('<h2>طفل التقرير</h2>', $html);
        $this->assertStringContainsString('.label { width: 26%; background: #fafafa;', $html);
        $this->assertStringContainsString('th, td { border: 1px solid #555;', $html);
    }

    /**
     * The module that keeps repeated visits still prints them, in every part.
     */
    public function test_a_module_with_a_repeated_section_still_splits(): void
    {
        FollowUpChild::factory()->count(5)->create()->each(function (FollowUpChild $child): void {
            $child->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-02', 'muac' => 121.5]);
        });

        $response = PdfExport::download(
            new FollowUpChildrenExport(FollowUpChild::query()->orderBy('id')),
            'follow-up-children.pdf',
            __('fields.follow_up_children'),
            'child_name',
            FollowUpChildPdfExport::section(),
            2,
        );

        $entries = $this->entries($this->sent($response));

        $this->assertSame([
            'follow-up-children_001_002.pdf',
            'follow-up-children_003_004.pdf',
            'follow-up-children_005_005.pdf',
        ], array_keys($entries));
    }

    /**
     * render() is the markup, not a materialised report: it walks the same
     * lazy cursor the writer does.
     */
    public function test_render_does_not_materialise_the_report(): void
    {
        $this->seedChildren(3);

        $seen = 0;

        $export = new class(Child::query()->orderBy('id'), $seen) extends ChildrenExport
        {
            public function __construct(Builder $query, private int &$seen)
            {
                parent::__construct($query);
            }

            public function map($record): array
            {
                $this->seen++;

                return parent::map($record);
            }
        };

        $html = PdfExport::render($export, __('fields.children'), 'name');

        $this->assertSame(3, $seen);
        $this->assertSame(3, substr_count($html, '<h2>'));
        $this->assertInstanceOf(Model::class, Child::query()->first());
    }
}
