<?php

namespace App\Models\Analytics;

use App\Models\BaseModel;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Exposure/tap events for the unified actions system (2026-07-23 rebuild).
// Fired by the sitepage's action-surface IntersectionObserver (seen) and
// capture-phase click listener (tap); read by ActionScorer for
// demand-rate scoring. Mirrors App\Models\Analytics\ItemView exactly —
// dedup is app-side Redis (AnalyticsDedupGuard, 300s), not a DB column.
class ActionEvent extends BaseModel
{
    use HasFactory, HasUuids;

    protected $table = 'analytics.action_events';

    public $incrementing = false;

    protected $keyType = 'string';

    // analytics tables don't have updated_at
    public $timestamps = false;

    protected $fillable = [
        'action_id',
        'event',
        'occurred_at',
        'session_id',
        'visitor_id',
        'ip_hash',
        'user_agent',
        'referrer',
        'country_code',
        'device_type',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
