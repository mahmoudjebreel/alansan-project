<?php

namespace Tests\Feature;

use App\Filament\Pages\ManageSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\NotificationSettings;
use App\Support\Notifications\ActionType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SuperAdminPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\LaravelSettings\Models\SettingsProperty;
use Tests\TestCase;

/**
 * The settings seeder exists because a settings migration runs once and is
 * then never repeated: a database restored from an older dump, or one where a
 * property was removed by hand, is left missing keys the settings class still
 * declares - and reading such an object throws, taking the whole panel down
 * rather than one page.
 *
 * @see \Database\Seeders\SettingsSeeder
 */
class SettingsSeederTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SuperAdminPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);

        return $user;
    }

    public function test_it_restores_a_property_that_went_missing(): void
    {
        SettingsProperty::query()
            ->where('group', 'general')
            ->where('name', 'default_pagination')
            ->delete();

        // Reading the settings object in this state is precisely what throws
        // MissingSettings and takes the panel down, so it is not read here -
        // the seeder works off the properties table for exactly that reason.
        app()->forgetInstance(GeneralSettings::class);

        $this->seed(SettingsSeeder::class);

        $this->assertSame(25, app(GeneralSettings::class)->default_pagination);
    }

    public function test_it_never_overwrites_a_value_the_operator_has_set(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->site_name = 'اسم مخصص';
        $settings->default_pagination = 100;
        $settings->save();

        $this->seed(SettingsSeeder::class);

        $settings = app(GeneralSettings::class)->refresh();

        $this->assertSame('اسم مخصص', $settings->site_name);
        $this->assertSame(100, $settings->default_pagination);
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        $this->seed(SettingsSeeder::class);
        $before = SettingsProperty::query()->count();

        $this->seed(SettingsSeeder::class);

        $this->assertSame($before, SettingsProperty::query()->count());
    }

    public function test_every_declared_general_setting_has_a_stored_value(): void
    {
        $this->seed(SettingsSeeder::class);

        $stored = SettingsProperty::query()
            ->where('group', 'general')
            ->pluck('name')
            ->all();

        $declared = array_map(
            fn (\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(GeneralSettings::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        $missing = array_diff($declared, $stored);

        $this->assertSame(
            [],
            array_values($missing),
            'GeneralSettings declares properties the seeder does not create: ' . implode(', ', $missing),
        );
    }

    public function test_every_action_type_is_notifiable_on_a_fresh_install(): void
    {
        $this->seed(SettingsSeeder::class);

        $this->assertSame(
            ActionType::all(),
            app(NotificationSettings::class)->refresh()->enabled_actions,
        );
    }

    public function test_the_settings_page_saves_every_field_it_shows(): void
    {
        $this->actingAsSuperAdmin();
        $this->seed(SettingsSeeder::class);

        Livewire::test(ManageSettings::class)
            ->fillForm([
                'site_name' => 'نظام محدث',
                'login_tagline' => 'سطر جديد',
                'default_theme' => 'dark',
                'timezone' => 'Asia/Amman',
                'default_pagination' => 50,
                'support_email' => 'support@example.org',
                'support_phone' => '0599000000',
            ])
            ->callAction('save')
            ->assertHasNoErrors();

        $settings = app(GeneralSettings::class)->refresh();

        $this->assertSame('نظام محدث', $settings->site_name);
        $this->assertSame('سطر جديد', $settings->login_tagline);
        $this->assertSame('dark', $settings->default_theme);
        $this->assertSame('Asia/Amman', $settings->timezone);
        $this->assertSame(50, $settings->default_pagination);
        $this->assertSame('support@example.org', $settings->support_email);
        $this->assertSame('0599000000', $settings->support_phone);
    }

    public function test_the_configured_page_size_is_always_one_of_the_offered_options(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_pagination = 37;

        $this->assertContains(37, $settings->paginationOptions());
    }
}
