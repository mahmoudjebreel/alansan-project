<?php

namespace App\Imports;

/**
 * Bulk Excel import for the Individual Counseling module.
 *
 * Column structure, validation rules and option lists all come from the
 * shared engine, which reads them from the module's Export class and
 * Filament form. Nothing module-specific needs to be repeated here.
 */
class IndividualCounselingImport extends AbstractTableImport
{
    protected function moduleKey(): string
    {
        return 'individual_counseling';
    }
}
