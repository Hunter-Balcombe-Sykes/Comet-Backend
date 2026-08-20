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
 * Eventbrite. Bespoke connect (EventsPlatformController) — no
 * connect capability. 25 regional TLDs verbatim from the HostMatch regex
 * (PRSP:424); each gets an organiser-page detector (/o/<org>), strength
 * DeepLinkWithSlug. A single-event URL (/e/…) is NOT an organiser-connect
 * candidate — it is reserved on the surface (see the builder), so it projects
 * no-rule-matched and the importer seeds it as an event ITEM. The earlier
 * "explicit second detector" for /e/ is gone: a path detector cannot score
 * below the suggest band, so it placed organiser rows from event links.
 */
class Eventbrite
{
    /** Regional TLDs verbatim from PRSP:424's HostMatch regex. Single source
     * of truth for the brand — consumed by WebsiteLinkHarvester::classify()
     * and ItemLinkRules, never re-listed. (EventbriteScraper's regex-string
     * TLDS predates this and stays: it is a pattern fragment, not a list.) */
    public const TLDS = [
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

        return [
            SurfaceBuilder::for('eventbrite.organiser')
                ->legacyPlatform('eventbrite')
                ->displayName('Eventbrite')
                ->routing(RoutingClass::Events)
                ->shelf(Shelf::Events)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(21600)
                ->canonicalUrl('https://eventbrite.com/o/{org}')
                // A single EVENT page (/e/…) is an ITEM, not an organiser
                // account. This used to be a second, MarketplaceListing-strength
                // /e/ detector on eventbrite.com meant to route to the event
                // flow — but any path-pattern detector scores 71 (40 + 35 − 4),
                // above the events suggest band, and an indirect origin
                // auto-applies that band: an event link PLACED an organiser
                // connection with the whole URL as its resource_id. Reserved
                // paths are the surface-level "can never auto-connect"
                // (LinkProjector::score), which is the semantic wanted here:
                // the URL projects no-rule-matched → Note → LinkInBioImporter
                // seeds the event through EventsSeeder as a pool item.
                ->reservedPaths('/e/')
                ->fetch('fetch.eventbrite.scrape.v1')
                ->multiAccount(10)
                ->detect(...$detectors)
                ->note('bespoke connect flow (P1)')
                ->build(),
        ];
    }
}
