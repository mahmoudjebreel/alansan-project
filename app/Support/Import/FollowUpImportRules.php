<?php

namespace App\Support\Import;

use App\Models\FollowUpChild;
use App\Support\TerminalChild;
use Carbon\Carbon;

/**
 * The episode rules a Follow Up Child upload is validated against, row by
 * row, before anything is written. Independent of ImportDuplicateGuard (C2),
 * which still decides afterwards which rows are already on file.
 *
 *   A death on file      an episode admitted after it is refused
 *                        (TerminalChild, with the module's discharge rule).
 *   A death in the file  the same, when the died row is in this upload:
 *                        a row admitted after it, or a died row when a row
 *                        admitted after it has already been read.
 *   An open episode      a child with an open episode on file cannot be
 *   on file              given a new episode by an upload. A row that is an
 *                        episode already on file (same child, same admission
 *                        date) is not new - it is the duplicate guard's to
 *                        skip - and is let through.
 *   An open episode      one child, one open episode: two open rows for the
 *   in the file          same child are refused, and so is a row admitted on
 *                        or after the admission of an open row of the same
 *                        child. Closed history before an open row is not.
 *
 * The upload is all-or-nothing, so a conflict is reported on whichever of
 * the two rows is read second, naming the reason; the whole file is refused
 * either way and the order of its rows does not matter.
 */
final class FollowUpImportRules
{
    /** Container key for one upload's state. */
    private const STATE = 'follow-up-import-rules.state';

    /**
     * Forget everything a previous upload in this request read or saw.
     * Called once at the start of every upload.
     */
    public static function beginUpload(): void
    {
        TerminalChild::forget();

        if (app()->bound(self::STATE)) {
            app()->forgetInstance(self::STATE);
        }
    }

    /**
     * The module's row validator.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string>
     */
    public static function forImportedRow(array $attributes): array
    {
        // The discharge rule and a death already on file.
        $messages = TerminalChild::forImportedFollowUpRow($attributes);

        $idNumber = (string) ($attributes['id_number'] ?? '');

        if ($idNumber === '') {
            return $messages;
        }

        $admitted = static::day($attributes['admission_date'] ?? null);
        $outcome = $attributes['discharge_outcome'] ?? null;
        $died = $outcome === FollowUpChild::DIED_OUTCOME;
        $open = ! in_array($outcome, FollowUpChild::CLOSING_OUTCOMES, true);

        $state = static::state();
        $seen = $state->file[$idNumber] ?? ['died' => null, 'latest' => null, 'open' => []];

        // A death in this file: nothing admitted after it.
        if ($seen['died'] !== null && ($admitted === null || $admitted > $seen['died'])) {
            $messages[] = __('ui.follow_up_import.died_in_file', ['id' => $idNumber]);
        } elseif ($died && $admitted !== null && $seen['latest'] !== null && $seen['latest'] > $admitted) {
            $messages[] = __('ui.follow_up_import.died_in_file', ['id' => $idNumber]);
        }

        // One open episode per child: on file...
        if (isset($state->open[$idNumber]) && ! static::isOnFile($idNumber, $admitted)) {
            $messages[] = __('ui.follow_up_import.open_on_file', ['id' => $idNumber]);
        }

        // ...and in this file.
        $afterAnOpenRow = $seen['open'] !== [] && ($admitted === null || $admitted >= min($seen['open']));
        $beforeALaterRow = $open && $seen['latest'] !== null && ($admitted === null || $seen['latest'] >= $admitted);

        if ($afterAnOpenRow || ($open && $seen['open'] !== []) || $beforeALaterRow) {
            $messages[] = __('ui.follow_up_import.open_in_file', ['id' => $idNumber]);
        }

        // Remember this row for the rows after it.
        if ($died && $admitted !== null) {
            $seen['died'] = $seen['died'] === null ? $admitted : min($seen['died'], $admitted);
        }

        if ($admitted !== null) {
            $seen['latest'] = $seen['latest'] === null ? $admitted : max($seen['latest'], $admitted);
        }

        if ($open && $admitted !== null) {
            $seen['open'][] = $admitted;
        }

        $state->file[$idNumber] = $seen;

        return $messages;
    }

    /**
     * Whether this row is an episode already on file for the child - the
     * same admission date - rather than a new one.
     */
    private static function isOnFile(string $idNumber, ?string $admitted): bool
    {
        return $admitted !== null && FollowUpChild::query()
            ->where('id_number', $idNumber)
            ->whereDate('admission_date', $admitted)
            ->exists();
    }

    /**
     * This upload's state: the child IDs with an open episode on file (read
     * once, when the first row is checked) and what the rows read so far
     * said about each child. An object, so the rows update it in place.
     */
    private static function state(): object
    {
        if (! app()->bound(self::STATE)) {
            $open = FollowUpChild::query()
                ->whereNotNull('id_number')
                ->where(function ($query): void {
                    $query->whereNull('discharge_outcome')
                        ->orWhereNotIn('discharge_outcome', FollowUpChild::CLOSING_OUTCOMES);
                })
                ->toBase()
                ->pluck('id_number')
                ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
                ->all();

            app()->instance(self::STATE, new class($open)
            {
                /** @var array<string, array{died: ?string, latest: ?string, open: list<string>}> */
                public array $file = [];

                /** @param  array<string, true>  $open */
                public function __construct(public array $open)
                {
                }
            });
        }

        return app(self::STATE);
    }

    private static function day(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (blank($value) || ! is_string($value) || ImportDateParser::statesNoDate($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
