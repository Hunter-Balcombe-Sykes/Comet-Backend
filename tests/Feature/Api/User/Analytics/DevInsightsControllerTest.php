<?php

/**
 * GET /api/professional/dev-insights — dev/testing surface, own-site only.
 *
 * #SEC-14: the controller resolved `currentSite()` and read straight off it
 * with no `authorizeForUser` call — defense-in-depth only (the route sits in
 * the plain user.api group with no staff/env gate, but currentSite() always
 * resolves the caller's OWN site via the user_id-scoped relation, so there
 * was never a cross-tenant read possible through this specific gap). Mirrors
 * UserSiteActionsController::show's authorizeForUser idiom.
 */

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSectionViewsTable();
    setupLinkClicksTable();
    setupItemViewsTable();
    setupContentPopularityScoresTable();
});

function devInsightsUser(string $handle): User
{
    return User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function devInsightsSite(User $user): void
{
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $user->handle,
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('returns 200 with the documented shape for the site owner', function () {
    $user = devInsightsUser('devinsightspro');
    devInsightsSite($user);

    $response = actingAsUser($user)->getJson('/api/professional/dev-insights');

    $response->assertOk();
    expect($response->json())->toHaveKeys(['pages', 'items', 'daily_series']);
});

it('422s when the professional has no site (currentSite() ValidationException)', function () {
    $user = devInsightsUser('devinsightsnosite');

    actingAsUser($user)->getJson('/api/professional/dev-insights')->assertStatus(422);
});
