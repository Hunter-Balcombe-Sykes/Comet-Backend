<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

// One dish's availability on a single ordering platform — WIRE SHAPE ONLY
// since slice 7 (the table is dropped; ManualMenuItems::toMenuItemModel
// pre-sets these from content.offers). Per-mode prices + the dish's own
// identity on that platform: item_url is the verified per-item deep link
// (never a store fallback — owner ruling D1, 2026-08-26 replaced the
// per-dish mode store urls), external_ref the platform's item id, sold_out
// its stock signal. Rebuilt wholesale every scrape.
/**
 * @property string $id
 * @property string $menu_item_id
 * @property string $platform Ordering platform slug, e.g. 'uber-eats'|'doordash'|'square'.
 * @property float|null $pickup_price
 * @property float|null $delivery_price
 * @property string|null $item_url Per-item deep link on this platform, or null.
 * @property string|null $external_ref The platform's own item id.
 * @property bool|null $sold_out Platform stock signal; null = not exposed.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MenuItem|null $menuItem
 */
class MenuItemPlatform extends BaseModel
{
    use HasUuids;

    protected $table = 'site.menu_item_platforms';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'menu_item_id',
        'platform',
        'pickup_price',
        'delivery_price',
        'item_url',
        'external_ref',
        'sold_out',
    ];

    protected $casts = [
        'pickup_price' => 'float',
        'delivery_price' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
