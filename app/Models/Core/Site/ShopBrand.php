<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $connection_id
 * @property string $brand_id Provider-scoped store key (the Shopify shop domain, etc.) — NOT the uuid PK; unique per connection.
 * @property string $provider
 * @property string|null $url
 * @property string|null $source_url
 * @property string|null $name
 * @property string|null $currency
 * @property string|null $favicon
 * @property string|null $logo
 * @property string|null $logo_mark_url Processed (background-removed) PNG mark — ProcessShopBrandLogoJob's output; NULL until it lands.
 * @property string|null $logo_mark_svg_url Vectorized SVG mark from the same run; NULL when the processor returned no vector.
 * @property string|null $discount_code
 * @property string|null $fetch_mode
 * @property string|null $connect_status One of 'pending'|'failed', or NULL once settled (shop_brands_connect_status_check).
 * @property string|null $connect_error
 * @property bool $is_individual
 * @property int $position
 * @property array<string, mixed>|null $style_analysis Internal design-preset input (OutsideWebsitesFactor) — never surfaced by toBrandArray().
 * @property string|null $selection_mode NOT NULL in Postgres (default 'manual'), but pre-migration rows and the SQLite test mirror read NULL — toBrandArray() coalesces.
 * @property string|null $link_mode Same NOT-NULL-in-Postgres/nullable-in-tests story as $selection_mode (default 'product').
 * @property string|null $referral_query Same story as $selection_mode (default '').
 * @property Carbon|null $products_curated_at #SEM-1: NULL = no evidence of human curation (ShopFetch's scheduled resync tracks this brand's newest products); non-NULL = the user hand-picked this brand's products at this instant (ShopController::setProducts) and ShopFetch skips it until selectionMode=latest clears it back to NULL. NOT selection_mode — see ShopFetch's docblock for why that column can't carry this fact.
 * @property Carbon|null $created_at Nullable in Postgres (DEFAULT now(), no NOT NULL) — same as IntegrationConnection.
 * @property Carbon|null $updated_at Nullable in Postgres, same as created_at above.
 */
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
        'logo_mark_url',
        'logo_mark_svg_url',
        'discount_code',
        'fetch_mode',
        'connect_status',
        'connect_error',
        'is_individual',
        'position',
        'style_analysis',
        'selection_mode',
        'link_mode',
        'referral_query',
        'products_curated_at',
    ];

    // App-side vocabulary source of truth for connect_status. NULL = settled;
    // this constant is the pending/failed subset a row can transition through
    // while ShopBrandConnectJob is in flight. Kept in lockstep with the
    // migration's CHECK by ConstraintVocabularyLockstepTest.
    public const CONNECT_STATUSES = ['pending', 'failed'];

    /**
     * The reserved brand id for individually-added products — a bucket, not a
     * connected store, so it never occupies a store slot. Lifted off
     * ShopController (where it was a private const) in convergence Phase 6:
     * ShopConnections needs it to route that bucket to its own anchor
     * (`partna.manual_product`), and two copies of a reserved id is how the two
     * lanes would drift apart.
     */
    public const INDIVIDUAL_BRAND_ID = 'individual';

    protected $casts = [
        'is_individual' => 'boolean',
        'position' => 'integer',
        // Internal-only: AnalyzeConnectionWebsitesJob writes it, OutsideWebsitesFactor
        // reads it. Deliberately NOT surfaced by toBrandArray() below — it must never
        // leak into the public/dashboard shop payload.
        'style_analysis' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'products_curated_at' => 'datetime',
    ];

    /** @return BelongsTo<IntegrationConnection, $this> */
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
        // W9: only present during/after a deferred connect (ShopBrandConnectJob
        // clears both back to null on settle) — omitted for the overwhelming
        // majority of (already-settled) rows so this dark-merges byte-identical
        // with the pre-W9 shape (IntegrationContractGoldenMasterTest).
        // Processed marks: conditional like the W9 keys, so settled rows that
        // were never processed stay byte-identical with the historical shape.
        if ($this->logo_mark_url !== null) {
            $brand['logoMark'] = $this->logo_mark_url;
        }
        if ($this->logo_mark_svg_url !== null) {
            $brand['logoMarkSvg'] = $this->logo_mark_svg_url;
        }
        if ($this->connect_status !== null) {
            $brand['connectStatus'] = $this->connect_status;
        }
        if ($this->connect_error !== null) {
            $brand['connectError'] = $this->connect_error;
        }
        // #SEM-1: present only for a curated brand — mirrors the connectStatus/
        // connectError W9 idiom above, so a non-curated (the overwhelming
        // majority) brand's body stays byte-identical
        // (IntegrationContractGoldenMasterTest dark-merges unchanged).
        if ($this->products_curated_at !== null) {
            $brand['productsCuratedAt'] = $this->products_curated_at->toIso8601String();
        }

        return $brand;
    }
}
