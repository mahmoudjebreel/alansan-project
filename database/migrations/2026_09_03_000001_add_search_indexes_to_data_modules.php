<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The indexes the listing pages actually need once a module holds six figures
 * of rows.
 *
 * Two kinds are added:
 *
 *  - (deleted_at, <sort column>) on every soft-deleting module. Every listing
 *    query is "WHERE deleted_at IS NULL ORDER BY <sort> DESC LIMIT n", and
 *    without a composite index MySQL sorts the whole live table to return the
 *    first page.
 *  - FULLTEXT on the name columns, which is what App\Support\RecordSearch uses
 *    in place of LIKE '%term%'. LIKE with a leading wildcard cannot use an
 *    index at all, so the six columns of a global search meant six table scans
 *    per keystroke.
 *
 * Identifier columns are searched by prefix instead, which the ordinary index
 * each of them already has can serve; the few that were missing one get it
 * here.
 *
 * Full-text indexes are MySQL/MariaDB-only. SQLite (the test connection) skips
 * them and RecordSearch falls back to LIKE there, so behaviour is unchanged.
 */
return new class extends Migration
{
    /**
     * table => [deleted_at sort column, identifier columns, full-text columns]
     *
     * @var array<string, array{sort: string, identifiers: array<string>, text: array<string>}>
     */
    private const PLAN = [
        'children' => [
            'sort' => 'date_of_reporting',
            'identifiers' => ['child_id'],
            'text' => ['name', 'mother_full_name', 'father_full_name'],
        ],
        'pregnant_lactating_women' => [
            'sort' => 'date_of_reporting',
            'identifiers' => ['mother_id'],
            'text' => ['full_name_ar'],
        ],
        'group_sessions' => [
            'sort' => 'session_date',
            'identifiers' => ['id_number', 'session_group_number'],
            'text' => ['full_name_ar'],
        ],
        'mother_to_mother_sessions' => [
            'sort' => 'session_date',
            'identifiers' => ['id_number', 'session_group_number'],
            'text' => ['full_name_ar', 'shelter_name'],
        ],
        'individual_counselings' => [
            'sort' => 'date',
            'identifiers' => ['mother_id_number'],
            'text' => ['child_name', 'mother_name', 'shelter_name'],
        ],
        'follow_up_children' => [
            'sort' => 'admission_date',
            'identifiers' => ['id_number'],
            'text' => ['child_name', 'shelter_name'],
        ],
    ];

    public function up(): void
    {
        foreach (self::PLAN as $table => $plan) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $plan): void {
                if (Schema::hasColumn($table, 'deleted_at') && Schema::hasColumn($table, $plan['sort'])) {
                    $this->addIndex($blueprint, $table, ['deleted_at', $plan['sort']]);
                }

                foreach ($plan['identifiers'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $this->addIndex($blueprint, $table, [$column]);
                    }
                }

                if (! $this->supportsFullText()) {
                    return;
                }

                foreach ($plan['text'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $this->addFullText($blueprint, $table, $column);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::PLAN as $table => $plan) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $names = [$this->indexName($table, ['deleted_at', $plan['sort']])];

            foreach ($plan['identifiers'] as $column) {
                $names[] = $this->indexName($table, [$column]);
            }

            if ($this->supportsFullText()) {
                foreach ($plan['text'] as $column) {
                    $names[] = $this->fullTextName($table, $column);
                }
            }

            $names = array_values(array_filter(
                $names,
                fn (string $name): bool => $this->hasIndex($table, $name),
            ));

            if ($names === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($names): void {
                foreach ($names as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }

    /**
     * @param  array<string>  $columns
     */
    private function addIndex(Blueprint $blueprint, string $table, array $columns): void
    {
        $name = $this->indexName($table, $columns);

        if (! $this->hasIndex($table, $name)) {
            $blueprint->index($columns, $name);
        }
    }

    private function addFullText(Blueprint $blueprint, string $table, string $column): void
    {
        $name = $this->fullTextName($table, $column);

        if (! $this->hasIndex($table, $name)) {
            $blueprint->fullText([$column], $name);
        }
    }

    /**
     * @param  array<string>  $columns
     */
    private function indexName(string $table, array $columns): string
    {
        return $table . '_' . implode('_', $columns) . '_index';
    }

    private function fullTextName(string $table, string $column): string
    {
        return $table . '_' . $column . '_fulltext';
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function supportsFullText(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
