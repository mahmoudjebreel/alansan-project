<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One parked PDF report, waiting for the browser to come and collect it.
 *
 * A module's PDF button cannot hand the file back itself. It is a Filament
 * action, so whatever it returns goes through Livewire, and Livewire answers
 * an action by buffering the entire response, base64-encoding it and posting
 * it back inside the JSON of the XHR. A report of two thousand children is
 * minutes of layout followed by a several-megabyte string in memory, and until
 * every last byte of it exists the browser has been shown nothing at all.
 *
 * So the click does the one cheap thing only it can do - resolve which records
 * the user is actually looking at, with their filters, their search and their
 * active tab still on the component - and parks that list here under a random
 * token. The browser is then sent to an ordinary GET route, which is free to
 * stream for as long as it needs to.
 *
 * What is parked is a list of primary keys, not a query: a query is closures
 * and builder state and does not survive a round trip through the cache, and a
 * key list is also the thing that makes the report reproducible. It is read in
 * blocks and printed in exactly the order it was stored, so the report cannot
 * repeat a record or drop one between parts.
 *
 * @see \App\Exports\PdfExport::start()
 * @see \App\Http\Controllers\PdfExportDownloadController
 */
class PdfExportTicket
{
    /**
     * How long a ticket stays collectable.
     *
     * Long enough to survive a slow browser, a re-click or a user who lets the
     * download sit for a minute before allowing it; short enough that a list
     * of keys is not a record of what somebody looked at an hour ago.
     */
    public const LIFETIME = 900;

    /**
     * Park a report and return the token that collects it.
     *
     * The keys are read in the query's own order, so the report prints in the
     * order the listing had on screen.
     *
     * @param  class-string|null  $section  a class with a static section()
     * @return string  the token, for the route
     */
    public static function issue(
        AbstractTableExport $export,
        string $ability,
        string $filename,
        string $title,
        string $nameField,
        ?string $section = null,
    ): string {
        $query = $export->query();
        $model = $query->getModel();

        $token = Str::random(48);

        Cache::put(self::key($token), [
            // The ticket belongs to the user who made it and to nobody else,
            // and the route checks the permission again before it prints: a
            // token is a pointer to a report, never a grant to read one.
            'user' => auth()->id(),
            'ability' => $ability,
            // The language the report was asked for in. The report's title is
            // translated here, at the click, but its field labels are
            // translated later while it is being written - and the route that
            // writes it is outside the panel, so it does not carry the panel's
            // locale middleware. Without this the headings of an Arabic
            // operator's report would come out in English under an Arabic
            // title.
            //
            // @see \App\Http\Middleware\SetLocale  panel middleware only
            'locale' => app()->getLocale(),
            'export' => $export::class,
            'model' => $model::class,
            'keys' => $query->pluck($model->getQualifiedKeyName())->all(),
            'filename' => $filename,
            'title' => $title,
            'nameField' => $nameField,
            'section' => $section,
        ], self::LIFETIME);

        return $token;
    }

    /**
     * The parked report, or null when the token is unknown, expired, or was
     * issued for somebody else.
     *
     * @return array<string, mixed>|null
     */
    public static function claim(string $token): ?array
    {
        $ticket = Cache::get(self::key($token));

        if (! is_array($ticket)) {
            return null;
        }

        if ($ticket['user'] !== auth()->id()) {
            return null;
        }

        return $ticket;
    }

    /**
     * Rebuild the module's export over the parked records.
     *
     * The query is the model's own, unfiltered: the filtering already happened
     * when the keys were resolved, and the key list is what selects the rows
     * now. The export class adds back whatever relations its report prints.
     *
     * @param  array<string, mixed>  $ticket
     */
    public static function export(array $ticket): AbstractTableExport
    {
        $model = $ticket['model'];
        $export = $ticket['export'];

        if (! is_subclass_of($model, Model::class) || ! is_subclass_of($export, AbstractTableExport::class)) {
            throw new RuntimeException('The parked PDF report does not name a module this application has.');
        }

        return new $export($model::query());
    }

    /**
     * The module's repeated visits or sessions, for the two modules that keep
     * them, rebuilt from the class the ticket named. The array itself holds a
     * closure, which is why the ticket carries the name instead.
     *
     * @param  array<string, mixed>  $ticket
     * @return array{title: string, columns: array<int, string>, empty: string, rows: callable}|null
     */
    public static function section(array $ticket): ?array
    {
        $section = $ticket['section'] ?? null;

        if ($section === null) {
            return null;
        }

        if (! is_string($section) || ! method_exists($section, 'section')) {
            throw new RuntimeException('The parked PDF report names a section this application has no builder for.');
        }

        return $section::section();
    }

    private static function key(string $token): string
    {
        return 'pdf-export-ticket:' . $token;
    }
}
