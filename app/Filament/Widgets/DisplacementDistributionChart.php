<?php

namespace App\Filament\Widgets;

use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Services\DashboardAnalytics;
use Filament\Widgets\ChartWidget;

/**
 * Displaced against non-displaced, over every record the user may see.
 */
class DisplacementDistributionChart extends ChartWidget
{
    protected static ?int $sort = 40;

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return DashboardAnalytics::canViewChildren() || DashboardAnalytics::canViewPregnantLactatingWomen();
    }

    public function getHeading(): string
    {
        return __('dashboard.displacement_distribution');
    }

    protected function getData(): array
    {
        $displaced = 0;
        $notDisplaced = 0;

        $modules = [
            Child::class => DashboardAnalytics::canViewChildren(),
            PregnantLactatingWoman::class => DashboardAnalytics::canViewPregnantLactatingWomen(),
        ];

        foreach ($modules as $model => $canView) {
            if (! $canView) {
                continue;
            }

            $overview = DashboardAnalytics::overview($model);
            $displaced += $overview['displaced'];
            $notDisplaced += $overview['not_displaced'];
        }

        if ($displaced + $notDisplaced === 0) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => [[
                'data' => [$displaced, $notDisplaced],
                'backgroundColor' => ['#f97316', '#14b8a6'],
            ]],
            'labels' => [__('dashboard.displaced'), __('dashboard.not_displaced')],
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
