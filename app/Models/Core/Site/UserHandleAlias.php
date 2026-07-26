<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

// Historical handle alias that serves 301 redirects after a professional renames.
// Lifecycle: GRACE (0–14d, owner-reclaimable) → REDIRECT (14–90d) → RELEASED (prune deletes row).
/**
 * @property string $id
 * @property string $user_id
 * @property string $handle
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $reclaim_until
 * @property Carbon|null $expires_at
 * @property Carbon|null $notified_t3_at
 * @property Carbon|null $notified_t1_at
 */
class UserHandleAlias extends BaseModel
{
    use HasUuids;

    protected $table = 'core.user_handle_aliases';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'handle',
        'reclaim_until',
        'expires_at',
        'notified_t3_at',
        'notified_t1_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'reclaim_until' => 'datetime',
        'expires_at' => 'datetime',
        'notified_t3_at' => 'datetime',
        'notified_t1_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Active = no expiry set (legacy) OR not yet expired.
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    // Reclaimable = within the owner-only grace window.
    public function scopeReclaimable($query)
    {
        return $query->whereNotNull('reclaim_until')->where('reclaim_until', '>', now());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
