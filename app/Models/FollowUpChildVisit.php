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

    protected $fillable = [
        'follow_up_child_id', 'visit_number', 'visit_date', 'muac', 'fi',
    ];

    protected $casts = [
        'visit_number' => 'integer',
        'visit_date' => 'date',
        'muac' => 'decimal:1',
    ];

    public function followUpChild(): BelongsTo
    {
        return $this->belongsTo(FollowUpChild::class);
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
