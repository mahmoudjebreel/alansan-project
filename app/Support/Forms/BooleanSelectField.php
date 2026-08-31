<?php

namespace App\Support\Forms;

use Filament\Forms\Components\Select;

/**
 * Shared boolean form field: renders any boolean column as a unified
 * "نعم / لا" dropdown instead of a toggle switch.
 *
 * The underlying column stays `boolean`/`tinyint` — Filament's own
 * `Select::boolean()` state cast round-trips the value as 1/0, so nothing
 * about storage changes.
 *
 * The field deliberately mirrors the toggle it replaced:
 *   - it starts on "لا", the way an untouched toggle started switched off;
 *   - a blank answer is stored as 0, never as NULL, because several of these
 *     columns are NOT NULL (children.has_oedema, group_sessions.*, ...) and
 *     the Excel importer applies the very same "blank means no" default.
 *
 * It is intentionally NOT required by default: App\Support\ImportSchema reads
 * this form to decide which columns an imported sheet must carry, and these
 * answers are optional there. Add ->required() at the call site when a field
 * genuinely has to be answered.
 *
 * Usage in any Resource form():
 *     BooleanSelectField::make('is_pwd')
 *     BooleanSelectField::make('is_pwd', __('fields.is_pwd'))
 */
final class BooleanSelectField
{
    public static function make(string $name, ?string $label = null): Select
    {
        $label ??= __("fields.{$name}");

        return Select::make($name)
            ->label($label)
            ->boolean(__('fields.yes'), __('fields.no'))
            ->native(false)
            // ->native(false) renders an ARIA combobox rather than a labelable
            // <select>, so the wrapper's <label for> cannot name it. Naming the
            // input explicitly is what a screen reader reads out alongside the
            // current نعم/لا value.
            ->extraInputAttributes(['aria-label' => $label])
            ->default(false)
            ->dehydrateStateUsing(fn (mixed $state): bool => (bool) $state);
    }
}
