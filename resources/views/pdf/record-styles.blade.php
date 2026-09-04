{{--
    The report stylesheet, on its own so mPDF can be handed it once as a CSS
    block before any record is written.

    @see resources/views/pdf/record-template.blade.php  the whole document
    @see \App\Exports\PdfExport                          the chunked writer
--}}
<style>
    @page { margin: 18px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
    h1 { font-size: 15px; margin: 0 0 14px; }
    h2 { font-size: 11px; margin: 0 0 6px; padding: 5px 6px; background: #e5e7eb; }
    h3 { font-size: 10px; margin: 10px 0 4px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #555; padding: 4px 5px; text-align: start; vertical-align: top; }
    th { background: #f3f4f6; font-weight: bold; }
    /* Each record is one block; keep it whole on a page where it fits. */
    .record { margin-bottom: 14px; page-break-inside: avoid; }
    .label { width: 26%; background: #fafafa; font-weight: bold; }
    .session-no { width: 8%; text-align: center; }
    .session-date { width: 18%; }
    .empty { color: #9ca3af; }
</style>
