<?php

namespace App\Exports;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

abstract class AbstractTableExport implements FromQuery, WithHeadings, WithMapping
{
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
        return array_map(
            fn (string $field): mixed => $this->formatValue($record, $field),
            $this->fields(),
        );
    }

    protected function formatValue(Model $record, string $field): mixed
    {
        $value = $record->getAttribute($field);

        if ($value === null) {
            return null;
        }

        if (in_array($field, $this->booleanFields(), true)) {
            return $value ? __('fields.yes') : __('fields.no');
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (in_array($field, $this->enumFields(), true)) {
            return __('fields.' . $value);
        }

        return $value;
    }
}
