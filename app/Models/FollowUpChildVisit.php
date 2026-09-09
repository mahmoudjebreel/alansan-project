<?php

namespace App\Models;

use App\Support\MuacClassifier;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUpChildVisit extends Model
{
    use HasFactory;

    protected $table = 'follow_up_child_visits';

    /** The child came and was seen. What every visit on file was until now. */
    public const STATUS_ATTENDED = 'attended';

    /**
     * The child did not come. Recorded only when a person records it - the
     * system never writes a missed visit of its own accord.
     */
    public const STATUS_MISSED = 'missed';

    /** @var array<string> */
    public const STATUSES = [
        self::STATUS_ATTENDED,
        self::STATUS_MISSED,
    ];

    protected $fillable = [
        'follow_up_child_id', 'visit_number', 'visit_date', 'muac', 'fi', 'status',
    ];

    protected $casts = [
        'visit_number' => 'integer',
        'visit_date' => 'date',
        'muac' => 'decimal:1',
    ];

    protected $attributes = [
        'status' => self::STATUS_ATTENDED,
    ];

    public function followUpChild(): BelongsTo
    {
        return $this->belongsTo(FollowUpChild::class);
    }

    public function isMissed(): bool
    {
        return $this->status === self::STATUS_MISSED;
    }

    /**
     * A missed visit has no measurement: nobody was there to take one. The
     * reading is cleared rather than kept, so a status changed to "missed"
     * can never leave a number behind that reads as a taken measurement.
     */
    protected static function booted(): void
    {
        static::saving(function (FollowUpChildVisit $visit): void {
            if (blank($visit->status)) {
                $visit->status = self::STATUS_ATTENDED;
            }

            if ($visit->isMissed()) {
                $visit->muac = null;
            }
        });
    }

    /**
     * FI is a stored copy of the visit's own MUAC classification, never an
     * input: writing the measurement is what settles it.
     */
    public function setMuacAttribute(mixed $value): void
    {
        $this->attributes['muac'] = $value;
        $this->attributes['fi'] = MuacClassifier::classify($value);
    }

    /**
     * Read FI back from the current measurement rather than from the column,
     * so a row written before the column existed still reports correctly.
     */
    protected function fi(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => MuacClassifier::classify($this->attributes['muac'] ?? null),
        );
    }
}
