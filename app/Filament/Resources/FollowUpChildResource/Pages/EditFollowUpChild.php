<?php

namespace App\Filament\Resources\FollowUpChildResource\Pages;

use App\Filament\Resources\ChildResource;
use App\Filament\Resources\FollowUpChildResource;
use App\Models\FollowUpChild;
use App\Support\ChildFollowUpTransfer;
use App\Support\MuacClassifier;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditFollowUpChild extends EditRecord
{
    protected static string $resource = FollowUpChildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('follow_up_children.delete') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('follow_up_children.delete') ?? false),
        ];
    }

    /**
     * A closed episode is history: it is displayed, never rewritten.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->getRecord()->isLocked()) {
            Notification::make()
                ->title(__('fields.record_locked_notice'))
                ->danger()
                ->send();

            $this->halt();
        }

        return $data;
    }

    /**
     * A latest visit that came back Normal means the child has recovered. That
     * is a clinical judgement, not an arithmetic one, so it is offered to the
     * user rather than applied: they either discharge as cured - which closes
     * this episode and hands the child back to Children as a new visit - or
     * keep the child under follow-up.
     */
    protected function afterSave(): void
    {
        /** @var FollowUpChild $record */
        $record = $this->getRecord()->refresh();

        if ($record->isLocked()) {
            return;
        }

        $latestVisit = $record->latestVisit();

        if ($latestVisit === null || MuacClassifier::classify($latestVisit->muac) !== MuacClassifier::NORMAL) {
            return;
        }

        $this->dispatch('show-follow-up-discharge-alert', [
            'record_id' => $record->getKey(),
            'child_name' => $record->child_name,
            'muac' => (float) $latestVisit->muac,
        ]);
    }

    /**
     * Close the episode as cured and return the child to the Children module
     * carrying the measurement that discharged them.
     */
    #[On('confirmFollowUpDischarge')]
    public function confirmFollowUpDischarge(): void
    {
        /** @var FollowUpChild $record */
        $record = $this->getRecord()->refresh();

        $latestVisit = $record->latestVisit();

        if ($record->isLocked() || $latestVisit === null) {
            return;
        }

        if (! ChildFollowUpTransfer::canDischargeToChildren($record)) {
            Notification::make()
                ->title(__('fields.discharge_missing_data'))
                ->danger()
                ->send();

            return;
        }

        $record->update([
            'discharge_outcome' => FollowUpChild::CURED_OUTCOME,
            'discharge_date' => $latestVisit->visit_date,
        ]);

        $child = ChildFollowUpTransfer::discharge($record, $latestVisit);

        Notification::make()
            ->title(__('fields.discharged_as_cured'))
            ->success()
            ->actions([
                Actions\Action::make('open')
                    ->label(__('fields.open_children_record'))
                    ->url(ChildResource::getUrl('edit', ['record' => $child]))
                    ->button(),
            ])
            ->send();

        $this->redirect(FollowUpChildResource::getUrl('index'));
    }

    /**
     * Decline the discharge: the episode stays open and nothing is written.
     */
    #[On('keepUnderFollowUp')]
    public function keepUnderFollowUp(): void
    {
        Notification::make()
            ->title(__('fields.kept_under_follow_up'))
            ->info()
            ->send();
    }
}
