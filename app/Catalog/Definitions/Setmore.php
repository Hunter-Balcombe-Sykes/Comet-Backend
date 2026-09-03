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
 * Setmore — WLH-label booking brand. Host from
 * WebsiteLinkHarvester::BOOKING_HOSTS (verbatim). setmore.com is also a
 * Hosts.php multi-tenant suffix override — tenant booking pages live at
 * <tenant>.setmore.com, confirmed live (giovauzcuts.setmore.com,
 * gillyssalon.setmore.com — real barbershop/salon pages with "Book now"
 * titles). Excludes Setmore's own infra labels so www/support/etc. never
 * read as a business named "support".
 */
class Setmore
{
    public static function brand(): Brand
    {
        return Brand::make('setmore', 'Setmore', 'https://www.setmore.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('setmore.book')
                ->displayName('Setmore')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('setmore.com')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('setmore.com')
                        ->subdomain('#^(?!(?:www|app|api|admin|help|support|status|blog|my|developer|login|manage)$)(?<tenant>[a-z0-9][a-z0-9-]*)$#i')
                        ->captures('tenant')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://giovauzcuts.setmore.com/'),
                )
                ->build(),
        ];
    }
}
