<?php

namespace App\Exports;

use App\Models\FollowUpChild;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF report for the Follow Up Child module.
 *
 * Same shared layout as every other module; the only thing this module adds is
 * its repeated part - up to sixteen visits per child, printed as numbered rows
 * under the record instead of thirty-two extra columns across the page.
 */
class FollowUpChildPdfExport
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
            new FollowUpChildrenExport($query),
            'follow_up_children.export',
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
            new FollowUpChildrenExport($query),
            $filename,
            $title,
            'child_name',
            self::section(),
        );
    }

    /**
     * The visits table: its heading, its columns, and how one child's visits
     * become rows.
     */
    public static function section(): array
    {
        return [
            'title' => __('fields.visits'),
            'columns' => [
                __('fields.visit_date'),
                __('fields.muac'),
                __('fields.visit_status'),
            ],
            'empty' => __('fields.no_visits'),
            // The relation already orders by visit_number, so the row number
            // is the visit number.
            'rows' => fn (FollowUpChild $record): array => $record->visits
                ->values()
                ->map(fn ($visit): array => [
                    $visit->visit_date?->format('Y-m-d'),
                    $visit->muac,
                    $visit->isMissed() ? __('fields.visit_missed') : __('fields.visit_attended'),
                ])
                ->all(),
        ];
    }
}
