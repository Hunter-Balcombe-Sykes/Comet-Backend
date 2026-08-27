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
 * Google Calendar appointment schedules (T27a, 2026-08-28) — the
 * calendar.app.google/<token> short links increasingly common in bios, plus
 * the long calendar.google.com/calendar/appointments/… form. Path-filtered
 * on the long form — a bare google.com link is NOT a booking page.
 */
class GoogleAppointments
{
    public static function brand(): Brand
    {
        return Brand::make('google_appointments', 'Google Calendar appointments', 'https://workspace.google.com/products/calendar/');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('google_appointments.book')
                ->displayName('Google Calendar appointments')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('app.google')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('google.com')->path('#^/calendar/appointments#')->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
