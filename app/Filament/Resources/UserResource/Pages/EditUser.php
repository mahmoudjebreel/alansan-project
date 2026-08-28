<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Drop the previous avatar file whenever it is replaced or cleared, so
     * that orphaned images do not pile up on the "public" disk.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $currentAvatar = $this->getRecord()->getOriginal('avatar');
        $newAvatar = $data['avatar'] ?? null;

        if ($currentAvatar && $currentAvatar !== $newAvatar) {
            Storage::disk('public')->delete($currentAvatar);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
