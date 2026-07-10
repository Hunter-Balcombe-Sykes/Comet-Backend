<?php

/**
 * GET /api/analytics/insights — the dedicated insights resource the dashboard's
 * Insights card consumes (the same array the summary embeds under `insights`).
 *
 * Proves the wired endpoint: fetch (AnalyticsQueryService) → derive
 * (InsightEngine) → cached response, on seeded rows. Only insights whose fetch
 * is SQLite-runnable are exercised here (time_of_day: link_clicks by hour); the
 * full fires/threshold/stat matrix lives in InsightEngineTest. The source-shift
 * insight is Postgres-only (ILIKE) and simply stays absent on the SQLite mirror,
 * which is itself the fail-open guarantee under test.
 */

use App\Models\Core\User\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupLinkClicksTable();
    setupSiteVisitsTable();
    setupSectionViewsTable();
});

function insightsUser(string $handle): User
{
    return User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

function insightsSite(User $user): void
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

function insertClickAtHour(string $userId, int $hour): void
{
    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'visitor_id' => (string) Str::uuid(),
        'occurred_at' => Carbon::now()->utc()->subDays(1)->setTime($hour, 0, 0)->toDateTimeString(),
        'url' => 'https://example.com/x',
        'created_at' => now()->toDateTimeString(),
    ]);
}

it('returns 200 with an insights array and the documented shape', function () {
    $user = insightsUser('insightspro');
    insightsSite($user);

    // 12 daytime clicks (hours 06–17, one each → rate 1.0/h) + 12 evening clicks
    // (hour 20 → rate 2.0/h) = 100% evening lift, 24 total (≥ 20 min).
    foreach (range(6, 17) as $h) {
        insertClickAtHour($user->id, $h);
    }
    foreach (range(1, 12) as $i) {
        insertClickAtHour($user->id, 20);
    }

    $response = actingAsUser($user)->getJson('/api/analytics/insights');
    $response->assertOk();

    // success() returns the payload at the root (no `data` envelope) — the
    // frontend reads `payload?.data ?? payload`.
    $insights = $response->json('insights');
    expect($insights)->toBeArray();

    $timeOfDay = collect($insights)->firstWhere('kind', 'time_of_day');
    expect($timeOfDay)->not->toBeNull()
        ->and($timeOfDay)->toHaveKeys(['id', 'kind', 'headline', 'supporting_stat', 'period'])
        ->and($timeOfDay['id'])->toBe('time_of_day')
        ->and($timeOfDay['headline'])->toContain('100%')
        ->and($timeOfDay['headline'])->toContain('evening')
        // Loose compare — JSON collapses the whole-number float 100.0 to 100;
        // exact-type correctness is pinned in InsightEngineTest.
        ->and($timeOfDay['supporting_stat']['value'])->toEqual(100.0)
        ->and($timeOfDay['supporting_stat']['unit'])->toBe('percent_change')
        ->and($timeOfDay['supporting_stat']['detail']['sample'])->toBe(24)
        ->and($timeOfDay['period'])->toHaveKeys(['from', 'to', 'label']);
});

it('returns an empty insights array (never fabricates) when there is no data', function () {
    $user = insightsUser('emptypro');
    insightsSite($user);

    $response = actingAsUser($user)->getJson('/api/analytics/insights');

    $response->assertOk();
    expect($response->json('insights'))->toBe([]);
});

it('404s when the professional has no site', function () {
    $user = insightsUser('nositepro');

    actingAsUser($user)->getJson('/api/analytics/insights')->assertStatus(404);
});
