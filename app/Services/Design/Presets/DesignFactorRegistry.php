<?php

namespace App\Services\Design\Presets;

// The set of registered design factors. Bound as a singleton in
// AppServiceProvider with the concrete factor list; factorsFor() is what the
// resolver calls per changed integration. An EMPTY registry makes the whole
// preset system a provable no-op (nothing to contribute) — the dark-launch
// safety net.
class DesignFactorRegistry
{
    /** @param  list<DesignFactor>  $factors */
    public function __construct(private readonly array $factors = []) {}

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
