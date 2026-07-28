<?php

namespace App\Routing\Probes;

use App\Catalog\CatalogIntegrityCheck;
use App\Routing\Iri;
use App\Services\Http\FetchBudget;
use Throwable;

/**
 * The probe runtime (P8 blocker B1, plan §11).
 *
 * `probe_capability` has existed as compiled catalog metadata since the
 * catalog landed, and nothing has ever executed one: the pure projector cannot
 * fetch by design, so a merchant's own-domain storefront had no way to be
 * identified at all. This is the piece that runs them.
 *
 * Deliberately NOT a router stage. The projector must stay pure and
 * reproducible — `routing:reproject` is only a diff tool because the same URL
 * always projects identically, and a stage that made network calls would end
 * that. A probe is instead an ASYNC second opinion: something noticed a URL
 * the projector could not place, a job asks this worker, and the answer
 * re-enters the pipeline as an ordinary Projection through PlacementPolicy and
 * SourceReconciler. Every gate — tombstones, capabilities, thresholds — then
 * applies to a probed storefront exactly as to a pasted link, because it IS
 * the same path.
 *
 * Queue-only (plan §11): a probe is one or more outbound requests to a
 * third-party host with no bound on how slow it can be. Nothing here may be
 * called from a request cycle.
 */
class LinkProbeWorker
{
    /** @var list<Probe> */
    private readonly array $probes;

    public function __construct(
        private readonly ProbeGate $gate,
        private readonly ProbeBudget $budget,
        private readonly FetchBudget $fetchBudget,
        ShopifyStorefrontProbe $shopify,
    ) {
        // Ordered: cheapest and most decisive first. Four more join here
        // (Woo, Squarespace, BigCartel, generic JSON-LD) — the registry is the
        // extension point, and every one of them ships the same contract.
        $this->probes = [$shopify];
    }

    /**
     * Mark the start of a run, resetting the per-run probe allowance.
     *
     * A batch importer routes many links through one worker; the per-run
     * dimension only means anything if something says where the run begins.
     * A queue job gets this for free — the budget is a scoped binding, so
     * forgetScopedInstances() makes one job one run — but an importer looping
     * inside a single job has to say so.
     */
    public function startRun(): void
    {
        $this->budget->startRun();
    }

    /**
     * Identify what a URL is, when the projector could not.
     *
     * @param  ?string  $userId  null for pre-account/staff builds — still budgeted globally
     */
    public function probe(Iri $iri, ?string $userId): ProbeOutcome
    {
        // A URL the projector already placed has nothing to probe for, and
        // spending a request to re-confirm a detector is pure waste.
        if ($this->alreadyServable($iri)) {
            return ProbeOutcome::refused('already_matched');
        }

        $cached = $this->gate->cachedAnswer($iri);
        if ($cached !== null) {
            return $this->fromCache($cached);
        }

        $decision = $this->gate->allows($iri, $userId);
        if (! $decision['allowed']) {
            return ProbeOutcome::refused((string) $decision['reason']);
        }

        $outcome = $this->run($iri);

        $this->gate->remember($iri, [
            'outcome' => $outcome->outcome,
            'surface_key' => $outcome->surfaceKey,
            'identifier' => $outcome->identifier,
            'probe' => $outcome->probe,
            'evidence' => $outcome->evidence,
            'reason' => $outcome->reason,
        ]);

        return $outcome;
    }

    /**
     * One wall-clock budget for the WHOLE cascade, not one per probe: five
     * probes each allowed their own full timeout is how a "cheap" check
     * becomes a stalled queue worker. ensureOpen so an outer budget (an
     * importer bounding a whole page) still governs.
     */
    private function run(Iri $iri): ProbeOutcome
    {
        return $this->fetchBudget->ensureOpen(
            (float) config('partna.routing.probe.budget_seconds', 15),
            fn (): ProbeOutcome => $this->cascade($iri),
        );
    }

    private function cascade(Iri $iri): ProbeOutcome
    {
        foreach ($this->probes as $probe) {
            if ($this->fetchBudget->exhausted()) {
                return ProbeOutcome::miss('budget_wall_clock');
            }

            // A probe that throws is a probe that missed. One platform's
            // outage must never abort the cascade before the others answer.
            try {
                $hit = $probe->attempt($iri);
            } catch (Throwable) {
                continue;
            }

            if ($hit === null) {
                continue;
            }

            if (! CatalogIntegrityCheck::isServable($probe->surfaceKey())) {
                // The probe is right and the build cannot serve it — an honest
                // miss, not a match we then refuse downstream.
                continue;
            }

            return ProbeOutcome::matched(
                $probe->surfaceKey(),
                (string) $hit['identifier'],
                $probe->name(),
                $hit['evidence'],
            );
        }

        return ProbeOutcome::miss();
    }

    /** @param array<string, mixed> $cached */
    private function fromCache(array $cached): ProbeOutcome
    {
        if (($cached['outcome'] ?? null) !== 'matched') {
            return ProbeOutcome::miss((string) ($cached['reason'] ?? 'no_probe_matched'));
        }

        return ProbeOutcome::matched(
            (string) $cached['surface_key'],
            (string) $cached['identifier'],
            (string) ($cached['probe'] ?? 'cached'),
            (array) ($cached['evidence'] ?? []),
        );
    }

    /**
     * A tenant-scoped host (acme.myshopify.com) sits under a platform suffix
     * and already has a detector — the projector places it without spending
     * anything. The probe exists for the merchant's OWN domain, which carries
     * no host signal at all.
     */
    private function alreadyServable(Iri $iri): bool
    {
        return $iri->tenantScoped;
    }
}
