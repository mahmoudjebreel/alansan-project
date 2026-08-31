<?php

namespace App\Listeners;

use App\Events\ExcelActionOccurred;
use App\Events\RecordActionOccurred;
use App\Support\Notifications\ActionNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Turns a data-action event into Super Admin notifications.
 *
 * The methods are deliberately not named handle*(): Laravel's event
 * auto-discovery picks those up by convention, which would register them a
 * second time on top of the explicit Event::listen() calls in
 * AppServiceProvider and send every notification twice.
 *
 * Runs synchronously and does almost nothing: the decision is cheap and the
 * delivery is queued. It also swallows its own failures, because an audit
 * notification must never be the reason a user's save appears to fail.
 */
class SendSuperAdminNotification
{
    public function onRecordAction(RecordActionOccurred $event): void
    {
        $this->guard(fn () => ActionNotifier::record(
            $event->record,
            $event->action,
            $event->actor,
        ));
    }

    public function onExcelAction(ExcelActionOccurred $event): void
    {
        $this->guard(fn () => ActionNotifier::excel(
            $event->module,
            $event->action,
            $event->actor,
            $event->recordCount,
        ));
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('Super Admin notification could not be queued: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
