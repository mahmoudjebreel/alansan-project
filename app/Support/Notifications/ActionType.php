<?php

namespace App\Support\Notifications;

/**
 * The data actions a Super Admin can be notified about, with the presentation
 * each one carries. Kept as one table so the notification, the settings page
 * and the log page can never disagree about colours or priorities.
 *
 * The wording lives in lang/*\/ui.php under `notifications.actions`, keyed by
 * the same action strings, so a notification reads in whichever language the
 * panel is set to rather than always in Arabic.
 */
final class ActionType
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    public const FORCE_DELETE = 'force_delete';

    public const EXPORT = 'export';

    public const PDF_EXPORT = 'pdf_export';

    public const IMPORT = 'import';

    /**
     * Presentation that does not change with the language.
     *
     * @var array<string, array{icon: string, color: string, priority: string}>
     */
    private const MAP = [
        self::CREATE => [
            'icon' => 'heroicon-o-plus-circle',
            'color' => 'success',
            'priority' => 'low',
        ],
        self::UPDATE => [
            'icon' => 'heroicon-o-pencil-square',
            'color' => 'warning',
            'priority' => 'medium',
        ],
        self::DELETE => [
            'icon' => 'heroicon-o-trash',
            'color' => 'danger',
            'priority' => 'high',
        ],
        self::FORCE_DELETE => [
            'icon' => 'heroicon-o-fire',
            'color' => 'danger',
            'priority' => 'high',
        ],
        self::EXPORT => [
            'icon' => 'heroicon-o-arrow-down-tray',
            'color' => 'info',
            'priority' => 'medium',
        ],
        self::PDF_EXPORT => [
            'icon' => 'heroicon-o-document-arrow-down',
            'color' => 'info',
            'priority' => 'medium',
        ],
        self::IMPORT => [
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
     * Exports and imports are already one-per-operation, so they never group.
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

    /**
     * Note the Arabic titles for delete and force_delete both start with
     * "حذف سجل", which is what SuperAdminNotificationTest asserts on.
     */
    public static function title(string $action): string
    {
        return self::exists($action)
            ? __('ui.notifications.actions.' . $action . '.title')
            : $action;
    }

    public static function verb(string $action): string
    {
        return self::exists($action)
            ? __('ui.notifications.actions.' . $action . '.verb')
            : $action;
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
        $options = [];

        foreach (self::all() as $action) {
            $options[$action] = self::title($action);
        }

        return $options;
    }
}
