<?php

namespace App\Models\Core\Site;

use App\Enums\EnquiryStatus;
use App\Models\BaseModel;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// V2: A visitor-submitted enquiry from a site's contact section block. read_at=null means unread.
class Enquiry extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.enquiries';

    public $incrementing = false;

    protected $keyType = 'string';

    // user_id/site_id (tenancy FKs, NOT NULL) and status/customer_id/notification_id
    // are excluded from mass-assignment. Writers set them explicitly:
    //   user_id/site_id/customer_id — PublicEnquiryController::submit() via
    //     ->user()/->site()/->customer()->associate() (never client input).
    //   status — the transition methods below (markRead/markReplied/archive/
    //     markSpam/restoreToNew) and UserEnquiryController::update() via
    //     forceFill(), never taken directly from a request payload.
    //   notification_id — InAppEnquiryNotificationAdapter via direct property
    //     assignment after the Notification row is created.
    protected $fillable = [
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'ip_hash',
        'user_agent',
        'read_at',
        'email_sent_at',
        'confirmation_sent_at',
        'replied_at',
        'archived_at',
        'spam_at',
        'redacted_at',
    ];

    // Submitter PII + request telemetry hidden from default model serialization.
    // UserEnquiryController surfaces these via EnquiryResource (direct attribute
    // access, unaffected by $hidden); naked toArray() in jobs/logs/broadcasts is now safe.
    protected $hidden = [
        'name',
        'email',
        'phone',
        'message',
        'ip_hash',
        'user_agent',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'email_sent_at' => 'datetime',
        'confirmation_sent_at' => 'datetime',
        'status' => EnquiryStatus::class,
        'replied_at' => 'datetime',
        'archived_at' => 'datetime',
        'spam_at' => 'datetime',
        'redacted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }

    // -------------------------------------------------------------------------
    // Status transition methods — each method is idempotent (safe to call
    // multiple times). Audit timestamps are never cleared by transitions so
    // the history of prior states is always preserved.
    // -------------------------------------------------------------------------

    public function markRead(): void
    {
        // status is not fillable — forceFill so this transition still persists it.
        // Preserve existing read_at if already read (idempotent timestamp).
        $this->forceFill(['status' => 'read', 'read_at' => $this->read_at ?? now()])->save();
    }

    public function markReplied(): void
    {
        $this->forceFill(['status' => 'replied', 'replied_at' => now()])->save();
    }

    public function archive(): void
    {
        $this->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
    }

    public function markSpam(): void
    {
        $this->forceFill(['status' => 'spam', 'spam_at' => now()])->save();
    }

    /**
     * Restore enquiry to 'new' status. Named restoreToNew() because
     * Eloquent's SoftDeletes trait already defines restore().
     * Audit timestamps (archived_at, spam_at, etc.) are intentionally
     * preserved to maintain the full transition history.
     */
    public function restoreToNew(): void
    {
        $this->forceFill(['status' => 'new'])->save();
    }

    /**
     * Scrub all submitter PII from this enquiry and null out the linked
     * notification body/title. Safe to call on enquiries with no notification.
     *
     * subject is '[redacted]', not null (models-data/PRIV-4) — two reasons:
     * it's free text that can carry the submitter's name ("Hi, it's Sarah"),
     * so it must be scrubbed like the other fields; and site.enquiries.subject
     * is NOT NULL in Postgres (supabase/migrations-archive/20260526000000_baseline_standalone_user.sql:974),
     * so `'subject' => null` would pass on SQLite and throw 23502 in production.
     * Mirrors the Notification title/body idiom below.
     */
    public function redact(): void
    {
        $this->update([
            'name' => null,
            'email' => null,
            'phone' => null,
            'subject' => '[redacted]',
            'message' => null,
            'ip_hash' => null,
            'user_agent' => null,
            'redacted_at' => now(),
        ]);

        if ($this->notification_id) {
            Notification::where('id', $this->notification_id)
                ->update(['title' => '[redacted]', 'body' => '[redacted]']);
        }
    }
}
