<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
     * Outcomes that close a record. A closed record is read-only: the child
     * has left the programme and the history of that episode must not move.
     *
     * @var array<string>
     */
    public const CLOSING_OUTCOMES = [
        self::CURED_OUTCOME,
        'defaulted',
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
     * Closed is not the test. Cured, defaulted, non-responded and died all
     * close a record just the same and never allow a readmission.
     *
     * @var array<string>
     */
    public const READMISSION_OUTCOMES = [
        'discharge_to_opt',
        'discharge_to_other',
        'referred_medical_inpt',
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
     * episode ended as cured, defaulted, non-responded or died is not
     * readmitted, whatever an earlier episode ended as.
     */
    public static function readmittableEpisodeFor(mixed $idNumber): ?self
    {
        $previous = static::latestClosedEpisodeFor($idNumber);

        return $previous?->canBeReadmitted() ? $previous : null;
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
