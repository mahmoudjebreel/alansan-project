<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\DataActionNotification;
use App\Support\Notifications\ActionNotifier;
use App\Support\Notifications\ActionType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Delivers one Super Admin notification, after the debounce window has passed.
 *
 * Running the count read here rather than inside the notification matters:
 * the count has to be resolved once for the whole batch, not once per
 * recipient, or the second Super Admin would see a counter that the first one
 * already cleared.
 */
class DeliverDataActionNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     * @param  string|null  $groupKey  Cache key holding how many actions the
     *                                 window collected; null when ungrouped.
     */
    public function __construct(
        private readonly array $payload,
        private readonly ?string $groupKey,
    ) {
    }

    public function handle(): void
    {
        $recipients = User::whereIn('id', ActionNotifier::recipients())->get();

        if ($recipients->isEmpty()) {
            $this->releaseGroup();

            return;
        }

        Notification::send($recipients, new DataActionNotification($this->finalPayload()));
    }

    /**
     * @return array<string, mixed>
     */
    private function finalPayload(): array
    {
        $payload = $this->payload;
        $count = $this->releaseGroup() ?? $payload['record_count'] ?? 1;

        $payload['record_count'] = $count;
        $payload['title'] = ActionType::title($payload['action_type']);
        $payload['body'] = $this->body($payload, $count);

        // A grouped notification covers several records, so the link to the
        // first one would be misleading.
        if ($count > 1) {
            $payload['record_label'] = null;
            $payload['reference_url'] = null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function body(array $payload, int $count): string
    {
        $actor = $payload['actor_name'];
        $role = $payload['actor_role'];
        $verb = ActionType::verb($payload['action_type']);
        $module = $payload['module_label'];

        if ($count > 1) {
            return "قام المستخدم ({$actor} - {$role}) {$verb} قسم [{$module}] — {$count} سجلات";
        }

        $label = $payload['record_label'] ?? null;

        return filled($label)
            ? "قام المستخدم ({$actor} - {$role}) {$verb} قسم [{$module}] — {$label}"
            : "قام المستخدم ({$actor} - {$role}) {$verb} قسم [{$module}]";
    }

    /**
     * Read and clear the window counter. Null when this was never grouped.
     */
    private function releaseGroup(): ?int
    {
        if ($this->groupKey === null) {
            return null;
        }

        $count = (int) Cache::get($this->groupKey, 1);
        Cache::forget($this->groupKey);

        return max(1, $count);
    }
}
