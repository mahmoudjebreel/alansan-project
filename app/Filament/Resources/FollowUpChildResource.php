<?php

namespace App\Filament\Resources;

use App\Support\Forms\DigitStringField;
use App\Support\RecordSearch;
use App\Filament\Concerns\AuthorizesModuleActions;
use App\Filament\Resources\FollowUpChildResource\Pages;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Filament\Resources\FollowUpChildResource\Actions\ReadmissionAction;
use App\Filament\Resources\FollowUpChildResource\Actions\ReferToChildrenAction;
use App\Support\FilamentInfolist;
use App\Support\FollowUpDischargeRule;
use App\Support\MuacClassifier;
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

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.data');
    }

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
            'non_responded' => __('fields.non_responded'),
            'referred_medical_inpt' => __('fields.referred_medical_inpt'),
            'died' => __('fields.died'),
            'under_follow_up' => __('fields.under_follow_up'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function admissionTypeOptions(): array
    {
        return [
            FollowUpChild::ADMISSION_NEW => __('fields.admission_new'),
            FollowUpChild::ADMISSION_READMISSION => __('fields.readmission'),
        ];
    }

    /**
     * The three classifications of an episode that follows a closed one, by
     * the value the model decides.
     *
     * @return array<string, string>
     */
    public static function readmissionClassificationOptions(): array
    {
        return [
            FollowUpChild::READMISSION_AFTER_DEFAULTED => __('fields.readmission_after_defaulted'),
            FollowUpChild::READMISSION_AFTER_OTHER => __('fields.readmission_after_other'),
            FollowUpChild::READMISSION_AFTER_RELAPSE => __('fields.readmission_after_relapse'),
        ];
    }

    /**
     * The label for a classification, or null when there is none to name.
     */
    public static function readmissionClassificationLabel(?string $classification): ?string
    {
        return $classification === null
            ? null
            : (static::readmissionClassificationOptions()[$classification] ?? null);
    }

    /**
     * The two cases the listing names on top of the three readmission
     * classifications. Not a fourth and fifth classification: only an
     * episode the module classifies as nothing can carry either.
     *
     * The keys are deliberately not any of READMISSION_CLASSIFICATIONS -
     * 'defaulted' there means "readmission after a default", which is a
     * different statement about a different episode.
     */
    public const CASE_DEFAULTED = 'defaulted_case';

    public const CASE_NEW = 'new';

    /**
     * The five values the case classification column can show.
     *
     * @return array<string, string>
     */
    public static function caseClassificationOptions(): array
    {
        return static::readmissionClassificationOptions() + [
            self::CASE_DEFAULTED => __('fields.defaulted'),
            self::CASE_NEW => __('fields.admission_new'),
        ];
    }

    /**
     * How one episode is classified in the listing.
     *
     * The readmission part is not decided here: it is read straight off
     * FollowUpChild::readmissionClassification(), which is the one SQL
     * definition the spreadsheet, the filters and the MEAL report read too,
     * so none of them can disagree about an episode.
     *
     * @see \App\Models\FollowUpChild::readmissionClassification()
     * @see \App\Exports\FollowUpChildrenExport::formatValue()
     *
     * Order matters, and follows the module's own: what an episode follows
     * on from is what the episode is, so a readmission stays a readmission
     * however it later ended - its own ending is already the discharge
     * outcome column. Only an episode that follows on from nothing is named
     * by its own outcome, and only when that outcome is a default.
     */
    public static function caseClassification(FollowUpChild $record): string
    {
        $classification = $record->readmissionClassification();

        if ($classification !== null) {
            return $classification;
        }

        return $record->discharge_outcome === FollowUpChild::DEFAULTED_OUTCOME
            ? self::CASE_DEFAULTED
            : self::CASE_NEW;
    }

    public static function caseClassificationLabel(FollowUpChild $record): string
    {
        return static::caseClassificationOptions()[static::caseClassification($record)];
    }

    /**
     * The filter behind the column: the same five cases, as a query.
     *
     * The very expression the column's value is selected by, so the filter
     * selects exactly the rows the column names.
     *
     * @see \App\Models\FollowUpChild::readmissionClassificationSql()
     */
    public static function applyCaseClassificationFilter(Builder $query, ?string $value): Builder
    {
        if ($value === null || $value === '') {
            return $query;
        }

        $classification = FollowUpChild::readmissionClassificationSql($query->getModel()->getTable());

        if (array_key_exists($value, static::readmissionClassificationOptions())) {
            return $query->whereRaw("{$classification} = ?", [$value]);
        }

        // Neither case follows on from anything the module classifies.
        $query->whereRaw("{$classification} IS NULL");

        return $value === self::CASE_DEFAULTED
            ? $query->where('discharge_outcome', FollowUpChild::DEFAULTED_OUTCOME)
            : $query->where(function (Builder $query): void {
                $query->whereNull('discharge_outcome')
                    ->orWhere('discharge_outcome', '!=', FollowUpChild::DEFAULTED_OUTCOME);
            });
    }

    /**
     * The admission type filter, over the derived admission type: a
     * readmission after a default, an other exit or a relapse, and a new
     * admission otherwise. The stored admission_type is not consulted.
     *
     * @see \App\Models\FollowUpChild::derivedAdmissionType()
     */
    public static function applyAdmissionTypeFilter(Builder $query, ?string $value): Builder
    {
        if ($value === null || $value === '') {
            return $query;
        }

        $classification = FollowUpChild::readmissionClassificationSql($query->getModel()->getTable());
        $kinds = implode(', ', array_fill(0, count(FollowUpChild::READMISSION_KINDS), '?'));

        return match ($value) {
            FollowUpChild::ADMISSION_READMISSION => $query->whereRaw(
                "{$classification} IN ({$kinds})",
                FollowUpChild::READMISSION_KINDS,
            ),
            FollowUpChild::ADMISSION_NEW => $query->whereRaw(
                "COALESCE({$classification}, '') NOT IN ({$kinds})",
                FollowUpChild::READMISSION_KINDS,
            ),
            default => $query,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function visitStatusOptions(): array
    {
        return [
            FollowUpChildVisit::STATUS_ATTENDED => __('fields.visit_attended'),
            FollowUpChildVisit::STATUS_MISSED => __('fields.visit_missed'),
        ];
    }

    /**
     * What one visit says about the child at that point, read off the
     * sequence as recorded and nothing else:
     *
     *   - a missed visit is a missed visit (a defaulter's absence);
     *   - an attended visit right after a missed one is the child returning;
     *   - the last visit of a closed episode carries the episode's outcome,
     *     because that is the visit at which the outcome was decided;
     *   - any other attended visit is the child under follow-up.
     *
     * Nothing here writes, and nothing invents a visit that is not there.
     */
    public static function visitOutcome(FollowUpChildVisit $visit): string
    {
        if ($visit->isMissed()) {
            return __('fields.visit_outcome_missed');
        }

        $episode = $visit->followUpChild;
        $visits = $episode?->visits ?? collect();

        $isLast = $visits->max('visit_number') === $visit->visit_number;

        if ($isLast && $episode?->isLocked()) {
            return __('fields.' . $episode->discharge_outcome);
        }

        $previous = $visits->firstWhere('visit_number', $visit->visit_number - 1);

        if ($previous instanceof FollowUpChildVisit && $previous->isMissed()) {
            return __('fields.visit_outcome_returned');
        }

        return __('fields.under_follow_up');
    }

    /**
     * Badge colour for the outcome above, keyed the same way.
     */
    public static function visitOutcomeColor(FollowUpChildVisit $visit): string
    {
        if ($visit->isMissed()) {
            return 'danger';
        }

        $episode = $visit->followUpChild;
        $visits = $episode?->visits ?? collect();

        if ($visits->max('visit_number') === $visit->visit_number && $episode?->isLocked()) {
            return 'gray';
        }

        $previous = $visits->firstWhere('visit_number', $visit->visit_number - 1);

        return $previous instanceof FollowUpChildVisit && $previous->isMissed() ? 'warning' : 'info';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('visits');
    }

    /**
     * Follow-up records are opened by the Children module when a screening
     * comes back MAM or SAM, never by hand.
     *
     * Creating one manually meant a child could be under treatment with no
     * screening behind them, which every report that joins the two modules
     * then had to guess about. The Excel import is unaffected: it carries
     * historical episodes that were recorded before this system existed.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Tabs::make('FollowUpChildTabs')
                ->tabs([
                    \Filament\Schemas\Components\Tabs\Tab::make(__('ui.tabs.follow_up_child_data'))
                        ->icon('heroicon-o-user')
                        ->schema([
                            static::getFollowUpChildDataSection(),
                        ]),
                    \Filament\Schemas\Components\Tabs\Tab::make(__('ui.tabs.ongoing_visits'))
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
                    // A digit string, not a quantity: type="number" dropped the
                    // leading zero. @see \App\Support\Forms\DigitStringField
                    ->extraInputAttributes(DigitStringField::inputAttributes())
                    ->rules(['regex:/^[0-9]{9}$/'])
                    ->validationMessages([
                        'required' => __('ui.validation.identity_required'),
                        'regex' => __('ui.validation.identity_digits'),
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
                    // A digit string, not a quantity: type="number" dropped the
                    // leading zero. @see \App\Support\Forms\DigitStringField
                    ->extraInputAttributes(DigitStringField::inputAttributes())
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
                    ->disabled()
                    ->dehydrated()
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('admitted_with')
                    ->label(__('fields.admitted_with'))
                    // A readmission opened on a Normal reading was admitted
                    // with neither SAM nor MAM and stores none; visit 1
                    // carries its Normal FI. It is the one record that may
                    // be saved - closed as cured, say - without picking one.
                    ->required(fn (?FollowUpChild $record): bool => ! ($record?->isReadmission() && blank($record->admitted_with)))
                    ->options(['SAM' => 'SAM', 'MAM' => 'MAM']),
                // Decided by how the episode was opened - a first admission,
                // or a readmission after a closed one - never picked by hand.
                // Shown so the record says what it is; a blank is a first
                // admission written before the field existed.
                Forms\Components\Select::make('admission_type')
                    ->label(__('fields.admission_type'))
                    ->options(static::admissionTypeOptions())
                    ->placeholder(__('fields.admission_new'))
                    // Shown as the history decides it, not as stored.
                    ->formatStateUsing(fn (?FollowUpChild $record, ?string $state): ?string => $record?->exists
                        ? $record->derivedAdmissionType()
                        : $state)
                    ->disabled()
                    ->dehydrated(false),
                // A closing outcome needs the date it closed on: the closed
                // history is ordered by it, and the reports count the
                // discharge in its month. Nothing is filled in on the
                // person's behalf - they are asked for it.
                Forms\Components\DatePicker::make('discharge_date')
                    ->label(__('fields.discharge_date'))
                    ->rules(['date'])
                    ->requiredIf('discharge_outcome', FollowUpChild::CLOSING_OUTCOMES)
                    // A discharge cannot precede the admission it ends. The
                    // required rule above has been asking for the date for a
                    // while; nothing checked it was a date that could have
                    // happened, so an episode admitted on the 20th could be
                    // discharged on the 10th and the reports counted the
                    // discharge in the wrong month.
                    ->rule(static function (Get $get): \Closure {
                        return static function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $messages = FollowUpDischargeRule::violations(
                                $get('discharge_outcome'),
                                $value,
                                $get('admission_date'),
                            );

                            foreach ($messages as $message) {
                                $fail($message);
                            }
                        };
                    }),
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
                        // Attended or missed. Every visit is attended unless
                        // somebody says otherwise, and a missed visit is
                        // recorded here by a person, on the date it was due -
                        // the system never writes one on its own.
                        Forms\Components\Select::make('status')
                            ->label(__('fields.visit_status'))
                            ->options(static::visitStatusOptions())
                            ->default(FollowUpChildVisit::STATUS_ATTENDED)
                            ->selectablePlaceholder(false)
                            // Not required: a blank is an attended visit,
                            // which the model settles itself on save.
                            ->live(),
                        // The date is what makes a visit a visit; the reading
                        // is not always taken. A child seen and referred to a
                        // hospital was still seen, and requiring the
                        // measurement here forced the team to either invent a
                        // number or leave the visit out of the record. Left
                        // blank the visit is listed under "Missing follow-up
                        // measurements" in the Referral Centre until somebody
                        // finds the reading. A missed visit has no reading at
                        // all, so the field is not offered for one.
                        Forms\Components\TextInput::make('muac')
                            ->label(__('fields.muac'))
                            ->numeric()
                            ->visible(fn (Get $get): bool => $get('status') !== FollowUpChildVisit::STATUS_MISSED),
                    ])
                    ->columns(3)
                    ->itemNumbers()
                    ->itemLabel(function (array $state): ?string {
                        if (blank($state['visit_date'] ?? null)) {
                            return null;
                        }

                        $label = __('fields.visit_date') . ': ' . $state['visit_date'];

                        if (($state['status'] ?? null) === FollowUpChildVisit::STATUS_MISSED) {
                            $label .= ' · ' . __('fields.visit_missed');
                        }

                        return $label;
                    })
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
                    TextEntry::make('admission_type')
                        ->label(__('fields.admission_type'))
                        ->state(fn (FollowUpChild $record): string => static::admissionTypeOptions()[$record->derivedAdmissionType()])
                        ->badge()
                        ->color(fn (FollowUpChild $record): string => $record->derivedAdmissionType() === FollowUpChild::ADMISSION_READMISSION ? 'warning' : 'info'),
                    // After defaulted, after other, or after relapse - read
                    // from the episode this one follows. Shown only when
                    // there is one to read.
                    TextEntry::make('readmission_classification')
                        ->label(__('fields.readmission_classification'))
                        ->state(fn (FollowUpChild $record): ?string => static::readmissionClassificationLabel($record->readmissionClassification()))
                        ->badge()
                        ->color('warning')
                        ->visible(fn (FollowUpChild $record): bool => $record->readmissionClassification() !== null),
                    FilamentInfolist::date('admission_date'),
                    FilamentInfolist::date('discharge_date'),
                    FilamentInfolist::enum('discharge_outcome'),
                    TextEntry::make('record_state')
                        ->label(__('fields.record_state'))
                        ->state(fn (FollowUpChild $record): string => $record->isLocked()
                            ? __('fields.record_locked')
                            : __('fields.record_active'))
                        ->badge()
                        ->color(fn (FollowUpChild $record): string => $record->isLocked() ? 'gray' : 'success'),
                    FilamentInfolist::text('notes')->columnSpanFull(),
                ])->columns(2),
            \Filament\Schemas\Components\Section::make(__('fields.visits'))
                ->description(fn (FollowUpChild $record): ?string => $record->meetsDefaulterRule() && ! $record->isLocked()
                    ? __('fields.defaulter_rule_body')
                    : null)
                ->schema([
                    RepeatableEntry::make('visits')
                        ->label(__('fields.visits'))
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('visit_number')
                                ->label(__('fields.visit_number')),
                            FilamentInfolist::date('visit_date'),
                            TextEntry::make('status')
                                ->label(__('fields.visit_status'))
                                ->formatStateUsing(fn (?string $state): string => static::visitStatusOptions()[$state ?? FollowUpChildVisit::STATUS_ATTENDED]
                                    ?? (string) $state)
                                ->badge()
                                ->color(fn (?string $state): string => $state === FollowUpChildVisit::STATUS_MISSED ? 'danger' : 'success'),
                            TextEntry::make('muac')
                                ->label(__('fields.muac'))
                                ->placeholder(fn (FollowUpChildVisit $record): string => $record->isMissed()
                                    ? __('fields.visit_missed')
                                    : __('ui.referral_center.status.missing_muac')),
                            TextEntry::make('fi')
                                ->label(__('fields.fi'))
                                ->badge()
                                ->placeholder('-')
                                ->color(fn (?string $state): string => MuacClassifier::color($state)),
                            // The visit's own outcome in the sequence: under
                            // follow-up, missed, returned, or - on the last
                            // visit of a closed episode - how it closed.
                            TextEntry::make('visit_outcome')
                                ->label(__('fields.visit_outcome'))
                                ->state(fn (FollowUpChildVisit $record): string => static::visitOutcome($record))
                                ->badge()
                                ->color(fn (FollowUpChildVisit $record): string => static::visitOutcomeColor($record)),
                        ])
                        ->columns(6),
                ]),
            // Every other episode on file for the same child ID: the history
            // a readmission follows on from, and the one it leaves behind.
            \Filament\Schemas\Components\Section::make(__('fields.follow_up_history'))
                ->description(__('fields.follow_up_history_hint'))
                ->schema([
                    RepeatableEntry::make('follow_up_history')
                        ->hiddenLabel()
                        ->state(fn (FollowUpChild $record): array => $record->otherEpisodes()
                            ->withAdmissionClassification()
                            ->withCount('visits')
                            ->get()
                            ->map(fn (FollowUpChild $episode): array => [
                                'id' => $episode->getKey(),
                                'admission_date' => $episode->admission_date?->format('Y-m-d'),
                                'admission_type' => static::admissionTypeOptions()[$episode->derivedAdmissionType()],
                                'is_readmission' => $episode->derivedAdmissionType() === FollowUpChild::ADMISSION_READMISSION,
                                'visits_count' => $episode->visits_count,
                                'discharge_outcome' => filled($episode->discharge_outcome)
                                    ? __('fields.' . $episode->discharge_outcome)
                                    : '-',
                                'discharge_date' => $episode->discharge_date?->format('Y-m-d'),
                                'record_state' => $episode->isLocked()
                                    ? __('fields.record_locked')
                                    : __('fields.record_active'),
                                'is_locked' => $episode->isLocked(),
                                'url' => static::getUrl('view', ['record' => $episode]),
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('admission_date')
                                ->label(__('fields.admission_date'))
                                ->placeholder('-'),
                            TextEntry::make('admission_type')
                                ->label(__('fields.admission_type'))
                                ->badge()
                                ->color(fn (Get $get): string => $get('is_readmission') ? 'warning' : 'info'),
                            TextEntry::make('visits_count')
                                ->label(__('fields.visits_recorded')),
                            TextEntry::make('discharge_outcome')
                                ->label(__('fields.discharge_outcome')),
                            TextEntry::make('discharge_date')
                                ->label(__('fields.discharge_date'))
                                ->placeholder('-'),
                            TextEntry::make('record_state')
                                ->label(__('fields.record_state'))
                                ->badge()
                                ->color(fn (Get $get): string => $get('is_locked') ? 'gray' : 'success')
                                ->url(fn (Get $get): ?string => $get('url')),
                        ])
                        ->columns(6)
                        ->placeholder(__('fields.no_other_episodes')),
                ])
                ->visible(fn (FollowUpChild $record): bool => $record->otherEpisodes()->exists()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Every row carries its classification, selected with the page.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('visits')->withAdmissionClassification())
            ->columns([
                Tables\Columns\TextColumn::make('id_number')
                    ->label(__('fields.id_number'))
                    ->searchable(query: RecordSearch::identifier('id_number')),
                Tables\Columns\TextColumn::make('child_name')
                    ->label(__('fields.child_name'))
                    ->searchable(query: RecordSearch::name('child_name')),
                Tables\Columns\TextColumn::make('sex')
                    ->label(__('fields.sex'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): ?string => filled($state) ? __('fields.' . $state) : null),
                Tables\Columns\TextColumn::make('shelter_name')
                    ->label(__('fields.shelter_name'))
                    ->searchable(query: RecordSearch::name('shelter_name')),
                Tables\Columns\TextColumn::make('admission_date')
                    ->label(__('fields.admission_date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('admitted_with')
                    ->label(__('fields.admitted_with'))
                    ->badge()
                    ->color(fn (?string $state): string => MuacClassifier::color($state)),
                Tables\Columns\TextColumn::make('admission_type')
                    ->label(__('fields.admission_type'))
                    ->state(fn (FollowUpChild $record): string => static::admissionTypeOptions()[$record->derivedAdmissionType()])
                    ->badge()
                    ->color(fn (FollowUpChild $record): string => $record->derivedAdmissionType() === FollowUpChild::ADMISSION_READMISSION ? 'warning' : 'info'),
                // What kind of case the episode is, derived from the closed
                // episode it follows on from exactly as the spreadsheet's
                // readmission_classification column is.
                // @see static::caseClassification()
                Tables\Columns\TextColumn::make('case_classification')
                    ->label(__('fields.case_classification'))
                    ->state(fn (FollowUpChild $record): string => static::caseClassificationLabel($record))
                    ->badge()
                    ->color(fn (FollowUpChild $record): string => match (static::caseClassification($record)) {
                        FollowUpChild::READMISSION_AFTER_RELAPSE => 'danger',
                        FollowUpChild::READMISSION_AFTER_DEFAULTED => 'warning',
                        FollowUpChild::READMISSION_AFTER_OTHER => 'gray',
                        self::CASE_DEFAULTED => 'warning',
                        default => 'info',
                    })
                    // Derived per record rather than stored, so the table
                    // cannot sort or search on it; the filter below is how
                    // the listing is narrowed to one kind of case.
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latest_visit_number')
                    ->label(__('fields.latest_visit_number'))
                    // Read off the eager-loaded relation, so the column costs
                    // nothing beyond the load the table already does.
                    ->state(fn (FollowUpChild $record): ?int => $record->visits->last()?->visit_number)
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('latest_visit_date')
                    ->label(__('fields.latest_visit_date'))
                    ->state(fn (FollowUpChild $record): mixed => $record->visits->last()?->visit_date)
                    ->date(),
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
                // Missed visits, off the relation the table already loads.
                Tables\Columns\TextColumn::make('missed_visits')
                    ->label(__('fields.missed_visits'))
                    ->state(fn (FollowUpChild $record): int => $record->visits
                        ->filter(fn (FollowUpChildVisit $visit): bool => $visit->isMissed())
                        ->count())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('latest_muac')
                    ->label(__('fields.latest_muac'))
                    ->state(fn (FollowUpChild $record): mixed => $record->latest_muac)
                    ->badge()
                    // A visit with no reading says so. Nothing supplies a
                    // value for it and the visit itself is never removed.
                    ->formatStateUsing(fn (mixed $state): string => blank($state)
                        ? __('ui.referral_center.status.missing_muac')
                        : (string) $state)
                    ->color(fn ($state): string => MuacClassifier::color(MuacClassifier::classify($state))),
                Tables\Columns\TextColumn::make('record_state')
                    ->label(__('fields.record_state'))
                    ->state(fn (FollowUpChild $record): string => $record->isLocked()
                        ? __('fields.record_locked')
                        : __('fields.record_active'))
                    ->badge()
                    ->color(fn (FollowUpChild $record): string => $record->isLocked() ? 'gray' : 'success'),
            ])
            ->defaultSort('admission_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('discharge_outcome')
                    ->label(__('fields.discharge_outcome'))
                    ->options(static::dischargeOutcomeOptions()),
                Tables\Filters\SelectFilter::make('admission_type')
                    ->label(__('fields.admission_type'))
                    ->options(static::admissionTypeOptions())
                    // The derived admission type, exactly as the column shows it.
                    // @see static::applyAdmissionTypeFilter()
                    ->query(fn (Builder $query, array $data): Builder => static::applyAdmissionTypeFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
                // The column's five cases, as a filter.
                // @see static::applyCaseClassificationFilter()
                Tables\Filters\SelectFilter::make('case_classification')
                    ->label(__('fields.case_classification'))
                    ->options(static::caseClassificationOptions())
                    ->query(fn (Builder $query, array $data): Builder => static::applyCaseClassificationFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
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
                // On a closed record only: opens a new episode for the same
                // child and leaves this one exactly as it is.
                ReadmissionAction::make(),
                // On a cured record with no Children row for its ID number:
                // writes that one row and leaves this record as it is.
                ReferToChildrenAction::make(),
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
