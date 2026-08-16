<?php

// TEST-5: Strava /selection and custom/links exact-shape snapshots.
//
// StravaConnectionResource is enumerating-style (6 keys). The custom/links
// endpoint builds its shape from CardPayload without going through a Resource
// class. Neither had an exact-shape snapshot before this test.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupContentPopularityScoresTable();
    Queue::fake();
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

    // Convergence Phase 6: a pool item, not a connection payload. favicon/logo
    // are structurally absent from this lane (LinkPoolWriter's docblock), so the
    // frozen shape carries the KEYS with null values — the dashboard still reads
    // six keys plus the rank, which is what this snapshot exists to pin.
    $id = app(LinkPoolWriter::class)->add(
        $user->refresh(), 'https://acme.example', 'Acme Corp', 'The best company.',
    );

    // RANK-1: link_item ranks are keyed by the link URL, not its id — seed a real
    // rank row under the URL and assert the dashboard surfaces it.
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
            'id' => $id,
            'url' => 'https://acme.example',
            'name' => 'Acme Corp',
            'description' => 'The best company.',
            'favicon' => null,
            'logo' => null,
            // Rides every dashboard link since 2026-08-04 (content_popularity
            // ranks keyed by the link URL) — the Smart order switch sorts on it.
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

    // No title given — the host stands in, which is the pool hand-add contract.
    $id = app(LinkPoolWriter::class)->add($user->refresh(), 'https://minimal.example');

    // Deliberately no content_popularity_scores row for this link's URL — the
    // site exists and the reader runs its real query, it just finds nothing to
    // key against. The legitimate "no rank yet" contract, not the RANK-1
    // mismatch (which produced null for every link, seeded or not).
    actingAsUser($user)->getJson('/api/platforms/custom/links')
        ->assertOk()
        ->assertExactJson(['links' => [[
            'id' => $id,
            'url' => 'https://minimal.example',
            'name' => 'minimal.example',
            'description' => null,
            'favicon' => null,
            'logo' => null,
            'popularityRank' => null,
        ]]]);
});
