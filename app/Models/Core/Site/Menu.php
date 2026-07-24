<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $content_source
 * @property string|null $store_name
 * @property string|null $logo_url
 * @property float|null $rating
 * @property int|null $review_count
 * @property string|null $currency
 * @property string|null $pickup_platform
 * @property string|null $delivery_platform
 * @property string|null $fetch_status
 * @property array|null $dining_modes
 * @property array|null $scan_items
 * @property array|null $suppressed_items
 * @property Carbon|null $last_fetched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read Collection<int, MenuCategory> $categories
 * @property-read Collection<int, MenuItem> $items
 * @property-read Collection<int, MenuPlatformLink> $platformLinks
 */
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
// platform), tracking each scrape independently. `dining_modes` is the store's
// supported dining modes (e.g. ["DELIVERY","PICKUP"]) from the Uber Eats scrape
// — display-only, null when unavailable (DoorDash exposes none). Per-item order
// LINKS are still computed at read time from the live ordering entries
// (MenuSource), never stored. Served both on the authenticated dashboard
// (MenuController) AND publicly on the sitepage (PublicMenuController, gated on
// last_fetched_at being set + the owner's Google Business menu display toggle).
// Content isn't only scraped either — menu_categories.source_platform can be
// 'scan' for items a user added by scanning a photo/PDF menu (MenuScanApplier);
// those rows are exempt from MenuFetchJob's wholesale scrape rebuild.
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
        'dining_modes',
        'last_fetched_at',
        'scan_items',
        'suppressed_items',
    ];

    protected $casts = [
        'rating' => 'float',
        'review_count' => 'integer',
        'dining_modes' => 'array',
        'scan_items' => 'array',
        // {category, name} scraped dishes the owner deleted — the scrape rebuild
        // consults this so a suppressed dish is never resurrected.
        'suppressed_items' => 'array',
        'last_fetched_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Ordered categories for this menu.
     *
     * @return HasMany<MenuCategory, $this>
     */
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
