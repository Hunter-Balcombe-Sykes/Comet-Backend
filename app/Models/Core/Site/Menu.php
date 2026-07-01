<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// A user's fetched food-ordering menu — the single source of truth for menu
// CONTENT, now relational: this row holds store-level display + provenance,
// while the dishes live in site.menu_categories → site.menu_items.
//
// `content_source` records which platform the canonical structure came from
// (Uber Eats preferred, DoorDash fallback). `pickup_platform` / `delivery_platform`
// record which platform priced each mode (from the Google-harvested type on the
// user's online-ordering links) — every item then carries a pickup_price and a
// delivery_price from those platforms. Each delivery platform's store URL,
// last-sync timestamp, and status live in site.menu_platform_links (one row per
// platform), tracking each scrape independently. Per-item order LINKS are
// still computed at read time from the live ordering entries (MenuSource), never
// stored. Dashboard-only — never exposed on the public sitepage.
class Menu extends BaseModel
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'site.menus';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'content_source',
        'store_name',
        'logo_url',
        'rating',
        'review_count',
        'currency',
        'pickup_platform',
        'delivery_platform',
        'fetch_status',
        'last_fetched_at',
    ];

    protected $casts = [
        'rating' => 'float',
        'review_count' => 'integer',
        'last_fetched_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Ordered categories for this menu. */
    public function categories(): HasMany
    {
        return $this->hasMany(MenuCategory::class, 'menu_id')->orderBy('position');
    }

    /** All dishes across every category (denormalized menu_id for direct access). */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'menu_id');
    }

    /** Per-platform sync state (one row per delivery platform). */
    public function platformLinks(): HasMany
    {
        return $this->hasMany(MenuPlatformLink::class, 'menu_id');
    }
}
