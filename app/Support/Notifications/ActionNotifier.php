<?php

namespace App\Support\Notifications;

use App\Models\User;
use App\Settings\NotificationSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Decides whether a data action should notify the Super Admins, and how.
 *
 * Everything here runs after the original operation has already committed. It
 * is deliberately cheap: a settings read, a role check and a cache increment.
 * The notification itself is written by DataActionDelivery, synchronously:
 * one row in the `notifications` table costs less than the queue plumbing
 * that used to carry it, and it never depends on a worker being up.
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

        self::deliver(
            module: NotifiableModule::keyFor($record),
            moduleLabel: NotifiableModule::labelFor($record),
            action: $action,
            actor: $actor,
            recordLabel: NotifiableModule::recordLabel($record),
            referenceUrl: NotifiableModule::referenceUrl($record, $action),
        );
    }

    /**
     * One bulk operation over many records of a single module.
     *
     * The per-record notifications are suppressed while a bulk delete runs, so
     * this is the notification that replaces them: it names the module and the
     * number of rows instead of pretending 5,000 deletes were one record. Like
     * the Excel summary it is deliberately not gated on self::$suppressed and
     * never grouped - it already is the group.
     */
    public static function bulk(Model | string $module, string $action, ?User $actor, int $recordCount): void
    {
        if (! self::shouldNotify($action, $actor)) {
            return;
        }

        self::deliver(
            module: NotifiableModule::keyFor($module),
            moduleLabel: NotifiableModule::labelFor($module),
            action: $action,
            actor: $actor,
            recordLabel: __('ui.notifications.record_count', ['count' => $recordCount]),
            referenceUrl: null,
            recordCount: $recordCount,
            groupable: false,
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

        self::deliver(
            module: $moduleKey,
            moduleLabel: NotifiableModule::labelFor($moduleKey),
            action: $action,
            actor: $actor,
            recordLabel: $recordCount === null ? null : __('ui.notifications.record_count', ['count' => $recordCount]),
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
     * Write the notification, debouncing repeats by the same user on the same
     * module and action.
     */
    private static function deliver(
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

        $payload = self::describe($module, $moduleLabel, $action, $actor, $recordLabel, $referenceUrl, $recordCount);

        if (! $group) {
            DataActionDelivery::send($payload);

            return;
        }

        $key = self::groupKey($actor, $module, $action);
        $count = self::increment($key, $window);

        // The first action of a window delivers straight away; every later one
        // folds itself into that same notification, so the recipient sees it
        // immediately and watches the count climb.
        if ($count === 1) {
            DataActionDelivery::send($payload, $key);

            return;
        }

        DataActionDelivery::collapse($key, $count);
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
     * Ids of the Super Admins who should be told about this actor's action.
     *
     * A Super Admin who is also the actor is dropped, so the bell does not
     * report back to someone what they just did themselves - unless they asked
     * for exactly that with notify_self_actions.
     *
     * @return array<int>
     */
    public static function recipientsFor(int | string | null $actorId): array
    {
        $recipients = self::recipients();

        if ($actorId === null || (self::settings()['notify_self_actions'] ?? false)) {
            return $recipients;
        }

        return array_values(array_filter(
            $recipients,
            fn ($id): bool => (string) $id !== (string) $actorId,
        ));
    }

    /**
     * Ids of the users configured to receive notifications.
     *
     * A named list wins outright and is not filtered by role: picking an Admin
     * on the settings page is how that Admin starts receiving the panel's
     * action notifications, which is the whole point of the setting. Leaving
     * the list empty keeps the original default - every Super Admin - so an
     * installation that never touches the setting behaves as it always did.
     *
     * The ids are read back through the users table rather than returned as
     * stored, so a recipient who has since been deleted drops out instead of
     * being handed to Notification::send() as a missing model.
     *
     * @return array<int>
     */
    public static function recipients(): array
    {
        $selected = self::settings()['recipient_user_ids'];

        if ($selected !== []) {
            return User::whereIn('id', $selected)->pluck('id')->all();
        }

        return User::role('Super Admin')->pluck('id')->all();
    }

    /**
     * Settings, with defaults if the settings row is not there yet — the
     * notification system must never be what breaks a request.
     *
     * @return array{enabled: bool, notify_self_actions: bool, enabled_actions: array<string>, recipient_user_ids: array<int>, group_window_seconds: int}
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
