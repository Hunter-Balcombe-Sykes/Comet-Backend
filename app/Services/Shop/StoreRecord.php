<?php

namespace App\Services\Shop;

use Illuminate\Support\Carbon;

/**
 * Slice 7 Task 24: one connected storefront, as ShopContentWriter needs it.
 *
 * This exists so the writer's identity anchor is DATA, not an Eloquent model
 * bound to `site.shop_brands`. That table is dropped by
 * `20260819000210_drop_site_shop_brands.sql` (the re-home's own band — slice 7
 * deferred the DROP it originally numbered `20260817000900`, and that file was
 * never written); a writer that type-hints its model cannot survive the DROP,
 * and shop writes are live (unlike `site.shop_products`, which nothing writes
 * any more).
 *
 * IDENTITY is (provider, externalRef) — never the name. `externalRef` is the
 * PROVIDER's own store id (`75102060779`, `fearnoevil-com-au`), which is what
 * `content.storefronts.external_ref` stores and what the legacy
 * `shop_brands.brand_id` column held. The display name is user-editable
 * (ShopController::updateBrand), so keying on it made a rename between two
 * upsertStore() calls mint a second collection+storefront pair and orphan the
 * first, taking its referral_query/discount_code (affiliate revenue) with it.
 *
 * Every field maps 1:1 onto a `content.storefronts` column except `name` and
 * `position`, which live on the parent `content.collections` row (`label`,
 * `position`) — so a record can always be rebuilt from content.* alone, with
 * no legacy row anywhere in the picture (see fromStorefrontRow()).
 */
final readonly class StoreRecord
{
    public function __construct(
        public string $externalRef,
        public string $provider,
        public ?string $name = null,
        public int $position = 0,
        public ?string $url = null,
        public ?string $sourceUrl = null,
        public ?string $currency = null,
        public ?string $discountCode = null,
        public string $referralQuery = '',
        public bool $isIndividual = false,
        public ?string $fetchMode = null,
        public ?string $connectStatus = null,
        public ?string $connectError = null,
        public ?string $logoUrl = null,
        public ?string $faviconUrl = null,
        public ?string $logoMarkUrl = null,
        public ?string $logoMarkSvgUrl = null,
        public ?Carbon $productsCuratedAt = null,
        /**
         * Set ONLY when read back from content.* — never an input to
         * upsertStore(), where the collection id is the OUTPUT. It is the
         * replacement identity for the site.shop_brands uuid PK the two async
         * jobs used to key on (spec §5), and the prefix ProcessShopBrandLogoJob
         * writes R2 objects under.
         */
        public ?string $collectionId = null,
        /**
         * Read-back only, like $collectionId — upsertStore() stamps its own
         * now() and never takes one.
         *
         * This is connectStatus()'s stale-pending clock (spec §12.2): a worker
         * that dies leaves a store 'pending' with nothing to flip it, so a poll
         * past StrandedPendingWindow::MINUTES answers 'failed' synthetically.
         * The clock lives on content.storefronts.updated_at.
         *
         * The spec doubted that upsertStore() would bump it for a byte-identical
         * write, and therefore that the legacy `->touch()` would need a
         * replacement. It does bump: Postgres's ON CONFLICT DO UPDATE writes the
         * row unconditionally. The touch() existed because ELOQUENT's
         * fill()->save() skips a no-op UPDATE — a model-layer dirty check with
         * no database equivalent — so it disappears rather than moving.
         */
        public ?Carbon $updatedAt = null,
    ) {}

    /**
     * Rebuild from a `content.storefronts` row joined to its
     * `content.collections` parent — the post-DROP read direction.
     *
     * `label` is NOT NULL on the collection and upsertStore() writes
     * `name ?? externalRef` into it, so a store with no display name reads
     * back with label === external_ref; null it out again here so the record
     * round-trips to the same write, rather than promoting an opaque provider
     * id into a display name (the identical rule ShopContentReader applies).
     */
    public static function fromStorefrontRow(object $row): self
    {
        $externalRef = (string) $row->external_ref;
        $label = isset($row->label) ? (string) $row->label : null;
        $curatedAt = $row->products_curated_at ?? null;
        $updatedAt = $row->updated_at ?? null;

        return new self(
            externalRef: $externalRef,
            provider: (string) $row->provider,
            name: ($label === null || $label === $externalRef) ? null : $label,
            position: (int) ($row->position ?? 0),
            url: $row->url,
            sourceUrl: $row->source_url,
            currency: $row->currency,
            discountCode: $row->discount_code,
            referralQuery: (string) ($row->referral_query ?? ''),
            isIndividual: (bool) $row->is_individual,
            fetchMode: $row->fetch_mode,
            connectStatus: $row->connect_status,
            connectError: $row->connect_error,
            logoUrl: $row->logo_url,
            faviconUrl: $row->favicon_url,
            logoMarkUrl: $row->logo_mark_url,
            logoMarkSvgUrl: $row->logo_mark_svg_url,
            productsCuratedAt: $curatedAt === null ? null : Carbon::parse((string) $curatedAt),
            collectionId: isset($row->collection_id) ? (string) $row->collection_id : null,
            updatedAt: $updatedAt === null ? null : Carbon::parse((string) $updatedAt),
        );
    }
}
