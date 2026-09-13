<?php

namespace App\Listeners;

use App\Events\ExcelActionOccurred;
use App\Support\Activity\AuditEvents;

/**
 * Writes an activity_log row for each Excel import and export.
 *
 * The list pages and the import already announce themselves through
 * ExcelActionOccurred for the Super Admin notifications; this listens to the
 * same event, so the call sites are not touched and the notifications are
 * unaffected. Runs after the event, cannot influence the export or import,
 * and the audit writer swallows its own failures.
 *
 * Not named handle(): see RecordAuthActivity for why.
 */
class RecordExcelActivity
{
    public function onExcelAction(ExcelActionOccurred $event): void
    {
        AuditEvents::excel(
            $event->module,
            $event->action,
            $event->actor,
            $event->recordCount,
        );
    }
}
