<?php

namespace App\Http\Controllers;

use App\Exports\PdfExport;
use App\Exports\PdfExportTicket;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Collects a PDF report that a module's button parked.
 *
 * The whole reason this route exists is that the report cannot be returned
 * from the Filament action that asks for it. An action's return value goes
 * back through Livewire, which buffers the entire response, base64-encodes it
 * and posts it inside the JSON of the XHR - so nothing reaches the browser
 * until the last of the several minutes a large report takes, and what does
 * reach it is a string a third larger than the file. The user saw a spinner
 * and never got a file.
 *
 * An ordinary GET has none of that in the way: the headers go out before the
 * first record is laid out, so the browser has a real, named download from the
 * start and fills it in as the parts are written.
 *
 * A controller rather than a closure so the route table can be cached; see
 * routes/web.php.
 *
 * @see \App\Exports\PdfExportTicket
 * @see \App\Exports\PdfExport::download()
 */
class PdfExportDownloadController extends Controller
{
    public function __invoke(string $ticket): StreamedResponse
    {
        $parked = PdfExportTicket::claim($ticket);

        // An unknown, expired or someone else's token is simply not a report.
        abort_if($parked === null, 404);

        // The permission is checked again here and not merely trusted from the
        // click: the ticket says which report was asked for, not that whoever
        // is asking now may still read it.
        abort_unless(auth()->user()?->can($parked['ability']) ?? false, 403);

        // The report prints in the language it was asked for in. This route
        // sits outside the panel, so it does not carry the panel's locale
        // middleware and would otherwise label an Arabic operator's report in
        // English.
        App::setLocale($parked['locale']);

        // A large report is minutes of layout, and this route does nothing
        // else for its whole life. The limit that applies to a form post is
        // not the one that should end a download in progress.
        set_time_limit(0);

        return PdfExport::download(
            PdfExportTicket::export($parked),
            $parked['filename'],
            $parked['title'],
            $parked['nameField'],
            PdfExportTicket::section($parked),
            null,
            $parked['keys'],
        );
    }
}
