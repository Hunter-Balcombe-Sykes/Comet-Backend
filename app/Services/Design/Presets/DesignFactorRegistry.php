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
     * @param  list<DesignFactor>  $factors
     * @param  list<SiteDesignFactor>  $siteFactors
     */
    public function __construct(
        private readonly array $factors = [],
        private readonly array $siteFactors = [],
    ) {}

    /** @return list<SiteDesignFactor> */
    public function siteFactors(): array
    {
        return $this->siteFactors;
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
