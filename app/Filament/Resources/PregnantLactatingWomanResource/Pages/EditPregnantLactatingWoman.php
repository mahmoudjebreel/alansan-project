<?php

namespace App\Filament\Resources\PregnantLactatingWomanResource\Pages;

use App\Filament\Resources\PregnantLactatingWomanResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPregnantLactatingWoman extends EditRecord
{
    protected static string $resource = PregnantLactatingWomanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('pregnant.delete') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('pregnant.delete') ?? false),
        ];
    }
}
