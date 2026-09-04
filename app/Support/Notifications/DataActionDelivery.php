<?php

namespace App\Support\Notifications;

use App\Models\User;
use App\Notifications\DataActionNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Writes the Super Admin notifications for one data action.
 *
 * Delivery is deliberately synchronous. A notification here is a single row in
 * the `notifications` table, so handing it to a queue bought nothing except a
 * dependency on a worker process that has to be running for any of it to
 * arrive at all - and when that worker is not running, the jobs pile up in the
 * `jobs` table and the recipient is never told anything.
 *
 * Grouping is what used to need the queue: repeated actions were collected in
 * a cache counter and one delayed job read it once the window closed. It is
 * done the other way round now - the first action delivers immediately and
 * every later action in the window folds itself into that same notification
 * via collapse(). The recipient sees the notification at once and watches its
 * count climb, instead of seeing nothing for a minute.
 *
 * @see ActionNotifier for the rules deciding whether an action notifies at all
 */
final class DataActionDelivery
{
    /**
     * Deliver a new notification to every configured recipient.
     *
     * @param  array<string, mixed>  $payload  From ActionNotifier::describe()
     * @param  string|null  $groupKey  Cache key of the window this notification
     *                                 owns, so collapse() can find it again.
     */
    public static function send(array $payload, ?string $groupKey = null): void
    {
        $recipients = User::whereIn('id', ActionNotifier::recipientsFor($payload['actor_id']))->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $payload['group_key'] = $groupKey;

        Notification::send(
            $recipients,
            new DataActionNotification(self::render($payload, $payload['record_count'] ?? 1)),
        );
    }

    /**
     * Fold one more action into the notification the window already delivered.
     *
     * Re-rendering from the stored payload rather than patching the body keeps
     * the notification identical to one that had been delivered with this
     * count from the start. Marking it unread again is what brings it back to
     * the top of the bell panel.
     */
    public static function collapse(string $groupKey, int $count): void
    {
        DatabaseNotification::query()
            ->where('data->group_key', $groupKey)
            ->get()
            ->each(function (DatabaseNotification $notification) use ($count): void {
                $payload = self::render($notification->data, $count);

                $notification->forceFill([
                    'data' => (new DataActionNotification($payload))->toDatabase($notification),
                    'read_at' => null,
                ])->save();
            });
    }

    /**
     * Resolve the parts of the payload that depend on how many records the
     * notification ended up covering.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function render(array $payload, int $count): array
    {
        $count = max(1, $count);

        $payload['record_count'] = $count;

        // A summary covers several records, so a label or a link pointing at
        // one of them would be misleading.
        if ($count > 1) {
            $payload['record_label'] = null;
            $payload['reference_url'] = null;
        }

        $payload['title'] = ActionType::title($payload['action_type']);
        $payload['body'] = self::body($payload, $count);

        return $payload;
    }

    /**
     * The one-line summary the bell shows.
     *
     * Three whole sentences rather than one with pieces glued on: a language
     * does not necessarily put the count, the label and the module in the
     * order Arabic does, so each variant is translated in one go.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function body(array $payload, int $count): string
    {
        $replacements = [
            'actor' => $payload['actor_name'],
            'role' => $payload['actor_role'],
            'verb' => ActionType::verb($payload['action_type']),
            'module' => $payload['module_label'],
        ];

        if ($count > 1) {
            return __('ui.notifications.body_with_count', $replacements + ['count' => $count]);
        }

        $label = $payload['record_label'] ?? null;

        return filled($label)
            ? __('ui.notifications.body_with_label', $replacements + ['label' => $label])
            : __('ui.notifications.body', $replacements);
    }
}
