<?php

// TEST-5: Strava /selection and custom/links exact-shape snapshots.
//
// StravaConnectionResource is enumerating-style (6 keys). The custom/links
// endpoint builds its shape from CardPayload without going through a Resource
// class. Neither had an exact-shape snapshot before this test.

use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
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

// ── Custom link CARD shape (LinkPoolReader::cards) ───────────────────────────
// The /platforms/custom/links endpoint left 2026-08-19; cards() is the shape
// every live reader consumes, so the snapshot pins the service now.

it('the link card freezes the exact per-link shape and strips payload-only fields', function () {
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

    expect(app(LinkPoolReader::class)->cards($user->refresh()))
        ->toEqual([[
            'id' => $id,
            'url' => 'https://acme.example',
            'name' => 'Acme Corp',
            'description' => 'The best company.',
            'favicon' => null,
            'logo' => null,
            // popularityRank left this shape with the retired controller —
            // the links POOL wire carries per-item ranks now (PoolResolver),
            // pinned by the content-pool contract tests.
        ]]);
});

it('the link card emits nulls for absent optional fields and unseeded ranks', function () {
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
    expect(app(LinkPoolReader::class)->cards($user->refresh()))
        ->toEqual([[
            'id' => $id,
            'url' => 'https://minimal.example',
            'name' => 'minimal.example',
            'description' => null,
            'favicon' => null,
            'logo' => null,
        ]]);
});

// The two strava cases were removed 2026-08-16: they froze the 6-key card
// selection shape (name/location/image/description/members/url) that the
// platform stopped producing when Phase 1.2 demoted it to link-only. Strava now
// publishes {username, url} like every other link-only platform, which
// PublicAllowlistCoverageTest and the link-only contract tests already cover.
// The custom/links half of this file is unaffected.
