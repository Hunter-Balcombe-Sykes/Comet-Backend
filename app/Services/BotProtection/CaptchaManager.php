<?php

namespace App\Services\BotProtection;

use App\Services\BotProtection\Contracts\CaptchaProvider;
use App\Services\BotProtection\Exceptions\CaptchaConfigurationException;
use App\Services\BotProtection\Providers\FakeProvider;
use App\Services\BotProtection\Providers\HCaptchaProvider;
use App\Services\BotProtection\Providers\NullProvider;
use App\Services\BotProtection\Providers\TurnstileProvider;
use Illuminate\Contracts\Foundation\Application;

final class CaptchaManager
{
    /** @var array<string, CaptchaProvider> Resolved providers keyed by driver name. */
    private array $resolved = [];

    public function __construct(private readonly Application $app) {}

    public function driver(?string $name = null): CaptchaProvider
    {
        $name = $name ?? (string) config('partna.bot_protection.driver');

        // Memoise within the manager's lifetime (singleton ⇒ per-request) to avoid
        // re-resolving on every verify() call. Runtime driver changes via
        // config([...]) require flush() or a fresh manager — matches the spec's
        // "swap driver via env var + worker restart" assumption.
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        return $this->resolved[$name] = match ($name) {
            'null' => $this->app->make(NullProvider::class),
            'turnstile' => $this->app->make(TurnstileProvider::class),
            'hcaptcha' => $this->app->make(HCaptchaProvider::class),
            'fake' => $this->app->make(FakeProvider::class),
            default => throw new CaptchaConfigurationException("Unknown bot protection driver: {$name}"),
        };
    }

    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult {
        return $this->driver()->verify($token, $remoteIp, $action, $timeoutMs);
    }

    // Clear the resolved-driver cache. Useful in tests that rebind the
    // CaptchaProvider container binding mid-test.
    public function flush(): void
    {
        $this->resolved = [];
    }
}
