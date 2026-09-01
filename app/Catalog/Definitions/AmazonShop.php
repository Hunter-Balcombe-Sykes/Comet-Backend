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
 * Amazon influencer storefronts (Item 10b, 2026-09-01) —
 * amazon.com/shop/<handle>, synced as a shop-pool store via the vendor lane
 * (AmazonShopConnectJob → ShopContentWriter::syncStore), never scraped
 * directly (amazon.com bot-blocks server reads — the documented reason it
 * sat in WebsiteLinkHarvester::LINK_ONLY_HOSTS). This surface exists so the
 * store's anchor connection has a truthful surface_key
 * (IntegrationConnection's saving guard refuses unknown surfaces) and so a
 * scanned /shop/ link can classify to its brand.
 *
 * NOT connectable here on purpose: a store connects through the shop lane
 * (POST /platforms/shop/amazon-shop/connect → the job), never as a brand
 * card — ShopConnections::surfaces() carries 'amazon-shop.store', which
 * keeps DerivedDescriptorFactory from deriving a brand connect for it (the
 * same skip every provider store surface gets).
 *
 * Brand key is hyphenated ('amazon-shop', matching
 * AmazonShopScraper::PROVIDER) so the slug, the provider string on
 * content.storefronts, and the surface prefix are ONE vocabulary — the
 * detector's job is naming links, and split_part covers the slug with no
 * legacy mapping.
 *
 * The handle grammar is AmazonShopScraper::handleFromUrl's, verbatim —
 * the scraper owns it; this detector mirrors it so a scanned link and a
 * pasted connect can never disagree.
 */
class AmazonShop
{
    public static function brand(): Brand
    {
        return Brand::make('amazon-shop', 'Amazon Storefront', 'https://www.amazon.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('amazon-shop.store')
                ->displayName('Amazon Storefront')
                ->routing(RoutingClass::Shop)
                ->shelf(Shelf::Commerce)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://www.amazon.com/shop/{handle}')
                ->multiAccount(10)
                ->detect(
                    Detector::url('amazon.com')
                        ->path('#^/shop/(?<handle>[A-Za-z0-9._-]{1,100})(?:/|$)#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->note('connects via the shop lane (AmazonShopConnectJob), never a brand card — see ShopConnections::PROVIDER_SURFACE')
                ->build(),
        ];
    }
}
