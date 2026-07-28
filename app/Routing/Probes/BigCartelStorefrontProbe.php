<?php

namespace App\Routing\Probes;

use App\Routing\Iri;
use App\Services\Platforms\BigCartelScraper;

/**
 * The Big Cartel probe — the host-decisive half of the legacy
 * ShopProviderDetector, ported (plan §11).
 *
 * `*.bigcartel.com` is a suffix override, so a PASTED tenant URL never
 * reaches the worker's cascade at all: the detector places it for free and
 * the worker refuses `already_matched`. This probe exists for the callers
 * that hold a bigcartel host and want the store VERIFIED with its identity
 * evidence in hand (name, currency) — a seeder settling a store card, an
 * importer that found the link inside a page — without re-implementing the
 * store.json round-trip the legacy detector carried.
 *
 * Custom-domain Big Cartel stores expose no keyless platform-only endpoint
 * (the public API hangs off api.bigcartel.com/{account}, and the account is
 * unknowable from the domain), so they fall through to the generic JSON-LD
 * probe — exactly as they did under the legacy detector.
 *
 * Delegates to BigCartelScraper (P8 deletion list) for the same reason
 * ShopifyStorefrontProbe delegates: when the endpoint drifts, one expression
 * changes.
 */
class BigCartelStorefrontProbe implements Probe
{
    public function __construct(private readonly BigCartelScraper $bigcartel) {}

    public function name(): string
    {
        return 'bigcartel_store_json';
    }

    public function surfaceKey(): string
    {
        return 'bigcartel.store';
    }

    public function attempt(Iri $iri): ?array
    {
        if ($iri->host === null) {
            return null;
        }

        // Pure host work first: a non-bigcartel host costs zero requests here
        // and moves straight on to the probes that can actually claim it.
        $account = $this->bigcartel->accountFromUrl('https://'.$iri->host.'/');
        if ($account === null) {
            return null;
        }

        // The host says Big Cartel; store.json says whether the store is real
        // (a deleted account's subdomain still resolves). A dead store is a
        // miss, matching the legacy detector's unreachable branch.
        $store = $this->bigcartel->fetchStore($account);
        if ($store === null) {
            return null;
        }

        return [
            // "bigcartel-{account}" — the same id the legacy detect() carried on
            // its store array, so a probed store and a wizard-connected one
            // dedupe to the same brand row.
            'identifier' => (string) $store['id'],
            'evidence' => [
                'origin' => $store['origin'],
                'shop_name' => $store['name'],
                'currency' => $store['currency'],
            ],
        ];
    }
}
