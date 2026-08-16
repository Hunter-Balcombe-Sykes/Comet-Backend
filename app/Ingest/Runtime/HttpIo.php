<?php

namespace App\Ingest\Runtime;

use App\Ingest\Manifest\Manifest;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\PrecheckableBilledEffect;
use App\Services\Http\SafeUrlFetcher;

/**
 * The production Io. Every call passes three gates before a socket opens, in
 * this order, because each is cheaper than the next:
 *
 *   1. ADMISSION — is this host on the connector's own manifest? A URL
 *      derived from vendor data can otherwise send us anywhere.
 *   2. SSRF — SafeUrlFetcher's public-address + own-infra policy.
 *   3. BUDGET/LEDGER — for billed effects only; free HTTP is not ledgered
 *      (the ledger exists to stop double-CHARGING, not double-fetching).
 *
 * A connector cannot skip these because it has no other way to reach the
 * network: that is the entire reason Io is an interface and not a helper.
 */
class HttpIo implements Io
{
    public function __construct(
        private readonly Manifest $manifest,
        private readonly SafeUrlFetcher $fetcher,
        private readonly EffectLedger $ledger,
        private readonly BilledEffectDriverRegistry $drivers,
        private readonly ?string $runId = null,
        private readonly ?string $sourceId = null,
        // Both drivers spend per-user budget: PlacesBudget carries a per-user
        // daily cap, and Instagram threads it for log correlation.
        private readonly ?string $userId = null,
    ) {}

    public function get(string $url, array $headers = []): array
    {
        $this->admit($url);

        $response = $this->fetcher->tryFetch($url, $headers);

        return $response === null
            ? ['status' => 0, 'body' => '', 'headers' => []]
            : ['status' => $response['status'], 'body' => $response['body'], 'headers' => [
                'content-type' => $response['contentType'],
                'etag' => $response['etag'],
                'last-modified' => $response['lastModified'],
                'final-url' => $response['finalUrl'],
            ]];
    }

    public function post(string $url, array $body = [], array $headers = []): array
    {
        $this->admit($url);

        // SafeUrlFetcher::post() re-validates every redirect hop and caps the
        // response body — the same guarantees get()/getMany() already have.
        $response = $this->fetcher->post($url, $body, $headers);

        return ['status' => $response['status'], 'body' => $response['body'], 'headers' => [
            'content-type' => $response['contentType'],
            'final-url' => $response['finalUrl'],
        ]];
    }

    public function getMany(array $urls, array $headers = []): array
    {
        foreach ($urls as $url) {
            $this->admit($url);
        }

        $responses = $this->fetcher->fetchMany($urls, $headers);

        $out = [];
        foreach ($responses as $url => $response) {
            $out[$url] = $response === null ? null : [
                'status' => $response['status'],
                'body' => $response['body'],
                'headers' => ['content-type' => $response['contentType'], 'final-url' => $response['finalUrl']],
            ];
        }

        return $out;
    }

    public function effect(string $kind, string $name, array $input): array
    {
        if (! config('partna.ingest.billed_effects_enabled')) {
            // Refused BEFORE the ledger, so no row is written and the digest stays
            // claimable the moment the switch is flipped. EffectRefused (not
            // EffectNotAttempted) because nothing was budgeted — RunExecutor folds it
            // to budget_skipped either way.
            throw new EffectRefused(
                "Billed effects are disabled in this environment (effect '{$kind}/{$name}') — ".
                'set PARTNA_INGEST_BILLED_EFFECTS_ENABLED=true to activate.'
            );
        }

        // Freshness-bucketed: within one window a retry or sibling stream
        // replays the stored result; the next window re-bills deliberately —
        // without it a recurring billed fetch would be one-shot forever.
        $digest = EffectLedger::digestFor(
            $kind,
            ['name' => $name] + $input,
            (int) config('partna.ingest.effect_freshness_seconds'),
        );

        $outcome = $this->ledger->once(
            digest: $digest,
            kind: $kind,
            effect: fn () => $this->runBilledEffect($kind, $name, $input),
            runId: $this->runId,
            sourceId: $this->sourceId,
            costTag: $name,
            costUnits: $this->manifest->cost->budgetWeight(),
            // #MONEY-2. A driver that already knows it will not attempt this
            // effect says so before the ledger writes a claim row it would
            // only delete again. Opt-in: a driver without a cheap up-front
            // check simply does not implement the interface, and its dispatch
            // is byte-for-byte unchanged.
            precheck: function () use ($kind, $name, $input): void {
                $driver = $this->drivers->for($kind, $name);
                if ($driver instanceof PrecheckableBilledEffect) {
                    $driver->precheck($this->contextFor($kind, $name, $input));
                }
            },
        );

        if ($outcome['status'] !== 'ok') {
            // A refused/abandoned/cached effect is not a failure of this run —
            // it is the ledger doing its job. The caller decides what an
            // absent result means for its stream.
            return ['status' => $outcome['status'], 'cached' => $outcome['cached'], 'data' => null];
        }

        return ['status' => 'ok', 'cached' => $outcome['cached'], 'data' => $outcome['result']];
    }

    /**
     * Perform the effect. Called from INSIDE EffectLedger::once(), which is why
     * claim-first and charge-once hold however a driver behaves.
     *
     * @param  array<string, mixed>  $input
     * @return array<int|string, mixed>|null null is an ANSWER ("nothing here"),
     *                                       never an outage — see EffectNoAnswer.
     */
    private function runBilledEffect(string $kind, string $name, array $input): ?array
    {
        $driver = $this->drivers->for($kind, $name);

        if ($driver === null) {
            // Loud, as it has always been: a connector must not declare a billed
            // effect nothing can perform, and staying loud is what stops such a
            // declaration reading as free.
            //
            // EffectNotAttempted, NOT a bare RuntimeException. This is the one
            // ending where provably NOTHING left the process — there is no driver
            // to have called a vendor with. A generic throw settles the digest
            // `failed`, which locks this source+input until the freshness bucket
            // rolls, so wiring the missing driver would change nothing until the
            // bucket expired; that is precisely the state EffectNotAttempted's
            // contract exists to avoid, and it is what made slice 4's second menu
            // run produce no new effect rows at all (spec §23.6).
            throw new EffectNotAttempted(
                "No billed-effect driver is wired for kind '{$kind}' (effect '{$name}'). ".
                'A connector must not declare a billed effect it cannot perform.'
            );
        }

        $result = $driver->run($this->contextFor($kind, $name, $input));

        if ($result->outcome === BilledEffectOutcome::NoAnswer) {
            throw new EffectNoAnswer($result->reason ?? "billed effect '{$kind}/{$name}' returned no answer");
        }

        return $result->data;
    }

    /**
     * The one place a BilledEffectContext is built, so precheck() and run()
     * can never judge different inputs for the same effect.
     *
     * @param  array<string, mixed>  $input
     */
    private function contextFor(string $kind, string $name, array $input): BilledEffectContext
    {
        return new BilledEffectContext(
            kind: $kind,
            name: $name,
            input: $input,
            runId: $this->runId,
            sourceId: $this->sourceId,
            userId: $this->userId,
        );
    }

    private function admit(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || ! $this->manifest->mayContact($host)) {
            throw new EffectRefused(
                "Connector {$this->manifest->source} may not contact {$host} — ".
                'add it to the manifest hosts if this is intended.'
            );
        }
    }
}
