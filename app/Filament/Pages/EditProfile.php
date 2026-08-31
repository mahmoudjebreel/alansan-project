<?php

namespace App\Filament\Pages;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class EditProfile extends BaseEditProfile
{
    protected static ?string $title = 'ملفي الشخصي';

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
            ->label('الاسم')
            ->required()
            ->maxLength(255)
            ->autofocus();
    }

    protected function getAvatarFormComponent(): Component
    {
        return FileUpload::make('avatar')
            ->label('الصورة الشخصية')
            ->image()
            ->avatar()
            ->disk('public')
            ->directory('avatars')
            ->visibility('public')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(2048);
    }

            protected function getSavedNotification(): ?Notification
            {
            return Notification::make()
                ->success()
                ->title('تم تحديث الملف الشخصي بنجاح');
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
            Storage::disk('public')->delete($currentAvatar);
        }

        return $data;
    }
}
