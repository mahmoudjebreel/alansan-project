<?php

namespace App\Filament\Widgets;

use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Services\DashboardAnalytics;
use Filament\Widgets\ChartWidget;

class RecordsOverTimeChart extends ChartWidget
{
    protected int | string | array $columnSpan = ['lg' => 2];

    public ?string $filter = '30_days';

    public static function canView(): bool
    {
        return DashboardAnalytics::canViewChildren() || DashboardAnalytics::canViewPregnantLactatingWomen();
    }

    public function getHeading(): string
    {
        return __('dashboard.records_over_time');
    }

    protected function getFilters(): ?array
    {
        return [
            '7_days' => __('dashboard.last_7_days'),
            '30_days' => __('dashboard.last_30_days'),
            '3_months' => __('dashboard.last_3_months'),
            '6_months' => __('dashboard.last_6_months'),
            '12_months' => __('dashboard.last_12_months'),
        ];
    }

    protected function getData(): array
    {
        $datasets = [];
        $labels = [];

        if (DashboardAnalytics::canViewChildren()) {
            $trend = DashboardAnalytics::reportingTrend(Child::class, $this->filter);
            $labels = $trend['labels'];
            $datasets[] = ['label' => __('dashboard.children'), 'data' => $trend['values'], 'borderColor' => DashboardAnalytics::primaryColor(), 'fill' => true, 'tension' => 0.35];
        }

        if (DashboardAnalytics::canViewPregnantLactatingWomen()) {
            $trend = DashboardAnalytics::reportingTrend(PregnantLactatingWoman::class, $this->filter);
            $labels = $labels ?: $trend['labels'];
            $datasets[] = ['label' => __('dashboard.pregnant_lactating_women'), 'data' => $trend['values'], 'borderColor' => DashboardAnalytics::secondaryColor(), 'fill' => true, 'tension' => 0.35];
        }

        if ($datasets === [] || array_sum(array_merge(...array_column($datasets, 'data'))) === 0) {
            return ['datasets' => [], 'labels' => []];
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
