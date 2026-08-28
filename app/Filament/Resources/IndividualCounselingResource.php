<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesModuleActions;
use App\Filament\Resources\IndividualCounselingResource\Pages;
use App\Filament\Tables\Columns\YesNoColumn;
use App\Models\IndividualCounseling;
use App\Support\FilamentInfolist;
use App\Support\Forms\BooleanSelectField;
use Filament\Forms;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IndividualCounselingResource extends Resource
{
    use AuthorizesModuleActions;

    protected static ?string $model = IndividualCounseling::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة البيانات';

    public static function getModelLabel(): string
    {
        return __('fields.individual_counseling');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fields.individual_counselings');
    }

    /** The Individual Counseling programme is delivered by a single fixed role. */
    public const HEALTH_EDUCATOR = 'Nutritionist';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Tabs::make('IndividualCounselingFormTabs')
                ->tabs([
                    Tab::make('visit_location')
                        ->label(__('fields.tab_visit_location'))
                        ->icon('heroicon-o-map-pin')
                        ->schema([
                            static::getVisitDataSection(),
                        ]),
                    Tab::make('child_parent_data')
                        ->label(__('fields.tab_child_parent_data'))
                        ->icon('heroicon-o-user')
                        ->schema([
                            static::getChildDataSection(),
                            static::getMotherDataSection(),
                        ]),
                    Tab::make('measurements_nutrition')
                        ->label(__('fields.tab_measurements_nutrition'))
                        ->icon('heroicon-o-scale')
                        ->schema([
                            static::getMeasurementsSection(),
                        ]),
                    Tab::make('family_special_cases')
                        ->label(__('fields.tab_family_special_cases'))
                        ->icon('heroicon-o-home')
                        ->schema([
                            static::getOptionalDataSection(),
                            static::getFollowUpSessionsSection(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * Tab 1 — when and where the counseling session took place.
     */
    protected static function getVisitDataSection(): Component
    {
        return Section::make(__('fields.counseling_data'))
            ->schema([
                Forms\Components\DatePicker::make('date')
                    ->label(__('fields.date'))
                    ->required()
                    ->default(now())
                    ->rules(['date'])
                    ->validationMessages(static::messagesFor('date', [
                        'required' => 'val_required',
                        'date' => 'val_date',
                    ])),
                // Fixed role: pre-filled on create and locked, while an existing
                // record keeps whatever it was already saved with.
                Forms\Components\TextInput::make('health_educator')
                    ->label(__('fields.health_educator'))
                    ->default(static::HEALTH_EDUCATOR)
                    ->disabled()
                    ->dehydrated()
                    ->maxLength(255),
                Forms\Components\Select::make('shelter_name')
                    ->label(__('fields.shelter_name'))
                    ->options(static::shelterOptions())
                    ->searchable()
                    ->native(false),
            ])->columns(2);
    }

    /**
     * Tab 2a — the child the session was about.
     */
    protected static function getChildDataSection(): Component
    {
        return Section::make(__('fields.child_data'))
            ->schema([
                Forms\Components\TextInput::make('child_name')
                    ->label(__('fields.child_name'))
                    ->required()
                    ->maxLength(255)
                    ->validationMessages(static::messagesFor('child_name', [
                        'required' => 'val_required',
                    ])),
                Forms\Components\Select::make('child_visit_type')
                    ->label(__('fields.child_visit_type'))
                    ->options(static::visitTypeOptions())
                    ->required()
                    ->native(false)
                    ->validationMessages(static::messagesFor('child_visit_type', [
                        'required' => 'val_required',
                    ])),
                Forms\Components\DatePicker::make('child_dob')
                    ->label(__('fields.child_dob'))
                    ->required()
                    ->rules(['date'])
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, $state) => $set(
                        'age_months',
                        IndividualCounseling::ageInMonths($state),
                    ))
                    ->validationMessages(static::messagesFor('child_dob', [
                        'required' => 'val_required',
                        'date' => 'val_date',
                    ])),
                Forms\Components\TextInput::make('age_months')
                    ->label(__('fields.age_months'))
                    ->numeric()
                    ->disabled()
                    ->dehydrated(),
                Forms\Components\Select::make('gender')
                    ->label(__('fields.gender'))
                    ->options(static::genderOptions())
                    ->required()
                    ->native(false)
                    ->validationMessages(static::messagesFor('gender', [
                        'required' => 'val_required',
                    ])),
            ])->columns(2);
    }

    /**
     * Tab 2b — the mother who attended the session.
     */
    protected static function getMotherDataSection(): Component
    {
        return Section::make(__('fields.mother_data'))
            ->schema([
                Forms\Components\Select::make('p_l')
                    ->label(__('fields.p_l'))
                    ->options(static::pregnantLactatingOptions())
                    ->required()
                    ->native(false)
                    ->validationMessages(static::messagesFor('p_l', [
                        'required' => 'val_required',
                    ])),
                Forms\Components\TextInput::make('mother_name')
                    ->label(__('fields.mother_name'))
                    ->required()
                    ->maxLength(255)
                    ->validationMessages(static::messagesFor('mother_name', [
                        'required' => 'val_required',
                    ])),
                Forms\Components\TextInput::make('mother_id_number')
                    ->label(__('fields.mother_id_number'))
                    ->required()
                    ->rules(['regex:/^[0-9]{9}$/'])
                    ->maxLength(9)
                    ->validationMessages(static::messagesFor('mother_id_number', [
                        'required' => 'val_required',
                        'regex' => 'val_digits_9',
                    ])),
                Forms\Components\Select::make('mother_visit_type')
                    ->label(__('fields.mother_visit_type'))
                    ->options(static::visitTypeOptions())
                    ->required()
                    ->native(false)
                    ->validationMessages(static::messagesFor('mother_visit_type', [
                        'required' => 'val_required',
                    ])),
                Forms\Components\DatePicker::make('mother_dob')
                    ->label(__('fields.mother_dob'))
                    ->required()
                    ->rules(['date'])
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, $state) => $set(
                        'mother_age_years',
                        IndividualCounseling::ageInYears($state),
                    ))
                    ->validationMessages(static::messagesFor('mother_dob', [
                        'required' => 'val_required',
                        'date' => 'val_date',
                    ])),
                Forms\Components\TextInput::make('mother_age_years')
                    ->label(__('fields.mother_age_years'))
                    ->numeric()
                    ->disabled()
                    ->dehydrated(),
                // A tel input rather than a numeric one, so a leading zero
                // survives; the regex already restricts it to 10 digits.
                Forms\Components\TextInput::make('mobile_number')
                    ->label(__('fields.mobile_number'))
                    ->tel()
                    ->required()
                    ->rules(['regex:/^[0-9]{10}$/'])
                    ->maxLength(10)
                    ->validationMessages(static::messagesFor('mobile_number', [
                        'required' => 'val_required',
                        'regex' => 'val_digits_10',
                    ])),
            ])->columns(2);
    }

    /**
     * Tab 3 — MUAC and feeding. The degree is never typed: it is derived from
     * the measurement by IndividualCounseling::classifyMuac.
     */
    protected static function getMeasurementsSection(): Component
    {
        return Section::make(__('fields.measurements'))
            ->schema([
                Forms\Components\TextInput::make('muac')
                    ->label(__('fields.muac'))
                    ->numeric()
                    ->required()
                    ->live(onBlur: true)
                    ->validationMessages(static::messagesFor('muac', [
                        'required' => 'val_required',
                        'numeric' => 'val_numeric',
                    ])),
                // Read-only and derived, so it is rendered as a badge rather
                // than an input. The classification is spelled out in the badge
                // text, so the colour reinforces the label instead of carrying
                // it alone, and Filament's own badge palette keeps the contrast
                // right in both the light and dark themes.
                TextEntry::make('muac_degree')
                    ->label(__('fields.muac_degree'))
                    ->helperText(__('fields.muac_degree_hint'))
                    ->state(fn (Get $get): ?string => IndividualCounseling::classifyMuac($get('muac')))
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (?string $state): string => static::muacDegreeColor($state)),
                Forms\Components\Select::make('child_age_lactated')
                    ->label(__('fields.child_age_lactated'))
                    ->options(static::childAgeLactatedOptions())
                    ->required()
                    ->native(false)
                    ->validationMessages(static::messagesFor('child_age_lactated', [
                        'required' => 'val_required',
                    ])),
                Forms\Components\TextInput::make('feeding_type')
                    ->label(__('fields.feeding_type'))
                    ->required()
                    ->maxLength(255)
                    ->validationMessages(static::messagesFor('feeding_type', [
                        'required' => 'val_required',
                    ])),
            ])->columns(2);
    }

    /**
     * Tab 4a — the optional fields, plus the two flags the programme still
     * insists on (IYCF form and status).
     */
    protected static function getOptionalDataSection(): Component
    {
        return Section::make(__('fields.optional_data'))
            ->description(__('fields.optional_data_hint'))
            ->collapsible()
            ->schema([
                Forms\Components\Select::make('consultation')
                    ->label(__('fields.consultation'))
                    ->options(static::consultationOptions())
                    ->native(false),
                BooleanSelectField::make('iycf_form_filled', __('fields.iycf_form_filled'))
                    // This one has always demanded a conscious answer, so it
                    // keeps its empty start and its required rule.
                    ->default(null)
                    ->required()
                    ->validationMessages(static::messagesFor('iycf_form_filled', [
                        'required' => 'val_required',
                    ])),
                Forms\Components\Select::make('status')
                    ->label(__('fields.status'))
                    ->options(static::statusOptions())
                    ->required()
                    ->native(false)
                    ->validationMessages(static::messagesFor('status', [
                        'required' => 'val_required',
                    ])),
                Forms\Components\Select::make('outcome')
                    ->label(__('fields.outcome'))
                    ->options(static::outcomeOptions())
                    ->native(false),
                Forms\Components\Select::make('assess')
                    ->label(__('fields.assess'))
                    ->options(static::assessOptions())
                    ->searchable()
                    ->native(false),
                Forms\Components\Select::make('pregnancy')
                    ->label(__('fields.pregnancy'))
                    ->options(static::yesNoTextOptions())
                    ->native(false),
                Forms\Components\Select::make('lactating')
                    ->label(__('fields.lactating'))
                    ->options(static::yesNoTextOptions())
                    ->native(false),
                Forms\Components\DatePicker::make('delivery_date')
                    ->label(__('fields.delivery_date'))
                    ->rules(['date'])
                    ->validationMessages(static::messagesFor('delivery_date', [
                        'date' => 'val_date',
                    ])),
                Forms\Components\TextInput::make('pregnancy_count')
                    ->label(__('fields.pregnancy_count'))
                    ->numeric()
                    ->minValue(0)
                    ->validationMessages(static::messagesFor('pregnancy_count', [
                        'numeric' => 'val_numeric',
                    ])),
                Forms\Components\Textarea::make('assess_and_analyze')
                    ->label(__('fields.assess_and_analyze'))
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('act')
                    ->label(__('fields.act'))
                    ->rows(3)
                    ->columnSpanFull(),
            ])->columns(2);
    }

    /**
     * Tab 4b — any number of follow-up sessions, stored one row per session in
     * individual_counseling_followups. A record may have none at all.
     */
    protected static function getFollowUpSessionsSection(): Component
    {
        return Section::make(__('fields.follow_up_sessions'))
            ->description(__('fields.follow_up_sessions_hint'))
            ->schema([
                Forms\Components\Repeater::make('followups')
                    ->label(__('fields.follow_up_sessions'))
                    ->hiddenLabel()
                    ->relationship()
                    ->schema([
                        Forms\Components\DatePicker::make('follow_up_visit_date')
                            ->label(__('fields.follow_up_visit_date'))
                            ->required()
                            ->rules(['date'])
                            ->validationMessages(static::messagesFor('follow_up_visit_date', [
                                'required' => 'val_required',
                                'date' => 'val_date',
                            ])),
                        Forms\Components\Textarea::make('assess_and_analyze')
                            ->label(__('fields.assess_and_analyze'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('act')
                            ->label(__('fields.act'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->itemNumbers()
                    ->itemLabel(fn (array $state): ?string => filled($state['follow_up_visit_date'] ?? null)
                        ? __('fields.follow_up_visit_date') . ': ' . $state['follow_up_visit_date']
                        : null)
                    ->defaultItems(0)
                    ->addActionLabel(__('fields.add_follow_up_session'))
                    ->orderColumn('sort_order')
                    ->collapsible()
                    ->deleteAction(fn (\Filament\Actions\Action $action) => $action->requiresConfirmation()),
            ]);
    }

    /**
     * Build the validation messages for one field.
     *
     * @param  array<string, string>  $rules  Laravel rule name => `fields.*` message key
     * @return array<string, string>
     */
    protected static function messagesFor(string $field, array $rules): array
    {
        $label = __('fields.' . $field);

        return array_map(
            fn (string $key): string => __('fields.' . $key, ['field' => $label]),
            $rules,
        );
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Tabs::make('IndividualCounselingInfolistTabs')
                ->tabs([
                    Tab::make('visit_location')
                        ->label(__('fields.tab_visit_location'))
                        ->icon('heroicon-o-map-pin')
                        ->schema([
                            Section::make(__('fields.counseling_data'))
                                ->schema([
                                    FilamentInfolist::date('date'),
                                    FilamentInfolist::text('health_educator'),
                                    FilamentInfolist::enum('shelter_name'),
                                ])->columns(2),
                        ]),
                    Tab::make('child_parent_data')
                        ->label(__('fields.tab_child_parent_data'))
                        ->icon('heroicon-o-user')
                        ->schema([
                            Section::make(__('fields.child_data'))
                                ->schema([
                                    FilamentInfolist::text('child_name'),
                                    FilamentInfolist::enum('child_visit_type'),
                                    FilamentInfolist::date('child_dob'),
                                    FilamentInfolist::text('age_months'),
                                    FilamentInfolist::enum('gender'),
                                ])->columns(2),
                            Section::make(__('fields.mother_data'))
                                ->schema([
                                    FilamentInfolist::enum('p_l'),
                                    FilamentInfolist::text('mother_name'),
                                    FilamentInfolist::text('mother_id_number'),
                                    FilamentInfolist::enum('mother_visit_type'),
                                    FilamentInfolist::date('mother_dob'),
                                    FilamentInfolist::text('mother_age_years'),
                                    FilamentInfolist::text('mobile_number'),
                                ])->columns(2),
                        ]),
                    Tab::make('measurements_nutrition')
                        ->label(__('fields.tab_measurements_nutrition'))
                        ->icon('heroicon-o-scale')
                        ->schema([
                            Section::make(__('fields.measurements'))
                                ->schema([
                                    FilamentInfolist::text('muac'),
                                    FilamentInfolist::text('muac_degree')
                                        ->badge()
                                        ->color(fn (?string $state): string => static::muacDegreeColor($state)),
                                    FilamentInfolist::enum('child_age_lactated'),
                                    FilamentInfolist::text('feeding_type'),
                                ])->columns(2),
                        ]),
                    Tab::make('family_special_cases')
                        ->label(__('fields.tab_family_special_cases'))
                        ->icon('heroicon-o-home')
                        ->schema([
                            Section::make(__('fields.optional_data'))
                                ->schema([
                                    FilamentInfolist::enum('consultation'),
                                    FilamentInfolist::boolean('iycf_form_filled'),
                                    FilamentInfolist::enum('status'),
                                    FilamentInfolist::enum('outcome'),
                                    FilamentInfolist::text('assess'),
                                    FilamentInfolist::enum('pregnancy'),
                                    FilamentInfolist::enum('lactating'),
                                    FilamentInfolist::date('delivery_date'),
                                    FilamentInfolist::text('pregnancy_count'),
                                    FilamentInfolist::text('assess_and_analyze')->columnSpanFull(),
                                    FilamentInfolist::text('act')->columnSpanFull(),
                                ])->columns(2),
                            Section::make(__('fields.follow_up_sessions'))
                                ->schema([
                                    RepeatableEntry::make('followups')
                                        ->label(__('fields.follow_up_sessions'))
                                        ->hiddenLabel()
                                        ->schema([
                                            FilamentInfolist::date('follow_up_visit_date'),
                                            FilamentInfolist::text('assess_and_analyze'),
                                            FilamentInfolist::text('act'),
                                        ])
                                        ->columns(3),
                                ]),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * Badge colour for a MUAC classification, shared by the table and infolist.
     */
    public static function muacDegreeColor(?string $degree): string
    {
        return match ($degree) {
            'SAM' => 'danger',
            'MAM' => 'warning',
            'Normal' => 'success',
            default => 'gray',
        };
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->select([
            'id', 'date', 'health_educator', 'child_name', 'child_visit_type', 'gender',
            'p_l', 'muac', 'muac_degree', 'mother_id_number', 'mother_name',
            'shelter_name', 'consultation', 'iycf_form_filled', 'status', 'outcome',
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
                Tables\Columns\TextColumn::make('date')->label(__('fields.date'))->date()->sortable(),
                Tables\Columns\TextColumn::make('child_name')->label(__('fields.child_name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('mother_name')->label(__('fields.mother_name'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('mother_id_number')->label(__('fields.mother_id_number'))->searchable(),
                Tables\Columns\TextColumn::make('health_educator')->label(__('fields.health_educator'))->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('child_visit_type')->label(__('fields.child_visit_type'))->badge()->formatStateUsing(fn (?string $state): ?string => filled($state) ? __('fields.' . $state) : null),
                Tables\Columns\TextColumn::make('gender')->label(__('fields.gender'))->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('muac')->label(__('fields.muac'))->sortable(),
                Tables\Columns\TextColumn::make('muac_degree')
                    ->label(__('fields.muac_degree'))
                    ->state(fn (IndividualCounseling $record): ?string => IndividualCounseling::classifyMuac($record->muac))
                    ->badge()
                    ->color(fn (?string $state): string => static::muacDegreeColor($state)),
                Tables\Columns\TextColumn::make('consultation')->label(__('fields.consultation'))->formatStateUsing(fn (?string $state): ?string => filled($state) ? __('fields.' . $state) : null)->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('iycf_form_filled')->label(__('fields.iycf_form_filled'))->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')->label(__('fields.status'))->badge()->formatStateUsing(fn (?string $state): ?string => filled($state) ? __('fields.' . $state) : null),
                Tables\Columns\TextColumn::make('outcome')->label(__('fields.outcome'))->badge()->formatStateUsing(fn (?string $state): ?string => filled($state) ? __('fields.' . $state) : null),
                Tables\Columns\TextColumn::make('shelter_name')->label(__('fields.shelter_name'))->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(__('fields.status'))->options(static::statusOptions()),
                Tables\Filters\SelectFilter::make('outcome')->label(__('fields.outcome'))->options(static::outcomeOptions()),
                Tables\Filters\SelectFilter::make('child_visit_type')->label(__('fields.child_visit_type'))->options(static::visitTypeOptions()),
                Tables\Filters\Filter::make('shelter_name')
                    ->form([
                        Forms\Components\TextInput::make('shelter_name')->label(__('fields.shelter_name')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['shelter_name'] ?? null, fn (Builder $query, string $value): Builder => $query->where('shelter_name', 'like', "%{$value}%"))),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('fields.date_from')),
                        Forms\Components\DatePicker::make('until')->label(__('fields.date_to')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('date', '<=', $date))),
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
        return 'individual_counseling';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIndividualCounselings::route('/'),
            'create' => Pages\CreateIndividualCounseling::route('/create'),
            'view' => Pages\ViewIndividualCounseling::route('/{record}'),
            'edit' => Pages\EditIndividualCounseling::route('/{record}/edit'),
        ];
    }

    public static function visitTypeOptions(): array { return ['new' => __('fields.new'), 'follow_up' => __('fields.follow_up')]; }
    public static function genderOptions(): array { return ['M' => __('fields.M'), 'F' => __('fields.F')]; }
    public static function pregnantLactatingOptions(): array { return ['L' => __('fields.L'), 'P' => __('fields.P'), 'P+L' => __('fields.P+L')]; }
    public static function childAgeLactatedOptions(): array { return ['less_6_months' => __('fields.less_6_months'), '6_23_months' => __('fields.6_23_months'), '24_59_months' => __('fields.24_59_months')]; }
    public static function shelterOptions(): array { return ['mosaab_camp' => __('fields.mosaab_camp'), 'mahabba' => __('fields.mahabba'), 'el_salam' => __('fields.el_salam'), 'el_qoqa' => __('fields.el_qoqa')]; }
    public static function yesNoOptions(): array { return [1 => __('fields.yes'), 0 => __('fields.no')]; }
    public static function yesNoTextOptions(): array { return ['yes' => __('fields.yes'), 'no' => __('fields.no')]; }
    public static function consultationOptions(): array { return ['complementary_feeding' => __('fields.complementary_feeding'), 'bf_support' => __('fields.bf_support'), 'relactation' => __('fields.relactation'), 'other' => __('fields.other')]; }
    public static function statusOptions(): array { return ['discharged' => __('fields.discharged'), 'under_follow_up' => __('fields.under_follow_up')]; }
    public static function outcomeOptions(): array { return ['improved' => __('fields.improved'), 'dont_improve' => __('fields.dont_improve'), 'non_response' => __('fields.non_response')]; }

    public static function assessOptions(): array
    {
        return array_combine(static::assessValues(), static::assessValues());
    }

    public static function actOptions(): array
    {
        return array_combine(static::actValues(), static::actValues());
    }

    public static function assessAndAnalyzeOptions(): array
    {
        return array_combine(static::assessAndAnalyzeValues(), static::assessAndAnalyzeValues());
    }

    protected static function assessValues(): array
    {
        return [
            'Malnutrition',
            'اعتقاد الام بعدم وجود حليب في الثدي',
            'اعتماد الطفل على الحليب الصناعي',
            'الرضاعة من ثدي واحد',
            'الطفلة تعاني من قلة في الوزن مع عدم وجود سوء تغذية',
            'صعوبة الرضاعة الطبيعية',
            'سوء تغذية',
            'الام غير قادرة على الرضاعة مع الحمل وعدم كفاية الحليب الطبيعي للطفل',
        ];
    }

    protected static function actValues(): array
    {
        return [
            'explain the importance of diverse food type, quantity',
            'تم توعية الام بأهمية الرضاعة الطبيعية للطفل في هذا العمر وخصوصاً بالنسبة لحالته لتقوية المناعة',
            'تم توعية الام بأهمية الرضاعة الطبيعية وتقليل الحليب الصناعي',
            'تم توعية الام بأهمية الغذاء التكميلي كميته ونوعه',
            'زيادة وعي الام بأهمية الرضاعة ومساوئ الحليب الصناعي',
            'توعية الام بأهمية الرضاعة الطبيعية وإيقاف الحليب الصناعي',
            'زيادة وعي الام بالرضاعة أثناء الحمل وطريقة تغذيتها',
            'توعية الأب بتحديد كمية وعدد الوجبات ونوعها لطفله',
            'توعية الام بكمية الوجبات المتناولة يومياً وإضافة النكهات للطعام لتقبل الطفلة له وتحضيره بأكثر من طريقة وضم الطفلة مع أطفال آخرين أثناء تناول الطعام لتشجيعها',
        ];
    }

    protected static function assessAndAnalyzeValues(): array
    {
        return [
            'poor nutrition due to low socioeconomic status',
            'قلة تغذية الام المرضعة / قلة وعي الام بإمكانية زيادة الحليب الطبيعي بزيادة الرضاعة',
            'قلة تغذية الام المرضعة الحامل',
            'الام لا تعاني من أي مشاكل - توعية الام بإرضاع الطفل من كلا الثديين بعد الشفاء من الالتهابات وزيادة عدد الرضعات ومدتها لتعويض النقص في الثدي الآخر وعدم إعطاء الطفل أي طعام تكميلي حتى يتجاوز 6 أشهر',
            'التوعية بأهمية الرضاعة الطبيعية لرفع مناعة الطفل وتوعية الام بعدم إعطاء الطفل أي غذاء تكميلي حتى يصبح عمره 6 أشهر',
            'توعية الام بطريقة إعداد الطعام التكميلي وأخذ RUTF',
        ];
    }
}
