<?php

namespace App\Services\Diagnostics;

/**
 * Single source of truth for env-var verification.
 *
 * Consumed by both the `env:check` artisan command and the
 * `/api/internal/env-check` HTTP endpoint. Checks resolved config paths,
 * not raw env keys, so typos in a config/*.php file surface too.
 *
 * Fresha + Square are excluded — both are link/scrape-based integrations whose
 * config values (config/services.php, config/partna.php) all ship with working
 * defaults, so there's nothing required to track here.
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
            'queue.batching.database' => 'DB_CONNECTION',
            'queue.failed.database' => 'DB_CONNECTION',
            'session.driver' => 'SESSION_DRIVER',
        ],
        'Supabase Auth' => [
            'supabase.url' => 'SUPABASE_URL',
            'supabase.jwt_issuer' => 'SUPABASE_JWT_ISSUER',
            'supabase.jwt_audience' => 'SUPABASE_JWT_AUD',
            'supabase.jwks_url' => 'SUPABASE_JWKS_URL',
            'supabase.service_role_key' => 'SUPABASE_SERVICE_ROLE_KEY',
        ],
        'Supabase Webhooks' => [
            // Auth hook secret (VerifySupabaseHookSignature) — missing = every auth hook delivery 503s.
            'services.supabase.auth_hook_secret' => 'SUPABASE_AUTH_HOOK_SECRET',
            // Email hook secret (VerifySupabaseHookSignature) — missing = every send-email hook delivery 503s.
            'services.supabase.email_hook_secret' => 'SUPABASE_EMAIL_HOOK_SECRET',
        ],
        'Cloudflare (DNS + KV + Purge)' => [
            'services.cloudflare.zone_id' => 'CLOUDFLARE_ZONE_ID',
            'services.cloudflare.account_id' => 'CLOUDFLARE_ACCOUNT_ID',
            'services.cloudflare.api_token' => 'CLOUDFLARE_API_TOKEN',
            'services.cloudflare.kv_namespace_id' => 'CLOUDFLARE_KV_NAMESPACE_ID',
            // CloudflarePurgeService uses a separate scoped token from the
            // general api_token — missing this silently broke cache busts
            // until B26 added the prod guard.
            'services.cloudflare.cache_purge_token' => 'CLOUDFLARE_CACHE_PURGE_TOKEN',
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
            'logging.channels.single.level' => 'LOG_LEVEL',
        ],
        'Mail' => [
            'services.resend.key' => 'RESEND_API_KEY',
            // Missing = the Resend bounce/complaint webhook 503s (fail-closed), so
            // dead/complaining addresses are never suppressed and drag down the
            // shared partna.au sender reputation.
            'services.resend.webhook_secret' => 'RESEND_WEBHOOK_SECRET',
            'mail.from.address' => 'MAIL_FROM_ADDRESS',
            'mail.from.name' => 'MAIL_FROM_NAME',
        ],
        'Bot Protection (Turnstile)' => [
            'partna.bot_protection.drivers.turnstile.secret' => 'TURNSTILE_SECRET',
            'partna.bot_protection.drivers.turnstile.site_key' => 'TURNSTILE_SITE_KEY',
        ],
        'Google Maps (address autocomplete + place details)' => [
            'services.google_maps.api_key' => 'GOOGLE_MAPS_API_KEY',
            'services.google_maps.server_api_key' => 'GOOGLE_MAPS_SERVER_API_KEY',
        ],
        'Media' => [
            // Drift between this config value and the $_ENV superglobal probe in
            // MediaDiskResolver emits a Log::info breadcrumb but still works — listed
            // here so a staging deploy with a mismatched env surfaces in the report.
            'partna.media_disk' => 'PARTNA_MEDIA_DISK',
        ],
    ];

    /**
     * Build the report consumed by the CLI command and the HTTP endpoint.
     *
     * @return array{
     *   status: 'ok'|'fail',
     *   required_missing: list<string>,
     *   recommended_missing: list<string>,
     *   bot_protection: array{driver: string, mode: string, fail_open: bool, effective: string},
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
            'bot_protection' => $this->botProtection(),
        ];
    }

    /**
     * #SEC-16 = #SEC-4: the keys above being present says nothing about whether
     * anything is actually VERIFIED — mode=shadow calls the real verifier, logs
     * bot_protection.shadow_reject, then passes everyone through by design. That
     * made "is bot protection on?" unanswerable without reading env vars on the
     * box. `effective` answers it in one word. Secrets are never included, only
     * whether a driver is named.
     *
     * @return array{driver: string, mode: string, fail_open: bool, effective: string}
     */
    private function botProtection(): array
    {
        // The absent-driver sentinel has three shapes — see the boot guard in
        // AppServiceProvider. Env::get() turns .env.example's literal
        // `BOT_PROTECTION_DRIVER=null` into PHP null, which `(string)` casts to
        // '' rather than 'null', so comparing against 'null' alone would report
        // "enforcing" for an environment with no verifier at all.
        $rawDriver = config('partna.bot_protection.driver');
        $driverAbsent = $rawDriver === null || $rawDriver === '' || $rawDriver === 'null';
        $driver = $driverAbsent ? 'null' : (string) $rawDriver;
        $mode = (string) config('partna.bot_protection.mode', 'off');
        $failOpen = (bool) config('partna.bot_protection.fail_open', false);

        $effective = match (true) {
            $mode === 'off' => 'inert: middleware short-circuits before any work',
            $driverAbsent => 'inert: no driver configured, nothing can be verified',
            $mode === 'shadow' => 'observing: verifier runs and logs, but every request passes',
            default => 'enforcing',
        };

        return [
            'driver' => $driver,
            'mode' => $mode,
            'fail_open' => $failOpen,
            'effective' => $effective,
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
