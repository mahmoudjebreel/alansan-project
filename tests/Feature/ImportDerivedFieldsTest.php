<?php

namespace Tests\Feature;

use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\GroupSession;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\ImportSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * The columns the system derives must be derived on the way in too.
 *
 * A bulk upload used to be the one door through which a hand-typed visit type
 * or age could reach the database, bypassing the locked form field and the
 * relapse / status-switch rules that decide it.
 */
class ImportDerivedFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        \Storage::fake('local');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    private function makeSheet(array $rows): string
    {
        $export = new class($rows) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = 'derived-test-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return \Storage::disk('local')->path($name);
    }

    private function import(string $key, array $rows): array
    {
        return app(ExcelImportService::class)->import(
            ImportDefinition::get($key),
            $this->makeSheet($rows),
        );
    }

    /**
     * @param  array<string, mixed>  $values  translated heading => cell value
     */
    private function sheet(string $key, array ...$records): array
    {
        $headings = (new ImportSchema(ImportDefinition::get($key)))->headings();
        $rows = [$headings];

        foreach ($records as $values) {
            $row = array_fill(0, count($headings), null);

            foreach ($values as $heading => $value) {
                $index = array_search($heading, $headings, true);
                $this->assertNotFalse($index, "No [{$heading}] column in the [{$key}] template.");
                $row[$index] = $value;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    // -----------------------------------------------------------------
    // Children
    // -----------------------------------------------------------------

    public function test_a_child_visit_type_in_the_file_cannot_override_the_relapse_rule(): void
    {
        // An existing active visit at MUAC 130 (Normal).
        Child::create([
            'child_id' => '111111111',
            'name' => 'Existing',
            'sex' => 'male',
            'governorate' => 'Gaza',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'date_of_reporting' => '2026-01-01',
            'muac_mm' => 130,
            'visit_type' => 'new',
        ]);

        // The file claims "new" for a stable reading, which is a follow up.
        $result = $this->import('children', $this->sheet('children', [
            __('fields.visit_type') => __('fields.new'),
            __('fields.child_id') => '111111111',
            __('fields.name') => 'Existing',
            __('fields.sex') => __('fields.male'),
            __('fields.governorate') => 'Gaza',
            __('fields.organization') => 'AEI',
            __('fields.implementing_partner') => 'SCI',
            __('fields.date_of_reporting') => '2026-02-01',
            __('fields.muac_mm') => 132,
        ]));

        $this->assertSame([], $result['errors']);

        $imported = Child::orderByDesc('id')->firstOrFail();

        $this->assertSame('follow_up', $imported->visit_type);
    }

    public function test_a_deteriorating_child_reading_is_imported_as_a_new_admission(): void
    {
        Child::create([
            'child_id' => '222222222',
            'name' => 'Existing',
            'sex' => 'female',
            'governorate' => 'Gaza',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'date_of_reporting' => '2026-01-01',
            'muac_mm' => 130,
            'visit_type' => 'new',
        ]);

        // The file claims "follow up"; MUAC 110 is SAM, a relapse, so "new".
        $result = $this->import('children', $this->sheet('children', [
            __('fields.visit_type') => __('fields.follow_up'),
            __('fields.child_id') => '222222222',
            __('fields.name') => 'Existing',
            __('fields.sex') => __('fields.female'),
            __('fields.governorate') => 'Gaza',
            __('fields.organization') => 'AEI',
            __('fields.implementing_partner') => 'SCI',
            __('fields.date_of_reporting') => '2026-02-01',
            __('fields.muac_mm') => 110,
        ]));

        $this->assertSame([], $result['errors']);

        $imported = Child::orderByDesc('id')->firstOrFail();

        $this->assertSame('new', $imported->visit_type);
        $this->assertSame('SAM', $imported->fi);
    }

    public function test_a_child_age_in_months_is_recalculated_from_the_date_of_birth(): void
    {
        $result = $this->import('children', $this->sheet('children', [
            __('fields.visit_type') => __('fields.new'),
            __('fields.child_id') => '333333333',
            __('fields.name') => 'Age Test',
            __('fields.sex') => __('fields.male'),
            __('fields.governorate') => 'Gaza',
            __('fields.organization') => 'AEI',
            __('fields.implementing_partner') => 'SCI',
            __('fields.date_of_reporting') => '2026-02-01',
            __('fields.date_of_birth') => now()->subMonthsNoOverflow(18)->format('Y-m-d'),
            // A nonsense age in the file; it must be replaced, not stored.
            __('fields.age_months') => 999,
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame(18, Child::where('child_id', '333333333')->firstOrFail()->age_months);
    }

    // -----------------------------------------------------------------
    // Pregnant / lactating women
    // -----------------------------------------------------------------

    public function test_a_woman_keeping_her_status_is_imported_as_a_follow_up(): void
    {
        PregnantLactatingWoman::create([
            'mother_id' => '444444444',
            'full_name_ar' => 'سيدة',
            'governorate' => 'Gaza',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'date_of_reporting' => '2026-01-01',
            'status_type' => 'pregnant',
            'muac_mm' => 240,
            'visit_type' => 'new',
        ]);

        $result = $this->import('pregnant', $this->sheet('pregnant', [
            __('fields.visit_type') => __('fields.new'),
            __('fields.mother_id') => '444444444',
            __('fields.full_name_ar') => 'سيدة',
            __('fields.governorate') => 'Gaza',
            __('fields.organization') => 'AEI',
            __('fields.implementing_partner') => 'SCI',
            __('fields.date_of_reporting') => '2026-02-01',
            __('fields.status_type') => __('fields.pregnant'),
            __('fields.muac_mm') => 235,
        ]));

        $this->assertSame([], $result['errors']);

        $imported = PregnantLactatingWoman::orderByDesc('id')->firstOrFail();

        $this->assertSame('follow_up', $imported->visit_type);
    }

    public function test_a_woman_switching_status_is_imported_as_a_new_admission(): void
    {
        PregnantLactatingWoman::create([
            'mother_id' => '555555555',
            'full_name_ar' => 'سيدة',
            'governorate' => 'Gaza',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'date_of_reporting' => '2026-01-01',
            'status_type' => 'pregnant',
            'muac_mm' => 240,
            'visit_type' => 'new',
        ]);

        $result = $this->import('pregnant', $this->sheet('pregnant', [
            __('fields.visit_type') => __('fields.follow_up'),
            __('fields.mother_id') => '555555555',
            __('fields.full_name_ar') => 'سيدة',
            __('fields.governorate') => 'Gaza',
            __('fields.organization') => 'AEI',
            __('fields.implementing_partner') => 'SCI',
            __('fields.date_of_reporting') => '2026-02-01',
            __('fields.status_type') => __('fields.lactating'),
            __('fields.muac_mm') => 235,
        ]));

        $this->assertSame([], $result['errors']);

        $imported = PregnantLactatingWoman::orderByDesc('id')->firstOrFail();

        $this->assertSame('new', $imported->visit_type);
    }

    // -----------------------------------------------------------------
    // Group sessions
    // -----------------------------------------------------------------

    public function test_a_group_session_visit_type_comes_from_the_id_number_not_the_file(): void
    {
        GroupSession::create([
            'session_date' => '2026-01-01',
            'session_group_number' => 'G1',
            'session_subject' => 'bf_support',
            'locality' => 'karamah',
            'shelter_name' => 'el_salam',
            'id_number' => '666666666',
            'full_name_ar' => 'مشاركة',
            'visit_type' => 'new',
            'category' => 'pregnant',
            'marital_status' => 'married',
        ]);

        $result = $this->import('group_sessions', $this->sheet('group_sessions', [
            __('fields.session_date') => '2026-02-01',
            __('fields.session_group_number') => 'G2',
            __('fields.session_subject') => __('fields.relactation'),
            __('fields.locality') => __('fields.karamah'),
            __('fields.shelter_name') => __('fields.el_salam'),
            __('fields.id_number') => '666666666',
            __('fields.full_name_ar') => 'مشاركة',
            // The file insists on "new"; an active session makes it a follow up.
            __('fields.visit_type') => __('fields.new'),
            __('fields.category') => __('fields.pregnant'),
            __('fields.marital_status') => __('fields.married'),
        ]));

        $this->assertSame([], $result['errors']);

        $imported = GroupSession::where('session_group_number', 'G2')->firstOrFail();

        $this->assertSame('follow_up', $imported->visit_type);
    }

    /**
     * A soft-deleted session is out of the system, so the same ID number starts
     * over as a brand new participant - the rule the whole module is built on.
     */
    public function test_a_trashed_previous_session_does_not_make_an_import_a_follow_up(): void
    {
        $previous = GroupSession::create([
            'session_date' => '2026-01-01',
            'session_group_number' => 'G1',
            'session_subject' => 'bf_support',
            'locality' => 'karamah',
            'shelter_name' => 'el_salam',
            'id_number' => '777777777',
            'full_name_ar' => 'مشاركة',
            'visit_type' => 'new',
            'category' => 'pregnant',
            'marital_status' => 'married',
        ]);

        $previous->delete();

        $result = $this->import('group_sessions', $this->sheet('group_sessions', [
            __('fields.session_date') => '2026-02-01',
            __('fields.session_group_number') => 'G2',
            __('fields.session_subject') => __('fields.relactation'),
            __('fields.locality') => __('fields.karamah'),
            __('fields.shelter_name') => __('fields.el_salam'),
            __('fields.id_number') => '777777777',
            __('fields.full_name_ar') => 'مشاركة',
            __('fields.visit_type') => __('fields.follow_up'),
            __('fields.category') => __('fields.pregnant'),
            __('fields.marital_status') => __('fields.married'),
        ]));

        $this->assertSame([], $result['errors']);

        $imported = GroupSession::where('session_group_number', 'G2')->firstOrFail();

        $this->assertSame('new', $imported->visit_type);
    }
}
