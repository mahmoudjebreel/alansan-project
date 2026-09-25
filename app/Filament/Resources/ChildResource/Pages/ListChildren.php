<?php

namespace App\Filament\Resources\ChildResource\Pages;

use App\Events\ExcelActionOccurred;
use App\Support\Activity\AuditEvents;
use App\Support\Notifications\ActionType;
use App\Exports\ChildrenExport;
use App\Exports\CsvExport;
use App\Exports\PdfExport;
use App\Filament\Resources\ChildResource;
use App\Filament\Concerns\HasExcelImport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListChildren extends ListRecords
{
    use HasExcelImport;

    protected static string $resource = ChildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label(__('fields.export_excel'))
                ->icon('heroicon-o-arrow-down-tray')
                ->authorize(fn (): bool => auth()->user()?->can('children.export') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('children.export') ?? false)
                ->action(fn () => $this->downloadExcel()),
            Actions\Action::make('exportPdf')
                ->label(__('fields.export_pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->authorize(fn (): bool => auth()->user()?->can('children.export') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('children.export') ?? false)
                ->action(fn () => $this->downloadPdf()),
            $this->importAction(),
            Actions\CreateAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('children.create') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('children.create') ?? false),
        ];
    }

    protected function importModuleKey(): string
    {
        return 'children';
    }

    public function downloadExcel()
    {
        abort_unless(auth()->user()?->can('children.export') ?? false, 403);

        // Announce the export after the fact; it cannot affect the download.
        ExcelActionOccurred::dispatch('Child', ActionType::EXPORT, auth()->user());

        $query = $this->exportQuery();

        // Up to the threshold the XLSX download is exactly what it always
        // was. Above it the same columns and values go out as a CSV file that
        // is written and checked in full before it is sent: PhpSpreadsheet
        // holds a whole workbook in memory, and a large one cannot be built.
        if ((clone $query)->count() > CsvExport::THRESHOLD) {
            return CsvExport::start(new ChildrenExport($query), 'children.export', 'children.csv');
        }

        return Excel::download(new ChildrenExport($query), 'children.xlsx');
    }

    public function downloadPdf()
    {
        abort_unless(auth()->user()?->can('children.export') ?? false, 403);

        AuditEvents::pdfExport('Child');

        return PdfExport::start(
            new ChildrenExport($this->exportQuery()),
            'children.export',
            'children.pdf',
            __('fields.children'),
            'name',
        );
    }

    private function exportQuery()
    {
        $query = clone $this->getTableQueryForExport();

        return $query->select($query->getModel()->qualifyColumn('*'));
    }
}
