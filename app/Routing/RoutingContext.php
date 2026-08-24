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
    /**
     * The full set of origins the routing.source_intents origin CHECK and
     * routing.link_observations source CHECK accept. A new origin is a SCHEMA
     * change: both constraints AND this list, in the same commit. Validated at
     * construction because an unlisted origin used to survive all the way to
     * the intent INSERT and roll back the whole apply transaction (M-12, B6:
     * origin 'google_business_website' silently cost the Instagram connect the
     * router had already decided to apply).
     *
     * @var list<string>
     */
    public const ORIGINS = [
        'paste', 'website_import', 'link_in_bio', 'bio_harvest',
        'google_business', 'staff', 'reproject', 'commerce_probe',
    ];

    public function __construct(
        public ?User $user,
        /** Where the link came from — decides auto-apply vs suggest. */
        public string $origin = 'paste',
        /** Pre-account/staff builds have no user yet (plan §2, Decision 7). */
        public bool $preAccount = false,
        public ?string $importRunId = null,
    ) {
        if (! in_array($origin, self::ORIGINS, true)) {
            throw new \InvalidArgumentException(
                "Unknown routing origin '{$origin}' — the routing.* CHECK constraints would reject its ledger writes. Allowed: ".implode(', ', self::ORIGINS)
            );
        }
    }

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
