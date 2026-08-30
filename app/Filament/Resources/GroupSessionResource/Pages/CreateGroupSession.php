<?php

namespace App\Filament\Resources\GroupSessionResource\Pages;

use App\Filament\Resources\GroupSessionResource;
use App\Support\GroupSessionDuplicateChecker;
use Filament\Resources\Pages\CreateRecord;
use Livewire\Attributes\On;

class CreateGroupSession extends CreateRecord
{
    protected static string $resource = GroupSessionResource::class;

    /**
     * Pull the participant's relatively stable data from their last active
     * session into this (still unsaved) form.
     *
     * Everything belonging to the session itself is deliberately left empty so
     * the user fills it in for the session actually being registered: the
     * session date, the group number and - most importantly - the subject,
     * which is very often a different one from last time.
     *
     * This always builds a brand new record; the previous session is never
     * touched.
     */
    #[On('fillGroupSessionDataFromAlert')]
    public function fillGroupSessionDataFromAlert(array $data): void
    {
        $participantData = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;

        $this->form->fill(array_merge($participantData, [
            // The ID number already has an active session, so this one is a
            // follow up; the field itself stays locked.
            'visit_type' => GroupSessionDuplicateChecker::resolveVisitType($participantData['id_number'] ?? null),
            'session_date' => null,
            'session_group_number' => null,
            'session_subject' => null,
            'session_subject_other' => null,
        ]));
    }

    /**
     * The visit type is decided server-side, never by the (locked) form field:
     * no active session with the same ID number means "new", otherwise it is a
     * follow up.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['visit_type'] = GroupSessionDuplicateChecker::resolveVisitType($data['id_number'] ?? null);

        return $data;
    }
}
