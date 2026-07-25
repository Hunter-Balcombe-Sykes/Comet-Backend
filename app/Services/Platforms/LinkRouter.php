<?php

namespace App\Services\Platforms;

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\Concerns\BuildsAutoSyncFindings;
use App\Services\Platforms\Registry\Platform;
use App\Services\Profile\SectorTaxonomy;

/**
 * Single classification+routing gateway for every link entering the system.
 *
 * Every entry point (manual add, Instagram bio scrape, link-in-bio unroll)
 * calls route(). The router decides whether the URL is a known platform and
 * whether this account is allowed to have that kind of connection, then either
 * creates the typed connection or returns a 'custom' outcome so the caller
 * falls through to CustomLinkSeeder::seedCustom().
 *
 * Uses BuildsAutoSyncFindings for the shared write helpers (resolveWrite,
 * resolveBookingLink, withBookingXorLock) — Decision 9, Phase 2.5.
 */
class LinkRouter
{
    use BuildsAutoSyncFindings;

    /** Reentrancy guard — static per-request URL set (Issue H / B1). */
    private static array $routing = [];

    public function __construct(
        private readonly WebsiteLinkHarvester $harvester,
        private readonly EventsSeeder $events,
    ) {}

    /**
     * Classify + route one URL. Returns a RouteResult the caller acts on.
     *
     * @param  RouteContext  $ctx  per-run dedupe map + probe cap, owned by the calling loop
     */
    public function route(User $user, string $url, RouteContext $ctx): RouteResult
    {
        $key = $this->reentrancyKey($user, $url);
        if (isset(self::$routing[$key])) {
            return RouteResult::skipped();
        }
        self::$routing[$key] = true;

        try {
            return $this->routeUnsafe($user, $url, $ctx);
        } finally {
            unset(self::$routing[$key]);
        }
    }

    private function routeUnsafe(User $user, string $url, RouteContext $ctx): RouteResult
    {
        if ($user->isPendingDeletion()) {
            return RouteResult::custom();
        }

        $classified = $this->harvester->classify($url);

        if ($classified === null) {
            return $this->routeUnclassified($user, $url, $ctx);
        }

        return $this->routeClassified($user, $url, $classified, $ctx);
    }

    /**
     * @param  array{platform:string, category:string, label:string}  $classified
     */
    private function routeClassified(User $user, string $url, array $classified, RouteContext $ctx): RouteResult
    {
        $platform = $classified['platform'];
        $category = $classified['category'];

        // First-link-per-platform wins (Issue M).
        if (isset($ctx->seenPlatforms[$platform])) {
            return RouteResult::skipped();
        }

        // Check routing gate (separate from AccountCapabilities).
        if (! $this->gateAllows($user, $category)) {
            return RouteResult::custom();
        }

        try {
            $result = match ($category) {
                'social' => $this->seedSocial($user, $platform, $url, $classified),
                'booking' => $this->seedBooking($user, $platform, $url, $classified),
                'event', 'event-organiser' => $this->seedEvent($user, $platform, $url, $category),
                'shop' => $this->seedShop($user, $url),
                'reservations' => $this->seedReservation($user, $platform, $url, $classified),
                'online-ordering' => $this->seedOnlineOrdering($user, $platform, $url, $classified),
                default => RouteResult::custom(),
            };

            if ($result->outcome === 'seeded') {
                $ctx->seenPlatforms[$platform] = true;
            }

            return $result;
        } catch (\Throwable $e) {
            report($e);

            return RouteResult::custom();
        }
    }

    private function routeUnclassified(User $user, string $url, RouteContext $ctx): RouteResult
    {
        if ($ctx->probeCount >= $ctx->maxProbes) {
            return RouteResult::custom();
        }
        $ctx->probeCount++;

        CommerceProbeJob::dispatch((string) $user->id, $url);

        return RouteResult::pending('commerce', '', '');
    }

    // ── Routing gates ────────────────────────────────────────────────────────

    /**
     * True when this account type + sector combination is allowed to auto-route
     * links of the given category. These are LinkRouter-internal gates —
     * deliberately separate from AccountCapabilities (Decision 6).
     */
    private function gateAllows(User $user, string $category): bool
    {
        $isBusiness = $user->isBusiness();
        $isFood = SectorTaxonomy::isFood($user->sector);

        return match ($category) {
            'social' => true, // Decision 8: everyone
            'booking' => $isBusiness ? ! $isFood : true, // partna always, business non-food only
            'event', 'event-organiser' => true,
            'shop' => true,
            'reservations' => $isBusiness && $isFood, // business food only
            'online-ordering' => $isBusiness && $isFood, // business food only
            default => false,
        };
    }

    // ── Category seeders ──────────────────────────────────────────────────────

    /**
     * @param  array{platform:string, category:string, label:string}  $classified
     */
    private function seedSocial(User $user, string $platform, string $url, array $classified): RouteResult
    {
        $write = $this->resolveWrite($platform, $url);
        $this->write((string) $user->id, $write['platform'], $write['resourceId'], $write['payload']);

        return RouteResult::seeded($write['platform'], $write['resourceId'], $classified['category']);
    }

    /**
     * @param  array{platform:string, category:string, label:string}  $classified
     */
    private function seedBooking(User $user, string $platform, string $url, array $classified): RouteResult
    {
        $write = $this->resolveWrite($platform, $url);
        $userId = (string) $user->id;

        /**
         * LIFE-106: run under the booking XOR lock shared with GoogleBusinessAutoSync
         * and the controller-side connect paths. Contention → custom link.
         */
        $result = $this->withBookingXorLock(
            $userId,
            fn () => $this->resolveBookingLink($userId, $platform, $url, $write, $classified),
            ['findings' => [], 'unmatched' => [['url' => $url, 'label' => $classified['label']]], 'consumed' => true],
        );

        if ($result['consumed'] && $result['findings'] !== []) {
            return RouteResult::seeded($write['platform'], $write['resourceId'], $classified['category']);
        }

        return RouteResult::custom();
    }

    private function seedEvent(User $user, string $platform, string $url, string $category): RouteResult
    {
        $ok = match ($category) {
            'event' => $this->events->seedStandalone($user, $platform, $url),
            'event-organiser' => $this->events->seedAccount($user, $platform, $url),
            default => false,
        };

        if ($ok) {
            return RouteResult::seeded($platform, $platform, $category);
        }

        return RouteResult::custom();
    }

    private function seedShop(User $user, string $url): RouteResult
    {
        // Commerce probe — async, dispatched as a job. The probe resolves
        // whether this is a storefront, product page, or neither.
        CommerceProbeJob::dispatch((string) $user->id, $url, 'shop');

        return RouteResult::pending('shop', 'shop', 'shop');
    }

    /**
     * @param  array{platform:string, category:string, label:string}  $classified
     */
    private function seedReservation(User $user, string $platform, string $url, array $classified): RouteResult
    {
        $payload = [
            'url' => $url,
            'provider' => $classified['label'],
            'source' => 'auto',
        ];

        $this->write((string) $user->id, Platform::Reservations->value, Platform::Reservations->value, $payload);

        return RouteResult::seeded(Platform::Reservations->value, Platform::Reservations->value, $classified['category']);
    }

    /**
     * @param  array{platform:string, category:string, label:string}  $classified
     */
    private function seedOnlineOrdering(User $user, string $platform, string $url, array $classified): RouteResult
    {
        $payload = [
            'url' => $url,
            'provider' => $classified['label'],
            'name' => $classified['label'],
            'source' => 'auto',
        ];

        $this->write((string) $user->id, Platform::OnlineOrdering->value, Platform::OnlineOrdering->value, $payload);

        return RouteResult::seeded(Platform::OnlineOrdering->value, Platform::OnlineOrdering->value, $classified['category']);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function reentrancyKey(User $user, string $url): string
    {
        return (string) $user->id.':'.sha1($url);
    }
}
