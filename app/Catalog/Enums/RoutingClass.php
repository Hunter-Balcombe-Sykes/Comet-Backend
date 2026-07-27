<?php

namespace App\Catalog\Enums;

// How a surface routes into the sitepage's connect/CTA system. Booking,
// Reservations and Ordering are the XOR auto-connect classes — a site
// auto-selects at most one of them as its primary CTA.
enum RoutingClass: string
{
    case Social = 'social';
    case Content = 'content';
    case Events = 'events';
    case Shop = 'shop';
    case Booking = 'booking';
    case Reservations = 'reservations';
    case Ordering = 'ordering';
    case Link = 'link';
    case Ignore = 'ignore';

    /** True for the three XOR auto-connect classes (Booking, Reservations, Ordering). */
    public function isExclusiveAuto(): bool
    {
        return match ($this) {
            self::Booking, self::Reservations, self::Ordering => true,
            default => false,
        };
    }

    /** Whether this class can hold the sitepage's primary CTA slot — the same three exclusive-auto classes. */
    public function hasPrimaryCta(): bool
    {
        return $this->isExclusiveAuto();
    }
}
