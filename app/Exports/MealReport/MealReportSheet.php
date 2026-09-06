<?php

namespace App\Exports\MealReport;

use App\Support\MealReport\MealReportLayout;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One sheet of the MEAL report, laid out exactly like the official template.
 *
 * The cell values (including the header captions) are written by FromArray;
 * AfterSheet then applies the merges, fills and rotations that turn those flat
 * rows back into the template's multi-level header.
 *
 * A report covering several months writes them one after another down this
 * same grid, in calendar order, each row naming its own month in the template's
 * MONTH column. The only thing the extra months add is a rule drawn across the
 * row each new month starts on, so the sections are easy to find; no column,
 * header or sheet is added or moved.
 */
class MealReportSheet implements FromArray, WithEvents, WithTitle
{
    /** Per-sheet palette, taken from the official template. */
    private const STYLES = [
        MealReportLayout::SHEET_SCREENING => [
            'title_size' => 20,
            'header_fill' => 'CAEDFB',
            'accent_row' => 4,
            'accent_fill' => '4EA82E',
            'leaf_height' => 99,
        ],
        MealReportLayout::SHEET_IYCF => [
            'title_size' => 20,
            'header_fill' => 'FAE2D5',
            'accent_row' => null,
            'accent_fill' => null,
            'leaf_height' => 54,
        ],
        MealReportLayout::SHEET_CMAM => [
            'title_size' => 18,
            'header_fill' => '96DCF7',
            'accent_row' => 4,
            'accent_fill' => 'FFFF00',
            'leaf_height' => 99,
        ],
    ];

    private const STUB_FILL = 'D8D8D8';

    private const FONT = 'Lato';

    /**
     * @param  array<int, array<string, int|float|string|null>>  $rows
     * @param  array<string, int|float|string|null>  $totals
     * @param  array<int>  $monthStarts  row offsets, into $rows, where a month begins
     */
    public function __construct(
        private readonly string $sheet,
        private readonly array $rows,
        private readonly array $totals,
        private readonly array $monthStarts = [],
    ) {
    }

    public function title(): string
    {
        return $this->sheet;
    }

    /**
     * The whole grid: a blank first row, the header block, then one row per
     * day and a closing Total row.
     *
     * @return array<int, array<int, int|float|string|null>>
     */
    public function array(): array
    {
        $columns = MealReportLayout::columns($this->sheet);
        $width = count($columns);
        $leafRow = MealReportLayout::LEAF_ROW[$this->sheet];

        $grid = [];

        for ($row = 1; $row <= $leafRow; $row++) {
            $grid[] = array_fill(0, $width, null);
        }

        foreach (MealReportLayout::merges($this->sheet) as [$row, $column, , , $caption]) {
            $grid[$row - 1][$column - 1] = $caption;
        }

        foreach (MealReportLayout::leafLabels($this->sheet) as $index => $label) {
            if ($label !== '') {
                $grid[$leafRow - 1][$index] = $label;
            }
        }

        foreach ($this->rows as $row) {
            $grid[] = $this->toCells($row, $columns);
        }

        $grid[] = $this->toCells($this->totals, $columns);

        return $grid;
    }

    /**
     * @param  array<string, int|float|string|null>  $row
     * @param  array<string>  $columns
     * @return array<int, int|float|string|null>
     */
    private function toCells(array $row, array $columns): array
    {
        return array_map(fn (string $key) => $row[$key] ?? null, $columns);
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $this->decorate($event->sheet->getDelegate());
            },
        ];
    }

    private function decorate(Worksheet $sheet): void
    {
        $style = self::STYLES[$this->sheet];
        $columns = MealReportLayout::columns($this->sheet);
        $width = count($columns);
        $leafRow = MealReportLayout::LEAF_ROW[$this->sheet];
        $lastColumn = $sheet->getCellByColumnAndRow($width, 1)->getColumn();
        $lastRow = $leafRow + count($this->rows) + 1;

        foreach (MealReportLayout::merges($this->sheet) as [$row, $column, $rowEnd, $columnEnd]) {
            // A few header captions occupy a single cell; merging those would
            // add 1x1 merges the template does not have.
            if ($row === $rowEnd && $column === $columnEnd) {
                continue;
            }

            $sheet->mergeCellsByColumnAndRow($column, $row, $columnEnd, $rowEnd);
        }

        $headerRange = "A2:{$lastColumn}{$leafRow}";

        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['name' => self::FONT, 'bold' => true, 'size' => 11],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $style['header_fill']]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        // The banner row carries the sheet's name at template size.
        $sheet->getStyle("D2:{$lastColumn}2")->getFont()->setSize($style['title_size']);

        if ($style['accent_row'] !== null) {
            $sheet->getStyle("D{$style['accent_row']}:{$lastColumn}{$style['accent_row']}")
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB($style['accent_fill']);
        }

        // MBA / MONTH / DAY: grey and rotated, as in the template.
        $sheet->getStyle("A2:C{$leafRow}")->applyFromArray([
            'font' => ['name' => self::FONT, 'bold' => true, 'size' => 14],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::STUB_FILL]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'textRotation' => 90,
            ],
        ]);

        // Innermost captions stand on end so the narrow count columns stay narrow.
        $sheet->getStyle("D{$leafRow}:{$lastColumn}{$leafRow}")
            ->getAlignment()
            ->setTextRotation(90);

        $sheet->getRowDimension($leafRow)->setRowHeight($style['leaf_height']);

        $firstDataRow = MealReportLayout::FIRST_DATA_ROW[$this->sheet];

        if ($lastRow >= $firstDataRow) {
            $sheet->getStyle("A{$firstDataRow}:{$lastColumn}{$lastRow}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 11],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);

            $sheet->getStyle("A{$lastRow}:{$lastColumn}{$lastRow}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
            ]);

            $sheet->mergeCells("A{$lastRow}:C{$lastRow}");

            $this->ruleOffMonths($sheet, $firstDataRow, $lastColumn);
        }

        $sheet->getColumnDimension('A')->setWidth(12);
        $sheet->getColumnDimension('B')->setWidth(10);
        $sheet->getColumnDimension('C')->setWidth(6);

        for ($column = 4; $column <= $width; $column++) {
            $sheet->getColumnDimensionByColumn($column)->setWidth(6);
        }

        $sheet->freezePane('D' . ($leafRow + 1));
    }

    /**
     * Draw a rule across the first row of each month after the first, so a
     * multi-month workbook reads as a sequence of months rather than one long
     * undifferentiated run of days.
     *
     * Formatting only: no row is inserted and no caption is written, so a
     * single-month report comes out byte for byte as it did before.
     */
    private function ruleOffMonths(Worksheet $sheet, int $firstDataRow, string $lastColumn): void
    {
        foreach (array_slice($this->monthStarts, 1) as $offset) {
            $row = $firstDataRow + $offset;

            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")
                ->getBorders()
                ->getTop()
                ->setBorderStyle(Border::BORDER_MEDIUM);
        }
    }
}
