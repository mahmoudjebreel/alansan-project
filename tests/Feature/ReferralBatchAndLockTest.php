<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Support\ChildFollowUpTransfer;
use App\Support\Referral\ReferralProcessor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * F2: a bulk referral reads the follow-up history of its whole selection in
 * one query - no lookup per child, whatever the children's histories are.
 *
 * F3: opening an episode happens under the child's lock, so a second attempt
 * at the same moment can never open a second episode.
 */
class ReferralBatchAndLockTest extends TestCase
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

    // =================================================================
    // F2 - batch
    // =================================================================

    public function test_skipping_children_back_after_a_default_or_an_other_exit_costs_no_query_per_child(): void
    {
        $selection = [];

        foreach (range(1, 20) as $i) {
            $idNumber = (string) (470400000 + $i);
            $this->closed($idNumber, $i % 2 === 0 ? 'defaulted' : 'discharge_to_other');
            $selection[] = $this->screening($idNumber)->getKey();
        }

        $selects = $this->countSelects(fn () => $result = ReferralProcessor::refer($selection), $result);

        $this->assertSame(20, $result['skipped_closed']);
        // The chunk, its follow-up history, and the list of children who died.
        $this->assertLessThanOrEqual(3, $selects, 'The history of a selection is read once, not once per child.');
    }

    public function test_referring_children_back_after_a_cure_reads_only_what_each_opening_must_recheck(): void
    {
        $selection = [];

        foreach (range(1, 20) as $i) {
            $idNumber = (string) (470410000 + $i);
            $this->closed($idNumber, 'cured');
            $selection[] = $this->screening($idNumber)->getKey();
        }

        $selects = $this->countSelects(fn () => $result = ReferralProcessor::refer($selection), $result);

        $this->assertSame(20, $result['referred']);

        // A fixed cost for the selection, and per opened episode only the
        // re-checks the transfer must make under the child's lock (open,
        // died, latest closed) plus the classification for the audit entry.
        $this->assertLessThanOrEqual(3 + 20 * 5, $selects);

        foreach (range(1, 20) as $i) {
            $this->assertSame(
                FollowUpChild::READMISSION_AFTER_RELAPSE,
                FollowUpChild::where('id_number', (string) (470410000 + $i))->orderByDesc('id')->first()->readmissionClassification(),
            );
        }
    }

    public function test_the_batch_decides_exactly_as_the_one_child_readmission_test_does(): void
    {
        // Every closing outcome, one child each, back at SAM.
        $selection = [];
        $expected = [];

        foreach (FollowUpChild::CLOSING_OUTCOMES as $i => $outcome) {
            $idNumber = (string) (470420000 + $i);
            $this->closed($idNumber, $outcome);
            $selection[] = $this->screening($idNumber)->getKey();

            $expected[$idNumber] = match (true) {
                $outcome === 'died' => 'skipped_died',
                FollowUpChild::readmittableEpisodeFor($idNumber) !== null => 'skipped_closed',
                default => 'referred',
            };
        }

        $result = ReferralProcessor::refer($selection);

        $this->assertSame(count(array_keys($expected, 'referred', true)), $result['referred']);
        $this->assertSame(count(array_keys($expected, 'skipped_closed', true)), $result['skipped_closed']);
        $this->assertSame(count(array_keys($expected, 'skipped_died', true)), $result['skipped_died']);
    }

    // =================================================================
    // F3 - lock
    // =================================================================

    public function test_a_second_attempt_while_the_first_holds_the_lock_opens_nothing(): void
    {
        $child = $this->screening('470430001');

        // The first attempt is in progress: it holds the child's lock.
        $first = Cache::lock(ChildFollowUpTransfer::lockKey('470430001'), 10);
        $this->assertTrue($first->get());

        // The second attempt at the same moment - Referral Centre, the
        // Children form, the readmission paths - opens nothing.
        $this->assertNull(ChildFollowUpTransfer::refer($child));
        $this->assertSame(0, ReferralProcessor::refer([$child->getKey()])['referred']);
        $this->assertSame(0, FollowUpChild::where('id_number', '470430001')->count());

        $first->release();

        // Once it is free, the child is referred - exactly once.
        $this->assertNotNull(ChildFollowUpTransfer::refer($child));
        $this->assertNull(ChildFollowUpTransfer::refer($child));
        $this->assertSame(1, FollowUpChild::where('id_number', '470430001')->count());
    }

    public function test_the_readmission_paths_are_locked_as_well(): void
    {
        $closed = $this->closed('470430002', 'defaulted');
        $child = $this->screening('470430002');

        $held = Cache::lock(ChildFollowUpTransfer::lockKey('470430002'), 10);
        $this->assertTrue($held->get());

        $this->assertNull(ChildFollowUpTransfer::readmit($child));
        $this->assertNull(ChildFollowUpTransfer::readmitFromEpisode($closed, [
            'admission_date' => '2026-09-01', 'visit_date' => '2026-09-01', 'muac' => 110,
        ]));
        $this->assertSame(1, FollowUpChild::where('id_number', '470430002')->count());

        $held->release();

        $this->assertNotNull(ChildFollowUpTransfer::readmit($child));
        $this->assertSame(2, FollowUpChild::where('id_number', '470430002')->count());
    }

    public function test_the_lock_is_released_after_every_attempt(): void
    {
        $child = $this->screening('470430003');

        ChildFollowUpTransfer::refer($child);   // opens
        ChildFollowUpTransfer::refer($child);   // refuses: already open

        $lock = Cache::lock(ChildFollowUpTransfer::lockKey('470430003'), 10);
        $this->assertTrue($lock->get(), 'The lock must not be left held.');
        $lock->release();
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function countSelects(callable $run, mixed &$result): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $result = $run();

        $selects = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower(trim($query['query'])), 'select'))
            ->count();

        DB::disableQueryLog();

        return $selects;
    }

    private function closed(string $idNumber, string $outcome): FollowUpChild
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
            'admission_date' => '2026-07-01',
            'discharge_date' => '2026-08-01',
            'discharge_outcome' => $outcome,
        ]);
    }

    private function screening(string $idNumber): Child
    {
        return Child::create([
            'visit_type' => 'new',
            'name' => 'Test child',
            'child_id' => $idNumber,
            'organization' => 'AEI',
            'implementing_partner' => 'AEI',
            'date_of_reporting' => '2026-09-01',
            'sex' => 'male',
            'date_of_birth' => '2025-01-01',
            'muac_mm' => 110,
            'has_oedema' => false,
            'is_pwd' => false,
            'governorate' => 'gaza',
            'location' => 'Mosaab camp',
            'type_of_site' => 'Mossab Camp',
        ]);
    }
}
