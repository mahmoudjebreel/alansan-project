<?php

namespace App\Support;

use App\Models\FollowUpChild;
use App\Support\Import\ImportDateParser;
use Carbon\Carbon;

/**
 * A child whose history ended in a death is never entered again.
 *
 * Whether the child died is read from the follow-up module and nowhere else:
 * the child's latest closed episode ended as died (FollowUpChild::
 * terminalEpisodeFor()). What is refused is anything that would enter the
 * child again AFTER that: a new episode by any route, and a new screening
 * dated after the death. The history up to and including the death stays
 * exactly as it is - it can be read, exported, and uploaded again - so a row
 * that describes that history is not a re-entry and is not refused.
 *
 *   A screening (Children)    refused when dated after the death, or undated.
 *   An uploaded episode       refused when admitted after the died episode was
 *   (Follow Up Child import)  admitted, or with no admission date. The died
 *                             episode itself, and every earlier one, is history.
 *   Any episode opened by a   always refused (ChildFollowUpTransfer).
 *   workflow
 *
 * The death is dated by the died episode's discharge date, or its admission
 * date when an old record carries none. A died episode with neither cannot
 * be placed in time, and everything is refused rather than guessed.
 */
final class TerminalChild
{
    /** Container key for the upload's one-pass copy of the terminal list. */
    private const MEMO = 'terminal-child.episodes';

    /**
     * The reason a new screening for this child on this date is refused, or
     * null when it may be recorded.
     */
    public static function refusesScreening(mixed $idNumber, mixed $dateOfReporting): ?string
    {
        $episode = FollowUpChild::terminalEpisodeFor($idNumber);

        if ($episode === null) {
            return null;
        }

        return static::screeningIsAfterDeath(
            static::day($dateOfReporting),
            $episode->discharge_date?->format('Y-m-d') ?? $episode->admission_date?->format('Y-m-d'),
        ) ? static::message() : null;
    }

    /**
     * Children upload: one row, checked against the terminal list read once
     * for the whole upload.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string>
     */
    public static function forImportedChildRow(array $attributes): array
    {
        $died = static::episodes()[(string) ($attributes['child_id'] ?? '')] ?? null;

        if ($died === null) {
            return [];
        }

        return static::screeningIsAfterDeath(
            static::day($attributes['date_of_reporting'] ?? null),
            $died['died_on'] ?? $died['admitted'],
        ) ? [static::message()] : [];
    }

    /**
     * Follow Up Child upload: the discharge rule the module already applies,
     * then the same check for an episode admitted after the death.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string>
     */
    public static function forImportedFollowUpRow(array $attributes): array
    {
        $messages = FollowUpDischargeRule::forImportedRow($attributes);

        $died = static::episodes()[(string) ($attributes['id_number'] ?? '')] ?? null;

        if ($died === null) {
            return $messages;
        }

        $admitted = static::day($attributes['admission_date'] ?? null);
        $diedEpisodeAdmitted = $died['admitted'] ?? $died['died_on'];

        if ($admitted === null || $diedEpisodeAdmitted === null || $admitted > $diedEpisodeAdmitted) {
            $messages[] = static::message();
        }

        return $messages;
    }

    /**
     * The reason a trashed episode may not be restored, or null when it may.
     *
     * An episode dated after the child's death - admitted after the died
     * episode was admitted, the same line an upload is held to - would put
     * the child back into the programme after the death, and is refused.
     * History up to and including the death, the death itself among it,
     * restores as it always did.
     *
     * The death is read from the child's other episodes, trash included, so
     * the episode being restored cannot count as the latest closure that
     * hides the death it follows.
     */
    public static function refusesRestore(FollowUpChild $episode): ?string
    {
        if (blank($episode->id_number)) {
            return null;
        }

        $latest = FollowUpChild::withTrashed()
            ->where('id_number', $episode->id_number)
            ->whereKeyNot($episode->getKey())
            ->latestClosedFirst()
            ->first();

        if ($latest?->discharge_outcome !== FollowUpChild::DIED_OUTCOME) {
            return null;
        }

        $admitted = static::day($episode->admission_date);
        $diedEpisodeAdmitted = static::day($latest->admission_date) ?? static::day($latest->discharge_date);

        return $admitted === null || $diedEpisodeAdmitted === null || $admitted > $diedEpisodeAdmitted
            ? __('ui.died_terminal.restore_refused')
            : null;
    }

    /**
     * The keys, among the trashed episodes the query selects, that may not be
     * restored - for the set-based restore, which runs without model events.
     * Only children with a died episode on file are looked at.
     *
     * @return list<int>
     */
    public static function unrestorableKeys(\Illuminate\Database\Eloquent\Builder $trashed): array
    {
        $died = FollowUpChild::withTrashed()
            ->where('discharge_outcome', FollowUpChild::DIED_OUTCOME)
            ->whereNotNull('id_number')
            ->distinct()
            ->pluck('id_number')
            ->all();

        if ($died === []) {
            return [];
        }

        return (clone $trashed)
            ->reorder()
            ->whereIn('id_number', $died)
            ->get()
            ->filter(static fn (FollowUpChild $episode): bool => static::refusesRestore($episode) !== null)
            ->map(static fn (FollowUpChild $episode): int => (int) $episode->getKey())
            ->values()
            ->all();
    }

    /**
     * Record, once, that a workflow refused to enter a child again because
     * of the death - who, which child, and where. Never per uploaded row:
     * an upload's refusals are in its own import summary.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function audit(string $workflow, mixed $idNumber, array $properties = []): void
    {
        try {
            activity('died_terminal')
                ->causedBy(auth()->user())
                ->event('refused')
                ->withProperties(array_merge([
                    'workflow' => $workflow,
                    'child_id' => (string) $idNumber,
                ], $properties))
                ->log('Refused: the child\'s latest Follow-Up episode ended with Died');
        } catch (\Throwable $e) {
            // An audit entry must never be the reason the refusal fails.
            report($e);
        }
    }

    /**
     * Audit a form save that the death refused: once per save attempt, when
     * the child ID field carries the refusal. Validation that runs while the
     * field is typed in is not a save, and is not audited.
     */
    public static function auditRefusedSave(\Illuminate\Validation\ValidationException $exception, string $workflow, mixed $idNumber, array $properties = []): void
    {
        $messages = $exception->errors()['data.child_id'] ?? [];

        if (in_array(static::message(), $messages, true)) {
            static::audit($workflow, $idNumber, $properties);
        }
    }

    /**
     * The one sentence every refusal carries.
     */
    public static function message(): string
    {
        return __('ui.died_terminal.message');
    }

    /**
     * Drop the upload's copy of the terminal list; the next read builds it
     * again. Called whenever an episode is saved, deleted or restored.
     */
    public static function forget(): void
    {
        if (app()->bound(self::MEMO)) {
            app()->forgetInstance(self::MEMO);
        }
    }

    /**
     * Every terminal child ID, read once and kept for the rest of the request
     * (an upload checks every row against it).
     *
     * @return array<string, array{admitted: ?string, died_on: ?string}>
     */
    private static function episodes(): array
    {
        if (! app()->bound(self::MEMO)) {
            app()->instance(self::MEMO, FollowUpChild::terminalEpisodes());
        }

        return app(self::MEMO);
    }

    private static function screeningIsAfterDeath(?string $screenedOn, ?string $diedOn): bool
    {
        return $screenedOn === null || $diedOn === null || $screenedOn > $diedOn;
    }

    /**
     * One date as a comparable "Y-m-d" day, or null when there is none.
     */
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
