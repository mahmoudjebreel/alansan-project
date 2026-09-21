<?php

namespace Tests\Feature;

use App\Filament\Resources\FollowUpChildResource\Pages\CreateFollowUpChild;
use App\Filament\Resources\FollowUpChildResource\Pages\EditFollowUpChild;
use App\Imports\ImportDefinition;
use App\Models\FollowUpChild;
use App\Models\User;
use App\Services\ExcelImportService;
use App\Support\FollowUpDischargeRule;
use App\Support\ImportSchema;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * C4 - a closed follow-up case must say when it closed.
 *
 * Every outcome in FollowUpChild::CLOSING_OUTCOMES ends the episode and needs a
 * discharge date. "Under follow-up" is the one outcome that does not, because
 * it is the outcome an open case carries: it has not been discharged, so there
 * is no date to give.
 *
 * Nothing is filled in on anybody's behalf. A row that closes a case without
 * saying when is refused and reported - today's date would put the discharge in
 * the wrong month of every report that counts it, and nobody reading the report
 * could see that it was invented.
 *
 * The same two rules have to hold on the create form, on the edit form and on
 * an uploaded sheet, which is why they live in one class and are tested through
 * all three doors here.
 */
class FollowUpDischargeDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        \Storage::fake('local');

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    // -----------------------------------------------------------------
    // The rule itself
    // -----------------------------------------------------------------

    /**
     * Every outcome that closes a case. Read off the model's own constant, so
     * an outcome added later cannot quietly escape the rule.
     *
     * @return array<string, array{0: string}>
     */
    public static function closingOutcomes(): array
    {
        return collect(FollowUpChild::CLOSING_OUTCOMES)
            ->mapWithKeys(fn (string $outcome): array => [$outcome => [$outcome]])
            ->all();
    }

    /**
     * @dataProvider closingOutcomes
     */
    public function test_every_closing_outcome_requires_a_discharge_date(string $outcome): void
    {
        $this->assertTrue(FollowUpDischargeRule::closes($outcome));

        $messages = FollowUpDischargeRule::violations($outcome, null, '2026-06-01');

        $this->assertCount(1, $messages, "[{$outcome}] must require a discharge date.");
        $this->assertStringContainsString(__('fields.' . $outcome), $messages[0]);
    }

    /**
     * The list is the requirement's list, spelled out rather than derived, so a
     * change to the constant has to be a deliberate one.
     */
    public function test_the_closing_outcomes_are_the_seven_the_requirement_names(): void
    {
        $this->assertEqualsCanonicalizing([
            'cured',
            'defaulted',
            'discharge_to_opt',
            'discharge_to_other',
            'non_responded',
            'referred_medical_inpt',
            'died',
        ], FollowUpChild::CLOSING_OUTCOMES);

        $this->assertNotContains(FollowUpChild::ACTIVE_OUTCOME, FollowUpChild::CLOSING_OUTCOMES);
    }

    public function test_under_follow_up_may_have_no_discharge_date(): void
    {
        $this->assertFalse(FollowUpDischargeRule::closes(FollowUpChild::ACTIVE_OUTCOME));

        $this->assertSame([], FollowUpDischargeRule::violations(
            FollowUpChild::ACTIVE_OUTCOME,
            null,
            '2026-06-01',
        ));
    }

    public function test_a_blank_outcome_needs_no_discharge_date(): void
    {
        $this->assertSame([], FollowUpDischargeRule::violations(null, null, '2026-06-01'));
    }

    public function test_a_closing_outcome_with_a_date_is_accepted(): void
    {
        $this->assertSame([], FollowUpDischargeRule::violations('cured', '2026-08-20', '2026-06-01'));
    }

    public function test_a_discharge_before_its_admission_is_refused(): void
    {
        $messages = FollowUpDischargeRule::violations('cured', '2026-08-10', '2026-08-20');

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('2026-08-10', $messages[0]);
        $this->assertStringContainsString('2026-08-20', $messages[0]);
    }

    public function test_a_discharge_on_the_admission_day_is_accepted(): void
    {
        $this->assertSame([], FollowUpDischargeRule::violations('cured', '2026-08-20', '2026-08-20'));
    }

    /**
     * The order rule holds for an open case too: "under follow-up" excuses a
     * missing date, not an impossible one.
     */
    public function test_the_order_rule_holds_whatever_the_outcome(): void
    {
        $messages = FollowUpDischargeRule::violations(
            FollowUpChild::ACTIVE_OUTCOME,
            '2026-08-10',
            '2026-08-20',
        );

        $this->assertCount(1, $messages);
    }

    // -----------------------------------------------------------------
    // The Excel import
    // -----------------------------------------------------------------

    private function headings(): array
    {
        return (new ImportSchema(ImportDefinition::get('follow_up_children')))->headings();
    }

    /**
     * @param  array<string, mixed>  $cells
     * @return array{imported: int, errors: array, skipped: array}
     */
    private function import(array $cells): array
    {
        $headings = $this->headings();
        $row = array_fill(0, count($headings), null);

        $cells = array_merge([
            __('fields.id_number') => '123456789',
            __('fields.child_name') => 'طفل المتابعة',
            __('fields.admission_date') => '2026-06-01',
        ], $cells);

        foreach ($cells as $heading => $value) {
            $index = array_search($heading, $headings, true);
            $this->assertNotFalse($index, "Heading [{$heading}] is not in the template.");
            $row[$index] = $value;
        }

        $export = new class([$headings, $row]) implements FromArray
        {
            public function __construct(private array $rows)
            {
            }

            public function array(): array
            {
                return $this->rows;
            }
        };

        $name = 'discharge-' . uniqid() . '.xlsx';
        Excel::store($export, $name, 'local');

        return app(ExcelImportService::class)->import(
            ImportDefinition::get('follow_up_children'),
            \Storage::disk('local')->path($name),
        );
    }

    /**
     * @dataProvider closingOutcomes
     */
    public function test_an_uploaded_row_that_closes_a_case_without_a_date_is_refused(string $outcome): void
    {
        $result = $this->import([__('fields.discharge_outcome') => __('fields.' . $outcome)]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString(__('fields.' . $outcome), $result['errors'][0]);
        $this->assertSame(0, FollowUpChild::count());
    }

    public function test_an_uploaded_row_under_follow_up_needs_no_discharge_date(): void
    {
        $result = $this->import([
            __('fields.discharge_outcome') => __('fields.' . FollowUpChild::ACTIVE_OUTCOME),
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertNull(FollowUpChild::first()->discharge_date);
    }

    public function test_an_uploaded_row_that_closes_a_case_with_a_date_is_accepted(): void
    {
        $result = $this->import([
            __('fields.discharge_outcome') => __('fields.cured'),
            __('fields.discharge_date') => '2026-08-20',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame('2026-08-20', FollowUpChild::first()->discharge_date->format('Y-m-d'));
    }

    public function test_an_uploaded_discharge_before_its_admission_is_refused(): void
    {
        $result = $this->import([
            __('fields.admission_date') => '20/08/2026',
            __('fields.discharge_outcome') => __('fields.cured'),
            __('fields.discharge_date') => '10/08/2026',
        ]);

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('2026-08-10', $result['errors'][0]);
        $this->assertSame(0, FollowUpChild::count());
    }

    /**
     * Nothing is filled in for a row that says nothing. A refused row must not
     * come back with today's date on it, which is the failure this whole rule
     * exists to prevent.
     */
    public function test_a_missing_discharge_date_is_never_filled_with_today(): void
    {
        $this->import([__('fields.discharge_outcome') => __('fields.cured')]);

        $this->assertSame(0, FollowUpChild::count());
        $this->assertSame(0, FollowUpChild::withTrashed()->count());
    }

    // -----------------------------------------------------------------
    // The manual forms
    // -----------------------------------------------------------------

    /**
     * There is no manual Create page for this module: the resource answers
     * canCreate() with false, and an episode is opened by the referral and
     * readmission paths rather than typed from scratch. So the manual door
     * these rules have to hold at is the Edit form, and that is where they are
     * exercised below.
     */
    public function test_the_module_has_no_manual_create_page(): void
    {
        $this->assertFalse(\App\Filament\Resources\FollowUpChildResource::canCreate());
    }

    private function openEpisode(): FollowUpChild
    {
        // Every column the Edit form requires, so a save that is refused is
        // refused over the discharge date and nothing else.
        $record = FollowUpChild::factory()->create([
            'id_number' => '987654321',
            'child_name' => 'طفل المتابعة',
            'sex' => 'M',
            'dob' => '2025-01-01',
            'mobile_number' => '0599123456',
            'shelter_name' => 'مخيم مصعب',
            'governorate' => 'gaza',
            'causes_of_admission' => 'malnutrition',
            'admitted_with' => 'SAM',
            'admission_date' => '2026-06-01',
            'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
            'discharge_date' => null,
        ]);

        $record->visits()->create([
            'visit_number' => 1,
            'visit_date' => '2026-06-01',
            'muac' => 110,
        ]);

        return $record;
    }

    public function test_the_edit_form_refuses_a_closed_case_with_no_discharge_date(): void
    {
        $record = $this->openEpisode();

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm([
                'discharge_outcome' => 'cured',
                'discharge_date' => null,
            ])
            ->call('save')
            ->assertHasFormErrors(['discharge_date']);

        $this->assertSame(FollowUpChild::ACTIVE_OUTCOME, $record->fresh()->discharge_outcome);
    }

    public function test_the_edit_form_accepts_an_open_case_with_no_discharge_date(): void
    {
        $record = $this->openEpisode();

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm([
                'discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME,
                'discharge_date' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($record->fresh()->discharge_date);
    }

    public function test_the_edit_form_refuses_a_discharge_before_its_admission(): void
    {
        $record = $this->openEpisode();

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm([
                'admission_date' => '2026-08-20',
                'discharge_outcome' => 'cured',
                'discharge_date' => '2026-08-10',
            ])
            ->call('save')
            ->assertHasFormErrors(['discharge_date']);

        $this->assertNull($record->fresh()->discharge_date);
    }

    public function test_the_edit_form_accepts_a_discharge_after_its_admission(): void
    {
        $record = $this->openEpisode();

        Livewire::test(EditFollowUpChild::class, ['record' => $record->getKey()])
            ->fillForm([
                'discharge_outcome' => 'cured',
                'discharge_date' => '2026-08-20',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-08-20', $record->fresh()->discharge_date->format('Y-m-d'));
    }
}
