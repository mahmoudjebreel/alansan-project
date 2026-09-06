<?php

namespace Tests\Feature;

use App\Filament\Pages\ReferralCenter;
use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Support\ChildDuplicateChecker;
use App\Support\Referral\ReferralCandidates;
use App\Support\Referral\ReferralProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Where a screened child currently stands, and where the two follow-up
 * listings put it.
 *
 * The separation this file defends: a Children row is a screening and a
 * Follow Up Child row is a treatment episode. The Children visit type belongs
 * entirely to the first and the CMAM visit number entirely to the second, and
 * neither is ever derived from the other. The existing rules are read here,
 * never restated - the visit type comes from ChildDuplicateChecker and the
 * closing outcomes from the FollowUpChild model.
 */
class ReferralStatusAndFollowUpViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
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

    /**
     * The status as the table computes it in SQL, for one child.
     */
    private function selectedStatus(Child $child): ?string
    {
        return ReferralCandidates::overview()
            ->whereKey($child->getKey())
            ->select('children.*')
            ->selectRaw(ReferralCandidates::statusCase() . ' as referral_status')
            ->first()?->referral_status;
    }

    // =================================================================
    // Children visit type: unchanged, and never a CMAM visit number
    // =================================================================

    public function test_a_first_screening_is_new(): void
    {
        $this->assertSame('new', ChildDuplicateChecker::resolveVisitType('900000001', 130));
    }

    public function test_a_later_screening_of_the_same_normal_child_is_a_follow_up(): void
    {
        $this->child(130, ['child_id' => '900000002']);

        $this->assertSame('follow_up', ChildDuplicateChecker::resolveVisitType('900000002', 130));
    }

    public function test_a_first_sam_screening_is_still_new(): void
    {
        $this->assertSame('new', ChildDuplicateChecker::resolveVisitType('900000003', 110));
        $this->assertSame('new', ChildDuplicateChecker::resolveVisitType('900000004', 120));
    }

    /**
     * The rule the whole feature is built around: referring a child, opening
     * an episode and recording visits changes nothing about the screening.
     */
    public function test_referral_does_not_touch_the_children_visit_type(): void
    {
        $this->actingAsRole();

        $child = $this->child(110, ['child_id' => '900000005', 'visit_type' => 'new']);

        ReferralProcessor::refer([$child->id]);

        $this->assertSame('new', $child->fresh()->visit_type);
    }

    public function test_a_cmam_visit_number_never_becomes_a_children_visit_type(): void
    {
        $this->actingAsRole();

        $child = $this->child(110, ['child_id' => '900000006', 'visit_type' => 'new']);

        ReferralProcessor::refer([$child->id]);

        $episode = FollowUpChild::firstWhere('id_number', '900000006');

        // Visit 1 of the episode exists, and the screening still says "new".
        $this->assertSame(1, $episode->visits()->first()->visit_number);
        $this->assertSame('new', $child->fresh()->visit_type);

        // The two vocabularies never mix.
        $this->assertNotContains($child->fresh()->visit_type, ['1', 1, 'visit_1']);
    }

    /**
     * A Normal child screened again is a Children follow-up and nothing more.
     * It does not enter the CMAM programme by repeating.
     */
    public function test_a_repeated_normal_screening_opens_no_episode(): void
    {
        $this->actingAsRole();

        $first = $this->child(130, ['child_id' => '900000007']);
        $second = $this->child(130, ['child_id' => '900000007']);

        ReferralProcessor::refer([$first->id, $second->id]);

        $this->assertSame(0, FollowUpChild::count());
        $this->assertSame(0, ReferralCandidates::overview()->count());
    }

    // =================================================================
    // The four statuses
    // =================================================================

    public function test_a_malnourished_child_with_no_episode_is_pending(): void
    {
        $child = $this->child(110, ['child_id' => '900000010']);

        $this->assertSame(ReferralCandidates::STATUS_PENDING, $this->selectedStatus($child));
        $this->assertSame(ReferralCandidates::STATUS_PENDING, ReferralCandidates::statusFor($child));
    }

    public function test_a_child_with_an_open_episode_reads_as_already_in_follow_up(): void
    {
        $child = $this->child(110, ['child_id' => '900000011']);

        FollowUpChild::factory()->create([
            'id_number' => '900000011',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        $this->assertSame(ReferralCandidates::STATUS_IN_FOLLOW_UP, $this->selectedStatus($child));
        $this->assertSame(ReferralCandidates::STATUS_IN_FOLLOW_UP, ReferralCandidates::statusFor($child));
    }

    public function test_a_child_whose_episode_closed_reads_as_previously_followed(): void
    {
        $child = $this->child(110, ['child_id' => '900000012']);

        FollowUpChild::factory()->create([
            'id_number' => '900000012',
            'discharge_outcome' => FollowUpChild::CURED_OUTCOME,
        ]);

        $this->assertSame(ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED, $this->selectedStatus($child));
        $this->assertSame(ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED, ReferralCandidates::statusFor($child));
    }

    /**
     * Every recorded closing outcome closes an episode, and none of them is
     * invented here.
     */
    public function test_every_closing_outcome_reads_as_previously_followed(): void
    {
        foreach (FollowUpChild::CLOSING_OUTCOMES as $index => $outcome) {
            $childId = '9000001' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);

            $child = $this->child(110, ['child_id' => $childId]);

            FollowUpChild::factory()->create([
                'id_number' => $childId,
                'discharge_outcome' => $outcome,
            ]);

            $this->assertSame(
                ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED,
                $this->selectedStatus($child),
                "[{$outcome}] should close the episode.",
            );
        }
    }

    // =================================================================
    // Missing MUAC
    // =================================================================

    public function test_a_child_without_a_muac_needs_review_and_is_not_classified(): void
    {
        $child = $this->child(null, ['child_id' => '900000020']);

        $this->assertSame(ReferralCandidates::STATUS_NEEDS_REVIEW, $this->selectedStatus($child));

        // Not Normal, not MAM, not SAM: no classification at all.
        $this->assertNull($child->fresh()->fi);
    }

    public function test_a_child_without_a_muac_is_never_eligible_for_referral(): void
    {
        $this->actingAsRole();

        $child = $this->child(null, ['child_id' => '900000021']);

        $this->assertFalse(ReferralCandidates::query()->pluck('id')->contains($child->id));

        $result = ReferralProcessor::refer([$child->id]);

        $this->assertSame([
            'referred' => 0,
            'skipped' => 1,
            'skipped_active' => 0,
            'skipped_closed' => 0,
            'skipped_ineligible' => 1,
            'failed' => 0,
        ], $result);
        $this->assertSame(0, FollowUpChild::count());
    }

    public function test_the_missing_muac_filter_lists_only_unmeasured_children(): void
    {
        $missing = $this->child(null, ['child_id' => '900000022']);
        $sam = $this->child(110, ['child_id' => '900000023']);

        $listed = ReferralCandidates::scopeToStatus(
            ReferralCandidates::overview(),
            ReferralCandidates::STATUS_NEEDS_REVIEW,
        )->pluck('id');

        $this->assertTrue($listed->contains($missing->id));
        $this->assertFalse($listed->contains($sam->id));
    }

    /**
     * A Normal reading is a finished screening; it is not something the
     * Referral Centre has an opinion about.
     */
    public function test_a_normal_child_appears_nowhere_in_the_overview(): void
    {
        $normal = $this->child(130, ['child_id' => '900000024']);

        $this->assertFalse(ReferralCandidates::overview()->pluck('id')->contains($normal->id));
    }

    // =================================================================
    // Counters
    // =================================================================

    public function test_the_counters_are_computed_in_the_database(): void
    {
        ReferralCandidates::forgetSummaries();

        $this->child(110, ['child_id' => '900000030']);                    // pending
        $this->child(120, ['child_id' => '900000031']);                    // pending
        $this->child(null, ['child_id' => '900000032']);                   // needs review
        $this->child(130, ['child_id' => '900000033']);                    // normal, not counted

        $this->child(110, ['child_id' => '900000034']);
        FollowUpChild::factory()->create([
            'id_number' => '900000034',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        $this->child(110, ['child_id' => '900000035']);
        FollowUpChild::factory()->create([
            'id_number' => '900000035',
            'discharge_outcome' => FollowUpChild::CURED_OUTCOME,
        ]);

        $summary = ReferralCandidates::statusSummary();

        $this->assertSame(2, $summary[ReferralCandidates::STATUS_PENDING]);
        $this->assertSame(1, $summary[ReferralCandidates::STATUS_NEEDS_REVIEW]);
        $this->assertSame(1, $summary[ReferralCandidates::STATUS_IN_FOLLOW_UP]);
        $this->assertSame(1, $summary[ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED]);
        $this->assertSame(1, $summary['active_follow_ups']);
        $this->assertSame(1, $summary['closed_cases']);
    }

    public function test_referring_a_child_refreshes_the_cached_counters(): void
    {
        $this->actingAsRole();

        $child = $this->child(110, ['child_id' => '900000040']);

        $this->assertSame(1, ReferralCandidates::statusSummary()[ReferralCandidates::STATUS_PENDING]);

        ReferralProcessor::refer([$child->id]);

        $after = ReferralCandidates::statusSummary();

        $this->assertSame(0, $after[ReferralCandidates::STATUS_PENDING]);
        $this->assertSame(1, $after[ReferralCandidates::STATUS_IN_FOLLOW_UP]);
        $this->assertSame(1, $after['active_follow_ups']);
    }

    // =================================================================
    // Performance
    // =================================================================

    public function test_the_status_column_does_not_query_once_per_row(): void
    {
        Child::factory()->count(40)->create(['muac_mm' => 110]);

        \DB::enableQueryLog();
        \DB::flushQueryLog();

        $rows = ReferralCandidates::overview()
            ->select('children.*')
            ->selectRaw(ReferralCandidates::statusCase() . ' as referral_status')
            ->get();

        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertCount(40, $rows);
        $this->assertSame(1, $queries, 'The status must be selected, not looked up per row.');
    }

    // =================================================================
    // The Referral Centre page
    // =================================================================

    public function test_the_page_still_defaults_to_the_children_waiting_for_a_decision(): void
    {
        $this->actingAsRole();

        $pending = $this->child(110, ['child_id' => '900000050']);
        $normal = $this->child(130, ['child_id' => '900000051']);

        $inFollowUp = $this->child(110, ['child_id' => '900000052']);
        FollowUpChild::factory()->create([
            'id_number' => '900000052',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        Livewire::test(ReferralCenter::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$normal, $inFollowUp]);
    }

    public function test_the_status_filter_can_show_the_children_already_in_follow_up(): void
    {
        $this->actingAsRole();

        $pending = $this->child(110, ['child_id' => '900000060']);

        $inFollowUp = $this->child(110, ['child_id' => '900000061']);
        FollowUpChild::factory()->create([
            'id_number' => '900000061',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', ReferralCandidates::STATUS_IN_FOLLOW_UP)
            ->assertCanSeeTableRecords([$inFollowUp])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_the_status_filter_can_show_the_children_without_a_muac(): void
    {
        $this->actingAsRole();

        $missing = $this->child(null, ['child_id' => '900000070']);
        $sam = $this->child(110, ['child_id' => '900000071']);

        Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', ReferralCandidates::STATUS_NEEDS_REVIEW)
            ->assertCanSeeTableRecords([$missing])
            ->assertCanNotSeeTableRecords([$sam]);
    }

    public function test_a_closed_case_is_listed_as_previously_followed_and_not_reopened(): void
    {
        $this->actingAsRole();

        $child = $this->child(110, ['child_id' => '900000080', 'visit_type' => 'new']);

        FollowUpChild::factory()->create([
            'id_number' => '900000080',
            'discharge_outcome' => FollowUpChild::CURED_OUTCOME,
        ]);

        Livewire::test(ReferralCenter::class)
            ->filterTable('referral_status', ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED)
            ->assertCanSeeTableRecords([$child]);

        // Listing it reopens nothing on its own, and the screening is untouched.
        $this->assertSame(1, FollowUpChild::where('id_number', '900000080')->count());
        $this->assertSame(
            FollowUpChild::CURED_OUTCOME,
            FollowUpChild::firstWhere('id_number', '900000080')->discharge_outcome,
        );
        $this->assertSame('new', $child->fresh()->visit_type);
    }

    public function test_the_page_is_still_closed_to_users_without_the_referral_permission(): void
    {
        $this->actingAsRole('Viewer');

        $this->assertFalse(ReferralCenter::canAccess());
        $this->assertFalse(ReferralCenter::canRefer());
    }

    // =================================================================
    // Active and Closed listings
    // =================================================================

    public function test_the_active_tab_lists_open_episodes_only(): void
    {
        $this->actingAsRole();

        $active = FollowUpChild::factory()->create([
            'id_number' => '900000090',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);
        $closed = FollowUpChild::factory()->create([
            'id_number' => '900000091',
            'discharge_outcome' => FollowUpChild::CURED_OUTCOME,
        ]);

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'active'])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$closed]);
    }

    public function test_the_closed_tab_lists_discharged_episodes_only(): void
    {
        $this->actingAsRole();

        $active = FollowUpChild::factory()->create([
            'id_number' => '900000092',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);
        $closed = FollowUpChild::factory()->create([
            'id_number' => '900000093',
            'discharge_outcome' => FollowUpChild::CURED_OUTCOME,
        ]);

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$closed])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_a_closed_case_never_counts_as_an_active_follow_up(): void
    {
        FollowUpChild::factory()->create([
            'id_number' => '900000094',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        foreach (FollowUpChild::CLOSING_OUTCOMES as $index => $outcome) {
            FollowUpChild::factory()->create([
                'id_number' => '9000000' . (95 + $index),
                'discharge_outcome' => $outcome,
            ]);
        }

        $this->assertSame(1, ReferralCandidates::activeFollowUps()->count());
        $this->assertSame(
            count(FollowUpChild::CLOSING_OUTCOMES),
            ReferralCandidates::closedFollowUps()->count(),
        );
    }

    /**
     * Nothing here rewrites an outcome. A discharged record keeps the outcome
     * it was given, whether or not the child is screened again afterwards.
     */
    public function test_a_closed_outcome_is_never_rewritten_by_a_later_screening(): void
    {
        $episode = FollowUpChild::factory()->create([
            'id_number' => '900000100',
            'discharge_outcome' => 'defaulted',
        ]);

        $this->child(110, ['child_id' => '900000100']);

        $this->assertSame('defaulted', $episode->fresh()->discharge_outcome);
        $this->assertSame(0, ReferralCandidates::activeFollowUps()->count());
    }
}
