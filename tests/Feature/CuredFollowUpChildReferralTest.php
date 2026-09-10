<?php

namespace Tests\Feature;

use App\Filament\Resources\FollowUpChildResource\Pages\ListFollowUpChildren;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\User;
use App\Support\Referral\CuredChildrenReferral;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cured follow-up children who are missing from Children.
 *
 * A cured record whose ID number is on no Children row is listed under
 * "Cured cases pending referral" and offered "Refer to Children". The ID
 * number is the identity; the name plays no part. Referring writes one
 * Children row through the existing discharge transfer and touches nothing
 * in the follow-up record. A Children row that already carries the ID -
 * whenever it appeared - stops both the listing and the write.
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
     * @return array<string, mixed>
     */
    private function snapshot(FollowUpChild $record): array
    {
        return [
            'record' => $record->fresh()->getAttributes(),
            'visits' => $record->visits()->get()->map(fn (FollowUpChildVisit $visit): array => $visit->getAttributes())->all(),
        ];
    }

    /** TEST 1 */
    public function test_a_cured_child_already_in_children_by_id_is_not_pending_and_gets_no_action(): void
    {
        $record = $this->cured();
        // Same ID, a different name: the ID is what matches.
        Child::factory()->create(['child_id' => self::CHILD_ID, 'name' => 'اسم مختلف تماماً']);

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
    public function test_a_cured_child_missing_from_children_is_pending_and_offered_the_action(): void
    {
        $record = $this->cured(['id_number' => self::OTHER_ID]);
        // Same name as the cured child, different ID: no match by name.
        Child::factory()->create(['child_id' => self::CHILD_ID, 'name' => 'طفل الاختبار']);

        $this->assertTrue(CuredChildrenReferral::isPending($record));
        $this->assertSame([$record->getKey()], CuredChildrenReferral::query()->pluck('id')->all());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanSeeTableRecords([$record])
            ->assertTableActionVisible(self::ACTION, $record);

        // Still listed with every other closed case, exactly as before.
        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertCanSeeTableRecords([$record]);
    }

    /** TEST 3 */
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

    /** TEST 4 */
    public function test_after_referral_the_child_is_no_longer_pending(): void
    {
        $record = $this->cured();

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanSeeTableRecords([$record])
            ->callTableAction(self::ACTION, $record)
            ->assertHasNoTableActionErrors();

        $this->assertFalse(CuredChildrenReferral::isPending($record->fresh()));
        $this->assertSame(0, CuredChildrenReferral::query()->count());

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => self::TAB])
            ->assertCanNotSeeTableRecords([$record]);

        Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertCanSeeTableRecords([$record])
            ->assertTableActionHidden(self::ACTION, $record);
    }

    /** TEST 5 */
    public function test_a_children_record_added_after_the_list_was_drawn_stops_a_duplicate(): void
    {
        $record = $this->cured();

        // The action was drawn while the child was still pending.
        $page = Livewire::test(ListFollowUpChildren::class, ['activeTab' => 'closed'])
            ->assertCanSeeTableRecords([$record])
            ->assertTableActionVisible(self::ACTION, $record);

        // Added in between, by an import or by hand.
        Child::factory()->create(['child_id' => self::CHILD_ID]);

        // The click re-evaluates the action against the database: the ID is
        // now on a Children row, so the action is refused and nothing runs.
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

    public function test_the_transfer_itself_refuses_a_child_already_in_children(): void
    {
        $record = $this->cured();
        Child::factory()->create(['child_id' => self::CHILD_ID]);

        // Around any button, the write refuses on the ID number alone.
        $this->assertSame(CuredChildrenReferral::BLOCKER_ALREADY_EXISTS, CuredChildrenReferral::blocker($record));
        $this->assertNull(CuredChildrenReferral::refer($record));
        $this->assertSame(1, Child::query()->where('child_id', self::CHILD_ID)->count());
    }

    /** TEST 6 */
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

    /** TEST 7 */
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
