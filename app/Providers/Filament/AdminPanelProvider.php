<?php

namespace App\Providers\Filament;

use App\Settings\GeneralSettings;
use App\Filament\Pages\EditProfile;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use App\Http\Middleware\SetLocale;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    private const FALLBACK_SITE_NAME = 'أرض الإنسان - نظام المسح التغذوي';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->databaseNotifications()
            ->profile(EditProfile::class, isSimple: false)
            ->brandName(fn (): string => self::siteName())
            ->favicon(secure_asset('favicon.svg'))
            ->colors(fn (): array => [
                'primary' => Color::hex(app(GeneralSettings::class)->primary_color),
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->font('Tajawal')
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('لوحة التحكم'),
                NavigationGroup::make()
                    ->label('إدارة البيانات'),
                NavigationGroup::make()
                    ->label('إدارة النظام'),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
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
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render('
                    <script src="{{ asset(\'vendor/sweetalert2/sweetalert2.all.min.js\') }}"></script>
                    <script>
                        // ---------------------------------------------------------------
                        // Centralized, theme-aware SweetAlert2 helpers, reused by every
                        // destructive action across the dashboard (Trash, Backups, ...).
                        // Defined once here so pages never duplicate SweetAlert config.
                        // ---------------------------------------------------------------
                        const dashboardPrimaryColor = @js($primaryColor);

                        window.dashboardIsDark = function () {
                            return document.documentElement.classList.contains("dark");
                        };

                        // Styled confirmation dialog. Returns the SweetAlert2 promise.
                        window.dashboardConfirm = function (options) {
                            options = options || {};
                            return Swal.fire({
                                title: options.title || "تأكيد",
                                html: options.text || "",
                                icon: options.icon || "question",
                                showCancelButton: true,
                                confirmButtonText: options.confirmText || "نعم",
                                cancelButtonText: options.cancelText || "إلغاء",
                                confirmButtonColor: options.danger ? "#dc2626" : dashboardPrimaryColor,
                                cancelButtonColor: "#6b7280",
                                reverseButtons: true,
                                focusCancel: options.danger === true,
                                background: window.dashboardIsDark() ? "#1f2937" : "#ffffff",
                                color: window.dashboardIsDark() ? "#f9fafb" : "#111827",
                            });
                        };

                        // Lightweight success/error toast.
                        window.dashboardToast = function (icon, title) {
                            Swal.fire({
                                toast: true,
                                position: "top-start",
                                icon: icon || "success",
                                title: title || "",
                                showConfirmButton: false,
                                timer: 3000,
                                timerProgressBar: true,
                                background: window.dashboardIsDark() ? "#1f2937" : "#ffffff",
                                color: window.dashboardIsDark() ? "#f9fafb" : "#111827",
                            });
                        };

                        // Confirm, then run a Livewire method. The $wire reference is
                        // captured synchronously as an argument, so the call still works
                        // inside the async .then() (Alpine magics are not reliable there).
                        window.confirmAction = function ($wire, method, params, options) {
                            options = options || {};
                            return window.dashboardConfirm(options).then((result) => {
                                if (! result.isConfirmed) {
                                    return;
                                }

                                return Promise.resolve($wire.call(method, ...(params || []))).then((ok) => {
                                    if (ok === false) {
                                        window.dashboardToast("error", options.errorText || "تعذّر تنفيذ العملية");
                                    } else if (options.successText) {
                                        window.dashboardToast("success", options.successText);
                                    }
                                });
                            });
                        };

                        window.addEventListener("show-duplicate-visit-alert", event => {
                            const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
                            // Only the pregnant/lactating module sends a previous
                            // status; the row is skipped everywhere else.
                            let statusHtml = "";
                            if (detail.last_status_type) {
                                statusHtml = `<p style="margin-bottom: 8px; color: #374151;"><strong>حالة الأم السابقة:</strong> <span style="color: #7c3aed; font-weight: bold;">${detail.last_status_type}</span></p>`;
                            }
                            let warningHtml = "";
                            if (detail.visit_type_warning) {
                                warningHtml = `<p style="margin-top: 12px; padding: 10px; background-color: #fef2f2; border-right: 4px solid #ef4444; color: #991b1b; font-weight: bold; border-radius: 4px; font-size: 14px;">${detail.visit_type_warning}</p>`;
                            }
                            Swal.fire({
                                title: detail.title || "تنبيه: البيانات مسجلة مسبقاً",
                                html: `
                                    <div style="text-align: right; font-family: Tajawal, sans-serif; direction: rtl; font-size: 16px; line-height: 1.8;">
                                        <p style="margin-bottom: 8px; color: #374151;"><strong>تاريخ آخر زيارة:</strong> <span style="color: #2563eb; font-weight: bold;">${detail.last_visit_date}</span></p>
                                        <p style="margin-bottom: 8px; color: #374151;"><strong>نوع الزيارة السابقة:</strong> <span style="color: #059669; font-weight: bold;">${detail.last_visit_type}</span></p>
                                        ${statusHtml}
                                        ${warningHtml}
                                    </div>
                                `,
                                icon: detail.visit_type_warning ? "warning" : "info",
                                showCancelButton: true,
                                confirmButtonText: detail.confirm_button_text || "إضافة نفس البيانات",
                                cancelButtonText: "تخطي",
                                confirmButtonColor: "#2563eb",
                                cancelButtonColor: "#6b7280",
                                reverseButtons: true,
                                allowOutsideClick: false,
                                allowEscapeKey: false
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    if (detail.action_type === "fill_child") {
                                        Livewire.dispatch("fillChildDataFromAlert", { data: detail.record_data });
                                    } else if (detail.action_type === "fill_mother") {
                                        Livewire.dispatch("fillMotherDataFromAlert", { data: detail.record_data });
                                    }
                                } else if (result.dismiss === Swal.DismissReason.cancel) {
                                    window.location.href = detail.index_url;
                                }
                            });
                        });

                        // Group sessions: the ID number is already registered on an
                        // active session. Same visual style as the alert above, with
                        // the session subject added and only two outcomes - prefill
                        // the participant data, or leave for the listing.
                        window.addEventListener("show-group-session-duplicate-alert", event => {
                            const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
                            Swal.fire({
                                title: detail.title || "رقم الهوية مسجل مسبقاً",
                                html: `
                                    <div style="text-align: right; font-family: Tajawal, sans-serif; direction: rtl; font-size: 16px; line-height: 1.8;">
                                        <p style="margin-bottom: 8px; color: #374151;"><strong>تاريخ آخر جلسة:</strong> <span style="color: #2563eb; font-weight: bold;">${detail.last_session_date}</span></p>
                                        <p style="margin-bottom: 8px; color: #374151;"><strong>نوع الزيارة:</strong> <span style="color: #059669; font-weight: bold;">${detail.last_visit_type}</span></p>
                                        <p style="margin-bottom: 8px; color: #374151;"><strong>اسم الجلسة:</strong> <span style="color: #7c3aed; font-weight: bold;">${detail.last_session_subject}</span></p>
                                    </div>
                                `,
                                icon: "info",
                                showCancelButton: true,
                                confirmButtonText: detail.confirm_button_text || "جلب البيانات",
                                cancelButtonText: detail.close_button_text || "إغلاق",
                                confirmButtonColor: "#2563eb",
                                cancelButtonColor: "#6b7280",
                                reverseButtons: true,
                                allowOutsideClick: false,
                                allowEscapeKey: false
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    Livewire.dispatch("fillGroupSessionDataFromAlert", { data: detail.record_data });
                                } else {
                                    window.location.href = detail.index_url;
                                }
                            });
                        });
                    </script>
                ', ['primaryColor' => app(GeneralSettings::class)->primary_color])
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

    private static function siteName(): string
    {
        $siteName = app(GeneralSettings::class)->site_name;

        if (str_contains($siteName, '╪') || str_contains($siteName, '┘')) {
            return self::FALLBACK_SITE_NAME;
        }

        return $siteName ?: self::FALLBACK_SITE_NAME;
    }
}
