<?php

namespace App\Filament\Resources\PregnantLactatingWomanResource\Pages;

use App\Filament\Resources\PregnantLactatingWomanResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewPregnantLactatingWoman extends ViewRecord
{
    protected static string $resource = PregnantLactatingWomanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('pregnant.edit') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('pregnant.edit') ?? false),
        ];
    }
}
