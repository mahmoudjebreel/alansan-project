<?php

namespace Tests\Feature;

use App\Filament\Pages\UserActivityPage;
use App\Filament\UserActivity\OnlineSessionsTable;
use App\Filament\UserActivity\PageVisitsTable;
use App\Filament\UserActivity\SessionsTable;
use App\Models\User;
use App\Models\UserPageVisit;
use App\Models\UserSession;
use App\Support\Activity\Duration;
use App\Support\Activity\UserAgentSummary;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The User Activity page: who may open it, what its tabs show, and that
 * pruning removes telemetry only.
 */
class UserActivityPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_only_super_admin_holds_the_permission_by_default(): void
    {
        foreach (['Admin', 'Data Entry', 'Viewer', 'M&E'] as $role) {
            $this->assertFalse(
                Role::findByName($role)->hasPermissionTo('user_activity.view'),
                "The [{$role}] role must not receive user_activity.view by default.",
            );
        }

        $this->assertFalse(User::factory()->create()->assignRole('Admin')->can('user_activity.view'));
        $this->assertTrue(User::factory()->create()->assignRole('Super Admin')->can('user_activity.view'));
    }

    public function test_the_page_is_forbidden_without_the_permission(): void
    {
        $this->actingAsRole('Admin');
        $this->get('/admin/user-activity-page')->assertForbidden();

        $this->actingAsRole('Data Entry');
        $this->get('/admin/user-activity-page')->assertForbidden();
    }

    public function test_super_admin_opens_the_page(): void
    {
        $this->actingAsRole('Super Admin');

        $this->get('/admin/user-activity-page')
            ->assertOk()
            ->assertSee(__('ui.user_activity.title'))
            ->assertSee(__('ui.user_activity.tabs.online'));
    }

    public function test_tabs_switch_and_a_session_narrows_the_visits_tab(): void
    {
        $this->actingAsRole('Super Admin');

        Livewire::test(UserActivityPage::class)
            ->assertSet('activeTab', 'online')
            ->call('setTab', 'sessions')
            ->assertSet('activeTab', 'sessions')
            ->call('setTab', 'nonsense')
            ->assertSet('activeTab', 'sessions')
            ->call('showVisits', 42)
            ->assertSet('activeTab', 'visits')
            ->assertSet('sessionId', 42)
            ->call('setTab', 'online')
            ->assertSet('sessionId', null);
    }

    public function test_the_tables_list_sessions_and_visits(): void
    {
        $viewer = $this->actingAsRole('Super Admin');
        $subject = User::factory()->create(['name' => 'Observed Person']);

        [$session, $visit] = $this->openVisit($subject, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36');

        Livewire::test(OnlineSessionsTable::class)
            ->assertCanSeeTableRecords([$session])
            ->assertSee('Observed Person')
            ->assertSee('Chrome 128');

        Livewire::test(SessionsTable::class)
            ->assertCanSeeTableRecords([$session]);

        Livewire::test(PageVisitsTable::class, ['sessionId' => $session->id])
            ->assertCanSeeTableRecords([$visit]);

        Livewire::test(PageVisitsTable::class, ['sessionId' => $session->id + 1000])
            ->assertCanNotSeeTableRecords([$visit]);
    }

    public function test_the_timeline_merges_visits_with_activity_log_rows(): void
    {
        $this->actingAsRole('Super Admin');
        $subject = User::factory()->create();

        [, $visit] = $this->openVisit($subject);

        activity('auth')->causedBy($subject)->event('login')->log('User logged in');
        activity()->causedBy(User::factory()->create())->event('created')->log('Someone else');

        $events = Livewire::test(UserActivityPage::class)
            ->set('data.user_id', $subject->id)
            ->instance()
            ->timelineEvents();

        $this->assertCount(2, $events);
        $this->assertSame(['visit', 'activity'], $events->pluck('type')->sort()->values()->reverse()->values()->all());
        $this->assertContains('User logged in', $events->pluck('title')->all());
    }

    public function test_pruning_deletes_old_telemetry_and_leaves_the_activity_log_alone(): void
    {
        $subject = User::factory()->create();

        [$oldSession, $oldVisit] = $this->openVisit($subject);
        $oldSession->forceFill(['login_at' => now()->subDays(120), 'last_seen_at' => now()->subDays(120)])->save();
        $oldVisit->forceFill(['entered_at' => now()->subDays(120)])->save();

        [$recentSession, $recentVisit] = $this->openVisit($subject);

        activity('auth')->causedBy($subject)->event('login')->log('User logged in');
        Activity::query()->update(['created_at' => now()->subDays(400)]);
        $activityCount = Activity::count();

        $this->artisan('user-activity:prune')->assertSuccessful();

        $this->assertDatabaseMissing('user_page_visits', ['id' => $oldVisit->id]);
        $this->assertDatabaseMissing('user_sessions', ['id' => $oldSession->id]);
        $this->assertDatabaseHas('user_page_visits', ['id' => $recentVisit->id]);
        $this->assertDatabaseHas('user_sessions', ['id' => $recentSession->id]);
        $this->assertSame($activityCount, Activity::count());
    }

    public function test_user_agent_summary_and_duration_helpers(): void
    {
        $chrome = UserAgentSummary::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36');
        $this->assertSame(['browser' => 'Chrome 128', 'platform' => 'Windows', 'device_type' => 'desktop'], $chrome);

        $iphone = UserAgentSummary::parse('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
        $this->assertSame(['browser' => 'Safari 17', 'platform' => 'iOS', 'device_type' => 'mobile'], $iphone);

        $android = UserAgentSummary::parse('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Mobile Safari/537.36');
        $this->assertSame(['browser' => 'Chrome 127', 'platform' => 'Android', 'device_type' => 'mobile'], $android);

        $this->assertSame(['browser' => null, 'platform' => null, 'device_type' => null], UserAgentSummary::parse(null));

        app()->setLocale('en');
        $this->assertSame('45 s', Duration::humanize(45));
        $this->assertSame('12 min', Duration::humanize(12 * 60 + 30));
        $this->assertSame('1 h 05 min', Duration::humanize(65 * 60));
    }

    /**
     * @return array{0: UserSession, 1: UserPageVisit}
     */
    private function openVisit(User $user, ?string $userAgent = null): array
    {
        $summary = UserAgentSummary::parse($userAgent);

        $session = UserSession::create([
            'user_id' => $user->id,
            'ip_address' => '10.0.0.5',
            'user_agent' => $userAgent,
            'browser' => $summary['browser'],
            'platform' => $summary['platform'],
            'device_type' => $summary['device_type'],
            'login_at' => now(),
            'last_seen_at' => now(),
        ]);

        $visit = UserPageVisit::create([
            'user_session_id' => $session->id,
            'user_id' => $user->id,
            'visit_token' => (string) Str::uuid(),
            'route_name' => 'filament.admin.resources.children.index',
            'path' => '/admin/children',
            'page_kind' => 'list',
            'entered_at' => now(),
            'active_seconds' => 90,
        ]);

        return [$session, $visit];
    }
}
