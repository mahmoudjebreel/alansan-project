<?php

namespace App\Exports\MealReport;

use App\Support\MealReport\MealReportLayout;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The MEAL monthly monitoring workbook: three sheets, in template order.
 *
 * The template's "KIT distribution" sheet is not produced - it has no data
 * source in this system and is filled in by hand.
 */
class MealReportExport implements WithMultipleSheets
{
    /**
     * @param  array<string, array{rows: array, totals: array}>  $data  keyed by sheet name
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * @return array<int, MealReportSheet>
     */
    public function sheets(): array
    {
        return array_map(
            fn (string $sheet): MealReportSheet => new MealReportSheet(
                $sheet,
                $this->data[$sheet]['rows'] ?? [],
                $this->data[$sheet]['totals'] ?? [],
            ),
            MealReportLayout::sheets(),
        );
    }
}
