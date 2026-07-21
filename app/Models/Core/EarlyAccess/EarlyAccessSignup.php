<?php

namespace App\Models\Core\EarlyAccess;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

// OV-A: early-access signup lifecycle row (waitlist → invited → signed_up).
// Created by the public marketing endpoint (source=marketing) or staff manual
// entry (source=manual). invite_meta carries manual-invite prefills
// ({integrations:{instagram, other[]}, architecture:{...}}).
//
// PII posture mirrors WaitlistSignup: email + consent telemetry + the invite
// token hash are hidden from default serialization; staff endpoints expose
// email deliberately through EarlyAccessSignupResource.
/**
 * @property string $id
 * @property string $email
 * @property string $email_lc
 * @property string $type
 * @property string|null $workplace_or_industry
 * @property array<int, string> $platforms
 * @property string $status
 * @property string $source
 * @property \Illuminate\Support\Carbon|null $invited_at
 * @property string|null $invite_token_hash
 * @property array<string, mixed>|null $invite_meta
 * @property string|null $invited_by
 * @property \Illuminate\Support\Carbon|null $signed_up_at
 * @property string|null $consent_ip_hash
 * @property string|null $consent_user_agent
 * @property string|null $source_type
 * @property string|null $source_ref
 * @property string|null $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class EarlyAccessSignup extends BaseModel
{
    use HasUuids;

    public const STATUS_WAITLIST = 'waitlist';

    public const STATUS_INVITED = 'invited';

    public const STATUS_SIGNED_UP = 'signed_up';

    /**
     * Invite tokens die this many days after they were minted (invited_at). A
     * leaked finish-signup link must not grant PII (resolveInvite) or waitlist
     * bypass (bootstrap) indefinitely — both paths resolve through
     * findByInviteToken(), so the age gate there covers both surfaces.
     */
    public const INVITE_TTL_DAYS = 14;

    protected $table = 'core.early_access_signups';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = [
        'email',
        'email_lc',
        'invite_token_hash',
        'consent_ip_hash',
        'consent_user_agent',
    ];

    // invited_at/invite_token_hash/invite_meta/invited_by/signed_up_at (S4 Tier 2b)
    // stay OUT of $fillable — invite-lifecycle fields written only by trusted
    // callers (EarlyAccessService::invite(), ApproveEarlyAccessBuildJob) via
    // forceFill. `status` is kept fillable on merge with the signup-flows feature:
    // no writer mass-assigns it (every status write is direct assignment or
    // forceFill, and StaffEarlyAccessUpdateRequest excludes it), so it carries no
    // request-bound exposure. source_type/source_ref are that feature's
    // build-provenance columns.
    protected $fillable = [
        'email',
        'email_lc',
        'type',
        'workplace_or_industry',
        'platforms',
        'source_type',
        'source_ref',
        'status',
        'source',
        'consent_ip_hash',
        'consent_user_agent',
    ];

    protected $casts = [
        'platforms' => 'array',
        'invite_meta' => 'array',
        'invited_at' => 'datetime',
        'signed_up_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Locate a row by its plaintext invite token (sha256 at rest). Returns null
     * for an expired token (older than INVITE_TTL_DAYS) so a leaked link stops
     * resolving to PII or a waitlist bypass — the single gate for both the
     * bootstrap and resolveInvite callers.
     */
    public static function findByInviteToken(string $token): ?self
    {
        if (trim($token) === '') {
            return null;
        }

        $signup = self::query()
            ->where('invite_token_hash', hash('sha256', $token))
            ->first();

        if ($signup === null) {
            return null;
        }

        // Token still hashes to a row, but the invite was minted too long ago —
        // treat as not-found. A null invited_at (hash present without a mint
        // timestamp) is unexpected and fails closed.
        if ($signup->invited_at === null || $signup->invited_at->lt(now()->subDays(self::INVITE_TTL_DAYS))) {
            return null;
        }

        return $signup;
    }
}
