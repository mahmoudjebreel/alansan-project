<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Models\UserSession;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Sign-ins, sign-outs and failed sign-ins are observed through Laravel's own
 * auth events: one session row in user_sessions and one audit row in the
 * existing activity_log each, without anything in the login page changing.
 */
class UserActivityAuthTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_login_opens_a_session_and_writes_an_auth_activity_row(): void
    {
        $user = User::factory()->create();

        Auth::login($user);

        $this->assertDatabaseHas('user_sessions', [
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'remember' => 0,
        ]);

        $session = UserSession::first();
        $this->assertNull($session->logout_at);
        $this->assertNotNull($session->login_at);
        $this->assertSame($session->id, session(UserSession::SESSION_KEY));

        $activity = Activity::where('log_name', 'auth')->where('event', 'login')->first();
        $this->assertNotNull($activity, 'The login was not written to activity_log.');
        $this->assertSame($user->id, (int) $activity->causer_id);
        $this->assertSame('127.0.0.1', $activity->properties['ip'] ?? null);
        $this->assertSame($activity->id, (int) $session->login_activity_id);
    }

    public function test_the_filament_login_page_is_observed_without_being_changed(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $user->assignRole('Admin');

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('user_sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'auth', 'event' => 'login', 'causer_id' => $user->id]);
    }

    public function test_a_failed_login_is_recorded_without_the_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);

        $this->assertFalse(Auth::attempt(['email' => $user->email, 'password' => 'wrong-secret-xyz']));

        $activity = Activity::where('log_name', 'auth')->where('event', 'login_failed')->first();
        $this->assertNotNull($activity, 'The failed attempt was not written to activity_log.');
        $this->assertSame($user->email, $activity->properties['email'] ?? null);
        $this->assertNull($activity->causer_id);
        $this->assertStringNotContainsString('wrong-secret-xyz', json_encode($activity->getAttributes()));

        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_a_logout_closes_the_session_and_writes_an_auth_activity_row(): void
    {
        $user = User::factory()->create();

        Auth::login($user);
        Auth::logout();

        $session = UserSession::first();
        $this->assertNotNull($session->logout_at);
        $this->assertSame(UserSession::ENDED_LOGOUT, $session->ended_reason);
        $this->assertSame(UserSession::STATUS_LOGGED_OUT, $session->status());

        $this->assertDatabaseHas('activity_log', ['log_name' => 'auth', 'event' => 'logout', 'causer_id' => $user->id]);
    }

    public function test_a_session_that_predates_the_deploy_is_opened_on_its_first_page_load(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        // actingAs() sets the user without firing the Login event, which is
        // exactly the shape of a session that was already signed in when the
        // feature was deployed.
        $this->actingAs($user)->get('/admin')->assertOk();

        $session = UserSession::where('user_id', $user->id)->first();
        $this->assertNotNull($session, 'No session row was opened lazily.');
        $this->assertNotNull($session->session_hash);
        $this->assertSame(64, strlen($session->session_hash));
        $this->assertNotSame(session()->getId(), $session->session_hash);
    }

    public function test_nothing_is_written_when_the_feature_is_switched_off(): void
    {
        config(['user-activity.enabled' => false]);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        Auth::login($user);
        $this->actingAs($user)->get('/admin')->assertOk();
        Auth::logout();

        $this->assertDatabaseCount('user_sessions', 0);
        $this->assertDatabaseCount('user_page_visits', 0);
        $this->assertSame(0, Activity::where('log_name', 'auth')->count());
    }
}
