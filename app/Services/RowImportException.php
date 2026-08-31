<?php

namespace App\Services;

/**
 * Raised when a row cannot be written, so the surrounding transaction rolls
 * back and the failure is reported against its sheet row number.
 */
class RowImportException extends \RuntimeException
{
}
