<?php

namespace App\Services\Platforms;

use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\Registry\PlatformRegistry;

/**
 * The single-slot booking family — the platforms that compete for one "Book
 * now" slot, of which a user may have at most one connected (the XOR that
 * CacheKeyGenerator::bookingXorLock serialises).
 *
 * Declared here because until 2026-09-02 the family was not declared anywhere
 * a controller could see: "Fresha excludes Square" was six hand-written
 * `hasConflictingConnection($user, Platform::Square->value)` calls with six
 * hand-written 409 messages, plus two `in_array($p, [Fresha, Square])` guards
 * in the auto-sync producers. Adding a third provider meant finding and
 * editing all eight, and missing one fails OPEN — two booking providers live
 * at once, which is exactly what the lock exists to prevent.
 *
 * Deliberately NOT the only list of these two values in the codebase:
 * GoogleBusinessAutoSync::BOOKING_PLATFORMS stays a literal so that
 * BookingXorConnectRaceTest's drift pin keeps an INDEPENDENT side to compare
 * against. A pin whose both sides derive from one const proves nothing. Do
 * not "tidy" that literal into this class.
 *
 * The reservations family (opentable/resdiary/nowbookit) is the same shape on
 * a different slot and has its own lock — modelled separately in
 * ReservationsProviders (D2, 2026-09-06), once GenericPlatformController's
 * ordinary connect endpoint needed the same cross-brand check this class
 * gives booking.
 */
final class BookingProviders
{
    /** @var list<string> */
    public const PLATFORMS = [
        Platform::Fresha->value,
        Platform::Square->value,
    ];

    public static function includes(string $platform): bool
    {
        return in_array($platform, self::PLATFORMS, true);
    }

    /**
     * Every OTHER member of the family — the ones $platform excludes.
     *
     * @return list<string>
     */
    public static function others(string $platform): array
    {
        return array_values(array_filter(
            self::PLATFORMS,
            static fn (string $candidate): bool => $candidate !== $platform,
        ));
    }

    /**
     * The name a user sees in a 409. The registry is the source (it already
     * labels 'fresha' => 'Fresha', 'square' => 'Square'); ucfirst only covers
     * a member added to PLATFORMS before it is registered, so a missing
     * descriptor degrades the wording rather than throwing inside a lock.
     */
    public static function label(string $platform): string
    {
        return app(PlatformRegistry::class)->get($platform)?->getLabel() ?? ucfirst($platform);
    }
}
