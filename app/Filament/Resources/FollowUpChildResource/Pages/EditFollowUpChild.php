<?php

namespace App\Filament\Resources\FollowUpChildResource\Pages;

use App\Filament\Resources\FollowUpChildResource;
use App\Models\FollowUpChild;
use App\Models\FollowUpChildVisit;
use App\Support\ChildFollowUpTransfer;
use App\Support\MuacClassifier;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Livewire\Attributes\On;

class EditFollowUpChild extends EditRecord
{
    protected static string $resource = FollowUpChildResource::class;

    /**
     * Set while the discharge confirmation is on screen, purely to keep the
     * page from redirecting away from the dialog after the save.
     */
    public bool $awaitingDischargeAnswer = false;

    /**
     * Identity of the latest visit as it stood before this save, so the
     * discharge prompt is raised for a reading that was actually just entered
     * and not on every unrelated edit of an already-Normal record.
     *
     * @var array{id: int|null, muac: string|null}|null
     */
    protected ?array $latestVisitBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('follow_up_children.delete') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('follow_up_children.delete') ?? false),
        ];
    }

    /**
     * A discharged record is read-only for good: say so above the form.
     */
    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        return $record->isLocked()
            ? __('fields.record_locked_hint', ['outcome' => __('fields.' . $record->discharge_outcome)])
            : null;
    }

    /**
     * Saving is not offered at all once the record is locked; the fields are
     * already disabled, and a Save button over a read-only form is only
     * confusing.
     */
    protected function getFormActions(): array
    {
        return $this->getRecord()->isLocked()
            ? [$this->getCancelFormAction()]
            : parent::getFormActions();
    }

    /**
     * Refuse a write to a discharged record even if one somehow reaches the
     * server - the disabled form is a convenience, not the guarantee.
     */
    protected function beforeSave(): void
    {
        if ($this->getRecord()->isLocked()) {
            Notification::make()
                ->warning()
                ->title(__('fields.record_locked_title'))
                ->body(__('fields.record_locked_body'))
                ->send();

            $this->halt();
        }

        $this->latestVisitBeforeSave = $this->latestVisitSnapshot();
    }

    /**
     * Offer the discharge once a newly entered latest visit comes back Normal.
     *
     * The visit itself is already saved by this point, which is exactly the
     * behaviour "keep under follow-up" asks for: declining changes nothing.
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record->isLocked()) {
            return;
        }

        $latest = $this->freshLatestVisit();

        if (! $latest || MuacClassifier::classify($latest->muac) !== MuacClassifier::NORMAL) {
            return;
        }

        // Only a reading that this save actually added or changed asks the
        // question; re-saving an untouched Normal record does not.
        $before = $this->latestVisitBeforeSave;
        $unchanged = $before !== null
            && $before['id'] === $latest->getKey()
            && $before['muac'] === (string) $latest->muac;

        if ($unchanged) {
            return;
        }

        $this->awaitingDischargeAnswer = true;

        $this->dispatch('show-follow-up-discharge-alert', [
            'muac' => (string) $latest->muac,
            'visit_date' => $latest->visit_date?->format('Y-m-d'),
            'child_name' => $record->child_name,
        ]);
    }

    /**
     * Stay on the page while the discharge question is unanswered.
     */
    protected function getRedirectUrl(): ?string
    {
        return $this->awaitingDischargeAnswer ? null : parent::getRedirectUrl();
    }

    /**
     * The user chose to discharge: mark the record Cured on the date of the
     * Normal visit, hand the child back to the Children module as a new visit,
     * and let the outcome lock the record.
     */
    #[On('confirmFollowUpDischarge')]
    public function confirmFollowUpDischarge(): void
    {
        $this->awaitingDischargeAnswer = false;

        $record = $this->getRecord();

        if ($record->isLocked()) {
            return;
        }

        $latest = $this->freshLatestVisit();

        if (! $latest || MuacClassifier::classify($latest->muac) !== MuacClassifier::NORMAL) {
            return;
        }

        $record->update([
            'discharge_outcome' => 'cured',
            'discharge_date' => $latest->visit_date,
        ]);

        if (! ChildFollowUpTransfer::canDischargeToChildren($record)) {
            // The discharge itself still stands; only the hand-back cannot be
            // written, because the Children table will not store the missing
            // detail and guessing it would invent data about a real child.
            Notification::make()
                ->warning()
                ->title(__('fields.discharge_cured_title'))
                ->body(__('fields.discharge_incomplete_body'))
                ->send();

            $this->redirect(FollowUpChildResource::getUrl('index'));

            return;
        }

        ChildFollowUpTransfer::discharge($record, $latest);

        Notification::make()
            ->success()
            ->title(__('fields.discharge_cured_title'))
            ->body(__('fields.discharge_cured_body'))
            ->send();

        $this->redirect(FollowUpChildResource::getUrl('index'));
    }

    /**
     * The user chose to keep the child under follow-up: the visit stays saved
     * and the page finishes the save it was in the middle of.
     */
    #[On('keepUnderFollowUp')]
    public function keepUnderFollowUp(): void
    {
        $this->awaitingDischargeAnswer = false;

        $this->redirect(FollowUpChildResource::getUrl('index'));
    }

    /**
     * @return array{id: int|null, muac: string|null}
     */
    protected function latestVisitSnapshot(): array
    {
        $latest = $this->freshLatestVisit();

        return [
            'id' => $latest?->getKey(),
            'muac' => $latest === null ? null : (string) $latest->muac,
        ];
    }

    protected function freshLatestVisit(): ?FollowUpChildVisit
    {
        /** @var FollowUpChild $record */
        $record = $this->getRecord();

        return $record->visits()->get()->last();
    }
}
