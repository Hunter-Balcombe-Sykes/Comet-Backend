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

/** Ovatu — 27-set booking, detect-only (logo card, no connect anywhere). */
class Ovatu
{
    public static function brand(): Brand
    {
        return Brand::make('ovatu', 'Ovatu', 'https://ovatu.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('ovatu.book')
                ->legacyPlatform('ovatu')
                ->displayName('Ovatu')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('ovatu.com')->strength(EvidenceStrength::ProfileLink),
                    // book.app: Ovatu's customer mini-site domain per their
                    // own docs ({business}.book.app). Plan-03 batch 6 could
                    // find no live example and left this host-only on the
                    // documented shape alone; the 2026-09-03 sweep found two,
                    // so the tenant is now captured off the subdomain.
                    Detector::url('book.app')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('book.app')
                        ->subdomain('#^(?!(?:www|app|api|admin|help|support|status|blog|my|login|secure)$)(?<tenant>[a-z0-9][a-z0-9-]*)$#i')
                        ->captures('tenant')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://1bodytherapies.book.app/ and https://curlygirls.book.app/ — both verified live (HTTP 200) 2026-09-03'),
                )
                ->build(),
        ];
    }
}
