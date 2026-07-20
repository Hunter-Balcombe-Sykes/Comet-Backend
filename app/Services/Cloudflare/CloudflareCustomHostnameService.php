<?php

namespace App\Services\Cloudflare;

use Illuminate\Http\Client\Response;
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

        // Bound every Cloudflare round-trip so a hung control-plane call can't pin the worker (mirrors SupabaseAdminService).
        $response = Http::withToken($this->apiToken)
            ->timeout(10)
            ->asJson()
            ->post($this->base(), [
                'hostname' => $hostname,
                'ssl' => [
                    'method' => 'http',
                    'type' => 'dv',
                    'settings' => ['min_tls_version' => '1.2'],
                ],
            ]);

        $body = $this->assertSuccessful($response, "create custom hostname '{$hostname}'");

        return $this->shape($body['result'] ?? []);
    }

    /**
     * Read a custom hostname's current status.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $this->ensureConfigured();

        $response = Http::withToken($this->apiToken)
            ->timeout(10)
            ->get($this->base()."/{$id}");

        $body = $this->assertSuccessful($response, "read custom hostname '{$id}'");

        return $this->shape($body['result'] ?? []);
    }

    /**
     * Delete a custom hostname. A missing id / unconfigured service is a no-op,
     * but a real error response THROWS (EDGE-101 — unlike create()/get(), this
     * previously swallowed failures silently even though every call site wraps
     * it in try/catch(Throwable){report($e)} expecting exactly that signal).
     */
    public function delete(string $id): void
    {
        if (! $this->configured || $id === '') {
            return;
        }

        $response = Http::withToken($this->apiToken)->timeout(5)->delete($this->base()."/{$id}");

        $this->assertSuccessful($response, "delete custom hostname '{$id}'", requiresResultId: false);
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

    /**
     * Cloudflare signals failure two different ways: an HTTP 4xx/5xx status,
     * OR — for validation-style rejections such as a hostname already in use
     * elsewhere — HTTP 200 with a `success: false` body. ->throw() alone only
     * catches the first, so a soft-rejected request would otherwise sail
     * through every caller here as if it had succeeded (this is exactly what
     * let create() persist a domain as "pending" with a null CF id and no way
     * for the user to retry). Both failure modes are folded into ONE thrown
     * exception so every call site's existing try/catch(Throwable){report($e)}
     * sees a single, consistent signal — the CF error code/message rides in
     * the exception message so report() surfaces it for diagnosis without any
     * extra logging call (a custom domain/hostname is user-supplied but not
     * secret, so it's safe to include).
     *
     * @return array<string, mixed> the decoded body, once verified successful
     */
    private function assertSuccessful(Response $response, string $action, bool $requiresResultId = true): array
    {
        $response->throw();

        $body = $response->json() ?? [];

        if (($body['success'] ?? null) !== true) {
            $reasons = collect($body['errors'] ?? [])
                ->map(fn ($e) => trim(($e['code'] ?? '?').': '.($e['message'] ?? 'unknown error')))
                ->implode('; ');

            $why = $reasons !== ''
                ? $reasons
                : (array_key_exists('success', $body) ? 'success:false, no error detail returned' : 'no success flag in response body');

            throw new \RuntimeException("Cloudflare rejected the request to {$action} ({$why})");
        }

        // Only for calls whose result we PERSIST. A success:true with no usable
        // result is the same corruption this method exists to stop: shape() would
        // return all-nulls, the caller would persist a null cf_id as 'pending',
        // and verify() 404s on it forever. Real CF does not do this, but the check
        // is one line and being wrong means a permanently stuck domain.
        // delete() passes false — a delete response carries no hostname id, and
        // requiring one there rejects every successful delete.
        if ($requiresResultId && ! isset($body['result']['id'])) {
            throw new \RuntimeException(
                "Cloudflare reported success for {$action} but returned no result id — refusing to persist a null hostname id"
            );
        }

        return $body;
    }
}
