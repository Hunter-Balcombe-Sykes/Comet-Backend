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
 * Deliveroo. New link-only surface — today a
 * WebsiteLinkHarvester::ORDERING_HOSTS label
 * (WebsiteLinkHarvester.php:92) that collapses into the generic
 * 'online-ordering' pseudo-bucket; this surface is the P1 upgrade to a
 * first-class brand. 12 regional TLDs verbatim from that regex. No config
 * entry — host-only detectors, no capture — plus a per-TLD
 * /menu/<city>/<area>/<slug> sibling (verified live 2026-09-03 against
 * .co.uk, .fr and .ae — one Deliveroo-built platform, not a merger of
 * acquired sites, so the same grammar is applied to all 12 TLDs). The
 * browse equivalent lives one path segment over, at
 * /restaurants/<city>/<area> — anchoring on ^/menu/ already excludes it
 * (and /cities/<city>, /takeaway) without a ->reject().
 */
class Deliveroo
{
    /** Regional TLDs verbatim from WebsiteLinkHarvester.php:92's ORDERING_HOSTS regex. */
    private const TLDS = [
        'com', 'co.uk', 'fr', 'ie', 'it', 'be', 'nl', 'sg', 'hk', 'ae', 'com.kw', 'qa',
    ];

    public static function brand(): Brand
    {
        return Brand::make('deliveroo', 'Deliveroo', 'https://deliveroo.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('deliveroo.order')
                ->displayName('Deliveroo')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(...array_merge(
                    array_map(
                        static fn (string $tld) => Detector::url("deliveroo.{$tld}")
                            ->strength(EvidenceStrength::ProfileLink),
                        self::TLDS,
                    ),
                    array_map(
                        // The locale segment is OPTIONAL, not absent: the UK
                        // site serves /menu/… bare, while the multilingual
                        // markets prefix it (deliveroo.fr/fr/menu/…, and .be
                        // carries both /fr/ and /nl/). Without the optional
                        // group those markets matched only the host-only rule
                        // above and connected with no identifier. Additive by
                        // construction — an optional leading group cannot cost
                        // the bare form its match.
                        static fn (string $tld) => Detector::url("deliveroo.{$tld}")
                            ->path('#^(?:/[a-z]{2}(?:-[a-z]{2})?)?/menu/[^/]+/[^/]+/(?<slug>[\w-]+)#')
                            ->captures('slug')
                            ->from(IdentifierSource::Path)
                            ->strength(EvidenceStrength::DeepLinkWithSlug)
                            ->note('e.g. https://deliveroo.co.uk/menu/london/enfield/the-meeting-bar-and-restaurant and https://deliveroo.fr/fr/menu/montauban/aussonne/mcdonalds-montauban-aussonne'),
                        self::TLDS,
                    ),
                ))
                ->build(),
        ];
    }
}
