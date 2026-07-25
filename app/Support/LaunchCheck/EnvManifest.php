<?php

namespace App\Support\LaunchCheck;

/**
 * Required/expected config manifest for the deployed env. Pure policy — the
 * Command resolves the values via config() and passes them here so this stays
 * unit-testable without a framework boot.
 */
final class EnvManifest
{
    /**
     * Config dot-keys that MUST be present and non-empty in every environment.
     * Standard-Laravel + Partna-integration secrets. This is the high-confidence
     * CORE, not the full set — extend it per the NOTE below.
     *
     * Key-path corrections made against the real config files (see task-8-report.md):
     * - `services.supabase.jwt_secret` does not exist — Supabase JWT verification is
     *   JWKS-based (config/supabase.php), not a shared secret. Replaced with
     *   `supabase.service_role_key`, the actual secret this app holds for Supabase
     *   (server-side admin ops; VerifySupabaseJwt's own config is JWKS-only).
     * - `services.cloudflare.subdomain_kv_namespace_id` does not exist in
     *   config/services.php — the real key is `services.cloudflare.kv_namespace_id`.
     */
    public const REQUIRED = [
        'app.key',
        'app.url',
        'database.connections.pgsql.host',
        'database.connections.pgsql.username',
        'database.connections.pgsql.password',
        'database.connections.pgsql.database',
        'database.redis.default.host',
        // Partna integrations — verified against config/supabase.php + config/services.php.
        'supabase.service_role_key',
        'services.cloudflare.api_token',
        'services.cloudflare.kv_namespace_id',
        'nightwatch.token',
    ];

    /**
     * Config dot-key => expected launch value. Mismatch FAILS at target=launch,
     * WARNS at target=pilot (the deployed dev env legitimately deviates — it runs
     * queue=sync today). These are the incident-backed core; keep them.
     */
    public const EXPECTED = [
        'app.debug' => false,
        'app.env' => 'production',
        'queue.default' => 'redis',   // NOT 'sync' — inline-jobs incident
        'cache.default' => 'redis',   // NOT 'failover'/'array' — fail-open escalation needs redis
        'session.driver' => 'redis',
    ];

    /** @return array{fail: string[], warn: string[], ok: string[]} */
    public static function evaluate(array $values, string $target): array
    {
        $fail = $warn = $ok = [];

        foreach (self::REQUIRED as $key) {
            $v = $values[$key] ?? null;
            if ($v === null || $v === '') {
                $fail[] = "missing: {$key}";
            } else {
                $ok[] = "present: {$key}";
            }
        }

        foreach (self::EXPECTED as $key => $expected) {
            $actual = $values[$key] ?? null;
            if ($actual === $expected) {
                $ok[] = "{$key} = ".self::show($expected);
            } else {
                $msg = "{$key} = ".self::show($actual).' (want '.self::show($expected).')';
                if ($target === 'launch') {
                    $fail[] = $msg;
                } else {
                    $warn[] = "{$msg} — expected deviation on dev?";
                }
            }
        }

        return ['fail' => $fail, 'warn' => $warn, 'ok' => $ok];
    }

    /** @return string[] every config key the Command must resolve. */
    public static function keys(): array
    {
        return array_merge(self::REQUIRED, array_keys(self::EXPECTED));
    }

    private static function show(mixed $v): string
    {
        return match (true) {
            $v === true => 'true',
            $v === false => 'false',
            $v === null => 'null',
            default => (string) $v,
        };
    }
}
