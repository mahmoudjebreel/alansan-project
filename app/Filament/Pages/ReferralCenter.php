<?php

namespace App\Filament\Pages;

use App\Models\Child;
use App\Models\FollowUpChild;
use App\Models\ReferralBatch;
use App\Support\MuacClassifier;
use App\Support\Referral\ReferralCandidates;
use App\Support\Referral\ReferralProcessor;
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
            ->query(fn (): Builder => ReferralCandidates::query())
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
                Tables\Columns\TextColumn::make('age')
                    ->label(__('ui.referral_center.columns.age'))
                    // Derived from date of birth when there is one, which is
                    // the same order of preference the Children forms use.
                    ->getStateUsing(fn (Child $record): ?int => $record->effective_age),
                Tables\Columns\TextColumn::make('muac_mm')
                    ->label(__('fields.muac_mm'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('fi')
                    ->label(__('fields.fi')),
                Tables\Columns\TextColumn::make('classification')
                    ->label(__('ui.referral_center.columns.classification'))
                    ->badge()
                    ->getStateUsing(fn (Child $record): ?string => MuacClassifier::classify($record->muac_mm))
                    ->color(fn (?string $state): string => MuacClassifier::color($state)),
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
                Tables\Columns\TextColumn::make('referral_status')
                    ->label(__('ui.referral_center.columns.status'))
                    ->badge()
                    ->color('warning')
                    // Everything the table lists is by definition still
                    // waiting: a referred child leaves the candidate query.
                    ->getStateUsing(fn (): string => __('ui.referral_center.status.pending')),
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

        $body = [];

        if ($result['skipped'] > 0) {
            $body[] = __('ui.referral_center.skipped', ['count' => $result['skipped']]);
        }

        if ($result['failed'] > 0) {
            $body[] = __('ui.referral_center.failed', ['count' => $result['failed']]);
        }

        $notification = Notification::make()
            ->title(__('ui.referral_center.referred', ['count' => $result['referred']]))
            ->body(implode(' ', $body) ?: null)
            ->icon('heroicon-o-arrow-right-circle');

        $result['failed'] > 0
            ? $notification->warning()->send()
            : $notification->success()->send();
    }
}
