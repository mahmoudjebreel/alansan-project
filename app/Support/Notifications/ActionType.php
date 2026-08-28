<?php

namespace App\Support\Notifications;

/**
 * The data actions a Super Admin can be notified about, with the presentation
 * each one carries. Kept as one table so the notification, the settings page
 * and the log page can never disagree about labels, colours or priorities.
 */
final class ActionType
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    public const FORCE_DELETE = 'force_delete';

    public const EXPORT = 'export';

    public const IMPORT = 'import';

    /**
     * Note the titles for delete and force_delete both start with "حذف سجل",
     * which is what SuperAdminNotificationTest asserts on.
     *
     * @var array<string, array{title: string, verb: string, icon: string, color: string, priority: string}>
     */
    private const MAP = [
        self::CREATE => [
            'title' => 'إضافة سجل جديد',
            'verb' => 'بإضافة سجل جديد في',
            'icon' => 'heroicon-o-plus-circle',
            'color' => 'success',
            'priority' => 'low',
        ],
        self::UPDATE => [
            'title' => 'تعديل سجل',
            'verb' => 'بتعديل سجل في',
            'icon' => 'heroicon-o-pencil-square',
            'color' => 'warning',
            'priority' => 'medium',
        ],
        self::DELETE => [
            'title' => 'حذف سجل',
            'verb' => 'بحذف سجل من',
            'icon' => 'heroicon-o-trash',
            'color' => 'danger',
            'priority' => 'high',
        ],
        self::FORCE_DELETE => [
            'title' => 'حذف سجل نهائياً',
            'verb' => 'بالحذف النهائي لسجل من',
            'icon' => 'heroicon-o-fire',
            'color' => 'danger',
            'priority' => 'high',
        ],
        self::EXPORT => [
            'title' => 'تصدير ملف Excel',
            'verb' => 'بتصدير ملف Excel من',
            'icon' => 'heroicon-o-arrow-down-tray',
            'color' => 'info',
            'priority' => 'medium',
        ],
        self::IMPORT => [
            'title' => 'استيراد ملف Excel',
            'verb' => 'باستيراد ملف Excel إلى',
            'icon' => 'heroicon-o-arrow-up-tray',
            'color' => 'warning',
            'priority' => 'high',
        ],
    ];

    /**
     * @return array<string>
     */
    public static function all(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Action types that are grouped when they repeat in quick succession.
     * Export and import are already one-per-operation, so they never group.
     *
     * @return array<string>
     */
    public static function groupable(): array
    {
        return [self::CREATE, self::UPDATE, self::DELETE, self::FORCE_DELETE];
    }

    public static function exists(string $action): bool
    {
        return array_key_exists($action, self::MAP);
    }

    public static function title(string $action): string
    {
        return self::MAP[$action]['title'] ?? $action;
    }

    public static function verb(string $action): string
    {
        return self::MAP[$action]['verb'] ?? $action;
    }

    public static function icon(string $action): string
    {
        return self::MAP[$action]['icon'] ?? 'heroicon-o-bell';
    }

    public static function color(string $action): string
    {
        return self::MAP[$action]['color'] ?? 'gray';
    }

    public static function priority(string $action): string
    {
        return self::MAP[$action]['priority'] ?? 'low';
    }

    /**
     * Labels for the settings page and the log page filters.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $meta): string => $meta['title'], self::MAP);
    }
}
