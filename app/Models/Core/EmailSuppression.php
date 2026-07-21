<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Carbon;

/**
 * A suppressed recipient address. The send-time MessageSending gate
 * (App\Listeners\BlockSuppressedRecipients) cancels any outbound mail whose
 * recipient hashes to a row here.
 *
 * One row per address (UNIQUE email_hash). Email is stored ONLY as a SHA256 HMAC
 * via App\Support\EmailHasher — never plaintext. Internal system table: no
 * user_id, no API surface. Staff read via DB-level RLS (see the migration);
 * POLICY_EXEMPT in PolicyCoverageTest for the same reason as SupabaseEmailEvent.
 *
 * @property string $id
 * @property string $email_hash SHA256 HMAC of the recipient email (app.key pepper).
 * @property string $reason hard_bounce | complaint | manual
 * @property string|null $source e.g. 'resend' | 'manual'
 * @property string|null $detail Non-PII bounce subtype (e.g. 'Suppressed').
 * @property Carbon|null $first_seen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EmailSuppression extends BaseModel
{
    use HasUuids;

    protected $table = 'core.email_suppressions';

    public $incrementing = false;

    protected $keyType = 'string';

    // email_hash is hashed PII; never serialise it even though this model has
    // no Resource class (defence-in-depth, mirrors SupabaseEmailEvent).
    protected $hidden = [
        'email_hash',
    ];

    protected $fillable = [
        'email_hash',
        'reason',
        'source',
        'detail',
        'first_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Reason constants — keep in sync with the CHECK constraint in migration
    // 20260721190000_create_email_suppressions.sql.
    public const REASON_HARD_BOUNCE = 'hard_bounce';

    public const REASON_COMPLAINT = 'complaint';

    public const REASON_MANUAL = 'manual';
}
