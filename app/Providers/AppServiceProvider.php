<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\PermissionCacheObserver;
use App\Support\FilamentFormValidation;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Global dashboard stylesheet (e.g. hiding the native number-input
        // spinner arrows). Registered once here so it loads on every Filament
        // page across all panels and modules.
        FilamentAsset::register([
            Css::make('dashboard-custom', resource_path('css/filament/admin/custom.css')),
        ]);

        TextInput::configureUsing(function (TextInput $component): void {
            $component
                ->live(onBlur: true)
                ->afterStateUpdated(function (Component $livewire, TextInput $field): void {
                    FilamentFormValidation::validateField($livewire, $field);
                });
        });

        Select::configureUsing(function (Select $component): void {
            $component
                ->live()
                ->afterStateUpdated(function (Component $livewire, Select $field): void {
                    FilamentFormValidation::validateField($livewire, $field);
                });
        });

        DatePicker::configureUsing(function (DatePicker $component): void {
            $component
                ->live(onBlur: true)
                ->afterStateUpdated(function (Component $livewire, DatePicker $field): void {
                    FilamentFormValidation::validateField($livewire, $field);
                });
        });

        // Super Admin gets all permissions automatically
        Gate::before(function ($user, $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });

        // Filament manages roles/permissions via relationship selects, which
        // sync the pivot tables directly and bypass Spatie's cache flush.
        // Flush the cached permission map whenever those assignments change so
        // revoked permissions (e.g. *.export) take effect immediately.
        $flush = fn () => app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::observe(PermissionCacheObserver::class);
        Permission::observe(PermissionCacheObserver::class);

        Role::saved($flush);
        User::saved($flush);

        // Super Admin notifications. The six data modules emit their own
        // events through App\Traits\NotifiesSuperAdminOnChange; only the
        // models outside those modules still need the observer.
        $auditedModels = [
            \App\Models\FollowUpChildVisit::class,
            \App\Models\User::class,
            \Spatie\Permission\Models\Role::class,
        ];

        foreach ($auditedModels as $modelClass) {
            $modelClass::observe(\App\Observers\AuditNotificationObserver::class);
        }

        Event::listen(
            \App\Events\RecordActionOccurred::class,
            [\App\Listeners\SendSuperAdminNotification::class, 'onRecordAction'],
        );

        Event::listen(
            \App\Events\ExcelActionOccurred::class,
            [\App\Listeners\SendSuperAdminNotification::class, 'onExcelAction'],
        );
    }
}
