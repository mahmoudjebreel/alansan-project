<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search expressions that an index can actually serve.
 *
 * Filament's default search is `column LIKE '%term%'`, which no B-tree index
 * can help with: every search was a full scan of the table, repeated once per
 * searchable column, and then again for the pagination count. On a table of a
 * hundred thousand rows that is the whole cost of the page.
 *
 * The two helpers here replace it with something the database can plan:
 *
 *  - identifier(): an anchored prefix match, served by the ordinary index the
 *    column already has. Nobody searches for the middle of an ID number.
 *  - name(): a full-text match on MySQL, so a term is found at the start of
 *    any word in the name rather than only at the start of the column.
 *
 * name() falls back to the old LIKE on connections without full-text support
 * (SQLite, which the test suite runs on) so behaviour stays identical there.
 *
 * @see database/migrations/2026_09_03_000001_add_search_indexes_to_data_modules.php
 */
final class RecordSearch
{
    /**
     * Drivers whose full-text syntax Laravel's whereFullText() supports.
     */
    private const FULL_TEXT_DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    /**
     * Prefix match on one or more indexed identifier columns.
     *
     * @return Closure(Builder, string): Builder
     */
    public static function identifier(string ...$columns): Closure
    {
        return function (Builder $query, string $search) use ($columns): Builder {
            $term = static::escapeLike(trim($search));

            if ($term === '') {
                return $query;
            }

            return $query->where(function (Builder $query) use ($columns, $term): void {
                foreach ($columns as $column) {
                    $query->orWhere($query->getModel()->qualifyColumn($column), 'like', "{$term}%");
                }
            });
        };
    }

    /**
     * Word-boundary match on one or more name columns.
     *
     * @return Closure(Builder, string): Builder
     */
    public static function name(string ...$columns): Closure
    {
        return function (Builder $query, string $search) use ($columns): Builder {
            $term = trim($search);

            if ($term === '') {
                return $query;
            }

            if (! static::supportsFullText($query)) {
                $escaped = static::escapeLike($term);

                return $query->where(function (Builder $query) use ($columns, $escaped): void {
                    foreach ($columns as $column) {
                        $query->orWhere($query->getModel()->qualifyColumn($column), 'like', "%{$escaped}%");
                    }
                });
            }

            // Boolean mode with a trailing wildcard, so "محم" still finds
            // "محمد". The term is passed as a binding, never interpolated.
            $expression = static::booleanExpression($term);

            return $query->where(function (Builder $query) use ($columns, $expression): void {
                foreach ($columns as $column) {
                    $query->orWhereFullText(
                        $query->getModel()->qualifyColumn($column),
                        $expression,
                        ['mode' => 'boolean'],
                    );
                }
            });
        };
    }

    /**
     * Turn a typed phrase into a boolean-mode expression: every word required,
     * the last one open-ended so the search narrows as the user types.
     */
    private static function booleanExpression(string $term): string
    {
        $words = preg_split('/\s+/u', static::stripOperators($term), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        $last = array_key_last($words);

        return implode(' ', array_map(
            fn (int $index, string $word): string => '+' . $word . ($index === $last ? '*' : ''),
            array_keys($words),
            $words,
        ));
    }

    /**
     * Remove the characters that mean something to boolean mode, so a user
     * typing a "+" or a quote gets a search rather than a syntax error.
     */
    private static function stripOperators(string $term): string
    {
        return preg_replace('/[+\-><()~*"@]+/u', ' ', $term) ?? '';
    }

    /**
     * Escape the LIKE wildcards so a literal % or _ is searched for, not
     * treated as "match anything".
     */
    private static function escapeLike(string $term): string
    {
        return addcslashes($term, '%_\\');
    }

    private static function supportsFullText(Builder $query): bool
    {
        return in_array($query->getConnection()->getDriverName(), self::FULL_TEXT_DRIVERS, true);
    }
}
