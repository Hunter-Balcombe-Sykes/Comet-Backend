<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

// Smart Link: a URL-fetched rich link card on a sitepage. Typed by `type`
// (namespaced, e.g. 'commerce.product', 'content.video') within two families
// (commerce / content). Snapshot columns are filled by SmartLinkResolver;
// `metadata` holds type-specific extras. Modelled on Service — distinct
// sitepage entity with its own table + observer + public-payload resolver.
class SmartLink extends BaseModel
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'site.smart_links';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'site_id',
        'family',
        'type',
        'platform',
        'canonical_url',
        'tracking_query',
        'discount_code',
        'discount_kind',
        'discount_value',
        'title',
        'image_url',
        'favicon_url',
        'brand_name',
        'brand_logo_url',
        'metadata',
        'image_sources',
        'sort_order',
        'is_active',
        'last_refreshed_at',
        'last_refresh_status',
        'consecutive_failures',
    ];

    protected $casts = [
        'metadata' => 'array',
        'image_sources' => 'array',
        'discount_value' => 'float',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'consecutive_failures' => 'integer',
        'last_refreshed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
