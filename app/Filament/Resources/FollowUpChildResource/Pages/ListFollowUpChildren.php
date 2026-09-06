<?php

namespace App\Filament\Resources\FollowUpChildResource\Pages;

use App\Events\ExcelActionOccurred;
use App\Support\Notifications\ActionType;
use App\Exports\FollowUpChildrenExport;
use App\Exports\FollowUpChildPdfExport;
use App\Filament\Resources\FollowUpChildResource;
use App\Filament\Concerns\HasExcelImport;
use App\Models\FollowUpChild;
use App\Support\Referral\ReferralCandidates;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class ListFollowUpChildren extends ListRecords
{
    use HasExcelImport;

    protected static string $resource = FollowUpChildResource::class;

    /**
     * Open episodes and finished ones, told apart.
     *
     * The split is the model's own: a record is closed when its discharge
     * outcome is one of the five that end an episode, and open otherwise.
     * No new status is introduced here and nothing is filtered out - "All"
     * is still the first tab and still shows exactly what it always did.
     *
     * @see \App\Models\FollowUpChild::CLOSING_OUTCOMES
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('ui.follow_up_tabs.all'))
                ->badge(fn (): int => FollowUpChild::query()->count()),

            'active' => Tab::make(__('ui.follow_up_tabs.active'))
                ->icon('heroicon-o-arrow-path')
                ->badge(fn (): int => ReferralCandidates::activeFollowUps()->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where(function (Builder $query): void {
                        $query->whereNull('discharge_outcome')
                            ->orWhereNotIn('discharge_outcome', FollowUpChild::CLOSING_OUTCOMES);
                    })),

            'closed' => Tab::make(__('ui.follow_up_tabs.closed'))
                ->icon('heroicon-o-lock-closed')
                ->badge(fn (): int => ReferralCandidates::closedFollowUps()->count())
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('discharge_outcome', FollowUpChild::CLOSING_OUTCOMES)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label(__('fields.export_excel'))
                ->icon('heroicon-o-arrow-down-tray')
                ->authorize(fn (): bool => auth()->user()?->can('follow_up_children.export') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('follow_up_children.export') ?? false)
                ->action(fn () => $this->downloadExcel()),
            Actions\Action::make('exportPdf')
                ->label(__('fields.export_pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->authorize(fn (): bool => auth()->user()?->can('follow_up_children.export') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('follow_up_children.export') ?? false)
                ->action(fn () => $this->downloadPdf()),
            $this->importAction(),
            // No create action: a follow-up episode is opened by the Children
            // module when a screening comes back MAM or SAM.
            // @see \App\Support\ChildFollowUpTransfer::refer()
        ];
    }

    protected function importModuleKey(): string
    {
        return 'follow_up_children';
    }

    public function downloadExcel()
    {
        abort_unless(auth()->user()?->can('follow_up_children.export') ?? false, 403);

        // Announce the export after the fact; it cannot affect the download.
        ExcelActionOccurred::dispatch('FollowUpChild', ActionType::EXPORT, auth()->user());

        return Excel::download(new FollowUpChildrenExport($this->exportQuery()), 'follow-up-children.xlsx');
    }

    public function downloadPdf()
    {
        abort_unless(auth()->user()?->can('follow_up_children.export') ?? false, 403);

        // This module keeps repeated visits: they print as numbered rows
        // under the record, not as thirty-two extra columns.
        return FollowUpChildPdfExport::download(
            $this->exportQuery(),
            'follow-up-children.pdf',
            __('fields.follow_up_children'),
        );
    }

    private function exportQuery()
    {
        $query = clone $this->getTableQueryForExport();

        return $query->select($query->getModel()->qualifyColumn('*'));
    }
}
