<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed Children Excel upload, as the Referral Centre sees it.
 *
 * Written after the import has committed, never during it. A batch is a
 * pointer at a primary-key window in `children` and carries no state of its
 * own: whether a child still needs referring is answered by looking at the
 * child and at the follow-up module, not by a flag kept here. That keeps the
 * batch from becoming a second, stale copy of what the follow-up records
 * already say.
 */
class ReferralBatch extends Model
{
    protected $table = 'referral_batches';

    /** The only module whose uploads produce referral candidates today. */
    public const CHILDREN_MODULE = 'children';

    protected $fillable = [
        'user_id', 'module', 'imported_count', 'first_record_id', 'last_record_id',
    ];

    protected $casts = [
        'imported_count' => 'integer',
        'first_record_id' => 'integer',
        'last_record_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The most recent Children upload, or null when none has been recorded.
     */
    public static function latestChildrenBatch(): ?self
    {
        return static::query()
            ->where('module', self::CHILDREN_MODULE)
            ->latest('id')
            ->first();
    }

    /**
     * A short label for the batch selector: when it ran, by whom, how big.
     */
    public function label(): string
    {
        return __('ui.referral_center.batch_label', [
            'date' => $this->created_at?->format('Y-m-d H:i') ?? '—',
            'count' => $this->imported_count,
            'user' => $this->user?->name ?? __('ui.referral_center.unknown_user'),
        ]);
    }
}
