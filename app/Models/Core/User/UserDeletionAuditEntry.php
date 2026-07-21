<?php

namespace App\Models\Core\User;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Audit trail for professional account deletion lifecycle. Rows survive the
// professional's hard delete via handle/email snapshots captured at write time.
class UserDeletionAuditEntry extends BaseModel
{
    use HasUuids;

    protected $table = 'audit.user_deletion_audit';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // only created_at; no updated_at

    public const EVENT_REQUESTED = 'requested';

    public const EVENT_CONFIRMED = 'confirmed';

    public const EVENT_CANCELLED = 'cancelled';

    public const EVENT_PURGED = 'purged';

    public const EVENT_PURGE_FAILED = 'purge_failed';

    public const EVENT_ADMIN_INITIATED = 'admin_initiated';

    public const EVENT_ADMIN_CANCELLED = 'admin_cancelled';

    public const ACTOR_TYPE_PROFESSIONAL = 'professional';

    public const ACTOR_TYPE_STAFF_ADMIN = 'staff_admin';

    public const ACTOR_TYPE_SYSTEM = 'system';

    // SEC-3: user_id, actor_id, ip_address, professional_email_snapshot, and
    // actor_handle_snapshot are server-managed — excluded from mass-assignment
    // on this append-only audit table. professional_email_snapshot is NOT NULL
    // on Postgres, so a silent drop here would 23502 instead of just no-opping.
    // Writers use forceCreate() to set them.
    protected $fillable = [
        'professional_handle_snapshot',
        'event',
        'actor_type',
        'reason',
        'user_agent',
        'metadata',
    ];

    // PII fields — never expose in serialisation (API responses, logs, job payloads)
    protected $hidden = [
        'professional_email_snapshot',
        'actor_handle_snapshot',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (! $entry->created_at) {
                $entry->created_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
