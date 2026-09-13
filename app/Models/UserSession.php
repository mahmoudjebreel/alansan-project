<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One sitting of one user: sign-in to sign-out, or to the last time the
 * browser was seen. Telemetry, not audit: rows are pruned after the retention
 * period, while the matching login/logout rows in activity_log are kept.
 */
class UserSession extends Model
{
    /** Session key holding the id of the current row, so a request can find it. */
    public const SESSION_KEY = 'user_activity.session_id';

    public const STATUS_ONLINE = 'online';

    public const STATUS_IDLE = 'idle';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_LOGGED_OUT = 'logged_out';

    public const ENDED_LOGOUT = 'logout';

    public const ENDED_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'session_hash',
        'ip_address',
        'user_agent',
        'browser',
        'platform',
        'device_type',
        'remember',
        'login_at',
        'last_seen_at',
        'logout_at',
        'ended_reason',
        'login_activity_id',
    ];

    protected function casts(): array
    {
        return [
            'remember' => 'boolean',
            'login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'logout_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(UserPageVisit::class);
    }

    public function latestVisit(): HasOne
    {
        return $this->hasOne(UserPageVisit::class)->latestOfMany();
    }

    /** Sessions seen within the configured online window and not signed out. */
    public function scopeOnline(Builder $query): Builder
    {
        return $query
            ->whereNull('logout_at')
            ->where('last_seen_at', '>=', now()->subMinutes(static::onlineWithinMinutes()));
    }

    public static function onlineWithinMinutes(): int
    {
        return max(1, (int) config('user-activity.online_within_minutes', 5));
    }

    /**
     * online, idle, expired or logged_out - derived, never stored, so the
     * answer is right at the moment it is read.
     */
    public function status(): string
    {
        if ($this->logout_at !== null) {
            return self::STATUS_LOGGED_OUT;
        }

        $lastSeen = $this->last_seen_at ?? $this->login_at;

        if ($lastSeen === null) {
            return self::STATUS_EXPIRED;
        }

        if ($lastSeen->gte(now()->subMinutes(static::onlineWithinMinutes()))) {
            return self::STATUS_ONLINE;
        }

        $lifetimeMinutes = max(1, (int) config('session.lifetime', 120));

        if ($lastSeen->gte(now()->subMinutes($lifetimeMinutes))) {
            return self::STATUS_IDLE;
        }

        return self::STATUS_EXPIRED;
    }

    /** "Chrome / Windows / desktop", from the heuristic user-agent summary. */
    public function deviceLabel(): string
    {
        $parts = array_filter([$this->browser, $this->platform, $this->device_type]);

        return $parts === [] ? '-' : implode(' / ', $parts);
    }
}
