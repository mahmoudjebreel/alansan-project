<?php

namespace App\Support\Activity;

/**
 * Seconds as a short, translated phrase for a listing: "45 s", "12 min",
 * "1 h 05 min". Rounded on purpose - the figure it describes is approximate.
 */
final class Duration
{
    public static function humanize(?int $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        if ($seconds < 60) {
            return __('ui.user_activity.duration.seconds', ['count' => $seconds]);
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return __('ui.user_activity.duration.minutes', ['count' => $minutes]);
        }

        return __('ui.user_activity.duration.hours', [
            'hours' => intdiv($minutes, 60),
            'minutes' => str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT),
        ]);
    }
}
