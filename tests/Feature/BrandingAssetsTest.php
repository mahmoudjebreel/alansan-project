<?php

namespace Tests\Feature;

use App\Filament\Pages\Backups;
use App\Filament\Pages\ManageSettings;
use App\Filament\Pages\MealReport;
use App\Filament\Pages\Trash;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\PublicUploads;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The logo and favicon an operator uploads, and where they end up.
 *
 * They used to be paths typed into a text box, which meant a typo showed as a
 * broken image on the sign-in page of every visitor with nothing on screen to
 * say why. The settings page takes the file itself now, and everything that
 * displays it goes through GeneralSettings, which hands back a URL or null and
 * never a link to a file that is not there.
 */
class BrandingAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);

        return $user;
    }

    public function test_the_shipped_logo_path_is_seeded_so_it_survives_a_reseed(): void
    {
        // The file itself lives in public/ and travels with the application,
        // so nobody has to upload it again after a deployment or a reseed.
        $this->assertSame(SettingsSeeder::LOGO_PATH, app(GeneralSettings::class)->logo_path);
    }

    public function test_a_logo_the_operator_chose_is_not_overwritten_by_the_seeder(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->logo_path = 'branding/theirs.png';
        $settings->save();

        $this->seed(SettingsSeeder::class);

        $this->assertSame('branding/theirs.png', app(GeneralSettings::class)->refresh()->logo_path);
    }

    public function test_a_logo_uploaded_on_the_settings_page_is_stored_and_resolvable(): void
    {
        Storage::fake(PublicUploads::DISK);
        $this->actingAsSuperAdmin();

        Livewire::test(ManageSettings::class)
            ->fillForm(['logo_path' => [UploadedFile::fake()->image('logo.png')]])
            ->callAction('save')
            ->assertHasNoErrors();

        $settings = app(GeneralSettings::class)->refresh();

        $this->assertStringStartsWith('branding/', $settings->logo_path);
        Storage::disk(PublicUploads::DISK)->assertExists($settings->logo_path);
        $this->assertNotNull($settings->logoUrl());
    }

    public function test_the_sign_in_page_shows_the_uploaded_logo(): void
    {
        Storage::fake(PublicUploads::DISK);
        Storage::disk(PublicUploads::DISK)->put('branding/logo.png', 'not-really-a-png');

        $settings = app(GeneralSettings::class);
        $settings->logo_path = 'branding/logo.png';
        $settings->save();

        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('login-brand__logo', $html);
        $this->assertStringContainsString('branding/logo.png', $html);
    }

    public function test_the_sign_in_page_falls_back_to_the_built_in_mark(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->logo_path = '';
        $settings->save();

        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('login-brand__logo', $html);
        $this->assertStringContainsString('login-brand__mark', $html);
    }

    public function test_a_path_pointing_at_nothing_resolves_to_null_rather_than_a_broken_image(): void
    {
        Storage::fake(PublicUploads::DISK);

        $settings = app(GeneralSettings::class);
        $settings->logo_path = 'branding/deleted-by-hand.png';
        $settings->favicon_path = '';
        $settings->save();

        $this->assertNull($settings->refresh()->logoUrl());
        $this->assertNull($settings->faviconUrl());
    }

    public function test_a_path_configured_before_uploads_existed_still_resolves(): void
    {
        // favicon.svg ships in public/ and is what such an installation holds.
        $settings = app(GeneralSettings::class);
        $settings->favicon_path = 'favicon.svg';
        $settings->save();

        // Root-relative on purpose: APP_URL is http://localhost, which is not
        // the host the panel is browsed at, so an absolute URL built from it
        // points somewhere the browser cannot reach.
        $this->assertSame('/favicon.svg', $settings->refresh()->faviconUrl());
    }

    /**
     * Reporting, backups and the trash are separate jobs done by different
     * people; each is its own sidebar section rather than three more links
     * under system management.
     */
    public function test_reporting_backups_and_trash_each_have_their_own_section(): void
    {
        $groups = [
            MealReport::getNavigationGroup(),
            Backups::getNavigationGroup(),
            Trash::getNavigationGroup(),
        ];

        $this->assertSame([
            __('ui.nav.reports'),
            __('ui.nav.backup'),
            __('ui.nav.trash'),
        ], $groups);

        // ...and none of them is the system-management group any more.
        $this->assertNotContains(__('ui.nav.system'), $groups);
    }
}
