<?php

namespace App\Filament\Resources\MotherToMotherResource\Pages;

use App\Events\ExcelActionOccurred;
use App\Support\Notifications\ActionType;
use App\Exports\MotherToMotherExport;
use App\Exports\PdfExport;
use App\Filament\Resources\MotherToMotherResource;
use App\Filament\Concerns\HasExcelImport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListMotherToMotherSessions extends ListRecords
{
    use HasExcelImport;

    protected static string $resource = MotherToMotherResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')->label(__('fields.export_excel'))->icon('heroicon-o-arrow-down-tray')->authorize(fn (): bool => auth()->user()?->can('mother_to_mother.export') ?? false)->visible(fn (): bool => auth()->user()?->can('mother_to_mother.export') ?? false)->action(fn () => $this->downloadExcel()),
            Actions\Action::make('exportPdf')->label(__('fields.export_pdf'))->icon('heroicon-o-document-arrow-down')->authorize(fn (): bool => auth()->user()?->can('mother_to_mother.export') ?? false)->visible(fn (): bool => auth()->user()?->can('mother_to_mother.export') ?? false)->action(fn () => $this->downloadPdf()),
            $this->importAction(),
            Actions\CreateAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('mother_to_mother.create') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('mother_to_mother.create') ?? false),
        ];
    }

    protected function importModuleKey(): string
    {
        return 'mother_to_mother';
    }

    public function downloadExcel()
    {
        abort_unless(auth()->user()?->can('mother_to_mother.export') ?? false, 403);
        // Announce the export after the fact; it cannot affect the download.
        ExcelActionOccurred::dispatch('MotherToMotherSession', ActionType::EXPORT, auth()->user());

        return Excel::download(new MotherToMotherExport($this->exportQuery()), 'mother-to-mother-sessions.xlsx');
    }

    public function downloadPdf()
    {
        abort_unless(auth()->user()?->can('mother_to_mother.export') ?? false, 403);
        return PdfExport::download(new MotherToMotherExport($this->exportQuery()), 'exports.mother-to-mother-sessions-pdf', 'mother-to-mother-sessions.pdf', __('fields.mother_to_mother_sessions'));
    }

    private function exportQuery()
    {
        $query = clone $this->getTableQueryForExport();
        return $query->select($query->getModel()->qualifyColumn('*'));
    }
}
