<?php

namespace App\Filament\Resources\IndividualCounselingResource\Pages;

use App\Filament\Resources\IndividualCounselingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIndividualCounseling extends EditRecord
{
    protected static string $resource = IndividualCounselingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('individual_counseling.delete') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('individual_counseling.delete') ?? false),
        ];
    }
}
