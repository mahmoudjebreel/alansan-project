<?php

namespace Tests\Feature;

use App\Exports\CsvExport;
use App\Exports\CsvExportTicket;
use App\Exports\FollowUpChildrenExport;
use App\Exports\IncompleteCsvExportException;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Models\FollowUpChild;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * F1: the full Follow-Up export goes out through the checked CSV route - the
 * same columns and values as before, every episode once, and never a partial
 * file.
 */
class FollowUpCsvExportTest extends TestCase
{
    use RefreshDatabase;

    private const RETURN_URL = '/admin/follow-up-children';

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
        $this->assertSame([], File::glob(storage_path('app/csv-exports/csv-*')), 'A temporary export file was left behind.');

        parent::tearDown();
    }

    public function test_the_page_sends_the_export_to_the_csv_route_and_every_episode_comes_out_once(): void
    {
        foreach (range(1, 12) as $i) {
            $this->episode((string) (470900000 + $i), $i % 3 === 0 ? 'cured' : null);
        }

        $redirect = Livewire::test(ListFollowUpChildren::class)->call('downloadExcel')->effects['redirect'] ?? null;
        $this->assertStringContainsString('/exports/csv/', (string) $redirect);

        $response = $this->get($redirect);
        $response->assertOk();
        $response->assertDownload('follow-up-children.csv');

        [$headings, $rows] = $this->parse(file_get_contents($response->baseResponse->getFile()->getPathname()));
        @unlink($response->baseResponse->getFile()->getPathname());

        $this->assertCount(12, $rows);
        $ids = array_column($rows, array_search(__('fields.id_number'), $headings, true));
        $this->assertCount(12, array_unique($ids));
    }

    public function test_the_file_is_the_same_as_the_existing_export_across_chunk_boundaries(): void
    {
        // Episodes on one admission date with visits of different lengths,
        // read three at a time: the visit columns come from the saved keys.
        foreach (range(1, 10) as $i) {
            $episode = $this->episode((string) (470910000 + $i), $i % 2 === 0 ? 'defaulted' : null);

            for ($n = 2; $n <= 1 + ($i % 4); $n++) {
                $episode->visits()->create(['visit_number' => $n, 'visit_date' => '2026-06-0' . $n, 'muac' => 110 + $n]);
            }
        }

        $query = FollowUpChild::query()->orderBy('id');
        $keys = FollowUpChild::query()->orderBy('id')->pluck('id')->all();

        // The existing writer's output, as the reference.
        $handle = fopen('php://memory', 'w+');
        (new FollowUpChildrenExport(clone $query))->writeCsv($handle);
        rewind($handle);
        $reference = stream_get_contents($handle);
        fclose($handle);

        $path = CsvExport::build(new FollowUpChildrenExport(FollowUpChild::withTrashed()), $keys, 3);
        $written = file_get_contents($path);
        @unlink($path);

        $this->assertSame($this->parse($reference), $this->parse($written), 'Same headings, same rows, same values.');
    }

    public function test_visit_columns_are_settled_on_the_saved_keys_only(): void
    {
        $short = $this->episode('470920001', null);
        $long = $this->episode('470920002', null);

        foreach (range(2, 6) as $n) {
            $long->visits()->create(['visit_number' => $n, 'visit_date' => '2026-06-0' . $n, 'muac' => 115]);
        }

        // Only the short episode is exported: one visit's columns, not six.
        $path = CsvExport::build(new FollowUpChildrenExport(FollowUpChild::withTrashed()), [$short->id]);
        [$headings] = $this->parse(file_get_contents($path));
        @unlink($path);

        $this->assertContains(__('fields.visit_date_n', ['n' => 1]), $headings);
        $this->assertNotContains(__('fields.visit_date_n', ['n' => 2]), $headings);
    }

    public function test_an_episode_gone_since_the_click_stops_the_export_and_the_user_is_sent_back(): void
    {
        foreach (range(1, 5) as $i) {
            $this->episode((string) (470930000 + $i), null);
        }

        $token = CsvExportTicket::issue(
            new FollowUpChildrenExport(FollowUpChild::query()->orderBy('id')),
            'follow_up_children.export',
            'follow-up-children.csv',
            'FollowUpChild',
            self::RETURN_URL,
        );

        FollowUpChild::withTrashed()->orderBy('id')->first()->forceDelete();

        $this->from('https://elsewhere.example')
            ->get(route('exports.csv', ['ticket' => $token]))
            ->assertRedirect(self::RETURN_URL);

        $this->expectException(IncompleteCsvExportException::class);
        CsvExport::build(new FollowUpChildrenExport(FollowUpChild::withTrashed()), ['999999']);
    }

    public function test_the_route_answers_404_to_an_unknown_or_foreign_ticket_and_403_without_the_permission(): void
    {
        $this->episode('470940001', null);

        $this->get(route('exports.csv', ['ticket' => 'unknown']))->assertNotFound();

        $token = CsvExportTicket::issue(
            new FollowUpChildrenExport(FollowUpChild::query()),
            'follow_up_children.export',
            'follow-up-children.csv',
            'FollowUpChild',
            self::RETURN_URL,
        );

        $this->actingAs(User::factory()->create())
            ->get(route('exports.csv', ['ticket' => $token]))
            ->assertNotFound();

        $this->user->syncRoles([]);
        $this->user->syncPermissions([]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->user->fresh())
            ->get(route('exports.csv', ['ticket' => $token]))
            ->assertForbidden();
    }

    private function episode(string $idNumber, ?string $outcome): FollowUpChild
    {
        $episode = FollowUpChild::create([
            'id_number' => $idNumber,
            'child_name' => 'Test child',
            'sex' => 'M',
            'dob' => '2025-01-01',
            'mobile_number' => '0599123456',
            'shelter_name' => 'Mosaab camp',
            'governorate' => 'Gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_date' => '2026-06-01',
            'discharge_date' => $outcome === null ? null : '2026-06-20',
            'discharge_outcome' => $outcome ?? FollowUpChild::ACTIVE_OUTCOME,
        ]);

        $episode->visits()->create(['visit_number' => 1, 'visit_date' => '2026-06-01', 'muac' => 110]);

        return $episode;
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
