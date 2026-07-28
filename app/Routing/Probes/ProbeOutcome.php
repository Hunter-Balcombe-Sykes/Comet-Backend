<?php

namespace App\Routing\Probes;

use App\Routing\Projection;

/**
 * What one probe run concluded about a URL.
 *
 * A probe answers exactly the question the projector cannot: a merchant's OWN
 * domain carries no host signal, so no detector can ever match it (see the
 * note on Shopify's definition). The probe fetches a keyless, platform-only
 * endpoint and reports which storefront answered.
 *
 * `refused` is deliberately distinct from `miss`: "we didn't look" and "we
 * looked and it isn't one" lead to different retries, and collapsing them is
 * how a budget exhaustion silently becomes "this isn't a shop".
 */
final readonly class ProbeOutcome
{
    private function __construct(
        public string $outcome,
        public ?string $surfaceKey = null,
        public ?string $identifier = null,
        public ?string $probe = null,
        /** @var array<string, mixed> Carried forward so the seeder needn't re-fetch. */
        public array $evidence = [],
        public ?string $reason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function matched(string $surfaceKey, string $identifier, string $probe, array $evidence = []): self
    {
        return new self('matched', $surfaceKey, $identifier, $probe, $evidence);
    }

    /** Every probe ran and none claimed the URL. */
    public static function miss(string $reason = 'no_probe_matched'): self
    {
        return new self('miss', reason: $reason);
    }

    /** The gate or the budget stopped us before any request went out. */
    public static function refused(string $reason): self
    {
        return new self('refused', reason: $reason);
    }

    public function isMatch(): bool
    {
        return $this->outcome === 'matched';
    }

    public function wasRefused(): bool
    {
        return $this->outcome === 'refused';
    }

    /**
     * A probe result IS a projection — the same shape the pure projector emits,
     * so a probed URL goes through PlacementPolicy and SourceReconciler
     * unchanged. That matters more than it looks: it is what keeps tombstones,
     * capability gates and the single-writer property applying to probed
     * storefronts exactly as they apply to pasted links.
     *
     * Confidence is the probe's own floor rather than a scored one: a
     * platform-only endpoint answering with a well-formed body is direct
     * evidence, not a pattern guess. Margin is full — nothing else competed.
     */
    public function toProjection(): Projection
    {
        return new Projection(
            surfaceKey: $this->surfaceKey,
            detectorId: null,
            captures: [],
            confidence: $this->isMatch() ? ProbeGate::PROBE_CONFIDENCE : 0,
            margin: $this->isMatch() ? 100 : 0,
            identifier: $this->identifier,
            reason: $this->isMatch() ? null : ($this->reason ?? 'probe_miss'),
        );
    }
}
