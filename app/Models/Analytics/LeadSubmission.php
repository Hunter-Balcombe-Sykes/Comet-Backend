<?php

namespace App\Models\Analytics;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

// V2: Tracks lead form submissions on public sites. Records outcome, timing, and visitor fingerprint for conversion analytics.
class LeadSubmission extends BaseModel
{
    use HasUuids;

    protected $table = 'analytics.lead_submissions';

    public $incrementing = false;

    protected $keyType = 'string';

    // analytics tables don't have updated_at
    public $timestamps = false;

    protected $fillable = [
        'occurred_at',
        'subdomain',
        'site_id',
        'user_id',
        'customer_id',
        'ip_hash',
        'user_agent',
        'referrer',
        'outcome',
        'form_started_at_ms',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];
}
