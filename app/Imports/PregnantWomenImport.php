<?php

namespace App\Imports;

use App\Support\Import\PregnantWomanImportDates;
use App\Support\Import\PregnantWomanImportSynonyms;

/**
 * Bulk Excel import for the Pregnant/Lactating Women module.
 *
 * Column structure, validation rules and option lists all come from the
 * shared engine, which reads them from the module's Export class and
 * Filament form. Nothing module-specific needs to be repeated here.
 *
 * The one addition is the normalisation step below: the field workbooks for
 * this module arrive written in Arabic or in English from one team to the
 * next, so every Select column is folded onto its stored value before the
 * unchanged validation runs. The map itself lives in
 * App\Support\Import\PregnantWomanImportSynonyms.
 *
 * Where a cell is actually settled — both layers are live, in this order:
 *
 *   1. normaliseValue() below. Reached from AbstractTableImport::readRow(),
 *      once per cell, BEFORE ImportSchema::castValue() sees anything. It is
 *      the gate for the five columns PregnantWomanImportSynonyms::handles()
 *      claims (status_type, visit_type, neighbourhood, type_of_site, status)
 *      and the four yes/no columns: a spelling missing from that map is
 *      refused here and never reaches the cast at all.
 *   2. ImportDefinition::get('pregnant')->synonyms, read by
 *      ImportSchema::castEnum() after the real option list. It carries the
 *      same map so the shared engine is not blind to it, and it is what
 *      settles any Select column layer 1 does not claim.
 *
 * So the two must be kept in step. A new accepted spelling for one of the
 * five gated columns has to be added to PregnantWomanImportSynonyms to have
 * any effect — adding it only to ImportDefinition is unreachable, because
 * layer 1 will already have refused the row.
 */
class PregnantWomenImport extends AbstractTableImport
{
    protected function moduleKey(): string
    {
        return 'pregnant';
    }

    protected function normaliseValue(string $field, mixed $value): array
    {
        // A text date cell is rewritten into an unambiguous Y-m-d first, so the
        // date rule that follows reads a day-first cell as the day it says.
        // Only newborn_dob is affected, and only when the cell is text this
        // class can read with certainty; everything else passes through.
        $value = PregnantWomanImportDates::normalise($field, $value);

        return PregnantWomanImportSynonyms::normalise($field, $value);
    }
}
