<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\NotifiesSuperAdminOnChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Carbon\Carbon;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class PregnantLactatingWoman extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, NotifiesSuperAdminOnChange;

    protected $table = 'pregnant_lactating_women';

    protected $fillable = [
        'visit_type', 'full_name_ar', 'mother_id', 'phone_number', 'is_pwd',
        'organization', 'implementing_partner', 'date_of_reporting',
        'is_displaced', 'screener_profession', 'date_of_birth', 'age_years',
        'status_type', 'weight_kg', 'height_cm', 'muac_mm', 'fi',
        'has_oedema', 'governorate', 'municipality', 'neighbourhood',
        'location', 'type_of_site', 'disability_type', 'newborn_dob',
        'status', 'husband_id_number', 'husband_full_name', 'husband_phone',
        'family_size', 'children_count', 'is_family_pwd',
    ];

    protected $casts = [
        'date_of_reporting' => 'date',
        'date_of_birth' => 'date',
        'newborn_dob' => 'date',
        'is_pwd' => 'boolean',
        'is_displaced' => 'boolean',
        'has_oedema' => 'boolean',
        'is_family_pwd' => 'boolean',
        'weight_kg' => 'decimal:2',
        'height_cm' => 'decimal:1',
        'muac_mm' => 'decimal:1',
        'age_years' => 'integer',
        'family_size' => 'integer',
        'children_count' => 'integer',
    ];

    /**
     * Classify a pregnant or lactating woman's MUAC measurement.
     */
    public static function classifyMuac(mixed $muacMm): ?string
    {
        if ($muacMm === null || $muacMm === '') {
            return null;
        }

        return (float) $muacMm < 230 ? 'Malnourished' : 'Normal';
    }

    /**
     * Keep FI derived from MUAC whenever the measurement changes.
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
     * Get the calculated age in years from date of birth.
     */
    protected function calculatedAge(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->date_of_birth) {
                    return (int) Carbon::parse($this->date_of_birth)->diffInYears(Carbon::now());
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
                return $this->calculated_age ?? $this->age_years;
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
            ->setDescriptionForEvent(fn(string $eventName) => "PLW record {$eventName}");
    }
}
