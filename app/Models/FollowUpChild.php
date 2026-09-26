<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\MuacClassifier;
use App\Traits\NotifiesSuperAdminOnChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class FollowUpChild extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, NotifiesSuperAdminOnChange;

    protected $table = 'follow_up_children';

    public const MAX_VISITS = 16;

    /**
     * The outcome a record carries while the child is still being followed up.
     * Every other outcome is an exit from the programme.
     */
    public const ACTIVE_OUTCOME = 'under_follow_up';

    /**
     * The outcome that hands the child back to the Children module.
     */
    public const CURED_OUTCOME = 'cured';

    /**
     * The outcome a person records when the child has missed two consecutive
     * visits - the programme's defaulter rule.
     */
    public const DEFAULTED_OUTCOME = 'defaulted';

    /**
     * Outcomes that close a record. A closed record is read-only: the child
     * has left the programme and the history of that episode must not move.
     *
     * 'defaulted' closes the record like the others: a defaulter has left
     * the programme, the missed visits stay recorded visit by visit, and a
     * child who comes back is readmitted into a NEW episode that follows
     * this one - never into this one.
     *
     * @var array<string>
     */
    public const CLOSING_OUTCOMES = [
        self::CURED_OUTCOME,
        self::DEFAULTED_OUTCOME,
        'discharge_to_opt',
        'discharge_to_other',
        'non_responded',
        'referred_medical_inpt',
        'died',
    ];

    /**
     * The only closed outcomes after which the same child may be readmitted
     * through the readmission button: the exits after which the child is
     * expected back.
     *
     * Closed is not the test. Cured, non-responded and died all close a
     * record just the same and never offer that button. A cured child who
     * deteriorates again is a readmission after relapse that follows the
     * cured episode, and is raised from a Children screening or the
     * Referral Centre, never from the readmission button.
     *
     * @var array<string>
     */
    public const READMISSION_OUTCOMES = [
        self::DEFAULTED_OUTCOME,
        'discharge_to_opt',
        'discharge_to_other',
        'referred_medical_inpt',
    ];

    /**
     * The outcomes that make a readmission a "Readmission after Other": the
     * eligible discharges that are neither a default nor a cure.
     *
     * @var array<string>
     */
    public const OTHER_READMISSION_OUTCOMES = [
        'discharge_to_opt',
        'discharge_to_other',
        'referred_medical_inpt',
    ];

    /**
     * The outcome that ends a child's history for good. A child whose latest
     * closed episode ended as died is never registered, admitted, readmitted
     * or referred again; the episode itself stays on file as it is.
     */
    public const DIED_OUTCOME = 'died';

    /**
     * Closed outcomes after which a returning child is simply a new
     * admission, not a readmission of any kind. Named rather than left to
     * fall through, so a change of programme policy is a change to this list.
     *
     * @var array<string>
     */
    public const NEW_AFTER_OUTCOMES = [
        'non_responded',
    ];

    /**
     * How an episode that follows a closed one is classified, decided from
     * the episode it follows and never picked by hand. Each of the three can
     * happen as often as the child comes back.
     *
     *   defaulted                  the previous episode closed as defaulted
     *   other                      the previous episode closed by an eligible
     *                              other exit
     *   readmission_after_relapse  the previous episode was a SAM or MAM
     *                              episode closed as cured, and the child is
     *                              back at SAM or MAM (either one)
     *
     * One definition decides it for every screen, export, filter and report:
     * readmissionClassificationSql(). The stored admission_type never does.
     */
    public const READMISSION_AFTER_DEFAULTED = 'defaulted';

    public const READMISSION_AFTER_OTHER = 'other';

    public const READMISSION_AFTER_RELAPSE = 'readmission_after_relapse';

    /**
     * Every classification an episode can carry; all three are readmissions.
     *
     * @var array<string>
     */
    public const READMISSION_CLASSIFICATIONS = [
        self::READMISSION_AFTER_DEFAULTED,
        self::READMISSION_AFTER_OTHER,
        self::READMISSION_AFTER_RELAPSE,
    ];

    /**
     * The classifications that make an episode a readmission (its admission
     * type as shown and exported).
     *
     * @var array<string>
     */
    public const READMISSION_KINDS = self::READMISSION_CLASSIFICATIONS;

    /**
     * The rules, as data - the one place they are written down. What the
     * outcome of the episode a return follows makes that return. The PHP
     * reading (classifyReturn()) and the SQL reading
     * (readmissionClassificationSql()) are both generated from this table,
     * so the two cannot say different things.
     *
     * @var array<string, array<string>>  classification => previous outcomes
     */
    public const RETURN_RULES = [
        self::READMISSION_AFTER_DEFAULTED => [self::DEFAULTED_OUTCOME],
        self::READMISSION_AFTER_OTHER => self::OTHER_READMISSION_OUTCOMES,
        self::READMISSION_AFTER_RELAPSE => [self::CURED_OUTCOME],
    ];

    /**
     * The rules above that hold only between two SAM/MAM episodes: the
     * episode followed was admitted at SAM or MAM, and so is the return
     * (either programme on either side).
     *
     * @var array<string>
     */
    public const MALNOURISHED_RETURN_RULES = [
        self::READMISSION_AFTER_RELAPSE,
    ];

    /**
     * The CMAM report admission columns. Every episode is counted in exactly
     * one of them.
     */
    public const CATEGORY_NEW = 'new';

    public const CATEGORY_RELAPSE = 'relapse';

    public const CATEGORY_READMISSION = 'readmission';

    /**
     * Which CMAM admission column each classification is counted in; an
     * episode with no classification is New.
     *
     * A readmission after relapse keeps that name everywhere in the module,
     * and is counted in the template's Relapse admission column - not in
     * Readmission, which is for returns after a default or an other exit.
     *
     * @var array<string, string>
     */
    public const CATEGORY_BY_CLASSIFICATION = [
        self::READMISSION_AFTER_DEFAULTED => self::CATEGORY_READMISSION,
        self::READMISSION_AFTER_OTHER => self::CATEGORY_READMISSION,
        self::READMISSION_AFTER_RELAPSE => self::CATEGORY_RELAPSE,
    ];

    /**
     * A first admission. Also what a NULL admission_type means: every record
     * written before the column existed was one.
     */
    public const ADMISSION_NEW = 'new';

    /**
     * A new episode opened for a child whose previous episode had closed. The
     * previous episode is never reopened or touched; this row follows it.
     */
    public const ADMISSION_READMISSION = 'readmission';

    /** @var array<string> */
    public const ADMISSION_TYPES = [
        self::ADMISSION_NEW,
        self::ADMISSION_READMISSION,
    ];

    protected $fillable = [
        'id_number', 'child_name', 'sex', 'dob', 'age', 'mobile_number',
        'shelter_name', 'governorate', 'causes_of_admission', 'admitted_with',
        'admission_type', 'admission_date', 'discharge_date', 'discharge_outcome',
        'notes', 'source_child_visit_id', 'previous_follow_up_child_id',
    ];

    protected $casts = [
        'dob' => 'date',
        'admission_date' => 'date',
        'discharge_date' => 'date',
    ];

    /**
     * Whether this episode is closed and the record may no longer be edited.
     */
    public function isLocked(): bool
    {
        return in_array($this->discharge_outcome, self::CLOSING_OUTCOMES, true);
    }

    /**
     * Whether this episode was opened as a readmission of a child whose
     * previous episode had closed.
     */
    public function isReadmission(): bool
    {
        return $this->admission_type === self::ADMISSION_READMISSION;
    }

    /**
     * The admission type as stored, with the pre-column NULL read as 'new'.
     */
    public function admissionType(): string
    {
        return $this->admission_type ?: self::ADMISSION_NEW;
    }

    /**
     * Whether this episode ended with one of the outcomes that allow the
     * child to be readmitted. Decided by the discharge outcome itself and
     * never inferred from the record merely being closed.
     */
    public function isReadmissionEligible(): bool
    {
        return $this->isLocked()
            && in_array($this->discharge_outcome, self::READMISSION_OUTCOMES, true);
    }

    /**
     * Whether a readmission may be opened from this record: its outcome is
     * one that allows it, no other episode is currently open for the same
     * child ID, and the child's history has not ended in a death. An open
     * one is where the child is being treated, and a second would count one
     * episode as two.
     */
    public function canBeReadmitted(): bool
    {
        return $this->isReadmissionEligible()
            && filled($this->id_number)
            && ! static::hasOpenEpisodeFor($this->id_number)
            && ! static::isTerminal($this->id_number);
    }

    /**
     * The closed episode a readmission for this child ID would follow on
     * from, or null when there is none - or when the latest closed episode
     * ended with an outcome that does not allow one.
     *
     * The latest closed episode is the one that decides: a child whose last
     * episode ended as cured, non-responded or died is not readmitted,
     * whatever an earlier episode ended as.
     */
    public static function readmittableEpisodeFor(mixed $idNumber): ?self
    {
        $previous = static::latestClosedEpisodeFor($idNumber);

        return $previous?->canBeReadmitted() ? $previous : null;
    }

    /**
     * What a SAM/MAM return after this closed episode would be, looking at
     * this episode alone, or null when such a return is simply a new
     * admission.
     *
     *   1. closed as defaulted                        -> after defaulted
     *   2. closed by an eligible other exit           -> after other
     *   3. a SAM/MAM episode closed as cured          -> after relapse
     *   4. anything else - cured with no SAM/MAM classification, non-responded,
     *      died, or not closed at all                 -> null (a new admission)
     *
     * Every caller asks about a SAM/MAM return (a screening that admits, or a
     * readmission after a default or an other exit, which does not depend on
     * the reading), so the return is taken as SAM here.
     */
    public function classifiesReturnAs(): ?string
    {
        if (! $this->isLocked()) {
            return null;
        }

        return static::classifyReturn($this->discharge_outcome, $this->admitted_with, MuacClassifier::SAM);
    }

    /**
     * The rules (RETURN_RULES), in PHP: what a return admitted with
     * $returningWith is, after an episode that closed with $previousOutcome
     * and had been admitted with $previousAdmittedWith. Null is a new
     * admission - after a non-response, a death, a cure that was not SAM/MAM,
     * or an outcome that does not close an episode.
     */
    public static function classifyReturn(?string $previousOutcome, ?string $previousAdmittedWith, ?string $returningWith): ?string
    {
        foreach (self::RETURN_RULES as $classification => $outcomes) {
            if (! in_array($previousOutcome, $outcomes, true)) {
                continue;
            }

            if (in_array($classification, self::MALNOURISHED_RETURN_RULES, true)
                && ! (MuacClassifier::isMalnourished($previousAdmittedWith) && MuacClassifier::isMalnourished($returningWith))) {
                continue;
            }

            return $classification;
        }

        return null;
    }

    /**
     * This episode's own classification - after defaulted, after other,
     * after relapse - or null for a new admission.
     *
     * Read from readmissionClassificationSql(), the one definition every
     * screen, filter, export and report uses. A row loaded through
     * withAdmissionClassification() already carries the answer; any other
     * row asks the database for it.
     */
    public function readmissionClassification(): ?string
    {
        if (array_key_exists('derived_classification', $this->attributes)) {
            return $this->attributes['derived_classification'];
        }

        if (! $this->exists) {
            return null;
        }

        $row = static::withTrashed()
            ->whereKey($this->getKey())
            ->withAdmissionClassification()
            ->first();

        return $row?->attributes['derived_classification'] ?? null;
    }

    /**
     * The admission type this episode is shown and exported as: a readmission
     * after a default, an other exit or a relapse, and a new admission
     * otherwise.
     *
     * Derived, never read from the stored admission_type: an imported value
     * there must not override what the history says.
     */
    public function derivedAdmissionType(): string
    {
        return in_array($this->readmissionClassification(), self::READMISSION_KINDS, true)
            ? self::ADMISSION_READMISSION
            : self::ADMISSION_NEW;
    }

    /**
     * Which of the three CMAM admission columns an episode with the given
     * classification is counted in (CATEGORY_BY_CLASSIFICATION).
     */
    public static function admissionCategoryOf(?string $classification): string
    {
        return self::CATEGORY_BY_CLASSIFICATION[$classification] ?? self::CATEGORY_NEW;
    }

    /**
     * The closed episode that classifies a return for this child ID, or null
     * when a return is a new admission with nothing to follow on from: no
     * closed episode, a latest closed episode that classifies as nothing, or
     * an episode still open.
     *
     * The latest closed episode decides, exactly as for readmittableEpisodeFor():
     * this is the superset of it that also knows a relapse.
     */
    public static function classifyingEpisodeFor(mixed $idNumber): ?self
    {
        $previous = static::latestClosedEpisodeFor($idNumber);

        if ($previous === null || $previous->classifiesReturnAs() === null) {
            return null;
        }

        return static::hasOpenEpisodeFor($idNumber) ? null : $previous;
    }

    /**
     * The classification a SAM/MAM return for this child ID would carry right
     * now, or null when it would be a new admission - or when the child may
     * not return at all.
     *
     * The same answer readmissionClassificationSql() gives the episode once
     * it is opened and linked: the latest closed episode decides.
     */
    public static function readmissionClassificationFor(mixed $idNumber): ?string
    {
        if (static::isTerminal($idNumber)) {
            return null;
        }

        return static::classifyingEpisodeFor($idNumber)?->classifiesReturnAs();
    }

    /**
     * Whether an episode that has not been closed exists for this child ID.
     */
    public static function hasOpenEpisodeFor(mixed $idNumber): bool
    {
        // One definition of "open", shared with the referral layer.
        return \App\Support\ChildFollowUpTransfer::hasOpenEpisode($idNumber);
    }

    /**
     * The most recently closed episode for a child ID, or null when the child
     * has none on file. The closed history a readmission follows on from.
     */
    public static function latestClosedEpisodeFor(mixed $idNumber): ?self
    {
        if (blank($idNumber)) {
            return null;
        }

        return static::query()
            ->where('id_number', $idNumber)
            ->latestClosedFirst()
            ->first();
    }

    /**
     * Closed episodes only, the latest first: by discharge date, most recent
     * first with an undated closure last, then by id. The one ordering every
     * "latest closed episode" reading uses; latestClosedSql() is the same
     * ordering written as a condition.
     */
    public function scopeLatestClosedFirst(Builder $query): Builder
    {
        return $query
            ->whereIn($this->qualifyColumn('discharge_outcome'), self::CLOSING_OUTCOMES)
            ->orderByDesc($this->qualifyColumn('discharge_date'))
            ->orderByDesc($this->qualifyColumn('id'));
    }

    // -----------------------------------------------------------------
    // Died: the end of a child's history
    // -----------------------------------------------------------------

    /**
     * The episode that ended this child's history, or null when the child's
     * latest closed episode did not end as died.
     *
     * The latest closed episode decides, ordered exactly as
     * latestClosedEpisodeFor() orders it, but read from the trash as well: a
     * death recorded and then deleted must not quietly allow the child back.
     */
    public static function terminalEpisodeFor(mixed $idNumber): ?self
    {
        if (blank($idNumber)) {
            return null;
        }

        $latest = static::withTrashed()
            ->where('id_number', $idNumber)
            ->latestClosedFirst()
            ->first();

        return $latest?->discharge_outcome === self::DIED_OUTCOME ? $latest : null;
    }

    /**
     * Whether this child ID's latest closed episode ended as died.
     */
    public static function isTerminal(mixed $idNumber): bool
    {
        return static::terminalEpisodeFor($idNumber) !== null;
    }

    /**
     * Every child ID whose latest closed episode ended as died, with the
     * dates of that episode - the same decision as terminalEpisodeFor(), for
     * the whole table in one query, so an upload or a bulk referral of any
     * size is checked without a query per row.
     *
     * Only died episodes are read, each kept when no closed episode of the
     * same child (trash included) ranks before it in latestClosedFirst()
     * order - the condition latestClosedSql() writes out.
     *
     * @return array<string, array{admitted: ?string, died_on: ?string}>
     */
    public static function terminalEpisodes(): array
    {
        $table = (new static)->getTable();
        $alias = 'terminal';

        return DB::table("{$table} as {$alias}")
            ->whereNotNull("{$alias}.id_number")
            ->where("{$alias}.discharge_outcome", self::DIED_OUTCOME)
            ->whereRaw(static::latestClosedSql($alias))
            ->select(["{$alias}.id_number", "{$alias}.admission_date", "{$alias}.discharge_date"])
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                (string) $row->id_number => [
                    'admitted' => static::day($row->admission_date),
                    'died_on' => static::day($row->discharge_date),
                ],
            ])
            ->all();
    }

    /**
     * "The closed episode aliased $alias is the latest closed episode of its
     * child", trash included, as SQL: no other closed episode of the same
     * child ranks before it in latestClosedFirst() order (discharge date
     * descending with an undated closure last, then id descending).
     */
    public static function latestClosedSql(string $alias): string
    {
        $table = (new static)->getTable();
        $later = "{$alias}_later";
        $closing = static::quoted(self::CLOSING_OUTCOMES);

        return "NOT EXISTS (
            SELECT 1 FROM {$table} AS {$later}
            WHERE {$later}.id_number = {$alias}.id_number
              AND {$later}.id <> {$alias}.id
              AND {$later}.discharge_outcome IN ({$closing})
              AND (
                  ({$later}.discharge_date IS NOT NULL AND ({$alias}.discharge_date IS NULL OR {$later}.discharge_date > {$alias}.discharge_date))
                  OR (
                      ({$later}.discharge_date = {$alias}.discharge_date OR ({$later}.discharge_date IS NULL AND {$alias}.discharge_date IS NULL))
                      AND {$later}.id > {$alias}.id
                  )
              )
        )";
    }

    /**
     * "The child ID in $idColumn is terminal" as SQL - terminalEpisodeFor()
     * for a correlated query, such as a Children listing.
     */
    public static function terminalChildSql(string $idColumn): string
    {
        $table = (new static)->getTable();
        $alias = 'terminal_child';

        return "EXISTS (
            SELECT 1 FROM {$table} AS {$alias}
            WHERE {$alias}.id_number = {$idColumn}
              AND {$alias}.discharge_outcome = '" . self::DIED_OUTCOME . "'
              AND " . static::latestClosedSql($alias) . '
        )';
    }

    /**
     * A stored date, as the "Y-m-d" day both databases hand back differently.
     */
    private static function day(mixed $value): ?string
    {
        return blank($value) ? null : Carbon::parse($value)->format('Y-m-d');
    }

    // -----------------------------------------------------------------
    // The one definition of the classification, in SQL
    // -----------------------------------------------------------------

    /**
     * Add the episode's classification (derived_classification) and the
     * episode it follows (resolved_previous_episode_id) to the selected
     * columns, so a whole page or a whole export is classified in the same
     * query that reads it.
     */
    public function scopeWithAdmissionClassification(Builder $query): Builder
    {
        $table = $this->getTable();

        if ($query->getQuery()->columns === null) {
            $query->select($this->qualifyColumn('*'));
        }

        return $query
            ->selectRaw(static::readmissionClassificationSql($table) . ' as derived_classification')
            ->selectRaw(static::previousEpisodeIdSql($table) . ' as resolved_previous_episode_id');
    }

    /**
     * The closed episode an episode follows, as SQL over the row aliased
     * $alias.
     *
     * The link previous_follow_up_child_id when the episode has one - read
     * whatever has happened to that episode since, trash included, because
     * the history was settled when the episode was opened. An episode with
     * no link was written before the link existed, or opened with nothing to
     * follow; it follows the latest closed, live episode of the same child
     * admitted before it, which is the episode the transfer would have
     * linked at the time.
     */
    public static function previousEpisodeIdSql(string $alias): string
    {
        $table = (new static)->getTable();
        $latest = "{$alias}_latest";
        $closing = static::quoted(self::CLOSING_OUTCOMES);

        return "COALESCE({$alias}.previous_follow_up_child_id, (
            SELECT {$latest}.id FROM {$table} AS {$latest}
            WHERE {$latest}.id_number = {$alias}.id_number
              AND {$latest}.deleted_at IS NULL
              AND {$latest}.id <> {$alias}.id
              AND {$latest}.discharge_outcome IN ({$closing})
              AND (
                  {$latest}.admission_date < {$alias}.admission_date
                  OR ({$latest}.admission_date = {$alias}.admission_date AND {$latest}.id < {$alias}.id)
              )
            ORDER BY {$latest}.discharge_date DESC, {$latest}.id DESC
            LIMIT 1
        ))";
    }

    /**
     * The classification of the episode aliased $alias, as SQL: one of
     * READMISSION_CLASSIFICATIONS, or NULL for a new admission.
     *
     * Decided by the episode it follows (previousEpisodeIdSql()), by the same
     * RETURN_RULES classifyReturn() reads:
     *
     *   defaulted                          -> after defaulted
     *   an eligible other exit             -> after other
     *   a SAM/MAM episode closed as cured,
     *   with this episode at SAM/MAM       -> after relapse (SAM or MAM on
     *                                         either side)
     *   anything else - non-responded,
     *   died, a cure with no SAM/MAM
     *   classification, nothing at all     -> NULL (new)
     *
     * Only the episode followed decides, so every kind of readmission repeats
     * for as often as the child comes back.
     */
    public static function readmissionClassificationSql(string $alias): string
    {
        $table = (new static)->getTable();
        $previous = "{$alias}_prev";
        $malnourished = static::quoted([MuacClassifier::SAM, MuacClassifier::MAM]);

        $arms = '';

        foreach (self::RETURN_RULES as $classification => $outcomes) {
            $condition = "{$previous}.discharge_outcome IN (" . static::quoted($outcomes) . ')';

            if (in_array($classification, self::MALNOURISHED_RETURN_RULES, true)) {
                $condition .= " AND {$previous}.admitted_with IN ({$malnourished})"
                    . " AND {$alias}.admitted_with IN ({$malnourished})";
            }

            $arms .= " WHEN {$condition} THEN '{$classification}'";
        }

        return "(
            SELECT CASE{$arms} END
            FROM {$table} AS {$previous}
            WHERE {$previous}.id = " . static::previousEpisodeIdSql($alias) . '
        )';
    }

    /**
     * @param  array<string>  $values  constants of this class, never user input
     */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'{$value}'", $values));
    }

    /**
     * Every other episode on file for the same child, oldest first: the
     * child's follow-up history as seen from this record.
     */
    public function otherEpisodes(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()
            ->where('id_number', $this->id_number)
            ->whereKeyNot($this->getKey())
            ->orderBy('admission_date')
            ->orderBy('id');
    }

    /**
     * The closed episode this readmission follows on from, when one is
     * linked. Documentary, like source_child_visit_id: no constraint.
     */
    public function previousEpisode(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_follow_up_child_id');
    }

    /**
     * The closed episode this one follows as the classification reads it:
     * the linked one, or for an unlinked episode the one the history infers.
     * Resolves only on a row loaded through withAdmissionClassification(),
     * which is what selects resolved_previous_episode_id.
     *
     * @see static::previousEpisodeIdSql()
     */
    public function resolvedPreviousEpisode(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'resolved_previous_episode_id')->withTrashed();
    }

    /**
     * The most recent recorded visit, or null while none exists.
     */
    public function latestVisit(): ?FollowUpChildVisit
    {
        return $this->visits()->reorder()->orderByDesc('visit_number')->first();
    }

    /**
     * The most recent visit the child actually attended, or null while none
     * exists. A missed visit carries no reading, so it never answers for
     * "the latest measurement".
     */
    public function latestAttendedVisit(): ?FollowUpChildVisit
    {
        return $this->visits()
            ->reorder()
            ->where('status', FollowUpChildVisit::STATUS_ATTENDED)
            ->orderByDesc('visit_number')
            ->first();
    }

    /**
     * Whether the two most recent recorded visits were both missed - the
     * programme's defaulter rule. Reported, never acted on: closing the
     * episode as defaulted stays a person's decision.
     */
    public function meetsDefaulterRule(): bool
    {
        $lastTwo = $this->visits
            ->sortByDesc('visit_number')
            ->take(2);

        if ($lastTwo->count() < 2) {
            return false;
        }

        return $lastTwo->every(fn (FollowUpChildVisit $visit): bool => $visit->isMissed());
    }

    /**
     * Visits ordered by their sequential visit number (1-16).
     */
    public function visits(): HasMany
    {
        return $this->hasMany(FollowUpChildVisit::class)->orderBy('visit_number');
    }

    /**
     * Destroy the recorded visits only when the record itself is destroyed.
     *
     * Visits are not soft-deletable, so removing them on an ordinary delete
     * made a "reversible" delete permanent: the record came back from the
     * trash with every MUAC reading gone. A soft delete now leaves them in
     * place and only a force delete clears them.
     */
    protected static function booted(): void
    {
        static::deleting(function (FollowUpChild $child): void {
            if ($child->isForceDeleting()) {
                $child->visits()->delete();
            }
        });

        // Any change to an episode may change which children have died; the
        // import's one-pass copy of that list is dropped so the next read
        // sees it.
        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::{$event}(static fn () => \App\Support\TerminalChild::forget());
        }

        // An episode dated after the child's death is not brought back from
        // the trash (the set-based restore asks excludeUnrestorable()).
        static::restoring(static fn (FollowUpChild $episode): ?bool => \App\Support\TerminalChild::refusesRestore($episode) !== null ? false : null);
    }

    /**
     * Narrow a set-based restore to the episodes that may be restored.
     * BulkRecordWriter runs without model events, so the restoring guard
     * above is applied here instead.
     *
     * @see \App\Support\TerminalChild::refusesRestore()
     */
    public static function excludeUnrestorable(Builder $trashed): Builder
    {
        $refused = \App\Support\TerminalChild::unrestorableKeys($trashed);

        return $refused === [] ? $trashed : $trashed->whereKeyNot($refused);
    }

    /**
     * Rows that BulkRecordWriter must clear itself, because the set-based path
     * deliberately runs with model events switched off.
     *
     * Mirrors booted() above: visits go only on a force delete.
     *
     * @return array<string, string>  relation name => foreign key column
     */
    public function bulkCascades(bool $forceDeleting): array
    {
        return $forceDeleting ? ['visits' => 'follow_up_child_id'] : [];
    }

    /**
     * Formatted age at admission: how old the child was, per their date of
     * birth, on the day they were admitted (DATEDIF equivalent).
     *
     * Both the months and the days come from a single calendar diff of the two
     * dates. Carbon 3 returns a float from diffInYears(), so deriving the
     * months from it separately double-counted the whole months already
     * carried by the diff and produced values like "11.96 months" for a six
     * month old.
     */
    public static function formatAgeAtAdmission(mixed $dob, mixed $admissionDate): ?string
    {
        if (blank($dob) || blank($admissionDate)) {
            return null;
        }

        $dob = Carbon::parse($dob);
        $admissionDate = Carbon::parse($admissionDate);

        if ($admissionDate->lt($dob)) {
            return null;
        }

        $diff = $dob->diff($admissionDate);

        $totalMonths = ($diff->y * 12) + $diff->m;
        $days = $diff->d;

        $parts = [];
        if ($totalMonths > 0) {
            $parts[] = __('ui.age.months', ['count' => $totalMonths]);
        }
        if ($days > 0) {
            $parts[] = __('ui.age.days', ['count' => $days]);
        }

        return implode(__('ui.age.join'), $parts) ?: __('ui.age.zero');
    }

    /**
     * Formatted current age, from the same date of birth and the same
     * formatter as the age at admission — so the two always agree, and the
     * age at admission can never exceed the current age.
     */
    public static function formatCurrentAge(mixed $dob): ?string
    {
        return static::formatAgeAtAdmission($dob, Carbon::now());
    }

    /**
     * Auto-calculated age at admission (recalculated whenever DOB/admission date change).
     */
    protected function ageAtAdmission(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => static::formatAgeAtAdmission($this->dob, $this->admission_date),
        );
    }

    /**
     * Current age, always derived from DOB rather than from whatever text was
     * typed into the column.
     */
    protected function age(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?string => static::formatCurrentAge($this->dob) ?? $value,
        );
    }

    /**
     * MUAC of the most recent visit the child attended. A missed visit has no
     * reading by definition, so it is skipped rather than reported as a
     * measurement that is missing.
     */
    protected function latestMuac(): Attribute
    {
        return Attribute::make(
            get: fn (): mixed => $this->visits
                ->last(fn (FollowUpChildVisit $visit): bool => ! $visit->isMissed())
                ?->muac,
        );
    }

    /**
     * Configure activity logging.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Follow Up Child record {$eventName}");
    }
}
