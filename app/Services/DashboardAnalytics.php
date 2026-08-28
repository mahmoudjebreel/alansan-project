<?php

namespace App\Services;

use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class DashboardAnalytics
{
    public static function canViewChildren(): bool
    {
        return auth()->user()?->can('children.view') ?? false;
    }

    public static function canViewPregnantLactatingWomen(): bool
    {
        return auth()->user()?->can('pregnant.view') ?? false;
    }

    public static function primaryColor(): string
    {
        return app(GeneralSettings::class)->primary_color;
    }

    public static function secondaryColor(): string
    {
        return app(GeneralSettings::class)->secondary_color;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    public static function reportingPeriod(?string $filter): array
    {
        return match ($filter) {
            '7_days' => [now()->toImmutable()->subDays(6)->startOfDay(), now()->toImmutable()->endOfDay(), 'day'],
            '3_months' => [now()->toImmutable()->subMonths(2)->startOfMonth(), now()->toImmutable()->endOfMonth(), 'month'],
            '6_months' => [now()->toImmutable()->subMonths(5)->startOfMonth(), now()->toImmutable()->endOfMonth(), 'month'],
            '12_months' => [now()->toImmutable()->subMonths(11)->startOfMonth(), now()->toImmutable()->endOfMonth(), 'month'],
            default => [now()->toImmutable()->subDays(29)->startOfDay(), now()->toImmutable()->endOfDay(), 'day'],
        };
    }

    /** @return array{labels: array<int, string>, values: array<int, int>} */
    public static function reportingTrend(string $model, ?string $filter): array
    {
        [$start, $end, $interval] = self::reportingPeriod($filter);
        $format = $interval === 'day' ? '%Y-%m-%d' : '%Y-%m';

        $counts = $model::query()
            ->whereBetween('date_of_reporting', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("DATE_FORMAT(date_of_reporting, '{$format}') as period, COUNT(*) as total")
            ->groupBy('period')
            ->pluck('total', 'period');

        $labels = [];
        $values = [];
        $cursor = $start;

        while ($cursor->lte($end)) {
            $key = $cursor->format($interval === 'day' ? 'Y-m-d' : 'Y-m');
            $labels[] = $cursor->translatedFormat($interval === 'day' ? 'd M' : 'M Y');
            $values[] = (int) ($counts[$key] ?? 0);
            $cursor = $interval === 'day' ? $cursor->addDay() : $cursor->addMonth();
        }

        return compact('labels', 'values');
    }

    /** @return array<string, int> */
    public static function byGovernorate(string $model): array
    {
        return $model::query()
            ->whereNotNull('governorate')
            ->selectRaw('governorate, COUNT(*) as total')
            ->groupBy('governorate')
            ->orderByDesc('total')
            ->pluck('total', 'governorate')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /** @return array{total: int, new: int, follow_up: int, displaced: int, not_displaced: int} */
    public static function overview(string $model): array
    {
        $counts = $model::query()
            ->selectRaw("COUNT(*) as total, SUM(visit_type = 'new') as new_records, SUM(visit_type = 'follow_up') as follow_up_records, SUM(is_displaced = 1) as displaced_records, SUM(is_displaced = 0) as not_displaced_records")
            ->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'new' => (int) ($counts->new_records ?? 0),
            'follow_up' => (int) ($counts->follow_up_records ?? 0),
            'displaced' => (int) ($counts->displaced_records ?? 0),
            'not_displaced' => (int) ($counts->not_displaced_records ?? 0),
        ];
    }

    public static function children(): Builder
    {
        return Child::query();
    }

    public static function pregnantLactatingWomen(): Builder
    {
        return PregnantLactatingWoman::query();
    }
}
