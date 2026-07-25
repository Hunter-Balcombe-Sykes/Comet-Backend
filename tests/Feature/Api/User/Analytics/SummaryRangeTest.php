<?php

// Regression guard: the summary endpoint must accept the dashboard's widest
// range presets. days=365 ("Last Year" — and the client-capped "All Time")
// used to 422 because startOfDay/endOfDay padding pushed diffInDays to 365.9
// past a hard >365 boundary; wide ranges now clamp to 730 days instead.

use App\Http\Controllers\Api\User\Analytics\UserAnalyticsController;
use App\Models\Core\User\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSiteVisitsTable();
    setupLinkClicksTable();
});

function sumUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function sumSite(User $user): void
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

// NOTE: a happy-path 200 for days=365/3650 can't be pinned here — the summary
// query path uses Postgres ILIKE, which the SQLite test mirror can't prepare
// (known schema-drift class, see Comet-Backend CLAUDE.md "Verify Before
// Done"). The wide-range clamp is verified against the deployed backend via
// the dashboard's Last Year / All Time presets instead.

it('still rejects a genuinely malformed range', function () {
    $user = sumUser('badrange');
    sumSite($user);

    actingAsUser($user)
        ->getJson('/api/analytics?from=2026-06-01&to=2026-01-01')
        ->assertStatus(422);
});

// #API-5 — group_by=hour is forced regardless of range width; unbounded this would
// emit ~17.5k hourly buckets at the 730-day cap. clampHourlyLookback() is exercised
// directly via reflection (same technique as AnalyticsQueryServiceConfigDrivenTest) —
// the endpoint's happy path can't be pinned end-to-end on SQLite (see note above), but
// the clamp itself is pure Carbon math with no DB involved.

function invokeClampHourlyLookback(Carbon $from, Carbon $to, bool $forceHourly): Carbon
{
    $controller = (new ReflectionClass(UserAnalyticsController::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($controller, 'clampHourlyLookback');
    $method->setAccessible(true);

    return $method->invoke($controller, $from, $to, $forceHourly);
}

it('clamps the hourly lookback when group_by=hour spans a wide custom range', function () {
    $to = Carbon::parse('2026-07-01 23:59:59');
    $from = Carbon::parse('2024-01-01 00:00:00'); // ~2.5 years — well past the 7-day hourly window

    $clamped = invokeClampHourlyLookback($from, $to, true);

    expect($clamped->toDateString())->toBe($to->copy()->subDays(7)->startOfDay()->toDateString());
});

it('leaves the range untouched when the hourly window is already inside the clamp', function () {
    $to = Carbon::parse('2026-07-01 23:59:59');
    $from = Carbon::parse('2026-06-28 00:00:00'); // 3 days — inside the default 7-day window

    expect(invokeClampHourlyLookback($from, $to, true)->eq($from))->toBeTrue();
});

it('leaves the range untouched for daily granularity regardless of width (normal-range behaviour unaffected)', function () {
    $to = Carbon::parse('2026-07-01 23:59:59');
    $from = Carbon::parse('2024-01-01 00:00:00');

    expect(invokeClampHourlyLookback($from, $to, false)->eq($from))->toBeTrue();
});

it('sources the clamp window from config, not a hardcoded 7', function () {
    Config::set('partna.analytics.hourly_bucket_max_days', 2);

    $to = Carbon::parse('2026-07-01 23:59:59');
    $from = Carbon::parse('2026-06-20 00:00:00'); // 11 days — outside a 2-day window

    $clamped = invokeClampHourlyLookback($from, $to, true);

    expect($clamped->toDateString())->toBe($to->copy()->subDays(2)->startOfDay()->toDateString());
});
