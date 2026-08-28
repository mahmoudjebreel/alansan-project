<?php

namespace App\Filament\Widgets;

use App\Models\Child;
use App\Services\DashboardAnalytics;
use Filament\Widgets\ChartWidget;

class ChildSexDistributionChart extends ChartWidget
{
    protected ?string $maxHeight = '220px';

    public static function canView(): bool
    {
        return DashboardAnalytics::canViewChildren();
    }

    public function getHeading(): string
    {
        return __('dashboard.child_sex_distribution');
    }

    protected function getData(): array
    {
        $counts = Child::query()->selectRaw('sex, COUNT(*) as total')->groupBy('sex')->pluck('total', 'sex');

        if ($counts->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => [[
                'data' => [(int) ($counts['male'] ?? 0), (int) ($counts['female'] ?? 0)],
                'backgroundColor' => ['#0ea5e9', '#ec4899'],
            ]],
            'labels' => [__('fields.male'), __('fields.female')],
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
