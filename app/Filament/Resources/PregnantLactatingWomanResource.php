<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesModuleActions;
use App\Filament\Resources\PregnantLactatingWomanResource\Pages;
use App\Filament\Tables\Columns\YesNoColumn;
use App\Models\PregnantLactatingWoman;
use App\Support\FilamentInfolist;
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

    protected static string|\UnitEnum|null $navigationGroup = 'إدارة البيانات';

    public static function getModelLabel(): string
    {
        return 'الحوامل والمرضعات';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الحوامل والمرضعات';
    }

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                \Filament\Schemas\Components\Tabs::make('PLWFormTabs')
                    ->tabs([
                        \Filament\Schemas\Components\Tabs\Tab::make('الزيارة والموقع')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                static::getVisitDataSection(),
                                static::getLocationSection(),
                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make('البيانات الشخصية والزوج')
                            ->icon('heroicon-o-user')
                            ->schema([
                                static::getPersonalDataSection(),
                                static::getHusbandDataSection(),

                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make('القياسات التغذوية')
                            ->icon('heroicon-o-scale')
                            ->schema([
                                static::getMeasurementsSection(),
                            ]),
                        \Filament\Schemas\Components\Tabs\Tab::make('بيانات الأسرة')
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
        return \Filament\Schemas\Components\Section::make('بيانات الزيارة')
            ->schema([
                \Filament\Forms\Components\Select::make('visit_type')
                    ->label(__('fields.visit_type'))
                    ->options([
                        'new' => __('fields.new'),
                        'follow_up' => __('fields.follow_up'),
                    ])
                    ->live()
                    ->afterStateUpdated(fn (Get $get, $livewire) => static::checkDuplicateMother($get, $livewire))
                    ->required(),
                \Filament\Forms\Components\TextInput::make('mother_id')
                    ->label(__('fields.mother_id'))
                    ->required()
                    ->numeric()
                    ->rules(['regex:/^[0-9]{9}$/'])
                    ->validationMessages([
                        'required' => 'رقم الهوية مطلوب.',
                        'numeric' => 'رقم الهوية يجب أن يكون رقماً.',
                        'regex' => 'رقم الهوية يجب أن يتكون من 9 أرقام بالضبط.',
                    ])
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, $livewire) => static::checkDuplicateMother($get, $livewire)),
                \Filament\Forms\Components\TextInput::make('full_name_ar')
                    ->label(__('fields.full_name_ar'))
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

    public static function checkDuplicateMother(Get $get, $livewire): void
    {
        $motherId = $get('mother_id');
        if (blank($motherId) || ! is_object($livewire) || ! method_exists($livewire, 'dispatch')) {
            return;
        }

        // Include soft-deleted (trashed) records so a deleted ID still counts
        // as existing, preventing duplicate-ID collisions when it is restored.
        $query = PregnantLactatingWoman::withTrashed()->where('mother_id', $motherId);

        if (isset($livewire->record) && $livewire->record instanceof \Illuminate\Database\Eloquent\Model) {
            $query->where('id', '!=', $livewire->record->id);
        }

        $existing = $query->latest('date_of_reporting')->first();

        if (! $existing) {
            return;
        }

        $lastVisitDate = $existing->date_of_reporting ? $existing->date_of_reporting->format('Y-m-d') : ($existing->created_at ? $existing->created_at->format('Y-m-d') : '-');
        $lastVisitType = $existing->visit_type === 'follow_up' ? 'متابعة' : 'جديد';

        $currentVisitType = $get('visit_type');
        $visitTypeWarning = null;
        if ($currentVisitType === 'new') {
            $visitTypeWarning = 'تنبيه: السيدة مسجلة سابقاً في النظام، ولذلك لا يمكن تسجيلها كـ (جديد) وستتم إضافة الزيارة كـ (متابعة).';
        }

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
            'status_type' => $existing->status_type,
            'governorate' => $existing->governorate,
            'municipality' => $existing->municipality,
            'neighbourhood' => $existing->neighbourhood,
            'location' => $existing->location,
            'type_of_site' => $existing->type_of_site,
            'disability_type' => $existing->disability_type,
            'newborn_dob' => $existing->newborn_dob ? $existing->newborn_dob->format('Y-m-d') : null,
            'husband_id_number' => $existing->husband_id_number,
            'husband_full_name' => $existing->husband_full_name,
            'husband_phone' => $existing->husband_phone,
            'family_size' => $existing->family_size,
            'children_count' => $existing->children_count,
            'is_family_pwd' => (bool) $existing->is_family_pwd,
        ];

        $livewire->dispatch('show-duplicate-visit-alert', [
            'title' => 'هذه السيدة موجودة مسبقاً في النظام',
            'last_visit_date' => $lastVisitDate,
            'last_visit_type' => $lastVisitType,
            'visit_type_warning' => $visitTypeWarning,
            'confirm_button_text' => 'جلب البيانات وتحويل الزيارة إلى متابعة',
            'action_type' => 'fill_mother',
            'index_url' => static::getUrl('index'),
            'record_data' => $recordData,
        ]);
    }

    protected static function getPersonalDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make('البيانات الشخصية')
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
                \Filament\Forms\Components\Select::make('status_type')
                    ->label(__('fields.status_type'))
                    ->options([
                        'pregnant' => __('fields.pregnant'),
                        'lactating' => __('fields.lactating'),
                    ])
                    ->required()
                    ->live(),
                 \Filament\Forms\Components\Select::make('status')
                    ->label(__('fields.status'))
                    ->required()
                    ->options([
                        'متزوجة' => 'متزوجة',
                        'أرملة' => 'أرملة',
                        'مطلقة' => 'مطلقة',
                        'منفصلة' => 'منفصلة',
                    ]),
                BooleanSelectField::make('is_pwd', __('fields.is_pwd'))
                    ->live(),
                BooleanSelectField::make('is_displaced', __('fields.is_displaced')),
                \Filament\Forms\Components\TextInput::make('disability_type')
                    ->label(__('fields.disability_type'))
                    ->required(fn (Get $get): bool => $get('is_pwd') === true)
                    ->visible(fn (Get $get): bool => $get('is_pwd') === true)
                    ->maxLength(255),
                \Filament\Forms\Components\DatePicker::make('newborn_dob')
                    ->label('تاريخ آخر مولود')
                    ->visible(fn (Get $get): bool => in_array($get('status_type'), ['pregnant', 'lactating'])),

            ])->columns(2);

    }

    protected static function getMeasurementsSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make('القياسات')
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
                        'required' => 'منتصف العضد مطلوب.',
                        'integer' => 'منتصف العضد يجب أن يكون رقماً صحيحاً.',
                        'min' => 'منتصف العضد يجب أن يكون بين 1 و 200.',
                        'max' => 'منتصف العضد يجب أن يكون بين 1 و 200.',
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
        return \Filament\Schemas\Components\Section::make('الموقع')
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
    //     return \Filament\Schemas\Components\Section::make('بيانات إضافية')
    //         ->schema([
    //             \Filament\Forms\Components\TextInput::make('disability_type')
    //                 ->label(__('fields.disability_type'))
    //                 ->required(fn (Get $get): bool => $get('is_pwd') === true)
    //                 ->visible(fn (Get $get): bool => $get('is_pwd') === true)
    //                 ->maxLength(255),
    //             \Filament\Forms\Components\DatePicker::make('newborn_dob')
    //                 ->label('تاريخ آخر مولود')
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

    protected static function getHusbandDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make('بيانات الزوج')
            ->schema([
                \Filament\Forms\Components\TextInput::make('husband_full_name')
                    ->label(__('fields.husband_full_name'))
                    ->required()
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('husband_id_number')
                    ->label(__('fields.husband_id_number'))
                    ->required()
                    ->numeric()
                    ->rules(['regex:/^[0-9]{9}$/'])
                    ->validationMessages([
                        'required' => 'رقم هوية الزوج مطلوب.',
                        'numeric' => 'رقم هوية الزوج يجب أن يكون رقماً.',
                        'regex' => 'رقم هوية الزوج يجب أن يتكون من 9 أرقام بالضبط.',
                    ])
                    ->maxLength(255),
                \Filament\Forms\Components\TextInput::make('husband_phone')
                    ->label(__('fields.husband_phone'))
                    ->tel()
                    ->required()
                    ->numeric()
                    ->rules(['regex:/^[0-9]{10}$/'])
                    ->validationMessages([
                        'required' => 'رقم هاتف الزوج مطلوب.',
                        'numeric' => 'رقم هاتف الزوج يجب أن يكون رقماً.',
                        'regex' => 'رقم هاتف الزوج يجب أن يتكون من 10 أرقام بالضبط.',
                    ])
            ])->columns(2);
    }

    protected static function getFamilyDataSection(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\Section::make('بيانات الأسرة')
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
                \Filament\Schemas\Components\Section::make('البيانات الشخصية')
                    ->schema([
                        FilamentInfolist::date('date_of_birth'),
                        FilamentInfolist::text('age_years'),
                        FilamentInfolist::enum('status_type'),
                        FilamentInfolist::boolean('is_pwd'),
                        FilamentInfolist::boolean('is_displaced'),
                    ])->columns(2),

                \Filament\Schemas\Components\Section::make('القياسات')
                    ->schema([
                        FilamentInfolist::text('weight_kg'),
                        FilamentInfolist::text('height_cm'),
                        FilamentInfolist::text('muac_mm'),
                        FilamentInfolist::text('fi'),
                        FilamentInfolist::boolean('has_oedema'),
                    ])->columns(2),
                \Filament\Schemas\Components\Section::make('الموقع')
                    ->schema([
                        FilamentInfolist::text('governorate'),
                        FilamentInfolist::text('municipality'),
                        FilamentInfolist::text('neighbourhood'),
                        FilamentInfolist::text('location'),
                        FilamentInfolist::text('type_of_site'),
                    ])->columns(2),
                \Filament\Schemas\Components\Section::make('بيانات إضافية')
                    ->schema([
                        FilamentInfolist::text('disability_type'),
                        FilamentInfolist::date('newborn_dob'),
                        FilamentInfolist::text('status'),
                    ])->columns(2),
                \Filament\Schemas\Components\Section::make('بيانات الزوج')
                    ->schema([
                        FilamentInfolist::text('husband_id_number'),
                        FilamentInfolist::text('husband_full_name'),
                        FilamentInfolist::text('husband_phone'),
                    ])->columns(2),
                \Filament\Schemas\Components\Section::make('بيانات الأسرة')
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
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('full_name_ar')
                    ->label(__('fields.full_name_ar'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status_type')
                    ->label(__('fields.status_type'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pregnant' => 'warning',
                        'lactating' => 'success',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('governorate')
                    ->label(__('fields.governorate'))
                    ->searchable()
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
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('date_of_reporting', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status_type')
                    ->label(__('fields.status_type'))
                    ->options([
                        'pregnant' => __('fields.pregnant'),
                        'lactating' => __('fields.lactating'),
                    ]),
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
