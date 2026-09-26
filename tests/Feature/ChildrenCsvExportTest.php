<?php

namespace Tests\Feature;

use App\Exports\ChildrenExport;
use App\Exports\CsvExport;
use App\Exports\CsvExportTicket;
use App\Exports\IncompleteCsvExportException;
use App\Filament\Resources\ChildResource\Pages\ListChildren;
use App\Models\Child;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H6: the Children export above 1000 rows is a CSV file written and checked
 * in full before it is sent. Complete means: every record once, no record
 * twice, none missing across read boundaries, the same columns and values as
 * the XLSX export - and a file that cannot be completed is never sent.
 */
class ChildrenCsvExportTest extends TestCase
{
    use RefreshDatabase;

    private const RETURN_URL = '/admin/children';

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
        // Nothing may be left behind on the server, whatever happened.
        $this->assertSame([], File::glob(storage_path('app/csv-exports/csv-*')), 'A temporary export file was left behind.');

        parent::tearDown();
    }

    // =================================================================
    // Threshold
    // =================================================================

    public function test_up_to_1000_rows_the_xlsx_download_is_unchanged(): void
    {
        $this->children(CsvExport::THRESHOLD);

        Livewire::test(ListChildren::class)
            ->call('downloadExcel')
            ->assertFileDownloaded('children.xlsx');
    }

    public function test_above_1000_rows_the_export_goes_to_the_csv_route(): void
    {
        $this->children(CsvExport::THRESHOLD + 1);

        $page = Livewire::test(ListChildren::class)->call('downloadExcel');

        $redirect = $page->effects['redirect'] ?? null;
        $this->assertNotNull($redirect, 'The large export must be collected from the CSV route.');
        $this->assertStringContainsString('/exports/csv/', $redirect);

        // Collected: exactly 1001 data rows, one per child.
        $response = $this->get($redirect);
        $response->assertOk();
        $response->assertDownload('children.csv');

        [, $rows] = $this->parse(file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertCount(CsvExport::THRESHOLD + 1, $rows);

        $response->baseResponse->getFile() && @unlink($response->baseResponse->getFile()->getPathname());
    }

    // =================================================================
    // Completeness
    // =================================================================

    public function test_every_record_is_written_once_across_chunk_boundaries_with_tied_dates(): void
    {
        // 53 children on ONE reporting date: an offset-paged sort on that date
        // is exactly what can skip or repeat a row at a page boundary.
        $children = $this->children(53, ['date_of_reporting' => '2026-08-19']);

        $export = new ChildrenExport(Child::query()->orderByDesc('date_of_reporting'));
        $keys = $this->keysOf($export);

        // A read size of 7 crosses a boundary eight times.
        $path = CsvExport::build($export, $keys, 7);
        [$headings, $rows] = $this->parse(file_get_contents($path));
        @unlink($path);

        $this->assertCount(53, $rows, 'Row count equals the record count.');

        $names = array_column($rows, array_search(__('fields.name'), $headings, true));
        $this->assertCount(53, array_unique($names), 'No duplicate rows.');
        $this->assertEqualsCanonicalizing($children->pluck('name')->all(), $names, 'No missing rows.');

        // In the listing's order: first and last are where they belong.
        $this->assertSame(Child::find($keys[0])->name, $names[0]);
        $this->assertSame(Child::find(end($keys))->name, end($names));
    }

    public function test_the_columns_and_values_are_the_xlsx_exports_own(): void
    {
        app()->setLocale('ar');

        $this->children(5);
        $export = new ChildrenExport(Child::query());
        $keys = $this->keysOf($export);

        $path = CsvExport::build($export, $keys, 2);
        $content = file_get_contents($path);
        @unlink($path);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content, 'Opens as UTF-8 in Excel.');

        [$headings, $rows] = $this->parse($content);

        $this->assertSame($export->headings(), $headings);

        foreach ($keys as $index => $key) {
            $child = Child::findOrFail($key);

            $expected = array_map(
                fn (mixed $value): string => $value === null ? '' : (string) $value,
                $export->map($child),
            );

            $this->assertSame($expected, $rows[$index], "Row for child {$child->getKey()}.");
        }
    }

    public function test_the_filters_and_the_trash_filter_chosen_at_the_click_are_kept(): void
    {
        $this->children(4, ['sex' => 'female']);
        $this->children(3, ['sex' => 'male']);
        $trashedFemale = Child::where('sex', 'female')->first();
        $trashedFemale->delete();

        // Only the live female children were on screen.
        $token = CsvExportTicket::issue(new ChildrenExport(Child::query()->where('sex', 'female')), 'children.export', 'children.csv', 'Child', self::RETURN_URL);

        $response = $this->get(route('exports.csv', ['ticket' => $token]));
        $response->assertOk();

        [, $rows] = $this->parse(file_get_contents($response->baseResponse->getFile()->getPathname()));
        @unlink($response->baseResponse->getFile()->getPathname());

        $this->assertCount(3, $rows);
    }

    public function test_a_record_deleted_to_the_trash_after_the_click_is_still_exported(): void
    {
        $this->children(3);
        $export = new ChildrenExport(Child::query());
        $token = CsvExportTicket::issue($export, 'children.export', 'children.csv', 'Child', self::RETURN_URL);

        Child::first()->delete();

        $response = $this->get(route('exports.csv', ['ticket' => $token]));
        $response->assertOk();

        [, $rows] = $this->parse(file_get_contents($response->baseResponse->getFile()->getPathname()));
        @unlink($response->baseResponse->getFile()->getPathname());

        $this->assertCount(3, $rows);
    }

    // =================================================================
    // A file that cannot be completed is never sent
    // =================================================================

    public function test_a_record_gone_since_the_click_stops_the_export_and_sends_nothing(): void
    {
        $this->children(10);
        $export = new ChildrenExport(Child::query());
        $keys = $this->keysOf($export);

        Child::withTrashed()->find($keys[6])->forceDelete();

        try {
            CsvExport::build($export, $keys, 3);
            $this->fail('A missing row must stop the export.');
        } catch (IncompleteCsvExportException $e) {
            $this->assertStringContainsString('10', $e->getMessage());
        }
    }

    public function test_a_repeated_key_stops_the_export(): void
    {
        $this->children(4);
        $export = new ChildrenExport(Child::query());
        $keys = $this->keysOf($export);
        $keys[] = $keys[1];

        $this->expectException(IncompleteCsvExportException::class);

        CsvExport::build($export, $keys, 2);
    }

    public function test_the_route_answers_an_incomplete_export_with_a_message_not_a_file(): void
    {
        $this->children(5);
        $token = CsvExportTicket::issue(new ChildrenExport(Child::query()), 'children.export', 'children.csv', 'Child', self::RETURN_URL);

        Child::withTrashed()->first()->forceDelete();

        // F14: back to the listing inside the application, whatever the
        // Referer header claims.
        $response = $this->from('https://elsewhere.example/phish')->get(route('exports.csv', ['ticket' => $token]));

        $response->assertRedirect(self::RETURN_URL);
        $this->assertNotInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse);

        // F7: the failure is audited once, with its reason; no success entry.
        $failed = \Spatie\Activitylog\Models\Activity::query()->where('event', 'export_failed')->sole();
        $this->assertSame('Child', $failed->properties['module']);
        $this->assertSame('csv', $failed->properties['format']);
        $this->assertSame(0, \Spatie\Activitylog\Models\Activity::query()->where('log_name', 'excel')->where('event', 'export')->count());
    }

    public function test_a_successful_export_is_audited_once_with_its_row_count_and_its_ticket_is_forgotten(): void
    {
        $this->children(4);
        $token = CsvExportTicket::issue(new ChildrenExport(Child::query()), 'children.export', 'children.csv', 'Child', self::RETURN_URL);

        $response = $this->get(route('exports.csv', ['ticket' => $token]));
        $response->assertOk();
        @unlink($response->baseResponse->getFile()->getPathname());

        $entry = \Spatie\Activitylog\Models\Activity::query()->where('log_name', 'excel')->where('event', 'export')->sole();
        $this->assertSame(4, $entry->properties['record_count']);
        $this->assertSame('Child', $entry->properties['module']);

        // The ticket is done with: collecting it again finds nothing.
        $this->get(route('exports.csv', ['ticket' => $token]))->assertNotFound();
    }

    public function test_temporary_files_older_than_an_hour_are_swept_and_new_ones_are_left(): void
    {
        File::ensureDirectoryExists(CsvExport::directory());

        $old = CsvExport::directory() . DIRECTORY_SEPARATOR . 'csv-orphan-old';
        $fresh = CsvExport::directory() . DIRECTORY_SEPARATOR . 'csv-orphan-fresh';
        $other = CsvExport::directory() . DIRECTORY_SEPARATOR . 'keep-me.txt';

        file_put_contents($old, 'x');
        file_put_contents($fresh, 'x');
        file_put_contents($other, 'x');
        touch($old, time() - CsvExport::ORPHAN_SECONDS - 60);
        touch($other, time() - CsvExport::ORPHAN_SECONDS - 60);

        $this->assertSame(1, CsvExport::sweep());

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
        $this->assertFileExists($other, 'Only the export\'s own temporary files are swept.');

        @unlink($fresh);
        @unlink($other);
    }

    public function test_the_ticket_carries_each_record_once_even_when_a_join_repeats_it(): void
    {
        $this->children(3);

        $query = Child::query()
            ->select('children.*')
            ->crossJoin('roles');

        $token = CsvExportTicket::issue(new ChildrenExport($query), 'children.export', 'children.csv', 'Child', self::RETURN_URL);

        $this->assertCount(3, CsvExportTicket::keys(CsvExportTicket::claim($token)));
    }

    // =================================================================
    // Access
    // =================================================================

    public function test_an_unknown_or_foreign_ticket_is_not_found(): void
    {
        $this->children(2);

        $this->get(route('exports.csv', ['ticket' => 'nope']))->assertNotFound();

        $token = CsvExportTicket::issue(new ChildrenExport(Child::query()), 'children.export', 'children.csv', 'Child', self::RETURN_URL);

        $this->actingAs(User::factory()->create())
            ->get(route('exports.csv', ['ticket' => $token]))
            ->assertNotFound();
    }

    public function test_a_user_who_may_no_longer_export_is_refused(): void
    {
        $this->children(2);
        $token = CsvExportTicket::issue(new ChildrenExport(Child::query()), 'children.export', 'children.csv', 'Child', self::RETURN_URL);

        $this->user->syncRoles([]);
        $this->user->syncPermissions([]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->user->fresh())
            ->get(route('exports.csv', ['ticket' => $token]))
            ->assertForbidden();
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Children with unique names, inserted in bulk.
     *
     * @return \Illuminate\Support\Collection<int, Child>
     */
    private function children(int $count, array $attributes = []): \Illuminate\Support\Collection
    {
        $start = Child::withTrashed()->count();

        $rows = Child::factory()->count($count)->make($attributes)->values()
            ->map(function (Child $child, int $index) use ($start): array {
                $child->name = 'Child ' . str_pad((string) ($start + $index + 1), 5, '0', STR_PAD_LEFT);
                $child->child_id = (string) (400000000 + $start + $index + 1);
                $child->created_at = now();
                $child->updated_at = now();

                return $child->getAttributes();
            });

        foreach ($rows->chunk(200) as $chunk) {
            Child::insert($chunk->values()->all());
        }

        return Child::query()->orderBy('id')->get()->slice($start)->values();
    }

    /** @return list<int> */
    private function keysOf(ChildrenExport $export): array
    {
        return $export->query()->pluck('children.id')->all();
    }

    /** @return array{0: array<int, string>, 1: array<int, array<int, string>>} */
    private function parse(string $content): array
    {
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;

        $handle = fopen('php://memory', 'w+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return [array_shift($rows), $rows];
    }
}
