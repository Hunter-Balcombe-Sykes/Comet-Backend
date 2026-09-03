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
 * Cliniko (T27a, 2026-08-28) — AU practice management; patient bookings at
 * <clinic>.<shard>.cliniko.com/bookings. Link-only.
 */
class Cliniko
{
    public static function brand(): Brand
    {
        return Brand::make('cliniko', 'Cliniko', 'https://www.cliniko.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('cliniko.book')
                ->displayName('Cliniko')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('cliniko.com')->strength(EvidenceStrength::ProfileLink),
                    // The clinic's tenant label is the identity, same shape
                    // as Bandcamp — either <clinic>.cliniko.com or
                    // <clinic>.<shard>.cliniko.com (a regional shard like
                    // au2/au3). Both verified live: lyndhurstchiro.cliniko.com
                    // /bookings, otway-aesthetics.au2.cliniko.com/bookings.
                    // Excludes the known non-tenant subdomains — 'www' is
                    // already nulled upstream by IriCanonicalizer, but
                    // help.cliniko.com and docs.api.cliniko.com are real
                    // Cliniko-owned hosts that would otherwise misread as a
                    // clinic named "help" or "docs.api".
                    Detector::url('cliniko.com')
                        ->subdomain('#^(?!www$|help$|api$|docs\.api$)(?<tenant>[a-z0-9-]+)(?:\.[a-z0-9]+)?$#i')
                        ->captures('tenant')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://lyndhurstchiro.cliniko.com/bookings, https://otway-aesthetics.au2.cliniko.com/bookings'),
                )
                ->build(),
        ];
    }
}
