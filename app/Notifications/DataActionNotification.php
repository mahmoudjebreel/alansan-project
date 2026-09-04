<?php

namespace App\Notifications;

use App\Support\Notifications\ActionType;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

/**
 * One Super Admin notification about a data action.
 *
 * Sent inline: the only channel is the database, so delivery is a single
 * insert and a queue round-trip would cost more than it saves - and would
 * make the bell depend on a worker being up. Adding 'mail' or 'broadcast'
 * later is a matter of returning them from via() and making the class
 * ShouldQueue again, because the payload is already assembled independently
 * of the channel.
 */
class DataActionNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $payload  See ActionPayload::build().
     */
    public function __construct(private readonly array $payload)
    {
    }

    /**
     * @return array<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Filament reads the well-known keys (title, body, icon, color, ...) to
     * render the bell panel. The module/action/actor keys are extra and are
     * ignored by Filament, but they are what the Notification Log page filters
     * and exports on.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return array_merge(
            $this->filamentMessage(),
            $this->payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function filamentMessage(): array
    {
        $action = $this->payload['action_type'];

        $notification = FilamentNotification::make()
            ->title($this->payload['title'])
            ->body($this->payload['body'])
            ->icon(ActionType::icon($action))
            ->iconColor(ActionType::color($action))
            ->color(ActionType::color($action));

        if (filled($this->payload['reference_url'] ?? null)) {
            $notification->actions([
                Action::make('view')
                    ->label(__('ui.notifications.view_record'))
                    ->url($this->payload['reference_url'])
                    ->markAsRead(),
            ]);
        }

        return $notification->getDatabaseMessage();
    }
}
