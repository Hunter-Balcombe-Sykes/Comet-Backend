<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $menu_id
 * @property string $name
 * @property string|null $description
 * @property string|null $image_url Cross-filled: Uber Eats preferred, DoorDash fills the gap.
 * @property float|null $rating DoorDash 👍 percent, 0-100. Uber Eats exposes no per-item rating, so null there.
 * @property int|null $rating_count
 * @property array<int, array{text: string, type?: string}>|null $badges DoorDash-only badges, normalized by DoorDashMenuDriver::badges(); Uber Eats exposes none per item.
 * @property float|null $base_price Representative headline price (min across platforms).
 * @property float|null $pickup_price Min among pickup-capable platforms.
 * @property float|null $delivery_price Min among delivery-capable platforms.
 * @property string|null $currency ISO 4217 code from the Uber Eats scrape (per item); null for DoorDash-only dishes.
 * @property array<int, string>|null $images Hero-first image URL set (images[0] = image_url); cross-platform union — no single platform exposes >1 item image today.
 * @property bool $is_manual Owner-authored/edited dish. Preserved across scrape rebuilds; a colliding scraped dish is skipped in its favour.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MenuCategory> $categories Every category this dish belongs to (pivot carries per-membership position).
 * @property-read Menu|null $menu
 * @property-read Collection<int, MenuItemPlatform> $platformLinks Per-platform availability (one row per ordering platform).
 */

// WIRE SHAPE ONLY — the tables this described are dropped (slice 7); real
// storage is content.* and ManualMenuItems::toMenuItemModel() pre-sets these
// unsaved models from it. One dish, one or more category memberships.
// Content (name / description / image) is UNIONED across every connected
// ordering platform: display fields gap-fill in registry priority order
// (Square > Uber Eats > DoorDash). `images` is the hero-first cross-platform
// union (images[0] = image_url). Each platform the dish is available on is a
// platformLinks entry — per-mode prices plus the dish's own identity there
// (item_url deep link / external_ref / sold_out; the per-dish mode STORE
// urls retired 2026-08-26, D1). `base_price` is the representative headline
// (min across platforms); `pickup_price` / `delivery_price` are aggregate
// minimums. `rating` + `rating_count` + `badges` are DoorDash-only.
// `currency` is per-item where a platform supplies it. Items are rebuilt
// wholesale on every scrape.
// `badges` stays JSONB by design (reviewed 2026-07-04, audit #FOUND-13) — DoorDash
// display copy with no query/filter usage anywhere in the codebase; revisit only
// if a real filtering need emerges.
class MenuItem extends BaseModel
{
    use HasUuids;

    protected $table = 'site.menu_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'menu_id',
        'name',
        'description',
        'image_url',
        'images',
        'rating',
        'rating_count',
        'badges',
        'base_price',
        'pickup_price',
        'delivery_price',
        'currency',
        // Owner-authored marker: created or edited via the dashboard. Preserved
        // across scrape rebuilds; a colliding scraped dish defers to it.
        'is_manual',
    ];

    protected $casts = [
        'badges' => 'array',
        'images' => 'array',
        'rating' => 'float',
        'rating_count' => 'integer',
        'base_price' => 'float',
        'pickup_price' => 'float',
        'delivery_price' => 'float',
        'is_manual' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Every category this dish is listed under. The pivot's `position` orders
     * the dish within EACH category independently.
     *
     * READ ONLY as a pre-set property. `ManualMenuItems::toMenuItemModel()`
     * setRelation()s this from content.*; the pivot `site.menu_item_categories`
     * was dropped by slice 7, so `->categories()`, `with('categories')` or any
     * other form that actually queries is a guaranteed 42P01 on Postgres.
     *
     * @return BelongsToMany<MenuCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(MenuCategory::class, 'site.menu_item_categories', 'menu_item_id', 'menu_category_id')
            ->withPivot('position')
            ->withTimestamps();
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /**
     * Per-platform availability (one row per ordering platform, per-mode prices/urls).
     *
     * READ ONLY as a pre-set property — same rule as categories() above.
     * `ManualMenuItems::toMenuItemModel()` setRelation()s this from content.*;
     * `site.menu_item_platforms` is dropped, so any real query is a 42P01.
     *
     * @return HasMany<MenuItemPlatform, $this>
     */
    public function platformLinks(): HasMany
    {
        return $this->hasMany(MenuItemPlatform::class, 'menu_item_id');
    }
}
