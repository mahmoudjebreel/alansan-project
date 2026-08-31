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
     * decision, so nothing is written until the user has confirmed it:
     *
     *  - first pass: raise the confirmation and stop, leaving the form intact
     *    so a mistyped MUAC can simply be corrected;
     *  - after "yes": enrol the child in follow-up, carry this very reading
     *    over as visit 1, and stop before the Children row is written - the
     *    same screening must not be counted in both modules.
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

        $followUpChild = ChildFollowUpTransfer::admit($this->pendingData, $fi);

        Notification::make()
            ->success()
            ->title(__('fields.referral_created_title'))
            ->body(__('fields.referral_created_body', ['fi' => $fi]))
            ->send();

        $this->redirect(FollowUpChildResource::getUrl('edit', ['record' => $followUpChild]));

        // Stop before handleRecordCreation(): the reading now lives in the
        // follow-up record only. halt() commits rather than rolls back, so the
        // record just created above survives.
        $this->halt();
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
