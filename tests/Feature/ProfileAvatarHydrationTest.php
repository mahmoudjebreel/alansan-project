<?php

namespace Tests\Feature;

use App\Filament\Pages\EditProfile;
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
        Storage::fake('public');

        $user = User::factory()->create(['avatar' => null]);
        $this->actingAs($user);

        Livewire::test(EditProfile::class)
            ->fillForm(['avatar' => UploadedFile::fake()->image('me.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $user->refresh()->avatar;

        $this->assertNotNull($stored);
        $this->assertStringStartsWith('avatars/', $stored);
        Storage::disk('public')->assertExists($stored);

        // Re-open the page: the field must be hydrated with the raw path.
        Livewire::test(EditProfile::class)
            ->assertFormSet(fn (array $state) => in_array($stored, (array) $state['avatar'], true));
    }

    public function test_uploading_again_replaces_the_previous_file_and_still_rehydrates(): void
    {
        Storage::fake('public');

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
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        Livewire::test(EditProfile::class)
            ->assertFormSet(fn (array $state) => in_array($second, (array) $state['avatar'], true));
    }

    public function test_avatar_url_is_served_by_the_public_disk_link(): void
    {
        $user = User::factory()->create(['avatar' => 'avatars/example.jpg']);

        $this->assertSame(
            Storage::disk('public')->url('avatars/example.jpg'),
            $user->avatar_url,
        );
    }
}
