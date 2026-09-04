<?php

namespace Tests\Feature;

use App\Filament\Pages\EditProfile;
use App\Support\PublicUploads;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Re-opening the profile page has to hand the raw relative path back to the
 * FileUpload field, and that path has to resolve to a URL the browser can
 * actually fetch. Both halves are covered here because the field renders an
 * empty avatar when either one breaks.
 */
class ProfileAvatarHydrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_avatar_is_restored_into_the_upload_field_on_reopen(): void
    {
        Storage::fake(PublicUploads::DISK);

        $user = User::factory()->create(['avatar' => null]);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm(['avatar' => UploadedFile::fake()->image('me.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $user->refresh()->avatar;

        $this->assertNotNull($stored);
        $this->assertStringStartsWith('avatars/', $stored);
        Storage::disk(PublicUploads::DISK)->assertExists($stored);

        // Re-open the page: the field must be hydrated with the raw path.
        Livewire::test(EditProfile::class)
            ->assertFormSet(fn (array $state) => in_array($stored, (array) $state['avatar'], true));
    }

    public function test_uploading_again_replaces_the_previous_file_and_still_rehydrates(): void
    {
        Storage::fake(PublicUploads::DISK);

        $user = User::factory()->create(['avatar' => null]);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm(['avatar' => UploadedFile::fake()->image('first.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $first = $user->refresh()->avatar;

        // Replace the whole field state, the way the browser does when a new
        // file is dropped onto a single-file upload.
        Livewire::test(EditProfile::class)
            ->set('data.avatar', [UploadedFile::fake()->image('second.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $second = $user->refresh()->avatar;

        $this->assertNotSame($first, $second);
        Storage::disk(PublicUploads::DISK)->assertMissing($first);
        Storage::disk(PublicUploads::DISK)->assertExists($second);

        Livewire::test(EditProfile::class)
            ->assertFormSet(fn (array $state) => in_array($second, (array) $state['avatar'], true));
    }

    /**
     * The URL is root-relative and needs no symlink.
     *
     * Both matter: APP_URL is not necessarily the host the panel is being
     * browsed at, and the server this is deployed to has no terminal to run
     * `storage:link` on, so an avatar behind that symlink would never load.
     */
    public function test_avatar_url_is_root_relative_and_needs_no_symlink(): void
    {
        Storage::fake(PublicUploads::DISK);
        Storage::disk(PublicUploads::DISK)->put('avatars/example.jpg', 'x');

        $user = User::factory()->create(['avatar' => 'avatars/example.jpg']);

        $this->assertSame('/uploads/avatars/example.jpg', $user->avatar_url);
    }

    public function test_a_missing_avatar_file_has_no_url_at_all(): void
    {
        Storage::fake(PublicUploads::DISK);

        $user = User::factory()->create(['avatar' => 'avatars/deleted-by-hand.jpg']);

        // Null, not a link that renders as a broken image in the user menu.
        $this->assertNull($user->avatar_url);
    }
}
