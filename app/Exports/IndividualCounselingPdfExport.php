<?php

namespace App\Exports;

use App\Models\IndividualCounseling;
use App\Support\RichText;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF report for the Individual Counseling module.
 *
 * The shared layout in PdfExport does the drawing; this class only says what
 * this module's repeated part is. A counseling record holds up to six
 * follow-up sessions, which as spreadsheet columns would be eighteen nobody
 * can read across a page, so they print as numbered rows under the record.
 */
class IndividualCounselingPdfExport
{
    /**
     * Park the report and send the browser to collect it.
     *
     * This is what the module's button returns; the report itself is built by
     * the download route, which can stream for as long as it needs to.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     *
     * @see \App\Exports\PdfExport::start()  why the type is left open
     */
    public static function start(Builder $query, string $filename, string $title)
    {
        return PdfExport::start(
            new IndividualCounselingExport($query),
            'individual_counseling.export',
            $filename,
            $title,
            'child_name',
            self::class,
        );
    }

    /**
     * The report as a download, built as it is sent. The route uses the
     * shared builder directly; this stays for tests that want the whole
     * report from one call.
     */
    public static function download(
        Builder $query,
        string $filename,
        string $title,
    ): StreamedResponse {
        return PdfExport::download(
            new IndividualCounselingExport($query),
            $filename,
            $title,
            'child_name',
            self::section(),
        );
    }

    /**
     * The follow-up sessions table: its heading, its columns, and how one
     * record's sessions become rows.
     */
    public static function section(): array
    {
        return [
            'title' => __('fields.follow_up_sessions'),
            'columns' => [
                __('fields.follow_up_visit_date'),
                __('fields.assess_and_analyze'),
                __('fields.act'),
            ],
            'empty' => __('fields.no_follow_up_sessions'),
            // Position in the sequence is the session number, matching the
            // order the spreadsheet's column groups use. The notes print as
            // text, as they do in the spreadsheet.
            'rows' => fn (IndividualCounseling $record): array => $record->followups
                ->values()
                ->map(fn ($session): array => [
                    $session->follow_up_visit_date?->format('Y-m-d'),
                    RichText::toPlain($session->assess_and_analyze),
                    RichText::toPlain($session->act),
                ])
                ->all(),
        ];
    }
}
