<?php

namespace Tests\Feature;

use App\Events\ExcelActionOccurred;
use App\Filament\Pages\ReferralCenter;
use App\Imports\ImportDefinition;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\ReferralBatch;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\ImportSchema;
use App\Support\Notifications\ActionType;
use App\Support\Referral\ReferralCandidates;
use App\Support\Referral\ReferralProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The referral layer that sits on top of the Children module.
 *
 * The upload writes children and stops. Deciding which of them are admitted to
 * the follow-up programme happens afterwards, on the Referral Centre, and is
 * always a person's decision. Everything here tests that separation as much as
 * it tests the referral itself: the import must behave exactly as it always
 * has, and a referral going wrong must not reach back into it.
 */
class ReferralWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        \Storage::fake('local');
    }

    private function actingAsRole(string $role = 'Super Admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function child(?int $muac, array $attributes = []): Child
    {
        return Child::factory()->create(array_merge([
            'muac_mm' => $muac,
            'date_of_reporting' => '2026-05-01',
        ], $attributes));
    }

    // =================================================================
    // Detection: who is a candidate
    // =================================================================

    public function test_sam_and_mam_children_are_eligible_and_normal_ones_are_not(): void
    {
        $sam = $this->child(110);
        $mam = $this->child(120);
        $normal = $this->child(130);
        $unmeasured = $this->child(null);

        $eligible = ReferralCandidates::query()->pluck('id');

        $this->assertTrue($eligible->contains($sam->id));
        $this->assertTrue($eligible->contains($mam->id));
        $this->assertFalse($eligible->contains($normal->id));
        $this->assertFalse($eligible->contains($unmeasured->id));
    }

    /**
     * The boundaries are the shared classifier's, not a second copy of them.
     */
    public function test_the_classification_boundaries_match_the_shared_classifier(): void
    {
        $samEdge = $this->child(115);
        $mamEdge = $this->child(124);
        $normalEdge = $this->child(125);

        $eligible = ReferralCandidates::query()->pluck('id');

        $this->assertTrue($eligible->contains($samEdge->id));
        $this->assertTrue($eligible->contains($mamEdge->id));
        $this->assertFalse($eligible->contains($normalEdge->id));
    }

    public function test_a_child_already_under_follow_up_is_not_a_candidate(): void
    {
        $child = $this->child(110, ['child_id' => 'CH-OPEN']);

        FollowUpChild::factory()->create([
            'id_number' => 'CH-OPEN',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        $this->assertFalse(ReferralCandidates::query()->pluck('id')->contains($child->id));
    }

    public function test_a_child_whose_previous_episode_is_closed_is_a_candidate_again(): void
    {
        $child = $this->child(110, ['child_id' => 'CH-CLOSED']);

        FollowUpChild::factory()->create([
            'id_number' => 'CH-CLOSED',
            'discharge_outcome' => FollowUpChild::CURED_OUTCOME,
        ]);

        // The existing rule: only an open episode blocks a new one. A closed
        // one is a finished episode, and a relapse is a new admission.
        $this->assertTrue(ReferralCandidates::query()->pluck('id')->contains($child->id));
    }

    public function test_only_the_children_of_the_selected_batch_are_listed(): void
    {
        $before = $this->child(110);
        $inBatch = $this->child(112);
        $after = $this->child(114);

        $batch = ReferralBatch::create([
            'module' => ReferralBatch::CHILDREN_MODULE,
            'imported_count' => 1,
            'first_record_id' => $inBatch->id,
            'last_record_id' => $inBatch->id,
        ]);

        $eligible = ReferralCandidates::query($batch)->pluck('id');

        $this->assertTrue($eligible->contains($inBatch->id));
        $this->assertFalse($eligible->contains($before->id));
        $this->assertFalse($eligible->contains($after->id));
    }

    public function test_the_summary_counts_the_batch_by_classification(): void
    {
        $first = $this->child(110);
        $this->child(120);
        $this->child(130);
        $last = $this->child(null);

        $batch = ReferralBatch::create([
            'module' => ReferralBatch::CHILDREN_MODULE,
            'imported_count' => 4,
            'first_record_id' => $first->id,
            'last_record_id' => $last->id,
        ]);

        $this->assertSame([
            'total' => 4,
            'normal' => 1,
            'mam' => 1,
            'sam' => 1,
            'unmeasured' => 1,
            'eligible' => 2,
        ], ReferralCandidates::summary($batch));
    }

    // =================================================================
    // Referral: what confirming actually writes
    // =================================================================

    public function test_referral_opens_a_follow_up_record_and_an_initial_visit(): void
    {
        $this->actingAsRole();

        $child = $this->child(110, ['child_id' => 'CH-REF', 'name' => 'طفل محال']);

        $result = ReferralProcessor::refer([$child->id]);

        $this->assertSame(['referred' => 1, 'skipped' => 0, 'failed' => 0], $result);

        $followUp = FollowUpChild::with('visits')->firstWhere('id_number', 'CH-REF');

        $this->assertNotNull($followUp);
        $this->assertSame('SAM', $followUp->admitted_with);
        $this->assertSame('malnutrition', $followUp->causes_of_admission);
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $followUp->discharge_outcome);
        // The screening this admission was raised from.
        $this->assertSame($child->id, $followUp->source_child_visit_id);

        $this->assertCount(1, $followUp->visits);
        $visit = $followUp->visits->first();
        $this->assertSame(1, $visit->visit_number);
        $this->assertEqualsWithDelta(110.0, (float) $visit->muac, 0.001);
        $this->assertSame('SAM', $visit->fi);
    }

    public function test_a_mam_child_is_admitted_as_mam(): void
    {
        $this->actingAsRole();

        $child = $this->child(120);

        ReferralProcessor::refer([$child->id]);

        $this->assertSame('MAM', FollowUpChild::first()->admitted_with);
    }

    public function test_the_original_child_record_is_left_exactly_as_it_was(): void
    {
        $this->actingAsRole();

        $child = $this->child(110);
        $before = $child->fresh()->getAttributes();

        ReferralProcessor::refer([$child->id]);

        // Not moved, not deleted, not edited: the screening record stands.
        $this->assertSame(1, Child::count());
        $this->assertNotNull(Child::find($child->id));
        $this->assertSame($before, $child->fresh()->getAttributes());
    }

    public function test_a_normal_child_is_never_referred_even_if_selected(): void
    {
        $this->actingAsRole();

        $child = $this->child(130);

        $result = ReferralProcessor::refer([$child->id]);

        $this->assertSame(['referred' => 0, 'skipped' => 1, 'failed' => 0], $result);
        $this->assertSame(0, FollowUpChild::count());
    }

    // =================================================================
    // Idempotency
    // =================================================================

    public function test_referring_the_same_selection_twice_opens_only_one_episode(): void
    {
        $this->actingAsRole();

        $child = $this->child(110, ['child_id' => 'CH-TWICE']);

        $first = ReferralProcessor::refer([$child->id]);
        $second = ReferralProcessor::refer([$child->id]);

        $this->assertSame(1, $first['referred']);
        $this->assertSame(0, $second['referred']);
        $this->assertSame(1, $second['skipped']);

        $this->assertSame(1, FollowUpChild::where('id_number', 'CH-TWICE')->count());
        $this->assertSame(1, FollowUpChild::first()->visits()->count());
    }

    public function test_two_rows_for_the_same_child_in_one_run_open_only_one_episode(): void
    {
        $this->actingAsRole();

        // The same child screened twice in one upload: two Children rows,
        // one child ID, and therefore one episode.
        $first = $this->child(110, ['child_id' => 'CH-DUP']);
        $second = $this->child(112, ['child_id' => 'CH-DUP']);

        $result = ReferralProcessor::refer([$first->id, $second->id]);

        $this->assertSame(1, $result['referred']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, FollowUpChild::where('id_number', 'CH-DUP')->count());
    }

    public function test_a_child_with_an_open_episode_is_skipped_rather_than_duplicated(): void
    {
        $this->actingAsRole();

        $child = $this->child(110, ['child_id' => 'CH-OPEN2']);

        FollowUpChild::factory()->create([
            'id_number' => 'CH-OPEN2',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        $result = ReferralProcessor::refer([$child->id]);

        $this->assertSame(0, $result['referred']);
        $this->assertSame(1, FollowUpChild::where('id_number', 'CH-OPEN2')->count());
    }

    // =================================================================
    // Performance: no query per row
    // =================================================================

    public function test_the_candidate_listing_does_not_query_once_per_child(): void
    {
        Child::factory()->count(40)->create(['muac_mm' => 110]);

        \DB::enableQueryLog();
        \DB::flushQueryLog();

        $count = ReferralCandidates::query()->get()->count();

        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame(40, $count);
        // One statement for the whole listing: the "already under follow-up"
        // rule is a correlated subquery, not forty lookups.
        $this->assertSame(1, $queries);
    }

    public function test_referring_a_selection_does_not_grow_a_lookup_per_child(): void
    {
        $this->actingAsRole();

        // All Normal, so nothing is written and what is left to count is the
        // fixed cost of reading the selection.
        $children = Child::factory()->count(30)->create(['muac_mm' => 130]);

        \DB::enableQueryLog();
        \DB::flushQueryLog();

        ReferralProcessor::refer($children->pluck('id')->all());

        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        // One to load the chunk, one to look up open episodes for all of it.
        $this->assertLessThanOrEqual(3, $queries);
    }

    // =================================================================
    // Audit
    // =================================================================

    public function test_a_referral_is_recorded_in_the_existing_activity_log(): void
    {
        $user = $this->actingAsRole();

        $child = $this->child(110, ['child_id' => 'CH-LOG']);

        $batch = ReferralBatch::create([
            'module' => ReferralBatch::CHILDREN_MODULE,
            'imported_count' => 1,
            'first_record_id' => $child->id,
            'last_record_id' => $child->id,
        ]);

        ReferralProcessor::refer([$child->id], $batch, $user);

        $activity = Activity::where('log_name', 'referral')->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame(FollowUpChild::class, $activity->subject_type);
        $this->assertSame('CH-LOG', $activity->properties['child_id']);
        $this->assertSame('SAM', $activity->properties['classification']);
        $this->assertSame($batch->id, $activity->properties['referral_batch_id']);
    }

    // =================================================================
    // The batch the upload leaves behind
    // =================================================================

    public function test_a_completed_children_import_records_a_batch(): void
    {
        $user = $this->actingAsRole();

        $first = $this->child(110);
        $last = $this->child(120);

        ExcelActionOccurred::dispatch('Child', ActionType::IMPORT, $user, 2);

        $batch = ReferralBatch::latestChildrenBatch();

        $this->assertNotNull($batch);
        $this->assertSame(2, $batch->imported_count);
        $this->assertSame($first->id, $batch->first_record_id);
        $this->assertSame($last->id, $batch->last_record_id);
        $this->assertSame($user->id, $batch->user_id);
    }

    public function test_exports_and_other_modules_record_no_batch(): void
    {
        $user = $this->actingAsRole();

        ExcelActionOccurred::dispatch('Child', ActionType::EXPORT, $user, 5);
        ExcelActionOccurred::dispatch('PregnantLactatingWoman', ActionType::IMPORT, $user, 5);
        ExcelActionOccurred::dispatch('Child', ActionType::IMPORT, $user, 0);

        $this->assertSame(0, ReferralBatch::count());
    }

    // =================================================================
    // Failure isolation: the import must never pay for the referral layer
    // =================================================================

    /**
     * The point of the whole arrangement. The batch listener runs after the
     * import has committed; if it throws, the rows it was going to describe
     * are still there and the import is still a success.
     */
    public function test_a_failing_batch_recorder_does_not_roll_back_a_successful_import(): void
    {
        $user = $this->actingAsRole();

        $rows = $this->childrenSheetRows(['CH-ISO-1' => 110, 'CH-ISO-2' => 130]);

        $result = app(ExcelImportService::class)->import(
            ImportDefinition::get('children'),
            $this->makeSheet($rows),
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['imported']);

        // The referral layer falls over the moment it is asked to record.
        \Schema::drop('referral_batches');

        ExcelActionOccurred::dispatch('Child', ActionType::IMPORT, $user, 2);

        // Both children are still on file, still exactly as imported.
        $this->assertSame(2, Child::count());
        $this->assertSame('SAM', Child::firstWhere('child_id', 'CH-ISO-1')->fi);
        $this->assertSame('Normal', Child::firstWhere('child_id', 'CH-ISO-2')->fi);
    }

    /**
     * One child that cannot be referred is one child, not the run.
     */
    public function test_a_child_that_cannot_be_referred_does_not_stop_the_rest(): void
    {
        $this->actingAsRole();

        $broken = $this->child(110, ['child_id' => 'CH-BAD']);
        $good = $this->child(110, ['child_id' => 'CH-GOOD-1']);
        $alsoGood = $this->child(112, ['child_id' => 'CH-GOOD-2']);

        // Stand in for whatever makes one write fail in production - a
        // constraint, a deadlock, a column that will not take the value.
        // The listener is registered on this test's own dispatcher and goes
        // when the application is rebuilt for the next test.
        FollowUpChild::creating(function (FollowUpChild $record): void {
            if ($record->id_number === 'CH-BAD') {
                throw new \RuntimeException('simulated write failure');
            }
        });

        $result = ReferralProcessor::refer([$broken->id, $good->id, $alsoGood->id]);

        $this->assertSame(2, $result['referred']);
        $this->assertSame(1, $result['failed']);

        // The failed one keeps its place in the queue for another attempt.
        $this->assertTrue(ReferralCandidates::query()->pluck('id')->contains($broken->id));
        $this->assertSame(3, Child::count());
    }

    // =================================================================
    // Import regression: the upload still refers nothing by itself
    // =================================================================

    public function test_a_children_import_still_refers_nobody_on_its_own(): void
    {
        $this->actingAsRole();

        $rows = $this->childrenSheetRows(['CH-IMP-1' => 110, 'CH-IMP-2' => 120]);

        $result = app(ExcelImportService::class)->import(
            ImportDefinition::get('children'),
            $this->makeSheet($rows),
        );

        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $result['imported']);

        // Two SAM/MAM children imported, and not one follow-up record: the
        // upload writes children and nothing else, exactly as before.
        $this->assertSame(0, FollowUpChild::count());

        // They are waiting on the Referral Centre instead.
        $this->assertSame(2, ReferralCandidates::query()->count());
    }

    // =================================================================
    // The page
    // =================================================================

    public function test_the_referral_centre_lists_only_eligible_children(): void
    {
        $this->actingAsRole();

        $sam = $this->child(110);
        $normal = $this->child(130);

        Livewire::test(ReferralCenter::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$sam])
            ->assertCanNotSeeTableRecords([$normal]);
    }

    public function test_confirming_the_bulk_action_refers_the_selected_children(): void
    {
        $this->actingAsRole();

        $sam = $this->child(110, ['child_id' => 'CH-PAGE-1']);
        $mam = $this->child(120, ['child_id' => 'CH-PAGE-2']);
        $normal = $this->child(130, ['child_id' => 'CH-PAGE-3']);

        Livewire::test(ReferralCenter::class)
            ->callTableBulkAction('refer', [$sam, $mam])
            ->assertHasNoTableActionErrors();

        $this->assertSame('SAM', FollowUpChild::firstWhere('id_number', 'CH-PAGE-1')?->admitted_with);
        $this->assertSame('MAM', FollowUpChild::firstWhere('id_number', 'CH-PAGE-2')?->admitted_with);
        // Never selected, never listed, never referred.
        $this->assertNull(FollowUpChild::firstWhere('id_number', 'CH-PAGE-3'));

        // Both Children rows stay exactly where they were.
        $this->assertSame(3, Child::count());

        // And they leave the candidate list, so a second confirmation has
        // nothing left to duplicate.
        $this->assertFalse(ReferralCandidates::query()->pluck('id')->contains($sam->id));
        $this->assertFalse(ReferralCandidates::query()->pluck('id')->contains($mam->id));
    }

    public function test_the_bulk_action_is_hidden_from_a_user_who_may_not_open_an_episode(): void
    {
        $this->actingAsRole('Viewer');

        // Viewer cannot even reach the page, which is the first gate.
        $this->assertFalse(ReferralCenter::canAccess());
    }

    public function test_the_page_is_closed_to_users_without_the_referral_permission(): void
    {
        $this->actingAsRole('Viewer');

        $this->assertFalse(ReferralCenter::canAccess());
        $this->assertFalse(ReferralCenter::canRefer());
    }

    public function test_the_page_is_open_to_the_roles_that_may_open_an_episode(): void
    {
        foreach (['Super Admin', 'Admin', 'Data Entry'] as $role) {
            $this->actingAsRole($role);

            $this->assertTrue(ReferralCenter::canAccess(), "[{$role}] cannot reach the Referral Centre.");
            $this->assertTrue(ReferralCenter::canRefer(), "[{$role}] may not refer.");
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * A children sheet with the required columns filled, keyed child ID => MUAC.
     */
    private function childrenSheetRows(array $children): array
    {
        $headings = (new ImportSchema(ImportDefinition::get('children')))->headings();

        $rows = [$headings];

        foreach ($children as $childId => $muac) {
            $row = array_fill(0, count($headings), null);

            foreach ([
                'child_id' => $childId,
                'name' => 'Child ' . $childId,
                'organization' => 'AEI',
                'implementing_partner' => 'SCI',
                'date_of_reporting' => '2026-05-01',
                'sex' => __('fields.male'),
                'governorate' => 'gaza',
                'muac_mm' => $muac,
            ] as $field => $value) {
                $index = array_search(__('fields.' . $field), $headings, true);
                $this->assertNotFalse($index, "No [{$field}] column in the children template.");
                $row[$index] = $value;
            }

            $rows[] = $row;
        }

        return $rows;
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

        $name = 'referral-test-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return \Storage::disk('local')->path($name);
    }
}
