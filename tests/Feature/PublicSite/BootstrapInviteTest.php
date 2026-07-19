<?php

/**
 * OV-A hardening — the JWT-claim email binding on bootstrap. primary_email is
 * bound to the VERIFIED supabase_claims['email'] in
 * BootstrapRequest::prepareForValidation() before any of the fail-closed
 * checks in the controller run, so a body email can never spoof the verified
 * identity.
 *
 * Task 14 (Pre-Account Sites) retired the invite-token block, INVITE_INVALID /
 * INVITE_EMAIL_MISMATCH, and the WAITLIST_ONLY bypass entirely — they only
 * ever fired for `! hasExistingProfessional($uid)` callers, and such callers
 * now 410 SIGNUP_MOVED before reaching them. Early-access invite consumption
 * for account CREATION has no successor (POST /api/public/signup/build takes
 * no `invite` parameter) — signup is site-first now. The invite-completion
 * happy path and the three invite-deny tests that used to live here were
 * retired for that reason; equivalent "the retired block can't be reached"
 * coverage lives in BootstrapWaitlistGateTest + BootstrapRetirementTest
 * (tests/Feature/PreAccount).
 *
 * What survives here: the EMAIL_VERIFICATION_REQUIRED fail-closed guard fires
 * BEFORE the hasExistingProfessional check (it's the first thing bootstrap()
 * does), so it is unaffected by the retirement and still needs coverage,
 * including the case where an invite token is present in the body but inert.
 */

use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Requests\Api\BootstrapRequest;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\User\User;
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

// Task 14: the WAITLIST_ONLY bypass-without-a-token block only ever fired for
// `! hasExistingProfessional($uid)` callers, so a plain new-signup attempt
// (no invite) now 410s SIGNUP_MOVED before the (removed) gate would have run —
// regardless of the waitlist config. This replaces the old "blocks a waitlist
// bypass attempt" test; WAITLIST_ONLY itself is now covered on the build
// endpoint (PublicBuildEndpointsTest).
it('410s a plain new-signup attempt (no invite) — the retired waitlist gate never runs', function () {
    config(['partna.waitlist.enabled' => true]);

    $response = app(BootstrapController::class)->bootstrap(ovaBootstrapRequest([
        'primary_email' => 'body@example.test',
        'display_name' => 'Gate Crasher',
    ], 'no-invite-uid', 'crasher@example.test'));

    expect($response->getStatusCode())->toBe(410)
        ->and($response->getData(true)['code'] ?? null)->toBe('SIGNUP_MOVED');
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
