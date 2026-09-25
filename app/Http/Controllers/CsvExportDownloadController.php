<?php

namespace App\Http\Controllers;

use App\Exports\CsvExport;
use App\Exports\CsvExportTicket;
use App\Exports\IncompleteCsvExportException;
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
            $path = CsvExport::build(CsvExportTicket::export($parked), CsvExportTicket::keys($parked));
        } catch (IncompleteCsvExportException $e) {
            report($e);

            Notification::make()
                ->title(__('ui.csv_export.failed_title'))
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return redirect()->to(url()->previous(route('home')));
        }

        return response()
            ->download($path, $parked['filename'], ['Content-Type' => 'text/csv; charset=UTF-8'])
            ->deleteFileAfterSend();
    }
}
