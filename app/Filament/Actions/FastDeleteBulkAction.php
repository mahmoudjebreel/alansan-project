<?php

namespace App\Filament\Actions;

use App\Support\BulkRecordWriter;
use Filament\Actions\DeleteBulkAction;
use Throwable;

/**
 * Filament's delete-selected action, rewritten to work on the selection as a
 * set instead of as hydrated models.
 *
 * The stock action loads every selected record and calls delete() on each one,
 * which on these modules costs three extra writes per row (activity log,
 * notification job, and the delete itself). Deleting a few thousand rows that
 * way times out. This one hands the selection query to BulkRecordWriter, which
 * issues one statement per chunk and records a single summary.
 *
 * @see \App\Support\BulkRecordWriter
 */
class FastDeleteBulkAction extends DeleteBulkAction
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep the selection as a query. Fetching it would defeat the point.
        $this->fetchSelectedRecords(false);

        $this->action(function (): void {
            $this->process(static function (FastDeleteBulkAction $action): void {
                try {
                    $action->reportBulkProcessingSuccessfulRecordsCount(
                        BulkRecordWriter::softDelete($action->getSelectedRecordsQuery()),
                    );
                } catch (Throwable $exception) {
                    $action->reportCompleteBulkProcessingFailure();

                    report($exception);
                }
            });
        });
    }
}
