<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Model;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single PDF report builder behind every module.
 *
 * Every module prints through resources/views/pdf/record-template.blade.php,
 * so the colours, fonts, spacing and borders can never drift apart between
 * modules: a change to the look is a change in one file. What differs per
 * module is only its data - which fields it owns, which attribute names the
 * record, and whether it keeps repeated sessions or visits at all.
 *
 * Column structure and value formatting still come from the module's
 * AbstractTableExport, so the PDF and the spreadsheet can never disagree about
 * what a field is called or how a value is written.
 */
class PdfExport
{
    /**
     * @param  string  $nameField  attribute shown in the record's grey name bar
     * @param  array{title: string, columns: array<int, string>, empty: string, rows: callable}|null  $section
     *         Repeated sessions/visits, for the two modules that have them.
     *         Null - the default - prints no section at all.
     */
    public static function download(
        AbstractTableExport $export,
        string $filename,
        string $title,
        string $nameField,
        ?array $section = null,
    ): StreamedResponse {
        $pdf = new Mpdf(['format' => 'A4']);
        $pdf->WriteHTML(self::render($export, $title, $nameField, $section));

        $content = $pdf->Output('', Destination::STRING_RETURN);

        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * The report's HTML, which is what the mPDF step is handed. Separate from
     * download() so the layout can be exercised without rendering a PDF.
     *
     * @param  array{title: string, columns: array<int, string>, empty: string, rows: callable}|null  $section
     */
    public static function render(
        AbstractTableExport $export,
        string $title,
        string $nameField,
        ?array $section = null,
    ): string {
        // The record's own fields, without any per-session column groups the
        // module's map() appends after them for the spreadsheet.
        $fields = $export->fields();
        $labels = array_map(fn (string $field): string => __('fields.' . $field), $fields);

        $records = $export->query()->cursor()->map(function (Model $record) use (
            $export, $fields, $labels, $title, $nameField, $section
        ): array {
            $values = array_slice($export->map($record), 0, count($fields));

            return [
                'title' => trim((string) $record->getAttribute($nameField)) ?: $title,
                'pairs' => array_map(
                    fn (string $label, mixed $value): array => ['label' => $label, 'value' => $value],
                    $labels,
                    $values,
                ),
                'section' => $section === null ? null : [
                    'title' => $section['title'],
                    'columns' => $section['columns'],
                    'empty' => $section['empty'],
                    'rows' => ($section['rows'])($record),
                ],
            ];
        })->all();

        return view('pdf.record-template', [
            'title' => $title,
            'records' => $records,
            'empty' => __('fields.import_empty_file'),
        ])->render();
    }
}
