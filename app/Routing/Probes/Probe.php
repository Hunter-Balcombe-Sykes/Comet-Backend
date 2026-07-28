<?php

namespace App\Routing\Probes;

use App\Routing\Iri;

/**
 * One keyless platform probe (plan §11: "one connector, five probes").
 *
 * A probe must be cheap, keyless and platform-exclusive: it asks an endpoint
 * only that platform serves, so a well-formed answer IS the identification.
 * Anything requiring a key, a partner approval, or a heuristic read of general
 * HTML belongs in a connector or an importer, not here.
 *
 * Implementations must be side-effect free. The worker decides whether a probe
 * may run at all; a probe that gates or budgets itself would make those
 * decisions unauditable.
 */
interface Probe
{
    /** Stable name, recorded on the observation so a miss can be traced. */
    public function name(): string;

    /** The catalog surface a hit belongs to. */
    public function surfaceKey(): string;

    /**
     * Identify the storefront, or return null when this platform did not
     * answer. Must never throw for an unreachable host — an outage is a miss.
     *
     * @return array{identifier: string, evidence: array<string, mixed>}|null
     */
    public function attempt(Iri $iri): ?array;
}
