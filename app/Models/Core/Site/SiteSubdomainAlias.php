<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Historical subdomain alias that serves 301 redirects after a site changes its subdomain.
// Lifecycle: GRACE (0–14d, owner-reclaimable) → REDIRECT (14–90d) → RELEASED (prune deletes row).
// Mirrors core.user_handle_aliases (UserHandleAlias) on the handle side.
class SiteSubdomainAlias extends BaseModel
{
    use HasUuids;

    protected $table = 'site.site_subdomain_aliases';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'subdomain',
        'created_at',
        'site_id',
        // Lifecycle columns — WITHOUT these in $fillable, UpdateSiteAction's
        // create([...]) silently drops them (BaseModel does not set $guarded = []),
        // so every alias would be born with NULL expiry and never get pruned.
        'reclaim_until',
        'expires_at',
        'notified_t3_at',
        'notified_t1_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'reclaim_until' => 'datetime',
        'expires_at' => 'datetime',
        'notified_t3_at' => 'datetime',
        'notified_t1_at' => 'datetime',
    ];

    // Active = no expiry set (legacy) OR not yet expired.
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    // Reclaimable = within the owner-only grace window.
    public function scopeReclaimable($query)
    {
        return $query->whereNotNull('reclaim_until')->where('reclaim_until', '>', now());
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
