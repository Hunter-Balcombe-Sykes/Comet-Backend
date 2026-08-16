<?php

use App\Enums\SitepageId;
use App\Services\Cloudflare\CloudflarePurgeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** Every URL POSTed across all purge_cache requests, flattened in send order. */
function cfRecordedFiles(): array
{
    return collect(Http::recorded())
        ->flatMap(fn ($pair) => (array) $pair[0]['files'])
        ->all();
}

/** The deep-link sub-pages the service purges: SitepageId taxonomy minus
 *  'home', plus the standalone routes (/privacy, /terms, /about). */
function cfDeepLinkSubPages(): array
{
    return [
        ...array_values(array_filter(
            SitepageId::canonicalOrder(),
            static fn (string $p): bool => $p !== 'home',
        )),
        'privacy',
        'terms',
        'about',
    ];
}

it('no-ops when unconfigured (no zone_id or token)', function () {
    Config::set('services.cloudflare.zone_id', '');
    Config::set('services.cloudflare.cache_purge_token', '');
    Http::fake();

    (new CloudflarePurgeService)->purgeUrls(['https://x.partna.au/']);

    Http::assertNothingSent();
});

it('no-ops on empty url list even when configured', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake();

    (new CloudflarePurgeService)->purgeUrls([]);

    Http::assertNothingSent();
});

it('POSTs purge_cache with files payload for the configured zone', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeUrls([
        'https://h1.partna.au/',
        'https://h1.partna.au',
    ]);

    Http::assertSent(function ($req) {
        return $req->url() === 'https://api.cloudflare.com/client/v4/zones/zoneXYZ/purge_cache'
            && $req->method() === 'POST'
            && $req->hasHeader('Authorization', 'Bearer tok')
            && $req['files'] === ['https://h1.partna.au/', 'https://h1.partna.au'];
    });
});

it('chunks purgeUrls into <=30-URL requests (Cloudflare files limit)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    // Pace-spy (not `new CloudflarePurgeService`) so this doesn't incur real
    // usleep — this test asserts chunking, not pacing.
    $urls = array_map(fn ($i) => "https://h.partna.au/p{$i}", range(1, 65));
    purgeServiceWithPaceSpy()->purgeUrls($urls);

    expect(Http::recorded())->toHaveCount(3); // 30 + 30 + 5
    Http::recorded()->each(fn ($pair) => expect(count($pair[0]['files']))->toBeLessThanOrEqual(30));
    expect(cfRecordedFiles())->toBe($urls); // order preserved, nothing dropped
});

it('purgeHandle purges root + every deep-link sub-page + shadows + API', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('  MIXED-CASE  ');

    $files = cfRecordedFiles();
    $base = 'https://mixed-case.partna.au';

    // root (slash + slash-less) + root shadow + the API subrequests
    expect($files)->toContain("{$base}/", $base, "{$base}/_swr-shadow/");
    expect($files)->toContain('https://dev-api.partna.au/api/public/profiles/mixed-case');
    expect($files)->toContain('https://dev-api.partna.au/api/public/profiles/mixed-case/integrations');
    // `/platforms` is the legacy alias onto the SAME controller — purging one and
    // not the other left the alias serving a pre-mutation payload.
    expect($files)->toContain('https://dev-api.partna.au/api/public/profiles/mixed-case/platforms');
    // `/menu` is NOT purged — the endpoint was deleted in slice 7 Phase 3 Task 10
    // (spec D2); menus reach the wire through `pools.menus` on the profile URL.
    expect($files)->not->toContain('https://dev-api.partna.au/api/public/profiles/mixed-case/menu');
    // every sub-page + its shadow; 'home' is the root, never a sub-page
    foreach (cfDeepLinkSubPages() as $page) {
        expect($files)->toContain("{$base}/{$page}", "{$base}/_swr-shadow/{$page}");
    }
    // One needle per call: toContain is variadic and `not` means "not ALL of
    // them", so a two-needle negation passes the moment either is absent.
    expect($files)->not->toContain("{$base}/home")
        ->and($files)->not->toContain("{$base}/_swr-shadow/home");
    // exact size: 3 root + 2 per sub-page + 3 API (profile + integrations + platforms)
    expect($files)->toHaveCount(3 + 2 * count(cfDeepLinkSubPages()) + 3);
});

it('purgeHandle also busts the custom domain edge cache when one is given', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane', 'Tuesdae.co');

    $files = cfRecordedFiles();
    // both hosts get root + shadow + a representative sub-page + its shadow
    expect($files)->toContain(
        'https://jane.partna.au/', 'https://jane.partna.au/_swr-shadow/',
        'https://jane.partna.au/shop', 'https://jane.partna.au/_swr-shadow/shop',
        'https://tuesdae.co/', 'https://tuesdae.co/_swr-shadow/',
        'https://tuesdae.co/shop', 'https://tuesdae.co/_swr-shadow/shop',
        'https://dev-api.partna.au/api/public/profiles/jane',
        'https://dev-api.partna.au/api/public/profiles/jane/integrations',
        'https://dev-api.partna.au/api/public/profiles/jane/platforms',
    );
    // `/menu` died with its endpoint (slice 7 Phase 3 Task 10, spec D2).
    expect($files)->not->toContain('https://dev-api.partna.au/api/public/profiles/jane/menu');
    // The API subrequests are keyed on the backend host, so they are emitted ONCE
    // no matter how many site hosts are purged — a custom domain must not double them.
    // two hosts -> 2 x (3 root + 2 per sub-page) + 3 API (profile + integrations + platforms)
    expect($files)->toHaveCount(2 * (3 + 2 * count(cfDeepLinkSubPages())) + 3);
});

it('purgeHandle strips trailing slash on app.url before composing API URL', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au/');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane');

    expect(cfRecordedFiles())
        ->toContain('https://dev-api.partna.au/api/public/profiles/jane')
        ->not->toContain('https://dev-api.partna.au//api/public/profiles/jane');
});

it('purgeHandle skips the API URL when app.url is unset', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', '');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane');

    $files = cfRecordedFiles();
    expect($files)->toContain('https://jane.partna.au/', 'https://jane.partna.au/shop');
    // no API entry -> exactly 3 root + 2 per sub-page
    expect($files)->toHaveCount(3 + 2 * count(cfDeepLinkSubPages()));
    expect(collect($files)->filter(fn ($u) => str_contains($u, '/api/public/profiles/'))->all())->toBe([]);
});

it('purgeHandle composes page URLs against the configured public domain (non-prod TLD)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('partna.public_domain', 'staging.partna.test');
    Config::set('app.url', '');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane');

    // targets follow public_domain, so a staging/non-prod TLD hits the right zone
    expect(cfRecordedFiles())
        ->toContain(
            'https://jane.staging.partna.test/',
            'https://jane.staging.partna.test/shop',
            'https://jane.staging.partna.test/_swr-shadow/shop',
        )
        ->not->toContain('https://jane.partna.au/');
});

// --- cache-edge-reconcile/LIFE-1 residual: bounded per-call HTTP timeout ---

it('bounds each purge_cache POST with an explicit timeout + connect timeout (LIFE-1 residual)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');

    $capturedOptions = [];
    // Http::fake's callback form receives the raw Guzzle transfer options as the
    // 2nd arg — the only place PendingRequest::timeout()/connectTimeout() are
    // observable, since they aren't part of the PSR request Http::recorded() exposes.
    Http::fake(function ($request, $options) use (&$capturedOptions) {
        $capturedOptions[] = $options;

        return Http::response(['success' => true], 200);
    });

    (new CloudflarePurgeService)->purgeUrls(['https://h1.partna.au/']);

    expect($capturedOptions)->toHaveCount(1)
        ->and($capturedOptions[0]['timeout'] ?? null)->toBe(10)
        ->and($capturedOptions[0]['connect_timeout'] ?? null)->toBe(3);
});

// --- cache-edge-reconcile/LIFE-1 residual: volume-signal log ---

it('logs a volume-warning when purgeHandle enumerates more URLs than the configured threshold (LIFE-1 residual)', function () {
    // Seed the tables the product/menu/event enrichment lookups join against so
    // they resolve to empty result sets instead of throwing "no such table" —
    // otherwise the unrelated OBS-101 lookup-failure warnings pollute the count.
    // content.* since slice 5a Task 8: the product-handle lookup reads
    // content.collection_items/f_catalog, not site.shop_products.
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Config::set('partna.public_domain', 'partna.au');
    Config::set('partna.cache.purge_url_volume_warning_threshold', 10);
    Http::fake(['*' => Http::response(['success' => true], 200)]);
    Log::spy();

    (new CloudflarePurgeService)->purgeHandle('bigcatalog');

    $expectedCount = 3 + 2 * count(cfDeepLinkSubPages()) + 3;
    Log::shouldHaveReceived('warning')->times(1);
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $msg, array $ctx) => $msg === 'cloudflare.purge.url_volume_high'
            && ($ctx['handle'] ?? null) === 'bigcatalog'
            && ($ctx['url_count'] ?? null) === $expectedCount
            && ($ctx['threshold'] ?? null) === 10
    );
});

it('does not log a volume-warning when the URL count is at or below the configured threshold (LIFE-1 residual)', function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', '');
    Config::set('partna.public_domain', 'partna.au');
    Config::set('partna.cache.purge_url_volume_warning_threshold', 100_000);
    Http::fake(['*' => Http::response(['success' => true], 200)]);
    Log::spy();

    (new CloudflarePurgeService)->purgeHandle('smallcatalog');

    Log::shouldNotHaveReceived('warning');
});

it('purgeHandle ignores empty handles', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake();

    (new CloudflarePurgeService)->purgeHandle('');

    Http::assertNothingSent();
});

/** Anonymous subclass that counts paceBetweenChunks() calls instead of really
 *  sleeping — captures the pacing MECHANISM (SCALE-101) without slowing the
 *  suite down or asserting on wall-clock time. Plain subclassing (not a
 *  Mockery partial mock) so the real constructor runs and initialises the
 *  readonly zoneId/apiToken/configured properties from config as normal. */
function purgeServiceWithPaceSpy(): object
{
    return new class extends CloudflarePurgeService
    {
        public int $paceCalls = 0;

        protected function paceBetweenChunks(): void
        {
            $this->paceCalls++;
        }
    };
}

it('paces between purge_cache chunk POSTs on a multi-chunk purge (SCALE-101)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    // 65 URLs -> 3 chunks (30/30/5) -> pacing fires BETWEEN chunks: exactly twice,
    // never before the first POST or after the last.
    $urls = array_map(fn ($i) => "https://h.partna.au/p{$i}", range(1, 65));

    $service = purgeServiceWithPaceSpy();
    $service->purgeUrls($urls);

    expect(Http::recorded())->toHaveCount(3);
    expect($service->paceCalls)->toBe(2);
});

it('caps TOTAL pacing time so a large purge cannot re-inflate guaranteed sleep past a fixed budget (SCALE-101)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    // Realistic worst case for one purgeHandle() call (see the docblock on
    // CHUNK_PACING_MICROSECONDS): 39 subpage urls + 200 product + 300 menu +
    // 200 event urls per host x 2 hosts (canonical + custom domain) + 3 API
    // urls = 1,481 -> chunked at 30 -> 50 chunks -> 49 POTENTIAL inter-chunk
    // gaps. Pin the pacing budget so a future limit increase (more products,
    // more menu items, more events) can't silently re-blow the guaranteed-sleep
    // contribution to CloudflareCachePurgeJob's 15s timeout in lockstep with
    // chunk count — this must fail if someone raises a limit (or removes the
    // budget) without revisiting pacing.
    $urls = array_map(fn ($i) => "https://h.partna.au/p{$i}", range(1, 1480));

    $service = purgeServiceWithPaceSpy();
    $service->purgeUrls($urls);

    expect(Http::recorded())->toHaveCount(50) // ceil(1480 / 30)
        // 49 gaps exist, but the budget caps REAL sleeps at
        // floor(2_000_000us budget / 50_000us per pace) = 40.
        ->and($service->paceCalls)->toBe(40)
        ->and($service->paceCalls)->toBeLessThan(49);
});

it('does not pace a single-chunk purge (no gap to pace between)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $service = purgeServiceWithPaceSpy();
    $service->purgeUrls(['https://h.partna.au/']);

    expect(Http::recorded())->toHaveCount(1);
    expect($service->paceCalls)->toBe(0);
});

it('reports and warns (not silently debug-logs) when the product/menu/event enrichment lookups fail (OBS-101)', function () {
    // Deliberately skip setupSitesTable() — the schemas are ATTACHed (empty) but
    // none of content.collection_items / site.menu_items / core.users exist,
    // so all three DB::table(...) lookups inside purgeHandle()
    // throw "no such table". Proves: (a) each is now reported to Nightwatch at
    // 'warning' — not the previous 'debug', invisible under the default
    // log_level=warning gate (config/nightwatch.php) — and (b) the purge itself
    // still succeeds despite all three lookups failing (the documented fail-open
    // contract: "never let this optional lookup break the purge itself").
    attachTestSchemas();
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', '');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);
    Exceptions::fake();
    Log::spy();

    (new CloudflarePurgeService)->purgeHandle('nouser');

    // The page-only purge still fired despite all 3 enrichment lookups failing.
    Http::assertSent(fn ($req) => $req->method() === 'POST');

    // TWO, not three, since slice 4: the menu lane now escalates through
    // EscalatesRepeatedFaults instead of calling report() raw. Its raw report
    // was un-deduped while CloudflareCachePurgeJob self-dispatches three
    // delayed follow-ups, so ONE site save reported the same fault four times.
    // The warning still fires every time — the log line is the per-occurrence
    // record; report() is the pager, and a pager that fires four times per
    // save for one broken query is noise, not signal. The next test proves a
    // SUSTAINED menu fault still reaches Nightwatch.
    Exceptions::assertReportedCount(2);
    // Total warning-level call count, independent of args matching below —
    // catches a regression that fires the right count but wrong message/level.
    Log::shouldHaveReceived('warning')->times(3);
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $msg, array $ctx) => $msg === 'CloudflarePurgeService: product-handle lookup failed, purging pages only'
            && ($ctx['handle'] ?? null) === 'nouser'
    );
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $msg, array $ctx) => $msg === 'CloudflarePurgeService: menu-item lookup failed, purging pages only'
            && ($ctx['handle'] ?? null) === 'nouser'
    );
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $msg, array $ctx) => $msg === 'CloudflarePurgeService: event-id lookup failed, purging pages only'
            && ($ctx['handle'] ?? null) === 'nouser'
    );
});

it('escalates a SUSTAINED menu-item lookup failure to Nightwatch', function () {
    // The other half of the dedupe: quieting the per-occurrence report is only
    // acceptable if a real, ongoing breakage still pages someone. The trait
    // reports the FAULT_THRESHOLD-th fault inside a 10-minute window, so a
    // genuinely broken lookup surfaces on the second site save (each save runs
    // the purge four times — once plus three delayed follow-ups).
    attachTestSchemas();
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', '');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);
    Exceptions::fake();

    $service = new CloudflarePurgeService;
    for ($i = 0; $i < CloudflarePurgeService::FAULT_THRESHOLD; $i++) {
        $service->purgeHandle('nouser');
    }

    // 5 runs x 2 always-reporting lanes (product, event) = 10, plus exactly ONE
    // from the menu lane on the 5th fault. A menu lane that had silently
    // stopped reporting altogether would land on 10.
    Exceptions::assertReportedCount(11);
});

/**
 * Slice 5a Task 8: the two rows purgeHandle()'s PDP lookup joins — a
 * storefront collection and one product item carrying a handle facet.
 * Hand-rolled (not ShopContentWriter) deliberately: this file's fixtures are
 * raw rows with fixed ids ('u-1', …) and no model layer at all, which is what
 * keeps it a purge-URL unit test rather than a second shop-storage test.
 */
function cfStorefront(object $db, string $collectionId, string $userId): void
{
    $db->table('content.collections')->insert([
        'id' => $collectionId, 'user_id' => $userId, 'label' => 'Store',
        'kind' => 'storefront', 'position' => 0, 'is_user_created' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function cfStorefrontProduct(object $db, string $collectionId, string $itemId, ?string $handle): void
{
    $db->table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId, 'source_id' => null, 'position' => 0,
    ]);
    $db->table('content.f_catalog')->insert([
        'item_id' => $itemId, 'source_id' => 'src-1', 'handle' => $handle, 'updated_at' => now(),
    ]);
}

it('purgeHandle also purges shop product detail pages + their shadows', function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $db = DB::connection('pgsql');
    $db->table('core.users')->insert([
        'id' => 'u-1', 'handle' => 'prodowner', 'handle_lc' => 'prodowner',
        'display_name' => 'Prod Owner', 'first_name' => 'Prod', 'account_type' => 'business',
        'status' => 'active', 'auth_user_id' => 'auth-1',
        'primary_email' => 'prodowner@example.com',
    ]);
    // Slice 5a Task 8: PDP handles come from the storefront collection's items
    // (content.f_catalog.handle), not site.shop_products.
    cfStorefront($db, 'col-1', 'u-1');
    cfStorefrontProduct($db, 'col-1', 'i-1', 'crest-pants');
    // No handle on the facet row → contributes nothing (and must not break the purge).
    cfStorefrontProduct($db, 'col-1', 'i-2', null);

    (new CloudflarePurgeService)->purgeHandle('prodowner');

    $files = cfRecordedFiles();
    $base = 'https://prodowner.partna.au';
    expect($files)->toContain("{$base}/products/crest-pants", "{$base}/_swr-shadow/products/crest-pants");
    // Root + sub-pages still there — the product URLs are additive.
    expect($files)->toContain("{$base}/", "{$base}/shop");
});

it('percent-encodes the handle and product handle before they land in a purge URL (SEC-1)', function () {
    // Handles are validated to [a-z0-9-] at write time, but purgeHandle() must not
    // assume that holds for every caller/legacy row — a stray space or slash must
    // be percent-encoded, not dropped straight into a URL. Product handles come
    // from scraped shop data (Shopify), which is even less trustworthy.
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $db = DB::connection('pgsql');
    $db->table('core.users')->insert([
        'id' => 'u-1', 'handle' => 'jane doe', 'handle_lc' => 'jane doe',
        'display_name' => 'Jane Doe', 'first_name' => 'Jane', 'account_type' => 'business',
        'status' => 'active', 'auth_user_id' => 'auth-1',
        'primary_email' => 'jane@example.com',
    ]);
    cfStorefront($db, 'col-1', 'u-1');
    cfStorefrontProduct($db, 'col-1', 'i-1', 'foo/bar');

    (new CloudflarePurgeService)->purgeHandle('jane doe');

    $files = cfRecordedFiles();
    expect($files)->toContain(
        'https://jane%20doe.partna.au/',
        'https://dev-api.partna.au/api/public/profiles/jane%20doe',
        'https://dev-api.partna.au/api/public/profiles/jane%20doe/integrations',
        'https://jane%20doe.partna.au/products/foo%2Fbar',
        'https://jane%20doe.partna.au/_swr-shadow/products/foo%2Fbar',
    );
    // One needle per call: toContain is variadic and `not` means "not ALL of
    // them", so a two-needle negation passes the moment either is absent.
    expect($files)->not->toContain('https://jane doe.partna.au/');
    expect($files)->not->toContain('https://jane%20doe.partna.au/products/foo/bar');
});

it('builds dish purge targets from BOTH menu lanes while both are live (slice 4)', function () {
    // The legacy menu wire still serves /menu/<legacy uuid> until slice 7
    // retires it, and the pool serves the content item id and its slug.
    // Purging one lane would leave the other's pages stale at the edge for the
    // full 24h TTL — the exact regression 5a's C2 closed on the shop side.
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    attachTestSchemas();
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', '');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $db = DB::connection('pgsql');
    $db->table('core.users')->insert([
        'id' => 'u-menu', 'handle' => 'dishy', 'handle_lc' => 'dishy',
        'display_name' => 'Dishy', 'first_name' => 'Dishy', 'account_type' => 'business',
        'status' => 'active', 'auth_user_id' => 'auth-menu', 'primary_email' => 'dishy@example.com',
    ]);
    $db->table('site.menus')->insert([
        'id' => 'm-1', 'user_id' => 'u-menu', 'fetch_status' => 'ok',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    $db->table('site.menu_items')->insert([
        'id' => 'legacy-dish-1', 'menu_id' => 'm-1', 'name' => 'Iced Latte', 'is_manual' => 0,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    $db->table('content.items')->insert([
        'id' => 'content-dish-1', 'user_id' => 'u-menu', 'kind' => 'menu_item',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        'first_seen_at' => now()->toDateTimeString(), 'last_seen_at' => now()->toDateTimeString(),
    ]);
    $db->table('content.item_slugs')->insert([
        'id' => 's-1', 'user_id' => 'u-menu', 'item_id' => 'content-dish-1',
        'slug' => 'iced-latte', 'is_current' => true, 'created_at' => now()->toDateTimeString(),
    ]);

    (new CloudflarePurgeService)->purgeHandle('dishy');

    expect(cfRecordedFiles())->toContain(
        'https://dishy.partna.au/menu/legacy-dish-1',
        'https://dishy.partna.au/menu/content-dish-1',
        'https://dishy.partna.au/menu/iced-latte',
    );
});

it('leaves a removed dish out of the purge set', function () {
    // A removed item's page is gone, and its slug has been freed for reuse —
    // purging it would spend one of the 150 slots on a URL that 404s.
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    attachTestSchemas();
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', '');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $db = DB::connection('pgsql');
    $db->table('core.users')->insert([
        'id' => 'u-gone', 'handle' => 'gone', 'handle_lc' => 'gone',
        'display_name' => 'Gone', 'first_name' => 'Gone', 'account_type' => 'business',
        'status' => 'active', 'auth_user_id' => 'auth-gone', 'primary_email' => 'gone@example.com',
    ]);
    $db->table('content.items')->insert([
        'id' => 'removed-dish', 'user_id' => 'u-gone', 'kind' => 'menu_item',
        'removed_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        'first_seen_at' => now()->toDateTimeString(), 'last_seen_at' => now()->toDateTimeString(),
    ]);

    (new CloudflarePurgeService)->purgeHandle('gone');

    expect(cfRecordedFiles())->not->toContain('https://gone.partna.au/menu/removed-dish');
});
