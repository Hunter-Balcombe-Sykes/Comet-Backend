<?php

// TEST-5: Strava /selection and custom/links exact-shape snapshots.
//
// StravaConnectionResource is enumerating-style (6 keys). The custom/links
// endpoint builds its shape from CardPayload without going through a Resource
// class. Neither had an exact-shape snapshot before this test.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentPopularityScoresTable();
});

function stravaCustomUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// ── Strava /selection ─────────────────────────────────────────────────────────

it('strava selection freezes the exact 6-key shape and strips unknown stored keys', function () {
    $user = stravaCustomUser('stsel1');
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'strava',
        'resource_id' => 'strava',
        'payload' => [
            'url' => 'https://www.strava.com/clubs/fade-lab',
            'name' => 'Fade Lab Running Club',
            'location' => 'Melbourne, Australia',
            'image' => 'https://dgalywyr863hv.cloudfront.net/pictures/clubs/1234/logo.jpg',
            'description' => 'The best running club in Melbourne.',
            'members' => 142,
            // Internal key outside the StravaConnectionResource allowlist:
            '_internal' => 'leak',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $selection = actingAsUser($user)->getJson('/api/platforms/strava/selection')
        ->assertOk()
        ->json('selection');

    // StravaConnectionResource emits exactly {url, name, location, image, description, members}.
    expect($selection)->toEqual([
        'url' => 'https://www.strava.com/clubs/fade-lab',
        'name' => 'Fade Lab Running Club',
        'location' => 'Melbourne, Australia',
        'image' => 'https://dgalywyr863hv.cloudfront.net/pictures/clubs/1234/logo.jpg',
        'description' => 'The best running club in Melbourne.',
        'members' => 142,
    ]);

    expect($selection)->not->toHaveKey('_internal', "'_internal' must not appear in the selection");
});

it('strava selection emits nulls for absent optional fields', function () {
    $user = stravaCustomUser('stsel2');
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'strava',
        'resource_id' => 'strava',
        'payload' => [
            'url' => 'https://www.strava.com/clubs/fade-lab',
            'name' => 'Fade Lab Running Club',
            // location, image, description, members absent → resource defaults to null.
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $selection = actingAsUser($user)->getJson('/api/platforms/strava/selection')
        ->assertOk()
        ->json('selection');

    // All optional fields fall back to null; key set must still be exactly 6.
    expect($selection)->toEqual([
        'url' => 'https://www.strava.com/clubs/fade-lab',
        'name' => 'Fade Lab Running Club',
        'location' => null,
        'image' => null,
        'description' => null,
        'members' => null,
    ]);
});

// ── Custom links GET /api/platforms/custom/links ──────────────────────────────

it('custom/links freezes the exact per-link shape and strips payload-only fields', function () {
    $user = stravaCustomUser('clsel1');
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => 'clsel1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    // Seed a row manually so we don't need to hit the external scraper.
    // The controller's linksData() reads CardPayload fields: url, name, description,
    // favicon, logo. The `kind` field is stored but only used internally.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'custom',
        'resource_id' => 'link-abc123def456',
        'resource_kind' => 'link',
        'payload' => [
            'kind' => 'link',
            'url' => 'https://acme.example',
            'name' => 'Acme Corp',
            'description' => 'The best company.',
            'favicon' => 'https://acme.example/favicon.ico',
            'logo' => 'https://acme.example/og-image.png',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    // RANK-1: link_item ranks are keyed by payload.url, not resource_id —
    // seed a real rank row under the URL and assert the dashboard surfaces it.
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'content_type' => 'link_item',
        'content_key' => 'https://acme.example',
        'score' => 12.5,
        'rank' => 2,
        'computed_at' => now()->toDateTimeString(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/custom/links')
        ->assertOk()
        ->assertExactJson(['links' => [[
            'id' => 'link-abc123def456',
            'url' => 'https://acme.example',
            'name' => 'Acme Corp',
            'description' => 'The best company.',
            'favicon' => 'https://acme.example/favicon.ico',
            'logo' => 'https://acme.example/og-image.png',
            // Rides every dashboard link since 2026-08-04 (content_popularity
            // ranks keyed by payload.url) — the Smart order switch sorts on it.
            'popularityRank' => 2,
        ]]]);
});

it('custom/links emits nulls for absent optional fields and unseeded ranks', function () {
    $user = stravaCustomUser('clsel2');
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => 'clsel2',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'custom',
        'resource_id' => 'link-000000000000',
        'resource_kind' => 'link',
        'payload' => [
            'kind' => 'link',
            'url' => 'https://minimal.example',
            // name, description, favicon, logo absent → CardPayload defaults to null.
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    // Deliberately no content_popularity_scores row for this link's URL —
    // the site exists and the reader runs its real query, it just finds
    // nothing to key against. This is the legitimate "no rank yet" contract,
    // not the RANK-1 mismatch (which produced null for every link, seeded or not).
    actingAsUser($user)->getJson('/api/platforms/custom/links')
        ->assertOk()
        ->assertExactJson(['links' => [[
            'id' => 'link-000000000000',
            'url' => 'https://minimal.example',
            'name' => null,
            'description' => null,
            'favicon' => null,
            'logo' => null,
            'popularityRank' => null,
        ]]]);
});
