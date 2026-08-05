<?php

namespace App\Services\Platforms\Registry;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\Payloads\EmbedPayload;
use App\Services\Platforms\Payloads\LinkPayload;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\Strategies\Contracts\Detection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;
use App\Services\Platforms\Strategies\Contracts\RefreshStrategy;
use App\Services\Platforms\Strategies\Refresh\NoRefresh;
use App\Services\Platforms\Strategies\Refresh\ScheduledRefresh;
use Closure;

// One declaration per platform — the single source of identity the registry,
// validation, refresher, and (later) the generic controller read from. Built via
// the fluent builder or an archetype preset. Behaviour (live strategy instances)
// is attached as platforms migrate in later plans; this plan carries identity +
// metadata + the Resource that shapes responses + a refreshable flag.
class PlatformDescriptor
{
    private string $label;

    private ?PlatformCategory $category = null;

    private ?string $resourceClass = null;

    private bool $refreshable = false;

    /** Per-platform refresh cadence in seconds; null = fall back to config('partna.refresh.default_ttl_seconds'). */
    private ?int $refreshInterval = null;

    /** @var array<int, array{key: string, label: string, description: string}> */
    private array $displayToggles = [];

    private ?Detection $detection = null;

    /** @var (Closure(): ConnectStrategy)|null Lazily builds the connect strategy (same rationale as fetch()). */
    private ?Closure $connectFactory = null;

    private ?string $connectErrorMessage = null;

    /**
     * Whether this platform's connect strategy implements DeferredConnect and
     * may run its content fetch on the queue (Phase 2 of
     * docs/superpowers/plans/2026-07-20-platform-connect-async.md). A DECLARED
     * flag, deliberately NEVER derived via `connectStrategy() instanceof
     * DeferredConnect` — see deferredConnect()'s docblock for why.
     */
    private bool $deferredConnect = false;

    /**
     * The user-facing message for a DEFERRED content-fetch failure (as opposed
     * to connectErrorMessage(), which is the parse-fail message). See
     * connectFetchError()'s docblock.
     */
    private ?string $connectFetchErrorMessage = null;

    private ?string $connectField = null;

    /** @var array<int, mixed> */
    private array $connectRules = [];

    /** @var array<string, string> */
    private array $connectMessages = [];

    private bool $connectNormalizesUrlish = false;

    private PlatformRouteShape $routeShape = PlatformRouteShape::Bespoke;

    private ?string $connectController = null;

    private bool $multiAccount = false;

    private ?string $payloadClass = null;

    /** @var (Closure(): FetchStrategy)|null Lazily builds the fetch strategy (see fetch()). */
    private ?Closure $fetchFactory = null;

    /** @var (Closure(): FetchStrategy)|null Lazily builds the CONNECT-only fetch strategy (see connectFetch()). */
    private ?Closure $connectFetchFactory = null;

    /** @var (Closure(): HighlightsStrategy)|null Lazily builds the highlights strategy (same rationale as fetch()). */
    private ?Closure $highlightsFactory = null;

    /** @var (Closure(User): bool)|null Optional capability predicate consulted by availableFor(). Null = always available (every platform's default). */
    private ?Closure $capabilityGate = null;

    /** @var (Closure(IntegrationConnection): bool)|null Optional content predicate consulted by isComplete(). Null = an active row is always complete (every platform's default). */
    private ?Closure $completenessGate = null;

    private function __construct(private readonly string $key)
    {
        $this->label = $key;
    }

    public static function make(string $key): self
    {
        return new self($key);
    }

    /** Link-only social: store a URL, no fetch, no refresh. */
    public static function linkOnly(string $key, string $label, string $resourceClass): self
    {
        return self::make($key)->label($label)->category(PlatformCategory::Social)
            ->resource($resourceClass)->refreshable(false)
            ->payload(LinkPayload::class);
    }

    /** oEmbed music embed: resolves name/artwork on refresh. */
    public static function oEmbed(string $key, string $label, string $resourceClass): self
    {
        return self::make($key)->label($label)->category(PlatformCategory::Music)
            ->resource($resourceClass)->refreshable(true)
            ->payload(EmbedPayload::class);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function category(PlatformCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function resource(string $resourceClass): self
    {
        $this->resourceClass = $resourceClass;

        return $this;
    }

    public function refreshable(bool $refreshable = true): self
    {
        $this->refreshable = $refreshable;

        return $this;
    }

    /**
     * Per-integration public display toggles — what parts of this platform's
     * synced content the owner can hide from their sitepage (e.g. Google
     * Business reviews). Declared here so the settings UI, the PATCH
     * validation, and the public-payload suppression all read one source; a
     * platform without toggles renders no Display card. A toggle defaults ON
     * unless the def carries 'default' => false; storage is the connection's
     * sparse display_settings JSONB. (The `siteColumn` bridge died 2026-08-05
     * with the site columns it served — see AutoSyncSetting.)
     *
     * @param  array<int, array{key: string, label: string, description: string, default?: bool}>  $toggles
     */
    public function displayToggles(array $toggles): self
    {
        $this->displayToggles = $toggles;

        return $this;
    }

    /** @return array<int, array{key: string, label: string, description: string, default?: bool}> */
    public function displayToggleDefs(): array
    {
        return $this->displayToggles;
    }

    public function hasDisplayToggles(): bool
    {
        return $this->displayToggles !== [];
    }

    /** Attach the smart-detect URL matcher (booking/reservations/events providers). */
    public function detect(Detection $detection): self
    {
        $this->detection = $detection;

        return $this;
    }

    public function detection(): ?Detection
    {
        return $this->detection;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCategory(): ?PlatformCategory
    {
        return $this->category;
    }

    public function resourceClass(): ?string
    {
        return $this->resourceClass;
    }

    public function isRefreshable(): bool
    {
        return $this->refreshable;
    }

    /** Override how often this platform is re-fetched (seconds). Null (default) uses the global config TTL. */
    public function refreshEvery(int $seconds): self
    {
        $this->refreshInterval = $seconds;

        return $this;
    }

    public function refreshInterval(): ?int
    {
        return $this->refreshInterval;
    }

    /**
     * Attach the live connect strategy — the platform's URL/handle normalizer
     * wrapped in a ConnectStrategy (UrlConnect) — plus the 422 message shown when
     * the input can't be parsed. The generic controller reads both. The message
     * is part of the frozen API contract, so each platform keeps its exact wording.
     */
    public function connect(ConnectStrategy|Closure $strategy, string $errorMessage): self
    {
        $this->connectFactory = $strategy instanceof Closure ? $strategy : fn () => $strategy;
        $this->connectErrorMessage = $errorMessage;

        return $this;
    }

    public function connectStrategy(): ?ConnectStrategy
    {
        return $this->connectFactory !== null ? ($this->connectFactory)() : null;
    }

    /**
     * Attach the strategy that drives this platform's recent-items picker and
     * curated-highlights save. Same lazy-Closure-factory rationale as fetch()/
     * connect(): the registry singleton is built at boot (the route loop calls
     * hasHighlights() while emitting routes), so eagerly resolving a strategy
     * that wraps a scraper here would bake it in before a test can mock it.
     */
    public function highlights(HighlightsStrategy|Closure $strategy): self
    {
        $this->highlightsFactory = $strategy instanceof Closure ? $strategy : fn () => $strategy;

        return $this;
    }

    /** Resolve the highlights strategy fresh (lazy — see highlights()); null when none attached. */
    public function highlightsStrategy(): ?HighlightsStrategy
    {
        return $this->highlightsFactory !== null ? ($this->highlightsFactory)() : null;
    }

    /** Boot-safe: the route loop calls this while emitting routes — it must
     *  never resolve the factory (that would eager-load the scraper). */
    public function hasHighlights(): bool
    {
        return $this->highlightsFactory !== null;
    }

    public function connectErrorMessage(): ?string
    {
        return $this->connectErrorMessage;
    }

    /**
     * Declare that this platform's connect strategy implements DeferredConnect
     * and may run its content fetch on the queue. BOOT-SAFE BY CONSTRUCTION: a
     * route loop iterating the registry at boot to decide whether to emit a
     * connect-status route CANNOT call connectStrategy() to find out — that
     * resolves the lazy factory and bakes a real scraper into the descriptor
     * before any test can mock it (the same reason hasHighlights() is a flag
     * rather than a null-check/instanceof on the resolved strategy — see that
     * method's docblock). This is a declared flag, never an `instanceof`.
     *
     * RegistryConnectCoverageTest asserts flag ⇔ instanceof for every
     * descriptor, so a descriptor can't declare deferredConnect() without its
     * strategy actually implementing the interface (or vice versa).
     */
    public function deferredConnect(bool $enabled = true): self
    {
        $this->deferredConnect = $enabled;

        return $this;
    }

    /** Boot-safe: never resolves connectStrategy() — see deferredConnect(). */
    public function supportsDeferredConnect(): bool
    {
        return $this->deferredConnect;
    }

    /**
     * The user-facing message shown when the DEFERRED content fetch fails —
     * the exact string this platform's resolve() returns today for the
     * equivalent fetch-stage failure. Under async connect that message can no
     * longer be delivered as a 422 response body (the request already returned
     * 202 before the fetch ran), so it is stored here and surfaced verbatim by
     * the eventual connect-status endpoint. Contract-preserving by
     * construction: same words, different transport.
     */
    public function connectFetchError(string $message): self
    {
        $this->connectFetchErrorMessage = $message;

        return $this;
    }

    public function connectFetchErrorMessage(): ?string
    {
        return $this->connectFetchErrorMessage;
    }

    /**
     * Declare this platform's connect-request validation contract — the single
     * input field, its Laravel rules (incl. regex), any custom 422 messages, and
     * whether the field is run through PlatformInput::urlish() before validation
     * (scheme-less pastes that the regex anchors on https?:// would otherwise miss).
     * Read by the shared PlatformConnectRequest. The field name + rules + messages
     * are part of the frozen API contract, so each platform reproduces its exact set.
     *
     * @param  array<int, mixed>  $rules
     * @param  array<string, string>  $messages
     */
    public function connectInput(string $field, array $rules, array $messages = [], bool $normalizeUrlish = false): self
    {
        $this->connectField = $field;
        $this->connectRules = $rules;
        $this->connectMessages = $messages;
        $this->connectNormalizesUrlish = $normalizeUrlish;

        return $this;
    }

    /** The connect-request input field name, or null when this platform isn't shared-request driven. */
    public function connectField(): ?string
    {
        return $this->connectField;
    }

    /** @return array<int, mixed> */
    public function connectRules(): array
    {
        return $this->connectRules;
    }

    /** @return array<string, string> */
    public function connectMessages(): array
    {
        return $this->connectMessages;
    }

    public function connectNormalizesUrlish(): bool
    {
        return $this->connectNormalizesUrlish;
    }

    /**
     * Declare the route archetype the registry-driven loop should emit for this
     * platform. $connectController is the controller class serving the bespoke
     * connect (and, for SingleSelection, selection/DELETE too); null for LinkOnly
     * (served by GenericPlatformController). $multiAccount gates the /accounts pair
     * for the MultiAccount shape.
     */
    public function routes(PlatformRouteShape $shape, ?string $connectController = null, bool $multiAccount = false): self
    {
        $this->routeShape = $shape;
        $this->connectController = $connectController;
        $this->multiAccount = $multiAccount;

        return $this;
    }

    public function routeShape(): PlatformRouteShape
    {
        return $this->routeShape;
    }

    public function connectController(): ?string
    {
        return $this->connectController;
    }

    public function multiAccount(): bool
    {
        return $this->multiAccount;
    }

    /** The typed DTO that hydrates this platform's stored payload (read boundary). */
    public function payload(string $payloadClass): self
    {
        $this->payloadClass = $payloadClass;

        return $this;
    }

    public function payloadClass(): ?string
    {
        return $this->payloadClass;
    }

    /**
     * Attach the strategy that re-pulls this platform's display snapshot from
     * upstream. Accepts either a ready FetchStrategy or — preferred for strategies
     * that wrap a scraper/API client — a Closure factory that builds it on demand.
     *
     * The factory form is LOAD-BEARING: the registry singleton is built at app boot
     * (routes/api/platforms.php iterates it to emit routes), so eagerly resolving
     * a scraper here would bake the real client into the descriptor before a test can
     * bind its mock (the SEC-1 registry-timing gotcha). Deferring resolution to
     * fetchStrategy() call-time — inside PlatformRefresher, after any mock is bound —
     * keeps the strategy honest regardless of WHEN the registry is first built. A bare
     * instance is wrapped in a closure so its identity is preserved.
     *
     * Null for link-only / no-fetch platforms. Consumed by the registry-driven
     * refresher (`$registry->refreshable()` → `fetchStrategy()->fetch($connection)`).
     *
     * @param  FetchStrategy|(Closure(): FetchStrategy)  $strategy
     */
    public function fetch(FetchStrategy|Closure $strategy): self
    {
        $this->fetchFactory = $strategy instanceof Closure ? $strategy : fn () => $strategy;

        return $this;
    }

    /** Resolve the fetch strategy fresh (lazy — see fetch()); null when none attached. */
    public function fetchStrategy(): ?FetchStrategy
    {
        return $this->fetchFactory !== null ? ($this->fetchFactory)() : null;
    }

    /**
     * CA-W6: attach the strategy ConnectFetchJob should run to COMPLETE a fresh
     * connect, when it differs from this platform's own refresh fetchStrategy().
     * Fresha is the first (and, as of this unit, only) user: FreshaFetch is
     * refresh-only (throws on a pending row with no selection — see its own
     * docblock) and calls FreshaServiceProjector::sync(), which individual-mode
     * connect must never trigger. Same lazy-Closure-factory contract as fetch()
     * — the SEC-1 registry-timing gotcha applies identically (the registry
     * singleton is built at boot to emit routes).
     *
     * @param  FetchStrategy|(Closure(): FetchStrategy)  $strategy
     */
    public function connectFetch(FetchStrategy|Closure $strategy): self
    {
        $this->connectFetchFactory = $strategy instanceof Closure ? $strategy : fn () => $strategy;

        return $this;
    }

    /**
     * Resolve the strategy ConnectFetchJob should run for a fresh connect.
     * Defaults to fetchStrategy() when no connectFetch() was registered — this
     * is what keeps the seven already-armed deferred platforms (spotify,
     * bandcamp, twitch, strava, vimeo, youtube-music, youtube)
     * resolving byte-identically to today; only a descriptor that calls
     * connectFetch() (Fresha) diverges.
     */
    public function connectFetchStrategy(): ?FetchStrategy
    {
        return $this->connectFetchFactory !== null ? ($this->connectFetchFactory)() : $this->fetchStrategy();
    }

    /**
     * The refresh behaviour for this platform, derived from its fetch strategy and
     * refreshable flag: a re-pull-and-persist ScheduledRefresh when refreshable with
     * a fetch, else the no-op NoRefresh. The registry-driven PlatformRefresher calls
     * refreshStrategy()->run() and wraps it to record the failure buckets the
     * strategy intentionally doesn't carry.
     */
    public function refreshStrategy(): RefreshStrategy
    {
        return $this->refreshable && $this->fetchFactory !== null
            ? new ScheduledRefresh($this->fetchStrategy())
            : new NoRefresh;
    }

    /**
     * Restrict connect() to accounts where the given predicate on
     * AccountCapabilities returns true — e.g. the reservation-family platforms
     * (opentable/resdiary/nowbookit) gated on can_use_reservations (2026-07-15
     * industry/sector gating). Wraps the same availableFor() seam every other
     * descriptor already implements as always-true; only descriptors that opt
     * in change behaviour.
     *
     * @param  Closure(User): bool  $predicate
     */
    public function requiresCapability(Closure $predicate): self
    {
        $this->capabilityGate = $predicate;

        return $this;
    }

    /**
     * Capability gate — consulted at EXACTLY ONE production call site:
     * `GenericPlatformController::connect` → `IntegrationConnectionPolicy::connect`
     * via `$descriptor->availableFor($actor)` (403 on deny). True for every
     * platform that hasn't called requiresCapability() (the vast majority).
     *
     * A platform whose connect ALSO has a bespoke (non-generic) controller —
     * Fresha, Square, the Booking/Reservations custom-card fallbacks, Menu,
     * Online-ordering — is NOT covered by this seam; each of those gates itself
     * inline (mirrors FreshaController::connect's can_book_storewide check).
     * Only wire requiresCapability() on a platform whose connect is fully
     * registry-driven (routeShape() !== Bespoke, null connectController).
     *
     * CRITICAL — if ever wired into the PUBLIC render path it MUST return 404, not
     * 403. The public endpoint already 404s unknown handles to prevent enumeration;
     * a 403 would leak handle existence to unauthenticated callers.
     */
    public function availableFor(User $user): bool
    {
        return $this->capabilityGate === null || ($this->capabilityGate)($user);
    }

    /**
     * Declare when a connection of this platform carries real, publishable
     * content. Default (no call) = an active row is always complete, which is
     * true for every url-only platform (square, the reservations family) and
     * every platform whose connect writes its payload in full.
     *
     * Opt in only where connect can legitimately leave a row half-built: fresha
     * (auto-harvest from an Instagram bio or Google Business saves a url with no
     * selection — InstagramAutoSync::resolveWrite, GoogleBusinessAutoSync::
     * resolveBookingWrite) and shop (a brand can exist with zero chosen
     * products, FOUND-25).
     *
     * @param  Closure(IntegrationConnection): bool  $predicate
     */
    public function complete(Closure $predicate): self
    {
        $this->completenessGate = $predicate;

        return $this;
    }

    /**
     * Whether this connection has publishable content. Read by the public
     * page-presence gate (SitepageDataResolverService::presentPageIds), the
     * Book-now fallback (SiteActionsService::pool), the dashboard status
     * endpoint (BookingController::statusFor) and the reconcile command.
     *
     * A predicate MAY query — shop's does — so callers on the public render
     * path wrap it in safeQuery() and fail CLOSED (hide the page), matching the
     * posture the inline shop gate had before this seam existed.
     *
     * NOT consulted by PublicIntegrationController: an incomplete fresha row's
     * url is the Book-now destination, so the row must keep reaching the
     * sitepage renderer. See the spec's "Decided" section before wiring this
     * into filterPayload().
     */
    public function isComplete(IntegrationConnection $connection): bool
    {
        return $this->completenessGate === null || ($this->completenessGate)($connection);
    }

    /**
     * Whether this platform has opted into the completeness seam at all (see
     * complete()). Lets callers that only care about content-dependent
     * page-presence — e.g. IntegrationConnectionObserver's cache-touch gate —
     * scope themselves to just the platforms isComplete() can return false
     * for, without hardcoding a platform-key list that would silently go
     * stale as more platforms opt in.
     */
    public function hasCompletenessPredicate(): bool
    {
        return $this->completenessGate !== null;
    }
}
