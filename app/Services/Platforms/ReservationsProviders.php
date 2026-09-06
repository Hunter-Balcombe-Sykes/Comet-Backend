<?php

namespace App\Services\Platforms;

use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\Registry\PlatformRegistry;

/**
 * The single-slot reservations family — the platforms that compete for one
 * "Book a table" slot, of which a user may have at most one connected (the
 * XOR that CacheKeyGenerator::reservationsXorLock serialises).
 *
 * D2 (2026-09-06, the suggestion-system auto-connect sweep): BookingProviders'
 * own docblock named this family and its lock back on 2026-09-02, but declined
 * to model it here because "nothing yet needs it to be" — reservations
 * connects only ever went through the auto-sync seeder path
 * (GoogleBusinessAutoSync/SourceReconciler), which already takes
 * reservationsXorLock. GenericPlatformController::connect()'s ordinary
 * dashboard "connect" endpoint for these three platforms never did: it locks
 * only the per-platform key, with no cross-brand check at all — an ordinary
 * POST /platforms/opentable/connect followed by POST /platforms/resdiary/connect,
 * no race required, leaves both simultaneously live. Same shape as
 * BookingProviders; see that class for the full "why a class, not inline
 * hasConflictingConnection calls" rationale.
 */
final class ReservationsProviders
{
    /** @var list<string> */
    public const PLATFORMS = [
        Platform::OpenTable->value,
        Platform::Resdiary->value,
        Platform::Nowbookit->value,
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

    /** The name a user sees in a 409 — same registry-first, ucfirst-fallback shape as BookingProviders::label(). */
    public static function label(string $platform): string
    {
        return app(PlatformRegistry::class)->get($platform)?->getLabel() ?? ucfirst($platform);
    }
}
