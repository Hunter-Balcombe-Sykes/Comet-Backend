<?php

namespace App\Services\Platforms\Registry;

use App\Models\Core\User\User;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

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
            ->resource($resourceClass)->refreshable(false);
    }

    /** oEmbed music embed: resolves name/artwork on refresh. */
    public static function oEmbed(string $key, string $label, string $resourceClass): self
    {
        return self::make($key)->label($label)->category(PlatformCategory::Music)
            ->resource($resourceClass)->refreshable(true);
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

    // Capability seam — every dispatcher/route/render checks this. Returns true
    // for everyone today; future paid-tier/account-type gating sets a predicate
    // here (read via AccountCapabilities) without touching call sites.
    public function availableFor(User $user): bool
    {
        return true;
    }
}
