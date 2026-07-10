<?php

/**
 * OV-A hardening — bootstrap early-access invite integration + the JWT-claim
 * email binding. primary_email is bound to the VERIFIED supabase_claims['email']
 * in BootstrapRequest::prepareForValidation(), so the invite email-match compares
 * the invite address against the verified identity — a body email can no longer
 * spoof it. Covers: valid invite → signed_up; INVITE_INVALID; INVITE_EMAIL_MISMATCH
 * (claim ≠ invite); waitlist-bypass without a token; expired (>14d) token.
 *
 * Direct-controller pattern (mirrors BootstrapWaitlistGateTest): UserBootstrapService
 * is mocked on the success path, but the REAL EarlyAccessService runs so the
 * signed_up flip is genuinely exercised. Deny paths never reach the service.
 */

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\User\UserBootstrapService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['partna.waitlist.enabled' => false]);
    config(['partna.individual_waitlist_enabled' => false]);

    setupUsersTable();
    setupSitesTable();
    setupEarlyAccessTable();

    DB::connection('pgsql')->statement('DELETE FROM core.early_access_signups');
    DB::connection('pgsql')->statement('DELETE FROM core.users');
})->group('bootstrap-invite');

/**
 * Build a resolved BootstrapRequest: sets the supabase attributes the JWT
 * middleware would set (uid + verified claims incl. email), then runs
 * prepareForValidation + validation so the claim-email binding takes effect.
 */
function ovaBootstrapRequest(array $body, string $uid, ?string $claimEmail): BootstrapRequest
{
    $request = BootstrapRequest::create('/api/bootstrap', 'POST', $body);
    $request->attributes->set('supabase_uid', $uid);
    // claimEmail === null models an anonymous / phone-only Supabase token:
    // project-valid, carries a sub + role but NO verified `email` claim.
    $request->attributes->set(
        'supabase_claims',
        $claimEmail === null ? ['sub' => $uid, 'role' => 'anon'] : ['email' => $claimEmail]
    );
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request;
}

function ovaInvitedRow(string $email, ?Carbon $invitedAt = null): EarlyAccessSignup
{
    return EarlyAccessSignup::query()->create([
        'email' => $email,
        'email_lc' => mb_strtolower($email),
        'type' => 'partna',
        'status' => 'invited',
        'source' => 'manual',
        'invited_at' => $invitedAt ?? now(),
        'invite_token_hash' => hash('sha256', 'tok-'.$email),
        'platforms' => ['instagram', 'fresha'],
    ]);
}

it('completes signup for a valid invite whose email matches the verified claim, flipping the row to signed_up', function () {
    // Waitlist ON to prove the invite bypasses the gate.
    config(['partna.waitlist.enabled' => true]);

    $row = ovaInvitedRow('invited@example.test');

    // Mock only the create-or-update transaction; the invite/claim logic + the
    // markSignedUp flip run for real.
    $pro = new User(['handle' => 'inviteduser', 'display_name' => 'Invited User', 'primary_email' => 'invited@example.test']);
    $pro->id = '00000000-0000-0000-0000-0000000000e1';
    $site = new Site(['id' => '00000000-0000-0000-0000-0000000000e2', 'subdomain' => 'inviteduser']);

    $this->instance(UserBootstrapService::class, Mockery::mock(UserBootstrapService::class, function ($mock) use ($pro, $site) {
        $mock->shouldReceive('bootstrap')->once()->andReturn([
            'professional' => $pro,
            'site' => $site,
            'created' => true,
        ]);
    }));

    // Body carries a DIFFERENT email; the verified claim must win.
    $request = ovaBootstrapRequest([
        'primary_email' => 'attacker-body@example.test',
        'display_name' => 'Invited User',
        'handle' => 'inviteduser',
        'invite' => 'tok-invited@example.test',
    ], 'invited-uid', 'invited@example.test');

    $response = app(BootstrapController::class)->bootstrap($request);

    expect($response->getStatusCode())->toBe(200);

    $row->refresh();
    expect($row->status)->toBe('signed_up')
        ->and($row->signed_up_at)->not->toBeNull()
        ->and($row->invite_token_hash)->toBeNull();
});

it('rejects an unknown invite token with INVITE_INVALID', function () {
    $response = app(BootstrapController::class)->bootstrap(ovaBootstrapRequest([
        'primary_email' => 'body@example.test',
        'display_name' => 'Nobody',
        'invite' => 'ThisTokenDoesNotExist',
    ], 'unknown-invite-uid', 'newbie@example.test'));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['errors']['code'] ?? null)->toBe('INVITE_INVALID');
});

it('rejects when the verified claim email differs from the invite email — a body email cannot spoof the match', function () {
    $row = ovaInvitedRow('invited@example.test');

    // Attacker sets the BODY email to the invite address, but their verified
    // identity (claim) is someone else — the match must use the claim.
    $response = app(BootstrapController::class)->bootstrap(ovaBootstrapRequest([
        'primary_email' => 'invited@example.test',
        'display_name' => 'Attacker',
        'invite' => 'tok-invited@example.test',
    ], 'attacker-uid', 'attacker@evil.test'));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['errors']['code'] ?? null)->toBe('INVITE_EMAIL_MISMATCH');

    // Untouched — the deny path never consumes the invite.
    expect($row->fresh()->status)->toBe('invited');
});

it('blocks a waitlist bypass attempt made without a valid invite token', function () {
    config(['partna.waitlist.enabled' => true]);

    $response = app(BootstrapController::class)->bootstrap(ovaBootstrapRequest([
        'primary_email' => 'body@example.test',
        'display_name' => 'Gate Crasher',
    ], 'no-invite-uid', 'crasher@example.test'));

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true)['errors']['code'] ?? null)->toBe('WAITLIST_ONLY');
});

it('treats an invite token older than the 14-day TTL as INVITE_INVALID', function () {
    ovaInvitedRow('stale@example.test', now()->subDays(15));

    $response = app(BootstrapController::class)->bootstrap(ovaBootstrapRequest([
        'primary_email' => 'body@example.test',
        'display_name' => 'Stale Invitee',
        'invite' => 'tok-stale@example.test',
    ], 'stale-invite-uid', 'stale@example.test'));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['errors']['code'] ?? null)->toBe('INVITE_INVALID');
});

it('fails closed (422) for a token with no verified email claim, even when the body spoofs the invite email — no squat', function () {
    // The bootstrap route requires supabase.jwt but NOT require.email_verified,
    // so an anonymous/phone token (no email claim) reaches here. The attacker
    // sets the BODY to a victim's invited address + presents a leaked invite
    // token — the fail-closed guard must reject before the email-match runs.
    $row = ovaInvitedRow('victim@example.test');

    $response = app(BootstrapController::class)->bootstrap(ovaBootstrapRequest([
        'primary_email' => 'victim@example.test',
        'display_name' => 'Squatter',
        'invite' => 'tok-victim@example.test',
    ], 'anon-uid', null));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['errors']['code'] ?? null)->toBe('EMAIL_VERIFICATION_REQUIRED');

    // Invite NOT burned — still 'invited', still claimable by the real invitee.
    $row->refresh();
    expect($row->status)->toBe('invited')
        ->and($row->invite_token_hash)->not->toBeNull();

    // No User row minted under the attacker's auth id.
    expect(User::query()->where('auth_user_id', 'anon-uid')->exists())->toBeFalse();
});

it('fails closed (422) for a token with no verified email claim on a normal signup', function () {
    $response = app(BootstrapController::class)->bootstrap(ovaBootstrapRequest([
        'primary_email' => 'phoneuser@example.test',
        'display_name' => 'Phone User',
    ], 'phone-only-uid', null));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['errors']['code'] ?? null)->toBe('EMAIL_VERIFICATION_REQUIRED');

    expect(User::query()->where('auth_user_id', 'phone-only-uid')->exists())->toBeFalse();
});
