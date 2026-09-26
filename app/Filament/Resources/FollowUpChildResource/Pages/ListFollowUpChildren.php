<?php

namespace App\Filament\Resources\FollowUpChildResource\Pages;

use App\Support\Activity\AuditEvents;
use App\Exports\CsvExport;
use App\Exports\FollowUpChildrenExport;
use App\Exports\FollowUpChildPdfExport;
use App\Filament\Resources\FollowUpChildResource;
use App\Filament\Concerns\HasExcelImport;
use App\Models\FollowUpChild;
use App\Support\Referral\CuredChildrenReferral;
use App\Support\Referral\ReferralCandidates;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

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

            // Cured records whose ID number is on no Children row: the ones
            // still waiting to be sent to Children by hand. A subset of
            // "closed"; nothing is taken away from the other tabs.
            // @see \App\Support\Referral\CuredChildrenReferral
            'cured_pending_referral' => Tab::make(__('ui.follow_up_tabs.cured_pending_referral'))
                ->icon('heroicon-o-arrow-right-circle')
                ->badge(fn (): int => CuredChildrenReferral::query()->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => CuredChildrenReferral::scope($query)),
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

    /**
     * The whole follow-up history as one CSV file.
     *
     * Every episode on file - open, closed under any outcome, historical,
     * and each readmission as its own row - whatever tab the listing is
     * showing. The export used to read the table's own query, so with the
     * Active tab open it wrote the fifty-odd open cases and nothing else.
     *
     * Not returned from this action: the history runs to something like
     * 150,000 rows, and a file returned through Livewire is buffered whole.
     * The episodes are parked and the browser collects the file from the CSV
     * route, which writes it in primary-key order - as before - and checks it
     * row by row before a byte is sent. The route announces the export, with
     * its row count, once the file is complete.
     *
     * @see \App\Exports\CsvExport
     */
    public function downloadExcel()
    {
        abort_unless(auth()->user()?->can('follow_up_children.export') ?? false, 403);

        return CsvExport::start(
            new FollowUpChildrenExport(
                $this->allHistoryExportQuery()
                    ->reorder()
                    ->orderBy((new FollowUpChild)->qualifyColumn('id')),
            ),
            'follow_up_children.export',
            'follow-up-children.csv',
            'FollowUpChild',
            FollowUpChildResource::getUrl('index'),
        );
    }

    public function downloadPdf()
    {
        abort_unless(auth()->user()?->can('follow_up_children.export') ?? false, 403);

        AuditEvents::pdfExport('FollowUpChild');

        // This module keeps repeated visits: they print as numbered rows
        // under the record, not as thirty-two extra columns.
        return FollowUpChildPdfExport::start(
            $this->exportQuery(),
            'follow-up-children.pdf',
            __('fields.follow_up_children'),
        );
    }

    /**
     * The query behind the CSV export: the resource's own records with the
     * filters and search the user set, and nothing from the active tab.
     *
     * Built from the resource query rather than the table's, because the
     * table's query carries the tab's restriction with it. The filters and
     * the search are applied the way the table itself applies them; the
     * sort is left off, as the writer orders by primary key.
     */
    public function allHistoryExportQuery(): Builder
    {
        $query = static::getResource()::getEloquentQuery();

        $this->applyFiltersToTableQuery($query);
        $this->applySearchToTableQuery($query);

        return $query->select($query->getModel()->qualifyColumn('*'));
    }

    private function exportQuery()
    {
        $query = clone $this->getTableQueryForExport();

        return $query->select($query->getModel()->qualifyColumn('*'));
    }
}
