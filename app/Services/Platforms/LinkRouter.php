<?php

namespace App\Services\Platforms;

use App\Catalog\LegacyPlatformMap;
use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Routing\ShortLinkExpander;
use App\Routing\SourceReconciler;
use App\Services\Platforms\Concerns\BuildsAutoSyncFindings;
use App\Services\Platforms\Payloads\CardPayload;
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
        private readonly MediaSeeder $media,
        private readonly ShortLinkExpander $expander,
    ) {}

    /**
     * Classify + route one URL. Returns a RouteResult the caller acts on.
     *
     * @param  RouteContext  $ctx  per-run dedupe map + probe cap, owned by the calling loop
     */
    public function route(User $user, string $url, RouteContext $ctx): RouteResult
    {
        // FI-3 (2026-08-20): the legacy lane gets the same short-link
        // expansion as LinkRoutingService — a bio's on.soundcloud.com /
        // spotify.link short URL classifies as its real destination, not as
        // an opaque code that falls to a card.
        $url = $this->expander->expandIfShort($url);

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

        // First-link-per-platform wins (Issue M) — a rule about CONNECTIONS (you
        // have one Fresha account), never about links.
        //
        // custom(), not skipped(): the link still needs somewhere to go. skipped()
        // means "write nothing", and it has two other producers that genuinely
        // mean that — outcomeFrom()'s already-synced-to-this-exact-url no-op, and
        // the reentrancy guard above. Returning it here made callers unable to
        // tell those apart, so the second link of a platform (an influencer's
        // second LTK link, a "watch this video" beside a channel link) was written
        // NOWHERE. custom() routes it to the caller's card write, which is what
        // every other dead end in this method already does.
        // Item categories bypass the slot on BOTH sides (F2, both halves —
        // the critic reproduced the asymmetry: an artist link consuming the
        // slot degraded the SAME bio's track link to a card, order-dependent).
        $itemCategory = $category === 'event' || $category === 'content-item';
        // M-5 (matrix run 2, chinchin live): reservations and ordering manage
        // their own family slot INSIDE their seeders (incumbent check →
        // recordCapBlock Swap, idempotent per identifier) — short-circuiting
        // them here turned the SECOND OpenTable link on a restaurant's own
        // website (two venues, or just footer + button) into a junk card the
        // seeder never got to cap. Socials/booking/shop keep the short-circuit:
        // their seeders assume the slot check happened.
        $slotSelfManaged = $category === 'reservations' || $category === 'online-ordering';
        if (! $itemCategory && ! $slotSelfManaged && isset($ctx->seenPlatforms[$platform])) {
            return RouteResult::custom();
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
                'content-item' => $this->seedMediaItem($user, $url),
                'shop' => $this->seedShop($user, $url, $ctx),
                // Recognised host with no legacy arm of its own. Two flavours
                // share this category: marketplaces/boards that must NEVER
                // connect (Amazon, LTK — LINK_ONLY_HOSTS), and CONNECTABLE
                // catalog brands the legacy tables simply never learned
                // (Apple Music artist, Tidal, Booksy…). F6 (2026-08-20, the
                // the_046_official trace): the second flavour was CARDED here
                // while the same URL through the P8 importer or the paste
                // lane became a connection/suggestion. Ask Engine 1 first —
                // the narrow slice of the gap-9 P8 promotion, done at the one
                // call site every Engine-2 lane shares.
                'link' => $this->seedCatalogLink($user, $url),
                'reservations' => $this->seedReservation($user, $platform, $url, $classified),
                // 'bio_harvest': route() is the SCAN gateway (Instagram bio,
                // link-in-bio unroll). routeOrdering() below is the Google
                // listing's own door and says so itself — the origin has to be
                // one routing.source_intents' CHECK constraint accepts.
                'online-ordering' => $this->seedOnlineOrdering($user, $platform, $url, $classified, 'bio_harvest'),
                default => RouteResult::custom(),
            };

            // Consume the platform slot only when the route actually HANDLED the
            // link. A gate denial or a thrown seeder must leave it open so a
            // later same-platform link in this run still gets its attempt —
            // the rule the deleted handleClassifiedLink() followed.
            //
            // ITEM categories never consume the slot (F2, 2026-08-20): the
            // slot rule is about CONNECTIONS ("you have one Fresha account"),
            // and an event/video is not a connection — consuming it turned
            // the SECOND event or video from one platform in a run into a
            // bare card. Issue M's own words: "never about links".
            if ($result->handled && ! $itemCategory) {
                $ctx->seenPlatforms[$platform] = true;
            }

            return $result;
        } catch (\Throwable $e) {
            report($e);

            return RouteResult::custom();
        }
    }

    /**
     * Route ONE url through the ordering arm only, for a caller that already
     * knows it is handling an ordering link and has run its own capability gate
     * (GoogleBusinessAutoSync's ordering seed; the retired ordering category
     * controller was the other caller). Convergence Phase 6.
     *
     * Deliberately not route(): that classifies freely, so an Instagram URL
     * pasted into the ordering box would be SEEDED as a social connection before
     * this caller could object — and a write already made cannot be taken back.
     * Here anything that is not an ordering brand answers custom(), and the
     * caller sends it to the links pool under owner ruling 2A.
     *
     * The gate is the CALLER's: both entry points already ran
     * AccountCapabilities::can_use_online_ordering, and re-deriving it here
     * through gateAllows() would be a second, differently-shaped answer to a
     * question the capability set already owns (Decision 6).
     */
    public function routeOrdering(User $user, string $url, string $origin = 'google_business'): RouteResult
    {
        if ($user->isPendingDeletion()) {
            return RouteResult::custom();
        }

        $classified = $this->harvester->classify($url);
        if ($classified === null || $classified['category'] !== 'online-ordering') {
            return RouteResult::custom();
        }

        try {
            return $this->seedOnlineOrdering($user, $classified['platform'], $url, $classified, $origin);
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
            'content-item' => true, // T6: a video/track/episode is the owner's own content

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

        // Every booking brand that is not Fresha or Square writes a plain
        // provider card. Before convergence Phase 6 they all shared the
        // 'booking' pseudo-platform key; now each carries its own (booksy,
        // treatwell.book, …), so the branch is on "is this one of the two rich
        // flows" rather than on a single shared slug. The payload shape is
        // unchanged — the dashboard renders "Book with {provider}" off it.
        if ($platform === Platform::Fresha->value || $platform === Platform::Square->value) {
            $write = $this->resolveWrite($platform, $url);
        } else {
            $write = [
                'platform' => $platform,
                'resourceId' => $this->brandResourceId($platform),
                'payload' => ['url' => $url, 'provider' => $classified['label'], 'source' => 'auto'],
            ];
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
            && in_array($platform, [Platform::Fresha->value, Platform::Square->value], true)
            && $ctx->autoConnectBooking
            && (bool) config('partna.connect.auto_booking.enabled', true)
        ) {
            $this->dispatchAutoBookingConnect($userId, $platform);
        }

        return $outcome;
    }

    /**
     * @param  array{platform:string, category:string, label:string}  $classified
     */
    private function seedEvent(User $user, string $platform, string $url, array $classified): RouteResult
    {
        $category = $classified['category'];

        // R7 (owner, 2026-08-19): a pasted EVENT link writes an events-pool
        // item and nothing else — no connection row, no finding (the synced
        // modal that read the finding is retired). handled:true so no caller
        // files a link card for something the events pool now carries.
        if ($category === 'event') {
            $written = $this->events->seedStandalone($user, $platform, $url);

            return $written !== null ? RouteResult::custom(handled: true) : RouteResult::custom();
        }

        $resourceId = $category === 'event-organiser'
            ? $this->events->seedAccount($user, $platform, $url)
            : null;

        if ($resourceId === null) {
            return RouteResult::custom();
        }

        // F8: the resourceId must be the row's OWN id ('acct-<hash>'), not the
        // platform name — an events platform holds many rows, and the remove
        // path can't be the forget route (that would delete every account the
        // user has, not the one this finding is about).
        $removePath = '/platforms/'.$platform.'/accounts/'.$resourceId;

        return RouteResult::seeded($platform, $resourceId, $category, [
            $this->seededFinding($platform, $resourceId, $category, $classified['label'], $url, $removePath),
        ]);
    }

    /**
     * T6 (2026-08-20): a scanned VIDEO/TRACK/RELEASE/EPISODE link writes a
     * watch/listen-pool item and nothing else — the media twin of the
     * 'event' arm above. handled:true so no caller files a link card for
     * something the pool now carries; a failed read falls through to the
     * caller's card write. Lands in the LIBRARY, never auto-pinned.
     */
    private function seedMediaItem(User $user, string $url): RouteResult
    {
        $written = $this->media->seedItem($user, $url);

        return $written !== null ? RouteResult::custom(handled: true) : RouteResult::custom();
    }

    /**
     * F6: run a catalog-recognised URL through the P8 pipeline. A placement
     * or suggestion rides the reconciler (tombstones, caps, aliases all
     * apply exactly as for a paste); anything Engine 1 can't place falls
     * back to the card this arm always wrote. A 'choose'/'hold' answers
     * handled — the suggestions inbox owns the link now, and a card beside
     * an open question would double it (the same reason F3 exists).
     */
    private function seedCatalogLink(User $user, string $url): RouteResult
    {
        try {
            $result = app(LinkRoutingService::class)
                ->route($url, RoutingContext::forUser($user, 'bio_harvest'));
        } catch (\Throwable $e) {
            report($e);

            return RouteResult::custom();
        }

        return match ($result['verdict']) {
            'place' => RouteResult::seeded('catalog', (string) ($result['connectionId'] ?? ''), 'link', []),
            'choose', 'hold' => RouteResult::custom(handled: true),
            // note/reject: the card path — LINK_ONLY_HOSTS land here by
            // construction (their catalog class refuses placement), so the
            // Amazon/LTK behaviour is byte-identical.
            default => RouteResult::custom(),
        };
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
        // Reservations stay SINGLE-SLOT across the whole family. The legacy
        // clear-then-write (ReservationsController::clearReservations) was
        // deleted 2026-08-19 with the pseudo-platform lane, and this auto
        // lane must neither silently replace the incumbent nor duplicate it
        // — a taken family slot becomes a Swap offer in the inbox, exactly
        // like the ordering arm below. routing_class is the family key, so
        // this spans brands the classifier has never heard of.
        $userId = (string) $user->id;
        $family = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('routing_class', 'reservations')
            ->orderBy('created_at')
            ->get();
        $incumbent = $family->first(fn (IntegrationConnection $row) => ! $this->sameUrl(
            (string) (CardPayload::fromArray($row->payload)->url() ?? ''), $url,
        ));

        if ($incumbent !== null) {
            // M-4 (matrix run 2, chinchin live): this passed origin 'auto',
            // which routing.source_intents' origin CHECK constraint has never
            // accepted — the insert threw 23514, route()'s catch-all turned
            // the answer into custom(), and the two extra OpenTable links on
            // the business's own website CARDED instead of filing the Swap
            // this arm promises. Invisible to the suite because SQLite doesn't
            // enforce the PG CHECK. 'bio_harvest' is route()'s own origin —
            // the same literal the ordering arm below already passes.
            app(SourceReconciler::class)->recordCapBlock(
                $user,
                LegacyPlatformMap::surfaceFor($platform) ?? $platform,
                'reservations',
                $this->brandResourceId($platform),
                $url,
                (string) $incumbent->id,
                'bio_harvest',
            );

            return RouteResult::custom(handled: true);
        }

        // SEM-2: only when the family holds NO live row at all is "nothing
        // here" ambiguous. Mirrors outcomeFrom():589 — handled, so a second
        // reservations link in the run does not retry, but nothing is written.
        if ($family->isEmpty() && $this->wasDisconnected($userId, 'routing_class', 'reservations')) {
            return RouteResult::custom([['url' => $url, 'label' => $classified['label']]], handled: true);
        }

        $payload = [
            'url' => $url,
            'provider' => $classified['label'],
            'source' => 'auto',
        ];

        // Phase 6: the brand's own key, not the retired 'reservations' pseudo key.
        $resourceId = $this->brandResourceId($platform);
        $this->write((string) $user->id, $platform, $resourceId, $payload);

        return RouteResult::seeded($platform, $resourceId, $classified['category'], [
            $this->seededFinding($platform, $resourceId, $classified['category'], $classified['label'], $url),
        ]);
    }

    /**
     * @param  array{platform:string, category:string, label:string}  $classified
     */
    private function seedOnlineOrdering(User $user, string $platform, string $url, array $classified, string $origin): RouteResult
    {
        $surface = LegacyPlatformMap::surfaceFor($platform) ?? $platform;

        // ONE store per ordering brand (owner ruling 2026-08-16). A second Uber
        // Eats store for the same user does not get a second connection. The
        // alternative was widening the `order:{platform}` collection natural
        // key, which slice 4 declined to touch because it rewrites every
        // collection's key.
        //
        // Same URL is not a second store: that is a re-sync, and write() below
        // is an updateOrCreate on it.
        $userId = (string) $user->id;
        $stores = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('surface_key', $surface)
            ->orderBy('created_at')
            ->get();
        $incumbent = $stores->first(fn (IntegrationConnection $row) => ! $this->sameUrl(
            (string) (CardPayload::fromArray($row->payload)->url() ?? ''), $url,
        ));

        if ($incumbent !== null) {
            // It becomes a SWAP OFFER, not a links-pool card (owner,
            // 2026-08-19). The old fall-through published a link the owner
            // never asked for and gave them no way to say "use that one
            // instead"; a cap-blocked intent naming the incumbent is exactly
            // what the suggestions inbox renders as Swap. `handled: true`
            // still, so no caller writes a card for it.
            // M-6 (critic on M-5, 2026-08-21): key the intent on the STORE
            // (host|path), not the full URL. With M-5 letting every ordering
            // link reach this arm, a page carrying N query-string variants of
            // one store's link (?pickup, ?diningMode…) minted N distinct
            // identifiers → N duplicate Swap rows in the inbox (the live
            // dedup index is (user, surface, identifier)). Distinct stores
            // still get distinct offers; variants of one store coalesce.
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $storePath = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
            app(SourceReconciler::class)->recordCapBlock(
                $user,
                $surface,
                'ordering',
                'order-'.substr(sha1($host.'|'.$storePath), 0, 16),
                $url,
                (string) $incumbent->id,
                $origin,
            );

            return RouteResult::custom(handled: true);
        }

        // SEM-2: only when this brand holds NO live store at all is "nothing
        // here" ambiguous. Mirrors seedReservation() above.
        if ($stores->isEmpty() && $this->wasDisconnected($userId, 'surface_key', $surface)) {
            return RouteResult::custom([['url' => $url, 'label' => $classified['label']]], handled: true);
        }

        $payload = [
            'url' => $url,
            'provider' => $classified['label'],
            'name' => $classified['label'],
            'source' => 'auto',
        ];

        // Ordering is a MULTI-entry family across brands, so the resource id is
        // url-derived rather than the brand — and it is byte-identical to
        // OnlineOrderingController::entryResourceId(), because the actions layer
        // emits `ordering:<resource_id>` action ids that users store preferences
        // against. A different shape here would fork those ids by write path.
        $resourceId = 'order-'.substr(sha1(strtolower($url)), 0, 16);
        $this->write((string) $user->id, $platform, $resourceId, $payload);

        return RouteResult::seeded($platform, $resourceId, $classified['category'], [
            $this->seededFinding($platform, $resourceId, $classified['category'], $classified['label'], $url),
        ]);
    }

    /**
     * The single-slot resource id for a brand — its brand prefix, whether the
     * caller passed a legacy slug ('booksy') or a catalog surface key
     * ('treatwell.book'). Matches what the existing per-brand rows already use
     * (opentable/resdiary/nowbookit all store resource_id = their slug).
     */
    private function brandResourceId(string $platform): string
    {
        return LegacyPlatformMap::legacyFor(
            LegacyPlatformMap::surfaceFor($platform) ?? $platform,
        );
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
