<?php

namespace App\Services\Platforms;

/**
 * Value object returned by LinkRouter::route(). Carries the routing decision
 * back to the caller so it can act accordingly (return a typed response,
 * dispatch enrichment, or fall through to a custom-link write).
 */
final class RouteResult
{
    /**
     * @param  'seeded'|'custom'|'pending'|'skipped'  $outcome
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $platform,
        public readonly string $resourceId,
        public readonly string $category,
    ) {}

    public static function seeded(string $platform, string $resourceId, string $category): self
    {
        return new self('seeded', $platform, $resourceId, $category);
    }

    public static function custom(): self
    {
        return new self('custom', 'custom', '', '');
    }

    public static function pending(string $platform, string $resourceId, string $category): self
    {
        return new self('pending', $platform, $resourceId, $category);
    }

    public static function skipped(): self
    {
        return new self('skipped', '', '', '');
    }
}
