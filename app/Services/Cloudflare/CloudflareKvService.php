<?php

namespace App\Services\Cloudflare;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Wraps the Cloudflare Workers KV REST API for the subdomain routing table.
// Entries are keyed by subdomain handle; values are JSON routing descriptors
// read by the Edge Worker to decide brand pass-through vs affiliate redirect.
//
// Gracefully no-ops when unconfigured (local dev without CF credentials).
class CloudflareKvService
{
    private readonly string $accountId;

    private readonly string $namespaceId;

    private readonly string $apiToken;

    private readonly bool $configured;

    public function __construct()
    {
        $this->accountId = (string) config('services.cloudflare.account_id', '');
        $this->namespaceId = (string) config('services.cloudflare.kv_namespace_id', '');
        $this->apiToken = (string) config('services.cloudflare.api_token', '');
        $this->configured = $this->accountId !== '' && $this->namespaceId !== '' && $this->apiToken !== '';
    }

    /**
     * Write a routing entry for a subdomain handle.
     *
     * @param  array<string, mixed>  $value
     * @param  int|null  $expirationTtl  Seconds until Cloudflare auto-evicts the key. Null = permanent.
     *                                   Cloudflare KV enforces a minimum of 60s; callers should pre-clamp.
     */
    public function put(string $key, array $value, ?int $expirationTtl = null): void
    {
        if (! $this->configured) {
            $this->guardUnconfigured('put', ['key' => $key]);

            return;
        }

        $url = $this->url($key);
        if ($expirationTtl !== null) {
            // Cloudflare KV: per-write TTL passed as ?expiration_ttl=<seconds> query param.
            // Without it, the key is permanent — which silently regressed alias auto-eviction
            // (see audit SYNC-1, 2026-05-20).
            $url .= '?expiration_ttl='.$expirationTtl;
        }

        // EDGE-1: explicit bounds — without these, Laravel's blanket defaults
        // (connect 10s / total 30s) apply and a slow-but-not-hung KV write can
        // ride along far longer than the caller (a job/observer) budgets for.
        Http::withToken($this->apiToken)
            ->withBody((string) json_encode($value), 'text/plain')
            ->timeout(10)
            ->connectTimeout(3)
            ->put($url)
            ->throw();
    }

    /**
     * Write multiple routing entries in a single Cloudflare KV bulk PUT request.
     * Each entry's value is JSON-encoded as a string, matching the single put() idiom.
     * Entries with expiration_ttl=null are written as permanent (no TTL field sent).
     * Chunks at 10 000 entries per request (Cloudflare bulk API limit).
     *
     * @param  array<array{key:string,value:array<string,mixed>,expiration_ttl:int|null}>  $entries
     */
    public function bulkPut(array $entries): void
    {
        if (empty($entries)) {
            return;
        }

        if (! $this->configured) {
            $this->guardUnconfigured('bulkPut', ['count' => count($entries)]);

            return;
        }

        $url = $this->bulkUrl();

        foreach (array_chunk($entries, 10000) as $chunk) {
            $payload = array_map(function (array $entry): array {
                $item = [
                    'key' => $entry['key'],
                    // Cloudflare KV bulk API requires the value as a JSON string,
                    // matching the single put() which uses json_encode + text/plain body.
                    'value' => (string) json_encode($entry['value']),
                    'base64' => false,
                ];

                // Only include expiration_ttl when set — omitting it means permanent,
                // matching the single put()'s ?expiration_ttl query-param behaviour.
                if ($entry['expiration_ttl'] !== null) {
                    $item['expiration_ttl'] = $entry['expiration_ttl'];
                }

                return $item;
            }, $chunk);

            // EDGE-1: same explicit bounds as put() — see that comment.
            Http::withToken($this->apiToken)
                ->timeout(10)
                ->connectTimeout(3)
                ->put($url, $payload)
                ->throw();
        }
    }

    /**
     * Remove a subdomain handle from the routing table.
     */
    public function delete(string $key): void
    {
        if (! $this->configured) {
            $this->guardUnconfigured('delete', ['key' => $key]);

            return;
        }

        // EDGE-1: same explicit bounds as put() — see that comment.
        Http::withToken($this->apiToken)
            ->timeout(10)
            ->connectTimeout(3)
            ->delete($this->url($key))
            ->throw();
    }

    private function url(string $key): string
    {
        return "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/storage/kv/namespaces/{$this->namespaceId}/values/{$key}";
    }

    private function bulkUrl(): string
    {
        return "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/storage/kv/namespaces/{$this->namespaceId}/bulk";
    }

    /**
     * In production / staging, missing credentials must page — the silent
     * no-op was originally a local-dev convenience and would otherwise mask
     * unrouted subdomains until a user reported it. Dev / local / test keep
     * the quiet behaviour so the absence of CF creds in .env.example doesn't
     * break the suite.
     *
     * @param  array<string, mixed>  $context
     */
    private function guardUnconfigured(string $op, array $context): void
    {
        if (app()->environment('production', 'staging')) {
            throw new \RuntimeException(
                'CloudflareKvService is not configured (account_id, kv_namespace_id, api_token required). '
                ."Refusing to silently no-op {$op} in ".app()->environment().'.'
            );
        }

        Log::debug("CloudflareKvService: skipping {$op} (not configured)", $context);
    }
}
