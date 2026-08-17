<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Services\Shop\StoreRecord;
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
 * @property array<string, mixed>|null $style_analysis Internal design-preset input (OutsideWebsitesFactor) — never surfaced on the wire.
 * @property string|null $selection_mode NOT NULL in Postgres (default 'manual'), but pre-migration rows and the SQLite test mirror read NULL. Dead since slice 5a — ShopContentReader emits the constant 'manual'.
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
        // reads it. Deliberately NOT surfaced on the wire — it must never
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
     * Slice 7 Task 24: this row as the data ShopContentWriter writes.
     *
     * The adapter lives HERE, on the doomed model, rather than as a
     * `StoreRecord::fromBrand()` — so `App\Services\Shop\` carries no
     * reference to `site.shop_brands` at all, and dropping the table is a
     * matter of deleting this class (Task 27, step 5) plus its remaining
     * callers, not of untangling the writer again.
     */
    public function toStoreRecord(): StoreRecord
    {
        return new StoreRecord(
            externalRef: (string) $this->brand_id,
            provider: (string) $this->provider,
            name: $this->name,
            position: (int) $this->position,
            url: $this->url,
            sourceUrl: $this->source_url,
            currency: $this->currency,
            discountCode: $this->discount_code,
            referralQuery: (string) ($this->referral_query ?? ''),
            isIndividual: (bool) $this->is_individual,
            fetchMode: $this->fetch_mode,
            connectStatus: $this->connect_status,
            connectError: $this->connect_error,
            logoUrl: $this->logo,
            faviconUrl: $this->favicon,
            logoMarkUrl: $this->logo_mark_url,
            logoMarkSvgUrl: $this->logo_mark_svg_url,
            productsCuratedAt: $this->products_curated_at,
        );
    }
}
