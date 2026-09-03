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
 * Bluesky — social, added link-only in wave 2 (2026-08-28), connectable
 * since Item 11e (2026-09-01) as an ingest data source (posts → the socials
 * pool via BlueskyConnector). Handles are {name}.bsky.social, a verbatim
 * custom domain, or a did:plc: id, so the capture charset is domain-shaped.
 * Verified example: bsky.app/profile/pres.cafe.
 */
class Bluesky
{
    public static function brand(): Brand
    {
        return Brand::make('bluesky', 'Bluesky', 'https://bsky.app');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('bluesky.profile')
                ->displayName('Bluesky')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Url)
                // Connectable since Item 11e (2026-09-01): the ingest lane
                // (BlueskyConnector → posts pool) needs a connect door, and
                // the wave-2 "would 422 its own URL" concern no longer holds —
                // classify() backstops through classifyFromCatalog(), which
                // answers for this surface once is_connectable is true (the
                // very flag the old note predated flipping). The derived
                // Brand card (BrandLinkConnect) is the whole flow.
                ->refreshEvery(0)
                ->canonicalUrl('https://bsky.app/profile/{handle}')
                ->detect(
                    Detector::url('bsky.app')
                        ->path('#^/profile/(?<handle>[A-Za-z0-9:._-]{1,253})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink)
                        ->note('e.g. https://bsky.app/profile/bsky.app — verified live 2026-09-03'),
                )
                ->build(),
        ];
    }
}
