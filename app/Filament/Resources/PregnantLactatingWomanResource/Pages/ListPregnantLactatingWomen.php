<?php

namespace App\Filament\Resources\PregnantLactatingWomanResource\Pages;

use App\Events\ExcelActionOccurred;
use App\Support\Notifications\ActionType;
use App\Exports\PdfExport;
use App\Exports\PregnantWomenExport;
use App\Filament\Resources\PregnantLactatingWomanResource;
use App\Filament\Concerns\HasExcelImport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListPregnantLactatingWomen extends ListRecords
{
    use HasExcelImport;

    protected static string $resource = PregnantLactatingWomanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')
                ->label(__('fields.export_excel'))
                ->icon('heroicon-o-arrow-down-tray')
                ->authorize(fn (): bool => auth()->user()?->can('pregnant.export') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('pregnant.export') ?? false)
                ->action(fn () => $this->downloadExcel()),
            Actions\Action::make('exportPdf')
                ->label(__('fields.export_pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->authorize(fn (): bool => auth()->user()?->can('pregnant.export') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('pregnant.export') ?? false)
                ->action(fn () => $this->downloadPdf()),
            $this->importAction(),
            Actions\CreateAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('pregnant.create') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('pregnant.create') ?? false),
        ];
    }

    protected function importModuleKey(): string
    {
        return 'pregnant';
    }

    public function downloadExcel()
    {
        abort_unless(auth()->user()?->can('pregnant.export') ?? false, 403);

        // Announce the export after the fact; it cannot affect the download.
        ExcelActionOccurred::dispatch('PregnantLactatingWoman', ActionType::EXPORT, auth()->user());

        return Excel::download(new PregnantWomenExport($this->exportQuery()), 'pregnant-lactating-women.xlsx');
    }

    public function downloadPdf()
    {
        abort_unless(auth()->user()?->can('pregnant.export') ?? false, 403);

        return PdfExport::download(
            new PregnantWomenExport($this->exportQuery()),
            'pregnant-lactating-women.pdf',
            __('fields.pregnant_lactating_women'),
            'full_name_ar',
        );
    }

    private function exportQuery()
    {
        $query = clone $this->getTableQueryForExport();

        return $query->select($query->getModel()->qualifyColumn('*'));
    }
}
