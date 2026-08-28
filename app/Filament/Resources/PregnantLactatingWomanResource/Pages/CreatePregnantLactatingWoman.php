<?php

namespace App\Filament\Resources\PregnantLactatingWomanResource\Pages;

use App\Filament\Resources\PregnantLactatingWomanResource;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\On;

class CreatePregnantLactatingWoman extends CreateRecord
{
    protected static string $resource = PregnantLactatingWomanResource::class;

    #[On('fillMotherDataFromAlert')]
    public function fillMotherDataFromAlert(array $data): void
    {
        $motherData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        $this->form->fill(array_merge($motherData, [
            'visit_type' => 'follow_up',
            'date_of_reporting' => now()->format('Y-m-d'),
        ]));
    }
}
