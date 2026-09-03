<?php

namespace App\Filament\Widgets;

use App\Models\PregnantLactatingWoman;
use App\Services\DashboardAnalytics;
use Filament\Widgets\ChartWidget;

class WomenStatusDistributionChart extends ChartWidget
{
    public static function canView(): bool
    {
        return DashboardAnalytics::canViewPregnantLactatingWomen();
    }

    public function getHeading(): string
    {
        return __('dashboard.women_status_distribution');
    }

    protected function getData(): array
    {
        $counts = PregnantLactatingWoman::query()->selectRaw('status_type, COUNT(*) as total')->groupBy('status_type')->pluck('total', 'status_type');

        if ($counts->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => [[
                // Three slices, one per stored status. Without the combined
                // one those women would silently drop out of the chart.
                'data' => [
                    (int) ($counts['pregnant'] ?? 0),
                    (int) ($counts['lactating'] ?? 0),
                    (int) ($counts['pregnant_lactating'] ?? 0),
                ],
                'backgroundColor' => ['#f59e0b', '#10b981', '#6366f1'],
            ]],
            'labels' => [__('fields.pregnant'), __('fields.lactating'), __('fields.pregnant_lactating')],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getEmptyStateDescription(): ?string
    {
        return __('dashboard.no_data');
    }
}
