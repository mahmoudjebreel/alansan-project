<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Support\Referral\ReferralCandidates;
use App\Support\Referral\ReferralProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Referral Centre recognises a child by ID number, and by nothing else.
 *
 * What this file defends: "the child exists in Follow-Up" is answered by the
 * ID number alone. A closed episode is still an episode on file, so a child
 * whose only follow-up record is closed - whatever it closed with, and
 * whether it was admitted as SAM or MAM - is never a new case, is never
 * offered for bulk referral, and never gets a second record written behind
 * the closed one. Open episodes keep reading as they always did, and an ID
 * with nothing on file keeps reading as new.
 *
 * Every assertion below is made three ways, because the Centre answers the
 * question three ways: the SQL CASE the table selects, the per-record
 * fallback, and the batch lookup the bulk referral runs on.
 */
class ReferralClosedFollowUpIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    private function child(int $muac, string $childId, string $name = 'طفل'): Child
    {
        return Child::factory()->create([
            'child_id' => $childId,
            'name' => $name,
            'muac_mm' => $muac,
            'visit_type' => 'new',
            'date_of_reporting' => '2026-08-19',
        ]);
    }

    private function closedEpisode(string $idNumber, string $outcome, string $admittedWith, string $name = 'طفل'): FollowUpChild
    {
        return FollowUpChild::factory()->create([
            'id_number' => $idNumber,
            'child_name' => $name,
            'admitted_with' => $admittedWith,
            'discharge_outcome' => $outcome,
            'discharge_date' => '2026-08-26',
        ]);
    }

    /**
     * The status as the table selects it in SQL, for one child.
     */
    private function selectedStatus(Child $child): ?string
    {
        return ReferralCandidates::overview()
            ->whereKey($child->getKey())
            ->select('children.*')
            ->selectRaw(ReferralCandidates::statusCase() . ' as referral_status')
            ->first()?->referral_status;
    }

    private function isOfferedForReferral(Child $child): bool
    {
        return ReferralCandidates::query()->whereKey($child->getKey())->exists();
    }

    /**
     * A closed episode, by ID number, is an existing child: it reads as
     * previously followed everywhere, is not offered, and a bulk referral of
     * the row skips it as closed and writes nothing.
     */
    private function assertRecognisedAsClosed(Child $child, FollowUpChild $episode): void
    {
        $this->assertSame(ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED, $this->selectedStatus($child));
        $this->assertSame(ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED, ReferralCandidates::statusFor($child));
        $this->assertSame(
            [$child->child_id => ReferralCandidates::STATE_CLOSED],
            ReferralCandidates::followUpStateForChildIds([$child->child_id]),
        );
        $this->assertFalse($this->isOfferedForReferral($child), 'A child with a closed episode is not a new case.');

        $result = ReferralProcessor::refer([$child->getKey()]);

        $this->assertSame(0, $result['referred']);
        $this->assertSame(1, $result['skipped_closed']);
        $this->assertSame(1, FollowUpChild::where('id_number', $child->child_id)->count(), 'No duplicate record.');
        $this->assertSame($episode->discharge_outcome, $episode->fresh()->discharge_outcome, 'The closed episode is untouched.');
    }

    // =================================================================
    // Test 1: the reported case - closed by a medical referral
    // =================================================================

    public function test_a_child_whose_episode_was_closed_by_a_medical_referral_is_recognised_by_id(): void
    {
        $episode = $this->closedEpisode('470828468', 'referred_medical_inpt', 'SAM', 'محمد عبد الكريم خليل عليوة');

        $child = $this->child(109, '470828468', 'محمد عبد الكريم خليل عليوة');

        $this->assertRecognisedAsClosed($child, $episode);
    }

    // =================================================================
    // Test 2: an open episode still reads as already in follow-up
    // =================================================================

    public function test_a_child_with_an_open_episode_still_reads_as_in_follow_up(): void
    {
        $episode = FollowUpChild::factory()->create([
            'id_number' => '470828468',
            'admitted_with' => 'SAM',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
        ]);

        $child = $this->child(109, '470828468');

        $this->assertSame(ReferralCandidates::STATUS_IN_FOLLOW_UP, $this->selectedStatus($child));
        $this->assertSame(ReferralCandidates::STATUS_IN_FOLLOW_UP, ReferralCandidates::statusFor($child));
        $this->assertSame(
            ['470828468' => ReferralCandidates::STATE_OPEN],
            ReferralCandidates::followUpStateForChildIds(['470828468']),
        );
        $this->assertFalse($this->isOfferedForReferral($child));

        $result = ReferralProcessor::refer([$child->getKey()]);

        $this->assertSame(0, $result['referred']);
        $this->assertSame(1, $result['skipped_active']);
        $this->assertSame(1, FollowUpChild::where('id_number', '470828468')->count());
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $episode->fresh()->discharge_outcome);
    }

    // =================================================================
    // Test 3: closed, whatever it closed with
    // =================================================================

    public function test_a_child_with_a_closed_episode_is_recognised_whatever_the_closing_outcome(): void
    {
        foreach (FollowUpChild::CLOSING_OUTCOMES as $index => $outcome) {
            $childId = '4700000' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);

            $episode = $this->closedEpisode($childId, $outcome, 'SAM');
            $child = $this->child(110, $childId);

            $this->assertSame(
                ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED,
                $this->selectedStatus($child),
                "[{$outcome}] is a closed episode and the child exists.",
            );
            $this->assertRecognisedAsClosed($child, $episode);
        }
    }

    // =================================================================
    // Tests 4 and 5: SAM and MAM
    // =================================================================

    public function test_a_closed_sam_episode_is_recognised_by_id(): void
    {
        $episode = $this->closedEpisode('470000101', 'referred_medical_inpt', 'SAM');

        // Screened at SAM again.
        $child = $this->child(110, '470000101');

        $this->assertRecognisedAsClosed($child, $episode);
    }

    public function test_a_closed_mam_episode_is_recognised_by_id(): void
    {
        $episode = $this->closedEpisode('470000102', 'referred_medical_inpt', 'MAM');

        // Screened at MAM again.
        $child = $this->child(120, '470000102');

        $this->assertRecognisedAsClosed($child, $episode);
    }

    // =================================================================
    // Test 6: nothing on file for the ID
    // =================================================================

    public function test_an_id_with_no_follow_up_record_is_still_a_new_case(): void
    {
        $child = $this->child(109, '470000103');

        $this->assertSame(ReferralCandidates::STATUS_PENDING, $this->selectedStatus($child));
        $this->assertSame(ReferralCandidates::STATUS_PENDING, ReferralCandidates::statusFor($child));
        $this->assertSame([], ReferralCandidates::followUpStateForChildIds(['470000103']));
        $this->assertTrue($this->isOfferedForReferral($child));

        $result = ReferralProcessor::refer([$child->getKey()]);

        $this->assertSame(1, $result['referred']);
        $this->assertSame(1, FollowUpChild::where('id_number', '470000103')->count());
    }

    // =================================================================
    // Identity is the ID number, not the name
    // =================================================================

    public function test_the_match_is_by_id_number_and_the_name_spelling_does_not_matter(): void
    {
        $episode = $this->closedEpisode('470828468', 'referred_medical_inpt', 'SAM', 'محمد عبد الكريم خليل عليوة');

        // Same ID, the name written without the space.
        $child = $this->child(109, '470828468', 'محمد عبدالكريم خليل عليوة');

        $this->assertRecognisedAsClosed($child, $episode);
    }

    public function test_the_same_name_under_a_different_id_number_is_a_different_child(): void
    {
        $this->closedEpisode('470828468', 'referred_medical_inpt', 'SAM', 'محمد عبد الكريم خليل عليوة');

        // Same name, one digit of the ID different: by the ID rule this is
        // not the child on file, and the Centre must not pretend it is.
        $child = $this->child(109, '470828568', 'محمد عبد الكريم خليل عليوة');

        $this->assertSame(ReferralCandidates::STATUS_PENDING, $this->selectedStatus($child));
        $this->assertSame(ReferralCandidates::STATUS_PENDING, ReferralCandidates::statusFor($child));
        $this->assertSame([], ReferralCandidates::followUpStateForChildIds(['470828568']));
        $this->assertTrue($this->isOfferedForReferral($child));
    }
}
