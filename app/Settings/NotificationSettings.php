<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Runtime configuration for the Super Admin data-action notifications.
 *
 * Everything here is read at dispatch time, so toggling a setting takes effect
 * on the very next action without a deploy.
 */
class NotificationSettings extends Settings
{
    /** Master on/off switch for the whole notification system. */
    public bool $enabled;

    /** Whether actions performed by a Super Admin should also trigger notifications. */
    public bool $notify_self_actions = false;

    /**
     * Action types that are allowed to notify.
     *
     * @var array<string> subset of App\Support\Notifications\ActionType::all()
     */
    public array $enabled_actions;

    /**
     * Which users receive notifications. Any user can be named here, not only
     * a Super Admin - naming an Admin is how that Admin starts receiving them.
     * Empty means "every Super Admin", which is also the default.
     *
     * @var array<int>
     */
    public array $recipient_user_ids;

    /**
     * Debounce window, in seconds, for repeated create/update/delete actions
     * by the same user on the same module. 0 disables grouping and sends every
     * action on its own.
     */
    public int $group_window_seconds;

    public static function group(): string
    {
        return 'notifications';
    }
}
