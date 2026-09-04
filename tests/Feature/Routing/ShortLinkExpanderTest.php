<?php

// FI-3 (scan-refinement run, 2026-08-20): the shortener-expansion layer.
// Reproduced live before it existed: linktr.ee/samakhurst carried
// on.soundcloud.com/fh433tMk6lU9xgP3TM → soundcloud.com/sam-akhurst (the
// ARTIST profile), but with no expansion the short link fell to
// no-rule-matched and became a custom link card.

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\LinkInBioImporter;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Routing\ShortLinkExpander;
use App\Services\Cache\CacheLockService;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    Cache::flush();
    // QUEUE_CONNECTION=sync — without this the content-class enrichment
    // fetch (F9) runs inline against the Http fake, fails, and soft-deletes
    // the very connection the assertions read.
    Queue::fake();
});

it('knows which hosts are short links', function () {
    $expander = app(ShortLinkExpander::class);

    expect($expander->isShort('https://on.soundcloud.com/AbC123'))->toBeTrue()
        ->and($expander->isShort('https://spotify.link/xYz'))->toBeTrue()
        ->and($expander->isShort('https://bit.ly/abc'))->toBeTrue()
        ->and($expander->isShort('https://soundcloud.com/sam-akhurst'))->toBeFalse()
        // Aggregators are pages to unroll, never redirects to follow.
        ->and($expander->isShort('https://linktr.ee/samakhurst'))->toBeFalse()
        ->and($expander->isShort('not a url'))->toBeFalse();
});

it('expands a short link and routes its real destination (the sammy.pdf shape)', function () {
    $pro = createTenant('shortlink-artist');

    Http::fake([
        'on.soundcloud.com/*' => Http::response('', 302, ['Location' => 'https://soundcloud.com/sam-akhurst?ref=clipboard']),
        'soundcloud.com/*' => Http::response('ok', 200, ['Content-Type' => 'text/html']),
    ]);

    $out = app(LinkRoutingService::class)->route(
        'https://on.soundcloud.com/fh433tMk6lU9xgP3TM',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    // Since 2026-09-03 an indirect, unconfirmed origin like 'bio_harvest'
    // never reaches Place (only isConfirmedByUser() does) — this test's
    // subject is shortlink EXPANSION (does on.soundcloud.com/... resolve to
    // the real soundcloud.player/sam-akhurst target), which is unaffected:
    // the expanded destination still routes correctly, it just lands as a
    // proposed suggestion instead of a live connection.
    expect($out['verdict'])->toBe('choose')
        ->and($out['routedTo']['surfaceKey'] ?? null)->toBe('soundcloud.player')
        ->and($out['routedTo']['identifier'] ?? null)->toBe('sam-akhurst');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->exists())->toBeFalse();
});

it('rejects an unexpandable platform short code instead of minting a fake profile', function () {
    $pro = createTenant('shortlink-dead');

    Http::fake(['on.soundcloud.com/*' => Http::response('', 500)]);

    // Lowercase code — the exact shape that used to match the soundcloud
    // profile detector via the on.→soundcloud.com alias (confidence 75 ≥
    // auto 70) and mint an account named after the code.
    $out = app(LinkRoutingService::class)->route(
        'https://on.soundcloud.com/abc123xy',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('reject')
        ->and($out['blockReason'])->toBe('shortener');
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
});

// CCH-2: proves the fix is single-flight, not just memoized. Mocks
// SafeUrlFetcher (rather than Http::fake) and calls expandIfShort() directly
// on the ShortLinkExpander instance so the Cache facade mock surface stays
// small — LinkRoutingService's own cache/DB traffic would otherwise collide
// with the Cache::shouldReceive expectations below.

it('takes a single-flight lock on the shortlink key before fetching', function () {
    // Structurally unsatisfiable by unlocked code: the pre-fix method never
    // calls Cache::lock() at all, so the ->once() below fails at
    // Mockery::close(). This is the assertion that actually distinguishes
    // locked from unlocked.
    $url = 'https://on.soundcloud.com/lock-check';
    $key = 'shortlink:'.sha1($url);

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->with(2)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    // Initial miss, then the post-lock double-check (also a miss).
    Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:'.$key, 20)->once()->andReturn($lock);
    // 86400 = ShortLinkExpander::SUCCESS_TTL_SECONDS, now jittered +/-20% by
    // CacheLockService (CCH-3), so assert the band. It cannot overlap the 3600
    // failure band (2880..4320), so this still pins WHICH ttl was used.
    Cache::shouldReceive('put')
        ->with($key, 'https://soundcloud.com/sam-akhurst', Mockery::on(fn ($ttl) => is_int($ttl) && $ttl >= 69120 && $ttl <= 103680))
        ->once();

    $this->mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryResolveFinalUrl')->once()->andReturn('https://soundcloud.com/sam-akhurst');
    });

    $expanded = app(ShortLinkExpander::class)->expandIfShort($url);

    expect($expanded)->toBe('https://soundcloud.com/sam-akhurst');
});

it('caches a failed expansion under the SHORT failure TTL', function () {
    // Negative-TTL regression pin: fails if this method is ever "simplified"
    // onto rememberLocked's single-TTL shape, which has no room for a
    // separate (short) failure TTL.
    $url = 'https://on.soundcloud.com/dead-code';
    $key = 'shortlink:'.sha1($url);

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->with(2)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:'.$key, 20)->once()->andReturn($lock);
    // 3600 = ShortLinkExpander::FAILURE_TTL_SECONDS, NOT the 86400 success TTL.
    // Jittered +/-20% (CCH-3); the two bands cannot overlap, so this still pins
    // that the FAILURE ttl was the one used.
    Cache::shouldReceive('put')
        ->with($key, '__cache_lock_null_sentinel__', Mockery::on(fn ($ttl) => is_int($ttl) && $ttl >= 2880 && $ttl <= 4320))
        ->once();

    $this->mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryResolveFinalUrl')->once()->andReturn(null);
    });

    $expanded = app(ShortLinkExpander::class)->expandIfShort($url);

    expect($expanded)->toBe($url);
});

it('a caller that finds the cache filled after waiting on the lock issues no fetch', function () {
    // Today's (pre-fix) code has no post-lock double-check at all, so it
    // fetches unconditionally — this fails against unlocked code.
    $url = 'https://on.soundcloud.com/filled-while-waiting';
    $key = 'shortlink:'.sha1($url);

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->with(2)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, 'https://soundcloud.com/sam-akhurst');
    Cache::shouldReceive('lock')->with('lock:'.$key, 20)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $this->mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryResolveFinalUrl')->never();
    });

    $expanded = app(ShortLinkExpander::class)->expandIfShort($url);

    expect($expanded)->toBe('https://soundcloud.com/sam-akhurst');
});

it('treats a legacy empty-string sentinel as keep-the-original-url', function () {
    // Deploy-window forward-compat pin, not a stampede pin. Pre-fix code
    // wrote '' for a failed expansion; rememberLockedNullable treats '' as an
    // ordinary (non-null, non-sentinel) cached value and returns it verbatim,
    // so the `$expanded !== ''` guard in expandIfShort() is what keeps an old
    // entry from being handed back as the "expanded" URL until it TTLs out.
    $url = 'https://on.soundcloud.com/legacy-sentinel';
    $key = 'shortlink:'.sha1($url);

    Cache::put($key, '', 60);

    $this->mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryResolveFinalUrl')->never();
    });

    $expanded = app(ShortLinkExpander::class)->expandIfShort($url);

    expect($expanded)->toBe($url);
});

// Proves memoization only (two SEQUENTIAL requests, the #TEST-8 shape), NOT
// single-flight concurrency — see the lock-acquisition/double-check tests
// above for the property that actually distinguishes locked from unlocked.
it('caches an expansion so the preview → route pair fetches once', function () {
    $pro = createTenant('shortlink-cache');

    Http::fake([
        'on.soundcloud.com/*' => Http::response('', 302, ['Location' => 'https://soundcloud.com/sam-akhurst']),
        'soundcloud.com/*' => Http::response('ok', 200, ['Content-Type' => 'text/html']),
    ]);

    $ctx = RoutingContext::forUser($pro, 'paste');
    app(LinkRoutingService::class)->preview('https://on.soundcloud.com/fh433tMk6lU9xgP3TM', $ctx);
    app(LinkRoutingService::class)->route('https://on.soundcloud.com/fh433tMk6lU9xgP3TM', $ctx);

    $shortHits = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'on.soundcloud.com'))
        ->count();
    expect($shortHits)->toBe(1);
});

it('keeps the canonicalizer rejecting platform short hosts when expansion is bypassed', function () {
    // Belt and braces: if a lane ever reaches canonicalize() without the
    // expander (or expansion failed), the host must reject as 'shortener' —
    // on. is a genuine soundcloud.com subdomain, so falling through would
    // evaluate the parent's detectors against an opaque code.
    $iri = app(IriCanonicalizer::class)->canonicalize('https://on.soundcloud.com/abc123xy');

    expect($iri->rejected)->toBe('shortener');
});

it('hands downstream consumers the EXPANDED url — probes never chase the short one (FI-9)', function () {
    // T4 live: route() expanded internally, but the importer's probe
    // dispatch and card fallback still carried the SHORT url — a tinyurl'd
    // page was probed as tinyurl.com (instant shortener reject, probe
    // wasted) and carded as "tinyurl.com" while its expansion routed
    // separately.
    $pro = createTenant('fi9-expanded-probe');

    Http::fake([
        'example.com/*' => Http::response('<a href="https://bit.ly/fi9code">My store</a>', 200, ['Content-Type' => 'text/html']),
        'bit.ly/*' => Http::response('', 302, ['Location' => 'https://example.org/shop']),
        'example.org/*' => Http::response('<html><body>shop</body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/bio');

    Queue::assertPushed(CommerceProbeJob::class, fn ($job) => $job->url === 'https://example.org/shop');
    Queue::assertNotPushed(CommerceProbeJob::class, fn ($job) => str_contains($job->url, 'bit.ly'));
});

// CCH-5: resolveFinal()'s catch had an empty body — comment only. The null it
// returns is negative-cached for FAILURE_TTL_SECONDS, so a real defect or a
// budget exhaustion looked exactly like "not expandable" for an hour, with
// nothing at all reaching Nightwatch.
it('logs a breadcrumb when short-link expansion throws, instead of failing silently', function () {
    Log::spy();

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryResolveFinalUrl')->once()->andThrow(new RuntimeException('fetch budget exhausted'));

    $expander = new ShortLinkExpander($fetcher, app(CacheLockService::class));

    $result = $expander->expandIfShort('https://on.soundcloud.com/AbC123');

    // Still fail-OPEN: the caller gets the original URL back, never an exception.
    expect($result)->toBe('https://on.soundcloud.com/AbC123');

    // Multi-argument matcher on purpose: a single-arg shouldHaveReceived is a
    // documented vacuous shape in this repo.
    Log::shouldHaveReceived('warning')
        ->with('routing.shortlink_expand_failed', Mockery::type('array'))
        ->once();
});

// #W2-SEC-17 (review round 2): Mockery::type('array') above would pass
// identically whether the payload carries the raw url, host+path, or nothing
// at all — vacuous. For this class's target hosts the path IS the opaque
// short-code (the whole identifying secret), so the failure log must not
// reconstruct it: no 'path' key, and no substring of the pasted path or query
// string anywhere in the logged values.
it('does not log the short link path or query string on expansion failure', function () {
    Log::spy();

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryResolveFinalUrl')->once()->andThrow(new RuntimeException('fetch budget exhausted'));

    $expander = new ShortLinkExpander($fetcher, app(CacheLockService::class));

    $expander->expandIfShort('https://on.soundcloud.com/fh433tMk6lU9xgP3TM?sig=topsecret');

    Log::shouldHaveReceived('warning')
        ->with('routing.shortlink_expand_failed', Mockery::on(function (array $payload) {
            expect($payload)->not->toHaveKey('path')
                ->and($payload)->not->toHaveKey('url');

            foreach ($payload as $value) {
                if (is_string($value)) {
                    expect($value)->not->toContain('fh433tMk6lU9xgP3TM')
                        ->and($value)->not->toContain('topsecret')
                        ->and($value)->not->toContain('?');
                }
            }

            return true;
        }))
        ->once();
});
