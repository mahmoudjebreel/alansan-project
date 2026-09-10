<?php

namespace App\Filament\Widgets;

use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Services\DashboardAnalytics;
use Filament\Widgets\ChartWidget;

class GovernorateDistributionChart extends ChartWidget
{
    protected static ?int $sort = 10;

    /** Two of the three columns on a wide screen; the nutrition doughnut takes the third. */
    protected int | string | array $columnSpan = ['default' => 1, 'md' => 2, 'xl' => 2];

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return DashboardAnalytics::canViewChildren() || DashboardAnalytics::canViewPregnantLactatingWomen();
    }

    public function getHeading(): string
    {
        return __('dashboard.records_by_governorate');
    }

    protected function getData(): array
    {
        $children = DashboardAnalytics::canViewChildren() ? DashboardAnalytics::byGovernorate(Child::class) : [];
        $women = DashboardAnalytics::canViewPregnantLactatingWomen() ? DashboardAnalytics::byGovernorate(PregnantLactatingWoman::class) : [];
        $labels = array_values(array_unique([...array_keys($children), ...array_keys($women)]));

        if ($labels === []) {
            return ['datasets' => [], 'labels' => []];
        }

        $datasets = [];

        if ($children !== []) {
            $datasets[] = ['label' => __('dashboard.children'), 'data' => array_map(fn (string $label): int => $children[$label] ?? 0, $labels), 'borderColor' => DashboardAnalytics::primaryColor(), 'backgroundColor' => DashboardAnalytics::primaryColor(), 'borderRadius' => 6, 'maxBarThickness' => 36];
        }

        if ($women !== []) {
            $datasets[] = ['label' => __('dashboard.pregnant_lactating_women'), 'data' => array_map(fn (string $label): int => $women[$label] ?? 0, $labels), 'borderColor' => DashboardAnalytics::secondaryColor(), 'backgroundColor' => DashboardAnalytics::secondaryColor(), 'borderRadius' => 6, 'maxBarThickness' => 36];
        }

        return compact('datasets', 'labels');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getEmptyStateDescription(): ?string
    {
        return __('dashboard.no_data');
    }
}
