<?php

use App\Support\Notifications\ActionType;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Defaults for the Super Admin data-action notifications.
 *
 * The system starts switched on for every action, notifying every Super Admin,
 * with a 60 second grouping window so a burst of edits by one user collapses
 * into a single notification.
 */
class CreateNotificationSettings extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('notifications.enabled', true);
        $this->migrator->add('notifications.enabled_actions', ActionType::all());
        $this->migrator->add('notifications.recipient_user_ids', []);
        $this->migrator->add('notifications.group_window_seconds', 60);
    }

    public function down(): void
    {
        $this->migrator->delete('notifications.enabled');
        $this->migrator->delete('notifications.enabled_actions');
        $this->migrator->delete('notifications.recipient_user_ids');
        $this->migrator->delete('notifications.group_window_seconds');
    }
}
