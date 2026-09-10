<?php

namespace App\Services;

use App\Models\Child;
use App\Models\PregnantLactatingWoman;
use App\Settings\GeneralSettings;
use App\Support\MuacClassifier;
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
        $keyFormat = $interval === 'day' ? 'Y-m-d' : 'Y-m';

        // Grouped by the raw date rather than a DATE_FORMAT() expression: the
        // window is at most a year, so this is a few hundred rows at the very
        // most, and the same query runs on MySQL in production and on SQLite
        // in the test suite. The month bucketing happens here instead.
        $counts = $model::query()
            // Full timestamps at both ends: a DATE column compares fine either
            // way on MySQL, but SQLite keeps what Eloquent wrote - a midnight
            // datetime string - and would sort today's rows past a bare date.
            ->whereBetween('date_of_reporting', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->selectRaw('date_of_reporting as period, COUNT(*) as total')
            ->groupBy('date_of_reporting')
            ->pluck('total', 'period')
            ->reduce(function (array $carry, $total, $date) use ($keyFormat): array {
                $key = CarbonImmutable::parse($date)->format($keyFormat);
                $carry[$key] = ($carry[$key] ?? 0) + (int) $total;

                return $carry;
            }, []);

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

    /**
     * How many screened children fall in each MUAC band.
     *
     * The bands are MuacClassifier's, applied in SQL so the count runs over
     * the column rather than over a hydrated model per child. Children without
     * a reading belong to no band, which is not the same as "normal".
     *
     * @return array{sam: int, mam: int, normal: int}
     */
    public static function nutritionStatus(): array
    {
        $sam = MuacClassifier::SAM_MAX_MM;
        $mam = MuacClassifier::MAM_MAX_MM;

        $counts = Child::query()
            ->whereNotNull('muac_mm')
            ->selectRaw("SUM(muac_mm <= {$sam}) as sam, SUM(muac_mm > {$sam} AND muac_mm < {$mam}) as mam, SUM(muac_mm >= {$mam}) as normal")
            ->first();

        return [
            'sam' => (int) ($counts->sam ?? 0),
            'mam' => (int) ($counts->mam ?? 0),
            'normal' => (int) ($counts->normal ?? 0),
        ];
    }

    /** @return array{pregnant: int, lactating: int} */
    public static function womenStatus(): array
    {
        $counts = PregnantLactatingWoman::query()
            ->selectRaw("SUM(status_type = 'pregnant') as pregnant, SUM(status_type = 'lactating') as lactating")
            ->first();

        return [
            'pregnant' => (int) ($counts->pregnant ?? 0),
            'lactating' => (int) ($counts->lactating ?? 0),
        ];
    }

    /**
     * Records reported on each of the last seven days, for the sparkline
     * under a figure.
     *
     * @return array<int, int>
     */
    public static function weeklySparkline(string $model): array
    {
        return self::reportingTrend($model, '7_days')['values'];
    }

    /**
     * A part as a whole-number percentage of a total, or null when there is
     * nothing to take a share of.
     */
    public static function percentage(int $part, int $whole): ?string
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part * 100 / $whole) . '%';
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
