<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

// A single integration-factor contribution to a site's design kit: one row per
// (factor source × target design-kit column), carrying the value + its priority
// + mode. The priority-merge of a site's rows is the "preset layer" resolved at
// read time (DesignPresetResolver) between package defaults and the user's
// manual design_kits values. System-managed — written only by the resolver on
// integration connect/refresh/disconnect; never user-authorized directly (no
// API surface), hence POLICY_EXEMPT in PolicyCoverageTest.
class DesignKitContribution extends BaseModel
{
    use HasUuids;

    protected $table = 'site.design_kit_contributions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'site_id',
        'source',
        'integration',
        'priority',
        'mode',
        'target_var',
        'value',
    ];

    protected $casts = [
        'priority' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
