<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\ImportRun;
use App\Routing\Importers\WebsiteImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Uses https://example.com throughout: SafeUrlFetcher::assertSafe() does a
// REAL DNS lookup before the fetch, which runs even under Http::fake(), so a
// non-resolving .example domain fails the SSRF check before the fake is
// consulted. example.com is one of the few reserved domains IANA keeps
// resolving. (Same reasoning as ScanPreviousWebsiteContentJobTest.)

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function websitePage(string $html): void
{
    Http::fake(['*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);
}

it('routes every link it finds through the same pipeline a paste uses', function () {
    $pro = createTenant('importer-basic');
    websitePage('<html><body>
        <a href="https://www.instagram.com/someshop">Instagram</a>
        <a href="https://x.com/someshop">X</a>
        <a href="https://joesplumbing.com.au/">A supplier</a>
    </body></html>');

    $result = app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    expect($result['outcome'])->toBe('ok')
        ->and($result['observations'])->toBe(3);

    // Every link is observed and explained, whether or not it connected.
    expect(DB::table('routing.link_observations')->where('source', 'website_import')->count())->toBe(3);
});

it('counts one decision per distinct link, not per occurrence', function () {
    // A site linking its Instagram in the header and the footer wants one
    // connection, not two attempts at the same one.
    $pro = createTenant('importer-dupes');
    websitePage('<html><body>
        <a href="https://www.instagram.com/someshop">Header</a>
        <a href="https://www.instagram.com/someshop">Footer</a>
        <a href="https://WWW.INSTAGRAM.COM/someshop">Shouty</a>
    </body></html>');

    $result = app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    // All three are the same link: the header/footer pair are byte-identical
    // and the shouty one differs only in case, so exactly ONE decision is
    // made — and therefore exactly one intent, not three.
    expect($result['observations'])->toBe(1)
        ->and(DB::table('routing.source_intents')->where('user_id', $pro->id)->count())->toBe(1);
});

it('connects a suggest-band link it found on a page', function () {
    // Owner ruling 2026-08-18: harvest origins auto-apply the suggest band.
    // The indirect penalty still applies to the score, but a harvested link
    // clearing the suggest threshold with a clean margin now connects instead
    // of waiting in the inbox. (Pre-ruling this test pinned the opposite —
    // 0 connections, state 'proposed'.)
    $pro = createTenant('importer-suggests');
    websitePage('<html><body><a href="https://www.instagram.com/someshop">IG</a></body></html>');

    app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(1)
        ->and(DB::table('routing.source_intents')->where('user_id', $pro->id)->value('state'))->toBe('applied');
});

it('marks harvested links as found, not typed', function () {
    // The placement policy demands more confidence from something found
    // incidentally than from something a person deliberately pasted.
    $pro = createTenant('importer-origin');
    websitePage('<html><body><a href="https://www.instagram.com/someshop">IG</a></body></html>');

    app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    expect(DB::table('routing.link_observations')->value('source'))->toBe('website_import');
});

it('records the run so a paid import can always be accounted for', function () {
    $pro = createTenant('importer-run');
    websitePage('<html><body><a href="https://x.com/someshop">X</a></body></html>');

    app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    $run = DB::table('routing.import_runs')->where('user_id', $pro->id)->first();
    expect($run->kind)->toBe('website')
        ->and($run->outcome)->toBe('ok')
        ->and($run->source_url)->toBe('https://example.com/')
        ->and($run->finished_at)->not->toBeNull();
});

it('reports an unreachable site as unavailable rather than as a site with no links', function () {
    $pro = createTenant('importer-down');
    Http::fake(['*' => Http::response('', 503)]);

    $result = app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    expect($result['outcome'])->toBe('unavailable')
        ->and(DB::table('routing.import_runs')->where('user_id', $pro->id)->value('error_class'))->toBe('fetch_failed');
});

it('refuses to re-run past the daily limit', function () {
    // Importers can trigger paid downstream effects, so an unlimited re-run
    // button is a way to spend money by accident.
    $pro = createTenant('importer-cooldown');
    websitePage('<html><body><a href="https://x.com/someshop">X</a></body></html>');

    for ($i = 0; $i < ImportRun::dailyLimit(); $i++) {
        expect(app(WebsiteImporter::class)->import($pro, 'https://example.com/')['outcome'])->not->toBe('cooldown');
    }

    expect(app(WebsiteImporter::class)->import($pro, 'https://example.com/')['outcome'])->toBe('cooldown')
        ->and(DB::table('routing.import_runs')->where('user_id', $pro->id)->count())->toBe(ImportRun::dailyLimit());
});

it('never creates a connection for the scraped page itself', function () {
    // The website is the SOURCE of links, not a platform to connect.
    $pro = createTenant('importer-nopage');
    websitePage('<html><body><a href="https://x.com/someshop">X</a></body></html>');

    app(WebsiteImporter::class)->import($pro, 'https://example.com/');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->pluck('resource_id')->all())
        ->not->toContain('https://example.com/');
});

it('redacts a secret-shaped param from the recorded source_url (#SEC-1)', function () {
    $pro = createTenant('importer-secret-url');
    websitePage('<html><body><a href="https://x.com/someshop">X</a></body></html>');

    app(WebsiteImporter::class)->import($pro, 'https://example.com/?api_key=eyJhbGciOiJIUzI1NiJ9.aaa.bbb');

    $run = DB::table('routing.import_runs')->where('user_id', $pro->id)->first();
    expect($run->source_url)->toContain('api_key=[redacted]')
        ->and($run->source_url)->not->toContain('eyJhbGci');
});
