<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One parked CSV export, waiting for the browser to come and collect it.
 *
 * The same arrangement as the PDF report, for the same reason: the export
 * button is a Filament action, and whatever an action returns is buffered by
 * Livewire, base64-encoded and posted back inside the XHR. The click resolves
 * exactly which records the user is looking at - filters, search, tab and
 * trash filter still on the component - and parks their primary keys here;
 * an ordinary GET route then writes and sends the file.
 *
 * The keys ARE the export. They are read in one query, in the listing's own
 * order, with no paging, so nothing can be skipped or repeated at a page
 * boundary; the count and a checksum of the list are parked beside it, and
 * the list is checked against both before a row is written.
 *
 * A six-figure key list is compressed before it is stored: the cache lives in
 * the database here, and a row that large could exceed the server's packet
 * limit.
 *
 * @see \App\Exports\PdfExportTicket  the same pattern for PDF
 * @see \App\Http\Controllers\CsvExportDownloadController
 */
class CsvExportTicket
{
    /** How long a ticket stays collectable, as for the PDF ticket. */
    public const LIFETIME = 900;

    /**
     * Park an export and return the token that collects it.
     *
     * @return string  the token, for the route
     */
    public static function issue(AbstractTableExport $export, string $ability, string $filename): string
    {
        $query = $export->query();
        $model = $query->getModel();

        // One record is one row, however many times a join repeated it.
        $keys = array_values(array_unique(
            $query->pluck($model->getQualifiedKeyName())->all(),
            SORT_REGULAR,
        ));

        $list = implode(',', $keys);
        $token = Str::random(48);

        Cache::put(self::key($token), [
            // The ticket belongs to the user who made it and to nobody else,
            // and the route checks the permission again before it writes.
            'user' => auth()->id(),
            'ability' => $ability,
            // The route is outside the panel and its locale middleware; the
            // headings are written in the language the export was asked in.
            'locale' => app()->getLocale(),
            'export' => $export::class,
            'model' => $model::class,
            'keys' => base64_encode(gzdeflate($list, 6)),
            'count' => count($keys),
            'checksum' => sha1($list),
            'filename' => $filename,
        ], self::LIFETIME);

        return $token;
    }

    /**
     * The parked export, or null when the token is unknown, expired, or was
     * issued for somebody else.
     *
     * @return array<string, mixed>|null
     */
    public static function claim(string $token): ?array
    {
        $ticket = Cache::get(self::key($token));

        if (! is_array($ticket) || $ticket['user'] !== auth()->id()) {
            return null;
        }

        return $ticket;
    }

    /**
     * The parked primary keys, in the order they were parked, checked
     * against the count and checksum parked with them.
     *
     * @param  array<string, mixed>  $ticket
     * @return list<string>
     *
     * @throws IncompleteCsvExportException  when the list did not survive intact
     */
    public static function keys(array $ticket): array
    {
        $list = gzinflate((string) base64_decode((string) $ticket['keys'], true));

        if ($list === false || sha1($list) !== $ticket['checksum']) {
            throw IncompleteCsvExportException::corruptTicket();
        }

        $keys = $list === '' ? [] : explode(',', $list);

        if (count($keys) !== $ticket['count']) {
            throw IncompleteCsvExportException::corruptTicket();
        }

        return $keys;
    }

    /**
     * Rebuild the module's export over its whole table, trash included: the
     * filtering already happened when the keys were resolved - including the
     * trash filter - and the key list is what selects the rows now.
     *
     * @param  array<string, mixed>  $ticket
     */
    public static function export(array $ticket): AbstractTableExport
    {
        $model = $ticket['model'];
        $export = $ticket['export'];

        if (! is_subclass_of($model, Model::class) || ! is_subclass_of($export, AbstractTableExport::class)) {
            throw new \RuntimeException('The parked CSV export does not name a module this application has.');
        }

        /** @var Builder $query */
        $query = in_array(SoftDeletes::class, class_uses_recursive($model), true)
            ? $model::withTrashed()
            : $model::query();

        return new $export($query);
    }

    private static function key(string $token): string
    {
        return 'csv-export-ticket:' . $token;
    }
}
