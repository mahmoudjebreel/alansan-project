<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One follow-up session recorded against an Individual Counseling record.
 */
class IndividualCounselingFollowup extends Model
{
    use HasFactory;

    protected $table = 'individual_counseling_followups';

    protected $fillable = [
        'individual_counseling_id', 'sort_order', 'follow_up_visit_date',
        'assess_and_analyze', 'act',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'follow_up_visit_date' => 'date',
    ];

    public function individualCounseling(): BelongsTo
    {
        return $this->belongsTo(IndividualCounseling::class);
    }
}
