<?php

namespace Tests\Feature;

use App\Exports\ChildrenExport;
use App\Exports\PdfExport;
use App\Exports\PdfExportTicket;
use App\Filament\Resources\ChildResource\Pages\ListChildren;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * The PDF button hands the report to the browser, not to Livewire.
 *
 * A Filament action's return value goes back through Livewire, which answers
 * a StreamedResponse by buffering all of it, base64-encoding it and posting it
 * inside the JSON of the XHR. For a report that takes minutes to lay out that
 * meant the browser was shown a spinner and never a file. So the click parks
 * the records under a ticket and redirects, and an ordinary GET route streams
 * the archive as it is built.
 *
 * @see \App\Exports\PdfExport::start()
 * @see \App\Http\Controllers\PdfExportDownloadController
 */
class PdfExportDownloadRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('Super Admin');
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        $left = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf-export-*');
        $this->assertSame([], (array) $left, 'a PDF working directory was left behind');

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Click a module's PDF button and hand back the ticket it parked.
     */
    private function clickPdf(string $page = ListChildren::class): string
    {
        $redirect = Livewire::test($page)->call('downloadPdf')->effects['redirect'] ?? null;

        $this->assertIsString($redirect, 'the PDF button did not send the browser anywhere');
        $this->assertStringContainsString('/exports/pdf/', $redirect);

        return basename(parse_url($redirect, PHP_URL_PATH));
    }

    /**
     * The bytes of a streamed download.
     *
     * Collected through an output-buffer callback because the download
     * flushes as it goes; see PdfExportChunkingTest::sent().
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
     * @return array<string, string>  archive entries, by name
     */
    private function entries(string $zip): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf-route-zip-');
        file_put_contents($path, $zip);

        try {
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($path) === true, 'the download is not a readable ZIP');

            $entries = [];

            for ($i = 0; $i < $archive->numFiles; $i++) {
                $entries[$archive->getNameIndex($i)] = (string) $archive->getFromIndex($i);
            }

            $archive->close();

            return $entries;
        } finally {
            @unlink($path);
        }
    }

    // -----------------------------------------------------------------
    // The click
    // -----------------------------------------------------------------

    /**
     * The button no longer answers with the file. It parks the report and
     * sends the browser to collect it, which is the whole point: a download
     * returned from here would be buffered and base64'd into the XHR.
     */
    public function test_the_button_redirects_instead_of_returning_the_file(): void
    {
        Child::factory()->count(3)->create();

        $component = Livewire::test(ListChildren::class)->call('downloadPdf');

        $this->assertArrayHasKey('redirect', $component->effects);
        $this->assertArrayNotHasKey(
            'download',
            $component->effects,
            'the report was still returned through Livewire',
        );
    }

    /**
     * The ticket carries the records that were on screen, resolved while the
     * component still knew what they were.
     */
    public function test_the_ticket_carries_the_records_that_were_listed(): void
    {
        Child::factory()->count(4)->create();

        $parked = PdfExportTicket::claim($this->clickPdf());

        $this->assertNotNull($parked);
        $this->assertSame('children.pdf', $parked['filename']);
        $this->assertSame('children.export', $parked['ability']);
        $this->assertSame(Child::class, $parked['model']);
        $this->assertSame(ChildrenExport::class, $parked['export']);
        $this->assertSame(
            Child::query()->orderBy('id')->pluck('id')->all(),
            collect($parked['keys'])->map(intval(...))->sort()->values()->all(),
        );
    }

    /**
     * A filtered listing exports the filtered scope and nothing else: the
     * keys were resolved from the table's own query.
     */
    public function test_a_filtered_listing_parks_only_its_own_records(): void
    {
        $wanted = Child::factory()->count(3)->create(['name' => 'سعيد']);
        Child::factory()->count(5)->create(['name' => 'خالد']);

        $redirect = Livewire::test(ListChildren::class)
            ->set('tableSearch', 'سعيد')
            ->call('downloadPdf')
            ->effects['redirect'];

        $parked = PdfExportTicket::claim(basename(parse_url($redirect, PHP_URL_PATH)));

        $this->assertNotNull($parked);
        $this->assertSame(
            $wanted->pluck('id')->sort()->values()->all(),
            collect($parked['keys'])->map(intval(...))->sort()->values()->all(),
            'the report was parked over more than the listing was showing',
        );
    }

    // -----------------------------------------------------------------
    // The route
    // -----------------------------------------------------------------

    /**
     * An unknown or expired token is not a report.
     */
    public function test_an_unknown_ticket_is_not_found(): void
    {
        $this->get(route('exports.pdf', ['ticket' => str_repeat('a', 48)]))
            ->assertNotFound();
    }

    /**
     * A ticket belongs to the user who made it. Somebody else holding the
     * token gets nothing, whatever they may export themselves.
     */
    public function test_another_users_ticket_is_not_found(): void
    {
        Child::factory()->count(2)->create();

        $ticket = $this->clickPdf();

        $other = User::factory()->create();
        $other->assignRole('Super Admin');

        $this->actingAs($other)
            ->get(route('exports.pdf', ['ticket' => $ticket]))
            ->assertNotFound();
    }

    /**
     * The permission is checked again at the route, not merely trusted from
     * the click that issued the ticket.
     */
    public function test_a_user_who_may_no_longer_export_is_refused(): void
    {
        Child::factory()->count(2)->create();

        $ticket = $this->clickPdf();

        $this->user->removeRole('Super Admin');
        $this->user->forgetCachedPermissions();

        $this->get(route('exports.pdf', ['ticket' => $ticket]))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // The report the route sends
    // -----------------------------------------------------------------

    /**
     * Nothing to print still downloads one PDF that says so.
     */
    public function test_no_records_is_still_one_pdf(): void
    {
        $response = $this->get(route('exports.pdf', ['ticket' => $this->clickPdf()]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    /**
     * One record, ten, a hundred: all well inside one part, all the single
     * PDF the modules have always downloaded.
     */
    #[DataProvider('smallReports')]
    public function test_a_small_report_is_one_pdf(int $count): void
    {
        Child::factory()->count($count)->create();

        $response = $this->get(route('exports.pdf', ['ticket' => $this->clickPdf()]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'children.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );

        $content = $response->streamedContent();
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertStringContainsString('%%EOF', $content);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function smallReports(): array
    {
        return [
            'no records' => [0],
            'one record' => [1],
            'ten records' => [10],
            'a hundred records' => [100],
        ];
    }

    /**
     * Past the chunk the report is an archive of parts, and between them
     * every record is printed exactly once: the ranges on the part names
     * tile the report end to end with no gap and no overlap.
     */
    public function test_a_long_report_is_one_zip_whose_parts_cover_every_record(): void
    {
        Child::factory()->count(7)->create();

        $parked = PdfExportTicket::claim($this->clickPdf());
        $keys = $parked['keys'];

        // The route's own boundary is five hundred, which no test can afford
        // to reach; the split itself is exercised here at three.
        $response = PdfExport::download(
            PdfExportTicket::export($parked),
            $parked['filename'],
            $parked['title'],
            $parked['nameField'],
            PdfExportTicket::section($parked),
            3,
            $keys,
        );

        $entries = $this->entries($this->sent($response));

        $this->assertSame([
            'children_001_003.pdf',
            'children_004_006.pdf',
            'children_007_007.pdf',
        ], array_keys($entries));

        foreach ($entries as $name => $bytes) {
            $this->assertStringStartsWith('%PDF', $bytes, $name);
            $this->assertStringContainsString('%%EOF', $bytes, $name);
        }
    }

    /**
     * The order the listing had is the order the report prints, and the
     * records are read in blocks rather than one query per record.
     */
    public function test_the_report_prints_in_the_parked_order_without_a_query_per_record(): void
    {
        FollowUpChild::factory()->count(6)->create()->each(function (FollowUpChild $child): void {
            $child->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-02', 'muac' => 121.5]);
        });

        $parked = PdfExportTicket::claim($this->clickPdf(ListFollowUpChildren::class));
        $this->assertNotNull($parked);

        $seen = [];

        $export = new class(FollowUpChild::query(), $seen) extends \App\Exports\FollowUpChildrenExport
        {
            /** @param list<string> $seen */
            public function __construct(\Illuminate\Database\Eloquent\Builder $query, private array &$seen)
            {
                parent::__construct($query);
            }

            public function map($record): array
            {
                $this->seen[] = (string) $record->getKey();

                return parent::map($record);
            }
        };

        DB::enableQueryLog();

        $response = PdfExport::download(
            $export,
            $parked['filename'],
            $parked['title'],
            $parked['nameField'],
            PdfExportTicket::section($parked),
            null,
            $parked['keys'],
        );

        $this->sent($response);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Printed once each, in the order the ticket parked them.
        $this->assertSame(
            collect($parked['keys'])->map(strval(...))->all(),
            $seen,
        );
        $this->assertSame(count($seen), count(array_unique($seen)));

        // Six children, their visits and their linked episodes in a handful
        // of queries rather than one per record: the export's own with() is
        // applied, which a cursor silently dropped.
        $this->assertLessThanOrEqual(
            6,
            $queries,
            'the report is running a query per record: ' . $queries . ' for 6 records',
        );
    }

    /**
     * The report prints in the language it was asked for in.
     *
     * The download route lives in routes/web.php, outside the panel, so it
     * does not carry the panel's SetLocale middleware: without the locale on
     * the ticket an Arabic operator's report came back with English field
     * headings under its Arabic title.
     *
     * @see \App\Http\Middleware\SetLocale
     */
    public function test_the_report_prints_in_the_language_it_was_asked_for(): void
    {
        app()->setLocale('ar');

        Child::factory()->create();

        $parked = PdfExportTicket::claim($this->clickPdf());

        $this->assertSame('ar', $parked['locale']);
        $this->assertSame(__('fields.children', [], 'ar'), $parked['title']);

        // The route is entered the way a browser enters it: with no idea what
        // language the panel was in.
        app()->setLocale('en');

        $this->get(route('exports.pdf', ['ticket' => $this->clickPdfIn('ar')]))->assertOk();

        $this->assertSame(
            'ar',
            app()->getLocale(),
            'the download route did not print in the locale the report was asked for',
        );
    }

    /**
     * Park a report from a panel that is in the given language.
     */
    private function clickPdfIn(string $locale): string
    {
        $was = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $this->clickPdf();
        } finally {
            app()->setLocale($was);
        }
    }

    /**
     * The printed page is untouched: the report is still Arabic, still
     * right-to-left, still the same stylesheet.
     */
    public function test_the_arabic_report_still_prints_right_to_left(): void
    {
        app()->setLocale('ar');

        Child::factory()->create(['name' => 'طفل التقرير']);

        $response = $this->get(route('exports.pdf', ['ticket' => $this->clickPdf()]));

        $content = $response->streamedContent();

        $this->assertStringStartsWith('%PDF', $content);

        // The layout itself is asserted on the markup, which is where it can
        // be read; see PdfExportChunkingTest::test_the_layout_is_untouched().
        $html = PdfExport::render(
            new ChildrenExport(Child::query()),
            __('fields.children'),
            'name',
        );

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('<h2>طفل التقرير</h2>', $html);
    }
}
