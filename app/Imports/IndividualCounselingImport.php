<?php

namespace App\Imports;

/**
 * Bulk Excel import for the Individual Counseling module.
 *
 * Column structure, validation rules and option lists all come from the
 * shared engine, which reads them from the module's Export class and
 * Filament form. Nothing module-specific needs to be repeated here.
 *
 * Two things are worth knowing about this module in particular:
 *
 *  - The numbered follow-up session columns (Session 1..6) are read into
 *    individual_counseling_followups rows, never into flat columns on the
 *    record. A file carrying a session past the sixth is refused outright.
 *  - The spellings the programme's own workbooks use — "Follow" for
 *    "Follow up", "P/L" for the composite, mixed-case yes/no, stray double
 *    spaces in the feeding type — are normalised before validation, so a
 *    legitimate row is never rejected over how it was typed. The list lives
 *    with the module's entry in ImportDefinition.
 */
class IndividualCounselingImport extends AbstractTableImport
{
    protected function moduleKey(): string
    {
        return 'individual_counseling';
    }
}
