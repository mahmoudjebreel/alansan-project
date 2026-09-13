<?php

namespace Tests\Feature;

use App\Models\Child;
use App\Models\User;
use App\Models\UserPageVisit;
use App\Models\UserSession;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Page visits are written by the panel middleware after the response, and
 * only for a real page load; the heartbeat and leave endpoints touch only a
 * visit the signed-in user owns, and add at most the configured cap.
 */
class UserActivityPageVisitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('Super Admin');
    }

    public function test_a_list_page_load_records_one_visit(): void
    {
        $this->actingAs($this->user)->get('/admin/children')->assertOk();

        $this->assertDatabaseCount('user_page_visits', 1);

        $visit = UserPageVisit::first();
        $this->assertSame('filament.admin.resources.children.index', $visit->route_name);
        $this->assertSame('/admin/children', $visit->path);
        $this->assertSame('list', $visit->page_kind);
        $this->assertNull($visit->subject_type);
        $this->assertSame('127.0.0.1', $visit->ip_address);
        $this->assertTrue(Str::isUuid($visit->visit_token));
        $this->assertSame(0, $visit->active_seconds);
        $this->assertNull($visit->left_at);
        $this->assertSame($this->user->id, (int) $visit->session->user_id);
    }

    public function test_the_query_string_is_never_stored(): void
    {
        $this->actingAs($this->user)->get('/admin/children?tableSearch=very-private-name')->assertOk();

        $visit = UserPageVisit::first();
        $this->assertSame('/admin/children', $visit->path);
        $this->assertStringNotContainsString('very-private-name', json_encode($visit->getAttributes()));
    }

    public function test_a_record_page_records_the_model_and_key_it_opened(): void
    {
        $child = Child::factory()->create();

        $this->actingAs($this->user)->get("/admin/children/{$child->id}/edit")->assertOk();

        $visit = UserPageVisit::first();
        $this->assertSame('edit', $visit->page_kind);
        $this->assertSame(Child::class, $visit->subject_type);
        $this->assertSame($child->id, (int) $visit->subject_id);
    }

    public function test_the_tracker_script_is_rendered_with_the_visit_token(): void
    {
        $html = $this->actingAs($this->user)->get('/admin/children')->assertOk()->getContent();

        $visit = UserPageVisit::first();
        $this->assertStringContainsString($visit->visit_token, $html);
        // @js() escapes the slashes in the URL it prints.
        $this->assertStringContainsString('activity\/heartbeat', $html);
    }

    public function test_requests_that_are_not_page_loads_record_nothing(): void
    {
        $this->actingAs($this->user);

        $this->get('/session/keep-alive')->assertNoContent();
        $this->get('/admin/children', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->get('/admin/does-not-exist')->assertNotFound();

        $this->assertDatabaseCount('user_page_visits', 0);
    }

    public function test_a_guest_records_nothing(): void
    {
        $this->get('/admin/children')->assertRedirect();

        $this->assertDatabaseCount('user_page_visits', 0);
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_a_heartbeat_adds_at_most_the_cap_and_refreshes_last_seen(): void
    {
        [$session, $visit] = $this->openVisit($this->user);
        $session->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

        $this->actingAs($this->user)
            ->post('/activity/heartbeat', ['visit' => $visit->visit_token, 'seconds' => 99999])
            ->assertNoContent();

        $visit->refresh();
        $this->assertSame((int) config('user-activity.max_heartbeat_increment_seconds'), $visit->active_seconds);
        $this->assertSame(1, $visit->heartbeats);
        $this->assertNotNull($visit->last_heartbeat_at);
        $this->assertNull($visit->left_at);

        $this->assertTrue($session->fresh()->last_seen_at->gt(now()->subMinute()));

        $this->actingAs($this->user)
            ->post('/activity/heartbeat', ['visit' => $visit->visit_token, 'seconds' => 30])
            ->assertNoContent();

        $this->assertSame(150, $visit->fresh()->active_seconds);
    }

    public function test_a_heartbeat_for_another_users_visit_changes_nothing(): void
    {
        $other = User::factory()->create();
        [, $visit] = $this->openVisit($other);

        $this->actingAs($this->user)
            ->post('/activity/heartbeat', ['visit' => $visit->visit_token, 'seconds' => 60])
            ->assertNoContent();

        $this->assertSame(0, $visit->fresh()->active_seconds);
        $this->assertSame(0, $visit->fresh()->heartbeats);
    }

    public function test_a_heartbeat_without_a_session_is_refused(): void
    {
        $this->post('/activity/heartbeat', ['visit' => (string) Str::uuid(), 'seconds' => 60])
            ->assertStatus(401);

        $this->post('/activity/leave', ['visit' => (string) Str::uuid(), 'seconds' => 60])
            ->assertStatus(401);
    }

    public function test_leaving_closes_the_visit_and_a_later_heartbeat_is_ignored(): void
    {
        [, $visit] = $this->openVisit($this->user);

        $this->actingAs($this->user)
            ->post('/activity/leave', ['visit' => $visit->visit_token, 'seconds' => 20])
            ->assertNoContent();

        $visit->refresh();
        $this->assertNotNull($visit->left_at);
        $this->assertSame(20, $visit->active_seconds);

        $this->actingAs($this->user)
            ->post('/activity/heartbeat', ['visit' => $visit->visit_token, 'seconds' => 60])
            ->assertNoContent();

        $this->assertSame(20, $visit->fresh()->active_seconds);
    }

    public function test_a_recorder_failure_never_breaks_the_page(): void
    {
        Log::spy();

        Schema::drop('user_page_visits');

        $this->actingAs($this->user)->get('/admin/children')->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'User activity could not be recorded'))
            ->atLeast()->once();
    }

    /**
     * @return array{0: UserSession, 1: UserPageVisit}
     */
    private function openVisit(User $user): array
    {
        $session = UserSession::create([
            'user_id' => $user->id,
            'login_at' => now(),
            'last_seen_at' => now(),
        ]);

        $visit = UserPageVisit::create([
            'user_session_id' => $session->id,
            'user_id' => $user->id,
            'visit_token' => (string) Str::uuid(),
            'route_name' => 'filament.admin.pages.dashboard',
            'path' => '/admin',
            'page_kind' => 'dashboard',
            'entered_at' => now(),
        ]);

        return [$session, $visit];
    }
}
