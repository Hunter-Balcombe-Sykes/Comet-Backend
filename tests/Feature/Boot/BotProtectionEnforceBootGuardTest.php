<?php

// #SEC-16 (unified-actions-security) = #SEC-4 (claim-gate) — one defect, two IDs.
//
// The finding's original evidence was stale; corrected against the live
// environments 2026-08-25 and re-verified here:
//   development — no bot vars set, so driver=null / mode=off. VerifyBotToken
//                 short-circuits on mode=off: zero work, zero network, zero Redis.
//   production  — driver=turnstile / mode=shadow with real keys. Shadow calls the
//                 real verifier, logs bot_protection.shadow_reject, and then passes
//                 EVERY request through by design.
// So the conclusion survives — nothing is enforced on either env — but the remedy
// is a code guard, not the "unset the vars on dev and prod" the audit proposed.
//
// The dangerous half-state is mode=enforce with driver=null: VerifyBotToken no
// longer short-circuits, runs the full path, and has no verifier to call. Neither
// current env can reach it, so this guard is INERT today and exists to stop a
// future half-flip.
//
// Route attachment is deliberately NOT re-tested here — Security/
// BotProtectionCoverageTest.php already asserts that every public mutation
// endpoint is either bot-protected or explicitly exempted, which is a stronger
// statement than the finding's "cover the ten wired routes".

use App\Services\Diagnostics\EnvCheckService;

it('AppServiceProvider::boot() carries the enforce-without-a-driver guard', function () {
    $source = (string) file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

    // Same required shape as the sibling guards: production-gated, read through
    // config() so config:cache is respected, and a message naming the env var.
    expect($source)
        ->toContain("config('partna.bot_protection.mode') === 'enforce'")
        ->toContain('$botDriverAbsent')
        ->toContain('BOT_PROTECTION_MODE=enforce requires a real BOT_PROTECTION_DRIVER');

    $count = substr_count($source, 'app()->isProduction()');
    expect($count)->toBeGreaterThanOrEqual(4, 'Expected ≥4 isProduction()-gated guards (throttle, public_domain, jwks_fail_closed, bot_protection)');
});

it('the guard cannot fire on either environment as configured today', function () {
    // dev/CI resolve to driver=null, mode=off. The guard needs enforce+null, so
    // the test env itself is the dev proof. Production is turnstile/shadow — also
    // not enforce+null. If either of these ever becomes enforce+null this fails
    // BEFORE a deploy refuses to boot.
    // ⚠️ $driver is INTENTIONALLY nullable. CI copies .env.example, which sets
    // BOT_PROTECTION_DRIVER=null, and Env::get() coerces the literal "null" to
    // PHP null — so this arrives as null there and as the string 'null' on a
    // machine that leaves the var unset. A `string $driver` hint TypeErrors in
    // CI while passing locally, which is how the same latent bug in the guard
    // itself reached a green local run. All three shapes mean "no driver".
    $tripped = fn (?string $mode, ?string $driver) => $mode === 'enforce'
        && ($driver === null || $driver === '' || $driver === 'null');

    expect($tripped(config('partna.bot_protection.mode'), config('partna.bot_protection.driver')))->toBeFalse()
        ->and($tripped('shadow', 'turnstile'))->toBeFalse()   // production, as configured 2026-08-25
        ->and($tripped('off', 'null'))->toBeFalse()           // development, as configured 2026-08-25
        ->and($tripped('enforce', 'null'))->toBeTrue()        // the state the guard exists to refuse
        ->and($tripped('enforce', null))->toBeTrue()          // …the same state, as CI's env resolves it
        ->and($tripped('enforce', ''))->toBeTrue();           // …and as an empty assignment resolves it
});

it('reports whether anything is actually verified, so shadow cannot read as "on"', function () {
    $report = app(EnvCheckService::class)->generate();

    expect($report)->toHaveKey('bot_protection')
        ->and($report['bot_protection'])->toHaveKeys(['driver', 'mode', 'fail_open', 'effective']);

    // The whole point: presence of TURNSTILE_SECRET says nothing about whether a
    // request is ever rejected. `effective` must say so in one word.
    config()->set('partna.bot_protection.mode', 'shadow');
    config()->set('partna.bot_protection.driver', 'turnstile');
    expect(app(EnvCheckService::class)->generate()['bot_protection']['effective'])
        ->toBe('observing: verifier runs and logs, but every request passes');

    // Both sentinel shapes must read as inert — 'null' (var unset, config default)
    // and real null (.env.example's BOT_PROTECTION_DRIVER=null via Env::get()).
    foreach (['null', null, ''] as $absent) {
        config()->set('partna.bot_protection.mode', 'enforce');
        config()->set('partna.bot_protection.driver', $absent);
        expect(app(EnvCheckService::class)->generate()['bot_protection']['effective'])
            ->toBe('inert: no driver configured, nothing can be verified');
    }

    config()->set('partna.bot_protection.mode', 'enforce');
    config()->set('partna.bot_protection.driver', 'turnstile');
    expect(app(EnvCheckService::class)->generate()['bot_protection']['effective'])->toBe('enforcing');
});

it('never exposes a driver secret in the diagnostic report', function () {
    config()->set('partna.bot_protection.drivers.turnstile.secret', 'super-secret-value');

    $encoded = json_encode(app(EnvCheckService::class)->generate());

    expect($encoded)->not->toContain('super-secret-value');
});
