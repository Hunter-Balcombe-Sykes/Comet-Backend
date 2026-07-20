<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $menu_id
 * @property string $category_id
 * @property int $position
 * @property string $name
 * @property string|null $description
 * @property string|null $image_url Cross-filled: Uber Eats preferred, DoorDash fills the gap.
 * @property float|null $rating DoorDash 👍 percent, 0-100. Uber Eats exposes no per-item rating, so null there.
 * @property int|null $rating_count
 * @property array<int, array{text: string, type?: string}>|null $badges DoorDash-only badges, normalized by DoorDashMenuDriver::badges(); Uber Eats exposes none per item.
 * @property float|null $base_price Representative headline price (min across platforms).
 * @property float|null $pickup_price Min among pickup-capable platforms.
 * @property string|null $pickup_source Platform backing pickup_price. One of 'uber-eats'|'doordash'.
 * @property float|null $delivery_price Min among delivery-capable platforms.
 * @property string|null $delivery_source Platform backing delivery_price. One of 'uber-eats'|'doordash'.
 * @property string|null $dd_external_id Cross-platform identity for re-matching against DoorDash.
 * @property string|null $currency ISO 4217 code from the Uber Eats scrape (per item); null for DoorDash-only dishes.
 * @property array<int, string>|null $images Hero-first image URL set (images[0] = image_url); cross-platform union — no single platform exposes >1 item image today.
 * @property bool $is_manual Owner-authored/edited dish. Preserved across scrape rebuilds; a colliding scraped dish is skipped in its favour.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MenuCategory|null $category
 * @property-read Menu|null $menu
 * @property-read Collection<int, MenuItemPlatform> $platformLinks Per-platform availability (one row per ordering platform).
 */

// One dish under a site.menu_categories row. Content (name / description /
// image) is UNIONED across every connected ordering platform: a dish
// on both Uber Eats and DoorDash becomes one row whose display fields gap-fill
// across platforms (UE wins a field only where present). `images` is the full
// hero-first image set (images[0] = image_url) — cross-platform union, since no
// single platform exposes more than one image per item today. Each platform the dish
// is available on is a site.menu_item_platforms row — per-mode pickup/delivery
// price + url for that platform's store link. `base_price` is the representative
// headline (min across platforms);
// `pickup_price` / `delivery_price` are aggregates (min among pickup-capable /
// delivery-capable platforms), each tagged with the platform backing the min
// (`*_source`) for back-compat. `rating` (👍 percent) + `rating_count` +
// `badges` are DoorDash-only — Uber Eats exposes none per item. `currency`
// (ISO 4217, e.g. 'AUD') is Uber-Eats-only per item — DoorDash's actor carries
// no per-item currency field, so it stays null there. Items are
// rebuilt wholesale on every scrape.
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
        'category_id',
        'position',
        'name',
        'description',
        'image_url',
        'images',
        'rating',
        'rating_count',
        'badges',
        'base_price',
        'pickup_price',
        'pickup_source',
        'delivery_price',
        'delivery_source',
        'dd_external_id',
        'currency',
        // Owner-authored marker: created or edited via the dashboard. Preserved
        // across scrape rebuilds; a colliding scraped dish defers to it.
        'is_manual',
    ];

    protected $casts = [
        'position' => 'integer',
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

    /** @return BelongsTo<MenuCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /**
     * Per-platform availability (one row per ordering platform, per-mode prices/urls).
     *
     * @return HasMany<MenuItemPlatform, $this>
     */
    public function platformLinks(): HasMany
    {
        return $this->hasMany(MenuItemPlatform::class, 'menu_item_id');
    }
}
