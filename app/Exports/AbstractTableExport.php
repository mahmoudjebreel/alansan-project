<?php

namespace App\Exports;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * The shared spreadsheet writer behind every module's export.
 *
 * Everything a row needs is resolved once per export rather than once per
 * cell. The column list, the boolean and enum sets and the translated labels
 * used to be rebuilt inside the mapping of every row: at sixty-eight columns
 * that is three array literals and two linear searches per cell, so a
 * hundred-thousand row export did that work several million times before
 * PhpSpreadsheet had written anything. The lookups below are built on first
 * use and then read as hash lookups.
 */
abstract class AbstractTableExport implements FromQuery, WithHeadings, WithMapping
{
    /** @var array<int, string>|null */
    private ?array $cachedFields = null;

    /** @var array<string, true>|null */
    private ?array $booleanLookup = null;

    /** @var array<string, true>|null */
    private ?array $enumLookup = null;

    /** @var array<string, string> Translated enum values, by stored value. */
    private array $enumLabels = [];

    private ?string $yes = null;

    private ?string $no = null;

    public function __construct(protected Builder $query)
    {
    }

    /**
     * Column definitions. Public so the matching Excel import/template can
     * reuse them as the single source of truth for column structure.
     */
    abstract public function fields(): array;

    abstract public function booleanFields(): array;

    abstract public function enumFields(): array;

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return array_map(
            fn (string $field): string => __('fields.' . $field),
            $this->fields(),
        );
    }

    public function map($record): array
    {
        $row = [];

        foreach ($this->fieldList() as $field) {
            $row[] = $this->formatValue($record, $field);
        }

        return $row;
    }

    protected function formatValue(Model $record, string $field): mixed
    {
        $value = $record->getAttribute($field);

        if ($value === null) {
            return null;
        }

        if (isset($this->booleans()[$field])) {
            return $value ? $this->yesLabel() : $this->noLabel();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (isset($this->enums()[$field]) && is_scalar($value)) {
            return $this->enumLabels[(string) $value] ??= __('fields.' . $value);
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function fieldList(): array
    {
        return $this->cachedFields ??= $this->fields();
    }

    /**
     * @return array<string, true>
     */
    private function booleans(): array
    {
        return $this->booleanLookup ??= array_fill_keys($this->booleanFields(), true);
    }

    /**
     * @return array<string, true>
     */
    private function enums(): array
    {
        return $this->enumLookup ??= array_fill_keys($this->enumFields(), true);
    }

    private function yesLabel(): string
    {
        return $this->yes ??= __('fields.yes');
    }

    private function noLabel(): string
    {
        return $this->no ??= __('fields.no');
    }
}
