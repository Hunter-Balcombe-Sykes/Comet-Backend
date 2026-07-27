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
 * Telegram — core-11 link social (UrlConnect + TelegramNormalizer). Three
 * real hosts accept a handle (t.me, telegram.me, telegram.org); t.me is
 * already a Hosts.php alias onto telegram.org, so only telegram.org and the
 * unaliased telegram.me need their own detector (mirrors X.php's x.com/
 * twitter.com pair).
 */
class Telegram
{
    public static function brand(): Brand
    {
        return Brand::make('telegram', 'Telegram', 'https://telegram.org');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('telegram.channel')
                ->displayName('Telegram')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://t.me/{handle}')
                ->connect('connect.telegram.url.v1')
                ->multiAccount(5)
                ->detect(
                    Detector::url('telegram.org')
                        ->path('#^/@?(?<handle>[A-Za-z0-9_]{5,32})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                    Detector::url('telegram.me')
                        ->path('#^/@?(?<handle>[A-Za-z0-9_]{5,32})/?$#')
                        ->captures('handle')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
