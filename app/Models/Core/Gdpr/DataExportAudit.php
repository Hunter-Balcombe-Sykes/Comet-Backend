<?php

namespace App\Models\Core\Gdpr;

use App\Models\BaseModel;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Audit row for professional data exports (self-service + staff-triggered).
// Snapshot columns survive the professional's hard-delete (FK SET NULL).
class DataExportAudit extends BaseModel
{
    use HasUuids;

    public const TRIGGERED_BY_SELF = 'self';

    public const TRIGGERED_BY_STAFF = 'staff';

    public const SEND_TO_PROFESSIONAL = 'professional';

    public const SEND_TO_STAFF = 'staff';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    // #P3-18: delivery outcome of the export notification email. Stamped 'sent' on
    // handoff to the mailer; a future Resend webhook handler advances it to one of
    // the later states so support can tell a bounce from a genuine "never received".
    public const EMAIL_DELIVERY_SENT = 'sent';

    public const EMAIL_DELIVERY_DELIVERED = 'delivered';

    public const EMAIL_DELIVERY_BOUNCED = 'bounced';

    public const EMAIL_DELIVERY_COMPLAINT = 'complaint';

    protected $table = 'audit.data_export_audit';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // only created_at + completed_at; no updated_at

    protected $fillable = [
        'user_id',
        'professional_handle_snapshot',
        'professional_email_snapshot',
        'triggered_by',
        'triggered_by_staff_id',
        'recipient_email',
        'send_to',
        'status',
        'file_path',
        'file_size_bytes',
        'file_sha256',
        'record_counts',
        'error_message',
        'email_sent_at',
        'email_delivery_status',
    ];

    protected $casts = [
        'record_counts' => 'array',
        'file_size_bytes' => 'integer',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
        'email_sent_at' => 'datetime',
    ];

    // PII fields — never expose in API responses or job payloads
    protected $hidden = [
        'professional_email_snapshot',
        'recipient_email',
        'file_sha256',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $audit) {
            if (! $audit->status) {
                $audit->status = self::STATUS_QUEUED;
            }
            if (! $audit->created_at) {
                $audit->created_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function triggeringStaff(): BelongsTo
    {
        return $this->belongsTo(PartnaStaff::class, 'triggered_by_staff_id');
    }

    public function markProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markCompleted(
        string $filePath,
        int $fileSizeBytes,
        string $fileSha256,
        array $recordCounts,
    ): void {
        $this->completed_at = now();
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'file_path' => $filePath,
            'file_size_bytes' => $fileSizeBytes,
            'file_sha256' => $fileSha256,
            'record_counts' => $recordCounts,
        ]);
    }

    public function markEmailSent(): void
    {
        $this->forceFill([
            'email_sent_at' => now(),
            'email_delivery_status' => self::EMAIL_DELIVERY_SENT,
        ])->saveQuietly();
    }

    public function markFailed(string $error): void
    {
        $this->completed_at = now();
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => mb_substr($error, 0, 2000),
        ]);
    }
}
