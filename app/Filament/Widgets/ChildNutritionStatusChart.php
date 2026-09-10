<?php

namespace App\Filament\Widgets;

use App\Services\DashboardAnalytics;
use Filament\Widgets\ChartWidget;

/**
 * Screened children by MUAC band: SAM, MAM, normal.
 */
class ChildNutritionStatusChart extends ChartWidget
{
    protected static ?int $sort = 20;

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return DashboardAnalytics::canViewChildren();
    }

    public function getHeading(): string
    {
        return __('dashboard.child_nutrition_status');
    }

    protected function getData(): array
    {
        $status = DashboardAnalytics::nutritionStatus();

        if (array_sum($status) === 0) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => [[
                'data' => [$status['sam'], $status['mam'], $status['normal']],
                'backgroundColor' => ['#ef4444', '#f59e0b', '#22c55e'],
            ]],
            'labels' => [__('dashboard.sam'), __('dashboard.mam'), __('dashboard.normal')],
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
