<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesModuleActions;
use App\Filament\Resources\ChildResource\Pages;
use App\Filament\Tables\Columns\YesNoColumn;
use App\Models\Child;
use App\Support\ChildDuplicateChecker;
use App\Support\FilamentInfolist;
use App\Support\Forms\BooleanSelectField;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ChildResource extends Resource
{
    use AuthorizesModuleActions;

    protected static ?string $model = Child::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة البيانات';

    public static function getModelLabel(): string
    {
        return __('fields.child');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fields.children');
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                \Filament\Schemas\Components\Tabs::make('ChildFormTabs')
                    ->tabs([
                        \Filament\Schemas\Components\Tabs\Tab::make('الزيارة والموقع')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                static::getVisitDataSection(),
                                static::getLocationSection(),
                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make('بيانات الطفل والوالدين')
                            ->icon('heroicon-o-user')
                            ->schema([
                                static::getChildDataSection(),
                                static::getMotherDataSection(),
                                static::getFatherDataSection(),
                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make('القياسات والتغذية')
                            ->icon('heroicon-o-scale')
                            ->schema([
                                static::getMeasurementsSection(),
                                static::getNutritionProgramSection(),
                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make('الأسرة والحالات الخاصة')
                            ->icon('heroicon-o-home')
                            ->schema([
                                static::getFamilyDataSection(),
                                static::getDisabilitySection(),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    protected static function getVisitDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.visit_data'))
            ->schema([
                // Derived automatically by the system (first visit, or the relapse
                // check against the last active visit) and locked so it can never
                // be picked by hand.
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
                \Filament\Forms\Components\TextInput::make('child_id')
                    ->label(__('fields.child_id'))
                    ->required()
                    ->numeric()
                    ->rules(['regex:/^[0-9]{9}$/'])
                    ->validationMessages([
                        'required' => 'رقم هوية الطفل مطلوب.',
                        'numeric' => 'رقم هوية الطفل يجب أن يكون رقماً.',
                        'regex' => 'رقم هوية الطفل يجب أن يتكون من 9 أرقام بالضبط.',
                    ])
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, $livewire) => static::checkDuplicateChild($get, $set, $livewire)),
                \Filament\Forms\Components\TextInput::make('name')
                    ->label(__('fields.name'))
                    ->required()
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('phone_number')
                    ->label(__('fields.phone_number'))
                    ->tel()
                    ->required()
                    ->numeric()
                    ->rules(['regex:/^[0-9]{10}$/'])
                    ->validationMessages([
                        'required' => 'رقم الهاتف مطلوب.',
                        'numeric' => 'رقم الهاتف يجب أن يكون رقماً.',
                        'regex' => 'رقم الهاتف يجب أن يتكون من 10 أرقام بالضبط.',
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
                    ->minDate(now()->startOfDay())
                    ->rules(['after_or_equal:today'])
                    ->validationMessages([
                        'required' => 'تاريخ التقرير مطلوب.',
                        'after_or_equal' => 'لا يمكنك اختيار تاريخ قبل اليوم.',
                    ])
                    ->required(),
                \Filament\Forms\Components\TextInput::make('screener_profession')
                    ->label(__('fields.screener_profession'))
                    ->default('CHW')
                    ->maxLength(255),
            ])->columns(2);
    }

    /**
     * Recompute the locked visit type from the child ID and the MUAC entered
     * for this visit. Only meaningful while creating a record: an existing
     * record keeps the visit type it was saved with.
     */
    public static function syncVisitType(Get $get, Set $set, $livewire): void
    {
        if (! $livewire instanceof \Filament\Resources\Pages\CreateRecord) {
            return;
        }

        $set('visit_type', ChildDuplicateChecker::resolveVisitType($get('child_id'), $get('muac_mm')));
    }

    public static function checkDuplicateChild(Get $get, Set $set, $livewire): void
    {
        $childId = $get('child_id');
        if (blank($childId) || ! is_object($livewire) || ! method_exists($livewire, 'dispatch')) {
            return;
        }

        $ignoreRecord = (isset($livewire->record) && $livewire->record instanceof \Illuminate\Database\Eloquent\Model)
            ? $livewire->record
            : null;

        // Soft-deleted children live in the trash and are not part of the system
        // any more, so the default (non-trashed) scope is what decides both the
        // duplicate alert and the visit type.
        $existing = ChildDuplicateChecker::latestActiveVisit($childId, $ignoreRecord);

        static::syncVisitType($get, $set, $livewire);

        // Rule 2: a first visit is simply "new" - no alert, nothing to confirm.
        if (! $existing) {
            return;
        }

        $lastVisitDate = $existing->date_of_reporting ? $existing->date_of_reporting->format('Y-m-d') : ($existing->created_at ? $existing->created_at->format('Y-m-d') : '-');
        $lastVisitType = $existing->visit_type === 'follow_up' ? 'متابعة' : 'جديد';

        $recordData = [
            'child_id' => $existing->child_id,
            'name' => $existing->name,
            'phone_number' => $existing->phone_number,
            'is_pwd' => (bool) $existing->is_pwd,
            'organization' => $existing->organization,
            'implementing_partner' => $existing->implementing_partner,
            'is_displaced' => (bool) $existing->is_displaced,
            'screener_profession' => $existing->screener_profession,
            'sex' => $existing->sex,
            'date_of_birth' => $existing->date_of_birth ? $existing->date_of_birth->format('Y-m-d') : null,
            'age_months' => $existing->age_months,
            'governorate' => $existing->governorate,
            'municipality' => $existing->municipality,
            'neighbourhood' => $existing->neighbourhood,
            'location' => $existing->location,
            'type_of_site' => $existing->type_of_site,
            'is_enrolled_bsfp' => (bool) $existing->is_enrolled_bsfp,
            'is_sick_last_6_months' => (bool) $existing->is_sick_last_6_months,
            'is_mother_alive' => (bool) $existing->is_mother_alive,
            'mother_full_name' => $existing->mother_full_name,
            'mother_id_number' => $existing->mother_id_number,
            'mother_date_of_birth' => $existing->mother_date_of_birth ? $existing->mother_date_of_birth->format('Y-m-d') : null,
            'mother_age_years' => $existing->mother_age_years,
            'mother_phone' => $existing->mother_phone,
            'father_full_name' => $existing->father_full_name,
            'father_id_number' => $existing->father_id_number,
            'father_phone' => $existing->father_phone,
            'has_lactating_woman' => (bool) $existing->has_lactating_woman,
            'has_pregnant_last_trimester' => (bool) $existing->has_pregnant_last_trimester,
            'children_under_5' => $existing->children_under_5,
            'head_of_household_sex' => $existing->head_of_household_sex,
            'mother_marital_status' => $existing->mother_marital_status,
            'mother_muac_mm' => $existing->mother_muac_mm,
            'is_mother_malnourished' => (bool) $existing->is_mother_malnourished,
            'has_stable_income' => (bool) $existing->has_stable_income,
            'income_source' => $existing->income_source,
            'is_income_below_500' => (bool) $existing->is_income_below_500,
            'male_children_under_5' => $existing->male_children_under_5,
            'female_children_under_5' => $existing->female_children_under_5,
            'family_size' => $existing->family_size,
            'current_address' => $existing->current_address,
            'original_address' => $existing->original_address,
            'has_family_disability' => (bool) $existing->has_family_disability,
            'disability_cause' => $existing->disability_cause,
            'disability_cause_other' => $existing->disability_cause_other,
            'has_injured_after_oct7' => (bool) $existing->has_injured_after_oct7,
            'injured_count' => $existing->injured_count,
            'has_unaccompanied_children' => (bool) $existing->has_unaccompanied_children,
            'unaccompanied_children_count' => $existing->unaccompanied_children_count,
            'has_released_children' => (bool) $existing->has_released_children,
        ];

        $livewire->dispatch('show-duplicate-visit-alert', [
            'title' => 'هذا الطفل موجود مسبقاً في النظام',
            'last_visit_date' => $lastVisitDate,
            'last_visit_type' => $lastVisitType,
            'visit_type_warning' => null,
            'confirm_button_text' => 'جلب البيانات وتحويل نوع الزيارة',
            'action_type' => 'fill_child',
            'index_url' => static::getUrl('index'),
            'record_data' => $recordData,
        ]);
    }

    protected static function getChildDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.child_data'))
            ->schema([
                \Filament\Forms\Components\Select::make('sex')
                    ->label(__('fields.sex'))
                    ->options([
                        'male' => __('fields.male'),
                        'female' => __('fields.female'),
                    ])
                    ->required(),
                \Filament\Forms\Components\DatePicker::make('date_of_birth')
                    ->label(__('fields.date_of_birth'))
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, $state) => $set(
                        'age_months',
                        filled($state) ? (int) \Carbon\Carbon::parse($state)->diffInMonths(now()) : null
                    )),
                \Filament\Forms\Components\TextInput::make('age_months')
                    ->label(__('fields.age_months'))
                    ->numeric()
                    ->disabled()
                    ->dehydrated(),
                BooleanSelectField::make('is_pwd', __('fields.is_pwd')),
                BooleanSelectField::make('is_displaced', __('fields.is_displaced')),
            ])->columns(2);
    }

    protected static function getMeasurementsSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.measurements'))
            ->schema([
                \Filament\Forms\Components\TextInput::make('muac_mm')
                    ->label(__('fields.muac_mm'))
                    ->numeric()
                    ->required()
                    ->rules(['integer', 'min:1', 'max:200'])
                    ->validationMessages([
                        'required' => 'منتصف العضد مطلوب.',
                        'integer' => 'منتصف العضد يجب أن يكون رقماً صحيحاً.',
                        'min' => 'منتصف العضد يجب أن يكون بين 1 و 200.',
                        'max' => 'منتصف العضد يجب أن يكون بين 1 و 200.',
                    ])
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, $livewire, $state): void {
                        // FI stays derived from this visit's MUAC alone, and the
                        // relapse check then re-derives the visit type from it.
                        $set('fi', Child::classifyMuac($state));
                        static::syncVisitType($get, $set, $livewire);
                    }),
                \Filament\Forms\Components\TextInput::make('weight_kg')
                    ->label(__('fields.weight_kg'))
                    ->numeric(),
                \Filament\Forms\Components\TextInput::make('height_cm')
                    ->label(__('fields.height_cm'))
                    ->numeric(),
                \Filament\Forms\Components\TextInput::make('whz')
                    ->label(__('fields.whz'))
                    ->numeric(),
                \Filament\Forms\Components\TextInput::make('fi')
                    ->label(__('fields.fi'))
                    ->disabled()
                    ->dehydrated(false)
                    ->extraInputAttributes(fn (Get $get): array => match (Child::classifyMuac($get('muac_mm'))) {
                        'SAM' => ['class' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400'],
                        'MAM' => ['class' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400'],
                        'Normal' => ['class' => 'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400'],
                        default => [],
                    }),
                BooleanSelectField::make('has_oedema', __('fields.has_oedema')),
            ])->columns(2);
    }

    protected static function getLocationSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.location'))
            ->schema([
                \Filament\Forms\Components\TextInput::make('governorate')
                    ->label(__('fields.governorate'))
                    ->default('gaza')
                    ->required(),
                \Filament\Forms\Components\TextInput::make('municipality')
                    ->label(__('fields.municipality'))
                    ->default('gaza')
                    ->required(),
                \Filament\Forms\Components\TextInput::make('neighbourhood')
                    ->label(__('fields.neighbourhood'))
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('location')
                    ->label(__('fields.location'))
                    ->maxLength(255),
                \Filament\Forms\Components\Select::make('type_of_site')
                    ->label(__('fields.type_of_site'))
                    ->required()
                    ->options([
                        'El Salam Camp' => __('fields.el_salam_camp'),
                        'Mossab Camp' => __('fields.mosaab_camp'),
                        'Mahabba Camp' => __('fields.mahabba'),
                        'El Qoqa' => __('fields.el_qoqa'),
                    ]),
            ])->columns(2);
    }

    protected static function getNutritionProgramSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.nutrition_program'))
            ->schema([
                BooleanSelectField::make('is_enrolled_bsfp', __('fields.is_enrolled_bsfp')),
                BooleanSelectField::make('is_sick_last_6_months', __('fields.is_sick_last_6_months')),
                BooleanSelectField::make('is_mother_alive', __('fields.is_mother_alive')),
            ])->columns(3);
    }

    protected static function getMotherDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.mother_data'))
            ->schema([
                \Filament\Forms\Components\TextInput::make('mother_full_name')
                    ->label(__('fields.mother_full_name'))
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('mother_id_number')
                    ->label(__('fields.mother_id_number'))
                    ->maxLength(255),
                \Filament\Forms\Components\DatePicker::make('mother_date_of_birth')
                    ->label(__('fields.mother_date_of_birth'))
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, $state) => $set(
                        'mother_age_years',
                        filled($state) ? (int) \Carbon\Carbon::parse($state)->diffInYears(now()) : null
                    )),
                \Filament\Forms\Components\TextInput::make('mother_age_years')
                    ->label(__('fields.mother_age_years'))
                    ->numeric()
                    ->disabled()
                    ->dehydrated(),
                \Filament\Forms\Components\TextInput::make('mother_phone')
                    ->label(__('fields.mother_phone'))
                    ->tel()
                    ->maxLength(255),
                \Filament\Forms\Components\Select::make('mother_marital_status')
                    ->label(__('fields.mother_marital_status'))
                    ->required()
                    ->options([
                        'متزوجة' => 'متزوجة',
                        'مطلقة' => 'مطلقة',
                        'أرملة' => 'أرملة',
                        'منفصلة' => 'منفصلة',
                    ]),
                \Filament\Forms\Components\TextInput::make('mother_muac_mm')
                    ->label(__('fields.mother_muac_mm'))
                    ->numeric(),
                BooleanSelectField::make('is_mother_malnourished', __('fields.is_mother_malnourished')),
            ])->columns(2);
    }

    protected static function getFatherDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.father_data'))
            ->schema([
                \Filament\Forms\Components\TextInput::make('father_full_name')
                    ->label(__('fields.father_full_name'))
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('father_id_number')
                    ->label(__('fields.father_id_number'))
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('father_phone')
                    ->label(__('fields.father_phone'))
                    ->tel()
                    ->maxLength(255),
            ])->columns(2);
    }

    protected static function getFamilyDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.family_data'))
            ->schema([
                BooleanSelectField::make('has_lactating_woman', __('fields.has_lactating_woman')),
                BooleanSelectField::make('has_pregnant_last_trimester', __('fields.has_pregnant_last_trimester')),
                \Filament\Forms\Components\TextInput::make('children_under_5')
                    ->label(__('fields.children_under_5'))
                    ->numeric()
                    ->default(0),
                \Filament\Forms\Components\Select::make('head_of_household_sex')
                    ->label(__('fields.head_of_household_sex'))
                    ->options([
                        'male' => __('fields.male'),
                        'female' => __('fields.female'),
                    ]),
                BooleanSelectField::make('has_stable_income', __('fields.has_stable_income')),
                \Filament\Forms\Components\Select::make('income_source')
                    ->label(__('fields.income_source'))
                    ->options([
                        'government' => __('fields.government'),
                        'unrwa' => __('fields.unrwa'),
                        'other' => __('fields.other'),
                    ]),
                BooleanSelectField::make('is_income_below_500', __('fields.is_income_below_500')),
                \Filament\Forms\Components\TextInput::make('male_children_under_5')
                    ->label(__('fields.male_children_under_5'))
                    ->numeric()
                    ->default(0),
                \Filament\Forms\Components\TextInput::make('female_children_under_5')
                    ->label(__('fields.female_children_under_5'))
                    ->numeric()
                    ->default(0),
                \Filament\Forms\Components\TextInput::make('family_size')
                    ->label(__('fields.family_size'))
                    ->numeric()
                    ->default(1),
                \Filament\Forms\Components\TextInput::make('current_address')
                    ->label(__('fields.current_address'))
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('original_address')
                    ->label(__('fields.original_address'))
                    ->maxLength(255),
            ])->columns(2);
    }

    protected static function getDisabilitySection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make(__('fields.disability_and_special_situations'))
            ->schema([
                BooleanSelectField::make('has_family_disability', __('fields.has_family_disability'))
                    ->live(),
                \Filament\Forms\Components\Select::make('disability_cause')
                    ->label(__('fields.disability_cause'))
                    ->options([
                        'war' => __('fields.war'),
                        'other' => __('fields.other'),
                    ])
                    ->visible(fn (Get $get) => $get('has_family_disability') === true)
                    ->live(),
                \Filament\Forms\Components\Textarea::make('disability_cause_other')
                    ->label(__('fields.disability_cause_other'))
                    ->visible(fn (Get $get) => $get('disability_cause') === 'other'),
                BooleanSelectField::make('has_injured_after_oct7', __('fields.has_injured_after_oct7'))
                    ->live(),
                \Filament\Forms\Components\TextInput::make('injured_count')
                    ->label(__('fields.injured_count'))
                    ->numeric()
                    ->visible(fn (Get $get) => $get('has_injured_after_oct7') === true),
                BooleanSelectField::make('has_unaccompanied_children', __('fields.has_unaccompanied_children'))
                    ->live(),
                \Filament\Forms\Components\TextInput::make('unaccompanied_children_count')
                    ->label(__('fields.unaccompanied_children_count'))
                    ->numeric()
                    ->visible(fn (Get $get) => $get('has_unaccompanied_children') === true),
                BooleanSelectField::make('has_released_children', __('fields.has_released_children')),
            ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Tabs::make('ChildInfolistTabs')
                    ->tabs([
                        \Filament\Schemas\Components\Tabs\Tab::make('الزيارة والموقع')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                \Filament\Schemas\Components\Section::make(__('fields.visit_data'))
                                    ->schema([
                                        FilamentInfolist::enum('visit_type'),
                                        FilamentInfolist::text('child_id'),
                                        FilamentInfolist::text('name'),
                                        FilamentInfolist::text('phone_number'),
                                        FilamentInfolist::text('organization'),
                                        FilamentInfolist::text('implementing_partner'),
                                        FilamentInfolist::date('date_of_reporting'),
                                        FilamentInfolist::text('screener_profession'),
                                    ])->columns(2),
                                \Filament\Schemas\Components\Section::make(__('fields.location'))
                                    ->schema([
                                        FilamentInfolist::text('governorate'),
                                        FilamentInfolist::text('municipality'),
                                        FilamentInfolist::text('neighbourhood'),
                                        FilamentInfolist::text('location'),
                                        FilamentInfolist::enum('type_of_site'),
                                    ])->columns(2),
                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make('بيانات الطفل والوالدين')
                            ->icon('heroicon-o-user')
                            ->schema([
                                \Filament\Schemas\Components\Section::make(__('fields.child_data'))
                                    ->schema([
                                        FilamentInfolist::enum('sex'),
                                        FilamentInfolist::date('date_of_birth'),
                                        FilamentInfolist::text('age_months'),
                                        FilamentInfolist::boolean('is_pwd'),
                                        FilamentInfolist::boolean('is_displaced'),
                                    ])->columns(2),
                                \Filament\Schemas\Components\Section::make(__('fields.mother_data'))
                                    ->schema([
                                        FilamentInfolist::text('mother_full_name'),
                                        FilamentInfolist::text('mother_id_number'),
                                        FilamentInfolist::date('mother_date_of_birth'),
                                        FilamentInfolist::text('mother_age_years'),
                                        FilamentInfolist::text('mother_phone'),
                                        FilamentInfolist::text('mother_marital_status'),
                                        FilamentInfolist::text('mother_muac_mm'),
                                        FilamentInfolist::boolean('is_mother_malnourished'),
                                    ])->columns(2),
                                \Filament\Schemas\Components\Section::make(__('fields.father_data'))
                                    ->schema([
                                        FilamentInfolist::text('father_full_name'),
                                        FilamentInfolist::text('father_id_number'),
                                        FilamentInfolist::text('father_phone'),
                                    ])->columns(2),
                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make('القياسات والتغذية')
                            ->icon('heroicon-o-scale')
                            ->schema([
                                \Filament\Schemas\Components\Section::make(__('fields.measurements'))
                                    ->schema([
                                        FilamentInfolist::text('muac_mm'),
                                        FilamentInfolist::text('weight_kg'),
                                        FilamentInfolist::text('height_cm'),
                                        FilamentInfolist::text('whz'),
                                        FilamentInfolist::text('fi'),
                                        FilamentInfolist::boolean('has_oedema'),
                                    ])->columns(2),
                                \Filament\Schemas\Components\Section::make(__('fields.nutrition_program'))
                                    ->schema([
                                        FilamentInfolist::boolean('is_enrolled_bsfp'),
                                        FilamentInfolist::boolean('is_sick_last_6_months'),
                                        FilamentInfolist::boolean('is_mother_alive'),
                                    ])->columns(3),
                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make('الأسرة والحالات الخاصة')
                            ->icon('heroicon-o-home')
                            ->schema([
                                \Filament\Schemas\Components\Section::make(__('fields.family_data'))
                                    ->schema([
                                        FilamentInfolist::boolean('has_lactating_woman'),
                                        FilamentInfolist::boolean('has_pregnant_last_trimester'),
                                        FilamentInfolist::text('children_under_5'),
                                        FilamentInfolist::enum('head_of_household_sex'),
                                        FilamentInfolist::boolean('has_stable_income'),
                                        FilamentInfolist::enum('income_source'),
                                        FilamentInfolist::boolean('is_income_below_500'),
                                        FilamentInfolist::text('male_children_under_5'),
                                        FilamentInfolist::text('female_children_under_5'),
                                        FilamentInfolist::text('family_size'),
                                        FilamentInfolist::text('current_address'),
                                        FilamentInfolist::text('original_address'),
                                    ])->columns(2),
                                \Filament\Schemas\Components\Section::make(__('fields.disability_and_special_situations'))
                                    ->schema([
                                        FilamentInfolist::boolean('has_family_disability'),
                                        FilamentInfolist::enum('disability_cause'),
                                        FilamentInfolist::text('disability_cause_other'),
                                        FilamentInfolist::boolean('has_injured_after_oct7'),
                                        FilamentInfolist::text('injured_count'),
                                        FilamentInfolist::boolean('has_unaccompanied_children'),
                                        FilamentInfolist::text('unaccompanied_children_count'),
                                        FilamentInfolist::boolean('has_released_children'),
                                    ])->columns(2),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->select([
            'id',
            'child_id',
            'name',
            'mother_full_name',
            'father_full_name',
            'sex',
            'governorate',
            'date_of_reporting',
            'is_displaced',
            'is_pwd',
            'has_oedema',
            'is_enrolled_bsfp',
            'is_sick_last_6_months',
            'is_mother_alive',
            'has_lactating_woman',
            'has_pregnant_last_trimester',
            'is_mother_malnourished',
            'has_stable_income',
            'is_income_below_500',
            'has_family_disability',
            'has_injured_after_oct7',
            'has_unaccompanied_children',
            'has_released_children',
            'visit_type',
            'organization',
            'muac_mm',
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
                Tables\Columns\TextColumn::make('child_id')
                    ->label(__('fields.child_id'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('fields.name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('mother_full_name')
                    ->label(__('fields.mother_full_name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('father_full_name')
                    ->label(__('fields.father_full_name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('sex')
                    ->label(__('fields.sex'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('fields.' . $state)),
                Tables\Columns\TextColumn::make('governorate')
                    ->label(__('fields.governorate'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('date_of_reporting')
                    ->label(__('fields.date_of_reporting'))
                    ->date()
                    ->sortable(),
                YesNoColumn::make('is_displaced')
                    ->label(__('fields.is_displaced')),
                YesNoColumn::make('is_pwd')
                    ->label(__('fields.is_pwd'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('has_oedema')
                    ->label(__('fields.has_oedema'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('is_enrolled_bsfp')
                    ->label(__('fields.is_enrolled_bsfp'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('is_sick_last_6_months')
                    ->label(__('fields.is_sick_last_6_months'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('is_mother_alive')
                    ->label(__('fields.is_mother_alive'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('has_lactating_woman')
                    ->label(__('fields.has_lactating_woman'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('has_pregnant_last_trimester')
                    ->label(__('fields.has_pregnant_last_trimester'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('is_mother_malnourished')
                    ->label(__('fields.is_mother_malnourished'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('has_stable_income')
                    ->label(__('fields.has_stable_income'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('is_income_below_500')
                    ->label(__('fields.is_income_below_500'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('has_family_disability')
                    ->label(__('fields.has_family_disability'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('has_injured_after_oct7')
                    ->label(__('fields.has_injured_after_oct7'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('has_unaccompanied_children')
                    ->label(__('fields.has_unaccompanied_children'))
                    ->toggleable(isToggledHiddenByDefault: true),
                YesNoColumn::make('has_released_children')
                    ->label(__('fields.has_released_children'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('visit_type')
                    ->label(__('fields.visit_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('fields.' . $state)),
                Tables\Columns\TextColumn::make('organization')
                    ->label(__('fields.organization'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('fi')
                    ->label(__('fields.fi'))
                    ->state(fn (Child $record): ?string => Child::classifyMuac($record->muac_mm))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'SAM' => 'danger',
                        'MAM' => 'warning',
                        'Normal' => 'success',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('date_of_reporting', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('governorate')
                    ->label(__('fields.governorate'))
                    ->options([
                        'North Gaza' => __('fields.north_gaza'),
                        'Gaza' => __('fields.gaza'),
                        'Deir al Balah' => __('fields.deir_al_balah'),
                        'Khan Younis' => __('fields.khan_younis'),
                        'Rafah' => __('fields.rafah'),
                    ]),
                Tables\Filters\SelectFilter::make('sex')
                    ->label(__('fields.sex'))
                    ->options([
                        'male' => __('fields.male'),
                        'female' => __('fields.female'),
                    ]),
                Tables\Filters\SelectFilter::make('visit_type')
                    ->label(__('fields.visit_type'))
                    ->options([
                        'new' => __('fields.new'),
                        'follow_up' => __('fields.follow_up'),
                    ]),
                Tables\Filters\SelectFilter::make('type_of_site')
                    ->label(__('fields.type_of_site'))
                    ->options([
                        'host_family' => __('fields.host_family'),
                        'camp' => __('fields.camp'),
                        'shelter' => __('fields.shelter'),
                        'other' => __('fields.other'),
                    ]),
                Tables\Filters\Filter::make('is_displaced')
                    ->label(__('fields.is_displaced'))
                    ->query(fn (Builder $query): Builder => $query->where('is_displaced', true)),
                Tables\Filters\Filter::make('date_of_reporting')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('reporting_from')->label(__('fields.from')),
                        \Filament\Forms\Components\DatePicker::make('reporting_to')->label(__('fields.to')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['reporting_from'],
                                fn (Builder $query, $date): Builder => $query->where('date_of_reporting', '>=', $date),
                            )
                            ->when(
                                $data['reporting_to'],
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
                    \Filament\Actions\DeleteBulkAction::make()
                        ->visible(fn (): bool => static::allowsAction('delete')),
                    \Filament\Actions\RestoreBulkAction::make()
                        ->visible(fn (): bool => static::allowsAction('delete')),
                    \Filament\Actions\ForceDeleteBulkAction::make()
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
        return 'children';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChildren::route('/'),
            'create' => Pages\CreateChild::route('/create'),
            'view' => Pages\ViewChild::route('/{record}'),
            'edit' => Pages\EditChild::route('/{record}/edit'),
        ];
    }
}
