<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\IdentifierSource;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * TikTok Shop storefronts (Item 10b, 2026-09-01) — tiktok.com/shop/store/…,
 * identified by SELLER ID and synced as a shop-pool store via the vendor
 * lane (TiktokShopConnectJob → syncStore; reviews ride the tiktok_shop
 * ingest connector off the same anchor). A DISTINCT brand from 'tiktok':
 * the storefront is a commerce source with its own identity (a numeric
 * seller id, not an at-handle), its own provider row on content.storefronts,
 * and its own budget key — and one brand cannot carry two slugs.
 *
 * NOT connectable as a brand card, same as every provider store surface:
 * ShopConnections::surfaces() carries 'tiktok_shop.store', which keeps
 * DerivedDescriptorFactory from deriving a brand connect. The surface
 * exists so the anchor connection's surface_key passes the saving guard and
 * a scanned /shop/store/ link classifies to its brand.
 *
 * The path grammar is TiktokShopScraper::sellerIdFrom's URL arm, verbatim —
 * the scraper owns it (an optional slug segment the vendor provably
 * ignores, then the numeric seller id).
 */
class TiktokShop
{
    public static function brand(): Brand
    {
        return Brand::make('tiktok_shop', 'TikTok Shop', 'https://www.tiktok.com/shop');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('tiktok_shop.store')
                ->displayName('TikTok Shop')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::NumericId)
                ->refreshEvery(0)
                ->canonicalUrl('https://www.tiktok.com/shop/store/s/{id}')
                ->multiAccount(10)
                ->detect(
                    Detector::url('tiktok.com')
                        ->path('#^/shop/store/(?:[^/?\#]+/)?(?<id>\d{6,})(?:/|$)#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://www.tiktok.com/shop/store/breo-us/7495369109679933933 — verified live 2026-09-03'),
                )
                ->note('connects via the shop lane (TiktokShopConnectJob), never a brand card — see ShopConnections::PROVIDER_SURFACE')
                ->build(),
        ];
    }
}
