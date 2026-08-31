<?php

namespace App\Imports;

/**
 * Bulk Excel import for the Follow Up Child module (includes Visit 1-16 columns).
 *
 * Column structure, validation rules and option lists all come from the
 * shared engine, which reads them from the module's Export class and
 * Filament form. Nothing module-specific needs to be repeated here.
 */
class FollowUpChildImport extends AbstractTableImport
{
    protected function moduleKey(): string
    {
        return 'follow_up_children';
    }
}
