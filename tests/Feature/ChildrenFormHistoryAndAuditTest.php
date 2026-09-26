<?php

namespace Tests\Feature;

use App\Filament\Pages\Trash;
use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Filament\Resources\ChildResource\Pages\EditChild;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * F8: what the Children form tells the screener about a child's history is
 * read from the classification and the terminal state - a return after a
 * cure is named as the readmission after relapse it is, and a child who died
 * is named as such before any referral question.
 *
 * F7: a refusal because of a death is audited, once, where it happened.
 */
class ChildrenFormHistoryAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
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

    public function test_the_history_payload_follows_the_classification_and_the_terminal_state(): void
    {
        $cases = [
            'cured' => ['470010001', true, 'readmission_after_relapse', false],
            'defaulted' => ['470010002', true, 'readmission_after_defaulted', false],
            'discharge_to_other' => ['470010003', true, 'readmission_after_other', false],
            'non_responded' => ['470010004', false, null, false],
        ];

        foreach ($cases as $outcome => [$idNumber, $readmission, $label, $terminal]) {
            $this->episode($idNumber, $outcome);

            Livewire::test(CreateChild::class)
                ->set('data.child_id', $idNumber)
                ->assertDispatched('follow-up-history-known', function (string $event, array $params) use ($outcome, $readmission, $label, $terminal): bool {
                    $history = $params[0] ?? $params;

                    $this->assertSame($readmission, $history['readmission'], "[{$outcome}] readmission");
                    $this->assertSame($label === null ? null : __('fields.' . $label), $history['classification'], "[{$outcome}] classification");
                    $this->assertSame($terminal, $history['terminal'], "[{$outcome}] terminal");
                    $this->assertSame($terminal ? __('ui.died_terminal.message') : null, $history['terminal_message'], "[{$outcome}] message");

                    return true;
                });
        }
    }

    public function test_a_child_who_died_is_named_as_soon_as_the_id_is_typed(): void
    {
        $this->episode('470010005', 'died');

        // A new screening: the ID field itself refuses, before any question.
        Livewire::test(CreateChild::class)
            ->set('data.child_id', '470010005')
            ->assertHasErrors(['data.child_id']);

        // Typing is not a save: nothing is audited yet.
        $this->assertSame(0, Activity::query()->where('log_name', 'died_terminal')->count());

        // An existing screening from before the death, being corrected: the
        // history the prompt reads says the child died, with the message.
        $screening = $this->screening('470010005', '2026-06-10', 130);

        Livewire::test(EditChild::class, ['record' => $screening->getKey()])
            ->set('data.child_id', '')
            ->set('data.child_id', '470010005')
            ->assertDispatched('follow-up-history-known', function (string $event, array $params): bool {
                $history = $params[0] ?? $params;

                $this->assertTrue($history['terminal']);
                $this->assertSame(__('ui.died_terminal.message'), $history['terminal_message']);
                $this->assertFalse($history['readmission']);
                $this->assertNull($history['classification']);

                return true;
            });
    }

    public function test_the_readmission_button_keeps_its_own_narrower_test(): void
    {
        // The prompt names a return after a cure as a readmission; the
        // one-child Readmission button is still offered only after a default
        // or an other exit.
        $cured = $this->episode('470010010', 'cured');

        $this->assertFalse($cured->canBeReadmitted());
        $this->assertNull(FollowUpChild::readmittableEpisodeFor('470010010'));
    }

    public function test_the_notice_after_a_referral_names_a_readmission_after_relapse_as_a_readmission(): void
    {
        $this->episode('470010020', 'cured');

        Livewire::test(CreateChild::class)
            ->fillForm($this->formData('470010020', 110))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('fields.readmitted_to_follow_up_title'));
    }

    public function test_a_refused_screening_after_a_death_is_audited(): void
    {
        $this->episode('470010030', 'died');

        Livewire::test(CreateChild::class)
            ->fillForm($this->formData('470010030', 110))
            ->call('create')
            ->assertHasFormErrors(['child_id']);

        $entry = Activity::query()->where('log_name', 'died_terminal')->sole();
        $this->assertSame('children_form', $entry->properties['workflow']);
        $this->assertSame('470010030', $entry->properties['child_id']);
    }

    public function test_an_edit_refused_a_follow_up_after_a_death_is_audited(): void
    {
        $this->episode('470010040', 'died');

        // A screening from before the death, corrected to SAM.
        $screening = $this->screening('470010040', '2026-07-10', 130);

        Livewire::test(EditChild::class, ['record' => $screening->getKey()])
            ->set('referFollowUpOnSave', true)
            ->fillForm(['muac_mm' => 110])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('ui.died_terminal.follow_up_refused'));

        $this->assertSame(1, FollowUpChild::where('id_number', '470010040')->count());
        $this->assertSame('children_edit', Activity::query()->where('log_name', 'died_terminal')->sole()->properties['workflow']);
    }

    public function test_a_refused_restore_is_audited(): void
    {
        $this->episode('470010050', 'died');
        $after = FollowUpChild::create([
            'id_number' => '470010050', 'child_name' => 'Test child', 'sex' => 'M', 'dob' => '2025-01-01',
            'mobile_number' => '0599123456', 'shelter_name' => 'Mosaab camp', 'governorate' => 'Gaza',
            'causes_of_admission' => 'malnutrition', 'admitted_with' => 'SAM',
            'admission_date' => '2026-09-01', 'discharge_date' => '2026-09-10', 'discharge_outcome' => 'cured',
        ]);
        $after->delete();

        Livewire::test(Trash::class)->instance()->restore('follow_up_child', $after->id);

        $this->assertSame('trash_restore', Activity::query()->where('log_name', 'died_terminal')->sole()->properties['workflow']);
    }

    private function episode(string $idNumber, string $outcome): FollowUpChild
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
}
