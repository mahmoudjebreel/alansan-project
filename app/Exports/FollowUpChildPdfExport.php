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
                __('fields.fi'),
            ],
            'empty' => __('fields.no_visits'),
            // The relation already orders by visit_number, so the row number
            // is the visit number.
            'rows' => fn (FollowUpChild $record): array => $record->visits
                ->values()
                ->map(fn ($visit): array => [
                    $visit->visit_date?->format('Y-m-d'),
                    $visit->muac,
                    $visit->fi,
                ])
                ->all(),
        ];
    }
}
