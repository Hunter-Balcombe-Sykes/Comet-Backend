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
 * Threads — core-11 link social (UrlConnect + ThreadsNormalizer). Both
 * threads.net and threads.com resolve a handle per the normalizer.
 */
class Threads
{
    public static function brand(): Brand
    {
        return Brand::make('threads', 'Threads', 'https://www.threads.net');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('threads.profile')
                ->displayName('Threads')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://www.threads.net/@{handle}')
                ->connect('connect.threads.url.v1')
                ->multiAccount(5)
                ->detect(
                    Detector::url('threads.net')
                        ->path('#^/@?(?<handle>[A-Za-z0-9._]{1,30})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('threads.com')
                        ->path('#^/@?(?<handle>[A-Za-z0-9._]{1,30})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
