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
 * Zenoti — 27-set booking. A business's own Webstore is a tenant
 * subdomain, <org>.zenoti.com (confirmed live: salon809.zenoti.com,
 * page-titled "salon809 - Online Booking"). Excludes Zenoti's own infra
 * labels so www/help/etc. never read as an org named "help".
 */
class Zenoti
{
    public static function brand(): Brand
    {
        return Brand::make('zenoti', 'Zenoti', 'https://www.zenoti.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('zenoti.book')
                ->legacyPlatform('zenoti')
                ->displayName('Zenoti')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('zenoti.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('zenoti.com')
                        ->subdomain('#^(?!(?:www|app|api|admin|help|support|status|blog|my|developer|login)$)(?<org>[a-z0-9][a-z0-9-]*)$#i')
                        ->captures('org')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://salon809.zenoti.com/webstoreNew/services'),
                )
                ->build(),
        ];
    }
}
