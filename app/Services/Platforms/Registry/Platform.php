<?php

namespace App\Services\Platforms\Registry;

/**
 * Canonical DB-string identifiers for every platform referenced by identity
 * (query/write/comparison/getter) in application code (FOUND-11). Every
 * case's value MUST also be a registered PlatformRegistry key; enforced by
 * tests/Feature/Platforms/Registry/PlatformEnumSyncTest.php.
 * Deliberately NOT a full mirror of PlatformRegistry (36+ platforms) — only
 * platforms actually referenced by identity in code get a case here.
 */
enum Platform: string
{
    case GoogleBusiness = 'google-business';
    case OnlineOrdering = 'online-ordering';
    case Fresha = 'fresha';
    case Square = 'square';
    case Reservations = 'reservations';
    case Custom = 'custom';
    case Booking = 'booking';
    case OpenTable = 'opentable';
    case Resdiary = 'resdiary';
    case Nowbookit = 'nowbookit';
    case Instagram = 'instagram';
}
