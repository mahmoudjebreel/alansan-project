<?php

namespace App\Support\Forms;

use Filament\Resources\Pages\CreateRecord;

/**
 * The "no backdating" rule on a reporting date, restricted to the screen it
 * actually belongs on.
 *
 * The rule exists so a visit cannot be entered under yesterday's date. Applied
 * unconditionally, it also fired on the edit form - where the date being
 * validated is the one the record was saved with. A record more than a day old
 * could therefore never be saved again: correcting a spelling in the name
 * failed on a date field the user had not touched.
 *
 * Both helpers return null / an empty rule set outside a create page, so an
 * existing record keeps whatever date it was recorded with.
 */
final class ReportingDateField
{
    /**
     * Earliest date the picker offers, or null when any date is allowed.
     */
    public static function minDate(mixed $livewire): ?string
    {
        return static::isCreating($livewire) ? now()->startOfDay()->toDateString() : null;
    }

    /**
     * @return array<string>
     */
    public static function rules(mixed $livewire): array
    {
        return static::isCreating($livewire) ? ['after_or_equal:today'] : [];
    }

    private static function isCreating(mixed $livewire): bool
    {
        return $livewire instanceof CreateRecord;
    }
}
