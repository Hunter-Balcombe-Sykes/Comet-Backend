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
 * SimplyBook.me — WLH-label booking brand. Host ("simplybook.me") from
 * WebsiteLinkHarvester::BOOKING_HOSTS, verbatim; also a Hosts.php
 * multi-tenant suffix override — a business's own page is a tenant
 * subdomain, <tenant>.simplybook.me / <tenant>.simplybook.it, confirmed
 * live on both TLDs (smileondentistry.simplybook.me,
 * tennishollin.simplybook.it — real listed customer sites). Excludes
 * SimplyBook.me's own infra labels (including "secure", used for the
 * tenant login gateway at <tenant>.secure.simplybook.me — a different,
 * unconfirmed shape, not detected here) so they never read as a tenant.
 */
class SimplybookMe
{
    public static function brand(): Brand
    {
        return Brand::make('simplybook_me', 'SimplyBook.me', 'https://simplybook.me');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('simplybook_me.book')
                ->displayName('SimplyBook.me')
                ->routing(RoutingClass::Booking)
                ->shelf(Shelf::Booking)
                ->identifier(IdentifierKind::Url)
                ->refreshEvery(0)
                ->detect(
                    Detector::url('simplybook.me')->strength(EvidenceStrength::ProfileLink),
                    // Country-TLD mirror: the .me host 302s real booking
                    // pages onto .it (plan-03 batch 6, verified live on a
                    // real barber's page).
                    Detector::url('simplybook.it')->strength(EvidenceStrength::ProfileLink),
                    Detector::url('simplybook.me')
                        ->subdomain('#^(?!(?:www|app|api|admin|help|support|status|blog|my|developer|login|news|secure)$)(?<tenant>[a-z0-9][a-z0-9-]*)$#i')
                        ->captures('tenant')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://smileondentistry.simplybook.me/'),
                    Detector::url('simplybook.it')
                        ->subdomain('#^(?!(?:www|app|api|admin|help|support|status|blog|my|developer|login|news|secure)$)(?<tenant>[a-z0-9][a-z0-9-]*)$#i')
                        ->captures('tenant')
                        ->from(IdentifierSource::Subdomain)
                        ->strength(EvidenceStrength::DeepLinkWithSlug)
                        ->note('e.g. https://tennishollin.simplybook.it/'),
                )
                ->build(),
        ];
    }
}
