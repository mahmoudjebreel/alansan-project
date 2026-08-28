<?php

namespace App\Filament\Resources\FollowUpChildResource\Pages;

use App\Filament\Resources\FollowUpChildResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewFollowUpChild extends ViewRecord
{
    protected static string $resource = FollowUpChildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('follow_up_children.edit') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('follow_up_children.edit') ?? false),
        ];
    }
}
