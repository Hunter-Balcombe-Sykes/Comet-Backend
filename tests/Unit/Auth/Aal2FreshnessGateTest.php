<?php

use App\Services\Auth\Aal2FreshnessGate;
use Illuminate\Http\Request;

/** Build a Request carrying the given amr timeline as the verified attribute. */
function aal2GateRequest(array $amr): Request
{
    $request = Request::create('/', 'GET');
    $request->attributes->set('supabase_amr', $amr);

    return $request;
}

it('allows when the most recent mfa entry is inside the window', function () {
    $request = aal2GateRequest([
        ['method' => 'totp', 'timestamp' => time() - 60],
    ]);

    expect((new Aal2FreshnessGate)->check($request, 300)->allowed())->toBeTrue();
});

it('denies with 401 + message when the most recent mfa entry is outside the window', function () {
    $request = aal2GateRequest([
        ['method' => 'totp', 'timestamp' => time() - 1000],
    ]);

    $response = (new Aal2FreshnessGate)->check($request, 300);

    expect($response->allowed())->toBeFalse();
    expect($response->status())->toBe(401);
    expect($response->message())->toBe('Recent MFA verification required');
});

it('denies with 401 + message when amr has no mfa entries', function () {
    $request = aal2GateRequest([
        ['method' => 'magiclink', 'timestamp' => time() - 5],
    ]);

    $response = (new Aal2FreshnessGate)->check($request, 300);

    expect($response->allowed())->toBeFalse();
    expect($response->status())->toBe(401);
    expect($response->message())->toBe('Recent MFA verification required');
});

it('denies with 401 when amr is empty', function () {
    $response = (new Aal2FreshnessGate)->check(aal2GateRequest([]), 300);

    expect($response->allowed())->toBeFalse();
    expect($response->status())->toBe(401);
});

it('scans for the max mfa timestamp, ignoring newer non-mfa entries', function () {
    // A newer non-mfa entry must NOT make the gate pass; the fresh mfa entry decides.
    $request = aal2GateRequest([
        ['method' => 'token_refresh', 'timestamp' => time() - 5],   // newest, not mfa
        ['method' => 'totp', 'timestamp' => time() - 60],            // mfa, fresh
        ['method' => 'magiclink', 'timestamp' => time() - 120],
    ]);

    expect((new Aal2FreshnessGate)->check($request, 300)->allowed())->toBeTrue();
});

it('takes the most recent mfa timestamp when multiple mfa entries exist (order-independent)', function () {
    // Stale totp listed AFTER a fresh webauthn — result must use the max (fresh) one.
    $request = aal2GateRequest([
        ['method' => 'totp', 'timestamp' => time() - 5000],    // stale
        ['method' => 'webauthn', 'timestamp' => time() - 30],  // fresh, max
    ]);

    expect((new Aal2FreshnessGate)->check($request, 300)->allowed())->toBeTrue();
});

it('counts phone and webauthn as valid mfa methods', function () {
    foreach (['phone', 'webauthn'] as $method) {
        $request = aal2GateRequest([
            ['method' => $method, 'timestamp' => time() - 10],
        ]);

        expect((new Aal2FreshnessGate)->check($request, 300)->allowed())
            ->toBeTrue("method {$method} should count as MFA");
    }
});
