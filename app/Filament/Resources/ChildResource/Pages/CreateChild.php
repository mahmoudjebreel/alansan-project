<?php

namespace App\Filament\Resources\ChildResource\Pages;

use App\Filament\Resources\ChildResource;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\On;

class CreateChild extends CreateRecord
{
    protected static string $resource = ChildResource::class;

    #[On('fillChildDataFromAlert')]
    public function fillChildDataFromAlert(array $data): void
    {
        $childData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        $this->form->fill(array_merge($childData, [
            'visit_type' => 'follow_up',
            'date_of_reporting' => now()->format('Y-m-d'),
        ]));
    }
}
