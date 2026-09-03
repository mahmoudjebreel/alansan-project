<?php

namespace App\Filament\Resources\ChildResource\Pages;

use App\Filament\Resources\ChildResource;
use App\Filament\Resources\FollowUpChildResource;
use App\Support\ChildDuplicateChecker;
use App\Support\ChildFollowUpTransfer;
use App\Support\MuacClassifier;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\On;

class CreateChild extends CreateRecord
{
    protected static string $resource = ChildResource::class;

    /**
     * Set once the user has answered the malnutrition referral prompt with
     * "yes". Until then a MAM/SAM reading never reaches the database.
     */
    public bool $referralConfirmed = false;

    /**
     * The validated form data of the save currently in flight, stashed so the
     * beforeCreate hook can read the reading that is about to be stored.
     *
     * @var array<string, mixed>
     */
    protected array $pendingData = [];

    /**
     * The confirmed FI of a referral in flight, set once "yes" has been given
     * and the reading is going into follow-up.
     */
    protected ?string $referralFi = null;

    /**
     * Where to send the user after a first-ever-visit referral: the follow-up
     * record that was raised alongside the Children row.
     */
    protected ?string $referralRedirectUrl = null;

    /**
     * Pull the relatively stable data of the last active visit into the form.
     *
     * The measurements taken during this visit (MUAC, weight, height, WHZ,
     * oedema) are deliberately left out so they stay empty and fully editable.
     * Entering the new MUAC is what triggers the relapse check that settles the
     * visit type.
     */
    #[On('fillChildDataFromAlert')]
    public function fillChildDataFromAlert(array $data): void
    {
        $childData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        $this->form->fill(array_merge($childData, [
            // No MUAC yet, so this stays "follow up" until the user enters one
            // and the relapse check re-derives it.
            'visit_type' => ChildDuplicateChecker::resolveVisitType($childData['child_id'] ?? null),
            'date_of_reporting' => now()->format('Y-m-d'),
        ]));
    }

    /**
     * The visit type is decided server-side, never by the (locked) form field:
     * no active record with the same child ID means "new", otherwise the
     * relapse check compares this visit's FI against the previous one.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['visit_type'] = ChildDuplicateChecker::resolveVisitType(
            $data['child_id'] ?? null,
            $data['muac_mm'] ?? null,
        );

        return $this->pendingData = $data;
    }

    /**
     * Divert a malnourished reading into the Follow Up Child programme.
     *
     * A Normal reading is untouched and follows the existing visit-type and
     * relapse rules exactly as before. A MAM or SAM reading is a referral
     * decision, so nothing is written until the user has confirmed it: the
     * first pass raises the confirmation and stops, leaving the form intact so
     * a mistyped MUAC can simply be corrected.
     *
     * What "yes" then writes depends on whether the child is new to the system:
     *
     *  - first ever visit (no active record in either module): the screening
     *    is a real event in its own right, so the Children row is written as
     *    usual and the follow-up record is raised alongside it in afterCreate();
     *  - a later relapse (the child already has a Children record): the reading
     *    belongs to the follow-up record only, and no second Children row is
     *    written for it - that would count the same screening twice.
     */
    protected function beforeCreate(): void
    {
        $fi = MuacClassifier::classify($this->pendingData['muac_mm'] ?? null);

        if (! MuacClassifier::isMalnourished($fi)) {
            return;
        }

        if (! $this->referralConfirmed) {
            $this->dispatch('show-child-referral-alert', [
                'fi' => $fi,
                'muac' => $this->pendingData['muac_mm'] ?? null,
                'child_name' => $this->pendingData['name'] ?? null,
            ]);

            $this->halt();
        }

        $this->referralFi = $fi;

        if (ChildFollowUpTransfer::isFirstEverVisit($this->pendingData['child_id'] ?? null)) {
            // Let the ordinary creation run; afterCreate() enrols the child.
            return;
        }

        $followUpChild = ChildFollowUpTransfer::admit($this->pendingData, $fi);

        $this->announceReferral($fi, wroteChildRow: false);

        $this->redirect(FollowUpChildResource::getUrl('edit', ['record' => $followUpChild]));

        // Stop before handleRecordCreation(): the reading now lives in the
        // follow-up record only. halt() commits rather than rolls back, so the
        // record just created above survives.
        $this->halt();
    }

    /**
     * First ever visit, confirmed as a referral: the Children row has just
     * been written, so enrol the child in follow-up and link the two records
     * to each other.
     *
     * Both writes share the transaction the create is already running in, so
     * a Children row can never be left behind without its admission.
     */
    protected function afterCreate(): void
    {
        if ($this->referralFi === null) {
            return;
        }

        /** @var \App\Models\Child $child */
        $child = $this->getRecord();

        $followUpChild = ChildFollowUpTransfer::admit($this->pendingData, $this->referralFi, $child);

        // The back-reference is what lets a later report tell this screening
        // apart from an ordinary one, should it ever need to exclude it.
        $child->forceFill(['source_follow_up_child_id' => $followUpChild->getKey()])->saveQuietly();

        $this->referralRedirectUrl = FollowUpChildResource::getUrl('edit', ['record' => $followUpChild]);

        $this->announceReferral($this->referralFi, wroteChildRow: true);
    }

    /**
     * Send the user to the new follow-up record rather than to wherever a
     * plain Children create would have gone.
     */
    protected function getRedirectUrl(): string
    {
        return $this->referralRedirectUrl ?? parent::getRedirectUrl();
    }

    /**
     * Say which of the two things actually happened, since a first ever visit
     * leaves a Children row behind and a later relapse deliberately does not.
     */
    private function announceReferral(string $fi, bool $wroteChildRow): void
    {
        Notification::make()
            ->success()
            ->title(__('fields.referral_created_title'))
            ->body(__(
                $wroteChildRow ? 'fields.referral_created_with_child_body' : 'fields.referral_created_body',
                ['fi' => $fi],
            ))
            ->send();
    }

    /**
     * The user accepted the referral in the confirmation dialog: replay the
     * save, this time allowed to go through with the transfer.
     */
    #[On('confirmChildReferral')]
    public function confirmChildReferral(): void
    {
        $this->referralConfirmed = true;

        $this->create();

        // A failed validation or a second prompt must not leave the flag on.
        $this->referralConfirmed = false;
    }
}
