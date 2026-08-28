<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Backfills the `notify_self_actions` key for the notification settings.
 *
 * The property was added to App\Settings\NotificationSettings after
 * CreateNotificationSettings had already run, so existing installs have no
 * stored value for it. The class default keeps reads working, but
 * spatie/laravel-settings treats a default-loaded property as missing when
 * saving, which made the settings page fail with MissingSettings.
 *
 * The default is false, matching the class default: a Super Admin's own
 * actions do not notify unless the toggle is switched on.
 */
class AddNotifySelfActionsToNotificationSettings extends SettingsMigration
{
    public function up(): void
    {
        // Guarded so installs where the key was already added by hand migrate cleanly.
        if (! $this->migrator->exists('notifications.notify_self_actions')) {
            $this->migrator->add('notifications.notify_self_actions', false);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('notifications.notify_self_actions');
    }
}
