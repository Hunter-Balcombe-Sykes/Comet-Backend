<?php

namespace App\Services\BotProtection\Providers;

use App\Services\BotProtection\Contracts\CaptchaProvider;
use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use App\Services\BotProtection\VerificationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TurnstileProvider implements CaptchaProvider
{
    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult {
        $config     = config('partna.bot_protection.drivers.turnstile');
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
                throw new CaptchaProviderException("Turnstile siteverify returned {$response->status()}");
            }

            $data = $response->json() ?? [];
        } catch (ConnectionException $e) {
            throw new CaptchaProviderException('Turnstile siteverify connection failed: '.$e->getMessage(), previous: $e);
        } catch (RequestException $e) {
            throw new CaptchaProviderException('Turnstile siteverify request failed: '.$e->getMessage(), previous: $e);
        }

        $errorCodes = (array) ($data['error-codes'] ?? []);
        // Map Turnstile's timeout-or-duplicate to internal captcha_expired sentinel
        // so the middleware can emit a UX-distinct response without coupling
        // the response layer to Turnstile's vocabulary.
        if (in_array('timeout-or-duplicate', $errorCodes, true)) {
            $errorCodes[] = 'captcha_expired';
        }

        return new VerificationResult(
            success:     (bool) ($data['success'] ?? false),
            errorCodes:  $errorCodes,
            hostname:    $data['hostname']     ?? null,
            action:      $data['action']       ?? $action,
            challengeTs: $data['challenge_ts'] ?? null,
        );
    }

    public function driverName(): string
    {
        return 'turnstile';
    }
}
