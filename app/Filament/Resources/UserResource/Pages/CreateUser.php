<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\PermissionRegistrar;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * afterCreate(), not afterSave(): CreateRecord never calls afterSave() -
     * that hook belongs to EditRecord - so the flush written here never ran.
     */
    protected function afterCreate(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
