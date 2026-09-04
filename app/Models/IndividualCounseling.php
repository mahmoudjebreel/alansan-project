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

class IndividualCounseling extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, NotifiesSuperAdminOnChange;

    protected $table = 'individual_counselings';

    /**
     * Follow-up sessions a single record may hold.
     *
     * Deliberately lower than FollowUpChild::MAX_VISITS: the counseling
     * programme closes a case after six sessions.
     */
    public const MAX_FOLLOWUP_SESSIONS = 6;

    protected $fillable = [
        'date',
        'health_educator',
        'child_name',
        'child_visit_type',
        'child_dob',
        'age_months',
        'gender',
        'child_age_lactated',
        'feeding_type',
        'p_l',
        'muac',
        'muac_degree',
        'mother_id_number',
        'mother_name',
        'mother_visit_type',
        'mother_dob',
        'mother_age_years',
        'mobile_number',
        'shelter_name',
        'consultation',
        'iycf_form_filled',
        'status',
        'outcome',
        'assess',
        'analyze',
        'act',
        'pregnancy',
        'lactating',
        'delivery_date',
        'pregnancy_count',
    ];

    protected $casts = [
        'date' => 'date',
        'child_dob' => 'date',
        'mother_dob' => 'date',
        'delivery_date' => 'date',
        'iycf_form_filled' => 'boolean',
        'age_months' => 'integer',
        'muac' => 'decimal:1',
    ];

    /**
     * Classify a MUAC measurement: <=115 SAM, 116-124 MAM, >=125 Normal.
     *
     * This used to carry its own copy of the thresholds, under a comment
     * saying the upper boundary differed from the Children module by one.
     * The two implementations were in fact identical - whichever change made
     * them agree left the comment behind - so the copy is gone and both read
     * the one classifier. A child measured at 124 mm is MAM in every module.
     */
    public static function classifyMuac(mixed $muac): ?string
    {
        return MuacClassifier::classify($muac);
    }

    /**
     * Whole months between a date of birth and today, for the read-only
     * "Age In Months" field.
     */
    public static function ageInMonths(mixed $dob): ?int
    {
        return blank($dob) ? null : (int) Carbon::parse($dob)->diffInMonths(Carbon::now());
    }

    /**
     * Whole years between a date of birth and today, for the read-only
     * "Age In Years" field.
     */
    public static function ageInYears(mixed $dob): ?int
    {
        return blank($dob) ? null : (int) Carbon::parse($dob)->diffInYears(Carbon::now());
    }

    /**
     * Repeatable follow-up sessions, in the order they were entered.
     */
    public function followups(): HasMany
    {
        return $this->hasMany(IndividualCounselingFollowup::class)->orderBy('sort_order');
    }

    /**
     * Keep the MUAC degree derived from MUAC whenever the measurement changes.
     */
    public function setMuacAttribute(mixed $value): void
    {
        $this->attributes['muac'] = $value;
        $this->attributes['muac_degree'] = static::classifyMuac($value);
    }

    /**
     * Always expose the degree as the classification of the current MUAC value.
     */
    protected function muacDegree(): Attribute
    {
        return Attribute::make(
            get: fn () => static::classifyMuac($this->attributes['muac'] ?? null),
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Individual counseling record {$eventName}");
    }
}
