<?php

namespace App\Models\Core\Waitlist;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

// V2: Pre-launch waitlist entry. Captures applicant type, industry, team size, and consent metadata for pilot program outreach.
class WaitlistSignup extends BaseModel
{
    use HasUuids;

    protected $table = 'core.waitlist_signups';

    public $incrementing = false;

    protected $keyType = 'string';

    // PII + consent telemetry hidden from default model serialization. Controllers / Resources
    // hand-pick fields explicitly when responses need to include them; this guards against
    // accidental exposure via toArray() in queue payloads, broadcast events, or future endpoints.
    // Mirrors the BrandAffiliateInvite deny-by-default posture.
    protected $hidden = [
        'name',
        'email',
        'email_lc',
        'phone',
        'consent_ip_hash',
        'consent_user_agent',
    ];

    protected $fillable = [
        'name',
        'email',
        'email_lc',
        'phone',
        'applicant_type',
        'applicant_type_other',
        'industry',
        'industry_other',
        'pilot_program_opt_in',
        'number_of_team_members',
        'consent_source',
        'consent_ip_hash',
        'consent_user_agent',
        'last_submitted_at',
    ];

    protected $casts = [
        'pilot_program_opt_in' => 'boolean',
        'number_of_team_members' => 'integer',
        'last_submitted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
