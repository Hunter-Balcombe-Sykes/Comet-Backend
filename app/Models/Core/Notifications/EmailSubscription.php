<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// V2: Marketing email opt-in/out record per professional+email. Source of truth for consent; caches status on Customer for performance.
// status ('subscribed'/'unsubscribed') is enforced at the DB level. @see supabase/migrations/202605190000002_add_enum_check_constraints.sql
class EmailSubscription extends BaseModel
{
    use HasUuids;

    protected $table = 'notifications.email_subscriptions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'unsubscribe_token',
        'consent_ip_hash',
        'consent_user_agent',
    ];

    protected $fillable = [
        'user_id',
        'list_key',
        'email',
        'full_name',
        'status',
        'subscribed_at',
        'unsubscribed_at',
        'unsubscribe_token',
        'consent_source',
        'consent_ip_hash',
        'consent_user_agent',
        'email_lc',
    ];

    protected $casts = [
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function newUnsubscribeToken(): string
    {
        return Str::random(48);
    }

    public function markSubscribed(array $meta = []): void
    {
        $this->status = 'subscribed';
        $this->subscribed_at = $this->subscribed_at ?? now();
        $this->unsubscribed_at = null;

        if (isset($meta['source'])) {
            $this->consent_source = $meta['source'];
        }
        if (isset($meta['ip_hash'])) {
            $this->consent_ip_hash = $meta['ip_hash'];
        }
        if (isset($meta['user_agent'])) {
            $this->consent_user_agent = $meta['user_agent'];
        }
    }

    public function markUnsubscribed(): void
    {
        $this->status = 'unsubscribed';
        $this->unsubscribed_at = now();
    }

    /**
     * Sync customer cache after save (when status changes).
     * Source of truth is this EmailSubscription.status, but we cache on Customer for UX/perf.
     * Dispatched async (CACHE-11) so bulk subscribe imports don't pay a per-row Customer lookup
     * inside the originating request — Customer.isMarketingOptedIn() falls back to a live
     * read while the cache catches up.
     */
    protected static function booted(): void
    {
        static::saved(function (self $subscription) {
            if ($subscription->list_key === 'marketing' && $subscription->user_id && $subscription->email) {
                // afterCommit so the job never enqueues if the surrounding
                // transaction rolls back — avoids a wasted queue slot and a
                // confusing "customer not found" no-op log in Horizon (#JOB-5).
                //
                // B3/P1-10: only UUIDs cross the queue boundary. Email + status
                // are looked up inside handle() from this row, so a GDPR-erased
                // row produces a quiet no-op instead of a leaked email payload.
                $userId = (string) $subscription->user_id;
                $subscriptionId = (string) $subscription->id;

                DB::afterCommit(function () use ($userId, $subscriptionId) {
                    \App\Jobs\Notifications\SyncCustomerMarketingOptInJob::dispatch(
                        $userId,
                        $subscriptionId,
                    );
                });
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
