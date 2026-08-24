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

    expect($out['verdict'])->toBe('place')
        ->and($out['routedTo']['surfaceKey'] ?? null)->toBe('soundcloud.player')
        ->and($out['routedTo']['identifier'] ?? null)->toBe('sam-akhurst');

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->firstOrFail();
    expect($connection->resource_id)->toBe('sam-akhurst');
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
    // 86400 = ShortLinkExpander::SUCCESS_TTL_SECONDS.
    Cache::shouldReceive('put')->with($key, 'https://soundcloud.com/sam-akhurst', 86400)->once();

    $this->mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryFetch')->once()->andReturn(['finalUrl' => 'https://soundcloud.com/sam-akhurst']);
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
    Cache::shouldReceive('put')->with($key, '__cache_lock_null_sentinel__', 3600)->once();

    $this->mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryFetch')->once()->andReturn(['finalUrl' => null]);
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
        $m->shouldReceive('tryFetch')->never();
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
        $m->shouldReceive('tryFetch')->never();
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
