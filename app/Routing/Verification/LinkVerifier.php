<?php

namespace App\Routing\Verification;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * L2 — "does the page this link names exist?". The network half of validity;
 * L1 (App\Routing\LinkValidity) is the free structural half and runs first.
 *
 * This class is only the dispatcher. The knowledge of how to ask a given brand
 * lives in a per-surface adapter, because there is no general answer: the four
 * behaviour classes we measured against live sites on 2026-09-03 are
 *
 *   A   free and definitive — a real 404 for a fake id (Quandoo, Booksy, X,
 *       YouTube, GitHub, Spotify, Calendly)
 *   A′  definitive only through the crawler prerender the brand publishes for
 *       search engines (Resy)
 *   B   answerable only through a paid actor we already pay for (Uber Eats via
 *       memo23, DoorDash via dz_omar)
 *   C   no mechanism at all — 200 on fabricated handles (Instagram, Facebook,
 *       TikTok, Pinterest, Reddit, Etsy, OpenTable, LinkedIn, Google Maps)
 *
 * A surface with no adapter is Class C by definition and resolves Blocked,
 * which is save-and-flag. That is the DEFAULT, and it is why adding this lane
 * cannot make any existing link unsaveable: a brand we have not taught it
 * about behaves exactly as it did before, plus a flag.
 *
 * Adapters are registered rather than discovered, so the set is readable in one
 * place and a surface cannot acquire verification behaviour by accident.
 */
class LinkVerifier
{
    /**
     * surface key => adapter class. Every surface is Class C until an adapter
     * proves otherwise, and a wrong adapter is worse than none: a false
     * NotFound refuses a link that was fine.
     *
     * Each entry below was measured on 2026-09-03 with the real page and a
     * fabricated id fetched side by side. A 404 on the fake alone proves
     * nothing — a brand that 404s everything, or that blocked us, looks
     * exactly the same. The evidence per brand is in PlainNotFoundAdapter's
     * docblock, including the two that were tested and REJECTED (Spotify
     * answers 200 for a fabricated artist id; Resy answers 404 only to a
     * crawler user agent).
     *
     * A method rather than a const so the declared type governs: as a literal
     * const, static analysis narrows it to a shape and reads the misses below
     * as dead code.
     *
     * @return array<string, class-string<VerificationAdapter>>
     */
    private static function adapters(): array
    {
        return [
            'quandoo.reserve' => PlainNotFoundAdapter::class,
            'github.profile' => PlainNotFoundAdapter::class,
            'calendly.book' => PlainNotFoundAdapter::class,
            'youtube.channel' => PlainNotFoundAdapter::class,
            'x.profile' => PlainNotFoundAdapter::class,
            // 200 + a redirect carrying its own "deleted" marker, never a 404.
            'booksy.book' => BooksyAdapter::class,
        ];
    }

    public function verify(string $surfaceKey, string $url): VerificationVerdict
    {
        $adapterClass = self::adapters()[$surfaceKey] ?? null;

        if ($adapterClass === null) {
            return VerificationVerdict::Blocked;
        }

        try {
            return app($adapterClass)->verify($url);
        } catch (Throwable $e) {
            // An adapter that throws has told us nothing about the link, which
            // is precisely Blocked. Reported, not swallowed: a consistently
            // throwing adapter is a bug we need to see, but it must never cost
            // the user their link.
            report($e);

            Log::warning('routing.verify.adapter_failed', [
                'surface_key' => $surfaceKey,
                'adapter' => $adapterClass,
            ]);

            return VerificationVerdict::Blocked;
        }
    }

    /** Whether this surface can be checked at all — false means Class C. */
    public function canVerify(string $surfaceKey): bool
    {
        return isset(self::adapters()[$surfaceKey]);
    }
}
