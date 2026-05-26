<?php

namespace App\Providers;

use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\CircuitBreaker;
use App\Services\BotProtection\Exceptions\CaptchaConfigurationException;
use Illuminate\Support\ServiceProvider;

final class BotProtectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CircuitBreaker::class, fn () => new CircuitBreaker(
            failureThreshold: (int) config('partna.bot_protection.circuit_breaker.failure_threshold', 5),
            windowSeconds:    (int) config('partna.bot_protection.circuit_breaker.window_seconds', 60),
            cooldownSeconds:  (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300),
        ));

        $this->app->singleton(CaptchaManager::class, fn ($app) => new CaptchaManager($app));
    }

    public function boot(): void
    {
        $this->runBootGuards();
    }

    private function runBootGuards(): void
    {
        $env    = $this->app->environment();
        $driver = (string) config('partna.bot_protection.driver');
        $mode   = (string) config('partna.bot_protection.mode');

        // Guard 1: null driver in production with enforce mode = silent disable.
        if ($env === 'production' && $driver === 'null' && $mode === 'enforce') {
            throw new CaptchaConfigurationException(
                'BOT_PROTECTION_DRIVER=null + BOT_PROTECTION_MODE=enforce in production is a silent no-op; set DRIVER explicitly or change MODE.'
            );
        }

        // Guard 2: Cloudflare test site key in production.
        $siteKey  = (string) config("partna.bot_protection.drivers.{$driver}.site_key", '');
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
    }
}
