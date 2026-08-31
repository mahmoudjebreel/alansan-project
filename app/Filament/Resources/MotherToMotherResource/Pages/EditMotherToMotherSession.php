<?php

namespace App\Filament\Resources\MotherToMotherResource\Pages;

use App\Filament\Resources\MotherToMotherResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMotherToMotherSession extends EditRecord
{
    protected static string $resource = MotherToMotherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('mother_to_mother.delete') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('mother_to_mother.delete') ?? false),
        ];
    }
}
