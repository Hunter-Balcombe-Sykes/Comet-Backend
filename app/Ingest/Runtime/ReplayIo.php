<?php

namespace App\Ingest\Runtime;

use App\Ingest\Manifest\Manifest;

/**
 * Tier-C test Io: replays a digest-keyed effect log instead of touching the
 * network, so a connector's real pull() can be exercised against recorded
 * vendor responses.
 *
 * A missing digest fails LOUDLY with both the digest and the canonical
 * request, because that message is directly usable as capture input — the
 * fixture you need is named in the failure that told you it was missing.
 */
class ReplayIo implements Io
{
    /** @param array<string, array<string, mixed>> $effects digest => recorded response */
    public function __construct(
        private readonly Manifest $manifest,
        private array $effects,
        /** @var list<string> digests actually consumed — lets a test assert no dead fixtures */
        private array $consumed = [],
    ) {}

    /** @param list<array<string, mixed>> $lines decoded effects.jsonl */
    public static function fromLog(Manifest $manifest, array $lines): self
    {
        $effects = [];
        foreach ($lines as $line) {
            $effects[$line['digest']] = $line['response'] ?? [];
        }

        return new self($manifest, $effects);
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->replay('http', ['method' => 'GET', 'url' => $url]);
    }

    public function post(string $url, array $body = [], array $headers = []): array
    {
        return $this->replay('http', ['method' => 'POST', 'url' => $url, 'body' => $body]);
    }

    public function getMany(array $urls, array $headers = []): array
    {
        $out = [];
        foreach ($urls as $url) {
            $out[$url] = $this->replay('http', ['method' => 'GET', 'url' => $url]);
        }

        return $out;
    }

    public function effect(string $kind, string $name, array $input): array
    {
        return $this->replay($kind, ['name' => $name] + $input);
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function replay(string $kind, array $request): array
    {
        $host = isset($request['url']) ? strtolower((string) parse_url((string) $request['url'], PHP_URL_HOST)) : null;
        if ($host !== null && ! $this->manifest->mayContact($host)) {
            throw new EffectRefused("Connector {$this->manifest->source} may not contact {$host}");
        }

        $digest = EffectLedger::digestFor($kind, $request);

        if (! array_key_exists($digest, $this->effects)) {
            throw new \RuntimeException(sprintf(
                "No recorded effect for digest %s.\nRequest: %s\nCapture it with: php artisan ingest:capture --digest=%s",
                $digest,
                json_encode($request, JSON_UNESCAPED_SLASHES),
                $digest,
            ));
        }

        $this->consumed[] = $digest;

        return $this->effects[$digest];
    }

    /** @return list<string> fixtures the connector never asked for */
    public function unusedDigests(): array
    {
        return array_values(array_diff(array_keys($this->effects), $this->consumed));
    }
}
