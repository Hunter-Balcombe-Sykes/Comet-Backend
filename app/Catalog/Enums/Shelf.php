<?php

namespace App\Catalog\Enums;

// The dashboard/profile-picker grouping shelf a surface's card appears under —
// deliberately separate from RoutingClass (routing is CTA/connect wiring;
// shelf is presentation grouping only), so a surface can route as e.g. Shop
// while shelving under Commerce or Food.
enum Shelf: string
{
    case Social = 'social';
    case Video = 'video';
    case Music = 'music';
    case Podcast = 'podcast';
    case Community = 'community';
    case Events = 'events';
    case Commerce = 'commerce';
    case Food = 'food';
    case Booking = 'booking';
    case Media = 'media';
    case Education = 'education';
    case Business = 'business';
}
