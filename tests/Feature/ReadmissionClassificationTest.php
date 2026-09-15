<?php

namespace Tests\Feature;

use App\Filament\Pages\ReferralCenter;
use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Filament\Resources\FollowUpChildResource\Pages\ViewFollowUpChild;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Models\User;
use App\Support\ChildFollowUpTransfer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The three readmission classifications, decided from the child's history
 * and never picked by hand.
 *
 *   after defaulted   the latest closed episode closed as defaulted
 *   after other       it closed by an eligible other exit
 *   after relapse     it was a SAM/MAM episode closed as cured
 *
 * A return after anything else - non-responded, died, cured with no SAM/MAM
 * classification - is a plain new admission with nothing to follow on from.
 * The classification is carried by the link previous_follow_up_child_id on
 * the new episode; the closed episode it points at is never written.
 */
class ReadmissionClassificationTest extends TestCase
{
    use RefreshDatabase;

    private const CHILD_ID = '470828468';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * A finished SAM episode: admitted 2026-08-19, two visits, closed
     * 2026-08-26 with the outcome given.
     */
    private function closedEpisode(string $outcome, array $attributes = []): FollowUpChild
    {
        $record = FollowUpChild::factory()->create(array_merge([
            'id_number' => self::CHILD_ID,
            'child_name' => 'محمد عبد الكريم خليل عليوة',
            'sex' => 'M',
            'dob' => '2025-01-15',
            'mobile_number' => '0591111111',
            'shelter_name' => 'مركز الإيواء أ',
            'governorate' => 'gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_type' => FollowUpChild::ADMISSION_NEW,
            'admission_date' => '2026-08-19',
            'discharge_date' => '2026-08-26',
            'discharge_outcome' => $outcome,
        ], $attributes));

        $muac = $record->admitted_with === 'MAM' ? 118 : 110;
        $record->visits()->create(['visit_number' => 1, 'visit_date' => '2026-08-19', 'muac' => $muac]);
        $record->visits()->create(['visit_number' => 2, 'visit_date' => '2026-08-26', 'muac' => $muac + 2]);

        return $record->fresh();
    }

    /**
     * The same child screened again at the programme, on 2026-09-09.
     */
    private function screening(int $muac, string $childId = self::CHILD_ID): Child
    {
        return Child::factory()->create([
            'child_id' => $childId,
            'name' => 'محمد عبد الكريم خليل عليوة',
            'sex' => 'male',
            'date_of_birth' => '2025-01-15',
            'muac_mm' => $muac,
            'date_of_reporting' => '2026-09-09',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readmissionData(int $muac = 110): array
    {
        return ['admission_date' => '2026-09-09', 'visit_date' => '2026-09-09', 'muac' => $muac];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(FollowUpChild $record): array
    {
        $record = $record->fresh();

        return [
            'record' => $record->getAttributes(),
            'visits' => $record->visits()->get()->map(fn (FollowUpChildVisit $visit): array => $visit->getAttributes())->all(),
        ];
    }

    private function assertLinkedReadmission(FollowUpChild $new, FollowUpChild $previous, string $classification): void
    {
        $this->assertTrue($new->isReadmission(), 'A return after a default or an other exit is a readmission.');
        $this->assertSame($previous->getKey(), $new->previous_follow_up_child_id);
        $this->assertSame($classification, $new->readmissionClassification());
        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $new->discharge_outcome);
        $this->assertSame([1], $new->visits->pluck('visit_number')->all());
    }

    // =================================================================
    // 1. After defaulted
    // =================================================================

    public function test_a_return_after_defaulted_is_a_readmission_after_defaulted(): void
    {
        $previous = $this->closedEpisode('defaulted');
        $before = $this->snapshot($previous);

        $this->assertSame(FollowUpChild::READMISSION_AFTER_DEFAULTED, $previous->classifiesReturnAs());
        $this->assertSame(FollowUpChild::READMISSION_AFTER_DEFAULTED, FollowUpChild::readmissionClassificationFor(self::CHILD_ID));

        // From the follow-up record itself.
        $new = ChildFollowUpTransfer::readmitFromEpisode($previous, $this->readmissionData());

        $this->assertNotNull($new);
        $this->assertLinkedReadmission($new, $previous, FollowUpChild::READMISSION_AFTER_DEFAULTED);
        $this->assertSame($before, $this->snapshot($previous));
    }

    public function test_a_screening_after_defaulted_is_a_readmission_after_defaulted_from_the_children_form(): void
    {
        $previous = $this->closedEpisode('defaulted');
        $before = $this->snapshot($previous);

        $new = ChildFollowUpTransfer::refer($this->screening(110));

        $this->assertNotNull($new);
        $this->assertLinkedReadmission($new, $previous, FollowUpChild::READMISSION_AFTER_DEFAULTED);
        $this->assertSame($before, $this->snapshot($previous));
    }

    // =================================================================
    // 2. After other
    // =================================================================

    public function test_a_return_after_each_eligible_other_exit_is_a_readmission_after_other(): void
    {
        foreach (['discharge_to_opt', 'discharge_to_other', 'referred_medical_inpt'] as $index => $outcome) {
            $idNumber = (string) (480000000 + $index);
            $previous = $this->closedEpisode($outcome, ['id_number' => $idNumber]);
            $before = $this->snapshot($previous);

            $this->assertSame(FollowUpChild::READMISSION_AFTER_OTHER, $previous->classifiesReturnAs(), "[{$outcome}]");
            $this->assertSame(FollowUpChild::READMISSION_AFTER_OTHER, FollowUpChild::readmissionClassificationFor($idNumber));

            $new = ChildFollowUpTransfer::readmitFromEpisode($previous, $this->readmissionData());

            $this->assertNotNull($new, "[{$outcome}]");
            $this->assertLinkedReadmission($new, $previous, FollowUpChild::READMISSION_AFTER_OTHER);
            $this->assertSame($before, $this->snapshot($previous));
        }
    }

    // =================================================================
    // 3-4. After relapse: cured SAM/MAM, then SAM/MAM again
    // =================================================================

    public function test_a_sam_child_cured_and_screened_sam_again_is_a_relapse(): void
    {
        $this->assertRelapse('SAM', 110);
    }

    public function test_a_mam_child_cured_and_screened_mam_again_is_a_relapse(): void
    {
        $this->assertRelapse('MAM', 118);
    }

    private function assertRelapse(string $programme, int $muac): void
    {
        $cured = $this->closedEpisode('cured', ['admitted_with' => $programme]);
        $before = $this->snapshot($cured);

        $this->assertSame(FollowUpChild::READMISSION_AFTER_RELAPSE, $cured->classifiesReturnAs());
        $this->assertSame(FollowUpChild::READMISSION_AFTER_RELAPSE, FollowUpChild::readmissionClassificationFor(self::CHILD_ID));

        // Cured is not a readmission: no button, and the transfer behind
        // the button refuses. The relapse is raised from the screening.
        $this->assertFalse($cured->canBeReadmitted());
        $this->assertNull(ChildFollowUpTransfer::readmitFromEpisode($cured, $this->readmissionData($muac)));

        Livewire::test(ViewFollowUpChild::class, ['record' => $cured->getKey()])
            ->assertActionHidden('readmission');

        $new = ChildFollowUpTransfer::refer($this->screening($muac));

        $this->assertNotNull($new);
        $this->assertFalse($new->isReadmission(), 'A relapse is a new admission, not a readmission.');
        $this->assertSame(FollowUpChild::ADMISSION_NEW, $new->admissionType());
        $this->assertSame($programme, $new->admitted_with);
        $this->assertSame($cured->getKey(), $new->previous_follow_up_child_id);
        $this->assertSame(FollowUpChild::READMISSION_AFTER_RELAPSE, $new->readmissionClassification());
        $this->assertSame([1], $new->visits->pluck('visit_number')->all());
        $this->assertSame($before, $this->snapshot($cured));
    }

    // =================================================================
    // 5-9. Not a relapse
    // =================================================================

    public function test_a_cured_episode_with_no_sam_mam_classification_is_not_a_relapse(): void
    {
        // A Normal readmission closed as cured was admitted with neither.
        $cured = $this->closedEpisode('cured', ['admitted_with' => null]);

        $this->assertNull($cured->classifiesReturnAs());
        $this->assertNull(FollowUpChild::readmissionClassificationFor(self::CHILD_ID));

        $new = ChildFollowUpTransfer::refer($this->screening(110));

        $this->assertNotNull($new);
        $this->assertFalse($new->isReadmission());
        $this->assertNull($new->previous_follow_up_child_id);
        $this->assertNull($new->readmissionClassification());
    }

    public function test_a_defaulted_history_is_never_a_relapse(): void
    {
        $this->closedEpisode('defaulted');

        $new = ChildFollowUpTransfer::refer($this->screening(110));

        $this->assertSame(FollowUpChild::READMISSION_AFTER_DEFAULTED, $new->readmissionClassification());
        $this->assertNotSame(FollowUpChild::READMISSION_AFTER_RELAPSE, $new->readmissionClassification());
    }

    public function test_an_other_history_is_never_a_relapse(): void
    {
        $this->closedEpisode('referred_medical_inpt');

        $new = ChildFollowUpTransfer::refer($this->screening(110));

        $this->assertSame(FollowUpChild::READMISSION_AFTER_OTHER, $new->readmissionClassification());
        $this->assertNotSame(FollowUpChild::READMISSION_AFTER_RELAPSE, $new->readmissionClassification());
    }

    public function test_a_return_after_non_responded_is_a_new_admission(): void
    {
        $previous = $this->closedEpisode('non_responded');
        $before = $this->snapshot($previous);

        $this->assertNull($previous->classifiesReturnAs());
        $this->assertNull(FollowUpChild::readmissionClassificationFor(self::CHILD_ID));
        $this->assertFalse($previous->canBeReadmitted());
        $this->assertNull(ChildFollowUpTransfer::readmitFromEpisode($previous, $this->readmissionData()));

        $new = ChildFollowUpTransfer::refer($this->screening(110));

        $this->assertNotNull($new);
        $this->assertFalse($new->isReadmission());
        $this->assertSame(FollowUpChild::ADMISSION_NEW, $new->admissionType());
        $this->assertNull($new->previous_follow_up_child_id);
        $this->assertNull($new->readmissionClassification());
        $this->assertSame($before, $this->snapshot($previous));
    }

    public function test_a_record_after_died_is_never_a_readmission(): void
    {
        $previous = $this->closedEpisode('died');

        $this->assertNull($previous->classifiesReturnAs());
        $this->assertNull(FollowUpChild::readmissionClassificationFor(self::CHILD_ID));
        $this->assertFalse($previous->canBeReadmitted());
        $this->assertNull(ChildFollowUpTransfer::readmitFromEpisode($previous, $this->readmissionData()));
        $this->assertNull(ChildFollowUpTransfer::readmit($this->screening(110)));

        Livewire::test(ViewFollowUpChild::class, ['record' => $previous->getKey()])
            ->assertActionHidden('readmission');

        // Whatever opens after it is not a readmission and follows nothing.
        $new = ChildFollowUpTransfer::refer(Child::where('child_id', self::CHILD_ID)->sole());

        $this->assertNotNull($new);
        $this->assertFalse($new->isReadmission());
        $this->assertNull($new->previous_follow_up_child_id);
        $this->assertNull($new->readmissionClassification());
    }

    // =================================================================
    // The latest closed episode decides, and an open one blocks everything
    // =================================================================

    public function test_the_latest_closed_episode_decides_the_classification(): void
    {
        // Cured, then defaulted: the child last left as a defaulter.
        $this->closedEpisode('cured', ['admission_date' => '2026-06-01', 'discharge_date' => '2026-06-29']);
        $defaulted = $this->closedEpisode('defaulted');

        $this->assertTrue($defaulted->is(FollowUpChild::classifyingEpisodeFor(self::CHILD_ID)));
        $this->assertSame(FollowUpChild::READMISSION_AFTER_DEFAULTED, FollowUpChild::readmissionClassificationFor(self::CHILD_ID));

        // Defaulted, then cured: the child last left cured.
        $this->closedEpisode('defaulted', ['id_number' => '480000010', 'admission_date' => '2026-06-01', 'discharge_date' => '2026-06-29']);
        $cured = $this->closedEpisode('cured', ['id_number' => '480000010']);

        $this->assertTrue($cured->is(FollowUpChild::classifyingEpisodeFor('480000010')));
        $this->assertSame(FollowUpChild::READMISSION_AFTER_RELAPSE, FollowUpChild::readmissionClassificationFor('480000010'));
    }

    public function test_an_open_episode_blocks_any_classification(): void
    {
        $this->closedEpisode('defaulted');
        $this->closedEpisode(FollowUpChild::ACTIVE_OUTCOME, ['discharge_date' => null, 'admission_date' => '2026-09-01']);

        $this->assertNull(FollowUpChild::classifyingEpisodeFor(self::CHILD_ID));
        $this->assertNull(FollowUpChild::readmissionClassificationFor(self::CHILD_ID));
        $this->assertNull(ChildFollowUpTransfer::refer($this->screening(110)));
        $this->assertSame(2, FollowUpChild::where('id_number', self::CHILD_ID)->count());
    }

    public function test_two_episodes_of_the_same_child_stay_independent(): void
    {
        $first = $this->closedEpisode('defaulted');
        $firstBefore = $this->snapshot($first);

        $second = ChildFollowUpTransfer::readmitFromEpisode($first, $this->readmissionData());
        $this->assertLinkedReadmission($second, $first, FollowUpChild::READMISSION_AFTER_DEFAULTED);

        // The second episode runs its own course and closes as cured.
        $second->visits()->create(['visit_number' => 2, 'visit_date' => '2026-09-16', 'muac' => 126]);
        $second->update(['discharge_outcome' => FollowUpChild::CURED_OUTCOME, 'discharge_date' => '2026-09-16']);
        $secondBefore = $this->snapshot($second);

        $this->assertSame($firstBefore, $this->snapshot($first));

        // A third return follows the second episode - a relapse - and
        // neither earlier episode moves.
        $third = ChildFollowUpTransfer::refer($this->screening(110));

        $this->assertNotNull($third);
        $this->assertSame($second->getKey(), $third->previous_follow_up_child_id);
        $this->assertSame(FollowUpChild::READMISSION_AFTER_RELAPSE, $third->readmissionClassification());
        $this->assertSame($firstBefore, $this->snapshot($first));
        $this->assertSame($secondBefore, $this->snapshot($second));
        $this->assertSame(3, FollowUpChild::where('id_number', self::CHILD_ID)->count());
    }

    // =================================================================
    // What the person is told: the dialogs say the classification and why
    // =================================================================

    public function test_the_children_form_announces_the_classification_and_its_reason(): void
    {
        $cases = [
            ['defaulted', '480000020', __('fields.readmission_after_defaulted'), __('ui.readmission.reasons.defaulted')],
            ['discharge_to_other', '480000021', __('fields.readmission_after_other'), __('ui.readmission.reasons.other')],
            ['cured', '480000022', __('fields.readmission_after_relapse'), __('ui.readmission.reasons.relapse')],
            ['non_responded', '480000023', null, null],
        ];

        foreach ($cases as [$outcome, $idNumber, $classification, $reason]) {
            $this->closedEpisode($outcome, ['id_number' => $idNumber]);

            Livewire::test(CreateChild::class)
                ->set('data.child_id', $idNumber)
                ->assertDispatched('follow-up-history-known', function (string $event, array $params) use ($classification, $reason, $outcome): bool {
                    $history = $params[0];

                    $this->assertSame('closed', $history['state'], "[{$outcome}]");
                    $this->assertSame($classification, $history['classification'], "[{$outcome}]");
                    $this->assertSame($reason, $history['reason'], "[{$outcome}]");

                    return true;
                });
        }
    }

    public function test_the_readmission_dialog_says_the_classification_and_its_reason(): void
    {
        $cases = [
            ['discharge_to_opt', '480000030', __('fields.readmission_after_other'), __('ui.readmission.reasons.other')],
            ['defaulted', '480000031', __('fields.readmission_after_defaulted'), __('ui.readmission.reasons.defaulted')],
        ];

        foreach ($cases as [$outcome, $idNumber, $classification, $reason]) {
            $previous = $this->closedEpisode($outcome, ['id_number' => $idNumber]);

            // The modal is not part of the page's rendered HTML in a test, so
            // its lines are read off the mounted action's own schema.
            $component = Livewire::test(ViewFollowUpChild::class, ['record' => $previous->getKey()])
                ->mountAction('readmission')
                ->assertActionMounted('readmission');

            $livewire = $component->instance();
            $schema = $livewire->getMountedAction()->getSchema(Schema::make($livewire)->record($previous));

            $lines = collect($schema->getFlatComponents(withHidden: true))
                ->filter(fn ($component): bool => $component instanceof Text)
                ->map(fn (Text $text): string => (string) $text->getContent())
                ->implode("\n");

            $this->assertStringContainsString($classification, $lines, "[{$outcome}]");
            $this->assertStringContainsString($reason, $lines, "[{$outcome}]");

            // The Referral Centre's dialog reads the same closed episode.
            $description = ReferralCenter::readmissionDescription($this->screening(110, $idNumber));

            $this->assertStringContainsString($classification, $description, "[{$outcome}]");
            $this->assertStringContainsString($reason, $description, "[{$outcome}]");
        }
    }
}
