<?php

namespace App\Filament\Resources\ChildResource\Pages;

use App\Filament\Resources\ChildResource;
use App\Support\ChildDuplicateChecker;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\On;

class CreateChild extends CreateRecord
{
    protected static string $resource = ChildResource::class;

    /**
     * Pull the relatively stable data of the last active visit into the form.
     *
     * The measurements taken during this visit (MUAC, weight, height, WHZ,
     * oedema) are deliberately left out so they stay empty and fully editable.
     * Entering the new MUAC is what triggers the relapse check that settles the
     * visit type.
     */
    #[On('fillChildDataFromAlert')]
    public function fillChildDataFromAlert(array $data): void
    {
        $childData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        $this->form->fill(array_merge($childData, [
            // No MUAC yet, so this stays "follow up" until the user enters one
            // and the relapse check re-derives it.
            'visit_type' => ChildDuplicateChecker::resolveVisitType($childData['child_id'] ?? null),
            'date_of_reporting' => now()->format('Y-m-d'),
        ]));
    }

    /**
     * The visit type is decided server-side, never by the (locked) form field:
     * no active record with the same child ID means "new", otherwise the
     * relapse check compares this visit's FI against the previous one.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['visit_type'] = ChildDuplicateChecker::resolveVisitType(
            $data['child_id'] ?? null,
            $data['muac_mm'] ?? null,
        );

        return $data;
    }
}
