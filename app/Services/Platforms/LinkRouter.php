<?php

namespace App\Services\Platforms;

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\User\User;
use App\Services\Platforms\Concerns\BuildsAutoSyncFindings;
use App\Services\Platforms\Registry\Platform;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Support\Str;

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
                'booking' => $this->seedBooking($user, $platform, $url, $classified, $ctx),
                'event', 'event-organiser' => $this->seedEvent($user, $platform, $url, $classified),
                'shop' => $this->seedShop($user, $url, $ctx),
                // Recognised, deliberately not connected — a marketplace or
                // board (Amazon, LTK, Pinterest). custom() with handled:false so
                // the platform slot stays open and a creator's second LTK link
                // gets its own card instead of being skipped. Spends no probe:
                // that is the entire reason these hosts are classified at all.
                'link' => RouteResult::custom(),
                'reservations' => $this->seedReservation($user, $platform, $url, $classified),
                'online-ordering' => $this->seedOnlineOrdering($user, $platform, $url, $classified),
                default => RouteResult::custom(),
            };

            // Consume the platform slot only when the route actually HANDLED the
            // link. A gate denial or a thrown seeder must leave it open so a
            // later same-platform link in this run still gets its attempt —
            // the rule the deleted handleClassifiedLink() followed.
            if ($result->handled) {
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
        if (! $ctx->consumeProbeFor($url)) {
            return RouteResult::custom();
        }

        CommerceProbeJob::dispatch((string) $user->id, $url);

        return RouteResult::pending('commerce', '', '');
    }

    // ── Routing gates ────────────────────────────────────────────────────────

    /**
     * True when this account type + sector combination is allowed to auto-route
     * links of the given category. These are LinkRouter-internal gates —
     * deliberately separate from AccountCapabilities (Decision 6): the
     * capability formulas govern dashboard visibility and API access and are
     * intentionally looser here (can_use_reservations is `true` for every
     * partna account, which must NOT mean partna gets reservations auto-routed).
     *
     * Reading account_type via isBusiness() here is a DELIBERATE exception to
     * the "only AccountCapabilities touches account_type" rule — this gate has
     * to diverge from the capability, so deriving it from one would defeat the
     * point. The booking arm mirrors the capability's own shape
     * (`$isBusiness ? ! $isFood : true`) rather than `! $isFood` alone, because
     * sector is irrelevant for partna and a partna account CAN carry a food
     * sector — see AccountCapabilities' own note on that.
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
            'link' => true, // recognised-but-never-connected; its arm returns custom() and the caller writes the card
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
        $userId = (string) $user->id;
        $write = $this->resolveWrite($platform, $url);

        return $this->outcomeFrom(
            $this->resolveSocialLink($userId, $platform, $url, $write, $classified),
            $write,
            $classified,
        );
    }

    /**
     * @param  array{platform:string, category:string, label:string}  $classified
     */
    private function seedBooking(User $user, string $platform, string $url, array $classified, RouteContext $ctx): RouteResult
    {
        $userId = (string) $user->id;

        // Shared-key booking platforms (Booksy, Timely, etc.) — write directly
        // with provider string. Fresha/Square use the rich flow below.
        if ($platform === Platform::Booking->value) {
            $write = [
                'platform' => Platform::Booking->value,
                'resourceId' => Platform::Booking->value,
                'payload' => ['url' => $url, 'provider' => $classified['label'], 'source' => 'auto'],
            ];
        } else {
            $write = $this->resolveWrite($platform, $url);
        }

        // LIFE-106: booking's XOR invariant spans THREE platforms
        // (fresha/square/booking), so the WHOLE check-then-write span —
        // conflicting-provider query, existing-row lookup, tombstone check, write
        // — runs under ONE lock shared with GoogleBusinessAutoSync::seedBooking.
        // On contention the link routes to custom rather than being dropped.
        $result = $this->withBookingXorLock(
            $userId,
            fn () => $this->resolveBookingLink($userId, $platform, $url, $write, $classified),
            ['findings' => [], 'unmatched' => [['url' => $url, 'label' => $classified['label']]], 'consumed' => false],
        );

        $outcome = $this->outcomeFrom($result, $write, $classified);

        // Auto-connect the menu. Gated on the ORIGIN flag, not the call site:
        // route() has four callers and cannot tell them apart, and a dashboard
        // paste must never trigger this. Only on a real seed — a conflict, gate
        // denial or lock contention wrote nothing to fetch for.
        if ($outcome->outcome === 'seeded'
            && $platform === Platform::Fresha->value
            && $ctx->autoConnectBooking
            && (bool) config('partna.connect.auto_booking.enabled', true)
        ) {
            $this->dispatchAutoBookingConnect($userId);
        }

        return $outcome;
    }

    /**
     * @param  array{platform:string, category:string, label:string}  $classified
     */
    private function seedEvent(User $user, string $platform, string $url, array $classified): RouteResult
    {
        $category = $classified['category'];

        $resourceId = match ($category) {
            'event' => $this->events->seedStandalone($user, $platform, $url),
            'event-organiser' => $this->events->seedAccount($user, $platform, $url),
            default => null,
        };

        if ($resourceId === null) {
            return RouteResult::custom();
        }

        // F8: this used to seed with no finding at all, so a connected Eventbrite
        // never appeared in the synced modal. The resourceId must be the row's
        // OWN id, not the platform name every other category uses:
        // shapeFinding() resolves a seeded finding by "platform|resourceId" and
        // drops what it can't match, and an events platform holds many rows
        // ('event-<id>' / 'acct-<hash>'). Same reason the remove path can't be
        // the platform's forget route — that would delete every event the user
        // has, not the one this finding is about. removeEvent() re-adds the
        // 'event-' prefix itself; removeAccount() matches the full id.
        $removePath = $category === 'event'
            ? '/platforms/'.$platform.'/events/'.Str::after($resourceId, 'event-')
            : '/platforms/'.$platform.'/accounts/'.$resourceId;

        return RouteResult::seeded($platform, $resourceId, $category, [
            $this->seededFinding($platform, $resourceId, $category, $classified['label'], $url, $removePath),
        ]);
    }

    private function seedShop(User $user, string $url, RouteContext $ctx): RouteResult
    {
        // Commerce probe — async, dispatched as a job. The probe resolves
        // whether this is a storefront, product page, or neither. Counted
        // against the SAME per-run budget as an unclassified probe: both are
        // ~5 HTTP round-trips on the scraping queue, so one link-in-bio page
        // must not fan out unbounded just because its links classify as shop.
        if (! $ctx->consumeProbeFor($url)) {
            return RouteResult::custom();
        }

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

        return RouteResult::seeded(Platform::Reservations->value, Platform::Reservations->value, $classified['category'], [
            $this->seededFinding(Platform::Reservations->value, Platform::Reservations->value, $classified['category'], $classified['label'], $url),
        ]);
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

        return RouteResult::seeded(Platform::OnlineOrdering->value, Platform::OnlineOrdering->value, $classified['category'], [
            $this->seededFinding(Platform::OnlineOrdering->value, Platform::OnlineOrdering->value, $classified['category'], $classified['label'], $url),
        ]);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Translate a resolve*Link() result into a RouteResult, preserving the
     * seeded/conflict distinction the synced modal depends on.
     *
     * `consumed: false` is lock contention (or a thrown seeder) — the caller must
     * fall through to a custom link, and the platform slot must stay open.
     *
     * @param  array{findings:list<array<string,mixed>>,unmatched:list<array<string,mixed>>,consumed:bool}  $result
     * @param  array{platform:string,resourceId:string,payload:array<string,mixed>}  $write
     * @param  array{platform:string,category:string,label:string}  $classified
     */
    private function outcomeFrom(array $result, array $write, array $classified): RouteResult
    {
        if (! $result['consumed']) {
            return RouteResult::custom($result['unmatched']);
        }

        // Tombstoned: the user disconnected this platform before. Handled (so the
        // slot IS consumed — a second link to the same tombstoned platform must
        // not retry), but nothing written: offer it as a custom link rather than
        // resurrecting a connection the user chose to remove.
        if ($result['unmatched'] !== []) {
            return RouteResult::custom($result['unmatched'], handled: true);
        }

        // Already synced to the same url — a true no-op, no finding to surface.
        if ($result['findings'] === []) {
            return RouteResult::skipped();
        }

        $isConflict = ($result['findings'][0]['outcome'] ?? null) === 'conflict';

        return $isConflict
            ? RouteResult::conflict($write['platform'], $write['resourceId'], $classified['category'], $result['findings'])
            : RouteResult::seeded($write['platform'], $write['resourceId'], $classified['category'], $result['findings']);
    }

    private function reentrancyKey(User $user, string $url): string
    {
        return (string) $user->id.':'.sha1($url);
    }
}
