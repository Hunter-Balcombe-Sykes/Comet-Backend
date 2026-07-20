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
        'selection_mode',
        'link_mode',
        'referral_query',
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

    /** @return HasMany<ShopProduct, $this> */
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
     * $productRanks is the PUBLIC-path popularity annotation: a map of
     * product HANDLE (the slug) → rank (content_popularity_scores,
     * content_type='shop_product' — the scoring pipeline keys shop products by
     * handle; see engines/scoring-id.ts LOCKSTEP).
     * When provided (even empty), each product gains a nullable `popularityRank`
     * (inert until ONE consumes it). When null — the DASHBOARD path
     * (ShopBrandResource) — the key is omitted entirely, keeping that shape
     * byte-identical. Product ORDER is never changed.
     *
     * @param  array<string, int>|null  $productRanks  product handle → rank (public path only)
     * @return array<string, mixed>
     */
    public function toBrandArray(?array $productRanks = null): array
    {
        $products = $productRanks === null
            ? $this->products->map->data->all()
            : $this->products->map(static function (ShopProduct $p) use ($productRanks): array {
                $data = is_array($p->data) ? $p->data : [];
                // Scores key shop products by their HANDLE slug (what the wire,
                // item beacons and click signals all carry) — never product_id.
                $data['popularityRank'] = $productRanks[(string) ($data['handle'] ?? '')] ?? null;

                return $data;
            })->all();

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
            // Pre-migration rows read null from SQLite (no column default
            // backfill) — coalesce to the mode defaults.
            'selectionMode' => $this->selection_mode ?? 'manual',
            'linkMode' => $this->link_mode ?? 'product',
            'referralQuery' => $this->referral_query ?? '',
            'products' => $products,
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
