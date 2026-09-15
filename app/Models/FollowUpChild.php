<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\MuacClassifier;
use App\Traits\NotifiesSuperAdminOnChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
     * into a new episode: the exits after which the child is expected back.
     *
     * Closed is not the test. Cured, non-responded and died all close a
     * record just the same and never allow a readmission. A cured child who
     * deteriorates again is a relapse - a new admission that follows the
     * cured episode - and is raised from a Children screening, never from
     * the readmission button.
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
     * How an episode that follows a closed one is classified, decided from
     * the closed episode's own history and never picked by hand.
     *
     * The classification is a property of the closed episode: it says what
     * a return after it is. It is stored on the new episode only as the
     * link previous_follow_up_child_id, which is what the reports read.
     *
     *   defaulted  the previous episode closed as defaulted
     *   other      the previous episode closed by an eligible other exit
     *   relapse    the previous episode was a SAM/MAM episode closed as cured
     */
    public const READMISSION_AFTER_DEFAULTED = 'defaulted';

    public const READMISSION_AFTER_OTHER = 'other';

    public const READMISSION_AFTER_RELAPSE = 'relapse';

    /** @var array<string> */
    public const READMISSION_CLASSIFICATIONS = [
        self::READMISSION_AFTER_DEFAULTED,
        self::READMISSION_AFTER_OTHER,
        self::READMISSION_AFTER_RELAPSE,
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
     * one that allows it, and no other episode is currently open for the
     * same child ID. An open one is where the child is being treated, and a
     * second would count one episode as two.
     */
    public function canBeReadmitted(): bool
    {
        return $this->isReadmissionEligible()
            && filled($this->id_number)
            && ! static::hasOpenEpisodeFor($this->id_number);
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
     * What an episode opened after this closed one is classified as, or null
     * when a return after this episode is simply a new admission.
     *
     * Decided by this episode's own outcome and admission, in this order:
     *
     *   1. closed as defaulted                        -> after defaulted
     *   2. closed by an eligible other exit           -> after other
     *   3. a SAM/MAM episode closed as cured          -> after relapse
     *   4. anything else - cured with no SAM/MAM classification, non-responded,
     *      died, or not closed at all                 -> null (a new admission)
     *
     * A cure is sufficient evidence for a relapse on its own: a Children
     * row written back from the cure is not required, because an episode
     * closed as cured by the import never has one until somebody refers it.
     */
    public function classifiesReturnAs(): ?string
    {
        if (! $this->isLocked()) {
            return null;
        }

        if ($this->discharge_outcome === self::DEFAULTED_OUTCOME) {
            return self::READMISSION_AFTER_DEFAULTED;
        }

        if (in_array($this->discharge_outcome, self::OTHER_READMISSION_OUTCOMES, true)) {
            return self::READMISSION_AFTER_OTHER;
        }

        if ($this->discharge_outcome === self::CURED_OUTCOME
            && MuacClassifier::isMalnourished($this->admitted_with)) {
            return self::READMISSION_AFTER_RELAPSE;
        }

        return null;
    }

    /**
     * This episode's own readmission classification - after defaulted, after
     * other, after relapse - read from the closed episode it is linked to,
     * or null for a first admission and for every row written before the
     * link existed.
     *
     * The linked episode is read even from the trash: the classification was
     * settled when this episode was opened, and trashing the history later
     * must not change what this episode is.
     */
    public function readmissionClassification(): ?string
    {
        if (blank($this->previous_follow_up_child_id)) {
            return null;
        }

        return static::withTrashed()
            ->find($this->previous_follow_up_child_id)
            ?->classifiesReturnAs();
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
     * The classification a return for this child ID would carry right now,
     * or null when it would be a new admission.
     */
    public static function readmissionClassificationFor(mixed $idNumber): ?string
    {
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
            ->whereIn('discharge_outcome', self::CLOSING_OUTCOMES)
            ->orderByDesc('discharge_date')
            ->orderByDesc('id')
            ->first();
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
