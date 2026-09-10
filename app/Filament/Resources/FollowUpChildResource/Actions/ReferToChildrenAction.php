<?php

namespace App\Filament\Resources\FollowUpChildResource\Actions;

use App\Filament\Resources\ChildResource;
use App\Models\Child;
use App\Models\FollowUpChild;
use App\Support\Referral\CuredChildrenReferral;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;

/**
 * "Refer to Children" on a cured Follow Up Child record.
 *
 * Offered only on a record closed as cured whose ID number appears on no
 * Children row. It writes one Children record through the existing discharge
 * transfer and nothing else: the follow-up record keeps its outcome, its
 * discharge date and every visit. Once the Children row exists the record no
 * longer qualifies, so the action disappears on its own.
 */
final class ReferToChildrenAction
{
    public static function make(string $name = 'referToChildren'): Action
    {
        return Action::make($name)
            ->label(__('ui.cured_referral.action'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('success')
            // A Children row is being created, so the Children create
            // permission is the one that decides.
            ->authorize(fn (): bool => ChildResource::allowsAction('create'))
            ->visible(fn (FollowUpChild $record): bool => ChildResource::allowsAction('create')
                && CuredChildrenReferral::isPending($record))
            ->requiresConfirmation()
            ->modalHeading(fn (FollowUpChild $record): string => __('ui.cured_referral.heading', [
                'name' => $record->child_name,
            ]))
            ->modalDescription(__('ui.cured_referral.description'))
            ->modalSubmitActionLabel(__('ui.cured_referral.submit'))
            ->modalIcon('heroicon-o-arrow-right-circle')
            ->schema(fn (FollowUpChild $record): array => [
                Section::make(__('ui.cured_referral.case'))
                    ->schema([
                        Text::make(__('ui.readmission.child_line', [
                            'name' => $record->child_name,
                            'id' => $record->id_number,
                        ])),
                        Text::make(__('ui.cured_referral.outcome_line', [
                            'outcome' => __('fields.' . $record->discharge_outcome),
                            'date' => $record->discharge_date?->format('Y-m-d') ?? '-',
                        ])),
                        Text::make(__('ui.cured_referral.unchanged_line')),
                    ])
                    ->compact(),
            ])
            ->action(function (FollowUpChild $record, Action $action): void {
                $child = CuredChildrenReferral::refer($record);

                if (! $child instanceof Child) {
                    // Re-checked at the moment of the write: the child has
                    // been added to Children since the list was drawn, or the
                    // record cannot be handed over as it stands.
                    $blocker = CuredChildrenReferral::blocker($record->fresh())
                        ?? CuredChildrenReferral::BLOCKER_ALREADY_EXISTS;

                    Notification::make()
                        ->title(__('ui.cured_referral.blocked.' . $blocker))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('ui.cured_referral.done_title'))
                    ->body(__('ui.cured_referral.done_body', ['name' => $child->name]))
                    ->success()
                    ->actions([
                        Action::make('open')
                            ->label(__('fields.open_children_record'))
                            ->url(ChildResource::getUrl('edit', ['record' => $child]))
                            ->button(),
                    ])
                    ->send();
            });
    }
}
