<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUpChildVisit extends Model
{
    use HasFactory;

    protected $table = 'follow_up_child_visits';

    protected $fillable = [
        'follow_up_child_id', 'visit_number', 'visit_date', 'muac',
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
}
