<?php

namespace App\Models\Analytics;

use App\Models\BaseModel;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Item-level impression events (analytics v2, popularity scoring). Fired by the
// storefront IntersectionObserver per scored item and written by the item-seen
// telemetry endpoint; read by analytics:compute-popularity.
//
// Mirrors analytics.section_views' visitor/session/geo columns but swaps the
// section_key grain for item_type (scored-item taxonomy) + item_id (product/item
// id) + an optional item_title. Dedup is app-side Redis (AnalyticsDedupGuard,
// 300s), not a DB column — same pattern as section-seen.
class ItemView extends BaseModel
{
    use HasFactory, HasUuids;

    protected $table = 'analytics.item_views';

    public $incrementing = false;

    protected $keyType = 'string';

    // analytics tables don't have updated_at
    public $timestamps = false;

    protected $fillable = [
        'item_type',
        'item_id',
        'item_title',
        'section_key',
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
