<?php

use App\Services\BotProtection\Providers\TurnstileProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->group('integration')->in(__FILE__);

beforeEach(function () {
    if (! env('CI_RUN_INTEGRATION', false)) {
        $this->markTestSkipped('Integration tests opt-in via CI_RUN_INTEGRATION=true');
    }

    // Connectivity pre-check so a Cloudflare incident doesn't fail nightly CI.
    try {
        $health = Http::timeout(2)->get('https://challenges.cloudflare.com');
        if ($health->failed()) {
            $this->markTestSkipped('Cloudflare unreachable; skipping integration test');
        }
    } catch (Throwable $e) {
        $this->markTestSkipped('Cloudflare unreachable: '.$e->getMessage());
    }

    config(['partna.bot_protection.drivers.turnstile' => [
        'site_key' => '1x00000000000000000000AA',
        'secret' => '1x0000000000000000000000000000000AA',
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ]]);
});

it('hits real Cloudflare siteverify with the always-pass test key', function () {
    $result = (new TurnstileProvider)->verify('XXXX.DUMMY.TOKEN.XXXX');

    // Always-pass test key returns success regardless of token value.
    expect($result->success)->toBeTrue();
});
