<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user exported or imported an Excel file for one of the data modules.
 *
 * Excel actions do not fire model events, so the export/import call sites
 * dispatch this explicitly once the operation has succeeded. An import
 * reports the number of rows it wrote, which is what turns a 240-row upload
 * into a single summary notification instead of 240 create notifications.
 */
class ExcelActionOccurred
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $module,
        public readonly string $action,
        public readonly ?User $actor,
        public readonly ?int $recordCount = null,
    ) {
    }
}
