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
 * Booksy. One of the 27-provider stopgap set (PICR.php:178-222) —
 * detect-only card, no connect/fetch, host verbatim from PRSP:429.
 * WebsiteLinkHarvester also matches booksy.com and agrees with this key:
 * Phase 6 split BOOKING_PLATFORM per brand, so it returns 'booksy'. (It used
 * to collapse to a generic 'booking' pseudo-platform; that is no longer true.)
 */
class Booksy
{
    public static function brand(): Brand
    {
        return Brand::make('booksy', 'Booksy', 'https://booksy.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('booksy.book')
                ->legacyPlatform('booksy')
                ->displayName('Booksy')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('booksy.com')->strength(EvidenceStrength::ProfileLink),
                    // Business profile paths carry a leading numeric business
                    // id before the slug: /<locale>/<id>_<slug>_<category>_
                    // <city-id>_<city> (verified live, booksy.com/en-us
                    // search results). Category-listing pages (/en-us/s/...)
                    // and account paths have no leading digit, so they can't
                    // match this without a reject list.
                    Detector::url('booksy.com')
                        ->path('#^/[a-z]{2}-[a-z]{2}/(?<id>\d+)_#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://booksy.com/en-us/904207_hairgameconcepts-by-adorned_hair-salon_134655_los-angeles'),
                    // 2026-09-05: a business's own booksy.com tenant subdomain
                    // (squeakprobarber.booksy.com) is the same Booksy account
                    // as its numeric-id directory listing above, but has no
                    // detector of its own — it fell through to a raw custom
                    // link that duplicated the already-connected Booksy card.
                    // Mirrors Square.book's identical tenant-subdomain fix
                    // the same day.
                    Detector::url('booksy.com')
                        ->subdomain('#^(?<tenant>[a-z0-9][a-z0-9-]{1,62})$#i')
                        ->strength(EvidenceStrength::ProfileLink),
                    // Booksy's own "powered by / learn more" badge domain — a
                    // widget artifact off the business's site, not content
                    // about the business, but still Booksy's brand: routing
                    // it through the booking policy (same find, same day)
                    // keeps it out of the links pool as a stray duplicate.
                    Detector::url('booksy.info')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
