<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// FOUND-25: one connected store under a user's 'shop' IntegrationConnection.
// Replaces the brand-keyed JSONB map that used to live in
// site.platform_connections.payload — each brand is now its own row, with its
// chosen products as child ShopProduct rows. The reserved 'individual' bucket
// (products added without a parent store) is also a row here, flagged by
// is_individual.
class ShopBrand extends BaseModel
{
    use HasUuids;

    protected $table = 'site.shop_brands';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'connection_id',
        'brand_id',
        'provider',
        'url',
        'source_url',
        'name',
        'currency',
        'favicon',
        'logo',
        'discount_code',
        'fetch_mode',
        'is_individual',
        'position',
        'style_analysis',
    ];

    protected $casts = [
        'is_individual' => 'boolean',
        'position' => 'integer',
        // Internal-only: AnalyzeConnectionWebsitesJob writes it, OutsideWebsitesFactor
        // reads it. Deliberately NOT surfaced by toBrandArray() below — it must never
        // leak into the public/dashboard shop payload.
        'style_analysis' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(ShopProduct::class, 'brand_id')->orderBy('position');
    }

    /**
     * Rehydrate the wire/internal brand shape ShopBrandResource and the public
     * endpoint's allowlist both consume — mirrors the pre-FOUND-25 stored
     * payload object verbatim except `fetchMode`/`individual` are only present
     * when actually set (matches the old array's optional-key behaviour).
     *
     * @return array<string, mixed>
     */
    public function toBrandArray(): array
    {
        $brand = [
            'id' => $this->brand_id,
            'provider' => $this->provider,
            'url' => $this->url,
            'sourceUrl' => $this->source_url,
            'name' => $this->name,
            'currency' => $this->currency,
            'favicon' => $this->favicon,
            'logo' => $this->logo,
            'discountCode' => $this->discount_code ?? '',
            'products' => $this->products->map->data->all(),
        ];

        if ($this->fetch_mode !== null) {
            $brand['fetchMode'] = $this->fetch_mode;
        }
        if ($this->is_individual) {
            $brand['individual'] = true;
        }

        return $brand;
    }
}
