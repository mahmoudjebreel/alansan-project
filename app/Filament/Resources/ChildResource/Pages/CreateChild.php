<?php

namespace App\Filament\Resources\ChildResource\Pages;

use App\Filament\Resources\ChildResource;
use App\Filament\Resources\FollowUpChildResource;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Support\ChildDuplicateChecker;
use App\Support\ChildFollowUpTransfer;
use App\Support\MuacClassifier;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\On;

class CreateChild extends CreateRecord
{
    protected static string $resource = ChildResource::class;

    /**
     * Set from the browser when the screener answers the SAM/MAM prompt.
     *
     * It defaults to false so that every path which never sees the prompt -
     * a test, a console command, a future API - keeps referring automatically,
     * exactly as it did before the prompt existed. Only an explicit refusal in
     * the dialog turns it on.
     */
    public bool $declineFollowUpReferral = false;

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

        return $data;
    }

    /**
     * Every screening is kept in Children whatever it says; a MAM or SAM
     * reading additionally opens a follow-up episode for the same child.
     *
     * The screener is asked about that referral in the browser, the moment the
     * reading is entered, and the answer arrives here on the save request. A
     * refusal is a decision rather than an omission, so it is reported back
     * instead of the save simply passing in silence.
     */
    protected function afterCreate(): void
    {
        /** @var Child $child */
        $child = $this->record;

        if ($this->declineFollowUpReferral && MuacClassifier::isMalnourished($child->fi)) {
            Notification::make()
                ->title(__('ui.referral.declined'))
                ->icon('heroicon-o-hand-raised')
                ->warning()
                ->send();

            return;
        }

        $followUpChild = ChildFollowUpTransfer::refer($child);

        if (! $followUpChild instanceof FollowUpChild) {
            return;
        }

        Notification::make()
            ->title(__('fields.referred_to_follow_up_title'))
            ->body(__('fields.referred_to_follow_up_body', [
                'name' => $child->name,
                'fi' => $followUpChild->admitted_with,
            ]))
            ->icon('heroicon-o-arrow-right-circle')
            ->warning()
            ->persistent()
            ->actions([
                Action::make('open')
                    ->label(__('fields.open_follow_up_record'))
                    ->url(FollowUpChildResource::getUrl('edit', ['record' => $followUpChild]))
                    ->button(),
            ])
            ->send();
    }
}
