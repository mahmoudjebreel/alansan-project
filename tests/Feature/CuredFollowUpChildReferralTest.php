<?php

namespace Tests\Feature;

use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\User;
use App\Services\MealReportService;
use App\Support\MealReport\MealReportLayout;
use App\Support\MealReport\ReportPeriod;
use App\Support\Referral\CuredChildrenReferral;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cured follow-up episodes whose cure has not been written to Children.
 *
 * A cured record is listed under "Cured cases pending referral" and offered
 * "Refer to Children" until a Children row has been written FROM THAT
 * EPISODE - the row carries source_follow_up_child_id. The screening row
 * that opened the episode, or any other Children visit the same child has,
 * is not a referral of it and does not hide it. Referring writes one
 * Children row through the existing discharge transfer and touches nothing
 * in the follow-up record. Once a live Children row points at the episode,
 * both the listing and the write stop.
 */
class CuredFollowUpChildReferralTest extends TestCase
{
    use RefreshDatabase;

    private const CHILD_ID = '470828468';

    private const OTHER_ID = '470999999';

    private const TAB = 'cured_pending_referral';

    private const ACTION = 'referToChildren';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    /**
     * A closed episode as the Excel import leaves it: the outcome written,
     * the visits recorded, and nothing sent to Children.
     */
    private function episode(?string $outcome, array $attributes = []): FollowUpChild
    {
        $record = FollowUpChild::factory()->create(array_merge([
            'id_number' => self::CHILD_ID,
            'child_name' => 'طفل الاختبار',
            'sex' => 'F',
            'dob' => '2025-02-10',
            'mobile_number' => '0591234567',
            'shelter_name' => 'مركز الإيواء أ',
            'governorate' => 'gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_date' => '2026-08-19',
            'discharge_date' => '2026-09-02',
            'discharge_outcome' => $outcome,
        ], $attributes));

        $record->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-19', 'muac' => 110, 'status' => FollowUpChildVisit::STATUS_ATTENDED]);
        $record->visits()->create(['visit_number' => 2, 'visit_date' => '2026-08-26', 'muac' => null, 'status' => FollowUpChildVisit::STATUS_MISSED]);
        $record->visits()->create(['visit_number' => 3, 'visit_date' => '2026-09-02', 'muac' => 126, 'status' => FollowUpChildVisit::STATUS_ATTENDED]);

        return $record;
    }

    private function cured(array $attributes = []): FollowUpChild
    {
        return $this->episode(FollowUpChild::CURED_OUTCOME, $attributes);
    }

    /**
     * The Children screening a follow-up episode is normally opened from:
     * same ID number, written before the episode, pointing at no episode.
     */
    private function screening(string $childId = self::CHILD_ID, array $attributes = []): Child
    {
        return Child::factory()->create(array_merge([
            'child_id' => $childId,
            'name' => 'طفل الاختبار',
            'muac_mm' => 110,
            'source_follow_up_child_id' => null,
        ], $attributes));
    }

    /**
     * The Children row the discharge transfer writes for an episode.
     */
    private function transferred(FollowUpChild $record): Child
    {
        return Child::factory()->create([
            'child_id' => $record->id_number,
            'muac_mm' => 126,
            'source_follow_up_child_id' => $record->getKey(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(FollowUpChild $record): array
    {
        return [
            'record' => $record->fresh()->getAttributes(),
            'visits' => $record->visits()->get()->map(fn (FollowUpChildVisit $visit): array => $visit->getAttributes())->all(),
        ];
    }

    /**
     * The CMAM sheet totals for September 2026, every site.
     *
     * @return array<string, int|float|string|null>
     */
    private function cmamTotals(): array
    {
        return app(MealReportService::class)
            ->buildPeriod(ReportPeriod::make(2026, 9, 9), null)[MealReportLayout::SHEET_CMAM]['totals'];
    }

    /** TEST 1 */
    public function test_a_cured_episode_already_transferred_to_children_is_not_pending_and_gets_no_action(): void
    {
        $record = $this->cured();
        // The row the discharge transfer wrote from this very episode.
        $this->transferred($record);

        $this->assertTrue(CuredChildrenReferral::isTransferred($record));
        $this->assertFalse(CuredChildrenReferral::isPending($record));
        $this->assertSame(0, CuredChildrenReferral::query()->count());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanNotSeeTableRecords([$record]);

        // Where the record is still listed, the action is not offered.
        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertCanSeeTableRecords([$record])
            ->assertTableActionHidden(self::ACTION, $record);

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'all'])
            ->assertTableActionHidden(self::ACTION, $record);
    }

    /** TEST 2 */
    public function test_a_cured_episode_with_an_earlier_children_screening_for_the_same_id_is_still_pending(): void
    {
        // The screening that opened the episode: same ID, written first.
        $screening = $this->screening();
        $record = $this->cured();

        // A Children row for the child is not a referral of this episode.
        $this->assertFalse(CuredChildrenReferral::isTransferred($record));
        $this->assertTrue(CuredChildrenReferral::isPending($record));
        $this->assertNull(CuredChildrenReferral::blocker($record));
        $this->assertSame([$record->getKey()], CuredChildrenReferral::query()->pluck('id')->all());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanSeeTableRecords([$record])
            ->assertTableActionVisible(self::ACTION, $record)
            ->callTableAction(self::ACTION, $record)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('ui.cured_referral.done_title'));

        // One row added, pointing at the episode; the screening untouched.
        $this->assertSame(2, Child::query()->where('child_id', self::CHILD_ID)->count());
        $this->assertSame(1, Child::query()->where('source_follow_up_child_id', $record->getKey())->count());
        $this->assertNull($screening->fresh()->source_follow_up_child_id);
        $this->assertEqualsWithDelta(110.0, (float) $screening->fresh()->muac_mm, 0.001);

        $this->assertFalse(CuredChildrenReferral::isPending($record->fresh()));
    }

    /** TEST 3 */
    public function test_a_cured_child_missing_from_children_is_pending_and_offered_the_action(): void
    {
        $record = $this->cured(['id_number' => self::OTHER_ID]);
        // Same name as the cured child, different ID: no match by name.
        $this->screening(self::CHILD_ID);

        $this->assertTrue(CuredChildrenReferral::isPending($record));
        $this->assertSame([$record->getKey()], CuredChildrenReferral::query()->pluck('id')->all());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanSeeTableRecords([$record])
            ->assertTableActionVisible(self::ACTION, $record);

        // Still listed with every other closed case, exactly as before.
        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertCanSeeTableRecords([$record]);
    }

    /** TEST 4 */
    public function test_referring_creates_the_children_record_through_the_existing_transfer(): void
    {
        $record = $this->cured();
        $before = $this->snapshot($record);

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->callTableAction(self::ACTION, $record)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('ui.cured_referral.done_title'));

        $child = Child::query()->where('child_id', self::CHILD_ID)->sole();

        // What the existing discharge transfer writes, from the last
        // attended reading, linked back to this follow-up record.
        $this->assertSame($record->getKey(), $child->source_follow_up_child_id);
        $this->assertSame('new', $child->visit_type);
        $this->assertSame('طفل الاختبار', $child->name);
        $this->assertSame('female', $child->sex);
        $this->assertSame('2025-02-10', $child->date_of_birth->format('Y-m-d'));
        $this->assertSame('2026-09-02', $child->date_of_reporting->format('Y-m-d'));
        $this->assertEqualsWithDelta(126.0, (float) $child->muac_mm, 0.001);
        $this->assertSame('مركز الإيواء أ', $child->location);

        // The follow-up record: not a byte moved.
        $this->assertSame($before, $this->snapshot($record));
        $this->assertSame(FollowUpChild::CURED_OUTCOME, $record->fresh()->discharge_outcome);
        $this->assertTrue($record->fresh()->isLocked());
        $this->assertSame(1, FollowUpChild::where('id_number', self::CHILD_ID)->count());
    }

    /** TEST 5 */
    public function test_after_referral_the_child_is_no_longer_pending(): void
    {
        $record = $this->cured();

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanSeeTableRecords([$record])
            ->callTableAction(self::ACTION, $record)
            ->assertHasNoTableActionErrors();

        $this->assertTrue(CuredChildrenReferral::isTransferred($record->fresh()));
        $this->assertFalse(CuredChildrenReferral::isPending($record->fresh()));
        $this->assertSame(0, CuredChildrenReferral::query()->count());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanNotSeeTableRecords([$record]);

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertCanSeeTableRecords([$record])
            ->assertTableActionHidden(self::ACTION, $record);
    }

    /** TEST 6 */
    public function test_a_transfer_written_after_the_list_was_drawn_stops_a_duplicate(): void
    {
        $record = $this->cured();

        // The action was drawn while the episode was still pending.
        $page = Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertCanSeeTableRecords([$record])
            ->assertTableActionVisible(self::ACTION, $record);

        // Written from this episode in between, by another person.
        $this->transferred($record);

        // The click re-evaluates the action against the database: a row now
        // points at the episode, so the action is refused and nothing runs.
        $page->assertTableActionHidden(self::ACTION, $record);

        $this->assertSame(1, Child::query()->where('child_id', self::CHILD_ID)->count());

        // On the pending tab the record has already dropped out of the
        // listing, so Filament cannot even resolve the click: nothing runs.
        $this->expectException(\Filament\Actions\Exceptions\ActionNotResolvableException::class);

        try {
            Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
                ->callTableAction(self::ACTION, $record);
        } finally {
            $this->assertSame(1, Child::query()->where('child_id', self::CHILD_ID)->count());
        }
    }

    public function test_a_screening_added_after_the_list_was_drawn_does_not_stop_the_referral(): void
    {
        $record = $this->cured();

        $page = Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanSeeTableRecords([$record])
            ->assertTableActionVisible(self::ACTION, $record);

        // A Children visit for the same child, added in between by an
        // import or by hand. It points at no episode.
        $this->screening();

        $page->assertTableActionVisible(self::ACTION, $record)
            ->callTableAction(self::ACTION, $record)
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, Child::query()->where('child_id', self::CHILD_ID)->count());
        $this->assertSame(1, Child::query()->where('source_follow_up_child_id', $record->getKey())->count());
    }

    public function test_the_transfer_itself_refuses_an_episode_already_transferred(): void
    {
        $record = $this->cured();
        $this->transferred($record);

        // Around any button, the write refuses on the episode link alone.
        $this->assertSame(CuredChildrenReferral::BLOCKER_ALREADY_EXISTS, CuredChildrenReferral::blocker($record));
        $this->assertNull(CuredChildrenReferral::refer($record));
        $this->assertSame(1, Child::query()->where('child_id', self::CHILD_ID)->count());
    }

    public function test_the_transfer_itself_is_not_refused_by_a_screening_for_the_same_id(): void
    {
        $this->screening();
        $record = $this->cured();

        $this->assertNull(CuredChildrenReferral::blocker($record));
        $this->assertInstanceOf(Child::class, CuredChildrenReferral::refer($record));
        $this->assertSame(2, Child::query()->where('child_id', self::CHILD_ID)->count());
    }

    /** TEST 7 */
    public function test_non_cured_records_are_never_pending(): void
    {
        $others = [];
        $index = 0;

        foreach (['under_follow_up', 'defaulted', 'discharge_to_opt', 'discharge_to_other', 'non_responded', 'referred_medical_inpt', 'died', null] as $outcome) {
            $others[] = $this->episode($outcome, [
                'id_number' => (string) (480000000 + $index++),
                'discharge_date' => in_array($outcome, FollowUpChild::CLOSING_OUTCOMES, true) ? '2026-09-02' : null,
            ]);
        }

        $cured = $this->cured();

        $this->assertSame([$cured->getKey()], CuredChildrenReferral::query()->pluck('id')->all());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanSeeTableRecords([$cured])
            ->assertCanNotSeeTableRecords($others);

        // Where every record is listed, only the cured one gets the action.
        $all = Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'all'])
            ->assertTableActionVisible(self::ACTION, $cured);

        foreach ($others as $other) {
            $this->assertFalse(CuredChildrenReferral::isPending($other), "[{$other->discharge_outcome}] must not be pending.");
            $this->assertSame(CuredChildrenReferral::BLOCKER_NOT_CURED, CuredChildrenReferral::blocker($other));
            $this->assertNull(CuredChildrenReferral::refer($other));
            $all->assertTableActionHidden(self::ACTION, $other);
        }

        $this->assertSame(0, Child::count());
    }

    /**
     * An open episode whose latest reading is Normal is under follow-up,
     * not cured: it is never pending, whatever the measurement says.
     */
    public function test_an_open_episode_with_a_normal_latest_reading_is_not_pending(): void
    {
        $record = $this->episode(FollowUpChild::ACTIVE_OUTCOME, ['discharge_date' => null]);
        $record->visits()->create(['visit_number' => 4, 'visit_date' => '2026-09-09', 'muac' => 125, 'status' => FollowUpChildVisit::STATUS_ATTENDED]);

        $this->assertFalse($record->isLocked());
        $this->assertFalse(CuredChildrenReferral::isPending($record));
        $this->assertSame(CuredChildrenReferral::BLOCKER_NOT_CURED, CuredChildrenReferral::blocker($record));
        $this->assertSame(0, CuredChildrenReferral::query()->count());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanNotSeeTableRecords([$record]);

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'active'])
            ->assertCanSeeTableRecords([$record])
            ->assertTableActionHidden(self::ACTION, $record);
    }

    /** TEST 8 */
    public function test_the_follow_up_history_and_visits_are_left_exactly_as_they_were(): void
    {
        $record = $this->cured();
        $before = $this->snapshot($record);
        $visitsBefore = FollowUpChildVisit::count();

        $child = CuredChildrenReferral::refer($record);

        $this->assertInstanceOf(Child::class, $child);
        $this->assertSame($before, $this->snapshot($record));

        // One attended, one missed, one attended - and no visit added.
        $history = $record->visits()->get();
        $this->assertCount(3, $history);
        $this->assertSame($visitsBefore, FollowUpChildVisit::count());
        $this->assertSame(FollowUpChildVisit::STATUS_ATTENDED, $history[0]->status);
        $this->assertSame(FollowUpChildVisit::STATUS_MISSED, $history[1]->status);
        $this->assertNull($history[1]->muac);
        $this->assertSame(FollowUpChildVisit::STATUS_ATTENDED, $history[2]->status);

        // Still cured, still closed, same discharge date, no second episode.
        $fresh = $record->fresh();
        $this->assertSame(FollowUpChild::CURED_OUTCOME, $fresh->discharge_outcome);
        $this->assertSame('2026-09-02', $fresh->discharge_date->format('Y-m-d'));
        $this->assertTrue($fresh->isLocked());
        $this->assertSame(1, FollowUpChild::count());

        // And a second call writes nothing more.
        $this->assertNull(CuredChildrenReferral::refer($record));
        $this->assertSame(1, Child::count());
    }

    /** TEST 9 */
    public function test_two_cured_episodes_for_the_same_child_are_referred_independently(): void
    {
        $first = $this->cured(['admission_date' => '2026-06-01', 'discharge_date' => '2026-07-01']);
        $second = $this->cured();

        // Both pending: two cures, neither written across.
        $this->assertSame([$first->getKey(), $second->getKey()], CuredChildrenReferral::query()->orderBy('id')->pluck('id')->all());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanSeeTableRecords([$first, $second]);

        // Referring one leaves the other pending.
        $this->assertInstanceOf(Child::class, CuredChildrenReferral::refer($first));

        $this->assertFalse(CuredChildrenReferral::isPending($first->fresh()));
        $this->assertTrue(CuredChildrenReferral::isPending($second->fresh()));
        $this->assertSame([$second->getKey()], CuredChildrenReferral::query()->pluck('id')->all());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanNotSeeTableRecords([$first])
            ->assertCanSeeTableRecords([$second])
            ->assertTableActionVisible(self::ACTION, $second);

        // Referring the other: one Children row per episode, each linked.
        $this->assertInstanceOf(Child::class, CuredChildrenReferral::refer($second));

        $this->assertSame(0, CuredChildrenReferral::query()->count());
        $this->assertSame(2, Child::query()->where('child_id', self::CHILD_ID)->count());
        $this->assertSame(1, Child::query()->where('source_follow_up_child_id', $first->getKey())->count());
        $this->assertSame(1, Child::query()->where('source_follow_up_child_id', $second->getKey())->count());

        // And neither can be written a second time.
        $this->assertNull(CuredChildrenReferral::refer($first));
        $this->assertNull(CuredChildrenReferral::refer($second));
        $this->assertSame(2, Child::count());
    }

    /** TEST 10 */
    public function test_the_cmam_sheet_counts_the_cure_the_same_whether_or_not_it_was_referred(): void
    {
        $record = $this->cured();

        // Recovered in September, in the band it was admitted into.
        $before = $this->cmamTotals();
        $this->assertSame(1, $before['sam_dis_recovered_6_23_female']);

        $this->assertInstanceOf(Child::class, CuredChildrenReferral::refer($record));

        // The referral wrote to Children only: the CMAM sheet, read from
        // follow_up_children, is the same figure for figure.
        $this->assertSame($before, $this->cmamTotals());
    }

    public function test_a_cured_record_with_no_attended_visit_is_listed_but_not_written(): void
    {
        $record = FollowUpChild::factory()->create([
            'id_number' => self::CHILD_ID,
            'child_name' => 'طفل بلا زيارات',
            'sex' => 'M',
            'discharge_outcome' => FollowUpChild::CURED_OUTCOME,
        ]);

        // Listed: the case still needs a person to look at it.
        $this->assertTrue(CuredChildrenReferral::isPending($record));

        // Not written: there is no reading to hand over.
        $this->assertSame(CuredChildrenReferral::BLOCKER_NO_VISIT, CuredChildrenReferral::blocker($record));
        $this->assertNull(CuredChildrenReferral::refer($record));
        $this->assertSame(0, Child::count());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->callTableAction(self::ACTION, $record)
            ->assertNotified(__('ui.cured_referral.blocked.no_visit'));

        $this->assertSame(0, Child::count());
    }
}
