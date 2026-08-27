<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Microsoft Bookings (T27a, 2026-08-28). Two live link shapes:
 * outlook.office365.com/owa/calendar/<id>/bookings/ and the newer
 * outlook.office.com/bookwithme/user/…. Path-filtered — a bare office
 * link is mail, not a booking page.
 */
class MicrosoftBookings
{
    public static function brand(): Brand
    {
        return Brand::make('microsoft_bookings', 'Microsoft Bookings', 'https://www.microsoft.com/microsoft-365/business/scheduling-and-booking-app');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('microsoft_bookings.book')
                ->displayName('Microsoft Bookings')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                // Detect-only, same reasoning as WixBookings: a bare office365/
                // office.com URL is mail, not a booking page, so the detectors are
                // path-filtered and no manual connect card exists.
                ->notConnectable()
                ->refreshEvery(0)
                ->detect(
                    Detector::url('office365.com')->path('#/bookings/#')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('office.com')->path('#^/bookwithme/#')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
