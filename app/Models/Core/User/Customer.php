<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Site\Enquiry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// V2: A professional's customer record. Supports soft deletes, marketing opt-in caching from EmailSubscription, and external ID for POS integrations.
class Customer extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.customers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'external_id',
    ];

    protected $fillable = [
        'user_id',
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

        $status = EmailSubscription::query()
            ->where('user_id', $this->user_id)
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

        $subscription = EmailSubscription::query()
            ->where('user_id', $this->user_id)
            ->where('list_key', 'marketing')
            ->where('email_lc', strtolower($this->email))
            ->first();

        $this->marketing_opt_in_cached = $subscription?->status === 'subscribed';
        $this->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Erase customer PII and cascade to all linked enquiries.
     *
     * Nulls contact fields and stamps redacted_at so downstream consumers
     * (exports, API resources) know the row has been sanitised. Cascades to
     * every enquiry that references this customer so PII doesn't linger there.
     */
    public function redact(): void
    {
        $this->update([
            'email' => null,
            'full_name' => null,
            'phone' => null,
            'notes' => null,
            'redacted_at' => now(),
        ]);

        Enquiry::where('customer_id', $this->id)
            ->each(fn ($e) => $e->redact());
    }
}
