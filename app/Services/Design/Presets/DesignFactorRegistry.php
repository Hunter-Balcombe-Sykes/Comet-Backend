<?php

namespace App\Services\Design\Presets;

// The set of registered design factors. Bound as a singleton in
// AppServiceProvider with the concrete factor lists; factorsFor() is what the
// resolver calls per changed integration, siteFactors() runs on every resolve
// (site-level state / cross-connection aggregates). An EMPTY registry makes
// the whole preset system a provable no-op (nothing to contribute) — the
// dark-launch safety net.
class DesignFactorRegistry
{
    /**
     * @param  list<DesignFactor>  $factors  v1 per-connection factors
     * @param  list<SiteDesignFactor>  $siteFactors  v1 site-level factors
     * @param  list<EvidenceFactor>  $evidenceFactors  v2 factors over the assembled IdentityEvidence bag
     */
    public function __construct(
        private readonly array $factors = [],
        private readonly array $siteFactors = [],
        private readonly array $evidenceFactors = [],
    ) {}

    /** @return list<SiteDesignFactor> */
    public function siteFactors(): array
    {
        return $this->siteFactors;
    }

    /** @return list<EvidenceFactor> */
    public function evidenceFactors(): array
    {
        return $this->evidenceFactors;
    }

    /**
     * Factors sourced from the given platform slug.
     *
     * @return list<DesignFactor>
     */
    public function factorsFor(string $platform): array
    {
        return array_values(array_filter(
            $this->factors,
            fn (DesignFactor $factor): bool => $factor->integration() === $platform,
        ));
    }

    /** @return list<DesignFactor> */
    public function all(): array
    {
        return $this->factors;
    }
}
