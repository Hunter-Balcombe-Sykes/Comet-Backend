<?php

namespace App\Services\Cache;

// Outcome of a PlacesBudget::claim() call. A bare bool can't express WHY a
// claim was denied, and the caller-facing behaviour differs by reason (RV-6
// §6): UserCapReached is the caller's own doing (429 on the dashboard connect
// path); PlatformCapReached and Unavailable both degrade quietly.
enum PlacesClaim: string
{
    case Granted = 'granted';
    case UserCapReached = 'user_cap_reached';
    case PlatformCapReached = 'platform_cap_reached';
    case Unavailable = 'unavailable';
}
