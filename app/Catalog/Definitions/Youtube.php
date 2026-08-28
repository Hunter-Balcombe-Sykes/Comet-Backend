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
 * YouTube — capture set translated from YoutubeScraper::normalizeHandle's
 * first three branches only (channel id, @handle, legacy /c//user/ prefix);
 * the fourth "legacy bare vanity" fallback is deliberately NOT modelled as a
 * detector — it would match almost any youtube.com/<anything> path, far too
 * permissive for smart-detect (it's fine for the explicit connect flow, where
 * the user is asserting a channel). youtu.be is a Hosts.php alias — no
 * separate detector needed.
 */
class Youtube
{
    public static function brand(): Brand
    {
        return Brand::make('youtube', 'YouTube', 'https://www.youtube.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('youtube.channel')
                ->legacyPlatform('youtube')
                ->displayName('YouTube')
                ->routing(RoutingClass::Content)
                ->shelf(Shelf::Video)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(43200)
                ->canonicalUrl('https://www.youtube.com/@{handle}')
                ->connect('connect.youtube.url.v1')
                ->fetch('fetch.youtube.scrape.v1')
                ->multiAccount(10)
                ->detect(
                    Detector::url('youtube.com')
                        ->path('#^/channel/(?<id>UC[A-Za-z0-9_-]{22})#')
                        ->captures('id')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('youtube.com')
                        ->path('#^/@(?<handle>[^/?]+)#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    // /channel/@handle — a shape YouTube itself redirects but
                    // people paste anyway, and it matched NEITHER rule above:
                    // the /channel/ one demands a UC id, and the /@ one is
                    // anchored at the path root. Found live 2026-08-29 on
                    // djhellraiser, whose bio carried
                    // youtube.com/channel/@djhellraiser303 and whose channel
                    // the catalog could not identify. Same identifier and the
                    // same strength as the bare @handle — it IS that handle,
                    // wearing a stale prefix.
                    Detector::url('youtube.com')
                        ->path('#^/channel/@(?<handle>[^/?]+)#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('youtube.com')
                        ->path('#^/(?:c|user)/(?<handle>[^/?]+)#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
