<?php

namespace App\Providers;

use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\CircuitBreaker;
use App\Services\BotProtection\Exceptions\CaptchaConfigurationException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class BotProtectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CircuitBreaker::class, fn () => new CircuitBreaker(
            failureThreshold: (int) config('partna.bot_protection.circuit_breaker.failure_threshold', 5),
            windowSeconds: (int) config('partna.bot_protection.circuit_breaker.window_seconds', 60),
            cooldownSeconds: (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300),
        ));

        $this->app->singleton(CaptchaManager::class, fn ($app) => new CaptchaManager($app));
    }

    public function boot(): void
    {
        $this->runBootGuards();
    }

    private function runBootGuards(): void
    {
        $env = $this->app->environment();
        $driver = (string) config('partna.bot_protection.driver');
        $mode = (string) config('partna.bot_protection.mode');

        // Guard 1: null driver in production with enforce mode = silent disable.
        if ($env === 'production' && $driver === 'null' && $mode === 'enforce') {
            throw new CaptchaConfigurationException(
                'BOT_PROTECTION_DRIVER=null + BOT_PROTECTION_MODE=enforce in production is a silent no-op; set DRIVER explicitly or change MODE.'
            );
        }

        // Guard 2: Cloudflare test site key in production.
        $siteKey = (string) config("partna.bot_protection.drivers.{$driver}.site_key", '');
        $testKeys = (array) config('partna.bot_protection.known_test_site_keys', []);
        if ($env === 'production' && $siteKey !== '' && in_array($siteKey, $testKeys, true)) {
            throw new CaptchaConfigurationException(
                "Cloudflare test site key '{$siteKey}' detected in production; replace with a real site key."
            );
        }

        // Guard 3: Active real driver without secret.
        if (in_array($driver, ['turnstile', 'hcaptcha'], true)) {
            $secret = (string) config("partna.bot_protection.drivers.{$driver}.secret", '');
            if ($secret === '') {
                throw new CaptchaConfigurationException(
                    "BOT_PROTECTION_DRIVER={$driver} but the driver secret is not set."
                );
            }
        }

        // Guard 4: mode=off in production — fail-closed (throws, matching Guards 1-3).
        // BOT_PROTECTION_MODE=off disables all bot verification on every protected
        // endpoint; silently accepting unlimited bot submissions in prod is a security
        // misconfiguration that must block the deploy rather than log a warning.
        if ($env === 'production' && $mode === 'off') {
            throw new CaptchaConfigurationException(
                'BOT_PROTECTION_MODE=off in production disables all bot verification; set MODE=shadow or MODE=enforce.'
            );
        }

        // Guard 5: Trusted proxies unconfigured. Soft warn — log only, do not refuse boot.
        // Without trusted-proxy config, $request->ip() returns the Cloudflare edge IP
        // instead of the real client IP — Turnstile fraud scoring is degraded and per-IP
        // rate limits collapse to a single bucket. Only meaningful in deployed envs;
        // skip in local/testing where there's no proxy at all.
        if (! in_array($env, ['local', 'testing'], true) && ! $this->trustedProxiesConfigured()) {
            Log::warning('bot_protection.trusted_proxies_unconfigured', [
                'note' => '$request->ip() may return the proxy IP instead of the real client IP; Turnstile siteverify scoring is degraded and per-IP rate limits collapse.',
            ]);
        }
    }

    private function trustedProxiesConfigured(): bool
    {
        // Laravel 12 stores trusted proxies on TrustProxies::$alwaysTrustProxies after
        // $middleware->trustProxies(at: ...) wires them up. The property is protected
        // and has no public getter, so we read it via reflection. '*' (trust all) counts
        // as configured — it's the recommended setting when the app sits behind a
        // single trusted edge (Cloudflare, Laravel Cloud).
        try {
            $reflection = new \ReflectionProperty(TrustProxies::class, 'alwaysTrustProxies');
            $reflection->setAccessible(true);
            $proxies = $reflection->getValue();
        } catch (\Throwable $e) {
            return false;
        }

        if ($proxies === null) {
            return false;
        }
        if (is_array($proxies)) {
            return $proxies !== [];
        }

        return (string) $proxies !== '';
    }
}
