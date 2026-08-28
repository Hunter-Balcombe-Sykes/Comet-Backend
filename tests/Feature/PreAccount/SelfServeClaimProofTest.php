<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimSiteService;
use App\Services\PreAccount\ClaimTokenIssuer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// #W2-SEC-1: a self-serve (VIA_SIGNUP) build with no contact_email had NO
// ownership check at all — any Supabase-authenticated stranger who guessed
// the subdomain could claim it. This file pins the new lane-agnostic proof
// gate (config('partna.pre_account.require_claim_proof')) and — just as
// importantly — that leaving it OFF is completely funnel-neutral except for
// the pre-existing (2026-08-24) outreach gate, which must keep firing
// unconditionally regardless of the flag.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupNotificationsTable(); // ClaimSiteService::claim() always writes the welcome notification
    setupEmailSubscriptionsTable(); // HTTP-level test mirrors ClaimEndpointTest / ClaimWithTokenTest
    setupSubdomainAliasesTable(); // SiteCacheService::invalidateSite reads this (post-commit cache bust)
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

// Local by design (cross-file helpers break --parallel). Mirrors
// ClaimWithTokenTest's claimTokenBuild(), but VIA_SIGNUP — the lane this
// finding is actually about — instead of VIA_STAFF.
function selfServeBuild(array $attrs = []): PreAccountBuild
{
    $user = User::factory()->create([
        'status' => 'unclaimed',
        'auth_user_id' => null,
        'primary_email' => null,
    ]);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => true]);

    $build = PreAccountBuild::factory()->make(array_merge([
        'built_via' => PreAccountBuild::VIA_SIGNUP,
        'contact_email' => null,
        'build_state' => PreAccountBuild::STATE_READY,
        'expires_at' => now()->addDays(30),
    ], $attrs));
    $build->user()->associate($user);
    $build->save();

    return $build->fresh();
}

/** @return array{0: PreAccountBuild, 1: string} */
function selfServeBuildWithToken(array $attrs = []): array
{
    $build = selfServeBuild($attrs);

    return [$build, app(ClaimTokenIssuer::class)->issue($build)];
}

// ── The regression test the finding demands ──────────────────────────────────

it('throws CLAIM_NOT_INVITED for a self-serve build with no email and no token, leaving the row untouched', function () {
    config()->set('partna.pre_account.require_claim_proof', true);
    $build = selfServeBuild();

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'stranger@example.com', $build->user->site->subdomain,
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');

    $user = $build->user->fresh();
    expect($user->auth_user_id)->toBeNull()
        ->and($user->status)->toBe('unclaimed')
        ->and($build->fresh()->claimed_at)->toBeNull();
});

it('answers 404 CLAIM_NOT_FOUND over HTTP for the same self-serve takeover attempt, row unchanged', function () {
    config()->set('partna.pre_account.require_claim_proof', true);
    $build = selfServeBuild();
    actingAsUser(claimJwtUser('http-takeover-uid', 'stranger@example.com'));

    $this->postJson('/api/claim', [
        'subdomain' => $build->user->site->subdomain,
    ])
        ->assertStatus(404)
        ->assertJsonPath('code', 'CLAIM_NOT_FOUND');

    $user = $build->user->fresh();
    expect($user->auth_user_id)->toBeNull()
        ->and($user->status)->toBe('unclaimed')
        ->and($build->fresh()->claimed_at)->toBeNull();
});

it('claims a self-serve build with a valid token and burns it (single-use)', function () {
    config()->set('partna.pre_account.require_claim_proof', true);
    [$build, $token] = selfServeBuildWithToken();

    $result = app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'rightful-owner@example.com', $build->user->site->subdomain, false, $token,
    );

    expect($result['professional']->status)->toBe('active')
        ->and($build->fresh()->claim_token_hash)->toBeNull();
});

it('still throws CLAIM_NOT_INVITED for a self-serve build with a wrong token', function () {
    config()->set('partna.pre_account.require_claim_proof', true);
    [$build, $token] = selfServeBuildWithToken();

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'stranger@example.com', $build->user->site->subdomain, false, $token.'x',
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

// ── Flag OFF: funnel-neutral, except the pre-existing outreach gate ─────────

it('flag OFF: a tokenless self-serve claim still succeeds (no regression for the current funnel)', function () {
    config()->set('partna.pre_account.require_claim_proof', false);
    $build = selfServeBuild();

    $result = app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'rightful-owner@example.com', $build->user->site->subdomain,
    );

    expect($result['professional']->status)->toBe('active');
});

it('flag OFF: an outreach build with no email and no token still throws CLAIM_NOT_INVITED (2026-08-24 gate is unconditional)', function () {
    config()->set('partna.pre_account.require_claim_proof', false);

    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);
    Site::factory()->create(['user_id' => $user->id, 'is_published' => true]);
    $build = PreAccountBuild::factory()->make([
        'built_via' => PreAccountBuild::VIA_STAFF,
        'contact_email' => null,
        'build_state' => PreAccountBuild::STATE_READY,
        'expires_at' => now()->addDays(30),
    ]);
    $build->user()->associate($user);
    $build->save();

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'stranger@example.com', $build->user->site->subdomain,
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

// ── A token proves invitation only — it does not bypass other gates ─────────

it('does not let an expired token satisfy the self-serve proof gate', function () {
    config()->set('partna.pre_account.require_claim_proof', true);
    [$build, $token] = selfServeBuildWithToken(['expires_at' => now()->subMinute()]);

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'stranger@example.com', $build->user->site->subdomain, false, $token,
    ))->toThrow(RuntimeException::class, 'CLAIM_NOT_INVITED');
});

it('does not let a valid token bypass ALREADY_CLAIMED', function () {
    config()->set('partna.pre_account.require_claim_proof', true);
    [$build, $token] = selfServeBuildWithToken();

    // Simulate the build already having been claimed by someone else,
    // bypassing claim() itself so the token is never burned.
    $build->user->forceFill(['auth_user_id' => (string) Str::uuid(), 'status' => 'active'])->save();

    expect(fn () => app(ClaimSiteService::class)->claim(
        (string) Str::uuid(), 'someone.else@example.com', $build->user->site->subdomain, false, $token,
    ))->toThrow(RuntimeException::class, 'ALREADY_CLAIMED');
});

// Pins the RESOLVED default, not a config()->set() value. Every other test in
// this file forces the flag in one direction or the other, so none of them
// would notice if the shipped default flipped to true — and that flip breaks
// self-serve claiming for every user until the frontend forwards a token.
// Flipping it is a deliberate rollout step (commit 3), never a config tidy-up.
it('ships with claim-proof enforcement OFF', function () {
    expect(config('partna.pre_account.require_claim_proof'))->toBeFalse();
});
