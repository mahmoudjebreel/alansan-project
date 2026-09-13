<?php

namespace App\Console\Commands;

use App\Support\Activity\TelemetryPruner;
use Illuminate\Console\Command;

/**
 * Deletes page-visit and session telemetry older than the retention period.
 *
 *     php artisan user-activity:prune            # config retention, 90 days
 *     php artisan user-activity:prune --days=30
 *
 * Never touches activity_log. This host has no scheduler, so the same job is
 * offered as a button on the User Activity page.
 */
class PruneUserActivity extends Command
{
    protected $signature = 'user-activity:prune {--days= : Delete telemetry older than this many days}';

    protected $description = 'Delete page-visit and session telemetry older than the retention period';

    public function handle(): int
    {
        $days = $this->option('days');

        $result = TelemetryPruner::prune(is_numeric($days) ? (int) $days : null);

        $this->info(sprintf(
            'Deleted %d page visits and %d sessions; marked %d sessions as expired.',
            $result['visits'],
            $result['sessions'],
            $result['expired'],
        ));

        return self::SUCCESS;
    }
}
