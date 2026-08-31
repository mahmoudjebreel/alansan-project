<?php

namespace Tests\Feature;

use App\Filament\Resources\FollowUpChildResource\Pages\CreateFollowUpChild;
use App\Filament\Resources\GroupSessionResource;
use App\Filament\Resources\GroupSessionResource\Pages\CreateGroupSession;
use App\Filament\Resources\MotherToMotherResource;
use App\Filament\Resources\MotherToMotherResource\Pages\CreateMotherToMotherSession;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\MotherToMotherSession;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the QA batch: the category truncation fix (items 0/3/4), the
 * conditional newborn DOB (item 5), and the Follow Up Child age and
 * graduation date corrections (items 1/2).
 */
class SessionCategoryAndFollowUpQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
    }

    private function sessionState(array $overrides = []): array
    {
        return array_merge([
            'session_date' => '2026-08-01',
            'session_group_number' => '1',
            'session_subject' => 'bf_support',
            'locality' => 'tal_al_hawa',
            'shelter_name' => 'mahabba',
            'id_number' => '123456789',
            'full_name_ar' => 'مشاركة تجريبية',
            'visit_type' => 'new',
            'category' => 'grandmothers',
            'marital_status' => 'married',
            'phone_number' => '0599123456',
            'is_pwd' => false,
            'has_gsfsh' => false,
            'receives_supplementary' => false,
        ], $overrides);
    }

    public static function categoryProvider(): array
    {
        return array_map(
            fn (string $value): array => [$value],
            [
                'grandmothers',
                'reproductive_age',
                'male',
                'caregiver_child_under_6_months',
                'caregiver_child_6_23_months',
                'pregnant',
            ],
        );
    }

    // -----------------------------------------------------------------
    // Items 0 / 3 — every offered category actually saves
    // -----------------------------------------------------------------

    #[DataProvider('categoryProvider')]
    public function test_group_session_saves_every_offered_category(string $category): void
    {
        $state = $this->sessionState(['category' => $category]);

        if (in_array($category, GroupSessionResource::newbornDobCategories(), true)) {
            $state['newborn_dob'] = '2026-01-01';
        }

        Livewire::test(CreateGroupSession::class)
            ->fillForm($state)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($category, GroupSession::latest('id')->first()->category);
    }

    #[DataProvider('categoryProvider')]
    public function test_mother_to_mother_saves_every_offered_category(string $category): void
    {
        $state = $this->sessionState([
            'category' => $category,
            'locality' => 'mosaab_camp',
            'shelter_name' => 'مأوى',
        ]);
        unset($state['has_gsfsh']);

        if (in_array($category, MotherToMotherResource::newbornDobCategories(), true)) {
            $state['newborn_dob'] = '2026-01-01';
        }

        Livewire::test(CreateMotherToMotherSession::class)
            ->fillForm($state)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($category, MotherToMotherSession::latest('id')->first()->category);
    }

    /**
     * Guards against the drift that caused the original truncation error: an
     * option the form offers that the column cannot store.
     */
    public function test_every_offered_category_is_a_storable_key(): void
    {
        foreach ([GroupSessionResource::class, MotherToMotherResource::class] as $resource) {
            foreach (array_keys($resource::categoryOptions()) as $value) {
                $this->assertMatchesRegularExpression(
                    '/^[a-z0-9_]+$/',
                    $value,
                    "Category option [{$value}] on {$resource} is not a storable column value.",
                );
            }
        }
    }

    // -----------------------------------------------------------------
    // Item 4 — the retired "lactating" option
    // -----------------------------------------------------------------

    public function test_lactating_is_not_offered_but_an_old_record_still_renders_it(): void
    {
        foreach ([GroupSessionResource::class, MotherToMotherResource::class] as $resource) {
            $this->assertArrayNotHasKey('lactating', $resource::categoryOptions());
            $this->assertArrayNotHasKey('lactating', $resource::categoryOptionsFor(null));
        }

        $legacy = GroupSession::create($this->sessionState(['category' => 'lactating']));

        $this->assertArrayHasKey('lactating', GroupSessionResource::categoryOptionsFor($legacy));
        $this->assertSame('lactating', $legacy->fresh()->category);
    }

    // -----------------------------------------------------------------
    // Item 5 — conditional newborn DOB
    // -----------------------------------------------------------------

    #[DataProvider('categoryProvider')]
    public function test_newborn_dob_visibility_follows_the_category(string $category): void
    {
        $shouldShow = in_array($category, GroupSessionResource::newbornDobCategories(), true);

        $component = Livewire::test(CreateGroupSession::class)
            ->fillForm($this->sessionState(['category' => $category]));

        $shouldShow
            ? $component->assertFormFieldVisible('newborn_dob')
            : $component->assertFormFieldHidden('newborn_dob');
    }

    public function test_newborn_dob_is_required_when_visible(): void
    {
        Livewire::test(CreateGroupSession::class)
            ->fillForm($this->sessionState(['category' => 'pregnant', 'newborn_dob' => null]))
            ->call('create')
            ->assertHasFormErrors(['newborn_dob' => 'required']);
    }

    public function test_newborn_dob_is_not_stored_for_other_categories(): void
    {
        Livewire::test(CreateGroupSession::class)
            ->fillForm($this->sessionState(['category' => 'male']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(GroupSession::latest('id')->first()->newborn_dob);
    }

    // -----------------------------------------------------------------
    // Item 1 — age at admission derives from DOB
    // -----------------------------------------------------------------

    public function test_age_at_admission_is_six_months_for_the_stated_example(): void
    {
        $this->assertSame('6 شهر', FollowUpChild::formatAgeAtAdmission('2024-01-01', '2024-07-01'));
    }

    public function test_age_at_admission_counts_whole_months_and_days(): void
    {
        $this->assertSame('27 شهر و 14 يوم', FollowUpChild::formatAgeAtAdmission('2022-01-01', '2024-04-15'));
        $this->assertSame('0 يوم', FollowUpChild::formatAgeAtAdmission('2024-05-05', '2024-05-05'));
        $this->assertNull(FollowUpChild::formatAgeAtAdmission('2024-05-05', '2024-01-01'));
        $this->assertNull(FollowUpChild::formatAgeAtAdmission(null, '2024-01-01'));
    }

    public function test_editing_dob_recalculates_the_age_live(): void
    {
        Livewire::test(CreateFollowUpChild::class)
            ->fillForm([
                'dob' => '2024-01-01',
                'admission_date' => '2024-07-01',
            ])
            ->assertFormSet(['age_at_admission' => '6 شهر'])
            ->fillForm(['dob' => '2024-04-01'])
            ->assertFormSet(['age_at_admission' => '3 شهر']);
    }

    public function test_age_at_admission_never_exceeds_the_current_age(): void
    {
        $child = FollowUpChild::create([
            'id_number' => '123456789',
            'child_name' => 'طفل',
            'governorate' => 'gaza',
            'dob' => '2024-01-01',
            'admission_date' => '2024-07-01',
        ]);

        $this->assertSame('6 شهر', $child->age_at_admission);
        $this->assertSame(FollowUpChild::formatCurrentAge('2024-01-01'), $child->age);
    }

    // -----------------------------------------------------------------
    // Item 2 — graduation (discharge) date is a real date
    // -----------------------------------------------------------------

    public function test_discharge_date_is_cast_and_formatted_as_a_date(): void
    {
        $child = FollowUpChild::create([
            'id_number' => '123456789',
            'child_name' => 'طفل',
            'governorate' => 'gaza',
            'dob' => '2024-01-01',
            'admission_date' => '2024-07-01',
            'discharge_date' => '2025-03-09',
        ]);

        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $child->fresh()->discharge_date);
        $this->assertSame('2025-03-09', $child->fresh()->discharge_date->format('Y-m-d'));
    }

    public function test_discharge_date_is_a_date_picker_not_a_text_input(): void
    {
        Livewire::test(CreateFollowUpChild::class)
            ->assertFormFieldExists(
                'discharge_date',
                fn ($field): bool => $field instanceof \Filament\Forms\Components\DatePicker,
            );
    }
}
