<?php

namespace App\Filament\Resources\FollowUpChildResource\Pages;

use App\Filament\Resources\FollowUpChildResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFollowUpChild extends EditRecord
{
    protected static string $resource = FollowUpChildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('follow_up_children.delete') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('follow_up_children.delete') ?? false),
        ];
    }
}
