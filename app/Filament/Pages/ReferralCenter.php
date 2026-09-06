<?php

namespace App\Filament\Pages;

use App\Filament\Resources\FollowUpChildResource;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\ReferralBatch;
use App\Support\MuacClassifier;
use App\Support\Referral\ReferralCandidates;
use App\Support\Referral\ReferralProcessor;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Reviewing the SAM and MAM children an upload brought in, and referring the
 * ones that should be admitted.
 *
 * This is a layer on top of the Children module, not a part of it. The Excel
 * import writes children and stops; nothing is referred until somebody looks
 * at this screen and says so. That separation is the whole point: an upload of
 * a thousand rows cannot be undone by a referral going wrong, and a referral
 * going wrong does not need the upload to be repeated.
 *
 * It is a page rather than anything inside ChildResource on purpose - the
 * resource already carries a great deal, and none of this belongs to editing
 * a child.
 */
class ReferralCenter extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-right-circle';

    protected string $view = 'filament.pages.referral-center';

    public static function getNavigationLabel(): string
    {
        return __('ui.referral_center.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.data');
    }

    public function getTitle(): string
    {
        return __('ui.referral_center.title');
    }

    public function getSubheading(): ?string
    {
        return __('ui.referral_center.description');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('children.refer') ?? false;
    }

    /**
     * The upload currently being reviewed, or null while the table is showing
     * every eligible child.
     */
    public function currentBatch(): ?ReferralBatch
    {
        $value = $this->tableFilters['referral_batch']['value'] ?? null;

        return filled($value) ? ReferralBatch::find($value) : null;
    }

    /**
     * The figures printed above the table.
     *
     * @return array{total: int, normal: int, mam: int, sam: int, unmeasured: int, eligible: int}
     */
    public function summary(): array
    {
        return ReferralCandidates::summary($this->currentBatch());
    }

    /**
     * The five counters printed beside the classification split: what is
     * waiting, what is being treated, what has closed, and the two kinds of
     * missing measurement.
     *
     * @return array{pending: int, previously_followed: int, in_follow_up: int, needs_review: int, active_follow_ups: int, closed_cases: int, missing_follow_up_muac: int}
     */
    public function statusSummary(): array
    {
        return ReferralCandidates::statusSummary($this->currentBatch());
    }

    /**
     * The status of one listed row.
     *
     * The table query already selected it, so this is a read of a column and
     * not a second question to the database; statusFor() is the fallback for
     * a record that reached the column by some other route.
     */
    public static function statusOf(Child $record): string
    {
        return $record->referral_status ?? ReferralCandidates::statusFor($record);
    }

    /**
     * One colour per status, shared by both status columns so a row never
     * reads two ways.
     */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            ReferralCandidates::STATUS_PENDING => 'warning',
            ReferralCandidates::STATUS_PREVIOUSLY_FOLLOWED => 'info',
            ReferralCandidates::STATUS_IN_FOLLOW_UP => 'success',
            default => 'gray',
        };
    }

    /**
     * Where a listed child's follow-up history is read, or null when there is
     * none and when the viewer may not read one.
     *
     * The record id was selected with the row; statusFor()'s own fallback
     * route is not needed here, because a row that arrived without the column
     * simply offers no link rather than paying for a lookup per row.
     */
    public static function followUpUrl(Child $record): ?string
    {
        $followUpChildId = $record->follow_up_child_id ?? null;

        if (blank($followUpChildId)) {
            return null;
        }

        // The follow-up module's own permission, not a new one.
        if (! (auth()->user()?->can('viewAny', FollowUpChild::class) ?? false)) {
            return null;
        }

        return FollowUpChildResource::getUrl('view', ['record' => $followUpChildId]);
    }

    /**
     * @return array<string, string>
     */
    public static function statusFilterOptions(): array
    {
        $options = [
            ReferralCandidates::FILTER_ELIGIBLE => __('ui.referral_center.status_filter.eligible'),
        ];

        foreach (ReferralCandidates::STATUSES as $status) {
            $options[$status] = __('ui.referral_center.status.' . $status);
        }

        return $options;
    }

    /**
     * Whether any upload has been recorded at all. When none has, the page
     * says so rather than silently listing the whole database as if it were
     * one upload.
     */
    public function hasRecordedBatches(): bool
    {
        return ReferralBatch::query()
            ->where('module', ReferralBatch::CHILDREN_MODULE)
            ->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            // Everything the screen can say something about - SAM, MAM and
            // the unmeasured - carrying its status as a selected column, so
            // one page of rows costs one query rather than one per row. Which
            // of them is actually listed is the status filter's decision, and
            // its default reproduces the old candidate query exactly.
            ->query(fn (): Builder => ReferralCandidates::overview()
                ->select('children.*')
                ->selectRaw(ReferralCandidates::statusCase() . ' as referral_status')
                // The record the row's history opens at, selected with the
                // row so the action below costs no lookup of its own.
                ->selectRaw(ReferralCandidates::followUpChildIdSql() . ' as follow_up_child_id'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('fields.name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('child_id')
                    ->label(__('fields.child_id'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sex')
                    ->label(__('fields.sex'))
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'male' => __('fields.male'),
                        'female' => __('fields.female'),
                        default => '-',
                    }),
                Tables\Columns\TextColumn::make('date_of_birth')
                    ->label(__('fields.date_of_birth'))
                    ->date()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('age')
                    ->label(__('ui.referral_center.columns.age'))
                    // Derived from date of birth when there is one, which is
                    // the same order of preference the Children forms use.
                    ->getStateUsing(fn (Child $record): ?int => $record->effective_age),
                Tables\Columns\TextColumn::make('muac_mm')
                    ->label(__('fields.muac_mm'))
                    ->sortable()
                    // A blank reading is said out loud rather than shown as an
                    // empty cell, which reads like a zero.
                    ->formatStateUsing(fn (mixed $state): string => blank($state)
                        ? __('ui.referral_center.status.missing_muac')
                        : (string) $state)
                    ->color(fn (mixed $state): ?string => blank($state) ? 'gray' : null),
                Tables\Columns\TextColumn::make('fi')
                    ->label(__('fields.fi')),
                Tables\Columns\TextColumn::make('classification')
                    ->label(__('ui.referral_center.columns.classification'))
                    ->badge()
                    // No measurement is not a classification. The cell says
                    // the reading has to be reviewed, and never guesses a band.
                    ->getStateUsing(fn (Child $record): string => MuacClassifier::classify($record->muac_mm)
                        ?? __('ui.referral_center.status.needs_review'))
                    ->color(fn (Child $record): string => MuacClassifier::color(
                        MuacClassifier::classify($record->muac_mm),
                    )),
                Tables\Columns\TextColumn::make('date_of_reporting')
                    ->label(__('fields.date_of_reporting'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('organization')
                    ->label(__('fields.organization'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('implementing_partner')
                    ->label(__('fields.implementing_partner'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('location')
                    ->label(__('fields.location'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('follow_up_status')
                    ->label(__('ui.referral_center.columns.follow_up_status'))
                    ->badge()
                    // Read off the status the query already selected, so this
                    // column adds no lookup of its own.
                    ->getStateUsing(fn (Child $record): string => __(
                        'ui.referral_center.follow_up_status.' . static::statusOf($record),
                    ))
                    ->color(fn (Child $record): string => static::statusColor(static::statusOf($record))),
                Tables\Columns\TextColumn::make('referral_status')
                    ->label(__('ui.referral_center.columns.status'))
                    ->badge()
                    ->getStateUsing(fn (Child $record): string => __(
                        'ui.referral_center.status.' . static::statusOf($record),
                    ))
                    ->color(fn (Child $record): string => static::statusColor(static::statusOf($record))),
            ])
            // SAM before MAM, so the most urgent readings are on the first
            // page of a long upload.
            ->defaultSort('muac_mm', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('referral_batch')
                    ->label(__('ui.referral_center.batch'))
                    ->placeholder(__('ui.referral_center.all_batches'))
                    ->options(fn (): array => ReferralBatch::query()
                        ->with('user')
                        ->where('module', ReferralBatch::CHILDREN_MODULE)
                        ->latest('id')
                        ->limit(25)
                        ->get()
                        ->mapWithKeys(fn (ReferralBatch $batch): array => [$batch->getKey() => $batch->label()])
                        ->all())
                    ->default(fn (): ?int => ReferralBatch::latestChildrenBatch()?->getKey())
                    ->query(function (Builder $query, array $data): Builder {
                        $batch = filled($data['value'] ?? null)
                            ? ReferralBatch::find($data['value'])
                            : null;

                        return ReferralCandidates::scopeToBatch($query, $batch);
                    }),
                Tables\Filters\SelectFilter::make('referral_status')
                    ->label(__('ui.referral_center.columns.status'))
                    ->placeholder(__('ui.referral_center.status_filter.all'))
                    ->options(fn (): array => static::statusFilterOptions())
                    // The default reproduces the listing this page has always
                    // shown: SAM/MAM with no open episode.
                    ->default(ReferralCandidates::FILTER_ELIGIBLE)
                    ->query(fn (Builder $query, array $data): Builder => ReferralCandidates::scopeToStatus(
                        $query,
                        filled($data['value'] ?? null) ? $data['value'] : null,
                    )),
            ])
            ->actions([
                // A child the Centre will not refer is not a dead end: this
                // opens the follow-up record it already has, where the
                // admission, every visit, the latest MUAC and the discharge
                // are read. It creates nothing and changes nothing.
                Action::make('view_follow_up')
                    ->label(__('ui.referral_center.view_follow_up'))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->url(fn (Child $record): ?string => static::followUpUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (Child $record): bool => static::followUpUrl($record) !== null),
            ])
            ->bulkActions([
                BulkAction::make('refer')
                    ->label(__('ui.referral_center.refer_selected'))
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('ui.referral_center.confirm_heading'))
                    ->modalDescription(__('ui.referral_center.confirm_body'))
                    ->modalSubmitActionLabel(__('ui.referral_center.confirm_submit'))
                    ->authorize(fn (): bool => static::canRefer())
                    ->visible(fn (): bool => static::canRefer())
                    // Keep the selection as a query: hydrating a whole upload
                    // to read its primary keys is what the processor exists
                    // to avoid.
                    ->fetchSelectedRecords(false)
                    ->deselectRecordsAfterCompletion()
                    ->action(function (BulkAction $action): void {
                        $this->referSelection($action);
                    }),
            ])
            ->emptyStateHeading(__('ui.referral_center.empty_heading'))
            ->emptyStateDescription(__('ui.referral_center.empty_description'))
            ->emptyStateIcon('heroicon-o-check-badge');
    }

    /**
     * Referring opens a follow-up record, so it needs both the permission to
     * decide and the existing policy's permission to create one. Neither is
     * bypassed here.
     */
    public static function canRefer(): bool
    {
        $user = auth()->user();

        return ($user?->can('children.refer') ?? false)
            && ($user?->can('create', FollowUpChild::class) ?? false);
    }

    /**
     * Run the referral over the current selection and report what happened.
     *
     * A failure here is reported and nothing else: the children are already
     * saved, and the ones that could not be referred stay in the candidate
     * list for another attempt.
     */
    private function referSelection(BulkAction $action): void
    {
        abort_unless(static::canRefer(), 403);

        $ids = $action->getSelectedRecordsQuery()
            ->reorder()
            ->pluck((new Child())->getQualifiedKeyName())
            ->all();

        if ($ids === []) {
            Notification::make()
                ->title(__('ui.referral_center.nothing_selected'))
                ->warning()
                ->send();

            return;
        }

        try {
            $result = ReferralProcessor::refer($ids, $this->currentBatch());
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title(__('ui.referral_center.failed', ['count' => count($ids)]))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        // Each reason said as itself. A child already known to the follow-up
        // module was not a failed referral, and must not read like one.
        $body = [];

        foreach (['skipped_active', 'skipped_closed', 'skipped_ineligible'] as $reason) {
            if ($result[$reason] > 0) {
                $body[] = __('ui.referral_center.' . $reason, ['count' => $result[$reason]]);
            }
        }

        if ($result['failed'] > 0) {
            $body[] = __('ui.referral_center.failed', ['count' => $result['failed']]);
        }

        $notification = Notification::make()
            ->title(__('ui.referral_center.referred', ['count' => $result['referred']]))
            ->body(implode(' ', $body) ?: null)
            ->icon('heroicon-o-arrow-right-circle');

        // A run that referred nobody because every child was already known to
        // the follow-up module is a correct answer, not a warning and not a
        // success: it is told plainly.
        match (true) {
            $result['failed'] > 0 => $notification->warning(),
            $result['referred'] > 0 => $notification->success(),
            default => $notification->info(),
        };

        $notification->send();
    }
}
