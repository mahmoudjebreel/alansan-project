<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Spatie\Permission\Models\Role;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة النظام';

    protected static string|null $modelLabel = 'دور';

    protected static string|null $pluralModelLabel = 'الأدوار';

    public static function canAccess(): bool
    {
        return auth()->user()->can('roles.manage');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['permissions', 'users']);
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema(static::getRoleFormFields());
    }

    protected static function getRoleFormFields(): array
    {
        return [
            \Filament\Forms\Components\TextInput::make('name')
                ->label('الاسم')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->validationMessages([
                    'unique' => 'اسم الدور مستخدم مسبقاً.',
                ]),
            \Filament\Forms\Components\CheckboxList::make('permissions')
                ->label('الصلاحيات')
                ->relationship('permissions', 'name')
                ->columns(2)
                ->gridDirection('row')
                ->bulkToggleable(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('عدد الصلاحيات')
                    ->counts('permissions')
                    ->sortable(),
                Tables\Columns\TextColumn::make('users_count')
                    ->label('عدد المستخدمين')
                    ->counts('users')
                    ->sortable(),
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
                \Filament\Actions\DeleteAction::make()
                    ->hidden(fn (Model $record) => $record->name === 'Super Admin'),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->action(function (Filament\Support\Enums\ActionSize $action, \Illuminate\Database\Eloquent\Collection $records) {
                            $records->each(function ($record) {
                                if ($record->name !== 'Super Admin') {
                                    $record->delete();
                                }
                            });
                        }),
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
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
