<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource\Pages\CreateChild;
use App\Filament\Resources\ChildResource\Pages\EditChild;
use App\Filament\Resources\FollowUpChildResource\Pages\CreateFollowUpChild;
use App\Filament\Resources\GroupSessionResource\Pages\CreateGroupSession;
use App\Filament\Resources\IndividualCounselingResource\Pages\CreateIndividualCounseling;
use App\Filament\Resources\MotherToMotherResource\Pages\CreateMotherToMotherSession;
use App\Filament\Resources\PregnantLactatingWomanResource\Pages\CreatePregnantLactatingWoman;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\GroupSession;
use App\Models\IndividualCounseling;
use App\Models\MotherToMotherSession;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use App\Support\Forms\BooleanSelectField;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminPermissionsSeeder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every Yes/No field of the six data modules must render as one and the same
 * "نعم / لا" dropdown, and must keep storing plain 0/1.
 */
class BooleanSelectFieldTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create page => model, for each of the six modules.
     *
     * @var array<class-string, class-string>
     */
    private const MODULES = [
        CreateChild::class => Child::class,
        CreatePregnantLactatingWoman::class => PregnantLactatingWoman::class,
        CreateGroupSession::class => GroupSession::class,
        CreateMotherToMotherSession::class => MotherToMotherSession::class,
        CreateIndividualCounseling::class => IndividualCounseling::class,
        CreateFollowUpChild::class => FollowUpChild::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // The Filament resources gate on Spatie permissions directly, so the
        // Super Admin role has to actually hold them.
        $this->seed(SuperAdminPermissionsSeeder::class);
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, \Filament\Forms\Components\Field>
     */
    private function fieldsOf(string $page): array
    {
        return Livewire::test($page)
            ->assertSuccessful()
            ->instance()
            ->form
            ->getFlatFields(withHidden: true);
    }

    /**
     * @return array<string>
     */
    private function booleanColumnsOf(string $model): array
    {
        return array_keys(array_filter(
            (new $model)->getCasts(),
            fn (string $cast): bool => $cast === 'boolean',
        ));
    }

    public function test_no_toggle_is_left_in_any_of_the_six_module_forms(): void
    {
        $this->actingAsSuperAdmin();

        foreach (array_keys(self::MODULES) as $page) {
            foreach ($this->fieldsOf($page) as $name => $field) {
                $this->assertNotInstanceOf(
                    Toggle::class,
                    $field,
                    "{$page} still renders \"{$name}\" as a toggle switch.",
                );
            }
        }
    }

    public function test_every_boolean_column_renders_as_the_unified_yes_no_select(): void
    {
        $this->actingAsSuperAdmin();

        $asserted = 0;

        foreach (self::MODULES as $page => $model) {
            $fields = $this->fieldsOf($page);

            foreach ($this->booleanColumnsOf($model) as $column) {
                // Not every boolean column is exposed on the form; only the
                // ones that are have to follow the shared shape.
                if (! isset($fields[$column])) {
                    continue;
                }

                $field = $fields[$column];

                $this->assertInstanceOf(
                    Select::class,
                    $field,
                    "{$page}: \"{$column}\" is not a select.",
                );
                $this->assertSame(
                    [1 => 'نعم', 0 => 'لا'],
                    $field->getOptions(),
                    "{$page}: \"{$column}\" does not offer the shared نعم/لا options.",
                );

                $asserted++;
            }
        }

        // Guards against the loop silently asserting nothing at all.
        $this->assertSame(24, $asserted, 'Unexpected number of Yes/No fields.');
    }

    public function test_the_shared_field_converts_between_boolean_state_and_zero_or_one(): void
    {
        $casts = BooleanSelectField::make('is_pwd')->getStateCasts();

        $this->assertNotEmpty($casts);

        foreach ($casts as $cast) {
            // Model value -> form state: what the dropdown is rendered with.
            $this->assertSame(1, $cast->set(true));
            $this->assertSame(0, $cast->set(false));

            // Form state -> model value: what is written to the column.
            $this->assertTrue($cast->get(1));
            $this->assertFalse($cast->get(0));
        }
    }

    public function test_a_child_is_created_and_re_edited_through_the_yes_no_selects(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateChild::class)
            ->fillForm($this->validChildState())
            ->call('create')
            ->assertHasNoFormErrors();

        $child = Child::where('child_id', '123456789')->firstOrFail();

        $this->assertDatabaseHas('children', [
            'id' => $child->id,
            'is_pwd' => 1,
            'is_displaced' => 0,
            'is_mother_alive' => 1,
        ]);

        // Re-opening the record must preselect the stored answer — including
        // the "لا" side, which a boolean-backed select is easy to get wrong.
        Livewire::test(EditChild::class, ['record' => $child->getRouteKey()])
            ->assertFormSet([
                'is_pwd' => 1,
                'is_displaced' => 0,
                'is_mother_alive' => 1,
            ])
            ->fillForm(['is_pwd' => 0, 'is_displaced' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('children', [
            'id' => $child->id,
            'is_pwd' => 0,
            'is_displaced' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validChildState(): array
    {
        return [
            'visit_type' => 'new',
            'child_id' => '123456789',
            'name' => 'طفل تجريبي',
            'phone_number' => '5991234567',
            'date_of_reporting' => now()->format('Y-m-d'),
            'sex' => 'male',
            'date_of_birth' => now()->subMonths(18)->format('Y-m-d'),
            'muac_mm' => 130,
            'governorate' => 'gaza',
            'municipality' => 'gaza',
            'type_of_site' => 'Mahabba Camp',
            'mother_marital_status' => 'متزوجة',
            'is_pwd' => 1,
            'is_displaced' => 0,
            'is_mother_alive' => 1,
        ];
    }
}
