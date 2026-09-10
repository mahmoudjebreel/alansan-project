<?php

namespace App\Filament\Resources\FollowUpChildResource\Actions;

use App\Filament\Resources\FollowUpChildResource;
use App\Models\FollowUpChild;
use App\Support\ChildFollowUpTransfer;
use App\Support\MuacClassifier;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;

/**
 * "Readmission" on a closed Follow Up Child record.
 *
 * The one deliberate way a child whose episode closed is admitted again from
 * inside the follow-up module. It is offered only on a record that is closed
 * and whose child has no other episode open, and it does one thing: opens a
 * NEW record for the same child, marked as a readmission and linked back to
 * this one, with its own visit 1. The closed record is read to copy the
 * child's identity and is never written - not its outcome, not its discharge
 * date, not a single visit.
 *
 * One definition, used by the listing's row action and by both record pages,
 * so the rule of when it appears and what it writes cannot drift.
 */
final class ReadmissionAction
{
    public static function make(string $name = 'readmission'): Action
    {
        return Action::make($name)
            ->label(__('fields.readmission'))
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('warning')
            ->authorize(fn (): bool => FollowUpChildResource::allowsAction('create'))
            ->visible(fn (FollowUpChild $record): bool => FollowUpChildResource::allowsAction('create')
                && $record->canBeReadmitted())
            ->modalHeading(fn (FollowUpChild $record): string => __('ui.readmission.heading', [
                'name' => $record->child_name,
            ]))
            ->modalDescription(__('ui.readmission.description'))
            ->modalSubmitActionLabel(__('ui.readmission.submit'))
            ->modalIcon('heroicon-o-arrow-path-rounded-square')
            ->schema(fn (FollowUpChild $record): array => [
                // What the person is looking at, said plainly before they
                // enter anything: the child is known, the case is closed and
                // how, and the case they are about to open is a new one.
                Section::make(__('ui.readmission.previous_case'))
                    ->schema([
                        Text::make(__('ui.readmission.child_line', [
                            'name' => $record->child_name,
                            'id' => $record->id_number,
                        ])),
                        Text::make(__('ui.readmission.outcome_line', [
                            'outcome' => __('fields.' . $record->discharge_outcome),
                            'date' => $record->discharge_date?->format('Y-m-d') ?? '-',
                        ])),
                        Text::make(__('ui.readmission.visits_line', [
                            'count' => $record->visits()->count(),
                        ])),
                        Text::make(__('ui.readmission.unchanged_line')),
                    ])
                    ->compact(),
                DatePicker::make('admission_date')
                    ->label(__('fields.admission_date'))
                    ->default(now()->format('Y-m-d'))
                    ->required(),
                DatePicker::make('visit_date')
                    ->label(__('ui.readmission.first_visit_date'))
                    ->default(now()->format('Y-m-d'))
                    ->required(),
                // The reading classifies the readmission - SAM, MAM or
                // Normal - by the shared classifier, in the transfer. A
                // Normal reading opens the episode too, under monitoring.
                TextInput::make('muac')
                    ->label(__('fields.muac'))
                    ->numeric()
                    ->required()
                    ->helperText(__('ui.readmission.muac_hint')),
            ])
            ->action(function (FollowUpChild $record, array $data, Action $action): void {
                $followUpChild = ChildFollowUpTransfer::readmitFromEpisode($record, $data);

                if (! $followUpChild instanceof FollowUpChild) {
                    // The record stopped qualifying between the click and the
                    // confirmation - somebody else opened an episode, say.
                    Notification::make()
                        ->title(__('ui.readmission.not_possible'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('ui.readmission.done_title'))
                    ->body(__('ui.readmission.done_body', [
                        'name' => $followUpChild->child_name,
                        // What the entered reading classified as, Normal
                        // included; the admission column holds SAM/MAM only.
                        'fi' => MuacClassifier::classify($data['muac'] ?? null),
                    ]))
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->success()
                    ->send();

                $action->redirect(FollowUpChildResource::getUrl('edit', ['record' => $followUpChild]));
            });
    }
}
