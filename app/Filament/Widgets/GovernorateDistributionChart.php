<?php

namespace App\Filament\Widgets;

use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Services\DashboardAnalytics;
use Filament\Widgets\ChartWidget;

class GovernorateDistributionChart extends ChartWidget
{
    protected int | string | array $columnSpan = 1;

    protected ?string $maxHeight = '220px';

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
            $datasets[] = ['label' => __('dashboard.children'), 'data' => array_map(fn (string $label): int => $children[$label] ?? 0, $labels), 'borderColor' => DashboardAnalytics::primaryColor(), 'backgroundColor' => DashboardAnalytics::primaryColor(), 'fill' => false, 'tension' => 0.3, 'borderWidth' => 2, 'pointRadius' => 3];
        }

        if ($women !== []) {
            $datasets[] = ['label' => __('dashboard.pregnant_lactating_women'), 'data' => array_map(fn (string $label): int => $women[$label] ?? 0, $labels), 'borderColor' => DashboardAnalytics::secondaryColor(), 'backgroundColor' => DashboardAnalytics::secondaryColor(), 'fill' => false, 'tension' => 0.3, 'borderWidth' => 2, 'pointRadius' => 3];
        }

        return compact('datasets', 'labels');
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getEmptyStateDescription(): ?string
    {
        return __('dashboard.no_data');
    }
}
