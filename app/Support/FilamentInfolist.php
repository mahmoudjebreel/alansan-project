<?php

namespace App\Support;

use Filament\Infolists\Components\TextEntry;

final class FilamentInfolist
{
    public static function text(string $name): TextEntry
    {
        return TextEntry::make($name)
            ->label(__("fields.{$name}"));
    }

    public static function date(string $name): TextEntry
    {
        return static::text($name)->date();
    }

    public static function boolean(string $name): TextEntry
    {
        return static::text($name)
            ->formatStateUsing(fn ($state): ?string => $state === null ? null : ($state ? __('fields.yes') : __('fields.no')));
    }

    /**
     * A rich editor field, shown with its formatting. A value saved as plain
     * text before the field became rich keeps its line breaks.
     */
    public static function richText(string $name): TextEntry
    {
        return static::text($name)
            ->formatStateUsing(fn (?string $state): ?string => RichText::toHtml($state))
            ->html()
            // Typographic styling, so a list shows its bullets.
            ->prose();
    }

    public static function enum(string $name): TextEntry
    {
        return static::text($name)
            ->formatStateUsing(fn (?string $state): ?string => filled($state) ? __("fields.{$state}") : null);
    }
}
