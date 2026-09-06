<?php

namespace App\Support\MealReport;

use Carbon\CarbonImmutable;

/**
 * The reporting window a MEAL report covers: one calendar year and a run of
 * consecutive months inside it.
 *
 * The months are never named in code. A period is stored as two month numbers
 * and expanded with Carbon, so the same class serves August 2026 -> October
 * 2026 and any window in any future year without being touched.
 *
 * A single month is the ordinary case of a period whose ends are equal, which
 * is what keeps the previous one-month report working unchanged.
 */
final class ReportPeriod
{
    private function __construct(
        public readonly int $year,
        public readonly int $fromMonth,
        public readonly int $toMonth,
    ) {
    }

    /**
     * Build a period, clamping the months into 1-12 and swapping them if they
     * arrive the wrong way round, so a mis-ordered filter still reports rather
     * than returning nothing at all.
     */
    public static function make(int $year, int $fromMonth, ?int $toMonth = null): self
    {
        $from = max(1, min(12, $fromMonth));
        $to = max(1, min(12, $toMonth ?? $fromMonth));

        return new self($year, min($from, $to), max($from, $to));
    }

    /** A period covering exactly one month. */
    public static function month(int $year, int $month): self
    {
        return self::make($year, $month, $month);
    }

    /**
     * Every month in the window, in calendar order - never alphabetical.
     *
     * @return array<int>
     */
    public function months(): array
    {
        return range($this->fromMonth, $this->toMonth);
    }

    public function monthCount(): int
    {
        return $this->toMonth - $this->fromMonth + 1;
    }

    public function isSingleMonth(): bool
    {
        return $this->fromMonth === $this->toMonth;
    }

    /** First instant of the window: the first day of the first month. */
    public function start(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->fromMonth, 1)->startOfDay();
    }

    /** Last instant of the window: the last day of the last month. */
    public function end(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->toMonth, 1)->endOfMonth()->endOfDay();
    }

    /**
     * The window as an inclusive pair of bounds, for a BETWEEN on an indexed
     * date column. Preferred over whereYear()+whereMonth(), which wrap the
     * column in a function and so cannot use its index.
     *
     * The upper bound is the last instant of the last day, not that day at
     * midnight: Eloquent's date cast writes '2026-10-31 00:00:00', so a bound
     * of '2026-10-31' compares as the smaller string and would drop every
     * record captured on the final day of the period.
     *
     * @return array{0:string, 1:string}
     */
    public function dateRange(): array
    {
        return [$this->start()->toDateTimeString(), $this->end()->toDateTimeString()];
    }

    /** Whether a date falls inside the window. */
    public function contains(mixed $date): bool
    {
        if (blank($date)) {
            return false;
        }

        $date = CarbonImmutable::parse($date);

        return $date->betweenIncluded($this->start(), $this->end());
    }

    /** The month name as the template's MONTH column spells it. */
    public function monthLabel(int $month): string
    {
        return CarbonImmutable::create($this->year, $month, 1)->format('F');
    }

    /** Human label for the period, e.g. "August 2026" or "August - October 2026". */
    public function label(): string
    {
        if ($this->isSingleMonth()) {
            return $this->start()->translatedFormat('F Y');
        }

        return sprintf(
            '%s - %s',
            $this->start()->translatedFormat('F'),
            $this->end()->translatedFormat('F Y'),
        );
    }

    /** Short slug for the exported filename, e.g. "Aug-Oct-2026". */
    public function filenameSlug(): string
    {
        if ($this->isSingleMonth()) {
            return $this->start()->format('M-Y');
        }

        return sprintf('%s-%s', $this->start()->format('M'), $this->end()->format('M-Y'));
    }

    /** Identity of this window, for memoising a build against it. */
    public function signature(): string
    {
        return sprintf('%04d:%02d:%02d', $this->year, $this->fromMonth, $this->toMonth);
    }
}
