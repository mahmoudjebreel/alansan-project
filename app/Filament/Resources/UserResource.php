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

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static string|null $modelLabel = 'مستخدم';

    protected static string|null $pluralModelLabel = 'المستخدمون';

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
                ->label('الاسم')
                ->required()
                ->maxLength(255),
            \Filament\Forms\Components\TextInput::make('email')
                ->label('البريد الإلكتروني')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->validationMessages([
                    'required' => 'البريد الإلكتروني مطلوب.',
                    'email' => 'يرجى إدخال بريد إلكتروني صحيح.',
                    'unique' => 'البريد الإلكتروني مستخدم مسبقاً.',
                ]),
            \Filament\Forms\Components\TextInput::make('password')
                ->label('كلمة المرور')
                ->password()
                ->required(fn (string $context): bool => $context === 'create')
                ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                ->dehydrated(fn ($state) => filled($state))
                ->maxLength(255)
                ->same('password_confirmation'),
            \Filament\Forms\Components\TextInput::make('password_confirmation')
                ->label('تأكيد كلمة المرور')
                ->password()
                ->required(fn (string $context): bool => $context === 'create')
                ->dehydrated(false),
            \Filament\Forms\Components\FileUpload::make('avatar')
                ->label('الصورة الشخصية')
                ->image()
                ->avatar()
                ->disk('public')
                ->directory('avatars')
                ->visibility('public')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(2048),
            \Filament\Forms\Components\Select::make('roles')
                ->label('الأدوار')
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
                    ->label('الصورة')
                    ->circular(),
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->searchable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('الأدوار')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
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
