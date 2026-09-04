<?php

namespace App\Filament\Actions;

use App\Support\BulkRecordWriter;
use Filament\Actions\ForceDeleteBulkAction;
use Throwable;

/**
 * Set-based counterpart of Filament's force-delete-selected action.
 *
 * @see \App\Filament\Actions\FastDeleteBulkAction
 */
class FastForceDeleteBulkAction extends ForceDeleteBulkAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->fetchSelectedRecords(false);

        $this->action(function (): void {
            $this->process(static function (FastForceDeleteBulkAction $action): void {
                try {
                    $action->reportBulkProcessingSuccessfulRecordsCount(
                        BulkRecordWriter::forceDelete($action->getSelectedRecordsQuery()),
                    );
                } catch (Throwable $exception) {
                    $action->reportCompleteBulkProcessingFailure();

                    report($exception);
                }
            });
        });
    }
}
