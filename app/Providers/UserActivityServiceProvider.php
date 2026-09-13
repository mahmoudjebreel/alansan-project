<?php

namespace App\Providers;

use App\Console\Commands\PruneUserActivity;
use App\Events\ExcelActionOccurred;
use App\Filament\UserActivity\OnlineSessionsTable;
use App\Filament\UserActivity\PageVisitsTable;
use App\Filament\UserActivity\SessionsTable;
use App\Http\Middleware\TrackUserActivity;
use App\Listeners\RecordAuthActivity;
use App\Listeners\RecordExcelActivity;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Throwable;

/**
 * Wires up user activity monitoring.
 *
 * Everything the feature needs to hook into the application is registered
 * from here - auth events, the Excel event, the tracker script, the Livewire
 * tables and the prune command - so removing this provider from
 * bootstrap/providers.php switches the whole feature off. The one piece that
 * cannot live here is the panel middleware, because Filament captures a
 * panel's middleware list before any application provider boots; that is the
 * single line added to AdminPanelProvider.
 *
 * Nothing registered here alters what a request, a save or a sign-in does.
 * Every listener runs after the fact and swallows its own failures.
 */
class UserActivityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PruneUserActivity::class]);
        }

        // The tables the User Activity page embeds. They live outside the
        // discovered Widgets directory on purpose: a discovered widget is
        // placed on the dashboard as well.
        Livewire::component('user-activity.online-sessions', OnlineSessionsTable::class);
        Livewire::component('user-activity.sessions', SessionsTable::class);
        Livewire::component('user-activity.page-visits', PageVisitsTable::class);

        Event::listen(Login::class, [RecordAuthActivity::class, 'onLogin']);
        Event::listen(Logout::class, [RecordAuthActivity::class, 'onLogout']);
        Event::listen(Failed::class, [RecordAuthActivity::class, 'onFailed']);
        Event::listen(ExcelActionOccurred::class, [RecordExcelActivity::class, 'onExcelAction']);

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => $this->trackerScript(),
        );
    }

    /**
     * The heartbeat script, rendered only into a full page load for a
     * signed-in user. Livewire round trips do not render this hook.
     */
    private function trackerScript(): string
    {
        try {
            if (! (bool) config('user-activity.enabled', true) || ! auth()->check()) {
                return '';
            }

            $token = request()->attributes->get(TrackUserActivity::TOKEN_ATTRIBUTE);

            if (! is_string($token) || $token === '') {
                return '';
            }

            return view('filament.scripts.activity-tracker', [
                'visitToken' => $token,
                'heartbeatUrl' => route('activity.heartbeat'),
                'leaveUrl' => route('activity.leave'),
                'heartbeatSeconds' => max(15, (int) config('user-activity.heartbeat_seconds', 60)),
                'csrfToken' => csrf_token(),
            ])->render();
        } catch (Throwable $e) {
            // A page must never fail to render because its tracker could not.
            Log::warning('User activity tracker could not be rendered: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return '';
        }
    }
}
