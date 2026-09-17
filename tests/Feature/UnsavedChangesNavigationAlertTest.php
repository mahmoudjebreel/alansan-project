<?php

namespace Tests\Feature;

use App\Filament\Resources\ChildResource;
use App\Filament\Resources\ChildResource\Pages\EditChild;
use App\Filament\Resources\FollowUpChildResource;
use App\Filament\Resources\PregnantLactatingWomanResource;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\PregnantLactatingWoman;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The panel's own unsaved-changes dialog for links inside the application.
 *
 * The decision itself - dirty or not - is made in the browser, from the
 * same form-data hash Filament keeps for its native beforeunload prompt.
 * What can be checked here is the server half: every form page carries
 * that hash, the dialog's script and its translated strings are on the
 * page, the strings exist in both languages, and a save refreshes the hash
 * so a saved form is pristine again.
 */
class UnsavedChangesNavigationAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SuperAdminPermissionsSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');
        $this->actingAs($user);
    }

    public function test_the_dialog_strings_exist_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (['title', 'text', 'leave', 'stay'] as $key) {
                $this->assertTrue(
                    trans()->has('ui.alerts.unsaved_changes.' . $key, $locale),
                    "[ui.alerts.unsaved_changes.{$key}] is missing in [{$locale}].",
                );
            }
        }

        $this->assertSame('لديك تغييرات غير محفوظة', trans('ui.alerts.unsaved_changes.title', [], 'ar'));
        $this->assertSame('لديك بيانات تم تعديلها ولم يتم حفظها. هل تريد مغادرة الصفحة؟', trans('ui.alerts.unsaved_changes.text', [], 'ar'));
        $this->assertSame('مغادرة الصفحة', trans('ui.alerts.unsaved_changes.leave', [], 'ar'));
        $this->assertSame('البقاء في الصفحة', trans('ui.alerts.unsaved_changes.stay', [], 'ar'));
    }

    public function test_every_form_page_carries_the_guard_and_filaments_own_dirty_state(): void
    {
        $child = Child::factory()->create(['municipality' => 'gaza', 'type_of_site' => 'El Salam Camp', 'mother_marital_status' => 'متزوجة']);
        $episode = FollowUpChild::factory()->create(['discharge_outcome' => FollowUpChild::ACTIVE_OUTCOME]);
        $mother = PregnantLactatingWoman::factory()->create();

        $urls = [
            'children create' => ChildResource::getUrl('create'),
            'children edit' => ChildResource::getUrl('edit', ['record' => $child]),
            'follow-up edit' => FollowUpChildResource::getUrl('edit', ['record' => $episode]),
            'pregnant create' => PregnantLactatingWomanResource::getUrl('create'),
            'pregnant edit' => PregnantLactatingWomanResource::getUrl('edit', ['record' => $mother]),
        ];

        foreach ($urls as $page => $url) {
            $response = $this->get($url);

            $response->assertOk();

            // Filament's own dirty state, which the dialog reads.
            $response->assertSee('savedDataHash', false);
            $response->assertSee('setUpUnsavedDataChangesAlert', false);

            // The dialog itself and its strings.
            $response->assertSee('unsaved_changes', false);
            $response->assertSee('dirtyComponents', false);
            $response->assertSee('swal2-noanimation', false);

            // The library is hinted to the browser from the head.
            $response->assertSee('rel="preload"', false);
            $response->assertSee('sweetalert2.all.min.js', false);
        }
    }

    public function test_a_save_leaves_the_form_pristine_again(): void
    {
        $child = Child::factory()->create([
            'child_id' => '123456789',
            'muac_mm' => 130,
            'phone_number' => '0599000000',
            'municipality' => 'gaza',
            'type_of_site' => 'El Salam Camp',
            'mother_marital_status' => 'متزوجة',
        ]);

        $page = Livewire::test(EditChild::class, ['record' => $child->getKey()]);

        $before = $page->get('savedDataHash');

        $page->fillForm(['phone_number' => '0599111111'])
            ->call('save')
            ->assertHasNoFormErrors();

        $after = $page->get('savedDataHash');

        $this->assertNotSame($before, $after, 'The hash must follow the saved data.');

        // The hash is Filament's own formula over the form data it holds, so
        // the browser's comparison of the two comes out equal after a save.
        $data = $page->get('data');
        $expected = md5((string) str(json_encode($data, JSON_UNESCAPED_UNICODE))->replace('\\', ''));

        $this->assertSame($expected, $after);
    }
}
