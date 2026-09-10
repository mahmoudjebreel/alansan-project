<?php

namespace App\Filament\Widgets;

use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Services\DashboardAnalytics;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The figures that open the dashboard.
 *
 * Sorted ahead of every chart so the page starts with the numbers, and laid
 * out four to a row so the two groups - the children's screening figures and
 * the whole-programme figures - each take one full row on a wide screen.
 */
class StatsOverview extends BaseWidget
{
    protected static ?int $sort = -10;

    protected int | string | array $columnSpan = 'full';

    protected int | array | null $columns = [
        'default' => 1,
        'sm' => 2,
        'xl' => 4,
    ];

    public static function canView(): bool
    {
        return DashboardAnalytics::canViewChildren() || DashboardAnalytics::canViewPregnantLactatingWomen();
    }

    protected function getStats(): array
    {
        $children = DashboardAnalytics::canViewChildren() ? DashboardAnalytics::overview(Child::class) : null;
        $women = DashboardAnalytics::canViewPregnantLactatingWomen() ? DashboardAnalytics::overview(PregnantLactatingWoman::class) : null;

        $stats = [];

        if ($children) {
            $nutrition = DashboardAnalytics::nutritionStatus();
            $screened = array_sum($nutrition);

            $stats[] = Stat::make(__('fields.total_children'), number_format($children['total']))
                ->description(__('dashboard.new_and_follow_up', [
                    'new' => number_format($children['new']),
                    'follow_up' => number_format($children['follow_up']),
                ]))
                ->descriptionIcon('heroicon-m-users')
                ->chart(DashboardAnalytics::weeklySparkline(Child::class))
                ->color('primary');

            $stats[] = Stat::make(__('dashboard.sam'), number_format($nutrition['sam']))
                ->description($this->share($nutrition['sam'], $screened))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger');

            $stats[] = Stat::make(__('dashboard.mam'), number_format($nutrition['mam']))
                ->description($this->share($nutrition['mam'], $screened))
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color('warning');

            $stats[] = Stat::make(__('dashboard.normal'), number_format($nutrition['normal']))
                ->description($this->share($nutrition['normal'], $screened))
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success');
        }

        if ($women) {
            $status = DashboardAnalytics::womenStatus();

            $stats[] = Stat::make(__('fields.total_plw'), number_format($women['total']))
                ->description(__('dashboard.pregnant_and_lactating', [
                    'pregnant' => number_format($status['pregnant']),
                    'lactating' => number_format($status['lactating']),
                ]))
                ->descriptionIcon('heroicon-m-heart')
                ->chart(DashboardAnalytics::weeklySparkline(PregnantLactatingWoman::class))
                ->color('info');
        }

        $newRecords = ($children['new'] ?? 0) + ($women['new'] ?? 0);
        $followUps = ($children['follow_up'] ?? 0) + ($women['follow_up'] ?? 0);
        $displaced = ($children['displaced'] ?? 0) + ($women['displaced'] ?? 0);
        $notDisplaced = ($children['not_displaced'] ?? 0) + ($women['not_displaced'] ?? 0);

        $stats[] = Stat::make(__('dashboard.new_entries'), number_format($newRecords))
            ->description(__('dashboard.across_modules'))
            ->descriptionIcon('heroicon-m-document-plus')
            ->color('info');

        $stats[] = Stat::make(__('dashboard.follow_up_entries'), number_format($followUps))
            ->description(__('dashboard.across_modules'))
            ->descriptionIcon('heroicon-m-arrow-path')
            ->color('gray');

        $stats[] = Stat::make(__('dashboard.displaced_records'), number_format($displaced))
            ->description(__('dashboard.not_displaced_count', ['count' => number_format($notDisplaced)]))
            ->descriptionIcon('heroicon-m-home')
            ->color('danger');

        return $stats;
    }

    /**
     * The band's share of the children who have a reading, or a plain note
     * when nobody has been measured yet.
     */
    private function share(int $part, int $screened): string
    {
        $share = DashboardAnalytics::percentage($part, $screened);

        return $share === null
            ? __('dashboard.no_measurements')
            : __('dashboard.share_of_screened', ['share' => $share]);
    }
}
