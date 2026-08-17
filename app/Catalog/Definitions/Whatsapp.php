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
                )
                ->build(),
        ];
    }
}
