<?php

namespace App\Models\Core\Notifications;

use App\Jobs\Notifications\SyncCustomerMarketingOptInJob;
use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string|null $user_id Null for list-level (non-user-specific) subscriptions — the partial unique/index pairs key on this being NULL vs NOT NULL.
 * @property string $list_key
 * @property string $email
 * @property string|null $full_name
 * @property string $status One of 'subscribed'|'unsubscribed' (email_subscriptions_status_check).
 * @property Carbon|null $subscribed_at
 * @property Carbon|null $unsubscribed_at
 * @property string $unsubscribe_token NOT NULL, no DB default — callers must set via newUnsubscribeToken() before create().
 * @property string|null $consent_source
 * @property string|null $consent_ip_hash
 * @property string|null $consent_user_agent
 * @property string $email_lc Lowercased email, backs the per-list uniqueness indexes.
 * @property Carbon|null $confirmation_sent_at Visitor-facing confirmation-email idempotency stamp; reset to null on a genuine re-subscribe.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */

// V2: Marketing email opt-in/out record per professional+email. Source of truth for consent; caches status on Customer for performance.
// status ('subscribed'/'unsubscribed') is enforced at the DB level by email_subscriptions_status_check.
// @see supabase/migrations/20260726000000_baseline_pilot.sql
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

    // user_id/email_lc (S4 Tier 2b) removed — user_id is the site-owner
    // attribution FK, email_lc backs the per-list uniqueness indexes; both are
    // written only by trusted controller code via direct property assignment,
    // never via request-bound fill().
    protected $fillable = [
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
        'confirmation_sent_at',
    ];

    protected $casts = [
        'subscribed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'confirmation_sent_at' => 'datetime',
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
                    SyncCustomerMarketingOptInJob::dispatch(
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
