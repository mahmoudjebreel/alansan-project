<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.system');
    }

    public static function getModelLabel(): string
    {
        return __('ui.users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.users.plural');
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('users.manage');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema(static::getUserFormFields());
    }

    protected static function getUserFormFields(): array
    {
        return [
            \Filament\Forms\Components\TextInput::make('name')
                ->label(__('ui.users.name'))
                ->required()
                ->maxLength(255),
            \Filament\Forms\Components\TextInput::make('email')
                ->label(__('ui.users.email'))
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->validationMessages([
                    'required' => __('ui.validation.email_required'),
                    'email' => __('ui.validation.email_invalid'),
                    'unique' => __('ui.validation.email_taken'),
                ]),
            \Filament\Forms\Components\TextInput::make('password')
                ->label(__('ui.users.password'))
                ->password()
                ->required(fn (string $context): bool => $context === 'create')
                ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                ->dehydrated(fn ($state) => filled($state))
                ->maxLength(255)
                ->same('password_confirmation'),
            \Filament\Forms\Components\TextInput::make('password_confirmation')
                ->label(__('ui.users.password_confirmation'))
                ->password()
                ->required(fn (string $context): bool => $context === 'create')
                ->dehydrated(false),
            \Filament\Forms\Components\FileUpload::make('avatar')
                ->label(__('ui.users.avatar'))
                ->image()
                ->avatar()
                ->disk('public')
                ->directory('avatars')
                ->visibility('public')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(2048),
            \Filament\Forms\Components\Select::make('roles')
                ->label(__('ui.users.roles'))
                ->multiple()
                ->relationship('roles', 'name')
                ->preload(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Uses the User::$avatar_url accessor so the URL is built in one
                // place only (profile page, header, sidebar and this table).
                Tables\Columns\ImageColumn::make('avatar_url')
                    ->label(__('ui.users.avatar_short'))
                    ->circular(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ui.users.name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('ui.users.email'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label(__('ui.users.roles'))
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ui.users.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
