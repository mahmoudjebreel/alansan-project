<?php

namespace App\Imports;

use App\Support\ImportSchema;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Empty, correctly-structured Excel template for a module.
 *
 * Headings come from the module's Export class (via ImportSchema), so the
 * template and the export can never drift apart.
 */
class ImportTemplateExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize
{
    private readonly ImportSchema $schema;

    public function __construct(private readonly ImportDefinition $definition)
    {
        $this->schema = new ImportSchema($definition);
    }

    public function headings(): array
    {
        return $this->schema->headings();
    }

    /**
     * A single guidance row showing the expected format for each column.
     */
    public function array(): array
    {
        $row = [];

        foreach ($this->definition->fields() as $field) {
            $row[] = $this->hintFor($field);
        }

        if ($this->definition->hasVisits()) {
            foreach ($this->schema->visitNumbers() as $_) {
                $row[] = 'YYYY-MM-DD';
                $row[] = '0.0';
            }
        }

        // Three cells per follow-up session: date, merged assessment, action.
        if ($this->definition->hasFollowups()) {
            foreach ($this->schema->followupNumbers() as $_) {
                $row[] = 'YYYY-MM-DD';
                $row[] = '';
                $row[] = '';
            }
        }

        return [$row];
    }

    /**
     * Format hint for one column: valid options, date format, or yes/no.
     */
    private function hintFor(string $field): string
    {
        if (in_array($field, $this->definition->booleanFields(), true)) {
            return __('fields.yes') . ' / ' . __('fields.no');
        }

        $options = $this->schema->optionsFor($field);

        if (filled($options)) {
            $hint = collect($options)->map(fn ($label) => (string) $label)->implode(' / ');

            // Long enough to spell out every option list the modules define,
            // so a hint never hides a value the column will then refuse. The
            // cap only exists to stop a runaway list from bloating the row.
            return mb_strlen($hint) > 255 ? mb_substr($hint, 0, 252) . '...' : $hint;
        }

        if (in_array($field, $this->definition->enumFields(), true)) {
            return '';
        }

        // Date columns are cast on the model; reuse that knowledge for the hint.
        $casts = (new ($this->definition->model))->getCasts();

        if (in_array($casts[$field] ?? null, ['date', 'datetime', 'immutable_date'], true)) {
            return 'YYYY-MM-DD';
        }

        return in_array($field, $this->schema->requiredFields(), true) ? '*' : '';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // Header row: bold, so the guidance row below is clearly separate.
            1 => ['font' => ['bold' => true]],
            // Guidance row: italic grey — it must be deleted before uploading.
            2 => ['font' => ['italic' => true, 'color' => ['argb' => 'FF888888']]],
        ];
    }
}
