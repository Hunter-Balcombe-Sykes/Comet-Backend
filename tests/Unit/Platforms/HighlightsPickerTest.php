<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\HighlightsPicker;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Unit 11 W2 — HighlightsPicker is what GenericPlatformController::recent()
// now delegates to (LIFE-21..24): serve the picker's items from the
// connection's private `recent` payload snapshot instead of live-scraping
// the vendor on EVERY modal open. See HighlightsPicker::items() for the
// 4-branch decision order these tests pin one at a time.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function hpUser(string $h): User
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

// A generic picker-platform row. HighlightsPicker only reads ->payload and
// ->last_refreshed_at, so the platform choice ('vimeo') is arbitrary — any
// registered highlights-capable platform would do.
function hpRow(User $user, array $payload, ?Carbon $lastRefreshedAt = null): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'vimeo',
        'resource_id' => 'vimeo',
        'payload' => $payload,
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => $lastRefreshedAt,
    ]);
}

// ── items(): the 4-branch decision ──────────────────────────────────────────

it('branch 1: serves a fresh non-empty snapshot without calling the strategy at all', function () {
    $user = hpUser('hp1');
    $row = hpRow($user, ['recent' => [['itemId' => 'a'], ['itemId' => 'b']]], now());

    $strategy = Mockery::mock(HighlightsStrategy::class);
    // Against unfixed code (GenericPlatformController::recent() calling
    // $strategy->recentItems() directly, with no HighlightsPicker at all),
    // this expectation is violated — the strategy IS called on every open.
    $strategy->shouldReceive('recentItems')->never();

    $items = app(HighlightsPicker::class)->items($strategy, $row, 'irrelevant-identity');

    expect($items)->toBe([['itemId' => 'a'], ['itemId' => 'b']]);
});

it('branch 2: live-fetches when the snapshot is stale, and serves the live result', function () {
    $user = hpUser('hp2');
    $row = hpRow($user, ['recent' => [['itemId' => 'old']]], now()->subHours(25)); // > 24h default TTL

    $strategy = Mockery::mock(HighlightsStrategy::class);
    $strategy->shouldReceive('recentItems')->once()->with('id')->andReturn([['itemId' => 'new']]);

    $items = app(HighlightsPicker::class)->items($strategy, $row, 'id');

    expect($items)->toBe([['itemId' => 'new']]);
});

it('branch 3: falls back to the stale snapshot when the live fetch fails — real-but-old beats an error', function () {
    $user = hpUser('hp3');
    $row = hpRow($user, ['recent' => [['itemId' => 'old']]], now()->subHours(25));

    $strategy = Mockery::mock(HighlightsStrategy::class);
    $strategy->shouldReceive('recentItems')->once()->andReturn(null);

    $items = app(HighlightsPicker::class)->items($strategy, $row, 'id');

    // Before this class existed, a failed live fetch always meant a 422 —
    // there was nothing to fall back to. This branch is the ONE behaviour
    // change beyond "don't re-scrape needlessly": null in, stale-but-usable
    // data out.
    expect($items)->toBe([['itemId' => 'old']]);
});

it('an empty snapshot counts as absent, not fresh, even with a fresh timestamp', function () {
    $user = hpUser('hp4');
    $row = hpRow($user, ['recent' => []], now()); // fresh timestamp, but EMPTY

    $strategy = Mockery::mock(HighlightsStrategy::class);
    // If empty-but-"fresh" were treated as usable, this would never fire and
    // items() would wrongly return [] instead of the live result.
    $strategy->shouldReceive('recentItems')->once()->andReturn([['itemId' => 'live']]);

    $items = app(HighlightsPicker::class)->items($strategy, $row, 'id');

    expect($items)->toBe([['itemId' => 'live']]);
});

it('branch 4: returns null when there is no snapshot at all and the live fetch fails — unchanged 422 contract', function () {
    $user = hpUser('hp5');
    $row = hpRow($user, [], null); // never connected/refreshed

    $strategy = Mockery::mock(HighlightsStrategy::class);
    $strategy->shouldReceive('recentItems')->once()->andReturn(null);

    $items = app(HighlightsPicker::class)->items($strategy, $row, 'id');

    // This is the ONE branch whose outward result is identical to before
    // HighlightsPicker existed — the controller still turns a null here into
    // its existing 422.
    expect($items)->toBeNull();
});

it('bounds the live-fetch fallback with the same wall-clock budget ConnectResolver opens around connect', function () {
    // Mirrors tests/Unit/Platforms/ConnectResolverTest.php's budget probe
    // ("opens a wall-clock budget around the strategy call"): an exhausted
    // (0s) budget means a strategy that tries a real SafeUrlFetcher call from
    // inside recentItems() never actually sends the request. Before this
    // change, GenericPlatformController::recent() had NO budget at all
    // (ConnectResolver only wrapped connect()) — against that code, the
    // probe's fetch proceeds normally and Http::assertNothingSent() below
    // fails.
    config()->set('partna.http_fetch.connect_budget_seconds', 0);
    Http::fake(['https://1.1.1.1/probe' => Http::response('ok', 200)]);

    $user = hpUser('hp6');
    $row = hpRow($user, [], null); // no snapshot → forces the live branch

    $probeStrategy = new class implements HighlightsStrategy
    {
        public function identity(array $payload): ?string
        {
            return null;
        }

        public function recentItems(string $identity): ?array
        {
            $sent = app(SafeUrlFetcher::class)->tryFetch('https://1.1.1.1/probe') !== null;

            return ['sent' => $sent];
        }

        public function apply(array $selection, array $items, array $chosenIds): array
        {
            return $selection;
        }

        public function requestField(): string
        {
            return 'itemIds';
        }

        public function rules(): array
        {
            return [];
        }

        public function responseKey(): string
        {
            return 'items';
        }

        public function notConnectedMessage(): string
        {
            return 'Connect first.';
        }

        public function loadErrorMessage(): string
        {
            return 'Could not load.';
        }
    };

    $items = app(HighlightsPicker::class)->items($probeStrategy, $row, 'anything');

    expect($items)->toBe(['sent' => false]);
    Http::assertNothingSent();
});

// ── fresh() ──────────────────────────────────────────────────────────────

it('fresh() treats last_refreshed_at within the TTL as fresh and past it (or absent) as stale', function () {
    $user = hpUser('hp7');
    $picker = app(HighlightsPicker::class);

    $withinTtl = hpRow($user, [], now()->subHours(23));
    expect($picker->fresh($withinTtl))->toBeTrue();

    $pastTtl = hpRow($user, [], now()->subHours(25));
    expect($picker->fresh($pastTtl))->toBeFalse();

    $never = hpRow($user, [], null);
    expect($picker->fresh($never))->toBeFalse();
});

// ── warmInto() ───────────────────────────────────────────────────────────
// Nothing calls warmInto() yet (it's built for a later phase's async connect
// job — see its docblock), so these are the only tests proving its contract:
// a no-op when the payload already carries `recent`, a backfill from the
// row's stored snapshot when it doesn't, and no key added when neither has one.

it('warmInto leaves an already-warm payload untouched', function () {
    $user = hpUser('hp8');
    $row = hpRow($user, ['recent' => [['itemId' => 'row-stale']]], now());

    $payload = ['recent' => [['itemId' => 'fresh-from-fetch']], 'name' => 'X'];

    $result = app(HighlightsPicker::class)->warmInto($payload, $row);

    expect($result['recent'])->toBe([['itemId' => 'fresh-from-fetch']]);
});

it('warmInto backfills recent from the row when the incoming payload has none', function () {
    $user = hpUser('hp9');
    $row = hpRow($user, ['recent' => [['itemId' => 'from-row']]], now());

    $payload = ['name' => 'X']; // no `recent` key at all

    $result = app(HighlightsPicker::class)->warmInto($payload, $row);

    expect($result['recent'])->toBe([['itemId' => 'from-row']]);
});

it('warmInto leaves recent absent when neither the payload nor the row has one', function () {
    $user = hpUser('hp10');
    $row = hpRow($user, [], null);

    $payload = ['name' => 'X'];

    $result = app(HighlightsPicker::class)->warmInto($payload, $row);

    expect($result)->not->toHaveKey('recent');
});
