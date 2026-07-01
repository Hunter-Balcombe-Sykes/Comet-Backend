<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// One dish under a site.menu_categories row. Content (name / description /
// image) is UNIONED across every connected ordering platform: a dish
// on both Uber Eats and DoorDash becomes one row whose display fields gap-fill
// across platforms (UE wins a field only where present). Each platform the dish
// is available on is a site.menu_item_platforms row — per-mode pickup/delivery
// price + url for that platform's store link. `base_price` is the representative
// headline (min across platforms);
// `pickup_price` / `delivery_price` are aggregates (min among pickup-capable /
// delivery-capable platforms), each tagged with the platform backing the min
// (`*_source`) for back-compat. `rating` (👍 percent) + `rating_count` +
// `badges` are DoorDash-only — Uber Eats exposes none per item. Items are
// rebuilt wholesale on every scrape.
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
        'rating',
        'rating_count',
        'badges',
        'base_price',
        'pickup_price',
        'pickup_source',
        'delivery_price',
        'delivery_source',
        'dd_external_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'badges' => 'array',
        'rating' => 'float',
        'rating_count' => 'integer',
        'base_price' => 'float',
        'pickup_price' => 'float',
        'delivery_price' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /** Per-platform availability (one row per ordering platform, per-mode prices/urls). */
    public function platformLinks(): HasMany
    {
        return $this->hasMany(MenuItemPlatform::class, 'menu_item_id');
    }
}
