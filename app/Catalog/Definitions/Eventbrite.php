<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\DetectorBuilder;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\IdentifierSource;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Eventbrite. Bespoke connect (EventsCatalog/EventsPlatformController) — no
 * connect capability. 25 regional TLDs verbatim from the HostMatch regex
 * (PRSP:424); each gets an organiser-page detector (/o/<org>), strength
 * DeepLinkWithSlug. A single eventbrite.com detector on /e/ is layered on
 * top with its own lower-confidence MarketplaceListing strength: a
 * single-event URL is NOT an organiser-connect candidate, it routes to the
 * event flow instead — kept as a separate signal rather than folded into
 * the organiser pattern (a reservedPaths('/e/') entry was considered and
 * rejected in favour of this explicit second detector).
 */
class Eventbrite
{
    /** Regional TLDs verbatim from PRSP:424's HostMatch regex. */
    private const TLDS = [
        'com', 'com.au', 'co.uk', 'co.nz', 'ca', 'de', 'fr', 'es', 'it', 'nl',
        'pt', 'ie', 'at', 'ch', 'dk', 'fi', 'se', 'be', 'sg', 'hk',
        'com.br', 'com.mx', 'com.ar', 'com.pe', 'cl',
    ];

    public static function brand(): Brand
    {
        return Brand::make('eventbrite', 'Eventbrite', 'https://www.eventbrite.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        /** @var list<DetectorBuilder> $detectors */
        $detectors = array_map(
            static fn (string $tld): DetectorBuilder => Detector::url("eventbrite.{$tld}")
                ->path('#^/o/(?<org>[a-z0-9-]+)#i')
                ->captures('org')
                ->from(IdentifierSource::Path)
                ->strength(EvidenceStrength::DeepLinkWithSlug),
            self::TLDS,
        );

        $detectors[] = Detector::url('eventbrite.com')
            ->path('#^/e/#')
            ->strength(EvidenceStrength::MarketplaceListing)
            ->note('single event page — routes to event flow, not organiser connect');

        return [
            SurfaceBuilder::for('eventbrite.organiser')
                ->displayName('Eventbrite')
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(21600)
                ->canonicalUrl('https://eventbrite.com/o/{org}')
                ->fetch('fetch.eventbrite.scrape.v1')
                ->multiAccount(5)
                ->detect(...$detectors)
                ->note('bespoke connect flow (P1)')
                ->build(),
        ];
    }
}
