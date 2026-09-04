<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use App\Notifications\DataActionNotification;
use App\Support\Notifications\ActionType;
use App\Support\Notifications\NotifiableModule;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * The panel's own wording follows the chosen language.
 *
 * Field labels were already translated; what these cover is the chrome around
 * them - navigation, page titles, module names, notification sentences - which
 * used to be Arabic literals scattered through the classes and stayed Arabic
 * whatever the panel was switched to.
 */
class PanelLocalizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every key in one file exists in the other.
     *
     * A key added to only one language does not fail anywhere: the missing
     * side silently prints the key path, mid-sentence, in production.
     */
    public function test_the_two_ui_translation_files_carry_the_same_keys(): void
    {
        $arabic = $this->flatten(require lang_path('ar/ui.php'));
        $english = $this->flatten(require lang_path('en/ui.php'));

        $this->assertSame([], array_values(array_diff($arabic, $english)), 'Keys missing from lang/en/ui.php');
        $this->assertSame([], array_values(array_diff($english, $arabic)), 'Keys missing from lang/ar/ui.php');
    }

    public function test_no_ui_translation_is_left_empty(): void
    {
        foreach (['ar', 'en'] as $locale) {
            foreach ($this->flatten(require lang_path($locale . '/ui.php')) as $key) {
                $this->assertNotSame('', trim((string) data_get(require lang_path($locale . '/ui.php'), $key)), $locale . ': ' . $key);
            }
        }
    }

    public function test_the_trash_page_reads_in_the_chosen_language(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);

        $child = Child::factory()->create(['name' => 'Localised', 'child_id' => '900000001']);
        $child->delete();

        // Through the session, the way the /locale/{locale} switcher sets it:
        // App::setLocale() alone would be undone by the SetLocale middleware
        // on the very request being made.
        $arabic = $this->withSession(['locale' => 'ar'])
            ->get('/admin/trash')->assertOk()->getContent();
        $this->assertStringContainsString('سلة المحذوفات المركزية', $arabic);

        $english = $this->withSession(['locale' => 'en'])
            ->get('/admin/trash')->assertOk()->getContent();
        $this->assertStringContainsString('Central trash', $english);
        $this->assertStringContainsString('Deleted records', $english);
        // The module badge comes from the shared module names, not from a
        // second Arabic-only list inside the page.
        $this->assertStringContainsString('Children', $english);
        $this->assertStringNotContainsString('سلة المحذوفات المركزية', $english);
    }

    public function test_a_notification_is_written_in_the_language_of_the_request(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        $dataEntry = User::factory()->create(['name' => 'Sami']);
        $dataEntry->assignRole('Data Entry');

        App::setLocale('en');
        $this->actingAs($dataEntry);

        Child::factory()->create();

        $payload = DatabaseNotification::query()
            ->where('type', DataActionNotification::class)
            ->firstOrFail()
            ->data;

        $this->assertSame('New record added', $payload['title']);
        $this->assertStringContainsString('Sami', $payload['body']);
        $this->assertStringContainsString('added a new record to', $payload['body']);
        $this->assertStringContainsString('Children', $payload['body']);
    }

    public function test_action_and_module_names_follow_the_locale(): void
    {
        App::setLocale('en');
        $this->assertSame('Record deleted', ActionType::title(ActionType::DELETE));
        $this->assertSame('Child follow-up', NotifiableModule::labelFor(\App\Models\FollowUpChild::class));

        App::setLocale('ar');
        $this->assertSame('حذف سجل', ActionType::title(ActionType::DELETE));
        $this->assertSame('متابعة الأطفال', NotifiableModule::labelFor(\App\Models\FollowUpChild::class));
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return array<string>
     */
    private function flatten(array $translations, string $prefix = ''): array
    {
        $keys = [];

        foreach ($translations as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flatten($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    }
}
