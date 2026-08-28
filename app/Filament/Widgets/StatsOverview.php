<?php

namespace App\Filament\Widgets;

use App\Services\DashboardAnalytics;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return DashboardAnalytics::canViewChildren() || DashboardAnalytics::canViewPregnantLactatingWomen();
    }

    protected function getStats(): array
    {
        $stats = [];
        $newRecords = 0;
        $followUps = 0;
        $displaced = 0;
        $notDisplaced = 0;

        if (DashboardAnalytics::canViewChildren()) {
            $children = DashboardAnalytics::overview(\App\Models\Child::class);

            $stats[] = Stat::make(__('fields.total_children'), $children['total'])
                ->icon('heroicon-o-users')
                ->color('success');

            $newRecords += $children['new'];
            $followUps += $children['follow_up'];
            $displaced += $children['displaced'];
            $notDisplaced += $children['not_displaced'];
        }

        if (DashboardAnalytics::canViewPregnantLactatingWomen()) {
            $women = DashboardAnalytics::overview(\App\Models\PregnantLactatingWoman::class);

            $stats[] = Stat::make(__('fields.total_plw'), $women['total'])
                ->icon('heroicon-o-heart')
                ->color('warning');

            $newRecords += $women['new'];
            $followUps += $women['follow_up'];
            $displaced += $women['displaced'];
            $notDisplaced += $women['not_displaced'];
        }

        return [
            ...$stats,
            Stat::make(__('dashboard.new_entries'), $newRecords)->icon('heroicon-o-document-plus')->color('info'),
            Stat::make(__('dashboard.follow_up_entries'), $followUps)->icon('heroicon-o-arrow-path')->color('gray'),
            Stat::make(__('dashboard.displaced_records'), $displaced)->icon('heroicon-o-map-pin')->color('danger'),
            Stat::make(__('dashboard.not_displaced_records'), $notDisplaced)->icon('heroicon-o-home')->color('success'),
        ];
    }
}
