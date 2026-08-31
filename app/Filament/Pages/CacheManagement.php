<?php

namespace App\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Manual cache maintenance for Super Admins.
 *
 * Every clear is triggered by an explicit button press — nothing here is ever
 * invoked automatically by the scheduler, a save, or a deployment step.
 */
class CacheManagement extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationLabel = 'إدارة الكاش';

    protected static string | \UnitEnum | null $navigationGroup = 'إدارة النظام';

    protected static ?string $title = 'إدارة الكاش';

    protected static ?int $navigationSort = 22;

    protected string $view = 'filament.pages.cache-management';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('cache.manage') ?? false;
    }

    /**
     * Central registry of every cache type exposed on this page.
     *
     * A null `command` marks a type that is cleared programmatically rather
     * than through an Artisan command.
     *
     * @return array<string, array{label: string, description: string, icon: string, color: string, command: ?string}>
     */
    public static function cacheTypes(): array
    {
        return [
            'application' => [
                'label' => 'كاش التطبيق العام',
                'description' => 'يمسح البيانات المخزَّنة مؤقتاً في نظام الكاش العام (cache:clear).',
                'icon' => 'heroicon-o-server-stack',
                'color' => 'primary',
                'command' => 'cache:clear',
            ],
            'config' => [
                'label' => 'كاش الإعدادات',
                'description' => 'يمسح ملف إعدادات النظام المجمَّع (config:clear).',
                'icon' => 'heroicon-o-cog-6-tooth',
                'color' => 'info',
                'command' => 'config:clear',
            ],
            'view' => [
                'label' => 'كاش الفيوهات',
                'description' => 'يمسح ملفات العرض المُصرَّفة مسبقاً (view:clear).',
                'icon' => 'heroicon-o-document-text',
                'color' => 'warning',
                'command' => 'view:clear',
            ],
            'route' => [
                'label' => 'كاش الروابط',
                'description' => 'يمسح ملف الروابط المجمَّع (route:clear).',
                'icon' => 'heroicon-o-link',
                'color' => 'gray',
                'command' => 'route:clear',
            ],
            'permissions' => [
                'label' => 'كاش الصلاحيات والأدوار',
                'description' => 'يجعل أي تعديل على الأدوار أو الصلاحيات ينعكس فوراً على المستخدمين دون انتظار انتهاء صلاحية الكاش.',
                'icon' => 'heroicon-o-shield-check',
                'color' => 'success',
                'command' => null,
            ],
        ];
    }

    /**
     * Clear a single cache type. Returns false so the front-end can surface an
     * error toast when the operation fails.
     */
    public function clearCache(string $type): bool
    {
        // Authorization is resolved before anything is flushed, so a user can
        // never reach the permission-cache flush in order to widen their own
        // access.
        abort_unless(static::canAccess(), 403);

        $types = static::cacheTypes();

        if (! array_key_exists($type, $types)) {
            Notification::make()
                ->title('نوع كاش غير معروف')
                ->body('لا يمكن تنفيذ العملية المطلوبة.')
                ->danger()
                ->send();

            return false;
        }

        try {
            $this->runClear($type);
        } catch (Throwable $e) {
            Notification::make()
                ->title('فشل مسح ' . $types[$type]['label'])
                ->body($e->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()
            ->title('تم مسح ' . $types[$type]['label'] . ' بنجاح')
            ->success()
            ->send();

        return true;
    }

    /**
     * Clear every registered cache type in sequence within this same request,
     * then report a single summary notification.
     */
    public function clearAll(): bool
    {
        abort_unless(static::canAccess(), 403);

        $cleared = [];
        $failed = [];

        foreach (static::cacheTypes() as $type => $definition) {
            try {
                $this->runClear($type);
                $cleared[] = $definition['label'];
            } catch (Throwable $e) {
                $failed[] = $definition['label'] . ' (' . $e->getMessage() . ')';
            }
        }

        if ($failed !== []) {
            Notification::make()
                ->title('اكتمل المسح مع وجود أخطاء')
                ->body(
                    ($cleared === [] ? '' : 'تم مسح: ' . implode('، ', $cleared) . '. ')
                    . 'تعذّر مسح: ' . implode('، ', $failed) . '.'
                )
                ->danger()
                ->persistent()
                ->send();

            return false;
        }

        Notification::make()
            ->title('تم مسح كل أنواع الكاش بنجاح')
            ->body('تم مسح: ' . implode('، ', $cleared) . '.')
            ->success()
            ->send();

        return true;
    }

    /**
     * Run the clear for one type, throwing when it does not succeed.
     */
    protected function runClear(string $type): void
    {
        if ($type === 'permissions') {
            $this->clearPermissionCache();

            return;
        }

        $command = static::cacheTypes()[$type]['command'];

        if (Artisan::call($command) !== 0) {
            throw new RuntimeException("انتهى الأمر [{$command}] بحالة فشل.");
        }
    }

    /**
     * Flush the spatie/laravel-permission cache.
     *
     * This version of the package ships a `permission:cache-reset` command, so
     * it is preferred. That command always exits with 0 — even when the flush
     * fails — so the outcome is verified against the registrar cache instead of
     * trusting its exit code. Only the cached copy is dropped; the `roles` and
     * `permissions` tables are never touched.
     */
    protected function clearPermissionCache(): void
    {
        $registrar = app(PermissionRegistrar::class);

        if (array_key_exists('permission:cache-reset', Artisan::all())) {
            Artisan::call('permission:cache-reset');

            if ($registrar->getCacheRepository()->has($registrar->cacheKey)) {
                throw new RuntimeException('ما زال كاش الصلاحيات موجوداً بعد محاولة المسح.');
            }

            return;
        }

        if (! $registrar->forgetCachedPermissions()) {
            throw new RuntimeException('تعذّر مسح كاش الصلاحيات والأدوار.');
        }
    }
}
