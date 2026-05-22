<?php

namespace App\Services\Diagnostics;

/**
 * Single source of truth for env-var verification.
 *
 * Consumed by both the `env:check` artisan command and the
 * `/api/internal/env-check` HTTP endpoint. Checks resolved config paths,
 * not raw env keys, so typos in a config/*.php file surface too.
 *
 * Fresha + Square are excluded — both integrations dropped (2026-05-11).
 * AWS S3 keys are excluded — we use Cloudflare R2 / direct disks.
 */
class EnvCheckService
{
    /**
     * Required: app will not function in production if any of these is blank.
     * Grouped by domain. Key = config path (dot-notation). Value = env var label.
     *
     * @var array<string, array<string, string>>
     */
    public const REQUIRED = [
        'App' => [
            'app.key' => 'APP_KEY',
            'app.env' => 'APP_ENV',
            'app.url' => 'APP_URL',
            'app.name' => 'APP_NAME',
            'app.frontend_url' => 'FRONTEND_URL',
        ],
        'Database (PostgreSQL)' => [
            'database.connections.pgsql.host' => 'DB_HOST',
            'database.connections.pgsql.port' => 'DB_PORT',
            'database.connections.pgsql.database' => 'DB_DATABASE',
            'database.connections.pgsql.username' => 'DB_USERNAME',
            'database.connections.pgsql.password' => 'DB_PASSWORD',
        ],
        'Redis' => [
            'database.redis.default.host' => 'REDIS_HOST',
            'database.redis.default.password' => 'REDIS_PASSWORD',
        ],
        'Cache / Queue / Session' => [
            'cache.default' => 'CACHE_STORE',
            'queue.default' => 'QUEUE_CONNECTION',
            'session.driver' => 'SESSION_DRIVER',
        ],
        'Supabase Auth' => [
            'supabase.url' => 'SUPABASE_URL',
            'supabase.jwt_issuer' => 'SUPABASE_JWT_ISSUER',
            'supabase.jwt_audience' => 'SUPABASE_JWT_AUD',
            'supabase.jwks_url' => 'SUPABASE_JWKS_URL',
            'supabase.service_role_key' => 'SUPABASE_SERVICE_ROLE_KEY',
        ],
        'Cloudflare (DNS + KV)' => [
            'services.cloudflare.zone_id' => 'CLOUDFLARE_ZONE_ID',
            'services.cloudflare.account_id' => 'CLOUDFLARE_ACCOUNT_ID',
            'services.cloudflare.api_token' => 'CLOUDFLARE_API_TOKEN',
            'services.cloudflare.kv_namespace_id' => 'CLOUDFLARE_KV_NAMESPACE_ID',
        ],
    ];

    /**
     * Recommended: app runs without these but loses an important feature
     * (observability, transactional email, brand storefront deploys, etc.).
     *
     * @var array<string, array<string, string>>
     */
    public const RECOMMENDED = [
        'Observability' => [
            'nightwatch.token' => 'NIGHTWATCH_TOKEN',
        ],
        'Mail' => [
            'services.resend.key' => 'RESEND_API_KEY',
            'mail.from.address' => 'MAIL_FROM_ADDRESS',
            'mail.from.name' => 'MAIL_FROM_NAME',
        ],
        'Cloudflare Turnstile (captcha)' => [
            'services.turnstile.secret_key' => 'CLOUDFLARE_TURNSTILE_SECRET_KEY',
        ],
        'Google Maps (address autocomplete)' => [
            'services.google_maps.api_key' => 'GOOGLE_MAPS_API_KEY',
        ],
    ];

    /**
     * Build the report consumed by the CLI command and the HTTP endpoint.
     *
     * @return array{
     *   status: 'ok'|'fail',
     *   required_missing: list<string>,
     *   recommended_missing: list<string>,
     * }
     */
    public function generate(): array
    {
        $requiredMissing = $this->scan(self::REQUIRED);
        $recommendedMissing = $this->scan(self::RECOMMENDED);

        return [
            'status' => $requiredMissing === [] ? 'ok' : 'fail',
            'required_missing' => $requiredMissing,
            'recommended_missing' => $recommendedMissing,
        ];
    }

    /**
     * Walk a config map and return the flat list of blank config paths.
     *
     * @param  array<string, array<string, string>>  $map
     * @return list<string>
     */
    public function scan(array $map): array
    {
        $missing = [];
        foreach ($map as $group => $entries) {
            foreach ($entries as $path => $envLabel) {
                if ($this->isBlank(config($path))) {
                    $missing[] = $path;
                }
            }
        }

        return $missing;
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return false;
    }
}
