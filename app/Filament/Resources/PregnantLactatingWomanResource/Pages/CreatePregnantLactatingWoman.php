<?php

namespace App\Filament\Resources\PregnantLactatingWomanResource\Pages;

use App\Filament\Resources\PregnantLactatingWomanResource;
use App\Support\PregnantWomanDuplicateChecker;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\On;

class CreatePregnantLactatingWoman extends CreateRecord
{
    protected static string $resource = PregnantLactatingWomanResource::class;

    /**
     * Pull the relatively stable data of the last active visit into the form.
     *
     * The pregnant/lactating status, the newborn date that hangs off it, the
     * reporting date and this visit's measurements (weight, height, MUAC) are
     * deliberately left out so they stay empty and fully editable. Picking the
     * status is what settles the visit type.
     */
    #[On('fillMotherDataFromAlert')]
    public function fillMotherDataFromAlert(array $data): void
    {
        $motherData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        $this->form->fill(array_merge($motherData, [
            'status_type' => null,
            'newborn_dob' => null,
            'weight_kg' => null,
            'height_cm' => null,
            'muac_mm' => null,
            'date_of_reporting' => null,
            // No status picked yet, so this stays "follow up" until the user
            // chooses one and the switch check re-derives it.
            'visit_type' => PregnantWomanDuplicateChecker::resolveVisitType($motherData['mother_id'] ?? null),
        ]));
    }

    /**
     * The visit type is decided server-side, never by the (locked) form field:
     * no active record with the same mother ID means "new", otherwise a switch
     * between pregnant and lactating means a new care cycle.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['visit_type'] = PregnantWomanDuplicateChecker::resolveVisitType(
            $data['mother_id'] ?? null,
            $data['status_type'] ?? null,
        );

        return $data;
    }
}
