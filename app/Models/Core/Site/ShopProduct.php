<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $brand_id
 * @property string $product_id Provider-scoped product id (Shopify's numeric id, etc.) — NOT the uuid PK.
 * @property int $position
 * @property array<string, mixed> $data Upstream-shaped product object verbatim (carries an absolute `url`, `productId`, `handle`, etc.) — the same object that used to live inline in the brand's JSONB `products` array. Rebuilt wholesale (delete + reinsert) on every selection save, so every key here is whatever the current writer (ShopFetch/ShopController::setProducts) puts in, not a fixed historical shape.
 * @property Carbon|null $created_at Nullable in Postgres (DEFAULT now(), no NOT NULL).
 * @property Carbon|null $updated_at Nullable in Postgres, same as created_at above.
 */
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
