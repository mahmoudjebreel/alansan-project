<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\MuacClassifier;
use App\Traits\NotifiesSuperAdminOnChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Carbon\Carbon;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Child extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, NotifiesSuperAdminOnChange;

    protected $table = 'children';

    protected $fillable = [
        'visit_type', 'name', 'child_id', 'phone_number', 'is_pwd',
        'organization', 'implementing_partner', 'date_of_reporting',
        'is_displaced', 'screener_profession', 'sex', 'date_of_birth',
        'age_months', 'muac_mm', 'fi', 'has_oedema', 'weight_kg',
        'height_cm', 'whz', 'governorate', 'municipality', 'neighbourhood',
        'location', 'type_of_site', 'is_enrolled_bsfp', 'is_sick_last_6_months',
        'is_mother_alive', 'mother_full_name', 'mother_id_number',
        'mother_date_of_birth', 'mother_age_years', 'mother_phone',
        'father_full_name', 'father_id_number', 'father_phone',
        'has_lactating_woman', 'has_pregnant_last_trimester',
        'children_under_5', 'head_of_household_sex', 'mother_marital_status',
        'mother_muac_mm', 'is_mother_malnourished', 'has_stable_income',
        'income_source', 'is_income_below_500', 'male_children_under_5',
        'female_children_under_5', 'family_size', 'current_address',
        'original_address', 'has_family_disability', 'disability_cause',
        'disability_cause_other', 'has_injured_after_oct7', 'injured_count',
        'has_unaccompanied_children', 'unaccompanied_children_count',
        'has_released_children', 'source_follow_up_child_id',
    ];

    protected $casts = [
        'date_of_reporting' => 'date',
        'date_of_birth' => 'date',
        'mother_date_of_birth' => 'date',
        'is_pwd' => 'boolean',
        'is_displaced' => 'boolean',
        'has_oedema' => 'boolean',
        'is_enrolled_bsfp' => 'boolean',
        'is_sick_last_6_months' => 'boolean',
        'is_mother_alive' => 'boolean',
        'has_lactating_woman' => 'boolean',
        'has_pregnant_last_trimester' => 'boolean',
        'is_mother_malnourished' => 'boolean',
        'has_stable_income' => 'boolean',
        'is_income_below_500' => 'boolean',
        'has_family_disability' => 'boolean',
        'has_injured_after_oct7' => 'boolean',
        'has_unaccompanied_children' => 'boolean',
        'has_released_children' => 'boolean',
        'muac_mm' => 'decimal:1',
        'weight_kg' => 'decimal:2',
        'height_cm' => 'decimal:1',
        'whz' => 'decimal:2',
        'mother_muac_mm' => 'decimal:1',
        'age_months' => 'integer',
        'mother_age_years' => 'integer',
        'children_under_5' => 'integer',
        'male_children_under_5' => 'integer',
        'female_children_under_5' => 'integer',
        'family_size' => 'integer',
        'injured_count' => 'integer',
        'unaccompanied_children_count' => 'integer',
    ];

    /**
     * Classify a child's MUAC measurement using the program thresholds.
     *
     * Kept as a method on the model because the forms, the table and the
     * imports all call it, but the thresholds themselves live in one place.
     */
    public static function classifyMuac(mixed $muacMm): ?string
    {
        return MuacClassifier::classify($muacMm);
    }

    /**
     * Keep FI derived from MUAC whenever a child's measurement changes.
     */
    public function setMuacMmAttribute(mixed $value): void
    {
        $this->attributes['muac_mm'] = $value;
        $this->attributes['fi'] = static::classifyMuac($value);
    }

    /**
     * Always expose FI as the classification of the current MUAC value.
     */
    protected function fi(): Attribute
    {
        return Attribute::make(
            get: fn () => static::classifyMuac($this->attributes['muac_mm'] ?? null),
        );
    }

    /**
     * Get the calculated age in months from date of birth.
     */
    protected function calculatedAge(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->date_of_birth) {
                    return (int) Carbon::parse($this->date_of_birth)->diffInMonths(Carbon::now());
                }
                return null;
            },
        );
    }

    /**
     * Get the effective age: calculated from DOB if available, otherwise manual entry.
     */
    protected function effectiveAge(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->calculated_age ?? $this->age_months;
            },
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
            ->setDescriptionForEvent(fn(string $eventName) => "Child record {$eventName}");
    }
}
