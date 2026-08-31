<?php

namespace App\Filament\Resources\MotherToMotherResource\Pages;

use App\Filament\Resources\MotherToMotherResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewMotherToMotherSession extends ViewRecord
{
    protected static string $resource = MotherToMotherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('mother_to_mother.edit') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('mother_to_mother.edit') ?? false),
        ];
    }
}
