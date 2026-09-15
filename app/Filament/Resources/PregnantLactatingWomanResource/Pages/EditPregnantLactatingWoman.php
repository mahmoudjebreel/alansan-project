<?php

namespace App\Filament\Resources\PregnantLactatingWomanResource\Pages;

use App\Filament\Resources\PregnantLactatingWomanResource;
use App\Support\PregnantWomanDuplicateChecker;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPregnantLactatingWoman extends EditRecord
{
    protected static string $resource = PregnantLactatingWomanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('pregnant.delete') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('pregnant.delete') ?? false),
        ];
    }

    /**
     * The visit type is decided server-side on every save, never by the
     * (locked) form field: a status corrected here is measured against the
     * mother's other active visits with the same rule a new entry uses. This
     * record is left out of that history so it never compares against itself.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['visit_type'] = PregnantWomanDuplicateChecker::resolveVisitType(
            $data['mother_id'] ?? null,
            $data['status_type'] ?? null,
            $this->getRecord(),
        );

        return $data;
    }
}
