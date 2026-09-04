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
        'died',
    ];

    protected $fillable = [
        'id_number', 'child_name', 'sex', 'dob', 'age', 'mobile_number',
        'shelter_name', 'governorate', 'causes_of_admission', 'admitted_with',
        'admission_date', 'discharge_date', 'discharge_outcome', 'notes',
        'source_child_visit_id',
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
     * The most recent recorded visit, or null while none exists.
     */
    public function latestVisit(): ?FollowUpChildVisit
    {
        return $this->visits()->reorder()->orderByDesc('visit_number')->first();
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
     * MUAC of the most recent recorded visit.
     */
    protected function latestMuac(): Attribute
    {
        return Attribute::make(
            get: fn (): mixed => $this->visits->last()?->muac,
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
