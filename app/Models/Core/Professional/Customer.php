<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// V2: A professional's customer record. Supports soft deletes, marketing opt-in caching from EmailSubscription, and external ID for POS integrations.
class Customer extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'core.customers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'external_id',
    ];

    protected $fillable = [
        'professional_id',
        'email',
        'phone',
        'full_name',
        'source',
        'notes',
        'external_id',
        'marketing_opt_in_cached',
        'redacted_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'marketing_opt_in_cached' => 'boolean',
        'redacted_at' => 'datetime',
    ];

    /**
     * Get the current marketing opt-in status.
     *
     * Uses the cached column as the primary source to avoid N+1 queries when
     * iterating over customers. Falls back to a live DB lookup only when the
     * cache is null (un-synced row). syncMarketingOptInCache() keeps the cache
     * fresh whenever the underlying EmailSubscription changes.
     */
    public function isMarketingOptedIn(): bool
    {
        if ($this->marketing_opt_in_cached !== null) {
            return (bool) $this->marketing_opt_in_cached;
        }

        $status = \App\Models\Core\Notifications\EmailSubscription::query()
            ->where('professional_id', $this->professional_id)
            ->where('list_key', 'marketing')
            ->where('email_lc', strtolower($this->email ?? ''))
            ->value('status');

        return $status === 'subscribed';
    }

    /**
     * Sync cache from EmailSubscription status (call when subscription changes).
     */
    public function syncMarketingOptInCache(): void
    {
        if (empty($this->email)) {
            $this->marketing_opt_in_cached = null;

            return;
        }

        $subscription = \App\Models\Core\Notifications\EmailSubscription::query()
            ->where('professional_id', $this->professional_id)
            ->where('list_key', 'marketing')
            ->where('email_lc', strtolower($this->email))
            ->first();

        $this->marketing_opt_in_cached = $subscription?->status === 'subscribed';
        $this->save();
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }
}
