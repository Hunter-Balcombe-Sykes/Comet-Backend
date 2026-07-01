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

    private bool $coverable = false;

    private ?Detection $detection = null;

    private ?ConnectStrategy $connectStrategy = null;

    private ?string $connectErrorMessage = null;

    private ?string $payloadClass = null;

    private ?FetchStrategy $fetchStrategy = null;

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
     * The strategy that re-pulls this platform's display snapshot from upstream.
     * Null for link-only / no-fetch platforms. Consumed by Plan 6's registry-driven
     * refresher (`$registry->refreshable()` → `fetchStrategy()->fetch($connection)`).
     */
    public function fetch(FetchStrategy $strategy): self
    {
        $this->fetchStrategy = $strategy;

        return $this;
    }

    public function fetchStrategy(): ?FetchStrategy
    {
        return $this->fetchStrategy;
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
        return $this->refreshable && $this->fetchStrategy !== null
            ? new ScheduledRefresh($this->fetchStrategy)
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
