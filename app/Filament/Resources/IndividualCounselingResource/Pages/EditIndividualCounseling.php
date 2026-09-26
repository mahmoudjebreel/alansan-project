<?php

namespace App\Filament\Resources\IndividualCounselingResource\Pages;

use App\Filament\Resources\IndividualCounselingResource;
use App\Support\RichText;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIndividualCounseling extends EditRecord
{
    protected static string $resource = IndividualCounselingResource::class;

    /**
     * A record saved as plain text (before the rich editor, or by the
     * spreadsheet import) keeps its line breaks when opened. The follow-up
     * sessions are filled by the repeater and get the same treatment there.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return RichText::hydrate($data, IndividualCounselingResource::RICH_TEXT_FIELDS);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('individual_counseling.delete') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('individual_counseling.delete') ?? false),
        ];
    }
}
