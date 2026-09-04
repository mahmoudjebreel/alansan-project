<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource;
use App\Filament\Resources\ChildResource\Pages\EditChild;
use App\Filament\Resources\FollowUpChildResource;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Correcting a measurement upwards into SAM or MAM on an existing Children
 * record, and the follow-up episode that opens from it.
 *
 * The referral used to happen on create only, so a reading entered wrong and
 * fixed afterwards - the commonest way a child ends up malnourished in the
 * data - opened nothing at all and the child was never followed up.
 *
 * The screener is asked before anything is opened, in the browser, and the
 * answer arrives here as `referFollowUpOnSave`. What is tested here is the
 * server half: what a confirmed answer does, and the three cases that must
 * open nothing whatever the browser sent.
 */
class ChildEditReferralTest extends TestCase
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

    private function child(int $muac): Child
    {
        return Child::factory()->create([
            'child_id' => '123456789',
            'name' => 'طفل التعديل',
            'sex' => 'male',
            'muac_mm' => $muac,
            'phone_number' => '0599000000',
            // Required by the form; the factory leaves them for the caller.
            'municipality' => 'gaza',
            'type_of_site' => 'El Salam Camp',
            'mother_marital_status' => 'متزوجة',
        ]);
    }

    private function edit(Child $child): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(EditChild::class, ['record' => $child->getKey()]);
    }

    // -----------------------------------------------------------------
    // 1. Normal corrected to SAM, confirmed
    // -----------------------------------------------------------------

    public function test_a_reading_corrected_into_sam_saves_the_edit_and_opens_an_episode(): void
    {
        $child = $this->child(130);

        $this->edit($child)
            ->fillForm(['muac_mm' => 110])
            // What the browser sets when the screener answers "yes, refer".
            ->set('referFollowUpOnSave', true)
            ->call('save')
            ->assertHasNoFormErrors();

        // The Children row is an existing record being corrected, so it stays
        // in Children carrying its new reading.
        $child->refresh();
        $this->assertEqualsWithDelta(110.0, (float) $child->muac_mm, 0.001);
        $this->assertSame('SAM', $child->fi);
        $this->assertSame(1, Child::count());

        $followUp = FollowUpChild::with('visits')->firstWhere('id_number', '123456789');

        $this->assertNotNull($followUp);
        $this->assertSame('SAM', $followUp->admitted_with);
        $this->assertSame('malnutrition', $followUp->causes_of_admission);
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $followUp->discharge_outcome);
        $this->assertSame($child->id, $followUp->source_child_visit_id);

        // The first visit carries the corrected measurement, not the old one.
        $this->assertCount(1, $followUp->visits);
        $visit = $followUp->visits->first();
        $this->assertSame(1, $visit->visit_number);
        $this->assertEqualsWithDelta(110.0, (float) $visit->muac, 0.001);
        $this->assertSame('SAM', $visit->fi);
    }

    public function test_a_mam_correction_is_admitted_as_mam(): void
    {
        $child = $this->child(130);

        $this->edit($child)
            ->fillForm(['muac_mm' => 120])
            ->set('referFollowUpOnSave', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('MAM', FollowUpChild::first()->admitted_with);
    }

    public function test_the_screener_lands_on_the_episode_that_was_just_opened(): void
    {
        $child = $this->child(130);

        $this->edit($child)
            ->fillForm(['muac_mm' => 110])
            ->set('referFollowUpOnSave', true)
            ->call('save')
            ->assertRedirect(FollowUpChildResource::getUrl('edit', [
                'record' => FollowUpChild::first(),
            ]));
    }

    // -----------------------------------------------------------------
    // 2, 3, 4. The saves that must open nothing
    // -----------------------------------------------------------------

    public function test_editing_another_field_saves_normally_and_opens_nothing(): void
    {
        $child = $this->child(130);

        $this->edit($child)
            ->fillForm(['phone_number' => '0599111222'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('0599111222', $child->refresh()->phone_number);
        $this->assertSame(0, FollowUpChild::count());
    }

    public function test_re_saving_an_unchanged_sam_reading_opens_nothing(): void
    {
        $child = $this->child(110);

        // Even with the flag set - a stale answer, a replayed request - an
        // untouched measurement is not a new decision about this child.
        $this->edit($child)
            ->set('referFollowUpOnSave', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, FollowUpChild::count());
    }

    public function test_a_reading_changed_but_still_normal_opens_nothing(): void
    {
        $child = $this->child(130);

        $this->edit($child)
            ->fillForm(['muac_mm' => 125])
            ->set('referFollowUpOnSave', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(125.0, (float) $child->refresh()->muac_mm, 0.001);
        $this->assertSame('Normal', $child->fi);
        $this->assertSame(0, FollowUpChild::count());
    }

    public function test_an_unconfirmed_save_writes_the_edit_but_opens_nothing(): void
    {
        // Declining in the browser stops the save from ever being sent, so the
        // server never sees this case from the panel. It is still what every
        // non-browser path does - and it must not refer.
        $child = $this->child(130);

        $this->edit($child)
            ->fillForm(['muac_mm' => 110])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('SAM', $child->refresh()->fi);
        $this->assertSame(0, FollowUpChild::count());
    }

    public function test_a_child_already_under_follow_up_does_not_get_a_second_episode(): void
    {
        $child = $this->child(130);

        $this->edit($child)
            ->fillForm(['muac_mm' => 110])
            ->set('referFollowUpOnSave', true)
            ->call('save');

        // Corrected again while the first episode is still open.
        $this->edit($child->refresh())
            ->fillForm(['muac_mm' => 112])
            ->set('referFollowUpOnSave', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsWithDelta(112.0, (float) $child->refresh()->muac_mm, 0.001);
        $this->assertSame(1, FollowUpChild::count());
    }

    public function test_one_confirmation_cannot_open_two_episodes(): void
    {
        $child = $this->child(130);

        $component = $this->edit($child)
            ->fillForm(['muac_mm' => 110])
            ->set('referFollowUpOnSave', true)
            ->call('save');

        // The flag is spent by the save that used it.
        $component->assertSet('referFollowUpOnSave', false);
    }

    // -----------------------------------------------------------------
    // What the browser needs in order to ask only on a real change
    // -----------------------------------------------------------------

    public function test_the_edit_form_carries_the_reading_already_on_file(): void
    {
        $child = $this->child(130);

        $html = $this->get(ChildResource::getUrl('edit', ['record' => $child]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-muac-referral', $html);
        // Without this the prompt could not tell a corrected measurement from
        // an untouched one, and would fire on every save of a SAM child.
        $this->assertStringContainsString('data-muac-original="130"', $html);
    }

    public function test_the_create_form_carries_no_stored_reading_to_compare_against(): void
    {
        $html = $this->get(ChildResource::getUrl('create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-muac-referral', $html);
        // Matched as an attribute: the alerts script names it in a comment,
        // which is on every page of the panel.
        $this->assertStringNotContainsString('data-muac-original="', $html);
    }
}
