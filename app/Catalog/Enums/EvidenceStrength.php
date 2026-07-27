<?php

namespace App\Catalog\Enums;

// How strongly a single piece of evidence implies a live connection to the
// brand — independent of EvidenceSurface's weight() (that ranks WHERE
// evidence was found; this ranks WHAT KIND it is). Detector::$strength
// defaults to ProfileLink.
enum EvidenceStrength: int
{
    case InstalledEmbedWithId = 90;
    case DeepLinkWithSlug = 70;
    case ProfileLink = 50;
    case MarketplaceListing = 30;
    case Mention = 10;
}
