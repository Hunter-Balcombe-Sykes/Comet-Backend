<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// V2: Audit trail for professional account deletion lifecycle. Rows survive the
// professional's hard delete via handle/email snapshots captured at write time.
class ProfessionalDeletionAuditEntry extends BaseModel
{
    use HasUuids;

    protected $table = 'core.professional_deletion_audit';

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

    protected $fillable = [
        'professional_id',
        'professional_handle_snapshot',
        'professional_email_snapshot',
        'event',
        'actor_type',
        'actor_id',
        'actor_handle_snapshot',
        'reason',
        'ip_address',
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

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
