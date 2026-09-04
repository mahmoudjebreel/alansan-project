<?php

namespace App\Filament\Pages;

use App\Support\PublicUploads;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class EditProfile extends BaseEditProfile
{
    public function getTitle(): string
    {
        return __('ui.profile.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getAvatarFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    protected function getNameFormComponent(): Component
    {
        return TextInput::make('name')
            ->label(__('ui.profile.name'))
            ->required()
            ->maxLength(255)
            ->autofocus();
    }

    protected function getAvatarFormComponent(): Component
    {
        return FileUpload::make('avatar')
            ->label(__('ui.profile.avatar'))
            ->image()
            ->avatar()
            ->disk(PublicUploads::DISK)
            ->directory('avatars')
            ->visibility('public')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(2048);
    }

            protected function getSavedNotification(): ?Notification
            {
            return Notification::make()
                ->success()
                ->title(__('ui.profile.updated'));
            }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $currentAvatar = $this->getUser()->getOriginal('avatar');
        $newAvatar = $data['avatar'] ?? null;

        if ($currentAvatar && $currentAvatar !== $newAvatar) {
            Storage::disk(PublicUploads::DISK)->delete($currentAvatar);
        }

        return $data;
    }
}
