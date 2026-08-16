<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

// One dish's availability on a single ordering platform — replaces the
// menu_items.platforms JSONB array. Per-mode: pickup_price + pickup_url,
// delivery_price + delivery_url. A mode the store doesn't offer is null on
// both. One row per (menu_item, platform); rebuilt wholesale every scrape.
/**
 * @property string $id
 * @property string $menu_item_id
 * @property string $platform Ordering platform slug, e.g. 'uber_eats'|'doordash'.
 * @property float|null $pickup_price
 * @property string|null $pickup_url
 * @property float|null $delivery_price
 * @property string|null $delivery_url
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
        'pickup_url',
        'delivery_price',
        'delivery_url',
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
