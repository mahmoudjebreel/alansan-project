<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesModuleActions;
use App\Filament\Resources\FollowUpChildResource\Pages;
use App\Models\FollowUpChild;
use App\Support\FilamentInfolist;
use Filament\Forms;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FollowUpChildResource extends Resource
{
    use AuthorizesModuleActions;

    protected static ?string $model = FollowUpChild::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة البيانات';

    public static function getModelLabel(): string
    {
        return __('fields.follow_up_child');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fields.follow_up_children');
    }

    public static function dischargeOutcomeOptions(): array
    {
        return [
            'cured' => __('fields.cured'),
            'defaulted' => __('fields.defaulted'),
            'discharge_to_opt' => __('fields.discharge_to_opt'),
            'discharge_to_other' => __('fields.discharge_to_other'),
            'died' => __('fields.died'),
            'under_follow_up' => __('fields.under_follow_up'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('visits');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Tabs::make('FollowUpChildTabs')
                ->tabs([
                    \Filament\Schemas\Components\Tabs\Tab::make('بيانات متابعة الطفل')
                        ->icon('heroicon-o-user')
                        ->schema([
                            static::getFollowUpChildDataSection(),
                        ]),
                    \Filament\Schemas\Components\Tabs\Tab::make('الزيارات الجارية والمتابعة')
                        ->icon('heroicon-o-clipboard-document-check')
                        ->schema([
                            static::getVisitsSection(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    protected static function getFollowUpChildDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.follow_up_child_data'))
            ->schema([
                Forms\Components\TextInput::make('id_number')
                    ->label(__('fields.id_number'))
                    ->required()
                    ->numeric()
                    ->rules(['regex:/^[0-9]{9}$/'])
                    ->validationMessages([
                        'required' => 'رقم الهوية مطلوب.',
                        'numeric' => 'رقم الهوية يجب أن يكون رقماً.',
                        'regex' => 'رقم الهوية يجب أن يتكون من 9 أرقام بالضبط.',
                    ])
                    ->maxLength(255),
                Forms\Components\TextInput::make('child_name')
                    ->label(__('fields.child_name'))
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('sex')
                    ->label(__('fields.sex'))
                    ->required()
                    ->options(['M' => __('fields.M'), 'F' => __('fields.F')]),
                Forms\Components\DatePicker::make('dob')
                    ->label(__('fields.dob'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => static::syncAges($set, $get)),
                Forms\Components\DatePicker::make('admission_date')
                    ->label(__('fields.admission_date'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => static::syncAges($set, $get)),
                Forms\Components\TextInput::make('age_at_admission')
                    ->label(__('fields.age_at_admission'))
                    ->disabled()
                    ->dehydrated(),
                Forms\Components\TextInput::make('age')
                    ->label(__('fields.age'))
                    ->disabled()
                    ->dehydrated()
                    ->maxLength(255),
                Forms\Components\TextInput::make('mobile_number')
                    ->label(__('fields.mobile_number'))
                    ->tel()
                    ->numeric()
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('shelter_name')
                    ->label(__('fields.shelter_name'))
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('governorate')
                    ->label(__('fields.governorate'))
                    ->default('gaza')
                    ->required(),
                Forms\Components\TextInput::make('causes_of_admission')
                    ->label(__('fields.causes_of_admission'))
                    ->default('malnutrition')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('admitted_with')
                    ->label(__('fields.admitted_with'))
                    ->required()
                    ->options(['SAM' => 'SAM', 'MAM' => 'MAM']),
                Forms\Components\DatePicker::make('discharge_date')
                    ->label(__('fields.discharge_date'))
                    ->rules(['date']),
                Forms\Components\Select::make('discharge_outcome')
                    ->label(__('fields.discharge_outcome'))
                    ->options(static::dischargeOutcomeOptions()),
                Forms\Components\Textarea::make('notes')
                    ->label(__('fields.notes'))
                    ->columnSpanFull(),
            ])->columns(2);
    }

    /**
     * Recalculate both age fields from the date of birth whenever DOB or the
     * admission date changes, so they can never drift apart.
     */
    protected static function syncAges(Set $set, Get $get): void
    {
        $set('age_at_admission', FollowUpChild::formatAgeAtAdmission($get('dob'), $get('admission_date')));
        $set('age', FollowUpChild::formatCurrentAge($get('dob')));
    }

    protected static function getVisitsSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.visits'))
            ->schema([
                Forms\Components\Repeater::make('visits')
                    ->label(__('fields.visits'))
                    ->hiddenLabel()
                    ->relationship()
                    ->schema([
                        Forms\Components\DatePicker::make('visit_date')
                            ->label(__('fields.visit_date'))
                            ->required(),
                        Forms\Components\TextInput::make('muac')
                            ->label(__('fields.muac'))
                            ->numeric()
                            ->required(),
                    ])
                    ->columns(2)
                    ->itemNumbers()
                    ->itemLabel(fn (array $state): ?string => filled($state['visit_date'] ?? null)
                        ? __('fields.visit_date') . ': ' . $state['visit_date']
                        : null)
                    ->minItems(1)
                    ->maxItems(FollowUpChild::MAX_VISITS)
                    ->defaultItems(1)
                    ->addActionLabel(__('fields.add_visit'))
                    ->orderColumn('visit_number')
                    ->reorderable(false)
                    ->deleteAction(fn (\Filament\Actions\Action $action) => $action->requiresConfirmation()),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make(__('fields.follow_up_child_data'))
                ->schema([
                    FilamentInfolist::text('id_number'),
                    FilamentInfolist::text('child_name'),
                    FilamentInfolist::enum('sex'),
                    FilamentInfolist::date('dob'),
                    FilamentInfolist::text('age_at_admission'),
                    FilamentInfolist::text('age'),
                    FilamentInfolist::text('mobile_number'),
                    FilamentInfolist::text('shelter_name'),
                    FilamentInfolist::text('governorate'),
                    FilamentInfolist::text('causes_of_admission'),
                    FilamentInfolist::text('admitted_with'),
                    FilamentInfolist::date('admission_date'),
                    FilamentInfolist::date('discharge_date'),
                    FilamentInfolist::enum('discharge_outcome'),
                    FilamentInfolist::text('notes')->columnSpanFull(),
                ])->columns(2),
            \Filament\Schemas\Components\Section::make(__('fields.visits'))
                ->schema([
                    RepeatableEntry::make('visits')
                        ->label(__('fields.visits'))
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('visit_number')
                                ->label(__('fields.visit_number')),
                            FilamentInfolist::date('visit_date'),
                            FilamentInfolist::text('muac'),
                        ])
                        ->columns(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('visits'))
            ->columns([
                Tables\Columns\TextColumn::make('id_number')
                    ->label(__('fields.id_number'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('child_name')
                    ->label(__('fields.child_name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('sex')
                    ->label(__('fields.sex'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => filled($state) ? __('fields.' . $state) : null),
                Tables\Columns\TextColumn::make('shelter_name')
                    ->label(__('fields.shelter_name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('admission_date')
                    ->label(__('fields.admission_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('discharge_date')
                    ->label(__('fields.discharge_date'))
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('discharge_outcome')
                    ->label(__('fields.discharge_outcome'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => filled($state) ? __('fields.' . $state) : null)
                    ->color(fn (?string $state): string => match ($state) {
                        'cured' => 'success',
                        'died' => 'danger',
                        'defaulted' => 'warning',
                        'under_follow_up' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('visits_count')
                    ->label(__('fields.visits_recorded'))
                    ->counts('visits')
                    ->formatStateUsing(fn ($state): string => $state . '/' . FollowUpChild::MAX_VISITS)
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('latest_muac')
                    ->label(__('fields.latest_muac'))
                    ->state(fn (FollowUpChild $record): mixed => $record->latest_muac)
                    ->badge()
                    ->color(fn ($state): string => match (\App\Models\Child::classifyMuac($state)) {
                        'SAM' => 'danger',
                        'MAM' => 'warning',
                        'Normal' => 'success',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('admission_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('discharge_outcome')
                    ->label(__('fields.discharge_outcome'))
                    ->options(static::dischargeOutcomeOptions()),
                Tables\Filters\SelectFilter::make('shelter_name')
                    ->label(__('fields.shelter_name'))
                    ->options(fn (): array => FollowUpChild::query()
                        ->whereNotNull('shelter_name')
                        ->distinct()
                        ->pluck('shelter_name', 'shelter_name')
                        ->all()),
                Tables\Filters\Filter::make('admission_date')
                    ->form([
                        Forms\Components\DatePicker::make('admission_from')->label(__('fields.from')),
                        Forms\Components\DatePicker::make('admission_to')->label(__('fields.to')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['admission_from'],
                                fn (Builder $query, $date): Builder => $query->where('admission_date', '>=', $date),
                            )
                            ->when(
                                $data['admission_to'],
                                fn (Builder $query, $date): Builder => $query->where('admission_date', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->visible(fn (): bool => static::allowsAction('edit')),
                \Filament\Actions\ViewAction::make()
                    ->visible(fn (): bool => static::allowsAction('view')),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->visible(fn (): bool => static::allowsAction('delete')),
                ]),
            ]);
    }

    /**
     * Permission prefix every action on this module authorises against.
     */
    public static function permissionPrefix(): string
    {
        return 'follow_up_children';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFollowUpChildren::route('/'),
            'create' => Pages\CreateFollowUpChild::route('/create'),
            'view' => Pages\ViewFollowUpChild::route('/{record}'),
            'edit' => Pages\EditFollowUpChild::route('/{record}/edit'),
        ];
    }
}
