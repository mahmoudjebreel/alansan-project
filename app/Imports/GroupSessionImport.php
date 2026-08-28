<?php

namespace App\Imports;

/**
 * Bulk Excel import for the Group Session module.
 *
 * Column structure, validation rules and option lists all come from the
 * shared engine, which reads them from the module's Export class and
 * Filament form. Nothing module-specific needs to be repeated here.
 */
class GroupSessionImport extends AbstractTableImport
{
    protected function moduleKey(): string
    {
        return 'group_sessions';
    }
}
