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

class IndividualCounseling extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, NotifiesSuperAdminOnChange;

    protected $table = 'individual_counselings';

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
        'act',
        'pregnancy',
        'lactating',
        'delivery_date',
        'pregnancy_count',
        'assess_and_analyze',
        'follow_up_visit_date',
    ];

    protected $casts = [
        'date' => 'date',
        'child_dob' => 'date',
        'mother_dob' => 'date',
        'delivery_date' => 'date',
        'follow_up_visit_date' => 'date',
        'iycf_form_filled' => 'boolean',
        'age_months' => 'integer',
        'muac' => 'decimal:1',
    ];

    /**
     * Classify a MUAC measurement: <=115 SAM, 116-124 MAM, >=125 Normal.
     *
     * Note the upper boundary differs by one from Child::classifyMuac, which
     * still counts 125 as MAM. The Individual Counseling programme treats 125
     * as Normal, so the two are deliberately not shared.
     */
    public static function classifyMuac(mixed $muac): ?string
    {
        if ($muac === null || $muac === '') {
            return null;
        }

        $muac = (float) $muac;

        return match (true) {
            $muac <= 115 => 'SAM',
            $muac < 125 => 'MAM',
            default => 'Normal',
        };
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
