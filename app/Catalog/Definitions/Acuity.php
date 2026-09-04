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
 * Acuity Scheduling. New link-only surface — today a
 * WebsiteLinkHarvester::BOOKING_HOSTS label (WebsiteLinkHarvester.php:116)
 * that collapses into the generic 'booking' pseudo-bucket; this surface is
 * the P1 upgrade to a first-class brand. No config entry, no registered
 * ConnectStrategy — host-only detector, no capture.
 */
class Acuity
{
    public static function brand(): Brand
    {
        return Brand::make('acuity', 'Acuity Scheduling', 'https://www.acuityscheduling.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('acuity.book')
                ->displayName('Acuity Scheduling')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('acuityscheduling.com')->strength(EvidenceStrength::ProfileLink),
                    // The account's numeric id lives in ?owner= on every
                    // scheduling-page link Acuity generates (dynamic links
                    // doc, help.acuityscheduling.com/hc/en-us/articles/
                    // 47575509977997), independent of any custom path —
                    // e.g. acuityscheduling.com/schedule.php?owner=18047438.
                    Detector::url('acuityscheduling.com')
                        ->query('owner')
                        ->captures('owner')
                        ->from(IdentifierSource::Query)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://acuityscheduling.com/schedule.php?owner=18047438'),
                    // as.me is Acuity's SHORT booking host, and the one people
                    // actually paste — Acuity itself hands out
                    // <tenant>.as.me links. It is already a
                    // Hosts::suffixOverrides() entry, so the registrable key
                    // is `<tenant>.as.me` and the acuityscheduling.com
                    // detector above never sees it: found live 2026-08-28 on
                    // theyogapeoplesydney, whose only bio link
                    // (theyogapeoplelink.as.me) was rejected outright and
                    // cost them their booking link. Host-level like its
                    // sibling — the tenant label is the identity, and there
                    // is nothing to capture from the path.
                    Detector::url('as.me')->strength(EvidenceStrength::ProfileLink),
                    // The tenant label IS the identity (same shape as
                    // Bandcamp's artist.bandcamp.com) — captured from the
                    // subdomain now instead of just gating on the bare host.
                    Detector::url('as.me')
                        ->subdomain('#^(?<tenant>[a-z0-9][a-z0-9-]*)$#i')
                        ->captures('tenant')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://theyogapeoplelink.as.me (live sighting, 2026-08-28)'),
                )
                ->build(),
        ];
    }
}
