<?php

namespace App\Filament\Resources;

use App\Support\Forms\DigitStringField;
use App\Support\Forms\ReportingDateField;
use App\Support\RecordSearch;
use App\Filament\Concerns\AuthorizesModuleActions;
use App\Filament\Resources\PregnantLactatingWomanResource\Pages;
use App\Filament\Tables\Columns\YesNoColumn;
use App\Models\PregnantLactatingWoman;
use App\Support\FilamentInfolist;
use App\Support\PregnantWomanDuplicateChecker;
use App\Support\Forms\BooleanSelectField;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PregnantLactatingWomanResource extends Resource
{
    use AuthorizesModuleActions;

    protected static ?string $model = PregnantLactatingWoman::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-heart';

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.data');
    }

    public static function getModelLabel(): string
    {
        return __('ui.modules.pregnant_lactating_woman');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.modules.pregnant_lactating_woman');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                \Filament\Schemas\Components\Tabs::make('PLWFormTabs')
                    ->tabs([
                        \Filament\Schemas\Components\Tabs\Tab::make(__('ui.tabs.visit_and_location'))
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                static::getVisitDataSection(),
                                static::getLocationSection(),
                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make(__('ui.tabs.personal_and_husband'))
                            ->icon('heroicon-o-user')
                            ->schema([
                                static::getPersonalDataSection(),
                                static::getHusbandDataSection(),

                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make(__('ui.tabs.nutrition_measurements'))
                            ->icon('heroicon-o-scale')
                            ->schema([
                                static::getMeasurementsSection(),
                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make(__('ui.tabs.family_data'))
                            ->icon('heroicon-o-home')
                            ->schema([
                                static::getFamilyDataSection(),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    protected static function getVisitDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('ui.sections.visit_data'))
            ->schema([
                // Derived automatically by the system (first visit, or the
                // pregnant/lactating switch against the last active visit) and
                // locked so it can never be picked by hand.
                \Filament\Forms\Components\Select::make('visit_type')
                    ->label(__('fields.visit_type'))
                    ->options([
                        'new' => __('fields.new'),
                        'follow_up' => __('fields.follow_up'),
                    ])
                    ->default('new')
                    ->disabled()
                    ->dehydrated()
                    ->live(),
                \Filament\Forms\Components\TextInput::make('mother_id')
                    ->label(__('fields.mother_id'))
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
                    ->afterStateUpdated(fn (Get $get, Set $set, $livewire) => static::checkDuplicateMother($get, $set, $livewire))
                    // Only this field and the visit type it derives change, so
                    // only those two are re-rendered. Re-rendering the whole
                    // schema sent ~270 KB back on every blur of this field,
                    // which is what made the duplicate alert feel slow.
                    ->partiallyRenderAfterStateUpdated()
                    ->partiallyRenderComponentsAfterStateUpdated(['visit_type']),
                \Filament\Forms\Components\TextInput::make('full_name_ar')
                    ->label(__('fields.full_name_ar'))
                    ->required()
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('phone_number')
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
                \Filament\Forms\Components\TextInput::make('organization')
                    ->label(__('fields.organization'))
                    ->default('AEI')
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('implementing_partner')
                    ->label(__('fields.implementing_partner'))
                    ->default('SCI')
                    ->maxLength(255),
                \Filament\Forms\Components\DatePicker::make('date_of_reporting')
                    ->label(__('fields.date_of_reporting'))
                    ->default(now())
                    // Only while creating: on the edit form this validated the
                    // date the record was already saved with, so a record more
                    // than a day old could never be saved again.
                    ->minDate(fn ($livewire): ?string => ReportingDateField::minDate($livewire))
                    ->rules(fn ($livewire): array => ReportingDateField::rules($livewire))
                    ->validationMessages([
                        'required' => __('ui.validation.reporting_date_required'),
                        'after_or_equal' => __('ui.validation.reporting_date_not_past'),
                    ])
                    ->required(),
                \Filament\Forms\Components\TextInput::make('screener_profession')
                    ->label(__('fields.screener_profession'))
                    ->default('CHW')
                    ->maxLength(255),
            ])->columns(2);
    }

    /**
     * Recompute the locked visit type from the mother ID and the
     * pregnant/lactating status entered for this visit. Only meaningful while
     * creating a record: an existing record keeps the visit type it was saved
     * with.
     */
    public static function syncVisitType(Get $get, Set $set, $livewire): void
    {
        if (! $livewire instanceof \Filament\Resources\Pages\CreateRecord) {
            return;
        }

        $set('visit_type', PregnantWomanDuplicateChecker::resolveVisitType($get('mother_id'), $get('status_type')));
    }

    public static function checkDuplicateMother(Get $get, Set $set, $livewire): void
    {
        $motherId = $get('mother_id');
        if (blank($motherId) || ! is_object($livewire) || ! method_exists($livewire, 'dispatch')) {
            return;
        }

        $ignoreRecord = (isset($livewire->record) && $livewire->record instanceof \Illuminate\Database\Eloquent\Model)
            ? $livewire->record
            : null;

        // Soft-deleted records live in the trash and are not part of the system
        // any more, so the default (non-trashed) scope is what decides both the
        // duplicate alert and the visit type.
        $existing = PregnantWomanDuplicateChecker::latestActiveVisit($motherId, $ignoreRecord);

        static::syncVisitType($get, $set, $livewire);

        // A first visit is simply "new" - no alert, nothing to confirm.
        if (! $existing) {
            return;
        }

        $lastVisitDate = $existing->date_of_reporting ? $existing->date_of_reporting->format('Y-m-d') : ($existing->created_at ? $existing->created_at->format('Y-m-d') : '-');
        $lastVisitType = $existing->visit_type === 'follow_up'
            ? __('ui.visit_type.follow_up')
            : __('ui.visit_type.new');
        $lastStatusType = match ($existing->status_type) {
            'pregnant' => __('fields.pregnant'),
            'lactating' => __('fields.lactating'),
            default => '-',
        };

        // The relatively stable data only. This visit's own status
        // (pregnant/lactating), the newborn date that hangs off it, the
        // reporting date and the measurements are deliberately left out so they
        // stay empty and fully editable: picking the status is what settles the
        // visit type.
        $recordData = [
            'mother_id' => $existing->mother_id,
            'full_name_ar' => $existing->full_name_ar,
            'phone_number' => $existing->phone_number,
            'is_pwd' => (bool) $existing->is_pwd,
            'organization' => $existing->organization,
            'implementing_partner' => $existing->implementing_partner,
            'is_displaced' => (bool) $existing->is_displaced,
            'screener_profession' => $existing->screener_profession,
            'date_of_birth' => $existing->date_of_birth ? $existing->date_of_birth->format('Y-m-d') : null,
            'age_years' => $existing->age_years,
            'governorate' => $existing->governorate,
            'municipality' => $existing->municipality,
            'neighbourhood' => $existing->neighbourhood,
            'location' => $existing->location,
            'type_of_site' => $existing->type_of_site,
            'disability_type' => $existing->disability_type,
            'status' => $existing->status,
            'husband_id_number' => $existing->husband_id_number,
            'husband_full_name' => $existing->husband_full_name,
            'husband_phone' => $existing->husband_phone,
            'family_size' => $existing->family_size,
            'children_count' => $existing->children_count,
            'is_family_pwd' => (bool) $existing->is_family_pwd,
        ];

        $livewire->dispatch('show-duplicate-visit-alert', [
            'title' => __('ui.duplicate.woman_title'),
            'last_visit_date' => $lastVisitDate,
            'last_visit_type' => $lastVisitType,
            'last_status_type' => $lastStatusType,
            'visit_type_warning' => null,
            'confirm_button_text' => __('ui.duplicate.woman_confirm'),
            'action_type' => 'fill_mother',
            'index_url' => static::getUrl('index'),
            'record_data' => $recordData,
        ]);
    }

    protected static function getPersonalDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('ui.sections.personal_data'))
            ->schema([
                \Filament\Forms\Components\DatePicker::make('date_of_birth')
                    ->label(__('fields.date_of_birth'))
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, $state) => $set(
                        'age_years',
                        filled($state) ? (int) \Carbon\Carbon::parse($state)->diffInYears(now()) : null
                    )),
                \Filament\Forms\Components\TextInput::make('age_years')
                    ->label(__('fields.age_years'))
                    ->numeric()
                    ->disabled()
                    ->dehydrated(),
                // Switching between pregnant and lactating is an admission into
                // a different care cycle, so picking a status here re-derives
                // the locked visit type.
                \Filament\Forms\Components\Select::make('status_type')
                    ->label(__('fields.status_type'))
                    ->options(static::statusTypeOptions())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, $livewire) => static::syncVisitType($get, $set, $livewire)),
                 \Filament\Forms\Components\Select::make('status')
                    ->label(__('fields.status'))
                    ->required()
                    ->live()
                    ->options(static::maritalStatusOptions()),
                BooleanSelectField::make('is_pwd', __('fields.is_pwd'))
                    ->live(),
                BooleanSelectField::make('is_displaced', __('fields.is_displaced')),
                \Filament\Forms\Components\TextInput::make('disability_type')
                    ->label(__('fields.disability_type'))
                    ->required(fn (Get $get): bool => $get('is_pwd') === true)
                    ->visible(fn (Get $get): bool => $get('is_pwd') === true)
                    ->maxLength(255),
                \Filament\Forms\Components\DatePicker::make('newborn_dob')
                    ->label(__('fields.last_newborn_dob'))
                    // Shown for every status this module records: a mother
                    // who is both pregnant and breastfeeding has a newborn too.
                    ->visible(fn (Get $get): bool => array_key_exists((string) $get('status_type'), static::statusTypeOptions())),

            ])->columns(2);

    }

    protected static function getMeasurementsSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('ui.sections.measurements'))
            ->schema([
                \Filament\Forms\Components\TextInput::make('weight_kg')
                    ->label(__('fields.weight_kg'))
                    ->numeric(),
                \Filament\Forms\Components\TextInput::make('height_cm')
                    ->label(__('fields.height_cm'))
                    ->numeric(),
                \Filament\Forms\Components\TextInput::make('muac_mm')
                    ->label(__('fields.muac_mm'))
                    ->numeric()
                    ->required()
                    ->rules(['integer', 'min:1', 'max:250'])
                    ->validationMessages([
                        'required' => __('ui.validation.muac_required'),
                        'integer' => __('ui.validation.muac_integer'),
                        'min' => __('ui.validation.muac_range'),
                        'max' => __('ui.validation.muac_range'),
                    ])
                    ->live()
                    ->afterStateUpdated(fn (Set $set, $state) => $set('fi', PregnantLactatingWoman::classifyMuac($state))),
                \Filament\Forms\Components\TextInput::make('fi')
                    ->label(__('fields.fi'))
                    ->disabled()
                    ->dehydrated(false)
                    ->extraInputAttributes(fn (Get $get): array => match (PregnantLactatingWoman::classifyMuac($get('muac_mm'))) {
                        'Malnourished' => ['class' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400'],
                        'Normal' => ['class' => 'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400'],
                        default => [],
                    }),
                BooleanSelectField::make('has_oedema', __('fields.has_oedema')),
            ])->columns(2);
    }

    protected static function getLocationSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('ui.sections.location'))
            ->schema([
                \Filament\Forms\Components\TextInput::make('governorate')
                    ->label(__('fields.governorate'))
                    ->default('gaza')
                    ->required(),
                \Filament\Forms\Components\TextInput::make('municipality')
                    ->label(__('fields.municipality'))
                    ->default('gaza')
                    ->required(),
                \Filament\Forms\Components\Select::make('neighbourhood')
                    ->label(__('fields.neighbourhood'))
                    ->required()
                    ->options([
                        'El Shatee' => __('fields.el_shatee'),
                        'El Nafaq' => __('fields.el_nafaq'),
                        'El Saftawi' => __('fields.el_saftawi'),
                        'Tal EalHawa' => __('fields.tal_al_hawa'),
                    ]),
                \Filament\Forms\Components\TextInput::make('location')
                    ->label(__('fields.location'))
                    ->maxLength(255),
                \Filament\Forms\Components\Select::make('type_of_site')
                    ->label(__('fields.type_of_site'))
                    ->options([
                        'El Salam Camp' => __('fields.el_salam_camp'),
                        'Mossab Camp' => __('fields.mosaab_camp'),
                        'Mahabba Camp' => __('fields.mahabba'),
                        'El Qoqa' => __('fields.el_qoqa'),
                    ]),
            ])->columns(2);
    }

    // protected static function getAdditionalDataSection(): \Filament\Schemas\Components\Component
    // {
    //     return \Filament\Schemas\Components\Section::make(__('ui.sections.extra_data'))
    //         ->schema([
    //             \Filament\Forms\Components\TextInput::make('disability_type')
    //                 ->label(__('fields.disability_type'))
    //                 ->required(fn (Get $get): bool => $get('is_pwd') === true)
    //                 ->visible(fn (Get $get): bool => $get('is_pwd') === true)
    //                 ->maxLength(255),
    //             \Filament\Forms\Components\DatePicker::make('newborn_dob')
    //                 ->label(__('fields.last_newborn_dob'))
    //                 ->visible(fn (Get $get): bool => in_array($get('status_type'), ['pregnant', 'lactating'])),
    //             \Filament\Forms\Components\Select::make('status')
    //                 ->label(__('fields.status'))
    //                 ->required()
    //                 ->options([
    //                     'متزوجة' => 'متزوجة',
    //                     'أرملة' => 'أرملة',
    //                     'مطلقة' => 'مطلقة',
    //                     'منفصلة' => 'منفصلة',
    //                 ]),
    //         ])->columns(2);
    // }

    /**
     * The marital status the husband data is required for.
     */
    public const MARRIED_STATUS = 'متزوجة';

    /**
     * @return array<string, string>
     */
    /**
     * The three statuses this module records.
     *
     * The combined status was added to the database enum and to the import
     * synonym map, but never to this Select - and the Select is what both the
     * manual form and the importer validate against, so a file carrying
     * "حامل + مرضع" was refused by a system whose own column accepted it.
     *
     * @return array<string, string>
     */
    public static function statusTypeOptions(): array
    {
        return [
            'pregnant' => __('fields.pregnant'),
            'lactating' => __('fields.lactating'),
            'pregnant_lactating' => __('fields.pregnant_lactating'),
        ];
    }

    public static function maritalStatusOptions(): array
    {
        return [
            self::MARRIED_STATUS => self::MARRIED_STATUS,
            // The keys are what the column stores; only the labels
            // follow the panel's language.
            'أرملة' => __('ui.marital.widowed'),
            'مطلقة' => __('ui.marital.divorced'),
            'منفصلة' => __('ui.marital.separated'),
            'الزوج مفقود' => __('ui.marital.husband_missing'),
            // Two statuses the field workbooks already record. They were in the
            // import synonym map but not on the Select, and the Select is what
            // both the manual form and the importer validate against - so a
            // file carrying either one was refused.
            'مهجورة' => __('ui.marital.abandoned'),
            'معلقة' => __('ui.marital.suspended'),
        ];
    }

    /**
     * Husband data is only mandatory while the mother is married. Every other
     * marital status keeps the three fields visible and fillable, just optional.
     */
    public static function husbandDataIsRequired(mixed $maritalStatus): bool
    {
        return $maritalStatus === self::MARRIED_STATUS;
    }

    protected static function getHusbandDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('ui.sections.husband_data'))
            ->schema([
                \Filament\Forms\Components\TextInput::make('husband_full_name')
                    ->label(__('fields.husband_full_name'))
                    ->required(fn (Get $get): bool => static::husbandDataIsRequired($get('status')))
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('husband_id_number')
                    ->label(__('fields.husband_id_number'))
                    ->required(fn (Get $get): bool => static::husbandDataIsRequired($get('status')))
                    // A digit string, not a quantity: type="number" dropped the
                    // leading zero. @see \App\Support\Forms\DigitStringField
                    ->extraInputAttributes(DigitStringField::inputAttributes())
                    ->rules(['regex:/^[0-9]{9}$/'])
                    ->validationMessages([
                        'required' => __('ui.validation.husband_identity_required'),
                        'regex' => __('ui.validation.husband_identity_digits'),
                    ])
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('husband_phone')
                    ->label(__('fields.husband_phone'))
                    ->tel()
                    ->required(fn (Get $get): bool => static::husbandDataIsRequired($get('status')))
                    // A digit string, not a quantity: type="number" dropped the
                    // leading zero. @see \App\Support\Forms\DigitStringField
                    ->extraInputAttributes(DigitStringField::inputAttributes())
                    ->rules(['regex:/^[0-9]{10}$/'])
                    ->validationMessages([
                        'required' => __('ui.validation.husband_phone_required'),
                        'regex' => __('ui.validation.husband_phone_digits'),
                    ])
            ])->columns(2);
    }

    protected static function getFamilyDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('ui.sections.family_data'))
            ->schema([
                \Filament\Forms\Components\TextInput::make('family_size')
                    ->label(__('fields.family_size'))
                    ->numeric(),
                \Filament\Forms\Components\TextInput::make('children_count')
                    ->label(__('fields.children_count'))
                    ->numeric(),
                BooleanSelectField::make('is_family_pwd', __('fields.is_family_pwd')),
            ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make(__('fields.visit_data'))
                    ->schema([
                        FilamentInfolist::enum('visit_type'),
                        FilamentInfolist::text('mother_id'),
                        FilamentInfolist::text('full_name_ar'),
                        FilamentInfolist::text('phone_number'),
                        FilamentInfolist::text('organization'),
                        FilamentInfolist::text('implementing_partner'),
                        FilamentInfolist::date('date_of_reporting'),
                        FilamentInfolist::text('screener_profession'),
                    ])->columns(2),
                \Filament\Schemas\Components\Section::make(__('ui.sections.personal_data'))
                    ->schema([
                        FilamentInfolist::date('date_of_birth'),
                        FilamentInfolist::text('age_years'),
                        FilamentInfolist::enum('status_type'),
                        FilamentInfolist::boolean('is_pwd'),
                        FilamentInfolist::boolean('is_displaced'),
                    ])->columns(2),

                \Filament\Schemas\Components\Section::make(__('ui.sections.measurements'))
                    ->schema([
                        FilamentInfolist::text('weight_kg'),
                        FilamentInfolist::text('height_cm'),
                        FilamentInfolist::text('muac_mm'),
                        FilamentInfolist::text('fi'),
                        FilamentInfolist::boolean('has_oedema'),
                    ])->columns(2),
                \Filament\Schemas\Components\Section::make(__('ui.sections.location'))
                    ->schema([
                        FilamentInfolist::text('governorate'),
                        FilamentInfolist::text('municipality'),
                        FilamentInfolist::text('neighbourhood'),
                        FilamentInfolist::text('location'),
                        FilamentInfolist::text('type_of_site'),
                    ])->columns(2),
                \Filament\Schemas\Components\Section::make(__('ui.sections.extra_data'))
                    ->schema([
                        FilamentInfolist::text('disability_type'),
                        FilamentInfolist::date('newborn_dob'),
                        FilamentInfolist::text('status'),
                    ])->columns(2),
                \Filament\Schemas\Components\Section::make(__('ui.sections.husband_data'))
                    ->schema([
                        FilamentInfolist::text('husband_id_number'),
                        FilamentInfolist::text('husband_full_name'),
                        FilamentInfolist::text('husband_phone'),
                    ])->columns(2),
                \Filament\Schemas\Components\Section::make(__('ui.sections.family_data'))
                    ->schema([
                        FilamentInfolist::text('family_size'),
                        FilamentInfolist::text('children_count'),
                        FilamentInfolist::boolean('is_family_pwd'),
                    ])->columns(2),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->select([
            'id',
            'mother_id',
            'full_name_ar',
            'status_type',
            'governorate',
            'date_of_reporting',
            'muac_mm',
            'is_displaced',
            'is_pwd',
            'has_oedema',
            'is_family_pwd',
            'organization',
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
                Tables\Columns\TextColumn::make('mother_id')
                    ->label(__('fields.mother_id'))
                    ->searchable(query: RecordSearch::identifier('mother_id'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('full_name_ar')
                    ->label(__('fields.full_name_ar'))
                    ->searchable(query: RecordSearch::name('full_name_ar'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status_type')
                    ->label(__('fields.status_type'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pregnant' => 'warning',
                        'lactating' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('governorate')
                    ->label(__('fields.governorate'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_of_reporting')
                    ->label(__('fields.date_of_reporting'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('muac_mm')
                    ->label(__('fields.muac_mm'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fi')
                    ->label(__('fields.fi'))
                    ->state(fn (PregnantLactatingWoman $record): ?string => PregnantLactatingWoman::classifyMuac($record->muac_mm))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'Malnourished' => 'danger',
                        'Normal' => 'success',
                        default => 'gray',
                    }),
                YesNoColumn::make('is_displaced')
                    ->label(__('fields.is_displaced')),
                YesNoColumn::make('is_pwd')
                    ->label(__('fields.is_pwd'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('has_oedema')
                    ->label(__('fields.has_oedema'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('is_family_pwd')
                    ->label(__('fields.is_family_pwd'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('organization')
                    ->label(__('fields.organization'))
                    ->searchable(query: RecordSearch::identifier('organization'))
                    ->sortable(),
            ])
            ->defaultSort('date_of_reporting', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status_type')
                    ->label(__('fields.status_type'))
                    ->options(static::statusTypeOptions()),
                Tables\Filters\SelectFilter::make('governorate')
                    ->label(__('fields.governorate')),
                Tables\Filters\SelectFilter::make('type_of_site')
                    ->label(__('fields.type_of_site')),
                Tables\Filters\SelectFilter::make('is_displaced')
                    ->label(__('fields.is_displaced'))
                    ->options([
                        '1' => __('fields.yes'),
                        '0' => __('fields.no'),
                    ]),
                Tables\Filters\Filter::make('date_of_reporting')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')
                            ->label(__('fields.date_from')),
                        \Filament\Forms\Components\DatePicker::make('created_until')
                            ->label(__('fields.date_to')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->where('date_of_reporting', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->where('date_of_reporting', '<=', $date),
                            );
                    }),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->visible(fn (): bool => static::allowsAction('edit')),
                \Filament\Actions\ViewAction::make()
                    ->visible(fn (): bool => static::allowsAction('view')),
                \Filament\Actions\DeleteAction::make()
                    ->visible(fn (): bool => static::allowsAction('delete')),
                // hidden() rather than visible(): RestoreAction and
                // ForceDeleteAction already use visible() for their own
                // "is this row trashed?" check, and replacing it would put
                // them on live rows.
                \Filament\Actions\RestoreAction::make()
                    ->hidden(fn (): bool => ! static::allowsAction('delete')),
                \Filament\Actions\ForceDeleteAction::make()
                    ->hidden(fn (): bool => ! static::allowsAction('delete')),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \App\Filament\Actions\FastDeleteBulkAction::make()
                        ->visible(fn (): bool => static::allowsAction('delete')),
                    \App\Filament\Actions\FastRestoreBulkAction::make()
                        ->visible(fn (): bool => static::allowsAction('delete')),
                    \App\Filament\Actions\FastForceDeleteBulkAction::make()
                        ->visible(fn (): bool => static::allowsAction('delete')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Permission prefix every action on this module authorises against.
     */
    public static function permissionPrefix(): string
    {
        return 'pregnant';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPregnantLactatingWomen::route('/'),
            'create' => Pages\CreatePregnantLactatingWoman::route('/create'),
            'view' => Pages\ViewPregnantLactatingWoman::route('/{record}'),
            'edit' => Pages\EditPregnantLactatingWoman::route('/{record}/edit'),
        ];
    }
}
