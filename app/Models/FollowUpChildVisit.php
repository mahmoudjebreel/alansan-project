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

    /**
     * Keep FI derived from MUAC whenever a visit's measurement changes, the
     * same way the Children module keeps its own FI in step.
     */
    public function setMuacAttribute(mixed $value): void
    {
        $this->attributes['muac'] = $value;
        $this->attributes['fi'] = MuacClassifier::classify($value);
    }

    /**
     * FI is never an input: reading it always re-classifies the stored MUAC,
     * so a row written before this column existed still reports correctly.
     */
    protected function fi(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => MuacClassifier::classify($this->attributes['muac'] ?? null),
        );
    }

    public function followUpChild(): BelongsTo
    {
        return $this->belongsTo(FollowUpChild::class);
    }
}
