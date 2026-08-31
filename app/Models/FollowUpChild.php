<?php

namespace App\Models;

use App\Support\MuacClassifier;
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
     * The one discharge outcome that leaves a record open.
     *
     * Discharge Outcome is the module's only state flag: as long as it holds
     * this value the record is active and fully editable; any other value is a
     * discharge, and a discharged record is locked for good.
     */
    public const ACTIVE_OUTCOME = 'under_follow_up';

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
     * Visits ordered by their sequential visit number (1-16).
     */
    public function visits(): HasMany
    {
        return $this->hasMany(FollowUpChildVisit::class)->orderBy('visit_number');
    }

    /**
     * Remove related visits only when the child record is destroyed for good.
     *
     * Visits are not soft-deletable, so deleting them on an ordinary (soft)
     * delete destroyed every recorded MUAC reading permanently: restoring the
     * child from the Trash brought back an empty record. A soft delete now
     * leaves the visits untouched, and only a force delete clears them - which
     * the foreign key would do on its own anyway.
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
     * Formatted age at admission: how old the child was, per their date of
     * birth, on the day they were admitted (DATEDIF equivalent).
     *
     * Both the months and the days come from a single calendar diff of the two
     * dates. Carbon 3 returns a float from diffInYears(), so deriving the
     * months from it separately double-counted the whole months already
     * carried by the diff and produced values like "11.96 شهر" for a six
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
            $parts[] = $totalMonths . ' شهر';
        }
        if ($days > 0) {
            $parts[] = $days . ' يوم';
        }

        return implode(' و ', $parts) ?: '0 يوم';
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
     * The most recent recorded visit, by visit number.
     */
    public function latestVisit(): ?FollowUpChildVisit
    {
        return $this->visits->last();
    }

    /**
     * FI of the most recent recorded visit.
     */
    protected function latestFi(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => MuacClassifier::classify($this->latestVisit()?->muac),
        );
    }

    /**
     * Whether this record has been discharged and is therefore locked.
     *
     * A blank outcome is an older record that predates the outcome ever being
     * filled in: it counts as still under follow-up, so nothing that used to
     * be editable becomes read-only on upgrade.
     */
    public function isLocked(): bool
    {
        return filled($this->discharge_outcome)
            && $this->discharge_outcome !== self::ACTIVE_OUTCOME;
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
