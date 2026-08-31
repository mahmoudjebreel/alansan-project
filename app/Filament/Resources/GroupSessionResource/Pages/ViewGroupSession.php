<?php

namespace App\Filament\Resources\GroupSessionResource\Pages;

use App\Filament\Resources\GroupSessionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewGroupSession extends ViewRecord
{
    protected static string $resource = GroupSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('group_sessions.edit') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('group_sessions.edit') ?? false),
        ];
    }
}
