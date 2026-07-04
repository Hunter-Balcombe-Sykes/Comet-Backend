<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// FOUND-25: one chosen product under a ShopBrand. `data` carries the
// upstream-shaped product object verbatim (each carries an absolute `url`) —
// the same object that used to live inline in the brand's JSONB `products`
// array. Rebuilt wholesale (delete + reinsert) on every selection save.
class ShopProduct extends BaseModel
{
    use HasUuids;

    protected $table = 'site.shop_products';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'brand_id',
        'product_id',
        'position',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
        'position' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ShopBrand::class, 'brand_id');
    }
}
