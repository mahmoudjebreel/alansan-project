<?php

namespace App\Exports;

use RuntimeException;

/**
 * A CSV export that could not be written in full. It is never sent: a file
 * that looks complete and is not is worse than no file at all.
 */
class IncompleteCsvExportException extends RuntimeException
{
    public static function rows(int $expected, int $written): self
    {
        return new self(__('ui.csv_export.incomplete', [
            'expected' => $expected,
            'written' => $written,
        ]));
    }

    public static function corruptTicket(): self
    {
        return new self(__('ui.csv_export.corrupt'));
    }

    public static function unwritable(): self
    {
        return new self(__('ui.csv_export.unwritable'));
    }
}
