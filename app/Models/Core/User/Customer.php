<?php

namespace App\Models\Core\User;

use App\Models\Analytics\LeadSubmission;
use App\Models\BaseModel;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Notifications\Notification;
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

    /**
     * external_id is a third-party POS reconciliation key — hidden from
     * serialization but deliberately NOT nulled by redact() (models-data/
     * PRIV-3, declined). This routine cannot authoritatively sever it: the
     * POS is the system of record for it, not us. Two concrete reasons it
     * must survive redaction:
     *   - UserEnquiryController:221 uses `empty($customer->external_id)` as a
     *     delete guard — nulling it would make a redacted customer deletable
     *     as a spam artefact, which is the opposite of the intended effect.
     *   - PublicCustomerLeadController:106 re-populates external_id on the
     *     next lead submission from the same source, so nulling it here
     *     would not even be durable.
     * See redact() below for the resulting erasure-completeness caveat.
     */
    protected $hidden = [
        'external_id',
    ];

    // SEC-1: user_id is server-managed — excluded from mass-assignment. Set via
    // the ->customers() relation's create() (sets the FK directly) or direct
    // property assignment on a pre-authorization skeleton.
    protected $fillable = [
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
     * Erase customer PII and cascade to all linked enquiries and lead
     * submissions.
     *
     * Nulls contact fields and stamps redacted_at so downstream consumers
     * (exports, API resources) know the row has been sanitised. Cascades to
     * every enquiry AND every lead-capture record that references this
     * customer so PII doesn't linger in either place (models-data/PRIV-1 —
     * the cascade previously stopped at Enquiry, leaving the visitor's
     * hashed IP/UA/referrer in analytics.lead_submissions untouched).
     *
     * external_id is deliberately NOT nulled here — see its own note above.
     * That means a redacted customer is CONTACT-erased (email/phone/
     * full_name/notes gone), not fully erased, whenever external_id is set.
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

        // SCALE-4: erase all linked enquiries in ONE bulk UPDATE instead of N
        // per-row redact() calls. Capture linked notification ids first and
        // redact them in a single UPDATE too — preserving Enquiry::redact()'s
        // full erasure semantics (the same nulled fields AND the notification
        // title/body scrub) without loading every enquiry into memory.
        // subject is '[redacted]', not null — site.enquiries.subject is
        // NOT NULL in Postgres (models-data/PRIV-4), so nulling it here would
        // pass on SQLite and throw 23502 on Postgres.
        $notificationIds = Enquiry::where('customer_id', $this->id)
            ->whereNotNull('notification_id')
            ->distinct()
            ->pluck('notification_id');

        Enquiry::where('customer_id', $this->id)->update([
            'name' => null,
            'email' => null,
            'phone' => null,
            'subject' => '[redacted]',
            'message' => null,
            'ip_hash' => null,
            'user_agent' => null,
            'redacted_at' => now(),
        ]);

        if ($notificationIds->isNotEmpty()) {
            Notification::whereIn('id', $notificationIds)
                ->update(['title' => '[redacted]', 'body' => '[redacted]']);
        }

        // models-data/PRIV-1: cascade to the visitor-side lead-capture record
        // too — analytics.lead_submissions carries the same customer_id FK and
        // the same ip_hash/user_agent PII the Enquiry cascade above already
        // scrubs, plus referrer. Strictly scoped to this customer's rows —
        // this is a bulk UPDATE across potentially many visitors' rows, and a
        // missing/loose customer_id filter here would over-redact.
        LeadSubmission::where('customer_id', $this->id)->update([
            'ip_hash' => null,
            'user_agent' => null,
            'referrer' => null,
        ]);
    }
}
