<?php

namespace App\Services\BotProtection\Providers;

use App\Services\BotProtection\Contracts\CaptchaProvider;
use App\Services\BotProtection\VerificationResult;

// Test double. Scripted responses via queueResult() / queueException().
// Captures inputs so tests can assert what reached the provider.
// Instance-scoped (NOT static) — bound fresh per test via Pest beforeEach hook
// to eliminate state bleed between tests.
final class FakeProvider implements CaptchaProvider
{
    /** @var array<int, VerificationResult|\Throwable> */
    private array $queued = [];

    /** @var array<int, array{token: string, ip: ?string, action: ?string, timeoutMs: ?int}> */
    private array $calls = [];

    public function queueResult(VerificationResult $result): self
    {
        $this->queued[] = $result;

        return $this;
    }

    public function queueException(\Throwable $e): self
    {
        $this->queued[] = $e;

        return $this;
    }

    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult {
        $this->calls[] = compact('token') + ['ip' => $remoteIp, 'action' => $action, 'timeoutMs' => $timeoutMs];

        if (empty($this->queued)) {
            // Default: succeed. Tests that care queue an explicit result.
            return new VerificationResult(success: true, action: $action);
        }
        $next = array_shift($this->queued);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function driverName(): string
    {
        return 'fake';
    }

    public function lastAction(): ?string
    {
        return $this->calls === [] ? null : end($this->calls)['action'];
    }

    public function verifyCount(): int
    {
        return count($this->calls);
    }

    public function calls(): array
    {
        return $this->calls;
    }
}
