<?php

namespace Tests\Feature;

use App\Filament\Resources\IndividualCounselingResource;
use App\Filament\Resources\IndividualCounselingResource\Pages\CreateIndividualCounseling;
use App\Filament\Resources\IndividualCounselingResource\Pages\EditIndividualCounseling;
use App\Models\IndividualCounseling;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndividualCounselingFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);

        return $user;
    }

    /**
     * A complete, valid set of form state.
     */
    private function validState(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-08-26',
            'health_educator' => IndividualCounselingResource::HEALTH_EDUCATOR,
            'shelter_name' => 'mahabba',
            'child_name' => 'طفل تجريبي',
            'child_visit_type' => 'new',
            'child_dob' => '2025-02-26',
            'gender' => 'F',
            'p_l' => 'P+L',
            'mother_name' => 'أم تجريبية',
            'mother_id_number' => '123456789',
            'mother_visit_type' => 'new',
            'mother_dob' => '1996-02-26',
            'mobile_number' => '0599123456',
            'muac' => 118,
            'child_age_lactated' => '6_23_months',
            'feeding_type' => 'رضاعة طبيعية',
            'iycf_form_filled' => 1,
            'status' => 'under_follow_up',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Structure
    // -----------------------------------------------------------------

    public function test_the_form_is_split_into_the_four_named_tabs(): void
    {
        $components = IndividualCounselingResource::form(Schema::make())->getComponents();

        $this->assertCount(1, $components);
        $this->assertInstanceOf(Tabs::class, $components[0]);

        app()->setLocale('ar');
        $arabic = array_map(fn ($tab): string => $tab->getLabel(), $components[0]->getDefaultChildComponents());

        $this->assertSame([
            'الزيارة والموقع',
            'بيانات الطفل والوالدين',
            'القياسات والتغذية',
            'الأسرة والحالات الخاصة',
        ], $arabic);

        app()->setLocale('en');
        $english = array_map(
            fn ($tab): string => $tab->getLabel(),
            IndividualCounselingResource::form(Schema::make())->getComponents()[0]->getDefaultChildComponents(),
        );

        $this->assertSame([
            'Visit & Location',
            'Child & Parent Data',
            'Measurements & Nutrition',
            'Family & Special Cases',
        ], $english);
    }

    public function test_the_create_form_renders_and_defaults_the_date_and_educator(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateIndividualCounseling::class)
            ->assertSuccessful()
            ->assertFormFieldExists('followups')
            ->assertFormSet([
                'date' => now()->format('Y-m-d'),
                'health_educator' => IndividualCounselingResource::HEALTH_EDUCATOR,
            ]);
    }

    // -----------------------------------------------------------------
    // Auto-calculated fields
    // -----------------------------------------------------------------

    public function test_age_in_months_and_years_calculate_live_from_the_dates_of_birth(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateIndividualCounseling::class)
            ->fillForm([
                'child_dob' => now()->subMonths(14)->format('Y-m-d'),
                'mother_dob' => now()->subYears(31)->format('Y-m-d'),
            ])
            ->assertFormSet([
                'age_months' => 14,
                'mother_age_years' => 31,
            ]);
    }

    public function test_muac_degree_updates_live_and_uses_the_115_and_125_boundaries(): void
    {
        $this->actingAsAdmin();

        // The degree is not an input: it renders as a colour-coded badge
        // derived from MUAC, so assert on the badge the form actually draws.
        $expected = [
            114 => ['SAM', 'danger'],
            115 => ['SAM', 'danger'],
            116 => ['MAM', 'warning'],
            124 => ['MAM', 'warning'],
            125 => ['Normal', 'success'],
            130 => ['Normal', 'success'],
        ];

        foreach ($expected as $muac => [$degree, $color]) {
            $html = Livewire::test(CreateIndividualCounseling::class)
                ->fillForm(['muac' => $muac])
                ->html();

            $this->assertSame(
                1,
                preg_match('/fi-badge[^>]*fi-color-([a-z]+)[^>]*>\s*([A-Za-z]+)\s*<\/span>/s', $html, $m),
                "No MUAC badge rendered for MUAC {$muac}.",
            );

            $this->assertSame([$degree, $color], [$m[2], $m[1]], "Wrong badge for MUAC {$muac}.");
        }
    }

    public function test_the_muac_classifier_is_the_single_source_of_truth(): void
    {
        $this->assertNull(IndividualCounseling::classifyMuac(null));
        $this->assertNull(IndividualCounseling::classifyMuac(''));
        $this->assertSame('SAM', IndividualCounseling::classifyMuac(115));
        $this->assertSame('MAM', IndividualCounseling::classifyMuac(124.9));
        $this->assertSame('Normal', IndividualCounseling::classifyMuac(125));
    }

    public function test_the_stored_degree_is_derived_and_never_taken_from_input(): void
    {
        $record = IndividualCounseling::create($this->validState([
            'muac' => 110,
            'muac_degree' => 'Normal',
        ]));

        $this->assertSame('SAM', $record->fresh()->muac_degree);
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    public function test_every_required_field_is_enforced(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateIndividualCounseling::class)
            ->fillForm([
                'date' => null,
                'child_name' => null,
                'child_visit_type' => null,
                'child_dob' => null,
                'gender' => null,
                'muac' => null,
                'child_age_lactated' => null,
                'feeding_type' => null,
                'p_l' => null,
                'mother_name' => null,
                'mother_id_number' => null,
                'mother_visit_type' => null,
                'mother_dob' => null,
                'mobile_number' => null,
                'iycf_form_filled' => null,
                'status' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'date' => 'required',
                'child_name' => 'required',
                'child_visit_type' => 'required',
                'child_dob' => 'required',
                'gender' => 'required',
                'muac' => 'required',
                'child_age_lactated' => 'required',
                'feeding_type' => 'required',
                'p_l' => 'required',
                'mother_name' => 'required',
                'mother_id_number' => 'required',
                'mother_visit_type' => 'required',
                'mother_dob' => 'required',
                'mobile_number' => 'required',
                'iycf_form_filled' => 'required',
                'status' => 'required',
            ]);

        $this->assertDatabaseCount('individual_counselings', 0);
    }

    #[DataProvider('badMobileNumbers')]
    public function test_the_mobile_number_must_be_exactly_ten_digits(string $mobile): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateIndividualCounseling::class)
            ->fillForm($this->validState(['mobile_number' => $mobile]))
            ->call('create')
            ->assertHasFormErrors(['mobile_number']);

        $this->assertDatabaseCount('individual_counselings', 0);
    }

    public static function badMobileNumbers(): array
    {
        return [
            'nine digits' => ['059912345'],
            'eleven digits' => ['05991234567'],
            'letters' => ['059912345a'],
            'spaced' => ['059 912345'],
            'dashed' => ['0599-12345'],
        ];
    }

    public function test_a_ten_digit_mobile_number_is_accepted_and_keeps_its_leading_zero(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateIndividualCounseling::class)
            ->fillForm($this->validState())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('0599123456', IndividualCounseling::first()->mobile_number);
    }

    public function test_the_validation_messages_are_localised(): void
    {
        app()->setLocale('en');
        $this->assertSame(
            'The Mobile number field must be exactly 10 digits.',
            __('fields.val_digits_10', ['field' => __('fields.mobile_number')]),
        );

        app()->setLocale('ar');
        $this->assertStringContainsString('10 أرقام', __('fields.val_digits_10', ['field' => __('fields.mobile_number')]));
    }

    // -----------------------------------------------------------------
    // Follow-up sessions repeater
    // -----------------------------------------------------------------

    public function test_a_record_saves_with_no_follow_up_sessions(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateIndividualCounseling::class)
            ->fillForm($this->validState())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('individual_counselings', 1);
        $this->assertDatabaseCount('individual_counseling_followups', 0);
    }

    public function test_follow_up_sessions_are_saved_as_related_rows(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateIndividualCounseling::class)
            ->fillForm($this->validState([
                'followups' => [
                    'a' => ['follow_up_visit_date' => '2026-09-01', 'assess_and_analyze' => 'تقييم أول', 'act' => 'إجراء أول'],
                    'b' => ['follow_up_visit_date' => '2026-09-15', 'assess_and_analyze' => 'تقييم ثانٍ', 'act' => 'إجراء ثانٍ'],
                ],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $record = IndividualCounseling::first();
        $followups = $record->followups;

        $this->assertCount(2, $followups);
        $this->assertSame('2026-09-01', $followups[0]->follow_up_visit_date->format('Y-m-d'));
        $this->assertSame('تقييم أول', $followups[0]->assess_and_analyze);
        $this->assertSame('2026-09-15', $followups[1]->follow_up_visit_date->format('Y-m-d'));
        $this->assertSame('إجراء ثانٍ', $followups[1]->act);
        // Filament's orderColumn numbers repeater rows from 1.
        $this->assertSame([1, 2], $followups->pluck('sort_order')->all());
    }

    public function test_a_follow_up_row_requires_its_visit_date(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateIndividualCounseling::class)
            ->fillForm($this->validState([
                'followups' => [
                    'a' => ['follow_up_visit_date' => null, 'assess_and_analyze' => 'بدون تاريخ', 'act' => null],
                ],
            ]))
            ->call('create')
            ->assertHasFormErrors(['followups.a.follow_up_visit_date' => 'required']);

        $this->assertDatabaseCount('individual_counselings', 0);
    }

    public function test_editing_loads_saved_follow_up_sessions_and_can_add_or_remove_them(): void
    {
        $this->actingAsAdmin();

        $record = IndividualCounseling::create($this->validState());
        $record->followups()->createMany([
            ['sort_order' => 0, 'follow_up_visit_date' => '2026-09-01', 'assess_and_analyze' => 'أولى', 'act' => 'إجراء'],
            ['sort_order' => 1, 'follow_up_visit_date' => '2026-09-15', 'assess_and_analyze' => 'ثانية', 'act' => 'إجراء'],
        ]);

        $page = Livewire::test(EditIndividualCounseling::class, ['record' => $record->getRouteKey()])
            ->assertSuccessful();

        // Previously saved sessions are loaded back into the repeater.
        $loaded = $page->get('data')['followups'];
        $this->assertCount(2, $loaded);
        $this->assertSame(['2026-09-01', '2026-09-15'], array_values(array_map(
            fn (array $row): string => $row['follow_up_visit_date'],
            $loaded,
        )));

        // Dropping the second row and adding a new one — set() replaces the
        // repeater state outright, which is what the delete action does.
        $keys = array_keys($loaded);
        $page->set('data.followups', [
            $keys[0] => $loaded[$keys[0]],
            'new' => ['follow_up_visit_date' => '2026-10-01', 'assess_and_analyze' => 'ثالثة', 'act' => 'إجراء'],
        ])->call('save')->assertHasNoFormErrors();

        $this->assertSame(
            ['2026-09-01', '2026-10-01'],
            $record->fresh()->followups->map(fn ($f): string => $f->follow_up_visit_date->format('Y-m-d'))->all(),
        );
    }

    public function test_deleting_a_record_removes_its_follow_up_sessions(): void
    {
        $record = IndividualCounseling::create($this->validState());
        $record->followups()->create(['sort_order' => 0, 'follow_up_visit_date' => '2026-09-01']);

        $record->forceDelete();

        $this->assertDatabaseCount('individual_counseling_followups', 0);
    }

    // -----------------------------------------------------------------
    // Widened option columns
    // -----------------------------------------------------------------

    public function test_the_widened_option_columns_accept_their_new_values(): void
    {
        $record = IndividualCounseling::create($this->validState([
            'p_l' => 'P+L',
            'consultation' => 'relactation',
            'pregnancy' => 'no',
            'lactating' => 'yes',
        ]))->fresh();

        $this->assertSame('P+L', $record->p_l);
        $this->assertSame('relactation', $record->consultation);
        $this->assertSame('no', $record->pregnancy);
        $this->assertSame('yes', $record->lactating);
    }
}
