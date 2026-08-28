<?php

namespace App\Filament\Resources\IndividualCounselingResource\Pages;

use App\Filament\Resources\IndividualCounselingResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewIndividualCounseling extends ViewRecord
{
    protected static string $resource = IndividualCounselingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('individual_counseling.edit') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('individual_counseling.edit') ?? false),
        ];
    }
}
