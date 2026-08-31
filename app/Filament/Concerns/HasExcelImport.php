<?php

namespace App\Filament\Concerns;

use App\Events\ExcelActionOccurred;
use App\Imports\ImportDefinition;
use App\Support\Notifications\ActionNotifier;
use App\Support\Notifications\ActionType;
use App\Imports\ImportTemplateExport;
use App\Services\ExcelImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Adds the "Import Excel" header action (reminder + template download +
 * upload) to a module's list page.
 *
 * Every list page reuses this one implementation; a page only has to declare
 * which module it belongs to via importModuleKey().
 */
trait HasExcelImport
{
    /**
     * Registry key of the module this page imports into.
     */
    abstract protected function importModuleKey(): string;

    protected function importDefinition(): ImportDefinition
    {
        return ImportDefinition::get($this->importModuleKey());
    }

    /**
     * can() rather than hasPermissionTo(): it honours the Gate::before rule
     * that grants Super Admin everything without explicit assignments.
     */
    protected function canImport(): bool
    {
        return auth()->user()?->can($this->importDefinition()->permission) ?? false;
    }

    /**
     * The Import Excel header action. Add it to getHeaderActions().
     */
    protected function importAction(): Action
    {
        return Action::make('importExcel')
            ->label(__('fields.import_excel'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('warning')
            ->authorize(fn (): bool => $this->canImport())
            ->visible(fn (): bool => $this->canImport())
            ->modalHeading(__('fields.import_heading'))
            ->modalSubmitActionLabel(__('fields.import_submit'))
            ->modalWidth('lg')
            ->schema([
                Text::make(__('fields.import_reminder')),
                FileUpload::make('file')
                    ->label(__('fields.import_file'))
                    ->required()
                    ->storeFiles(true)
                    ->disk('local')
                    ->directory('imports')
                    ->visibility('private')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                        'text/csv',
                        'text/plain',
                    ]),
            ])
            ->extraModalFooterActions([
                Action::make('downloadTemplate')
                    ->label(__('fields.download_template'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn (): BinaryFileResponse => $this->downloadImportTemplate()),
            ])
            ->action(fn (array $data) => $this->runImport($data));
    }

    /**
     * Download the empty template for this module.
     */
    public function downloadImportTemplate(): BinaryFileResponse
    {
        $definition = $this->importDefinition();

        abort_unless(auth()->user()?->can($definition->permission), 403);

        return Excel::download(
            new ImportTemplateExport($definition),
            $definition->filename . '-template.xlsx',
        );
    }

    /**
     * Validate and import the uploaded file, then report the outcome.
     */
    protected function runImport(array $data): void
    {
        $definition = $this->importDefinition();

        // Server-side guard: the UI check alone is not enough.
        abort_unless(auth()->user()?->can($definition->permission), 403);

        $file = is_array($data['file'] ?? null) ? reset($data['file']) : ($data['file'] ?? null);

        if (blank($file)) {
            Notification::make()
                ->title(__('fields.import_empty_file'))
                ->danger()
                ->send();

            return;
        }

        $path = Storage::disk('local')->path($file);

        try {
            // A 240-row upload should tell the Super Admins once, not 240
            // times, so the per-row notifications are held back and one
            // summary is announced below instead. Suppression is scoped to
            // this call and does not change what the import writes.
            $result = ActionNotifier::withoutRecordNotifications(
                fn (): array => app(ExcelImportService::class)->import($definition, $path),
            );
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('fields.import_failed_heading'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        } finally {
            Storage::disk('local')->delete($file);
        }

        if ($result['errors'] !== []) {
            $this->notifyFailure($result['errors']);

            return;
        }

        ExcelActionOccurred::dispatch(
            $definition->moduleKeyForNotifications(),
            ActionType::IMPORT,
            auth()->user(),
            $result['imported'],
        );

        Notification::make()
            ->title(__('fields.import_success', ['count' => $result['imported']]))
            ->success()
            ->send();
    }

    /**
     * Report every failing row and its reason. Nothing was saved.
     */
    protected function notifyFailure(array $errors): void
    {
        $shown = array_slice($errors, 0, 15);
        $body = __('fields.import_failed_body', ['count' => count($errors)])
            . '<br><br>'
            . collect($shown)->map(fn (string $e): string => e($e))->implode('<br>');

        if (count($errors) > count($shown)) {
            $body .= '<br>…';
        }

        Notification::make()
            ->title(__('fields.import_failed_heading'))
            ->body(new \Illuminate\Support\HtmlString($body))
            ->danger()
            ->persistent()
            ->send();
    }
}
