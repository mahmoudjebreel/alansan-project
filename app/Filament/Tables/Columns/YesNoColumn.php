<?php

namespace App\Filament\Tables\Columns;

use Filament\Tables\Columns\TextColumn;

/**
 * Shared boolean column: renders any truthy value as a localized "Yes" (نعم)
 * badge and any falsy/null value as a localized "No" (لا) badge.
 *
 * Usage in any Resource table():
 *     YesNoColumn::make('is_pwd')->label(__('fields.is_pwd'))
 */
class YesNoColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->badge()
            ->formatStateUsing(fn (mixed $state): string => $state ? __('fields.yes') : __('fields.no'))
            ->color(fn (mixed $state): string => $state ? 'success' : 'gray');
    }
}
