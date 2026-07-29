<?php

namespace App\Ingest\Runtime;

use App\Ingest\Manifest\Manifest;
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
        private readonly ?string $runId = null,
        private readonly ?string $sourceId = null,
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
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function runBilledEffect(string $kind, string $name, array $input): array
    {
        throw new \RuntimeException(
            "No billed-effect driver is wired for kind '{$kind}' (effect '{$name}'). ".
            'Actor and AI drivers land with their connectors at P7; until then a '.
            'connector must not declare a billed effect it cannot perform.'
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
