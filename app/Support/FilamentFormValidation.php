<?php

namespace App\Support;

use Filament\Forms\Components\Field;
use Livewire\Component;

class FilamentFormValidation
{
    public static function validateField(Component $livewire, Field $field): void
    {
        $livewire->validateOnly($field->getStatePath());
    }
}
