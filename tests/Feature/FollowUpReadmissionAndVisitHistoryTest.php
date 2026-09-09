<?php

namespace Tests\Feature;

use App\Exports\FollowUpChildrenExport;
use App\Filament\Pages\ReferralCenter;
use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Filament\Resources\FollowUpChildResource;
use App\Filament\Resources\FollowUpChildResource\Pages\EditFollowUpChild;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Filament\Resources\FollowUpChildResource\Pages\ViewFollowUpChild;
use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\ChildDuplicateChecker;
use App\Support\ChildFollowUpTransfer;
use App\Support\ImportSchema;
use App\Support\Referral\ReferralCandidates;
use App\Support\Referral\ReferralProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * A child's identity is independent of the state of their follow-up case.
 *
 * One child can have several follow-up episodes over time. A closed episode
 * is finished history: it is never reopened, never rewritten and never a
 * reason to treat the child as somebody new. A readmission is a NEW episode,
 * opened by a person choosing it, that follows the closed one. And within an
 * episode, every visit keeps its own record - attended or missed - so a
 * defaulter's history reads visit by visit rather than as one final word.
 */
class FollowUpReadmissionAndVisitHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** Every outcome that closes an episode, as the module defines them. */
    private const CLOSED_OUTCOMES = [
        'cured',
        'defaulted',
        'non_responded',
        'referred_medical_inpt',
        'discharge_to_opt',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * The real case: a child whose episode closed because they were referred
     * to hospital, with the two visits that were attended before that.
     */
    private function closedEpisode(string $outcome = 'referred_medical_inpt', string $idNumber = '470828468'): FollowUpChild
    {
        $episode = FollowUpChild::factory()->create([
            'id_number' => $idNumber,
            'child_name' => 'محمد عبد الكريم خليل عليوة',
            'sex' => 'M',
            'dob' => '2025-01-15',
            'mobile_number' => '0591111111',
            'shelter_name' => 'مركز الإيواء أ',
            'governorate' => 'gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_date' => '2026-08-19',
            'discharge_date' => '2026-08-26',
            'discharge_outcome' => $outcome,
        ]);

        $episode->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-19', 'muac' => 110]);
        $episode->visits()->create(['visit_number' => 2, 'visit_date' => '2026-08-26', 'muac' => 112]);

        return $episode->fresh();
    }

    private function openEpisode(string $idNumber = '470828468'): FollowUpChild
    {
        $episode = FollowUpChild::factory()->create([
            'id_number' => $idNumber,
            'child_name' => 'طفل تحت المتابعة',
            'sex' => 'F',
            'dob' => '2025-03-01',
            'mobile_number' => '0592222222',
            'shelter_name' => 'مركز الإيواء ب',
            'governorate' => 'gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_date' => '2026-09-01',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        $episode->visits()->create(['visit_number' => 1, 'visit_date' => '2026-09-01', 'muac' => 110]);

        return $episode->fresh();
    }

    /**
     * A snapshot of everything a closed episode carries, so "unchanged" can
     * be asserted column by column rather than trusted.
     *
     * @return array<string, mixed>
     */
    private function snapshot(FollowUpChild $episode): array
    {
        $episode = $episode->fresh();

        return [
            'attributes' => $episode->getAttributes(),
            'visits' => $episode->visits()->get()->map(fn (FollowUpChildVisit $visit): array => $visit->getAttributes())->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function childFormData(int $muac, string $childId = '470828468'): array
    {
        return [
            'child_id' => $childId,
            'name' => 'محمد عبد الكريم خليل عليوة',
            'phone_number' => '0591111111',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'date_of_reporting' => now()->format('Y-m-d'),
            'screener_profession' => 'CHW',
            'sex' => 'male',
            'date_of_birth' => '2025-01-15',
            'muac_mm' => $muac,
            'governorate' => 'gaza',
            'municipality' => 'gaza',
            'location' => 'مركز الإيواء أ',
            'type_of_site' => 'El Salam Camp',
            'mother_marital_status' => 'متزوجة',
        ];
    }

    /**
     * One Children sheet row, by field name, written to a real workbook and
     * pushed through the same import the upload button uses.
     *
     * @param  array<string, mixed>  $values
     * @return array{imported: int, errors: array}
     */
    private function importChildrenRow(array $values): array
    {
        $headings = (new ImportSchema(ImportDefinition::get('children')))->headings();

        $row = array_fill(0, count($headings), null);

        foreach ($values as $field => $value) {
            $index = array_search(__('fields.' . $field), $headings, true);
            $this->assertNotFalse($index, "No [{$field}] column in the children template.");
            $row[$index] = $value;
        }

        return $this->importSheet('children', [$headings, $row]);
    }

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

        $name = 'readmission-test-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return app(ExcelImportService::class)->import(
            ImportDefinition::get($key),
            Storage::disk('local')->path($name),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function childSheetRow(string $childId, int $muac): array
    {
        return [
            'child_id' => $childId,
            'name' => 'محمد عبد الكريم خليل عليوة',
            'organization' => 'AEI',
            'implementing_partner' => 'SCI',
            'date_of_reporting' => '2026-09-09',
            'sex' => __('fields.male'),
            'governorate' => 'gaza',
            'muac_mm' => $muac,
        ];
    }

    // =================================================================
    // TEST 1 - existing child + closed follow-up, imported again
    // =================================================================

    public function test_a_child_with_a_closed_follow_up_is_imported_as_an_existing_child(): void
    {
        Child::factory()->create([
            'child_id' => '470828468',
            'name' => 'محمد عبد الكريم خليل عليوة',
            'muac_mm' => 110,
            'date_of_reporting' => '2026-08-19',
            'visit_type' => 'new',
        ]);
        $episode = $this->closedEpisode();
        $before = $this->snapshot($episode);

        $result = $this->importChildrenRow($this->childSheetRow('470828468', 110));

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);

        // The upload is one more screening of the same child, and is read
        // as one: a follow-up visit, not a new child.
        $imported = Child::where('child_id', '470828468')->orderByDesc('id')->first();

        $this->assertSame('follow_up', $imported->visit_type);
        $this->assertSame(2, Child::where('child_id', '470828468')->count(), 'One screening per upload, no more.');

        // The closed episode is not the reason for anything, and is untouched.
        $this->assertSame(1, FollowUpChild::where('id_number', '470828468')->count());
        $this->assertSame($before, $this->snapshot($episode));
    }

    public function test_a_child_known_only_from_a_closed_follow_up_is_still_an_existing_child(): void
    {
        // Historical follow-up data, imported before the Children module
        // ever saw the child: no screening row exists.
        $episode = $this->closedEpisode();
        $before = $this->snapshot($episode);

        $this->assertTrue(ChildDuplicateChecker::isKnownChild('470828468'));

        $result = $this->importChildrenRow($this->childSheetRow('470828468', 110));

        $this->assertSame([], $result['errors']);

        $imported = Child::firstWhere('child_id', '470828468');

        $this->assertNotNull($imported);
        $this->assertSame('follow_up', $imported->visit_type, 'A closed follow-up does not make the child new.');
        $this->assertSame(1, FollowUpChild::count(), 'The upload opens no episode of its own.');
        $this->assertSame($before, $this->snapshot($episode));
    }

    // =================================================================
    // TEST 6 - every closed outcome, imported again
    // =================================================================

    public function test_no_closed_outcome_turns_the_child_into_a_new_one_on_import(): void
    {
        foreach (self::CLOSED_OUTCOMES as $index => $outcome) {
            $idNumber = '47082846' . $index;
            $episode = $this->closedEpisode($outcome, $idNumber);
            $before = $this->snapshot($episode);

            $this->assertTrue($episode->isLocked(), "[{$outcome}] closes the episode.");
            $this->assertTrue(ChildDuplicateChecker::isKnownChild($idNumber), "[{$outcome}] still identifies the child.");

            $result = $this->importChildrenRow($this->childSheetRow($idNumber, 110));

            $this->assertSame([], $result['errors'], "[{$outcome}] import failed.");

            $imported = Child::firstWhere('child_id', $idNumber);

            $this->assertNotNull($imported);
            $this->assertSame('follow_up', $imported->visit_type, "[{$outcome}] made the child read as new.");
            $this->assertSame(1, Child::where('child_id', $idNumber)->count());
            $this->assertSame(1, FollowUpChild::where('id_number', $idNumber)->count());
            $this->assertSame($before, $this->snapshot($episode), "[{$outcome}] episode was changed by the import.");
        }
    }

    /**
     * The relapse rule is untouched: measured against the follow-up module's
     * last reading when Children has none, a cured child who comes back
     * worse is a relapse and a new admission, exactly as they would be had
     * the cure been recorded as a Children visit.
     */
    public function test_the_relapse_rule_still_applies_against_the_last_follow_up_reading(): void
    {
        $episode = $this->closedEpisode('cured');
        $episode->visits()->create(['visit_number' => 3, 'visit_date' => '2026-09-02', 'muac' => 130]);

        $this->assertSame('new', ChildDuplicateChecker::resolveVisitType('470828468', 110));
        $this->assertSame('follow_up', ChildDuplicateChecker::resolveVisitType('470828468', 130));
        $this->assertSame('follow_up', ChildDuplicateChecker::resolveVisitType('470828468', null));
    }

    // =================================================================
    // TEST 2 - Referred For Medical Reason (inpt)
    // =================================================================

    public function test_a_referred_for_medical_reason_case_stays_closed_and_offers_readmission(): void
    {
        $episode = $this->closedEpisode('referred_medical_inpt');
        $before = $this->snapshot($episode);

        $this->assertTrue($episode->isLocked());
        $this->assertTrue($episode->canBeReadmitted());
        $this->assertSame('referred_medical_inpt', $episode->discharge_outcome);
        $this->assertCount(2, $episode->visits);

        // The record page says what it is - closed, how, with its visits -
        // and offers the one way forward.
        Livewire::test(ViewFollowUpChild::class, ['record' => $episode->getKey()])
            ->assertSuccessful()
            ->assertSee(__('fields.referred_medical_inpt'))
            ->assertSee(__('fields.record_locked'))
            ->assertActionVisible('readmission');

        // A locked record still refuses a save, readmission or not.
        Livewire::test(EditFollowUpChild::class, ['record' => $episode->getKey()])
            ->assertActionVisible('readmission')
            ->fillForm(['notes' => 'محاولة تعديل'])
            ->call('save');

        $this->assertSame($before, $this->snapshot($episode));
    }

    public function test_readmission_is_not_offered_on_an_open_case_or_when_another_case_is_open(): void
    {
        $open = $this->openEpisode('111111111');

        Livewire::test(ViewFollowUpChild::class, ['record' => $open->getKey()])
            ->assertActionHidden('readmission');

        // Closed, but the child is already being treated in a newer episode.
        $closed = $this->closedEpisode('referred_medical_inpt', '222222222');
        $this->openEpisode('222222222');

        $this->assertFalse($closed->fresh()->canBeReadmitted());

        Livewire::test(ViewFollowUpChild::class, ['record' => $closed->getKey()])
            ->assertActionHidden('readmission');
    }

    // =================================================================
    // TEST 3 - Readmission from the UI
    // =================================================================

    public function test_readmission_from_the_record_page_opens_a_new_case_and_leaves_the_old_one_alone(): void
    {
        $old = $this->closedEpisode();
        $before = $this->snapshot($old);

        Livewire::test(ViewFollowUpChild::class, ['record' => $old->getKey()])
            ->callAction('readmission', data: [
                'admission_date' => '2026-09-09',
                'visit_date' => '2026-09-09',
                'muac' => 111,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified(__('ui.readmission.done_title'));

        $episodes = FollowUpChild::where('id_number', '470828468')->orderBy('id')->get();

        $this->assertCount(2, $episodes, 'One new case, not a duplicate child and not a rewrite.');

        $new = $episodes->last();

        $this->assertNotSame($old->getKey(), $new->getKey());
        $this->assertTrue($new->isReadmission());
        $this->assertSame(FollowUpChild::ADMISSION_READMISSION, $new->admission_type);
        $this->assertSame($old->getKey(), $new->previous_follow_up_child_id);
        $this->assertSame('470828468', $new->id_number);
        $this->assertSame('محمد عبد الكريم خليل عليوة', $new->child_name);
        $this->assertSame('M', $new->sex);
        $this->assertSame('SAM', $new->admitted_with);
        $this->assertSame('2026-09-09', $new->admission_date->format('Y-m-d'));
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $new->discharge_outcome);
        $this->assertNull($new->discharge_date);
        $this->assertFalse($new->isLocked());

        // The new case starts its own sequence at visit 1.
        $this->assertCount(1, $new->visits);
        $this->assertSame(1, $new->visits->first()->visit_number);
        $this->assertSame('2026-09-09', $new->visits->first()->visit_date->format('Y-m-d'));
        $this->assertEqualsWithDelta(111.0, (float) $new->visits->first()->muac, 0.001);

        // The old case: same outcome, same date, same two visits, same
        // everything.
        $this->assertSame($before, $this->snapshot($old));
        $this->assertTrue($old->fresh()->isLocked());

        // And the old case is now the history the new one shows.
        Livewire::test(ViewFollowUpChild::class, ['record' => $new->getKey()])
            ->assertSuccessful()
            ->assertSee(__('fields.readmission'))
            ->assertSee(__('fields.follow_up_history'))
            ->assertSee(__('fields.referred_medical_inpt'));

        // A readmitted child is under follow-up again: no second readmission
        // is offered while this one is open.
        $this->assertFalse($old->fresh()->canBeReadmitted());
        $this->assertTrue(ChildFollowUpTransfer::hasOpenEpisode('470828468'));
    }

    public function test_readmission_is_offered_on_the_listing_row_of_a_closed_case(): void
    {
        $old = $this->closedEpisode();
        $open = $this->openEpisode('333333333');

        Livewire::test(ListFollowUpChildren::class)
            ->assertTableActionVisible('readmission', $old)
            ->assertTableActionHidden('readmission', $open)
            ->callTableAction('readmission', $old, data: [
                'admission_date' => '2026-09-09',
                'visit_date' => '2026-09-09',
                'muac' => 118,
            ])
            ->assertHasNoTableActionErrors();

        $new = FollowUpChild::where('id_number', '470828468')->orderByDesc('id')->first();

        $this->assertTrue($new->isReadmission());
        $this->assertSame('MAM', $new->admitted_with);
        $this->assertSame($old->getKey(), $new->previous_follow_up_child_id);
        $this->assertSame('referred_medical_inpt', $old->fresh()->discharge_outcome);
    }

    public function test_a_normal_reading_cannot_open_a_readmission(): void
    {
        $old = $this->closedEpisode();

        Livewire::test(ViewFollowUpChild::class, ['record' => $old->getKey()])
            ->callAction('readmission', data: [
                'admission_date' => '2026-09-09',
                'visit_date' => '2026-09-09',
                'muac' => 130,
            ])
            ->assertHasActionErrors(['muac']);

        $this->assertSame(1, FollowUpChild::where('id_number', '470828468')->count());
    }

    public function test_the_listing_tells_a_readmission_apart_from_a_first_admission(): void
    {
        $old = $this->closedEpisode();
        $new = ChildFollowUpTransfer::readmitFromEpisode($old, [
            'admission_date' => '2026-09-09',
            'visit_date' => '2026-09-09',
            'muac' => 110,
        ]);

        Livewire::test(ListFollowUpChildren::class)
            ->assertCanSeeTableRecords([$old, $new])
            ->assertTableColumnStateSet('admission_type', __('fields.admission_new'), $old)
            ->assertTableColumnStateSet('admission_type', __('fields.readmission'), $new)
            ->assertTableColumnStateSet('record_state', __('fields.record_locked'), $old)
            ->assertTableColumnStateSet('record_state', __('fields.record_active'), $new);

        // Both tabs still hold what they always did.
        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$new]);

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'active'])
            ->assertCanSeeTableRecords([$new])
            ->assertCanNotSeeTableRecords([$old]);
    }

    // -----------------------------------------------------------------
    // Readmission from the Referral Centre
    // -----------------------------------------------------------------

    public function test_the_referral_centre_recognises_the_child_and_offers_readmission(): void
    {
        $old = $this->closedEpisode();
        $before = $this->snapshot($old);

        $child = Child::factory()->create([
            'child_id' => '470828468',
            'name' => 'محمد عبد الكريم خليل عليوة',
            'muac_mm' => 110,
            'date_of_reporting' => '2026-09-09',
        ]);

        // Same child, not a new one: listed under the closed history.
        $this->assertSame(ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED, ReferralCandidates::statusFor($child));

        $pending = Child::factory()->create(['child_id' => '999999999', 'muac_mm' => 110]);

        Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED)
            ->assertCanSeeTableRecords([$child])
            ->assertTableActionVisible('readmit', $child)
            ->callTableAction('readmit', $child)
            ->assertNotified(__('ui.readmission.done_title'));

        Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', ReferralCandidates::STATUS_PENDING)
            ->assertTableActionHidden('readmit', $pending);

        $new = FollowUpChild::where('id_number', '470828468')->orderByDesc('id')->first();

        $this->assertNotSame($old->getKey(), $new->getKey());
        $this->assertTrue($new->isReadmission());
        $this->assertSame($old->getKey(), $new->previous_follow_up_child_id);
        $this->assertSame($child->getKey(), $new->source_child_visit_id);
        $this->assertSame('SAM', $new->admitted_with);
        $this->assertCount(1, $new->visits);
        $this->assertSame(1, $new->visits->first()->visit_number);

        $this->assertSame($before, $this->snapshot($old));

        // Now in follow-up, so the Centre reads them as such.
        $this->assertSame(ReferralCandidates::STATUS_IN_FOLLOW_UP, ReferralCandidates::statusFor($child->fresh()));
    }

    /**
     * The bulk referral keeps its rule: a closed episode is skipped, never
     * silently readmitted. Readmission is one child, one decision.
     */
    public function test_the_bulk_referral_still_skips_closed_cases(): void
    {
        $this->closedEpisode();
        $child = Child::factory()->create(['child_id' => '470828468', 'muac_mm' => 110]);

        $result = ReferralProcessor::refer([$child->getKey()]);

        $this->assertSame(0, $result['referred']);
        $this->assertSame(1, $result['skipped_closed']);
        $this->assertSame(1, FollowUpChild::count());
    }

    /**
     * The Children form: a child screened SAM again after a closed episode
     * is referred through the same confirmed prompt as ever, and the episode
     * that opens is named a readmission and linked to the closed one.
     */
    public function test_a_screening_after_a_closed_case_opens_a_readmission_from_the_children_form(): void
    {
        $old = $this->closedEpisode();
        $before = $this->snapshot($old);

        Livewire::test(CreateChild::class)
            ->fillForm($this->childFormData(110))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('fields.readmitted_to_follow_up_title'));

        $child = Child::firstWhere('child_id', '470828468');
        $this->assertNotNull($child);
        $this->assertSame('follow_up', $child->visit_type, 'Known from the closed episode: not a new child.');

        $episodes = FollowUpChild::where('id_number', '470828468')->orderBy('id')->get();

        $this->assertCount(2, $episodes);
        $this->assertTrue($episodes->last()->isReadmission());
        $this->assertSame($old->getKey(), $episodes->last()->previous_follow_up_child_id);
        $this->assertSame($before, $this->snapshot($old));
    }

    // =================================================================
    // TEST 4 - Non Responded
    // =================================================================

    public function test_non_responded_closes_the_case_and_keeps_its_history(): void
    {
        $episode = $this->openEpisode();
        $episode->visits()->create(['visit_number' => 2, 'visit_date' => '2026-09-08', 'muac' => 110]);
        $visitsBefore = $episode->visits()->get()->map->getAttributes()->all();

        Livewire::test(EditFollowUpChild::class, ['record' => $episode->getKey()])
            ->fillForm([
                'discharge_outcome' => 'non_responded',
                'discharge_date' => '2026-09-15',
                'visits' => [
                    ['visit_date' => '2026-09-01', 'muac' => 110],
                    ['visit_date' => '2026-09-08', 'muac' => 110],
                    ['visit_date' => '2026-09-15', 'muac' => 111],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $episode->refresh();

        $this->assertSame('non_responded', $episode->discharge_outcome);
        $this->assertTrue($episode->isLocked());
        $this->assertCount(3, $episode->visits);

        // The earlier visits are exactly what they were.
        foreach ($visitsBefore as $index => $attributes) {
            $stored = $episode->visits[$index];
            $this->assertSame($attributes['visit_date'], $stored->getAttributes()['visit_date']);
            $this->assertSame($attributes['muac'], $stored->getAttributes()['muac']);
            $this->assertSame(FollowUpChildVisit::STATUS_ATTENDED, $stored->status);
        }

        // Closed is not gone, not a defaulter, and not a readmission.
        $this->assertSame(1, FollowUpChild::count());
        $this->assertFalse($episode->isReadmission());
        $this->assertNotSame('defaulted', $episode->discharge_outcome);

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertCanSeeTableRecords([$episode]);

        // The last visit is the one at which the outcome was decided.
        $this->assertSame(__('fields.non_responded'), FollowUpChildResource::visitOutcome($episode->visits->last()));
        $this->assertSame(__('fields.under_follow_up'), FollowUpChildResource::visitOutcome($episode->visits->first()));

        Livewire::test(ViewFollowUpChild::class, ['record' => $episode->getKey()])
            ->assertSuccessful()
            ->assertSee(__('fields.non_responded'));
    }

    public function test_every_closed_outcome_locks_the_record_and_is_offered_in_the_form(): void
    {
        $options = FollowUpChildResource::dischargeOutcomeOptions();

        foreach (self::CLOSED_OUTCOMES as $outcome) {
            $this->assertArrayHasKey($outcome, $options);
            $this->assertTrue(FollowUpChild::factory()->create(['discharge_outcome' => $outcome])->isLocked());
        }

        $this->assertArrayHasKey('under_follow_up', $options);
        $this->assertFalse(FollowUpChild::factory()->create(['discharge_outcome' => 'under_follow_up'])->isLocked());
    }

    // =================================================================
    // TEST 5 - Defaulter: visit-by-visit history
    // =================================================================

    public function test_missed_visits_are_kept_in_sequence_with_the_attended_ones(): void
    {
        $episode = $this->openEpisode();

        Livewire::test(EditFollowUpChild::class, ['record' => $episode->getKey()])
            ->fillForm([
                'visits' => [
                    ['visit_date' => '2026-09-01', 'status' => 'attended', 'muac' => 110],
                    ['visit_date' => '2026-09-08', 'status' => 'missed'],
                    ['visit_date' => '2026-09-15', 'status' => 'attended', 'muac' => 112],
                    ['visit_date' => '2026-09-22', 'status' => 'missed'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $episode->refresh();

        // Four rows, each the visit it was recorded as - and not one more.
        $this->assertCount(4, $episode->visits);
        $this->assertSame([1, 2, 3, 4], $episode->visits->pluck('visit_number')->all());
        $this->assertSame(
            ['attended', 'missed', 'attended', 'missed'],
            $episode->visits->pluck('status')->all(),
        );

        // A missed visit carries no reading, ever.
        $this->assertNull($episode->visits[1]->muac);
        $this->assertNull($episode->visits[1]->fi);
        $this->assertNull($episode->visits[3]->muac);
        $this->assertEqualsWithDelta(112.0, (float) $episode->visits[2]->muac, 0.001);

        // Read back as a history: under follow-up, missed, returned, missed.
        $this->assertSame([
            __('fields.under_follow_up'),
            __('fields.visit_outcome_missed'),
            __('fields.visit_outcome_returned'),
            __('fields.visit_outcome_missed'),
        ], $episode->visits->map(fn (FollowUpChildVisit $visit): string => FollowUpChildResource::visitOutcome($visit))->all());

        // The episode is still open and nobody has been called a defaulter
        // on the record's behalf; the latest measurement is the last one
        // actually taken.
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $episode->discharge_outcome);
        $this->assertFalse($episode->meetsDefaulterRule());
        $this->assertEqualsWithDelta(112.0, (float) $episode->latest_muac, 0.001);

        // A missed visit is not a measurement waiting to be found.
        $this->assertSame(0, ReferralCandidates::visitsMissingMuac()->count());

        Livewire::test(ViewFollowUpChild::class, ['record' => $episode->getKey()])
            ->assertSuccessful()
            ->assertSee(__('fields.visit_outcome_missed'))
            ->assertSee(__('fields.visit_outcome_returned'));

        Livewire::test(ListFollowUpChildren::class)
            ->assertTableColumnStateSet('missed_visits', 2, $episode);
    }

    public function test_two_consecutive_missed_visits_are_reported_and_change_nothing(): void
    {
        $episode = $this->openEpisode();

        Livewire::test(EditFollowUpChild::class, ['record' => $episode->getKey()])
            ->fillForm([
                'visits' => [
                    ['visit_date' => '2026-09-01', 'status' => 'attended', 'muac' => 110],
                    ['visit_date' => '2026-09-08', 'status' => 'missed'],
                    ['visit_date' => '2026-09-15', 'status' => 'missed'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('fields.defaulter_rule_title'));

        $episode->refresh();

        $this->assertTrue($episode->meetsDefaulterRule());

        // Reported, not applied: the outcome is still the person's to set,
        // the visits are exactly as recorded, and no episode was opened.
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $episode->discharge_outcome);
        $this->assertFalse($episode->isLocked());
        $this->assertCount(3, $episode->visits);
        $this->assertSame(1, FollowUpChild::count());
    }

    public function test_a_visit_with_no_status_is_an_attended_visit(): void
    {
        $episode = $this->openEpisode();

        $visit = $episode->visits()->create(['visit_number' => 2, 'visit_date' => '2026-09-08', 'muac' => 115]);

        $this->assertSame(FollowUpChildVisit::STATUS_ATTENDED, $visit->fresh()->status);
        $this->assertFalse($visit->fresh()->isMissed());
    }

    // -----------------------------------------------------------------
    // Export and import carry both additions
    // -----------------------------------------------------------------

    public function test_the_export_writes_the_admission_type_and_each_visit_status(): void
    {
        $old = $this->closedEpisode();
        $new = ChildFollowUpTransfer::readmitFromEpisode($old, [
            'admission_date' => '2026-09-09',
            'visit_date' => '2026-09-09',
            'muac' => 110,
        ]);
        $new->visits()->create(['visit_number' => 2, 'visit_date' => '2026-09-16', 'status' => 'missed']);

        $export = new FollowUpChildrenExport(FollowUpChild::query());
        $headings = $export->headings();
        $fields = $export->fields();

        $typeIndex = array_search('admission_type', $fields, true);
        $this->assertNotFalse($typeIndex);
        $this->assertSame(__('fields.admission_type'), $headings[$typeIndex]);

        $oldRow = $export->map($old->fresh()->load('visits'));
        $newRow = $export->map($new->fresh()->load('visits'));

        // A row written before the column existed exports blank, a
        // readmission exports as one.
        $this->assertNull($oldRow[$typeIndex]);
        $this->assertSame(__('fields.readmission'), $newRow[$typeIndex]);

        $base = count($fields);
        $this->assertSame(__('fields.visit_status_n', ['n' => 1]), $headings[$base + 3]);
        $this->assertSame(__('fields.visit_attended'), $newRow[$base + 3]);
        $this->assertSame(__('fields.visit_missed'), $newRow[$base + 7]);
    }

    public function test_an_exported_file_re_imports_its_readmissions_and_missed_visits(): void
    {
        $schema = new ImportSchema(ImportDefinition::get('follow_up_children'));

        // The template still carries only date and MUAC per visit; the status
        // column is read when an export supplies it.
        $this->assertNotContains(__('fields.visit_status_n', ['n' => 1]), $schema->headings());
        $this->assertSame(['type' => 'visit_status', 'number' => 1], $schema->resolveHeading(__('fields.visit_status_n', ['n' => 1])));

        $headings = $schema->headings();
        $headings[] = __('fields.visit_status_n', ['n' => 1]);
        $headings[] = __('fields.visit_status_n', ['n' => 2]);

        $row = array_fill(0, count($headings), null);
        $row[array_search(__('fields.id_number'), $headings, true)] = '470828468';
        $row[array_search(__('fields.child_name'), $headings, true)] = 'طفل مستورد';
        $row[array_search(__('fields.governorate'), $headings, true)] = 'Gaza';
        $row[array_search(__('fields.admission_type'), $headings, true)] = __('fields.readmission');
        $row[array_search(__('fields.discharge_outcome'), $headings, true)] = 'Referred For Medical Reason(inpt)';
        $row[array_search(__('fields.visit_date_n', ['n' => 1]), $headings, true)] = '2026-08-19';
        $row[array_search(__('fields.visit_muac_n', ['n' => 1]), $headings, true)] = 110;
        $row[array_search(__('fields.visit_date_n', ['n' => 2]), $headings, true)] = '2026-08-26';
        $row[array_search(__('fields.visit_status_n', ['n' => 2]), $headings, true)] = __('fields.visit_missed');

        $result = $this->importSheet('follow_up_children', [$headings, $row]);

        $this->assertSame([], $result['errors']);

        $episode = FollowUpChild::with('visits')->firstWhere('id_number', '470828468');

        $this->assertTrue($episode->isReadmission());
        $this->assertSame('referred_medical_inpt', $episode->discharge_outcome);
        $this->assertCount(2, $episode->visits);
        $this->assertSame('attended', $episode->visits[0]->status);
        $this->assertSame('missed', $episode->visits[1]->status);
        $this->assertNull($episode->visits[1]->muac);
    }
}
