<?php

namespace Tests\Feature;

use App\Exports\FollowUpChildrenExport;
use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Filament\Resources\FollowUpChildResource\Pages\EditFollowUpChild;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Filament\Resources\FollowUpChildResource\Pages\ViewFollowUpChild;
use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Support\ChildFollowUpTransfer;
use App\Support\ImportSchema;
use App\Support\MuacClassifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use App\Services\ExcelImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two automatic transfers between the Children module and the Follow Up
 * Child module, and the record lock that a discharge leaves behind.
 */
class ChildFollowUpTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function childFormData(int $muac, string $childId = '123456789'): array
    {
        return [
            'child_id' => $childId,
            'name' => 'طفل الاختبار',
            'phone_number' => '0591234567',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'date_of_reporting' => now()->format('Y-m-d'),
            'screener_profession' => 'CHW',
            'sex' => 'male',
            'date_of_birth' => now()->subMonths(18)->format('Y-m-d'),
            'muac_mm' => $muac,
            'governorate' => 'gaza',
            'municipality' => 'gaza',
            'location' => 'مركز الإيواء أ',
            'type_of_site' => 'El Salam Camp',
            'mother_marital_status' => 'متزوجة',
        ];
    }

    // -----------------------------------------------------------------
    // Children -> Follow Up Child (admission)
    // -----------------------------------------------------------------

    public function test_a_normal_reading_is_saved_as_an_ordinary_new_children_visit(): void
    {
        Livewire::test(CreateChild::class)
            ->fillForm($this->childFormData(130))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotDispatched('show-child-referral-alert');

        $child = Child::firstWhere('child_id', '123456789');

        $this->assertNotNull($child);
        $this->assertSame('new', $child->visit_type);
        $this->assertSame('Normal', $child->fi);
        $this->assertSame(0, FollowUpChild::count());
    }

    public function test_a_malnourished_reading_only_raises_the_confirmation_and_saves_nothing(): void
    {
        Child::factory()->create(['child_id' => '123456789', 'muac_mm' => 130]);

        Livewire::test(CreateChild::class)
            ->fillForm($this->childFormData(110))
            ->call('create')
            ->assertDispatched('show-child-referral-alert');

        // Cancelling is simply not answering: nothing was written anywhere.
        $this->assertSame(1, Child::count());
        $this->assertSame(0, FollowUpChild::count());
    }

    public function test_confirming_the_referral_creates_the_follow_up_record_and_no_children_row(): void
    {
        $previous = Child::factory()->create(['child_id' => '123456789', 'muac_mm' => 130]);

        Livewire::test(CreateChild::class)
            ->fillForm($this->childFormData(110))
            ->call('create')
            ->assertDispatched('show-child-referral-alert')
            ->call('confirmChildReferral');

        // No second Children row for the same reading.
        $this->assertSame(1, Child::count());

        $followUp = FollowUpChild::with('visits')->firstWhere('id_number', '123456789');

        $this->assertNotNull($followUp);
        $this->assertSame('SAM', $followUp->admitted_with);
        $this->assertSame('malnutrition', $followUp->causes_of_admission);
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $followUp->discharge_outcome);
        $this->assertSame('طفل الاختبار', $followUp->child_name);
        $this->assertSame('M', $followUp->sex);
        $this->assertSame('مركز الإيواء أ', $followUp->shelter_name);
        $this->assertSame(now()->format('Y-m-d'), $followUp->admission_date->format('Y-m-d'));
        $this->assertSame($previous->id, $followUp->source_child_visit_id);

        $this->assertCount(1, $followUp->visits);
        $firstVisit = $followUp->visits->first();
        $this->assertSame(1, $firstVisit->visit_number);
        $this->assertSame('SAM', $firstVisit->fi);
        $this->assertEqualsWithDelta(110.0, (float) $firstVisit->muac, 0.001);
        $this->assertSame('SAM', $firstVisit->getRawOriginal('fi'));
    }

    // A first ever visit - no active record in either module - is a screening
    // event in its own right, so it is written to Children as well.

    public function test_a_first_ever_visit_is_saved_in_both_modules(): void
    {
        Livewire::test(CreateChild::class)
            ->fillForm($this->childFormData(110))
            ->call('create')
            ->assertDispatched('show-child-referral-alert')
            ->call('confirmChildReferral');

        $child = Child::firstWhere('child_id', '123456789');

        $this->assertNotNull($child, 'A first ever visit must leave a Children row behind.');
        $this->assertSame(1, Child::count());
        $this->assertSame('new', $child->visit_type);
        $this->assertSame('SAM', $child->fi);
        $this->assertEqualsWithDelta(110.0, (float) $child->muac_mm, 0.001);
        // Every field actually entered on the form is stored, not just the reading.
        $this->assertSame('طفل الاختبار', $child->name);
        $this->assertSame('0591234567', $child->phone_number);
        $this->assertSame('El Salam Camp', $child->type_of_site);

        $followUp = FollowUpChild::with('visits')->firstWhere('id_number', '123456789');

        $this->assertNotNull($followUp);
        $this->assertSame('SAM', $followUp->admitted_with);
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $followUp->discharge_outcome);

        $visit = $followUp->visits->first();
        $this->assertSame(1, $visit->visit_number);
        $this->assertSame('SAM', $visit->fi);
        $this->assertEqualsWithDelta(110.0, (float) $visit->muac, 0.001);
        $this->assertSame(
            $child->date_of_reporting->format('Y-m-d'),
            $visit->visit_date->format('Y-m-d'),
            'The first visit carries the date of the reading it came from.',
        );

        // The two records point at each other, so a report can always tell
        // this screening apart from an ordinary one.
        $this->assertSame($followUp->id, $child->source_follow_up_child_id);
        $this->assertSame($child->id, $followUp->source_child_visit_id);
    }

    public function test_a_first_ever_mam_visit_is_saved_in_both_modules(): void
    {
        Livewire::test(CreateChild::class)
            ->fillForm($this->childFormData(120))
            ->call('create')
            ->call('confirmChildReferral');

        $child = Child::firstWhere('child_id', '123456789');

        $this->assertNotNull($child);
        $this->assertSame('new', $child->visit_type);
        $this->assertSame('MAM', $child->fi);

        $followUp = FollowUpChild::firstWhere('id_number', '123456789');

        $this->assertSame('MAM', $followUp->admitted_with);
        $this->assertSame('MAM', $followUp->visits->first()->fi);
        $this->assertSame($followUp->id, $child->source_follow_up_child_id);
    }

    public function test_cancelling_a_first_ever_visit_still_writes_nothing(): void
    {
        Livewire::test(CreateChild::class)
            ->fillForm($this->childFormData(110))
            ->call('create')
            ->assertDispatched('show-child-referral-alert');

        $this->assertSame(0, Child::count());
        $this->assertSame(0, FollowUpChild::count());
    }

    /**
     * A child already known to the follow-up module is not a first visit
     * either, even with no Children row of their own.
     */
    public function test_a_child_already_in_follow_up_is_not_a_first_ever_visit(): void
    {
        FollowUpChild::factory()->create(['id_number' => '123456789']);

        Livewire::test(CreateChild::class)
            ->fillForm($this->childFormData(110))
            ->call('create')
            ->call('confirmChildReferral');

        $this->assertSame(0, Child::count(), 'Only a genuinely first visit writes a Children row.');
        $this->assertSame(2, FollowUpChild::count());
    }

    public function test_a_trashed_record_makes_the_next_registration_a_first_visit_again(): void
    {
        Child::factory()->create(['child_id' => '123456789', 'muac_mm' => 130])->delete();

        $this->assertTrue(ChildFollowUpTransfer::isFirstEverVisit('123456789'));

        Livewire::test(CreateChild::class)
            ->fillForm($this->childFormData(110))
            ->call('create')
            ->call('confirmChildReferral');

        $this->assertSame(1, Child::count(), 'The trashed row does not count, so this is a first visit.');
        $this->assertSame('new', Child::first()->visit_type);
        $this->assertSame(1, FollowUpChild::count());
    }

    // A later relapse keeps the original behaviour: the reading goes to the
    // follow-up record only.

    public function test_a_later_relapse_writes_no_second_children_row(): void
    {
        $previous = Child::factory()->create([
            'child_id' => '123456789',
            'muac_mm' => 130,
            'date_of_reporting' => now()->subMonth(),
        ]);

        $this->assertFalse(ChildFollowUpTransfer::isFirstEverVisit('123456789'));

        Livewire::test(CreateChild::class)
            ->fillForm($this->childFormData(110))
            ->call('create')
            ->assertDispatched('show-child-referral-alert')
            ->call('confirmChildReferral');

        $this->assertSame(1, Child::count(), 'A relapse reading must not be counted in Children again.');
        $this->assertNull($previous->fresh()->source_follow_up_child_id);

        $followUp = FollowUpChild::firstWhere('id_number', '123456789');

        $this->assertSame('SAM', $followUp->admitted_with);
        $this->assertSame('SAM', $followUp->visits->first()->fi);
        // The link points at the earlier visit, which is the only Children row.
        $this->assertSame($previous->id, $followUp->source_child_visit_id);
    }

    // -----------------------------------------------------------------
    // Follow Up Child -> Children (discharge)
    // -----------------------------------------------------------------

    private function activeFollowUpChild(): FollowUpChild
    {
        $record = FollowUpChild::factory()->create([
            'id_number' => '987654321',
            'child_name' => 'طفل المتابعة',
            'sex' => 'F',
            'dob' => now()->subMonths(20)->format('Y-m-d'),
            'mobile_number' => '0599999999',
            'shelter_name' => 'مركز الإيواء ب',
            'governorate' => 'gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_date' => now()->subMonths(2)->format('Y-m-d'),
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        $record->visits()->create([
            'visit_number' => 1,
            'visit_date' => now()->subMonths(2)->format('Y-m-d'),
            'muac' => 110,
        ]);

        return $record->refresh();
    }

    /**
     * The visits repeater state for the seeded first visit plus one more.
     *
     * @return array<int, array<string, mixed>>
     */
    private function visitsWith(string $date, int $muac): array
    {
        return [
            ['visit_date' => now()->subMonths(2)->format('Y-m-d'), 'muac' => 110],
            ['visit_date' => $date, 'muac' => $muac],
        ];
    }

    public function test_a_normal_latest_visit_raises_the_discharge_confirmation(): void
    {
        $record = $this->activeFollowUpChild();

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm(['visits' => $this->visitsWith('2026-06-01', 130)])
            ->call('save')
            ->assertDispatched('show-follow-up-discharge-alert');

        // Declining is simply not answering: nothing transferred, nothing locked.
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $record->fresh()->discharge_outcome);
        $this->assertSame(0, Child::count());
    }

    public function test_confirming_the_discharge_cures_locks_and_hands_the_child_back(): void
    {
        $record = $this->activeFollowUpChild();

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm(['visits' => $this->visitsWith('2026-06-01', 130)])
            ->call('save')
            ->call('confirmFollowUpDischarge');

        $record->refresh();

        $this->assertSame('cured', $record->discharge_outcome);
        $this->assertSame('2026-06-01', $record->discharge_date->format('Y-m-d'));
        $this->assertTrue($record->isLocked());

        $child = Child::firstWhere('child_id', '987654321');

        $this->assertNotNull($child);
        $this->assertSame('new', $child->visit_type);
        $this->assertSame('female', $child->sex);
        $this->assertSame('Normal', $child->fi);
        $this->assertEqualsWithDelta(130.0, (float) $child->muac_mm, 0.001);
        $this->assertSame('2026-06-01', $child->date_of_reporting->format('Y-m-d'));
        $this->assertSame('مركز الإيواء ب', $child->location);
        $this->assertSame($record->id, $child->source_follow_up_child_id);
    }

    public function test_keeping_the_child_under_follow_up_changes_nothing(): void
    {
        $record = $this->activeFollowUpChild();

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm(['visits' => $this->visitsWith('2026-06-01', 130)])
            ->call('save')
            ->call('keepUnderFollowUp');

        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $record->fresh()->discharge_outcome);
        $this->assertSame(0, Child::count());
        $this->assertSame(2, $record->visits()->count());
    }

    public function test_a_still_malnourished_latest_visit_raises_nothing(): void
    {
        $record = $this->activeFollowUpChild();

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm(['visits' => $this->visitsWith('2026-06-01', 118)])
            ->call('save')
            ->assertNotDispatched('show-follow-up-discharge-alert');
    }

    // -----------------------------------------------------------------
    // The lock
    // -----------------------------------------------------------------

    public function test_every_manual_discharge_outcome_locks_the_record_without_creating_a_child(): void
    {
        foreach (['defaulted', 'discharge_to_opt', 'discharge_to_other', 'died'] as $outcome) {
            $record = FollowUpChild::factory()->create(['discharge_outcome' => $outcome]);

            $this->assertTrue($record->isLocked(), "[{$outcome}] must lock the record.");
        }

        $this->assertSame(0, Child::count());
    }

    public function test_an_active_or_blank_outcome_leaves_the_record_open(): void
    {
        $active = FollowUpChild::factory()->create(['discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME]);
        $blank = FollowUpChild::factory()->create(['discharge_outcome' => null]);

        $this->assertFalse($active->isLocked());
        $this->assertFalse($blank->isLocked());
    }

    public function test_a_locked_record_refuses_a_save(): void
    {
        $record = $this->activeFollowUpChild();
        $record->update(['discharge_outcome' => 'died']);

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm(['notes' => 'محاولة تعديل'])
            ->call('save');

        $this->assertNull($record->fresh()->notes);
    }

    public function test_the_listing_marks_a_discharged_record_as_locked(): void
    {
        $active = $this->activeFollowUpChild();
        $locked = FollowUpChild::factory()->create(['discharge_outcome' => 'died']);

        Livewire::test(ListFollowUpChildren::class)
            ->assertCanSeeTableRecords([$active, $locked])
            ->assertTableColumnStateSet('record_state', __('fields.record_active'), $active)
            ->assertTableColumnStateSet('record_state', __('fields.record_locked'), $locked);
    }

    public function test_the_view_page_shows_the_fi_of_each_visit(): void
    {
        $record = $this->activeFollowUpChild();
        $record->visits()->create(['visit_number' => 2, 'visit_date' => '2026-06-01', 'muac' => 130]);

        Livewire::test(ViewFollowUpChild::class, ['record' => $record->getKey()])
            ->assertSuccessful()
            ->assertSee('SAM')
            ->assertSee('Normal');
    }

    // -----------------------------------------------------------------
    // FI on the visits themselves
    // -----------------------------------------------------------------

    public function test_visit_fi_is_derived_from_muac_and_never_typed_in(): void
    {
        $record = FollowUpChild::factory()->create();

        $visit = $record->visits()->create([
            'visit_number' => 1,
            'visit_date' => '2026-01-01',
            'muac' => 110,
            // Ignored: the mutator always re-derives it.
            'fi' => 'Normal',
        ]);

        $this->assertSame('SAM', $visit->fresh()->fi);

        $visit->update(['muac' => 130]);

        $this->assertSame('Normal', $visit->fresh()->fi);
        $this->assertSame('Normal', $visit->fresh()->getRawOriginal('fi'));
    }

    public function test_the_shared_classifier_keeps_the_existing_thresholds(): void
    {
        $this->assertSame('SAM', MuacClassifier::classify(115));
        $this->assertSame('MAM', MuacClassifier::classify(116));
        $this->assertSame('MAM', MuacClassifier::classify(124));
        $this->assertSame('Normal', MuacClassifier::classify(125));
        $this->assertNull(MuacClassifier::classify(null));

        foreach ([null, '', 110, 120, 130] as $muac) {
            $this->assertSame(MuacClassifier::classify($muac), Child::classifyMuac($muac));
        }
    }

    // -----------------------------------------------------------------
    // Export and import
    // -----------------------------------------------------------------

    public function test_the_export_appends_an_fi_column_after_each_visit_pair(): void
    {
        $record = $this->activeFollowUpChild();
        $record->visits()->create(['visit_number' => 2, 'visit_date' => '2026-06-01', 'muac' => 130]);

        $export = new FollowUpChildrenExport(FollowUpChild::query());
        $headings = $export->headings();
        $row = $export->map($record->fresh()->load('visits'));

        $base = count($export->fields());

        $this->assertSame(__('fields.visit_date_n', ['n' => 1]), $headings[$base]);
        $this->assertSame(__('fields.visit_muac_n', ['n' => 1]), $headings[$base + 1]);
        $this->assertSame(__('fields.visit_fi_n', ['n' => 1]), $headings[$base + 2]);
        $this->assertSame(__('fields.visit_date_n', ['n' => 2]), $headings[$base + 3]);

        $this->assertSame('SAM', $row[$base + 2]);
        $this->assertSame('Normal', $row[$base + 5]);
        $this->assertCount(count($headings), $row);
    }

    public function test_the_import_template_still_carries_only_date_and_muac_per_visit(): void
    {
        $schema = new ImportSchema(ImportDefinition::get('follow_up_children'));
        $headings = $schema->headings();

        $this->assertContains(__('fields.visit_muac_n', ['n' => 1]), $headings);
        $this->assertNotContains(__('fields.visit_fi_n', ['n' => 1]), $headings);

        // An exported file's extra FI column is simply ignored on the way in.
        $this->assertNull($schema->resolveHeading(__('fields.visit_fi_n', ['n' => 1])));
    }

    public function test_importing_a_malnourished_child_writes_an_ordinary_children_row(): void
    {
        $headings = (new ImportSchema(ImportDefinition::get('children')))->headings();

        $row = array_fill(0, count($headings), null);
        foreach ([
            'child_id' => '111222333',
            'name' => 'طفل مستورد',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'date_of_reporting' => '2026-05-01',
            'sex' => __('fields.male'),
            'governorate' => 'gaza',
            'muac_mm' => 110,
        ] as $field => $value) {
            $index = array_search(__('fields.' . $field), $headings, true);
            $this->assertNotFalse($index, "No [{$field}] column in the children template.");
            $row[$index] = $value;
        }

        $result = $this->importSheet('children', [$headings, $row]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        // The bulk upload never asks anything and never transfers: a SAM
        // reading is simply a Children row, exactly as before this feature.
        $child = Child::firstWhere('child_id', '111222333');

        $this->assertNotNull($child);
        $this->assertSame('SAM', $child->fi);
        $this->assertSame(0, FollowUpChild::count());
    }

    /**
     * An older workbook - one exported before the visit FI column existed -
     * still imports, and the extra FI column of a new export is ignored.
     */
    public function test_follow_up_child_files_import_with_and_without_the_new_visit_fi_column(): void
    {
        $schema = new ImportSchema(ImportDefinition::get('follow_up_children'));
        $headings = $schema->headings();

        $values = [
            'id_number' => '555666777',
            'child_name' => 'طفل مستورد للمتابعة',
            'sex' => __('fields.M'),
            'governorate' => 'gaza',
            'admitted_with' => 'SAM',
            'admission_date' => '2026-04-01',
        ];

        $row = array_fill(0, count($headings), null);
        foreach ($values as $field => $value) {
            $index = array_search(__('fields.' . $field), $headings, true);
            $this->assertNotFalse($index, "No [{$field}] column in the follow-up template.");
            $row[$index] = $value;
        }

        $row[array_search(__('fields.visit_date_n', ['n' => 1]), $headings, true)] = '2026-04-01';
        $row[array_search(__('fields.visit_muac_n', ['n' => 1]), $headings, true)] = 110;

        // The old file: template columns only, no visit FI anywhere.
        $old = $this->importSheet('follow_up_children', [$headings, $row]);

        $this->assertSame([], $old['errors']);
        $this->assertSame(1, $old['imported']);
        $this->assertSame('SAM', FollowUpChild::firstWhere('id_number', '555666777')->visits->first()->fi);

        // A file straight out of the new export, extra FI column and all.
        $values['id_number'] = '555666778';
        $newHeadings = array_merge($headings, [__('fields.visit_fi_n', ['n' => 1])]);
        $newRow = array_merge($row, ['Normal']);
        $newRow[array_search(__('fields.id_number'), $headings, true)] = '555666778';

        $new = $this->importSheet('follow_up_children', [$newHeadings, $newRow]);

        $this->assertSame([], $new['errors']);
        $this->assertSame(1, $new['imported']);

        // The FI cell in the file is ignored; FI stays derived from the MUAC.
        $this->assertSame('SAM', FollowUpChild::firstWhere('id_number', '555666778')->visits->first()->fi);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{imported: int, errors: array<string>}
     */
    private function importSheet(string $key, array $rows): array
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

        $name = 'transfer-test-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return app(ExcelImportService::class)->import(
            ImportDefinition::get($key),
            Storage::disk('local')->path($name),
        );
    }
}
