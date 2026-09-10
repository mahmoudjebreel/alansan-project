<?php

namespace App\Providers\Filament;

use App\Settings\GeneralSettings;
use App\Support\MuacClassifier;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\EditProfile;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Enums\ThemeMode;
use Filament\Navigation\NavigationGroup;
use App\Http\Middleware\SetLocale;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Throwable;

class AdminPanelProvider extends PanelProvider
{
    /**
     * The colour the panel is drawn in before an operator picks one.
     *
     * Matches the default SettingsSeeder writes, so a panel falling back to
     * it looks the same as one reading the setting.
     */
    private const DEFAULT_PRIMARY_COLOR = '#10b981';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->databaseNotifications()
            ->profile(EditProfile::class, isSimple: false)
            ->brandName(fn (): string => self::siteName())
            // Both fall back to what the panel shipped with when the operator
            // has not uploaded anything, so a fresh install still has a mark.
            ->brandLogo(fn (): ?string => self::settings()?->logoUrl())
            ->brandLogoHeight('2.5rem')
            ->favicon(fn (): string => self::settings()?->faviconUrl() ?? secure_asset('favicon.svg'))
            ->colors(fn (): array => [
                'primary' => Color::hex(self::primaryColor()),
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->font('Tajawal')
            ->defaultThemeMode(self::themeMode())
            // The listings are wide - six modules of thirty-odd columns - so
            // the content area is given the full width rather than the default
            // centred column that left the tables scrolling inside a gutter.
            ->maxContentWidth(Width::Full)
            ->navigationGroups([
                NavigationGroup::make()
                    ->label(fn (): string => __('ui.nav.dashboard'))
                    ->icon('heroicon-o-home'),
                NavigationGroup::make()
                    ->label(fn (): string => __('ui.nav.data'))
                    ->icon('heroicon-o-folder')
                    ->collapsible(),
                NavigationGroup::make()
                    ->label(fn (): string => __('ui.nav.system'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsible(),
                // The reporting, backup and trash screens each get their own
                // section rather than being three more links at the bottom of
                // system management: they are separate jobs, done by different
                // people, and looking for the trash under "settings" is not
                // where anybody looks first.
                NavigationGroup::make()
                    ->label(fn (): string => __('ui.nav.reports'))
                    ->icon('heroicon-o-document-chart-bar')
                    ->collapsible(),
                NavigationGroup::make()
                    ->label(fn (): string => __('ui.nav.backup'))
                    ->icon('heroicon-o-circle-stack')
                    ->collapsible(),
                NavigationGroup::make()
                    ->label(fn (): string => __('ui.nav.trash'))
                    ->icon('heroicon-o-trash')
                    ->collapsible(),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->unsavedChangesAlerts()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            // The home page is App\Filament\Pages\Dashboard, picked up by the
            // page discovery above; registering Filament's own here as well
            // would put two pages on the same route.
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // No account widget: the figures open the page, and the user menu
            // in the top bar already carries the name and the sign-out link.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->userMenuItems([
                MenuItem::make('locale_en')
                    ->label('EN')
                    ->url('/locale/en')
                    ->sort(0),
                MenuItem::make('locale_ar')
                    ->label('العربية')
                    ->url('/locale/ar')
                    ->sort(1),
            ])
            ->renderHook(
                // Above the heading, not between it and the form: the brand is
                // what the page opens with, and putting it lower left the site
                // name printed twice with the greeting in between.
                PanelsRenderHook::SIMPLE_PAGE_START,
                fn (): string => view('filament.auth.login-brand', [
                    'siteName' => self::siteName(),
                    'tagline' => self::settings()?->login_tagline,
                    'logoUrl' => self::settings()?->logoUrl(),
                ])->render()
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.scripts.dashboard-alerts', [
                    'primaryColor' => self::primaryColor(),
                    'keepAliveUrl' => route('session.keep-alive'),
                    'keepAliveSeconds' => self::keepAliveSeconds(),
                    // The referral prompt classifies the reading in the
                    // browser, so it needs the same cut-offs PHP applies.
                    'muacThresholds' => [
                        'sam_max' => MuacClassifier::SAM_MAX_MM,
                        'mam_max' => MuacClassifier::MAM_MAX_MM,
                    ],
                ])->render()
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SetLocale::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * How often an open tab pings the session, in seconds.
     *
     * Derived from the configured session lifetime rather than fixed, so
     * shortening SESSION_LIFETIME cannot leave the ping arriving after the
     * session it was meant to keep alive has already gone. A third of the
     * lifetime means two pings are missed before anything expires, and the
     * floor keeps a misconfigured lifetime from turning this into a flood.
     */
    private static function keepAliveSeconds(): int
    {
        $lifetimeSeconds = ((int) config('session.lifetime', 120)) * 60;

        return max(60, (int) floor($lifetimeSeconds / 3));
    }

    /**
     * The operator-editable settings, or null when they cannot be read.
     *
     * Everything the panel is branded and coloured with lives in the database,
     * and it is read while the panel is being defined - which also happens
     * during `migrate`, on a database that has no settings table yet, and on
     * any environment where the settings migrations have not been run. A
     * missing row would otherwise take down every page of the panel, the
     * sign-in screen included, leaving no way in to put it right. Each caller
     * falls back to what the panel ships with instead.
     *
     * Note that spatie's settings load lazily: nothing reaches the database
     * until a property is read, so the read has to happen inside the try.
     */
    private static function settings(): ?GeneralSettings
    {
        try {
            $settings = app(GeneralSettings::class);
            $settings->site_name;

            return $settings;
        } catch (Throwable) {
            return null;
        }
    }

    /** The colour the panel is drawn in, from the settings page. */
    private static function primaryColor(): string
    {
        return self::settings()?->primary_color ?: self::DEFAULT_PRIMARY_COLOR;
    }

    /**
     * The theme the panel opens on, from the settings page.
     *
     * An unreadable setting, or one holding an unrecognised value, follows the
     * operating system.
     */
    private static function themeMode(): ThemeMode
    {
        return match (self::settings()?->default_theme) {
            'light' => ThemeMode::Light,
            'dark' => ThemeMode::Dark,
            default => ThemeMode::System,
        };
    }

    /**
     * The brand name, from the settings page.
     *
     * The mojibake check catches a site name that was written to the database
     * through a mis-encoded connection: rather than printing the wreckage
     * across every page, the panel falls back to its own name.
     */
    private static function siteName(): string
    {
        $fallback = __('ui.site.fallback_name');
        $siteName = self::settings()?->site_name ?? '';

        if (str_contains($siteName, '╪') || str_contains($siteName, '┘')) {
            return $fallback;
        }

        return $siteName ?: $fallback;
    }
}
