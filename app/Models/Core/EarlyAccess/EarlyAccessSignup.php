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
class EarlyAccessSignup extends BaseModel
{
    use HasUuids;

    public const STATUS_WAITLIST = 'waitlist';

    public const STATUS_INVITED = 'invited';

    public const STATUS_SIGNED_UP = 'signed_up';

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

    protected $fillable = [
        'email',
        'email_lc',
        'type',
        'workplace_or_industry',
        'platforms',
        'status',
        'source',
        'invited_at',
        'invite_token_hash',
        'invite_meta',
        'invited_by',
        'signed_up_at',
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

    /** Locate a row by its plaintext invite token (sha256 at rest). */
    public static function findByInviteToken(string $token): ?self
    {
        if (trim($token) === '') {
            return null;
        }

        return self::query()
            ->where('invite_token_hash', hash('sha256', $token))
            ->first();
    }
}
