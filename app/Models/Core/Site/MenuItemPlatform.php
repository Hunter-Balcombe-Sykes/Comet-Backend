<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One dish's availability on a single ordering platform — replaces the
// menu_items.platforms JSONB array. Per-mode: pickup_price + pickup_url,
// delivery_price + delivery_url. A mode the store doesn't offer is null on
// both. One row per (menu_item, platform); rebuilt wholesale every scrape.
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
