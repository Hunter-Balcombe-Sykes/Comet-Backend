<?php

namespace App\Models\Content;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Slice 5a §3.1: per-store behaviour hanging off a content.collections row.
 * Not an item — it has no content.items row and never reaches PoolResolver.
 *
 * @property string $collection_id PK, FK -> content.collections.id.
 * @property string $provider
 * @property string|null $url
 * @property string|null $source_url
 * @property string|null $currency
 * @property string|null $discount_code
 * @property string $referral_query Affiliate revenue — see spec §3.7.
 * @property bool $is_individual
 * @property string|null $fetch_mode
 * @property string|null $connect_status
 * @property string|null $connect_error
 * @property Carbon|null $products_curated_at
 * @property string|null $logo_url
 * @property string|null $favicon_url
 * @property string|null $logo_mark_url
 * @property string|null $logo_mark_svg_url
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection|null $collection
 */
class Storefront extends BaseModel
{
    protected $table = 'content.storefronts';

    protected $primaryKey = 'collection_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'provider', 'url', 'source_url', 'currency', 'discount_code',
        'referral_query', 'is_individual', 'fetch_mode', 'connect_status',
        'connect_error', 'products_curated_at', 'logo_url', 'favicon_url',
        'logo_mark_url', 'logo_mark_svg_url',
    ];

    protected function casts(): array
    {
        return [
            'is_individual' => 'boolean',
            'products_curated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class, 'collection_id');
    }
}
