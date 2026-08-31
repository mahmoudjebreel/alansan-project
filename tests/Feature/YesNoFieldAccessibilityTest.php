<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource\Pages\CreateChild;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Item 6, accessibility half: the shared Yes/No field is rendered with
 * ->native(false), which produces an ARIA combobox instead of a labelable
 * <select>. The field wrapper's <label for> therefore cannot name it, so each
 * one has to carry its own accessible name.
 *
 * BooleanSelectFieldTest already covers the shape and the stored value; this
 * covers only the name.
 */
class YesNoFieldAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<class-string, class-string> */
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

        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
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

    public function test_every_yes_no_field_carries_its_own_accessible_name(): void
    {
        $asserted = 0;

        foreach (self::MODULES as $page => $model) {
            $fields = $this->fieldsOf($page);

            foreach ($this->booleanColumnsOf($model) as $column) {
                if (! isset($fields[$column])) {
                    continue;
                }

                $field = $fields[$column];

                $this->assertSame(
                    $field->getLabel(),
                    $field->getExtraInputAttributes()['aria-label'] ?? null,
                    "{$page}: \"{$column}\" is not announced with its own label.",
                );

                $asserted++;
            }
        }

        $this->assertSame(24, $asserted, 'Unexpected number of Yes/No fields.');
    }

    /**
     * Item 7: the Mother to Mother module specifically — its Yes/No field uses
     * the same shared component, and its category field takes the values the
     * item 0 fix made storable.
     */
    public function test_mother_to_mother_uses_the_shared_field_and_the_fixed_category(): void
    {
        $fields = $this->fieldsOf(CreateMotherToMotherSession::class);

        $this->assertArrayHasKey('is_pwd', $fields);
        $this->assertSame(
            [1 => __('fields.yes'), 0 => __('fields.no')],
            $fields['is_pwd']->getOptions(),
        );
        $this->assertSame(
            $fields['is_pwd']->getLabel(),
            $fields['is_pwd']->getExtraInputAttributes()['aria-label'] ?? null,
        );

        $this->assertSame(
            array_keys(\App\Filament\Resources\MotherToMotherResource::categoryOptions()),
            array_keys($fields['category']->getOptions()),
        );
    }
}
