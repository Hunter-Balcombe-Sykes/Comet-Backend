<?php

namespace App\Services\Design\Presets;

/**
 * Shim: presents a legacy per-connection {@see DesignFactor} as an
 * {@see EvidenceFactor} so it can be driven from the assembled IdentityEvidence
 * bag instead of a single IntegrationConnection. This is what lets the seven v1
 * factors keep their exact `detect(IntegrationConnection)` bodies while the v2
 * engine reasons over one assembled source.
 *
 * The adapter walks the evidence's active connections filtered to the wrapped
 * factor's platform and merges each connection's detection. When a user has more
 * than one connection of the same platform, later connections win a contested
 * column (last-writer) — matching the per-connection resolver loop, where each
 * connection's factor run upserts in turn.
 *
 * NOTE: the production resolver does NOT wrap the v1 factors — it keeps its
 * original per-connection loop (which also carries the one-shot freeze). This
 * adapter is the v2 bridge used where a single evidence-driven path is wanted
 * (and to prove, in tests, that the v1 factors read identically off the bag).
 */
final class IntegrationConnectionFactorAdapter implements EvidenceFactor
{
    public function __construct(private readonly DesignFactor $inner) {}

    public function key(): string
    {
        return $this->inner->key();
    }

    public function integrationLabel(): string
    {
        return $this->inner->integration();
    }

    public function mode(): FactorMode
    {
        return $this->inner->mode();
    }

    public function priority(): int
    {
        return $this->inner->priority();
    }

    /** @return array<string, string> */
    public function detect(IdentityEvidence $evidence): array
    {
        $platform = $this->inner->integration();

        $merged = [];
        foreach ($evidence->connections() as $connection) {
            if ($connection->platform !== $platform) {
                continue;
            }
            $merged = array_merge($merged, $this->inner->detect($connection));
        }

        return $merged;
    }
}
