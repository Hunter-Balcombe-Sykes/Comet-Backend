<?php

namespace App\Models\Core\Notifications;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Carbon;

/**
 * WHK-3: Forensic trail row for a single Supabase auth-email webhook delivery.
 *
 * One row per webhook_id (unique). Status transitions: queued → (stays queued on
 * success) | failed | unhandled. No token column — WHK-4 (replay) is deferred.
 *
 * @property string $id
 * @property string $webhook_id
 * @property string|null $request_id
 * @property string $action_type
 * @property string|null $recipient_email_hash
 * @property array $raw_payload Redacted — no tokens, no plaintext email.
 * @property string $status queued | failed | unhandled
 * @property string|null $error
 * @property Carbon|null $queued_at
 * @property Carbon|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SupabaseEmailEvent extends BaseModel
{
    use HasUuids;

    protected $table = 'core.supabase_email_events';

    public $incrementing = false;

    protected $keyType = 'string';

    // Token columns and hashed PII are never serialised to API responses;
    // hidden here as defence-in-depth even though this model has no Resource class.
    protected $hidden = [
        'recipient_email_hash',
        'raw_payload',
    ];

    protected $fillable = [
        'webhook_id',
        'request_id',
        'action_type',
        'recipient_email_hash',
        'raw_payload',
        'status',
        'error',
        'queued_at',
        'failed_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'queued_at' => 'datetime',
        'failed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Status constants — keep in sync with the CHECK constraint in the migration.
    public const STATUS_QUEUED = 'queued';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNHANDLED = 'unhandled';
    // WHK-4 (deferred): add STATUS_REPLAYED = 'replayed' when token retention is approved.
}
