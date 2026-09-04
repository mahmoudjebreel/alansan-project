<?php

namespace App\Filament\Resources;

use App\Support\Forms\DigitStringField;
use App\Support\RecordSearch;
use App\Filament\Concerns\AuthorizesModuleActions;
use App\Filament\Resources\MotherToMotherResource\Pages;
use App\Filament\Tables\Columns\YesNoColumn;
use App\Models\MotherToMotherSession;
use App\Support\FilamentInfolist;
use App\Support\Forms\BooleanSelectField;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MotherToMotherResource extends Resource
{
    use AuthorizesModuleActions;

    protected static ?string $model = MotherToMotherSession::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-heart';

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.data');
    }

    public static function getModelLabel(): string
    {
        return __('fields.mother_to_mother_session');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fields.mother_to_mother_sessions');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Tabs::make('MotherToMotherTabs')
                ->tabs([
                    \Filament\Schemas\Components\Tabs\Tab::make(__('ui.tabs.mother_to_mother_data'))
                        ->icon('heroicon-o-heart')
                        ->schema([
                            static::getSessionDataSection(),
                        ]),
                    \Filament\Schemas\Components\Tabs\Tab::make(__('ui.tabs.female_participant_data'))
                        ->icon('heroicon-o-user-group')
                        ->schema([
                            static::getParticipantDataSection(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    protected static function getSessionDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.session_data'))
            ->schema([
                Forms\Components\DatePicker::make('session_date')->label(__('fields.session_date'))->required(),
                Forms\Components\TextInput::make('session_group_number')->label(__('fields.session_group_number'))->required()->maxLength(255),
                Forms\Components\Select::make('session_subject')
                    ->label(__('fields.session_subject'))
                    ->options(static::subjectOptions())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state !== 'other') {
                            $set('session_subject_other', null);
                        }
                    }),
                Forms\Components\TextInput::make('session_subject_other')
                    ->label(__('fields.session_subject_other'))
                    ->required(fn (Get $get): bool => $get('session_subject') === 'other')
                    ->visible(fn (Get $get): bool => $get('session_subject') === 'other')
                    ->dehydrated(fn (Get $get): bool => $get('session_subject') === 'other')
                    ->maxLength(255),
                Forms\Components\Select::make('locality')->label(__('fields.locality'))->options(static::localityOptions())->required(),
                Forms\Components\TextInput::make('shelter_name')->label(__('fields.shelter_name'))->required()->maxLength(255),
                Forms\Components\Select::make('visit_type')->label(__('fields.visit_type'))->options(static::visitTypeOptions())->required(),
            ])->columns(2);
    }

    protected static function getParticipantDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.participant_data'))
            ->schema([
                Forms\Components\TextInput::make('id_number')
                    ->label(__('fields.id_number'))
                    ->required()
                    // A digit string, not a quantity: type="number" dropped the
                    // leading zero. @see \App\Support\Forms\DigitStringField
                    ->extraInputAttributes(DigitStringField::inputAttributes())
                    ->rules(['regex:/^[0-9]{9}$/'])
                    ->validationMessages([
                        'required' => __('ui.validation.identity_required'),
                        'regex' => __('ui.validation.identity_digits'),
                    ])
                    ->maxLength(255),
                Forms\Components\TextInput::make('full_name_ar')->label(__('fields.full_name_ar'))->required()->maxLength(255),
                Forms\Components\Select::make('category')
                    ->label(__('fields.category'))
                    ->options(fn (?Model $record): array => static::categoryOptionsFor($record))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if (! in_array($state, static::newbornDobCategories(), true)) {
                            $set('newborn_dob', null);
                        }
                    }),
                Forms\Components\DatePicker::make('newborn_dob')
                    ->label(__('fields.newborn_dob'))
                    ->visible(fn (Get $get): bool => in_array($get('category'), static::newbornDobCategories(), true))
                    ->required(fn (Get $get): bool => in_array($get('category'), static::newbornDobCategories(), true))
                    ->dehydrated(fn (Get $get): bool => in_array($get('category'), static::newbornDobCategories(), true)),
                BooleanSelectField::make('is_pwd', __('fields.is_pwd')),
                Forms\Components\Select::make('marital_status')->label(__('fields.marital_status'))->options(static::maritalStatusOptions())->required(),
                Forms\Components\TextInput::make('phone_number')
                    ->label(__('fields.phone_number'))
                    ->tel()
                    ->required()
                    // A digit string, not a quantity: type="number" dropped the
                    // leading zero. @see \App\Support\Forms\DigitStringField
                    ->extraInputAttributes(DigitStringField::inputAttributes())
                    ->rules(['regex:/^[0-9]{10}$/'])
                    ->validationMessages([
                        'required' => __('ui.validation.phone_required'),
                        'regex' => __('ui.validation.phone_digits'),
                    ])
                    ->maxLength(255),
                Forms\Components\TextInput::make('receives_supplementary')->label(__('fields.receives_supplementary'))->maxLength(255),
            ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make(__('fields.session_data'))
                ->schema([
                    FilamentInfolist::date('session_date'),
                    FilamentInfolist::text('session_group_number'),
                    FilamentInfolist::enum('session_subject'),
                    FilamentInfolist::text('session_subject_other'),
                    FilamentInfolist::enum('locality'),
                    FilamentInfolist::text('shelter_name'),
                    FilamentInfolist::enum('visit_type'),
                ])->columns(2),
            \Filament\Schemas\Components\Section::make(__('fields.participant_data'))
                ->schema([
                    FilamentInfolist::text('id_number'),
                    FilamentInfolist::text('full_name_ar'),
                    FilamentInfolist::enum('category'),
                    FilamentInfolist::date('newborn_dob'),
                    FilamentInfolist::boolean('is_pwd'),
                    FilamentInfolist::enum('marital_status'),
                    FilamentInfolist::text('phone_number'),
                    FilamentInfolist::text('receives_supplementary'),
                ])->columns(2),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->select([
            'id', 'session_date', 'session_group_number', 'session_subject', 'locality',
            'shelter_name', 'id_number', 'full_name_ar', 'visit_type', 'category', 'is_pwd',
        ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('session_date')->label(__('fields.session_date'))->date()->sortable(),
                Tables\Columns\TextColumn::make('session_group_number')->label(__('fields.session_group_number'))->searchable(query: RecordSearch::identifier('session_group_number'))->sortable(),
                Tables\Columns\TextColumn::make('session_subject')->label(__('fields.session_subject'))->badge()->formatStateUsing(fn (string $state): string => __('fields.' . $state)),
                Tables\Columns\TextColumn::make('locality')->label(__('fields.locality'))->formatStateUsing(fn (string $state): string => __('fields.' . $state)),
                Tables\Columns\TextColumn::make('shelter_name')->label(__('fields.shelter_name'))->searchable(query: RecordSearch::name('shelter_name')),
                Tables\Columns\TextColumn::make('id_number')->label(__('fields.id_number'))->searchable(query: RecordSearch::identifier('id_number'))->sortable(),
                Tables\Columns\TextColumn::make('full_name_ar')->label(__('fields.full_name_ar'))->searchable(query: RecordSearch::name('full_name_ar'))->sortable(),
                Tables\Columns\TextColumn::make('visit_type')->label(__('fields.visit_type'))->badge()->formatStateUsing(fn (string $state): string => __('fields.' . $state)),
                Tables\Columns\TextColumn::make('category')->label(__('fields.category'))->formatStateUsing(fn (string $state): string => __('fields.' . $state)),
                YesNoColumn::make('is_pwd')->label(__('fields.is_pwd'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('session_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('locality')->label(__('fields.locality'))->options(static::localityOptions()),
                Tables\Filters\SelectFilter::make('session_subject')->label(__('fields.session_subject'))->options(static::subjectOptions()),
                Tables\Filters\SelectFilter::make('visit_type')->label(__('fields.visit_type'))->options(static::visitTypeOptions()),
                Tables\Filters\Filter::make('shelter_name')
                    ->form([
                        Forms\Components\TextInput::make('shelter_name')->label(__('fields.shelter_name')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['shelter_name'] ?? null, fn (Builder $query, string $value): Builder => $query->where('shelter_name', 'like', "%{$value}%"))),
                Tables\Filters\Filter::make('session_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('fields.date_from')),
                        Forms\Components\DatePicker::make('until')->label(__('fields.date_to')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('session_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('session_date', '<=', $date))),
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->visible(fn (): bool => static::allowsAction('edit')),
                \Filament\Actions\ViewAction::make()
                    ->visible(fn (): bool => static::allowsAction('view')),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \App\Filament\Actions\FastDeleteBulkAction::make()
                        ->visible(fn (): bool => static::allowsAction('delete')),
                ]),
            ]);
    }

    /**
     * Permission prefix every action on this module authorises against.
     */
    public static function permissionPrefix(): string
    {
        return 'mother_to_mother';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMotherToMotherSessions::route('/'),
            'create' => Pages\CreateMotherToMotherSession::route('/create'),
            'view' => Pages\ViewMotherToMotherSession::route('/{record}'),
            'edit' => Pages\EditMotherToMotherSession::route('/{record}/edit'),
        ];
    }

    public static function subjectOptions(): array { return ['bf_support' => __('fields.bf_support'), 'relactation' => __('fields.relactation'), 'complimentary_feeding' => __('fields.complimentary_feeding'), 'other' => __('fields.other')]; }
    public static function localityOptions(): array { return ['mosaab_camp' => __('fields.mosaab_camp'), 'el_salam_camp' => __('fields.el_salam_camp'), 'mahabba_camp' => __('fields.mahabba_camp'), 'el_qoqa' => __('fields.el_qoqa')]; }
    public static function visitTypeOptions(): array { return ['new' => __('fields.new'), 'follow_up' => __('fields.follow_up')]; }
    /**
     * Selectable participant categories. The keys are exactly the values the
     * category column accepts; 'lactating' (مرضع) was retired and is no longer
     * offered, but categoryOptionsFor() still surfaces it on an old record.
     */
    public static function categoryOptions(): array
    {
        return [
            'grandmothers' => __('fields.grandmothers'),
            'reproductive_age' => __('fields.reproductive_age'),
            'male' => __('fields.male'),
            'caregiver_child_under_6_months' => __('fields.caregiver_child_under_6_months'),
            'caregiver_child_6_23_months' => __('fields.caregiver_child_6_23_months'),
            'pregnant' => __('fields.pregnant'),
        ];
    }

    /**
     * Categories for which a newborn date of birth is collected: the two
     * caregiver-with-child categories and Pregnant.
     */
    public static function newbornDobCategories(): array
    {
        return ['caregiver_child_under_6_months', 'caregiver_child_6_23_months', 'pregnant'];
    }

    /**
     * The options plus, when a saved record still holds a retired value, that
     * value itself — so opening an old record never renders a blank Select.
     */
    public static function categoryOptionsFor(?Model $record): array
    {
        $options = static::categoryOptions();
        $current = $record?->category;

        if (filled($current) && ! array_key_exists($current, $options)) {
            $options[$current] = __('fields.' . $current);
        }

        return $options;
    }

    public static function maritalStatusOptions(): array { return ['married' => __('fields.married'), 'divorced' => __('fields.divorced'), 'widow' => __('fields.widow'), 'separated' => __('fields.separated')]; }
}
