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
 * Just Eat. New link-only surface — today a
 * WebsiteLinkHarvester::ORDERING_HOSTS label ('Just Eat',
 * WebsiteLinkHarvester.php:97) that collapses into the generic
 * 'online-ordering' pseudo-bucket; this surface is the P1 upgrade to a
 * first-class brand. The source regex
 * (`just-?eat\.(co\.uk|com|fr|ie|es|it|ch|dk|no|lu)`) allows an OPTIONAL
 * hyphen independent of TLD, i.e. both spellings are valid on every TLD —
 * translated here as both spellings x all 10 TLDs (20 detectors), not one
 * regex, to match this catalog's per-exact-domain Detector idiom.
 *
 * Store-page grammar is NOT uniform across this brand (verified live
 * 2026-09-03) — two families found, and the rest unconfirmed:
 *  - STORE_TLDS (co.uk, ie, es, it): /restaurants-<slug>/menu — identical
 *    grammar confirmed on four markets in three languages.
 *  - fr: a DIFFERENT grammar, /restaurant-livraison-a-domicile/restaurant/
 *    <slug>/... — inherited from the pre-rebrand AlloResto site. Note the
 *    singular "restaurant-": France's own BROWSE/zone pages live at the
 *    plural /restaurants-livraison-a-domicile/zone-livraison/<city>, one
 *    character away from the store grammar — confirmed distinct, not
 *    guessed.
 *  - com, ch, dk, no, lu: no real store-page example found (com 301s
 *    through a bot-walled redirect; ch's one indexed /en/menu/<slug> hit
 *    had a generic, non-restaurant-named title, not enough to confirm a
 *    grammar). No detector added for these — the host-only sibling above
 *    still covers them.
 */
class JustEat
{
    /** Regional TLDs verbatim from WebsiteLinkHarvester.php:97's ORDERING_HOSTS regex. */
    private const TLDS = ['co.uk', 'com', 'fr', 'ie', 'es', 'it', 'ch', 'dk', 'no', 'lu'];

    /** Both spellings the source regex's optional hyphen allows. */
    private const SPELLINGS = ['just-eat', 'justeat'];

    /** TLDs confirmed live to share the /restaurants-<slug>/menu grammar. */
    private const STORE_TLDS = ['co.uk', 'ie', 'es', 'it'];

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

            foreach (self::STORE_TLDS as $tld) {
                $detectors[] = Detector::url("{$spelling}.{$tld}")
                    ->path('#^/restaurants-(?<slug>[\w-]+)/menu#')
                    ->captures('slug')
                    ->from(IdentifierSource::Path)
                    ->strength(EvidenceStrength::DeepLinkWithSlug)
                    ->note('e.g. https://www.just-eat.co.uk/restaurants-chicken-and-co-leyton/menu');
            }

            // fr only — different platform heritage, different grammar (see class docblock).
            $detectors[] = Detector::url("{$spelling}.fr")
                ->path('#^/restaurant-livraison-a-domicile/restaurant/(?<slug>[\w-]+)#')
                ->captures('slug')
                ->from(IdentifierSource::Path)
                ->strength(EvidenceStrength::DeepLinkWithSlug)
                ->note('e.g. https://www.just-eat.fr/restaurant-livraison-a-domicile/restaurant/queen/st-laurent-du-var');
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
