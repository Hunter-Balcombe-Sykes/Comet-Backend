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
 * Treatwell — WLH-label booking brand, new link-only surface. TLD set from
 * WebsiteLinkHarvester::BOOKING_HOSTS (verbatim); since Phase 6 WLH returns
 * this surface key ('treatwell.book') rather than a generic 'booking' bucket.
 *
 * A salon's own page is a per-locale path, NOT a single word translated the
 * same way everywhere — each entry in PLACE_PATH below was confirmed by
 * fetching a real city/category listing page on that TLD and reading an
 * actual salon href from it (e.g. treatwell.de uses the category word
 * "orte" but the individual page is singular "ort", not "salon"). 'com'
 * and 'lv' are deliberately left host-only: treatwell.com is a country-
 * picker hub with no salon pages of its own, and treatwell.lv redirects to
 * a 404 (the market looks discontinued) — no live example exists for
 * either, so no path detector is added for them.
 */
class Treatwell
{
    /** @var list<string> */
    private const TLDS = ['com', 'co.uk', 'de', 'fr', 'nl', 'es', 'it', 'be', 'at', 'ch', 'ie', 'pt', 'lt', 'lv', 'gr'];

    /**
     * TLD => [localized path segment, one real slug seen live on that TLD].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PLACE_PATH = [
        'co.uk' => ['place', 'the-basement-hair-studio'],
        'ie' => ['place', 'la-hair-beauty-1'],
        'fr' => ['salon', 'le-salon-6'],
        'nl' => ['salon', 'hair-salon-4-you'],
        'be' => ['salon', 'yoo-barber'],
        'it' => ['salone', 'my-place'],
        'de' => ['ort', 'the-barberos-berlin'],
        'at' => ['ort', 'luxury-beauty-wien'],
        'ch' => ['ort', 'odyssea'],
        'es' => ['establecimiento', 'deja-vu-hair-make-up'],
        'pt' => ['estabelecimento', 'beauty-concept-by-vinny'],
        'lt' => ['salonas', 'any1-barbershop'],
        'gr' => ['katasthma', 'tony-barbiero-barbershop'],
    ];

    public static function brand(): Brand
    {
        return Brand::make('treatwell', 'Treatwell', 'https://www.treatwell.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('treatwell.book')
                ->displayName('Treatwell')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    ...array_map(
                        fn (string $tld) => Detector::url("treatwell.{$tld}")->strength(EvidenceStrength::ProfileLink),
                        self::TLDS,
                    ),
                    ...array_map(
                        fn (string $tld, array $shape) => Detector::url("treatwell.{$tld}")
                            ->path("#^/{$shape[0]}/(?<slug>[a-z0-9][a-z0-9-]{1,90})/?(?:[/?]|$)#i")
                            ->captures('slug')
                            ->from(IdentifierSource::Path)
                            ->strength(EvidenceStrength::DeepLinkWithSlug)
                            ->note("e.g. https://www.treatwell.{$tld}/{$shape[0]}/{$shape[1]}/"),
                        array_keys(self::PLACE_PATH),
                        array_values(self::PLACE_PATH),
                    ),
                )
                ->build(),
        ];
    }
}
