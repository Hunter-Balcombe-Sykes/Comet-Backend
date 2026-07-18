<?php

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\User\User;
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

// An UNSAVED User carrying just enough shape for actingAsUser() to derive JWT
// claims (sub + email) for a Supabase auth id with NO core.users row — the
// pre-claim state ClaimSiteService expects. auth_user_id isn't fillable (see
// ClaimSiteService's own comment to that effect), so it's set directly.
function claimJwtUser(string $uid, ?string $email): User
{
    $user = new User(['primary_email' => $email]);
    $user->auth_user_id = $uid;

    return $user;
}

it('claims a ready build end-to-end and returns the bootstrap-shaped payload', function () {
    makeReadyBuild(); // shared helper from ClaimSiteServiceTest.php (global Pest function)
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

it('404s an unknown subdomain', function () {
    actingAsUser(claimJwtUser('auth-uid-4', 'ghost@example.com'));

    $this->postJson('/api/claim', ['subdomain' => 'ghost'])
        ->assertStatus(404)
        ->assertJsonPath('code', 'CLAIM_NOT_FOUND');
});
