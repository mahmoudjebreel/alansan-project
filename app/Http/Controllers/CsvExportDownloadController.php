<?php

namespace App\Http\Controllers;

use App\Events\ExcelActionOccurred;
use App\Exports\CsvExport;
use App\Exports\CsvExportTicket;
use App\Exports\IncompleteCsvExportException;
use App\Support\Activity\AuditEvents;
use App\Support\Notifications\ActionType;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Collects a CSV export that a module's button parked.
 *
 * The file is written and checked in full before anything is sent (see
 * CsvExport::build()). An export that cannot be completed is not sent at
 * all: the user is taken back to where they came from and told so.
 *
 * A controller rather than a closure so the route table can be cached; see
 * routes/web.php.
 *
 * @see \App\Exports\CsvExportTicket
 */
class CsvExportDownloadController extends Controller
{
    public function __invoke(string $ticket): BinaryFileResponse|RedirectResponse
    {
        $parked = CsvExportTicket::claim($ticket);

        // An unknown, expired or someone else's token is simply not an export.
        abort_if($parked === null, 404);

        // Checked again here and not merely trusted from the click: the
        // ticket says which export was asked for, not that whoever is asking
        // now may still read it.
        abort_unless(auth()->user()?->can($parked['ability']) ?? false, 403);

        App::setLocale($parked['locale']);

        // A large export is a long write, and this route does nothing else.
        set_time_limit(0);

        try {
            $keys = CsvExportTicket::keys($parked);
            $path = CsvExport::build(CsvExportTicket::export($parked), $keys);
        } catch (IncompleteCsvExportException $e) {
            report($e);

            // One audit entry for the failed export, with its reason.
            AuditEvents::exportFailed($parked['module'], 'csv', $e->getMessage());

            Notification::make()
                ->title(__('ui.csv_export.failed_title'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            // Back to the listing the export was asked from - a fixed address
            // inside the application, never whatever the Referer header says.
            return redirect()->to($parked['return']);
        }

        // Sent once: the ticket is done with.
        CsvExportTicket::forget($ticket);

        // One audit entry for the whole export, with how many rows it holds -
        // announced now that the file is complete, not when it was asked for.
        ExcelActionOccurred::dispatch($parked['module'], ActionType::EXPORT, auth()->user(), count($keys));

        return response()
            ->download($path, $parked['filename'], ['Content-Type' => 'text/csv; charset=UTF-8'])
            ->deleteFileAfterSend();
    }
}
