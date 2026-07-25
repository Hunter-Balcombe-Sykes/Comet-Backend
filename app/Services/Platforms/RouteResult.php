<?php

namespace App\Services\Platforms;

/**
 * Value object returned by LinkRouter::route(). Carries the routing decision
 * back to the caller so it can act on it (return a typed response, or fall
 * through to a custom-link write).
 *
 * It also carries the findings/unmatched entries the write path produced. That
 * is not decoration: the Instagram synced modal's contract distinguishes
 * "seeded" from "conflict" — a platform already connected to a DIFFERENT link,
 * which the user resolves with Swap — and a conflict writes nothing. Collapsing
 * the two into outcome:'seeded', as the first cut of this class did, reported
 * success for a link that was never written and dropped the Swap surface
 * entirely.
 */
final class RouteResult
{
    /**
     * @param  'seeded'|'conflict'|'custom'|'pending'|'skipped'  $outcome
     * @param  list<array<string,mixed>>  $findings  synced-modal findings this route produced
     * @param  list<array<string,mixed>>  $unmatched  custom-link suggestions this route produced
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $platform,
        public readonly string $resourceId,
        public readonly string $category,
        public readonly array $findings = [],
        public readonly array $unmatched = [],
        /**
         * True when this route CONSUMED the platform's slot for the run, so a
         * later link classifying to the same platform is skipped (Issue M
         * first-link-wins). A gate denial or a thrown seeder must NOT consume it
         * — the next same-platform link still deserves its attempt — but a
         * tombstoned platform must, even though it returns 'custom'.
         */
        public readonly bool $handled = false,
    ) {}

    /**
     * A row was written.
     *
     * @param  list<array<string,mixed>>  $findings
     */
    public static function seeded(string $platform, string $resourceId, string $category, array $findings = []): self
    {
        return new self('seeded', $platform, $resourceId, $category, $findings, [], handled: true);
    }

    /**
     * The platform is already connected to a different link, or a rival booking
     * provider is live. Nothing was written; the finding offers the swap.
     *
     * @param  list<array<string,mixed>>  $findings
     */
    public static function conflict(string $platform, string $resourceId, string $category, array $findings): self
    {
        return new self('conflict', $platform, $resourceId, $category, $findings, [], handled: true);
    }

    /**
     * Gate denied, seeder failed, tombstoned, or probe budget spent — the caller
     * writes a custom link so the link is never silently dropped.
     *
     * @param  list<array<string,mixed>>  $unmatched
     */
    public static function custom(array $unmatched = [], bool $handled = false): self
    {
        return new self('custom', 'custom', '', '', [], $unmatched, handled: $handled);
    }

    public static function pending(string $platform, string $resourceId, string $category): self
    {
        return new self('pending', $platform, $resourceId, $category);
    }

    /**
     * Nothing to do: this platform already won its slot this run (Issue M
     * first-link-per-platform wins), the link is already synced to the same url,
     * or the reentrancy guard tripped.
     */
    public static function skipped(): self
    {
        return new self('skipped', '', '', '', [], [], handled: true);
    }
}
