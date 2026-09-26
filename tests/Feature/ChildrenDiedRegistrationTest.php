<?php

namespace Tests\Feature;

use App\Filament\Pages\Trash;
use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Filament\Resources\ChildResource\Pages\EditChild;
use App\Filament\Resources\FollowUpChildResource\Pages\EditFollowUpChild;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\BulkRecordWriter;
use App\Support\ChildFollowUpTransfer;
use App\Support\ImportSchema;
use App\Support\Referral\CuredChildrenReferral;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Rule B: a child recorded as died in Follow-Up is never registered as a
 * child again - not by the Children form, not by an ID change on another
 * record, not by an upload, not by a restore from the trash, and not by the
 * write-back a cure performs. Trash included: a died episode in the trash,
 * or a later episode in the trash, changes nothing.
 *
 * The date rule stays as agreed for history: an upload, a restore or a
 * write-back dated before or on the death is history and is allowed; after
 * it, refused. A NEW registration by the form, or a record given the died
 * child's ID, is refused whatever its date.
 */
class ChildrenDiedRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const DIED = '470030001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Carbon::setTestNow('2026-09-20 10:00:00');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =================================================================
    // 1-4. Create Child
    // =================================================================

    public function test_1_a_died_id_cannot_be_registered_as_a_child(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');

        $this->assertRegistrationRefused(self::DIED);
    }

    public function test_1b_a_died_id_is_refused_even_when_the_death_is_dated_today(): void
    {
        // The case that used to slip through: a death recorded today and a
        // screening dated today were "the same day", which the date rule
        // allows for history. A new registration is decided by identity.
        $this->died(self::DIED, '2026-09-01', '2026-09-20');

        $this->assertRegistrationRefused(self::DIED);
    }

    public function test_2_a_died_episode_in_the_trash_still_refuses_the_registration(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20')->delete();

        $this->assertRegistrationRefused(self::DIED);

        // The form refuses the ID as soon as it is typed, trash or not...
        Livewire::test(CreateChild::class)
            ->set('data.child_id', self::DIED)
            ->assertHasErrors(['data.child_id']);

        // ...and the history the referral prompt reads says the child died,
        // even though the only died episode is in the trash.
        $history = $this->screening(self::DIED, '2026-04-10', 130);

        Livewire::test(EditChild::class, ['record' => $history->getKey()])
            ->set('data.child_id', '')
            ->set('data.child_id', self::DIED)
            ->assertDispatched('follow-up-history-known', fn (string $event, array $params): bool => ($params[0] ?? $params)['terminal'] === true);
    }

    public function test_3_a_later_trashed_episode_does_not_lift_the_refusal(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');
        $this->episode(self::DIED, 'cured', '2026-07-01', '2026-07-20')->delete();

        $this->assertRegistrationRefused(self::DIED);
    }

    public function test_4_a_child_who_did_not_die_is_still_registered(): void
    {
        $this->episode('470030002', 'cured', '2026-05-01', '2026-05-20');

        Livewire::test(CreateChild::class)
            ->fillForm($this->formData('470030002', 130))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Child::where('child_id', '470030002')->count());
    }

    // =================================================================
    // 5-6. Edit Child
    // =================================================================

    public function test_5_changing_a_records_id_to_a_died_id_is_refused_whatever_the_records_date(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');

        // A record dated well BEFORE the death: the date rule would allow it
        // as history, but this is an identity change, not history.
        $other = $this->screening('470030003', '2026-03-01', 130);

        Livewire::test(EditChild::class, ['record' => $other->getKey()])
            ->fillForm(['child_id' => self::DIED])
            ->call('save')
            ->assertHasFormErrors(['child_id']);

        $this->assertSame('470030003', $other->fresh()->child_id);
        $this->assertSame(0, Child::where('child_id', self::DIED)->count());

        // Audited once, as a refused save.
        $this->assertSame(1, Activity::query()->where('log_name', 'died_terminal')->count());
    }

    public function test_6_edits_that_do_not_change_the_id_still_work_and_so_does_a_change_to_a_living_child(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');

        // A screening of the died child from before the death is corrected.
        $history = $this->screening(self::DIED, '2026-04-10', 130);

        Livewire::test(EditChild::class, ['record' => $history->getKey()])
            ->fillForm(['name' => 'Corrected name', 'muac_mm' => 128])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Corrected name', $history->fresh()->name);

        // Another record's ID changed to a child who did not die.
        $this->episode('470030004', 'cured', '2026-05-01', '2026-05-20');
        $other = $this->screening('470030005', '2026-03-01', 130);

        Livewire::test(EditChild::class, ['record' => $other->getKey()])
            ->fillForm(['child_id' => '470030004'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('470030004', $other->fresh()->child_id);
    }

    // =================================================================
    // 7-8. Restore from the trash
    // =================================================================

    public function test_7_a_screening_dated_after_the_death_cannot_be_restored_and_history_can(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');
        $after = $this->screening(self::DIED, '2026-07-01', 110);
        $before = $this->screening(self::DIED, '2026-03-01', 130);
        $onTheDay = $this->screening(self::DIED, '2026-05-20', 118);

        foreach ([$after, $before, $onTheDay] as $record) {
            $record->delete();
        }

        $trash = Livewire::test(Trash::class)->instance();

        $this->assertFalse($trash->restore('child', $after->id));
        $this->assertTrue($after->fresh()->trashed());
        $this->assertFalse($after->fresh()->restore(), 'Not by the model either.');

        $this->assertTrue($trash->restore('child', $before->id));
        $this->assertTrue($trash->restore('child', $onTheDay->id));
        $this->assertFalse($before->fresh()->trashed());
        $this->assertFalse($onTheDay->fresh()->trashed());

        $this->assertSame('trash_restore', Activity::query()->where('log_name', 'died_terminal')->sole()->properties['workflow']);
    }

    public function test_7b_the_died_episode_may_itself_be_in_the_trash(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20')->delete();
        $after = $this->screening(self::DIED, '2026-07-01', 110);
        $after->delete();

        $this->assertFalse(Livewire::test(Trash::class)->instance()->restore('child', $after->id));
        $this->assertTrue($after->fresh()->trashed());
    }

    public function test_8_the_model_restore_and_both_bulk_restores_are_protected(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');
        $after = $this->screening(self::DIED, '2026-07-01', 110);
        $before = $this->screening(self::DIED, '2026-03-01', 130);
        $unrelated = $this->screening('470030006', '2026-07-01', 130);

        foreach ([$after, $before, $unrelated] as $record) {
            $record->delete();
        }

        // The Children listing's own restore action calls the model's
        // restore(), which refuses.
        $this->assertFalse($after->fresh()->restore());
        $this->assertTrue($after->fresh()->trashed());

        // The set-based restore the bulk actions run.
        BulkRecordWriter::restore(Child::onlyTrashed()->whereIn('id', [$after->id, $before->id, $unrelated->id]));

        $this->assertTrue($after->fresh()->trashed());
        $this->assertFalse($before->fresh()->trashed());
        $this->assertFalse($unrelated->fresh()->trashed());

        // The Trash page's bulk restore, and its notice.
        $before->delete();
        $unrelated->delete();

        Livewire::test(Trash::class)
            ->set('selected', ["child:{$after->id}", "child:{$before->id}", "child:{$unrelated->id}"])
            ->call('restoreSelected')
            ->assertNotified(__('ui.died_terminal.restore_refused_title'));

        $this->assertTrue($after->fresh()->trashed());
        $this->assertFalse($before->fresh()->trashed());
        $this->assertFalse($unrelated->fresh()->trashed());
    }

    // =================================================================
    // 9-10. Excel upload
    // =================================================================

    public function test_9_an_upload_with_a_died_id_is_refused_whole_and_writes_nothing(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');

        $result = $this->importChildren([
            ['470030007', '2026-08-01'],
            [self::DIED, '2026-08-01'],
            ['470030008', '2026-08-01'],
        ]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(__('ui.died_terminal.message'), implode(' ', $result['errors']));
        $this->assertSame(0, Child::count(), 'All or nothing: the valid rows were not written either.');
    }

    public function test_10_an_upload_is_refused_when_the_died_episode_is_in_the_trash(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20')->delete();

        $result = $this->importChildren([[self::DIED, '2026-08-01']]);

        $this->assertSame(0, $result['imported']);
        $this->assertStringContainsString(__('ui.died_terminal.message'), implode(' ', $result['errors']));
        $this->assertSame(0, Child::count());
    }

    public function test_10b_history_from_before_the_death_still_uploads(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');

        $result = $this->importChildren([[self::DIED, '2026-04-01']]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
    }

    // =================================================================
    // 11. The cure write-back
    // =================================================================

    public function test_11_discharging_an_episode_as_cured_does_not_write_a_children_record_after_the_death(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');

        // An open episode of the died child (data as it stands), whose last
        // visit - dated after the death - came back Normal.
        $open = $this->episode(self::DIED, FollowUpChild::ACTIVE_OUTCOME, '2026-07-01', null);
        $open->visits()->create(['visit_number' => 1, 'visit_date' => '2026-07-01', 'muac' => 110]);
        $open->visits()->create(['visit_number' => 2, 'visit_date' => '2026-07-15', 'muac' => 130]);

        Livewire::test(EditFollowUpChild::class, ['record' => $open->getKey()])
            ->call('confirmFollowUpDischarge')
            ->assertNotified(__('ui.died_terminal.children_record_refused'));

        $this->assertSame(0, Child::where('child_id', self::DIED)->count());
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $open->fresh()->discharge_outcome, 'Nothing was written.');
        $this->assertNull(ChildFollowUpTransfer::discharge($open->fresh(), $open->latestVisit()));
        $this->assertSame('follow_up_discharge', Activity::query()->where('log_name', 'died_terminal')->sole()->properties['workflow']);
    }

    public function test_11b_a_cure_dated_before_the_death_is_history_and_still_writes_back(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');

        $earlier = $this->episode(self::DIED, FollowUpChild::ACTIVE_OUTCOME, '2026-03-01', null);
        $earlier->visits()->create(['visit_number' => 1, 'visit_date' => '2026-03-01', 'muac' => 110]);
        $earlier->visits()->create(['visit_number' => 2, 'visit_date' => '2026-03-20', 'muac' => 130]);

        $child = ChildFollowUpTransfer::discharge($earlier, $earlier->latestVisit());

        $this->assertNotNull($child);
        $this->assertSame('2026-03-20', $child->date_of_reporting->format('Y-m-d'));
    }

    // =================================================================
    // 12. Send to Children
    // =================================================================

    public function test_12_sending_a_cured_episode_to_children_is_blocked_after_the_death(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');

        $cured = $this->episode(self::DIED, 'cured', '2026-07-01', '2026-07-20');
        $cured->visits()->create(['visit_number' => 1, 'visit_date' => '2026-07-01', 'muac' => 110]);
        $cured->visits()->create(['visit_number' => 2, 'visit_date' => '2026-07-20', 'muac' => 130]);

        $this->assertSame(CuredChildrenReferral::BLOCKER_DIED, CuredChildrenReferral::blocker($cured));
        $this->assertNull(CuredChildrenReferral::refer($cured));
        $this->assertSame(0, Child::where('child_id', self::DIED)->count());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'cured_pending_referral'])
            ->callTableAction('referToChildren', $cured)
            ->assertNotified(__('ui.cured_referral.blocked.died'));

        $this->assertSame(0, Child::where('child_id', self::DIED)->count());
        $this->assertSame('cured_referral', Activity::query()->where('log_name', 'died_terminal')->sole()->properties['workflow']);
    }

    public function test_12b_a_cure_from_before_the_death_and_a_living_childs_cure_still_go_to_children(): void
    {
        $this->died(self::DIED, '2026-05-01', '2026-05-20');

        $history = $this->episode(self::DIED, 'cured', '2026-03-01', '2026-03-20');
        $history->visits()->create(['visit_number' => 1, 'visit_date' => '2026-03-20', 'muac' => 130]);

        $living = $this->episode('470030009', 'cured', '2026-07-01', '2026-07-20');
        $living->visits()->create(['visit_number' => 1, 'visit_date' => '2026-07-20', 'muac' => 130]);

        $this->assertNull(CuredChildrenReferral::blocker($history));
        $this->assertNotNull(CuredChildrenReferral::refer($history));
        $this->assertNull(CuredChildrenReferral::blocker($living));
        $this->assertNotNull(CuredChildrenReferral::refer($living));
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function assertRegistrationRefused(string $idNumber): void
    {
        Livewire::test(CreateChild::class)
            ->fillForm($this->formData($idNumber, 130))
            ->call('create')
            ->assertHasFormErrors(['child_id']);

        $this->assertSame(0, Child::where('child_id', $idNumber)->count(), 'No Children record was created.');
    }

    private function died(string $idNumber, string $admitted, string $discharged): FollowUpChild
    {
        return $this->episode($idNumber, 'died', $admitted, $discharged);
    }

    private function episode(string $idNumber, string $outcome, string $admitted, ?string $discharged): FollowUpChild
    {
        return FollowUpChild::create([
            'id_number' => $idNumber,
            'child_name' => 'Test child',
            'sex' => 'M',
            'dob' => '2025-01-01',
            'mobile_number' => '0599123456',
            'shelter_name' => 'Mosaab camp',
            'governorate' => 'Gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_date' => $admitted,
            'discharge_date' => $discharged,
            'discharge_outcome' => $outcome,
        ]);
    }

    private function screening(string $childId, string $date, int $muac): Child
    {
        return Child::create([
            'visit_type' => 'new', 'name' => 'Test child', 'child_id' => $childId,
            'phone_number' => '0591234567', 'organization' => 'AEI', 'implementing_partner' => 'SCI',
            'screener_profession' => 'CHW', 'date_of_reporting' => $date, 'sex' => 'male',
            'date_of_birth' => '2025-01-01', 'muac_mm' => $muac, 'has_oedema' => false, 'is_pwd' => false,
            'governorate' => 'gaza', 'municipality' => 'gaza', 'location' => 'Mosaab camp',
            'type_of_site' => 'Mossab Camp', 'mother_marital_status' => 'متزوجة',
        ]);
    }

    private function formData(string $childId, int $muac): array
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
            'date_of_birth' => '2025-01-01',
            'muac_mm' => $muac,
            'governorate' => 'gaza',
            'municipality' => 'gaza',
            'location' => 'مركز الإيواء أ',
            'type_of_site' => 'El Salam Camp',
            'mother_marital_status' => 'متزوجة',
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows  [child ID, reporting date]
     * @return array{imported: int, errors: array<string>, skipped: array<string>}
     */
    private function importChildren(array $rows): array
    {
        $headings = (new ImportSchema(ImportDefinition::get('children')))->headings();
        $sheet = [$headings];

        foreach ($rows as [$childId, $date]) {
            $values = [
                __('fields.visit_type') => __('fields.new'),
                __('fields.child_id') => $childId,
                __('fields.name') => 'Test child',
                __('fields.sex') => __('fields.male'),
                __('fields.governorate') => 'Gaza',
                __('fields.organization') => 'AEI',
                __('fields.implementing_partner') => 'SCI',
                __('fields.date_of_reporting') => $date,
                __('fields.muac_mm') => 130,
            ];

            $row = array_fill(0, count($headings), null);

            foreach ($values as $heading => $value) {
                $index = array_search($heading, $headings, true);
                $this->assertNotFalse($index, "No [{$heading}] column.");
                $row[$index] = $value;
            }

            $sheet[] = $row;
        }

        $export = new class($sheet) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = 'died-registration-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return app(ExcelImportService::class)->import(
            ImportDefinition::get('children'),
            Storage::disk('local')->path($name),
        );
    }
}
