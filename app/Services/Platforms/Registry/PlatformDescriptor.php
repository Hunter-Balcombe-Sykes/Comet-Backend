<?php

namespace App\Services\Platforms\Registry;

use App\Models\Core\User\User;
use App\Services\Platforms\Payloads\EmbedPayload;
use App\Services\Platforms\Payloads\LinkPayload;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\Strategies\Contracts\Detection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
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

    private bool $coverable = false;

    private ?Detection $detection = null;

    private ?ConnectStrategy $connectStrategy = null;

    private ?string $connectErrorMessage = null;

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
     * Cover-image capability — whether this platform has a design-pool cover-image
     * singleton slot (`cover_<key>`). Drives SiteMedia::designSingletonPurposes() so
     * adding a cover for a new platform is this one flag, not a new const + list +
     * migration. Mirrors refreshable(): identity metadata, no behaviour attached.
     */
    public function coverable(bool $coverable = true): self
    {
        $this->coverable = $coverable;

        return $this;
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

    public function isCoverable(): bool
    {
        return $this->coverable;
    }

    /**
     * Attach the live connect strategy — the platform's URL/handle normalizer
     * wrapped in a ConnectStrategy (UrlConnect) — plus the 422 message shown when
     * the input can't be parsed. The generic controller reads both. The message
     * is part of the frozen API contract, so each platform keeps its exact wording.
     */
    public function connect(ConnectStrategy $strategy, string $errorMessage): self
    {
        $this->connectStrategy = $strategy;
        $this->connectErrorMessage = $errorMessage;

        return $this;
    }

    public function connectStrategy(): ?ConnectStrategy
    {
        return $this->connectStrategy;
    }

    public function connectErrorMessage(): ?string
    {
        return $this->connectErrorMessage;
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
     * (routes/api/integrations.php iterates it to emit routes), so eagerly resolving
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
     * Capability gate — intentional not-yet-wired seam. Returns true for all
     * users today. Currently consulted at EXACTLY ONE production call site:
     * `GenericPlatformController::connect` via `abort_unless($descriptor->availableFor($user), 403)`.
     *
     * Introducing a non-trivial predicate (e.g. paid-tier gating) REQUIRES first
     * wiring the ~20 bespoke connect flows + `PublicIntegrationController::show`.
     * The preferred wiring point for the public render path is
     * `ManagesIntegrationConnection::writeConnection()`, which would need to resolve
     * `app(PlatformRegistry::class)->get($this->platform())` since the trait holds
     * no descriptor today.
     *
     * CRITICAL — if ever wired into the PUBLIC render path it MUST return 404, not
     * 403. The public endpoint already 404s unknown handles to prevent enumeration;
     * a 403 would leak handle existence to unauthenticated callers.
     */
    public function availableFor(User $user): bool
    {
        return true;
    }
}
