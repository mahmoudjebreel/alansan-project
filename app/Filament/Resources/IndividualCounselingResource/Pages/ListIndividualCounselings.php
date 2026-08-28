<?php

namespace App\Filament\Resources\IndividualCounselingResource\Pages;

use App\Events\ExcelActionOccurred;
use App\Support\Notifications\ActionType;
use App\Exports\IndividualCounselingExport;
use App\Exports\PdfExport;
use App\Filament\Resources\IndividualCounselingResource;
use App\Filament\Concerns\HasExcelImport;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListIndividualCounselings extends ListRecords
{
    use HasExcelImport;

    protected static string $resource = IndividualCounselingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportExcel')->label(__('fields.export_excel'))->icon('heroicon-o-arrow-down-tray')->authorize(fn (): bool => auth()->user()?->can('individual_counseling.export') ?? false)->visible(fn (): bool => auth()->user()?->can('individual_counseling.export') ?? false)->action(fn () => $this->downloadExcel()),
            Actions\Action::make('exportPdf')->label(__('fields.export_pdf'))->icon('heroicon-o-document-arrow-down')->authorize(fn (): bool => auth()->user()?->can('individual_counseling.export') ?? false)->visible(fn (): bool => auth()->user()?->can('individual_counseling.export') ?? false)->action(fn () => $this->downloadPdf()),
            $this->importAction(),
            Actions\CreateAction::make()
                ->authorize(fn (): bool => auth()->user()?->can('individual_counseling.create') ?? false)
                ->visible(fn (): bool => auth()->user()?->can('individual_counseling.create') ?? false),
        ];
    }

    protected function importModuleKey(): string
    {
        return 'individual_counseling';
    }

    public function downloadExcel()
    {
        abort_unless(auth()->user()?->can('individual_counseling.export') ?? false, 403);
        // Announce the export after the fact; it cannot affect the download.
        ExcelActionOccurred::dispatch('IndividualCounseling', ActionType::EXPORT, auth()->user());

        return Excel::download(new IndividualCounselingExport($this->exportQuery()), 'individual-counselings.xlsx');
    }

    public function downloadPdf()
    {
        abort_unless(auth()->user()?->can('individual_counseling.export') ?? false, 403);
        return PdfExport::download(new IndividualCounselingExport($this->exportQuery()), 'exports.individual-counselings-pdf', 'individual-counselings.pdf', __('fields.individual_counselings'));
    }

    private function exportQuery()
    {
        $query = clone $this->getTableQueryForExport();
        return $query->select($query->getModel()->qualifyColumn('*'));
    }
}
