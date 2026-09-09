<?php

namespace App\Filament\Resources\FollowUpChildResource\Pages;

use App\Filament\Resources\FollowUpChildResource;
use App\Filament\Resources\FollowUpChildResource\Actions\ReadmissionAction;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewFollowUpChild extends ViewRecord
{
    protected static string $resource = FollowUpChildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Offered only on a closed record whose child has no open
            // episode; opens a new record and leaves this one as it is.
            ReadmissionAction::make(),
            Actions\EditAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('follow_up_children.edit') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('follow_up_children.edit') ?? false),
        ];
    }
}
