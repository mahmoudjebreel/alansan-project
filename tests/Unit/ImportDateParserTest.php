<?php

namespace Tests\Unit;

use App\Support\Import\ImportDateParser;
use PHPUnit\Framework\TestCase;

/**
 * C1 - the one date reader every import module uses.
 *
 * The accepted shapes are fixed by the requirement, and so is the reading of an
 * ambiguous numeric date: day first, always. The cases below are the ones the
 * requirement names, plus the two failure modes that must stay failures -
 * a day that does not exist, and a cell that is not a date at all.
 */
class ImportDateParserTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function acceptedShapes(): array
    {
        return [
            'slashed, four digit year' => ['19/08/2026', '2026-08-19'],
            'dashed, four digit year' => ['19-08-2026', '2026-08-19'],
            'dotted, four digit year' => ['19.08.2026', '2026-08-19'],
            'slashed, single digit month' => ['19/8/2026', '2026-08-19'],
            'dashed, single digit month' => ['19-8-2026', '2026-08-19'],
            'dotted, single digit month' => ['19.8.2026', '2026-08-19'],
            'iso' => ['2026-08-19', '2026-08-19'],
            'month named, day first' => ['19-Aug-2026', '2026-08-19'],
            'month named in full' => ['19 August 2026', '2026-08-19'],
            'single digit day and month' => ['3/4/2026', '2026-04-03'],
            'two digit year' => ['19/08/26', '2026-08-19'],
            'backslash for slash' => ['19\\08\\2026', '2026-08-19'],
        ];
    }

    /**
     * @dataProvider acceptedShapes
     */
    public function test_it_reads_every_shape_the_requirement_names(string $cell, string $expected): void
    {
        $this->assertSame($expected, ImportDateParser::toIsoDate($cell), "Failed reading [{$cell}].");
    }

    /**
     * The whole point of the class. PHP reads a slashed date month-first, so
     * this cell used to import as the 4th of March in four of the six modules.
     */
    public function test_an_ambiguous_numeric_date_is_read_day_first(): void
    {
        $this->assertSame('2026-04-03', ImportDateParser::toIsoDate('03/04/2026'));
        $this->assertSame('2026-04-03', ImportDateParser::toIsoDate('03-04-2026'));
        $this->assertSame('2026-04-03', ImportDateParser::toIsoDate('03.04.2026'));
    }

    /**
     * A date-time cell keeps its date and loses its time. No time-based rule is
     * introduced by this; the time half was being discarded either way, and
     * leaving it attached was what pushed the cell through to Carbon's
     * month-first reading.
     */
    public function test_a_date_time_cell_keeps_only_its_date(): void
    {
        $this->assertSame('2026-04-03', ImportDateParser::toIsoDate('03/04/2026 14:30'));
        $this->assertSame('2026-04-03', ImportDateParser::toIsoDate('03/04/2026 14:30:00'));
        $this->assertSame('2026-04-03', ImportDateParser::toIsoDate('03/04/2026 2:30 PM'));
        $this->assertSame('2026-08-19', ImportDateParser::toIsoDate('2026-08-19T00:00:00'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidCells(): array
    {
        return [
            'day that never happened' => ['31/4/2025'],
            'february 30th' => ['30/02/2026'],
            'month past twelve in the month slot' => ['19/19/2026'],
            'not a date' => ['not a date'],
            'a word that is not a month' => ['19-Aug0st-2026'],
            'half a date' => ['19/08'],
            'a bare year' => ['2026'],
        ];
    }

    /**
     * @dataProvider invalidCells
     */
    public function test_an_invalid_date_is_refused_rather_than_rolled_over(string $cell): void
    {
        $this->assertNull(ImportDateParser::toIsoDate($cell), "[{$cell}] should not have been read as a date.");
    }

    /**
     * An unreadable cell is handed back exactly as it came, so the caller can
     * refuse it by name rather than receive a guess.
     */
    public function test_normalise_hands_back_what_it_cannot_read(): void
    {
        $this->assertSame('not a date', ImportDateParser::normalise('not a date'));
        $this->assertSame('2026-08-19', ImportDateParser::normalise('19/08/2026'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function absentDates(): array
    {
        return [
            'empty' => [''],
            'a dash' => ['-'],
            'two dashes' => ['--'],
            'a lone slash' => ['/'],
            'a non-breaking space' => ["\u{00A0}"],
            'the excel serial for never filled in' => [0],
            'a negative serial' => [-1],
            'all-zero digits' => ['00/00/0000'],
            'an iso zero date' => ['0000-00-00'],
            'an excel FALSE' => [false],
        ];
    }

    /**
     * @dataProvider absentDates
     */
    public function test_it_recognises_a_cell_that_states_no_date(mixed $cell): void
    {
        $this->assertTrue(ImportDateParser::statesNoDate($cell));
    }

    public function test_a_real_date_is_never_mistaken_for_an_absent_one(): void
    {
        $this->assertFalse(ImportDateParser::statesNoDate('19/08/2026'));
        $this->assertFalse(ImportDateParser::statesNoDate('2026-08-19'));
        $this->assertFalse(ImportDateParser::statesNoDate(45000));
    }
}
