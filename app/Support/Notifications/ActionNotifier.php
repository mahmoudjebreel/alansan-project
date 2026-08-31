<?php

namespace App\Support\Notifications;

use App\Jobs\DeliverDataActionNotification;
use App\Models\User;
use App\Settings\NotificationSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Decides whether a data action should notify the Super Admins, and how.
 *
 * Everything here runs after the original operation has already committed. It
 * is deliberately cheap: a settings read, a role check and a cache increment.
 * The actual notification is handed to the queue.
 */
final class ActionNotifier
{
    /** Cache prefix for the per-user/module/action debounce counters. */
    private const GROUP_PREFIX = 'superadmin-notif';

    /** Set while a bulk operation runs, so rows do not notify one by one. */
    private static bool $suppressed = false;

    /**
     * Run a bulk operation without emitting a notification per affected row.
     *
     * Used by the Excel import, which reports itself once with a row count
     * instead of once per row.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutRecordNotifications(callable $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }

    public static function isSuppressed(): bool
    {
        return self::$suppressed;
    }

    /**
     * A create/update/delete on a single record.
     */
    public static function record(Model $record, string $action, ?User $actor): void
    {
        if (self::$suppressed || ! self::shouldNotify($action, $actor)) {
            return;
        }

        self::queue(
            module: NotifiableModule::keyFor($record),
            moduleLabel: NotifiableModule::labelFor($record),
            action: $action,
            actor: $actor,
            recordLabel: NotifiableModule::recordLabel($record),
            referenceUrl: NotifiableModule::referenceUrl($record, $action),
        );
    }

    /**
     * An Excel export or import. Never grouped: one operation, one
     * notification, carrying the row count for an import.
     */
    public static function excel(string $moduleKey, string $action, ?User $actor, ?int $recordCount = null): void
    {
        // Deliberately not gated on self::$suppressed: the import summary is
        // the notification that replaces the suppressed per-row ones.
        if (! self::shouldNotify($action, $actor)) {
            return;
        }

        self::queue(
            module: $moduleKey,
            moduleLabel: NotifiableModule::labelFor($moduleKey),
            action: $action,
            actor: $actor,
            recordLabel: $recordCount === null ? null : $recordCount . ' سجل',
            referenceUrl: null,
            recordCount: $recordCount,
            groupable: false,
        );
    }

    /**
     * Master switch, per-action switch, and "only other users" rule.
     */
    private static function shouldNotify(string $action, ?User $actor): bool
    {
        // No authenticated user means a seeder, a console command or a test
        // factory — not something a Super Admin needs to hear about.
        if ($actor === null) {
            return false;
        }

        if (! ActionType::exists($action)) {
            return false;
        }

        $settings = self::settings();

        // Exclude Super Admin's own actions unless notify_self_actions is enabled.
        if ($actor->hasRole('Super Admin') && ! ($settings['notify_self_actions'] ?? false)) {
            return false;
        }

        return $settings['enabled']
            && in_array($action, $settings['enabled_actions'], true)
            && self::recipients() !== [];
    }

    /**
     * Hand the notification to the queue, debouncing repeats by the same user
     * on the same module and action.
     */
    private static function queue(
        string $module,
        string $moduleLabel,
        string $action,
        User $actor,
        ?string $recordLabel,
        ?string $referenceUrl,
        ?int $recordCount = null,
        bool $groupable = true,
    ): void {
        $window = self::settings()['group_window_seconds'];
        $group = $groupable
            && $window > 0
            && in_array($action, ActionType::groupable(), true);

        if (! $group) {
            DeliverDataActionNotification::dispatch(
                self::describe($module, $moduleLabel, $action, $actor, $recordLabel, $referenceUrl, $recordCount),
                null,
            );

            return;
        }

        $key = self::groupKey($actor, $module, $action);
        $count = self::increment($key, $window);

        // Only the first action in the window schedules delivery; the ones
        // after it just raise the counter that delivery will read.
        if ($count === 1) {
            DeliverDataActionNotification::dispatch(
                self::describe($module, $moduleLabel, $action, $actor, $recordLabel, $referenceUrl, $recordCount),
                $key,
            )->delay(now()->addSeconds($window));
        }
    }

    /**
     * The notification payload, before the group count is known.
     *
     * @return array<string, mixed>
     */
    private static function describe(
        string $module,
        string $moduleLabel,
        string $action,
        User $actor,
        ?string $recordLabel,
        ?string $referenceUrl,
        ?int $recordCount,
    ): array {
        return [
            'actor_id' => $actor->getKey(),
            'actor_name' => $actor->name,
            'actor_role' => $actor->getRoleNames()->first() ?? '-',
            'action_type' => $action,
            'module' => $module,
            'module_label' => $moduleLabel,
            'record_label' => $recordLabel,
            'record_count' => $recordCount,
            'occurred_at' => now()->toDateTimeString(),
            'reference_url' => $referenceUrl,
            'priority' => ActionType::priority($action),
        ];
    }

    /**
     * Atomically count one action into the current window.
     */
    private static function increment(string $key, int $window): int
    {
        // add() only succeeds for the first action of a window, and is what
        // sets the TTL; increment() afterwards keeps that same TTL.
        if (Cache::add($key, 1, $window)) {
            return 1;
        }

        $value = Cache::increment($key);

        return is_int($value) && $value > 0 ? $value : (int) Cache::get($key, 1);
    }

    public static function groupKey(User $actor, string $module, string $action): string
    {
        return self::GROUP_PREFIX . ':' . $actor->getKey() . ':' . $module . ':' . $action;
    }

    /**
     * Ids of the Super Admins configured to receive notifications.
     *
     * @return array<int>
     */
    public static function recipients(): array
    {
        $selected = self::settings()['recipient_user_ids'];

        return User::role('Super Admin')
            ->when($selected !== [], fn ($query) => $query->whereIn('id', $selected))
            ->pluck('id')
            ->all();
    }

    /**
     * Settings, with defaults if the settings row is not there yet — the
     * notification system must never be what breaks a request.
     *
     * @return array{enabled: bool, enabled_actions: array<string>, recipient_user_ids: array<int>, group_window_seconds: int}
     */
    private static function settings(): array
    {
        try {
            $settings = app(NotificationSettings::class);

            return [
                'enabled' => $settings->enabled,
                'notify_self_actions' => $settings->notify_self_actions ?? false,
                'enabled_actions' => $settings->enabled_actions,
                'recipient_user_ids' => $settings->recipient_user_ids,
                'group_window_seconds' => $settings->group_window_seconds,
            ];
        } catch (\Throwable) {
            return [
                'enabled' => true,
                'notify_self_actions' => false,
                'enabled_actions' => ActionType::all(),
                'recipient_user_ids' => [],
                'group_window_seconds' => 0,
            ];
        }
    }
}
