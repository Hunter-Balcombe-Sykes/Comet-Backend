<?php

namespace App\Services\BotProtection\Providers;

use App\Services\BotProtection\Contracts\CaptchaProvider;
use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use App\Services\BotProtection\VerificationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class HCaptchaProvider implements CaptchaProvider
{
    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult {
        $config     = config('partna.bot_protection.drivers.hcaptcha');
        $defaultMs  = (int) config('partna.bot_protection.enforce_timeout_ms', 3000);
        $timeoutSec = ($timeoutMs ?? $defaultMs) / 1000;

        try {
            $response = Http::asForm()
                ->timeout((float) $timeoutSec)
                ->post($config['verify_url'], [
                    'secret'   => $config['secret'],
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]);

            if ($response->serverError()) {
                throw new CaptchaProviderException("hCaptcha siteverify returned {$response->status()}");
            }
            $data = $response->json() ?? [];
        } catch (ConnectionException $e) {
            throw new CaptchaProviderException('hCaptcha siteverify connection failed: '.$e->getMessage(), previous: $e);
        } catch (RequestException $e) {
            throw new CaptchaProviderException('hCaptcha siteverify request failed: '.$e->getMessage(), previous: $e);
        }

        return new VerificationResult(
            success:     (bool) ($data['success'] ?? false),
            errorCodes:  (array) ($data['error-codes'] ?? []),
            hostname:    $data['hostname']     ?? null,
            action:      $action,                                 // hCaptcha ignores action; preserve caller's tag for observability
            challengeTs: $data['challenge_ts'] ?? null,
        );
    }

    public function driverName(): string
    {
        return 'hcaptcha';
    }
}
