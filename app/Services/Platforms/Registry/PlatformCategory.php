<?php

namespace App\Services\Platforms\Registry;

// The integration categories a platform descriptor belongs to. Distinct from
// config('partna.social_platforms') (the lightweight link-block registry) — this
// is the integration-connections taxonomy used by the dashboard grouping and the
// smart-detect facades.
enum PlatformCategory: string
{
    case Social = 'social';
    case Content = 'content';
    case Streaming = 'streaming';
    case Music = 'music';
    case Events = 'events';
    case Booking = 'booking';
    case Reservations = 'reservations';
    case OnlineOrdering = 'online-ordering';
    case Shop = 'shop';
    case Education = 'education';
    case Business = 'business';

    public static function fromKey(string $key): self
    {
        return self::from($key);
    }
}
