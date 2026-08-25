<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimSiteService;
use App\Services\PreAccount\ClaimTokenIssuer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupNotificationsTable(); // ClaimSiteService::claim() always writes the welcome notification
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

// Local by design (cross-file helpers break --parallel). The factory creates no
// user, and user_id is NOT NULL and not fillable — attach via associate().
function claimTokenBuild(array $attrs = []): PreAccountBuild
{
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'auth_user_id' => null,
        'primary_email' => null,
    ]);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => true]);

    $build = PreAccountBuild::factory()->make(array_merge([
        'built_via' => PreAccountBuild::VIA_STAFF,
        'contact_email' => null,
        'build_state' => PreAccountBuild::STATE_READY,
        'expires_at' => now()->addDays(30),
    ], $attrs));
    $build->user()->associate($user);
    $build->save();

    return $build->fresh();
}

/** @return array{0: PreAccountBuild, 1: string} */
function outreachBuildWithToken(array $attrs = []): array
{
    $build = claimTokenBuild($attrs);

    return [$build, app(ClaimTokenIssuer::class)->issue($build)];
}

it('claims an outreach build with no contact_email when a valid token is presented', function () {
    [$build, $token] = outreachBuildWithToken();

    $result = app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'someone@example.com', $build->user->site->subdomain, false, $token,
    );

    expect($result['professional']->status)->toBe('active');
});

it('still throws CLAIM_NOT_INVITED with no token', function () {
    [$build] = outreachBuildWithToken();

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'someone@example.com', $build->user->site->subdomain,
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

it('still throws CLAIM_NOT_INVITED with a wrong token', function () {
    [$build, $token] = outreachBuildWithToken();

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'someone@example.com', $build->user->site->subdomain, false, $token.'x',
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

it('refuses a valid token on an expired build', function () {
    [$build, $token] = outreachBuildWithToken(['expires_at' => now()->subMinute()]);

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'someone@example.com', $build->user->site->subdomain, false, $token,
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

// ── The token is NARROW (spec §6.2) ──────────────────────────────────────────

it('does NOT let a token override an email-gated build with a mismatched address', function () {
    [$build, $token] = outreachBuildWithToken(['contact_email' => 'owner@example.com']);

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'attacker@example.com', $build->user->site->subdomain, false, $token,
    ))->toThrow(RuntimeException::class, 'CLAIM_EMAIL_MISMATCH');
});

it('lets a token claim an email-gated build when the address DOES match', function () {
    [$build, $token] = outreachBuildWithToken(['contact_email' => 'owner@example.com']);

    $result = app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'owner@example.com', $build->user->site->subdomain, false, $token,
    );

    expect($result['professional']->status)->toBe('active');
});

// ── Single-use = used, not opened (spec §4) ──────────────────────────────────

it('burns the token on a successful claim so a replay is refused', function () {
    [$build, $token] = outreachBuildWithToken();

    app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'first@example.com', $build->user->site->subdomain, false, $token,
    );

    expect($build->fresh()->claim_token_hash)->toBeNull();
});

it('leaves the token intact when the claim throws', function () {
    // REGRESSION GUARD, not a proof of rollback. After this task the burn is
    // folded into the final claimed_at write, so EVERY throw is structurally
    // before it. This test fails if someone moves the burn earlier.
    [$build, $token] = outreachBuildWithToken();
    $service = app(ClaimSiteService::class);

    $uid = (string) Str::uuid();
    [$other, $otherToken] = outreachBuildWithToken();
    $service->claim($uid, 'taken@example.com', $other->user->site->subdomain, false, $otherToken);

    // Same uid already owns a site → ACCOUNT_EXISTS on this one.
    expect(fn () => $service->claim($uid, 'taken@example.com', $build->user->site->subdomain, false, $token))
        ->toThrow(RuntimeException::class, 'ACCOUNT_EXISTS');

    expect($build->fresh()->claim_token_hash)->not->toBeNull();
});
