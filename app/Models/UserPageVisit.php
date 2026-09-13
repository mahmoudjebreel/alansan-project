<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One full page load inside the panel, and the approximate time the tab was
 * visible on it. The browser identifies the visit by its token only.
 */
class UserPageVisit extends Model
{
    protected $fillable = [
        'user_session_id',
        'user_id',
        'visit_token',
        'route_name',
        'path',
        'page_kind',
        'subject_type',
        'subject_id',
        'ip_address',
        'entered_at',
        'last_heartbeat_at',
        'left_at',
        'active_seconds',
        'heartbeats',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'left_at' => 'datetime',
            'active_seconds' => 'integer',
            'heartbeats' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(UserSession::class, 'user_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * When the visit is considered over: the explicit leave, or the last
     * heartbeat once the browser has been silent for the online window.
     */
    public function endedAt(): ?Carbon
    {
        if ($this->left_at !== null) {
            return $this->left_at;
        }

        $lastSignal = $this->last_heartbeat_at ?? $this->entered_at;

        if ($lastSignal !== null && $lastSignal->lt(now()->subMinutes(UserSession::onlineWithinMinutes()))) {
            return $lastSignal;
        }

        return null;
    }
}
