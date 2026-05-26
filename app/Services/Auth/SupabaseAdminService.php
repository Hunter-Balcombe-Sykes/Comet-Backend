<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// V2: Server-side Supabase user management via the GoTrue Admin API. Creates users server-side for the setup wizard flow.
class SupabaseAdminService
{
    private string $baseUrl;

    private string $serviceRoleKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('supabase.url'), '/');
        $this->serviceRoleKey = (string) config('supabase.service_role_key');

        if ($this->baseUrl === '' || $this->serviceRoleKey === '') {
            throw new RuntimeException('Supabase URL and service role key must be configured.');
        }
    }

    /**
     * Create a Supabase user server-side (no password — magic link auth).
     *
     * @return array{id: string, email: string, created: bool}
     */
    public function createUser(string $email, array $metadata = []): array
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            throw new RuntimeException('Email is required to create a Supabase user.');
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->post("{$this->baseUrl}/auth/v1/admin/users", [
                'email' => $email,
                'email_confirm' => true,
                'user_metadata' => $metadata ?: (object) [],
            ]);

        if ($response->successful()) {
            $user = $response->json();

            return [
                'id' => (string) ($user['id'] ?? ''),
                'email' => (string) ($user['email'] ?? $email),
                'created' => true,
            ];
        }

        // User already exists — GoTrue v2 includes the existing user object in the error response.
        if ($response->status() === 422 || $response->status() === 409) {
            $body = $response->json();
            $existingId = $body['user']['id'] ?? null;

            if ($existingId) {
                return [
                    'id' => (string) $existingId,
                    'email' => (string) ($body['user']['email'] ?? $email),
                    'created' => false,
                ];
            }
        }

        // Privacy: never write raw email to logs. Retry storms (Supabase 5xx, network
        // blips) compound — a single 10-retry burst would otherwise persist 10 emails
        // into Nightwatch / log aggregator retention windows that GDPR erasure cannot
        // reach. A SHA-256 fingerprint correlates retry attempts for the same address
        // without storing the address itself.
        Log::error('Supabase admin: failed to create user', [
            'email_fingerprint' => $this->emailFingerprint($email),
            'status' => $response->status(),
            'error_code' => $response->json('code'),
            'error_msg' => $response->json('msg'),
        ]);

        throw new RuntimeException('Failed to create Supabase user.');
    }

    /**
     * Look up a Supabase auth user by email via the Admin API.
     *
     * Used by signup availability checks to detect orphan auth.users records
     * (Supabase user exists, no Laravel mirror) so we can route visitors to
     * sign-in instead of re-attempting a signUp that Supabase silently rejects
     * with `user_repeated_signup` (anti-enumeration).
     *
     * Throws RuntimeException on transport/HTTP failure so callers can decide
     * whether to fail-open or fail-closed.
     *
     * @return array{id: string, email: string, email_confirmed_at: ?string}|null
     *                                                                            null when no exact-match user exists.
     */
    public function findUserByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return null;
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(10)
            ->get("{$this->baseUrl}/auth/v1/admin/users", [
                'email' => $email,
                'per_page' => 1,
            ]);

        if (! $response->successful()) {
            // Privacy: no raw email in logs — fingerprint correlates retries
            // without persisting the address (same rationale as createUser).
            Log::warning('Supabase admin: findUserByEmail failed', [
                'email_fingerprint' => $this->emailFingerprint($email),
                'status' => $response->status(),
            ]);

            throw new RuntimeException('Failed to query Supabase user by email.');
        }

        // The `email` query param is a substring filter on some GoTrue versions,
        // so re-check exact match before returning a hit.
        $users = $response->json('users') ?? [];

        foreach ($users as $user) {
            if (! is_array($user) || ! isset($user['email'])) {
                continue;
            }
            if (strtolower((string) $user['email']) !== $email) {
                continue;
            }

            return [
                'id' => (string) ($user['id'] ?? ''),
                'email' => (string) $user['email'],
                'email_confirmed_at' => $user['email_confirmed_at'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Remove an MFA factor from a Supabase user via the Admin API.
     *
     * Endpoint: DELETE /auth/v1/admin/users/{user_id}/factors/{factor_id}
     * Auth: service role key.
     *
     * Throws \RuntimeException on non-2xx response so the controller can
     * map to a 502.
     */
    public function unenrollMfaFactor(string $supabaseUserId, string $factorId): void
    {
        $baseUrl = rtrim((string) config('supabase.admin.base_url'), '/');
        $serviceRoleKey = (string) config('supabase.service_role_key');

        if ($baseUrl === '' || $serviceRoleKey === '') {
            throw new RuntimeException('Supabase admin config missing');
        }

        $response = Http::timeout(5)
            ->withHeaders([
                'apikey' => $serviceRoleKey,
                'Authorization' => 'Bearer '.$serviceRoleKey,
            ])
            ->delete("{$baseUrl}/users/{$supabaseUserId}/factors/{$factorId}");

        if (! $response->successful()) {
            // Privacy: GoTrue 4xx responses include the user object (email,
            // user_metadata, phone). Status code is sufficient for the controller's
            // 502 mapping — the response body cannot be embedded in the exception
            // message, which would otherwise persist into Horizon failed-job records.
            throw new RuntimeException(
                "Supabase factor unenroll failed: HTTP {$response->status()}"
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->serviceRoleKey,
            'apikey' => $this->serviceRoleKey,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Normalised SHA-256 fingerprint of an email address. Use anywhere an email
     * would otherwise land in a log line so retry attempts for the same address
     * remain correlatable without persisting the address itself.
     */
    private function emailFingerprint(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
