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
 * Pinterest — TLD set is WebsiteLinkHarvester's own pinterest.* host pattern
 * (broader than PinterestScraper::parseUsername's narrower connect-side TLD
 * list — the detector favours the broader set to catch more real links).
 * Username capture + RESERVED blocklist verbatim from PinterestScraper.
 * refreshEvery/connect capability: see sidecar AMBIGUOUS #1 — pinterest has a
 * real Deferred ConnectStrategy (PinterestConnect) but was omitted from the
 * task's enumerated connect-capability brand list; granted here per the
 * stated principle ("connect only where a real ConnectStrategy exists") and
 * consistency with the sibling deferred-connect platforms.
 */
class Pinterest
{
    private const RESERVED = 'pin|ideas|search|settings|business|today|watch|about|careers|policy|help|login|signup|news_hub|oauth|resource|topics';

    /** @var list<string> */
    private const TLDS = ['com', 'com.au', 'com.mx', 'com.br', 'co.uk', 'co.kr', 'ca', 'fr', 'de', 'es', 'it', 'jp', 'pt', 'se', 'dk', 'at', 'ch', 'cl', 'ie', 'nz'];

    public static function brand(): Brand
    {
        return Brand::make('pinterest', 'Pinterest', 'https://www.pinterest.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('pinterest.profile')
                ->displayName('Pinterest')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Media)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(86400)
                ->note('refresh interval falls back to refresh.default_ttl_seconds — no dedicated config key exists (inventory D2#7); connect capability granted despite the omission from the task brand list — see sidecar AMBIGUOUS #1')
                ->canonicalUrl('https://www.pinterest.com/{user}/')
                ->connect('connect.pinterest.url.v1')
                ->fetch('fetch.pinterest.scrape.v1')
                ->detect(
                    ...array_map(
                        fn (string $tld) => Detector::url("pinterest.{$tld}")
                            ->path('#^/(?!(?:'.self::RESERVED.')(?:/|$))(?<user>[A-Za-z0-9_]{3,30})/?$#')
                            ->captures('user')
                            ->from(IdentifierSource::Path)
                            ->strength(EvidenceStrength::ProfileLink),
                        self::TLDS,
                    ),
                )
                ->build(),
        ];
    }
}
