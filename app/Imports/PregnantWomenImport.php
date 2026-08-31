<?php

namespace App\Imports;

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
 */
class PregnantWomenImport extends AbstractTableImport
{
    protected function moduleKey(): string
    {
        return 'pregnant';
    }

    protected function normaliseValue(string $field, mixed $value): array
    {
        return PregnantWomanImportSynonyms::normalise($field, $value);
    }
}
