<?php

namespace App\Filament\Actions;

use App\Support\BulkRecordWriter;
use Filament\Actions\RestoreBulkAction;
use Throwable;

/**
 * Set-based counterpart of Filament's restore-selected action.
 *
 * @see \App\Filament\Actions\FastDeleteBulkAction
 */
class FastRestoreBulkAction extends RestoreBulkAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->fetchSelectedRecords(false);

        $this->action(function (): void {
            $this->process(static function (FastRestoreBulkAction $action): void {
                try {
                    $action->reportBulkProcessingSuccessfulRecordsCount(
                        BulkRecordWriter::restore($action->getSelectedRecordsQuery()),
                    );
                } catch (Throwable $exception) {
                    $action->reportCompleteBulkProcessingFailure();

                    report($exception);
                }
            });
        });
    }
}
