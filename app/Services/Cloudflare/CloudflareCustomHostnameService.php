<?php

namespace App\Services\Cloudflare;

use Illuminate\Support\Facades\Http;

// Wraps the Cloudflare for SaaS "Custom Hostnames" API for user-connected
// domains. Each connected domain becomes a custom hostname on the partna.au
// zone with an auto-provisioned DV certificate; the router Worker resolves the
// host to a handle via SUBDOMAIN_KV once the hostname is active.
//
// Distinct from the KV routing-table service — this only talks to the
// certificate/hostname API. Gracefully reports "not configured" when the SaaS
// credentials are absent (local dev / before the CF setup lands).
class CloudflareCustomHostnameService
{
    private readonly string $zoneId;

    private readonly string $apiToken;

    private readonly bool $configured;

    public function __construct()
    {
        $this->zoneId = (string) config('services.cloudflare.zone_id', '');
        // Prefer the SaaS-scoped token (SSL & Certificates: Edit); fall back to the
        // general api_token so a single broadly-scoped token also works.
        $this->apiToken = (string) (config('services.cloudflare.saas_api_token')
            ?: config('services.cloudflare.api_token')
            ?: '');
        $this->configured = $this->zoneId !== '' && $this->apiToken !== '';
    }

    public function configured(): bool
    {
        return $this->configured;
    }

    /** The CNAME value users point their domain at. */
    public function cnameTarget(): string
    {
        return (string) config('services.cloudflare.saas_cname_target', 'cname.partna.au');
    }

    /**
     * Create a custom hostname with a DV certificate (HTTP validation).
     *
     * @return array<string, mixed> shaped status (id, status, ssl_status, ownership…)
     */
    public function create(string $hostname): array
    {
        $this->ensureConfigured();

        $result = Http::withToken($this->apiToken)
            ->asJson()
            ->post($this->base(), [
                'hostname' => $hostname,
                'ssl' => [
                    'method' => 'http',
                    'type' => 'dv',
                    'settings' => ['min_tls_version' => '1.2'],
                ],
            ])
            ->throw()
            ->json('result', []);

        return $this->shape($result);
    }

    /**
     * Read a custom hostname's current status.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $this->ensureConfigured();

        $result = Http::withToken($this->apiToken)
            ->get($this->base()."/{$id}")
            ->throw()
            ->json('result', []);

        return $this->shape($result);
    }

    /** Delete a custom hostname (best-effort — a missing id is a no-op). */
    public function delete(string $id): void
    {
        if (! $this->configured || $id === '') {
            return;
        }

        Http::withToken($this->apiToken)->delete($this->base()."/{$id}");
    }

    /**
     * Normalise the Cloudflare result into the fields the dashboard needs.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function shape(array $r): array
    {
        return [
            'id' => $r['id'] ?? null,
            'status' => $r['status'] ?? null,                          // pending / active / blocked / moved / deleted
            'ssl_status' => data_get($r, 'ssl.status'),                // pending_validation / active / …
            'verification_errors' => $r['verification_errors'] ?? [],
            // DNS the user may need to add for domain-control / cert validation.
            'ownership' => data_get($r, 'ownership_verification'),     // { type, name, value } (TXT)
            'ownership_http' => data_get($r, 'ownership_verification_http'),
            'ssl_validation' => data_get($r, 'ssl.validation_records'),
        ];
    }

    private function base(): string
    {
        return "https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/custom_hostnames";
    }

    private function ensureConfigured(): void
    {
        if (! $this->configured) {
            throw new \RuntimeException(
                'CloudflareCustomHostnameService is not configured (zone_id + saas_api_token required).'
            );
        }
    }
}
