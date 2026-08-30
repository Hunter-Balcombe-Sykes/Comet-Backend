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
 * WhatsApp — one of the 11 "new" link-only socials with zero connect wiring
 * (no ->connect(), ->connectInput(), or ->routes() in PRSP — inventory D2#5).
 * wa.me is a Hosts.php alias onto whatsapp.com, so the detector needs only the
 * click-to-chat entry-point paths (/send, /message); it captures no
 * identifier because the real identity is a phone number entered directly by
 * the owner, not parsed from a detected URL — see the note.
 */
class Whatsapp
{
    public static function brand(): Brand
    {
        return Brand::make('whatsapp', 'WhatsApp', 'https://www.whatsapp.com');
    }

    /** @return list<Surface> */
    public static function surfaces(): array
    {
        return [
            SurfaceBuilder::for('whatsapp.chat')
                ->legacyPlatform('whatsapp')
                ->displayName('WhatsApp')
                ->routing(RoutingClass::Social)
                ->shelf(Shelf::Social)
                ->identifier(IdentifierKind::NumericId)
                ->refreshEvery(0)
                ->note("identifier is a phone number (7-15 digits, optional leading +) per config('partna.social_platforms.whatsapp').handle_pattern — not detector-captured")
                ->canonicalUrl('https://wa.me/{handle}')
                ->detect(
                    Detector::url('whatsapp.com')
                        ->path('#^/(?:send|message)#')
                        ->strength(EvidenceStrength::ProfileLink),
                    // The BARE phone path — wa.me/<number>, which is what
                    // WhatsApp's own click-to-chat produces and what this very
                    // surface's canonicalUrl above emits. The two entry-point
                    // paths did not cover it, so the catalog could not identify
                    // the single most common WhatsApp link there is.
                    //
                    // It went unnoticed because the OTHER lane hid it: a wa.me
                    // link found directly in an Instagram bio is seeded by
                    // WebsiteLinkHarvester (classify() answers whatsapp/social)
                    // and never reaches the catalog. Only links unrolled from an
                    // AGGREGATOR page route through here — so the bug needed a
                    // Linktree/bio.site carrying a wa.me link to show itself.
                    // Found live 2026-08-30 on finderseekerphotography, whose
                    // bio.site WhatsApp link noted as no-rule-matched while two
                    // other accounts' identical-shaped links connected fine.
                    //
                    // Captures nothing, deliberately, matching its siblings and
                    // the note above: the phone number stays the owner-entered
                    // identity rather than one parsed out of a detected URL.
                    // Optional leading '+' because that is how people write an
                    // international number, and it survives into the path.
                    Detector::url('whatsapp.com')
                        ->path('#^/\+?\d{7,15}/?$#')
                        ->strength(EvidenceStrength::ProfileLink),
                )
                ->build(),
        ];
    }
}
