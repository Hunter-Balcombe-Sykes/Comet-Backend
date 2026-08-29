<?php

// #W1-SCALE-3 — per-SITE pageview ingest ceiling.
//
// Every other control on POST /api/public/analytics/pageviews is per-IP
// (throttle:analytics = 120/min per visitor IP + a 3000/min per-true-IP
// backstop). A crawler sweep distributed across many source IPs against one
// viral page satisfies all of them, and the ingest it generates consumes
// shared `analytics` queue capacity belonging to every other tenant. The cap
// exercised here is the only bound keyed by the site.
//
// WHAT THIS IS NOT: it is not a bot filter and not a dedup. pageview()
// deliberately records bot user agents (labelled device_type='bot', separated
// at read time) and genuine refreshes — an owner decision pinned by
// PageviewDeviceTypeTest, which must stay green and unchanged. The last case
// below is the regression guard for exactly that.

use App\Services\Analytics\Contracts\AnalyticsIngestor;
use App\Services\Analytics\Ingestors\SyncIngestor;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSiteVisitsTable();

    // Sync ingestor so the row is queryable inline — the assertions here are all
    // about whether a ROW was written, which is the only thing the cap changes.
    app()->bind(AnalyticsIngestor::class, SyncIngestor::class);
});

/**
 * Post one pageview beacon as the site's own client-side tracker would.
 */
function burstCapPageview(TestCase $test, string $subdomain, string $siteId, string $userAgent = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36')
{
    return $test->withHeaders([
        'Origin' => 'https://'.$subdomain.'.'.config('partna.public_domain'),
        'User-Agent' => $userAgent,
    ])->postJson('/api/public/analytics/pageviews', [
        'site_id' => $siteId,
        'session_id' => (string) Str::uuid(),
        'visitor_id' => (string) Str::uuid(),
    ]);
}

it('writes a row for every pageview while the site is under its per-minute cap', function () {
    $this->freezeTime();
    config(['partna.analytics.pageview_site_cap_per_minute' => 5]);

    $tenant = createTenant('burstcap-under');

    for ($i = 0; $i < 3; $i++) {
        burstCapPageview($this, 'burstcap-under', $tenant->site->id)->assertStatus(201);
    }

    // No dedup: three beacons, three rows. A visitor-keyed dedup would collapse
    // these and is deliberately NOT part of this fix.
    expect(DB::connection('pgsql')->table('analytics.site_visits')
        ->where('site_id', $tenant->site->id)->count())->toBe(3);
});

it('still answers 201 with a visit_id over the cap but writes no further row', function () {
    $this->freezeTime();
    config(['partna.analytics.pageview_site_cap_per_minute' => 1]);

    $tenant = createTenant('burstcap-over');

    burstCapPageview($this, 'burstcap-over', $tenant->site->id)->assertStatus(201);

    $capped = burstCapPageview($this, 'burstcap-over', $tenant->site->id);

    // The response must stay byte-identical in shape so the cap cannot be
    // fingerprinted from the wire — asserting ONLY this would pass even if
    // nothing were capped, hence the row count below.
    $capped->assertStatus(201);
    expect($capped->json('visit_id'))->toBeString()->not->toBeEmpty();
    expect($capped->json('message'))->toBe('Pageview recorded');

    expect(DB::connection('pgsql')->table('analytics.site_visits')
        ->where('site_id', $tenant->site->id)->count())->toBe(1);
});

it('caps per site — one site exhausting its window does not suppress another', function () {
    $this->freezeTime();
    config(['partna.analytics.pageview_site_cap_per_minute' => 1]);

    $a = createTenant('burstcap-site-a');
    $b = createTenant('burstcap-site-b');

    // Site A burns its whole window.
    burstCapPageview($this, 'burstcap-site-a', $a->site->id)->assertStatus(201);
    burstCapPageview($this, 'burstcap-site-a', $a->site->id)->assertStatus(201);

    // Site B is untouched by A's traffic — this is the entire point of the fix.
    // A global counter would pass every other case in this file and fail here.
    burstCapPageview($this, 'burstcap-site-b', $b->site->id)->assertStatus(201);

    expect(DB::connection('pgsql')->table('analytics.site_visits')
        ->where('site_id', $a->site->id)->count())->toBe(1);
    expect(DB::connection('pgsql')->table('analytics.site_visits')
        ->where('site_id', $b->site->id)->count())->toBe(1);
});

/**
 * Every operation throws, the way phpredis does when the socket is gone.
 * A file-local copy of DeadCacheStoreTest's ThrowingStore, under its own name:
 * a class declared in another test file is not loadable when paratest assigns
 * the two files to different processes, and reusing the name would be a
 * duplicate declaration when they land in the same one.
 */
final class BurstCapDeadStore implements Store
{
    public function get($key)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function many(array $keys)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function put($key, $value, $seconds)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function putMany(array $values, $seconds)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function increment($key, $value = 1)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function decrement($key, $value = 1)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function forever($key, $value)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function forget($key)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function flush()
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }

    public function getPrefix()
    {
        return '';
    }

    public function add($key, $value, $seconds)
    {
        throw new RuntimeException('read error on connection to 127.0.0.1:6379');
    }
}

it('ingests normally when the cache store throws — the cap fails OPEN', function () {
    $this->freezeTime();
    // A cap of 1 would suppress the second beacon if the counter worked. It does
    // not work here, so a fail-CLOSED cap would drop the beacon instead — which
    // is what this case exists to catch.
    config(['partna.analytics.pageview_site_cap_per_minute' => 1]);

    $tenant = createTenant('burstcap-deadstore');

    // Only the Cache facade is redirected; the RateLimiter singleton keeps the
    // healthy array store so throttle:analytics still lets the request through
    // and the assertion is about the controller's own guard, not middleware.
    Cache::extend('burst-cap-dead', fn () => Cache::repository(new BurstCapDeadStore));
    config([
        'cache.stores.burst-cap-dead' => ['driver' => 'burst-cap-dead'],
        'cache.default' => 'burst-cap-dead',
    ]);

    burstCapPageview($this, 'burstcap-deadstore', $tenant->site->id)->assertStatus(201);
    burstCapPageview($this, 'burstcap-deadstore', $tenant->site->id)->assertStatus(201);

    // Analytics is fail-open by contract: a Valkey blip must never drop a beacon.
    expect(DB::connection('pgsql')->table('analytics.site_visits')
        ->where('site_id', $tenant->site->id)->count())->toBe(2);
});

it('still records a bot pageview under the cap — the label-dont-drop decision is intact', function () {
    $this->freezeTime();
    config(['partna.analytics.pageview_site_cap_per_minute' => 5]);

    $tenant = createTenant('burstcap-bot');

    burstCapPageview($this, 'burstcap-bot', $tenant->site->id, 'curl/8.4.0')->assertStatus(201);

    // The cap must not have become a bot filter by the back door. Same assertion
    // PageviewDeviceTypeTest makes, restated here so a future change to this file
    // cannot quietly reverse the owner decision it depends on.
    $row = DB::connection('pgsql')->table('analytics.site_visits')
        ->where('site_id', $tenant->site->id)->first();

    expect($row)->not->toBeNull();
    expect($row->device_type)->toBe('bot');
});
