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
 * Discord. Core-11 link-only social (UrlConnect + NORM/DiscordNormalizer).
 * The "handle" is an invite CODE, not a persistent username — it can expire
 * or rotate, so both detectors read DeepLinkWithSlug rather than
 * ProfileLink. Only discord.gg/<code> and the literal discord.com/invite/
 * <code> path are recognised, verbatim from DiscordNormalizer.php:23-36;
 * any other discord.com path (login, channels, …) is deliberately
 * unmatched — the code charset (2-32, alnum + hyphen) is the same file's
 * validation regex.
 */
class Discord
{
    public static function brand(): Brand
    {
        return Brand::make('discord', 'Discord', 'https://discord.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('discord.server')
                ->legacyPlatform('discord')
                ->displayName('Discord')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::Handle)
                ->refreshEvery(0)
                ->canonicalUrl('https://discord.gg/{code}')
                ->connect('connect.discord.url.v1')
                ->detect(
                    Detector::url('discord.gg')
                        ->path('#^/(?<code>[A-Za-z0-9-]{2,32})/?$#')
                        ->captures('code')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                    Detector::url('discord.com')
                        ->path('#^/invite/(?<code>[A-Za-z0-9-]{2,32})/?$#')
                        ->captures('code')
                        ->from(IdentifierSource::Path)
                        ->strength(EvidenceStrength::DeepLinkWithSlug),
                )
                ->build(),
        ];
    }
}
