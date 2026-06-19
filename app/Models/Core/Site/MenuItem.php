<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// One dish under a site.menu_categories row. Content (name / description /
// image / modifiers) comes from the content-source platform, with the image
// cross-filled from the matched item on the other platform when missing.
// `base_price` is the headline price; `pickup_price` / `delivery_price` are the
// per-mode prices, each sourced from the platform the user mapped to that mode
// (`*_source`). `rating` (👍 percent) + `rating_count` + `badges` are DoorDash-
// only — Uber Eats exposes none of them per item. `modifiers` are Uber Eats
// option groups. Items are rebuilt wholesale on every scrape (no soft delete).
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
        'modifiers',
        'is_sold_out',
        'rating',
        'rating_count',
        'badges',
        'base_price',
        'pickup_price',
        'pickup_source',
        'delivery_price',
        'delivery_source',
        'ue_external_id',
        'dd_external_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'modifiers' => 'array',
        'badges' => 'array',
        'is_sold_out' => 'boolean',
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
}
