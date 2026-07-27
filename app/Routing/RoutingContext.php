<?php

namespace App\Routing;

use App\Models\Core\User\User;

/**
 * Everything about the CALLER that a placement decision may consider — and
 * nothing about the link itself. Keeping this separate from Iri/Projection is
 * what makes the projector pure and reproducible: the same URL always
 * projects identically, and only placement varies by who is asking.
 */
final readonly class RoutingContext
{
    public function __construct(
        public ?User $user,
        /** Where the link came from — decides auto-apply vs suggest. */
        public string $origin = 'paste',
        /** Pre-account/staff builds have no user yet (plan §2, Decision 7). */
        public bool $preAccount = false,
        public ?string $importRunId = null,
    ) {}

    public static function forUser(User $user, string $origin = 'paste', ?string $importRunId = null): self
    {
        return new self($user, $origin, false, $importRunId);
    }

    public static function preAccountBuild(string $origin = 'staff', ?string $importRunId = null): self
    {
        return new self(null, $origin, true, $importRunId);
    }

    /**
     * A user pasting a link is asking for it directly; a harvester found it
     * incidentally. The distinction decides how much confidence we demand
     * before writing without asking.
     */
    public function isDirectRequest(): bool
    {
        return $this->origin === 'paste';
    }
}
