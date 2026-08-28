<?php

namespace App\Filament\Resources\ChildResource\Pages;

use App\Events\ExcelActionOccurred;
use App\Support\Notifications\ActionType;
use App\Exports\ChildrenExport;
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

        return Excel::download(new ChildrenExport($this->exportQuery()), 'children.xlsx');
    }

    public function downloadPdf()
    {
        abort_unless(auth()->user()?->can('children.export') ?? false, 403);

        return PdfExport::download(
            new ChildrenExport($this->exportQuery()),
            'exports.children-pdf',
            'children.pdf',
            __('fields.children'),
        );
    }

    private function exportQuery()
    {
        $query = clone $this->getTableQueryForExport();

        return $query->select($query->getModel()->qualifyColumn('*'));
    }
}
