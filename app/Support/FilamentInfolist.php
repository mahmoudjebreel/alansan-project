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

    public static function enum(string $name): TextEntry
    {
        return static::text($name)
            ->formatStateUsing(fn (?string $state): ?string => filled($state) ? __("fields.{$state}") : null);
    }
}
