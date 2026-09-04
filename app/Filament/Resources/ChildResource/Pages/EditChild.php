<?php

namespace App\Filament\Resources\ChildResource\Pages;

use App\Filament\Resources\ChildResource;
use App\Filament\Resources\FollowUpChildResource;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Support\ChildFollowUpTransfer;
use App\Support\MuacClassifier;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditChild extends EditRecord
{
    protected static string $resource = ChildResource::class;

    /**
     * Set from the browser when the screener confirms the SAM/MAM prompt that
     * a changed measurement raised.
     *
     * It defaults to false, so a save that never saw the prompt - a test, a
     * console command, the bulk import - behaves exactly as it did before this
     * existed: the edit is written and no episode is opened. Only an explicit
     * "yes" in the dialog turns it on, and afterSave turns it back off, so one
     * confirmation can only ever open one episode.
     */
    public bool $referFollowUpOnSave = false;

    /**
     * The follow-up record this save opened, if any. Read by getRedirectUrl(),
     * which Filament calls after afterSave().
     */
    protected ?FollowUpChild $openedFollowUpChild = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('children.delete') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('children.delete') ?? false),
        ];
    }

    /**
     * A measurement corrected upwards into SAM or MAM opens a follow-up
     * episode, once the screener has confirmed it.
     *
     * Unlike a first screening, the Children row here already existed and is
     * simply saved with its new reading - the episode is opened alongside it,
     * not instead of it.
     *
     * Three conditions, all of them required, and the middle one is the point
     * of the whole change: the screener said yes, the measurement actually
     * changed in this save, and the new reading is one the programme admits
     * on. Without the second, re-saving a child who is already SAM would open
     * an episode for a form nobody touched.
     */
    protected function afterSave(): void
    {
        if (! $this->referFollowUpOnSave) {
            return;
        }

        // One confirmation, one episode: a later save has to be confirmed on
        // its own terms.
        $this->referFollowUpOnSave = false;

        /** @var Child $child */
        $child = $this->record;

        if (! $child->wasChanged('muac_mm')) {
            return;
        }

        if (! MuacClassifier::isMalnourished($child->fi)) {
            return;
        }

        // Already under follow-up: the edit stands, but counting one episode
        // as two would corrupt every report the module feeds, so this says so
        // rather than passing in silence.
        if (ChildFollowUpTransfer::hasOpenEpisode($child->child_id)) {
            Notification::make()
                ->title(__('ui.referral.already_open'))
                ->icon('heroicon-o-information-circle')
                ->warning()
                ->send();

            return;
        }

        $followUpChild = ChildFollowUpTransfer::refer($child);

        if (! $followUpChild instanceof FollowUpChild) {
            return;
        }

        $this->openedFollowUpChild = $followUpChild;

        Notification::make()
            ->title(__('ui.referral.edit_referred_title'))
            ->body(__('ui.referral.edit_referred_body', [
                'name' => $child->name,
                'fi' => $followUpChild->admitted_with,
            ]))
            ->icon('heroicon-o-arrow-right-circle')
            ->success()
            ->send();
    }

    /**
     * Where the save lands.
     *
     * Straight to the episode that was just opened, because that record is
     * incomplete until somebody fills it in and the screener is the person who
     * has the information. Every other save redirects wherever it always did.
     */
    protected function getRedirectUrl(): ?string
    {
        if ($this->openedFollowUpChild instanceof FollowUpChild) {
            return FollowUpChildResource::getUrl('edit', ['record' => $this->openedFollowUpChild]);
        }

        return parent::getRedirectUrl();
    }
}
