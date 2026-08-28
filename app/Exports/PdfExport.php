<?php

namespace App\Exports;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PdfExport
{
    public static function download(AbstractTableExport $export, string $view, string $filename, string $title): StreamedResponse
    {
        $rows = $export->query()->cursor()
            ->map(fn ($record): array => $export->map($record))
            ->all();

        $pdf = new Mpdf(['format' => 'A4-L']);
        $pdf->WriteHTML(view($view, [
            'title' => $title,
            'headings' => $export->headings(),
            'rows' => $rows,
        ])->render());

        $content = $pdf->Output('', Destination::STRING_RETURN);

        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
