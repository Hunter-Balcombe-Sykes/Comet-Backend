<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEmailSubscriptionsTable();
    setupNotificationsTable();
    setupSubdomainAliasesTable(); // SiteCacheService::invalidateSite reads this (post-commit cache bust)
    Queue::fake(); // avoid SyncSubdomainToKvJob actually running under QUEUE_CONNECTION=sync
});

it('claims a ready build end-to-end and returns the bootstrap-shaped payload', function () {
    makeReadyBuild(); // suite-global helper — tests/Pest.php
    actingAsUser(claimJwtUser('auth-uid-1', 'jane@example.com'));

    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])
        ->assertOk()
        ->assertJsonStructure(['professional' => ['id', 'display_name', 'status'], 'site' => ['id', 'subdomain']])
        ->assertJsonPath('professional.status', 'active');

    // Pins the post-commit KV re-sync dispatch that converts the TTL'd
    // unclaimed entry to a permanent one (Task-12 review finding).
    Queue::assertPushed(SyncSubdomainToKvJob::class);
});

it('409s the second claimer', function () {
    makeReadyBuild();

    actingAsUser(claimJwtUser('auth-uid-1', 'jane@example.com'));
    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])->assertOk();

    actingAsUser(claimJwtUser('auth-uid-2', 'mallory@example.com'));
    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'ALREADY_CLAIMED');
});

it('422s a token with no verified email claim', function () {
    makeReadyBuild();

    // No email claim: actingAsUser() derives the 'email' claim from
    // $professional->primary_email, so passing null here yields a token
    // with no verified email — exactly like a phone-only Supabase session.
    actingAsUser(claimJwtUser('auth-uid-3', null));

    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');
});

it('422s a token whose email claim is present but unverified', function () {
    makeReadyBuild();

    // SEC-2: an unconfirmed Supabase session can carry a real `email` claim
    // that the caller never proved they own. Presence alone must not be
    // enough to claim a site.
    actingAsUser(claimJwtUser('auth-uid-5', 'jane@example.com'), ['email_verified' => false]);

    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');
});

it('claims successfully for the legacy email_verified-in-user_metadata cohort', function () {
    makeReadyBuild();

    // Legacy Supabase projects only populate email_verified inside
    // user_metadata, not at the JWT root — the dual-location read must not
    // lock these real users out.
    actingAsUser(claimJwtUser('auth-uid-6', 'jane@example.com'), [
        'email_verified' => null,
        'user_metadata' => ['email_verified' => true],
    ]);

    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])
        ->assertOk()
        ->assertJsonPath('professional.status', 'active');
});

it('404s an unknown subdomain', function () {
    actingAsUser(claimJwtUser('auth-uid-4', 'ghost@example.com'));

    $this->postJson('/api/claim', ['subdomain' => 'ghost'])
        ->assertStatus(404)
        ->assertJsonPath('code', 'CLAIM_NOT_FOUND');
});

it('PRIV-101: omitting marketing_opt_in creates no sidest_updates subscription', function () {
    makeReadyBuild();
    actingAsUser(claimJwtUser('auth-uid-1', 'jane@example.com'));

    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])->assertOk();

    expect(
        DB::connection('pgsql')->table('notifications.email_subscriptions')
            ->where('list_key', 'sidest_updates')
            ->where('email_lc', 'jane@example.com')
            ->exists()
    )->toBeFalse();
});

it('PRIV-101: marketing_opt_in=true creates a sidest_updates subscription', function () {
    makeReadyBuild();
    actingAsUser(claimJwtUser('auth-uid-1', 'jane@example.com'));

    $this->postJson('/api/claim', ['subdomain' => 'janedoe', 'marketing_opt_in' => true])->assertOk();

    $row = DB::connection('pgsql')->table('notifications.email_subscriptions')
        ->where('list_key', 'sidest_updates')
        ->where('email_lc', 'jane@example.com')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('subscribed');
});

it('409s a mismatched email on an email-gated build', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill(['contact_email' => 'owner@example.com'])->save();
    Queue::fake();

    actingAsUser(claimJwtUser('auth-uid-1', 'intruder@example.com'));
    $this->postJson('/api/claim', ['subdomain' => 'janedoe'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CLAIM_EMAIL_MISMATCH');
});
