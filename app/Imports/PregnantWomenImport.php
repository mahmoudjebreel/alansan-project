<?php

namespace App\Imports;

/**
 * Bulk Excel import for the Pregnant/Lactating Women module.
 *
 * Column structure, validation rules and option lists all come from the
 * shared engine, which reads them from the module's Export class and
 * Filament form. Nothing module-specific needs to be repeated here.
 */
class PregnantWomenImport extends AbstractTableImport
{
    protected function moduleKey(): string
    {
        return 'pregnant';
    }
}
