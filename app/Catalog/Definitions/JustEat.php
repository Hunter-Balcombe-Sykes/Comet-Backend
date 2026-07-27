<?php

namespace App\Catalog\Definitions;

use App\Catalog\Brand;
use App\Catalog\Detector;
use App\Catalog\Enums\EvidenceStrength;
use App\Catalog\Enums\IdentifierKind;
use App\Catalog\Enums\RoutingClass;
use App\Catalog\Enums\Shelf;
use App\Catalog\Surface;
use App\Catalog\SurfaceBuilder;

/**
 * Just Eat. New link-only surface — today a
 * WebsiteLinkHarvester::ORDERING_HOSTS label ('Just Eat',
 * WebsiteLinkHarvester.php:97) that collapses into the generic
 * 'online-ordering' pseudo-bucket; this surface is the P1 upgrade to a
 * first-class brand. The source regex
 * (`just-?eat\.(co\.uk|com|fr|ie|es|it|ch|dk|no|lu)`) allows an OPTIONAL
 * hyphen independent of TLD, i.e. both spellings are valid on every TLD —
 * translated here as both spellings x all 10 TLDs (20 detectors), not one
 * regex, to match this catalog's per-exact-domain Detector idiom.
 */
class JustEat
{
    /** Regional TLDs verbatim from WebsiteLinkHarvester.php:97's ORDERING_HOSTS regex. */
    private const TLDS = ['co.uk', 'com', 'fr', 'ie', 'es', 'it', 'ch', 'dk', 'no', 'lu'];

    /** Both spellings the source regex's optional hyphen allows. */
    private const SPELLINGS = ['just-eat', 'justeat'];

    public static function brand(): Brand
    {
        return Brand::make('just_eat', 'Just Eat', 'https://www.just-eat.co.uk');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        $detectors = [];
        foreach (self::SPELLINGS as $spelling) {
            foreach (self::TLDS as $tld) {
                $detectors[] = Detector::url("{$spelling}.{$tld}")
                    ->strength(EvidenceStrength::ProfileLink);
            }
        }

        return [
            SurfaceBuilder::for('just_eat.order')
                ->displayName('Just Eat')
                ->routing(RoutingClass::Ordering)
                ->shelf(Shelf::Food)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(...$detectors)
                ->build(),
        ];
    }
}
