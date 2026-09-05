<?php

namespace App\Filament\Resources;

use App\Support\Forms\DigitStringField;
use App\Support\RecordSearch;
use App\Filament\Concerns\AuthorizesModuleActions;
use App\Filament\Resources\GroupSessionResource\Pages;
use App\Filament\Tables\Columns\YesNoColumn;
use App\Models\GroupSession;
use App\Support\FilamentInfolist;
use App\Support\GroupSessionDuplicateChecker;
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

class GroupSessionResource extends Resource
{
    use AuthorizesModuleActions;

    protected static ?string $model = GroupSession::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.data');
    }

    public static function getModelLabel(): string
    {
        return __('fields.group_session');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fields.group_sessions');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Tabs::make('GroupSessionTabs')
                ->tabs([
                    \Filament\Schemas\Components\Tabs\Tab::make(__('ui.tabs.group_session_data'))
                        ->icon('heroicon-o-calendar')
                        ->schema([
                            static::getGroupSessionDataSection(),
                        ]),
                    \Filament\Schemas\Components\Tabs\Tab::make(__('ui.tabs.participant_data'))
                        ->icon('heroicon-o-user-group')
                        ->schema([
                            static::getParticipantDataSection(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    protected static function getGroupSessionDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.group_session_data'))
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
                Forms\Components\Select::make('shelter_name')->label(__('fields.shelter_name'))->options(static::shelterOptions())->required(),
                // Derived from whether the participant already has an active
                // session, and locked so it can never be picked by hand. There is
                // no MUAC/FI reading in this module, so there is no relapse rule:
                // an existing active ID is simply a follow up.
                Forms\Components\Select::make('visit_type')
                    ->label(__('fields.visit_type'))
                    ->options(static::visitTypeOptions())
                    ->default('new')
                    ->disabled()
                    ->dehydrated()
                    ->live(),
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
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, $livewire) => static::checkDuplicateParticipant($get, $set, $livewire))
                    // Only this field and the visit type it derives change, so
                    // only those two are re-rendered. Re-rendering the whole
                    // schema sent ~270 KB back on every blur of this field,
                    // which is what made the duplicate alert feel slow.
                    ->partiallyRenderAfterStateUpdated()
                    ->partiallyRenderComponentsAfterStateUpdated(['visit_type']),
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
                BooleanSelectField::make('has_gsfsh', __('fields.has_gsfsh')),
                // Not a yes/no answer: the sheets name the commodity that
                // was handed out - "HEB", "RUCF+HEB+LNS" - and the column has
                // been a nullable string since the 2026_08_26 migration.
                // Mother-to-Mother already records it as free text.
                \Filament\Forms\Components\TextInput::make('receives_supplementary')
                    ->label(__('fields.receives_supplementary'))
                    ->maxLength(255),
            ])->columns(2);
    }

    /**
     * Recompute the locked visit type from the ID number. Only meaningful while
     * creating a record: a saved session keeps the visit type it was stored with.
     */
    public static function syncVisitType(Get $get, Set $set, $livewire): void
    {
        if (! $livewire instanceof \Filament\Resources\Pages\CreateRecord) {
            return;
        }

        $set('visit_type', GroupSessionDuplicateChecker::resolveVisitType($get('id_number')));
    }

    /**
     * Warn, while registering a participant, that the entered ID number already
     * belongs to an active group session.
     *
     * Soft-deleted sessions live in the trash and are not part of the system any
     * more, so the model's default (non-trashed) scope is what decides this: an
     * ID that only exists in the trash raises no alert at all.
     *
     * Both offered actions (return to the listing, or prefill from the previous
     * session) only make sense while creating a record, so an edit form is left
     * completely untouched - a redirect there would silently discard the changes
     * being made.
     */
    public static function checkDuplicateParticipant(Get $get, Set $set, $livewire): void
    {
        $idNumber = $get('id_number');

        if (blank($idNumber) || ! $livewire instanceof \Filament\Resources\Pages\CreateRecord) {
            return;
        }

        $existing = GroupSessionDuplicateChecker::latestActiveSession($idNumber);

        static::syncVisitType($get, $set, $livewire);

        // A first registration is simply "new" - no alert, nothing to fetch.
        if (! $existing) {
            return;
        }

        $livewire->dispatch('show-group-session-duplicate-alert', [
            'title' => __('ui.duplicate.group_session_title'),
            'last_session_date' => $existing->session_date?->format('Y-m-d')
                ?? $existing->created_at?->format('Y-m-d')
                ?? '-',
            'last_visit_type' => $existing->visit_type === 'follow_up' ? __('fields.follow_up') : __('fields.new'),
            'last_session_subject' => static::sessionSubjectLabel($existing),
            'confirm_button_text' => __('ui.duplicate.group_session_confirm'),
            'close_button_text' => __('ui.duplicate.group_session_close'),
            'index_url' => static::getUrl('index'),
            'record_data' => static::participantDataFrom($existing),
        ]);
    }

    /**
     * The participant's relatively stable data, as carried over into a brand new
     * session by the "fetch data" action.
     *
     * Everything that belongs to the session rather than to the person is
     * deliberately excluded - the session date, the group number, and the
     * subject - so the user enters them fresh for this session. In particular the
     * subject is never copied: a participant who attended a BF Support session
     * may well be attending a Complementary Feeding one now.
     *
     * @return array<string, mixed>
     */
    public static function participantDataFrom(GroupSession $record): array
    {
        return [
            'id_number' => $record->id_number,
            'full_name_ar' => $record->full_name_ar,
            'locality' => $record->locality,
            'shelter_name' => $record->shelter_name,
            'category' => $record->category,
            'newborn_dob' => $record->newborn_dob?->format('Y-m-d'),
            'is_pwd' => (bool) $record->is_pwd,
            'marital_status' => $record->marital_status,
            'phone_number' => $record->phone_number,
            'has_gsfsh' => (bool) $record->has_gsfsh,
            'receives_supplementary' => $record->receives_supplementary,
        ];
    }

    /**
     * Human readable session subject, falling back to the free-text field when
     * the subject was recorded as "other".
     */
    public static function sessionSubjectLabel(GroupSession $record): string
    {
        if ($record->session_subject === 'other') {
            return filled($record->session_subject_other)
                ? $record->session_subject_other
                : __('fields.other');
        }

        return filled($record->session_subject) ? __('fields.' . $record->session_subject) : '-';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make(__('fields.group_session_data'))
                ->schema([
                    FilamentInfolist::date('session_date'),
                    FilamentInfolist::text('session_group_number'),
                    FilamentInfolist::enum('session_subject'),
                    FilamentInfolist::text('session_subject_other'),
                    FilamentInfolist::enum('locality'),
                    FilamentInfolist::enum('shelter_name'),
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
                    FilamentInfolist::boolean('has_gsfsh'),
                    FilamentInfolist::text('receives_supplementary'),
                ])->columns(2),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->select([
            'id', 'session_date', 'session_group_number', 'session_subject', 'locality',
            'shelter_name', 'id_number', 'full_name_ar', 'visit_type', 'category',
            'is_pwd', 'has_gsfsh', 'receives_supplementary',
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
                Tables\Columns\TextColumn::make('shelter_name')->label(__('fields.shelter_name'))->formatStateUsing(fn (string $state): string => __('fields.' . $state)),
                Tables\Columns\TextColumn::make('id_number')->label(__('fields.id_number'))->searchable(query: RecordSearch::identifier('id_number'))->sortable(),
                Tables\Columns\TextColumn::make('full_name_ar')->label(__('fields.full_name_ar'))->searchable(query: RecordSearch::name('full_name_ar'))->sortable(),
                Tables\Columns\TextColumn::make('visit_type')->label(__('fields.visit_type'))->badge()->formatStateUsing(fn (string $state): string => __('fields.' . $state)),
                Tables\Columns\TextColumn::make('category')->label(__('fields.category'))->formatStateUsing(fn (string $state): string => __('fields.' . $state)),
                YesNoColumn::make('is_pwd')->label(__('fields.is_pwd'))->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('has_gsfsh')->label(__('fields.has_gsfsh'))->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('receives_supplementary')->label(__('fields.receives_supplementary'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('session_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('locality')->label(__('fields.locality'))->options(static::localityOptions()),
                Tables\Filters\SelectFilter::make('shelter_name')->label(__('fields.shelter_name'))->options(static::shelterOptions()),
                Tables\Filters\SelectFilter::make('session_subject')->label(__('fields.session_subject'))->options(static::subjectOptions()),
                Tables\Filters\SelectFilter::make('visit_type')->label(__('fields.visit_type'))->options(static::visitTypeOptions()),
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
        return 'group_sessions';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroupSessions::route('/'),
            'create' => Pages\CreateGroupSession::route('/create'),
            'view' => Pages\ViewGroupSession::route('/{record}'),
            'edit' => Pages\EditGroupSession::route('/{record}/edit'),
        ];
    }

    public static function subjectOptions(): array { return ['bf_support' => __('fields.bf_support'), 'relactation' => __('fields.relactation'), 'complimentary_feeding' => __('fields.complimentary_feeding'), 'other' => __('fields.other')]; }
    public static function localityOptions(): array { return ['tal_al_hawa' => __('fields.tal_al_hawa'), 'el_saftawi' => __('fields.el_saftawi'), 'el_nafaq' => __('fields.el_nafaq'), 'el_shatee' => __('fields.el_shatee'), 'karamah' => __('fields.karamah')]; }
    public static function shelterOptions(): array { return ['mosaab_camp' => __('fields.mosaab_camp'), 'mahabba' => __('fields.mahabba'), 'el_salam' => __('fields.el_salam'), 'el_qoqa' => __('fields.el_qoqa'), 'al_helou' => __('fields.al_helou')]; }
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
