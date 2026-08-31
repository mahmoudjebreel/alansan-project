<?php

namespace App\Filament\Resources\GroupSessionResource\Pages;

use App\Filament\Resources\GroupSessionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGroupSession extends EditRecord
{
    protected static string $resource = GroupSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('group_sessions.delete') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('group_sessions.delete') ?? false),
        ];
    }
}
