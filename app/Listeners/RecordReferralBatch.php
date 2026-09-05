<?php

namespace App\Listeners;

use App\Events\ExcelActionOccurred;
use App\Models\Child;
use App\Models\ReferralBatch;
use App\Support\Notifications\ActionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Notes which children a completed Children upload wrote, so the Referral
 * Centre can show that upload on its own.
 *
 * Deliberately an after-the-fact listener rather than anything inside the
 * import. By the time this runs the import's transaction has committed and
 * its rows are safe; this cannot roll them back, cannot slow the upload's
 * per-row work, and swallows its own failures - a batch that fails to record
 * costs the Referral Centre one convenience filter and costs the upload
 * nothing at all.
 *
 * The window is derived from the row count the import reports. The import is
 * strict all-or-nothing and commits in one transaction, so the rows it wrote
 * are the last `imported` children by primary key.
 */
class RecordReferralBatch
{
    public function onExcelAction(ExcelActionOccurred $event): void
    {
        if ($event->action !== ActionType::IMPORT) {
            return;
        }

        // The module key notifications use is the model's base class name.
        if ($event->module !== class_basename(Child::class)) {
            return;
        }

        $imported = (int) ($event->recordCount ?? 0);

        if ($imported < 1) {
            return;
        }

        try {
            // Only the two ends of the window are wanted, so they are read as
            // two aggregates over a derived table rather than by pulling a
            // six-figure upload's worth of primary keys into memory.
            $bounds = DB::query()
                ->fromSub(
                    Child::query()->select('id')->orderByDesc('id')->limit($imported),
                    'window',
                )
                ->selectRaw('min(id) as first_record_id, max(id) as last_record_id')
                ->first();

            if ($bounds === null || $bounds->first_record_id === null) {
                return;
            }

            ReferralBatch::create([
                'user_id' => $event->actor?->getKey(),
                'module' => ReferralBatch::CHILDREN_MODULE,
                'imported_count' => $imported,
                'first_record_id' => (int) $bounds->first_record_id,
                'last_record_id' => (int) $bounds->last_record_id,
            ]);
        } catch (\Throwable $e) {
            // The upload has already succeeded and been reported to the user.
            // Referral tracking is the only thing lost here, and the Referral
            // Centre still lists every eligible child without it.
            Log::warning('Referral batch could not be recorded: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
