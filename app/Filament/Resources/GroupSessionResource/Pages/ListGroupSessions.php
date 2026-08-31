<?php

namespace App\Filament\Resources\GroupSessionResource\Pages;

use App\Events\ExcelActionOccurred;
use App\Support\Notifications\ActionType;
use App\Exports\GroupSessionExport;
use App\Exports\PdfExport;
use App\Filament\Resources\GroupSessionResource;
use App\Filament\Concerns\HasExcelImport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListGroupSessions extends ListRecords
{
    use HasExcelImport;

    protected static string $resource = GroupSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')->label(__('fields.export_excel'))->icon('heroicon-o-arrow-down-tray')->authorize(fn (): bool => auth()->user()?->can('group_sessions.export') ?? false)->visible(fn (): bool => auth()->user()?->can('group_sessions.export') ?? false)->action(fn () => $this->downloadExcel()),
            Actions\Action::make('exportPdf')->label(__('fields.export_pdf'))->icon('heroicon-o-document-arrow-down')->authorize(fn (): bool => auth()->user()?->can('group_sessions.export') ?? false)->visible(fn (): bool => auth()->user()?->can('group_sessions.export') ?? false)->action(fn () => $this->downloadPdf()),
            $this->importAction(),
            Actions\CreateAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('group_sessions.create') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('group_sessions.create') ?? false),
        ];
    }

    protected function importModuleKey(): string
    {
        return 'group_sessions';
    }

    public function downloadExcel()
    {
        abort_unless(auth()->user()?->can('group_sessions.export') ?? false, 403);
        // Announce the export after the fact; it cannot affect the download.
        ExcelActionOccurred::dispatch('GroupSession', ActionType::EXPORT, auth()->user());

        return Excel::download(new GroupSessionExport($this->exportQuery()), 'group-sessions.xlsx');
    }

    public function downloadPdf()
    {
        abort_unless(auth()->user()?->can('group_sessions.export') ?? false, 403);
        return PdfExport::download(new GroupSessionExport($this->exportQuery()), 'group-sessions.pdf', __('fields.group_sessions'), 'full_name_ar');
    }

    private function exportQuery()
    {
        $query = clone $this->getTableQueryForExport();
        return $query->select($query->getModel()->qualifyColumn('*'));
    }
}
