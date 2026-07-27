<?php

use App\Routing\Importers\LinkInBioImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Uses https://example.com as the bio page: SafeUrlFetcher::assertSafe() does
// a real DNS lookup before the fetch even under Http::fake(), so a
// non-resolving domain fails the SSRF check before the fake is consulted.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function bioPage(string $html): void
{
    Http::fake(['*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);
}

it('skips the bio platform\'s own chrome and keeps only the owner\'s links', function () {
    // Measured on a real Linktree page: 58 anchors, 55 of them the platform's
    // own navigation. Without this rule an unroll turns a user's page into a
    // directory of someone else's marketing.
    bioPage('<html><body>
        <a href="https://example.com/pricing">Pricing</a>
        <a href="https://example.com/blog">Blog</a>
        <a href="https://example.com/help">Help centre</a>
        <a href="https://www.instagram.com/theartist">Instagram</a>
        <a href="https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4">Spotify</a>
    </body></html>');

    $result = app(LinkInBioImporter::class)->import(createTenant('bio-chrome'), 'https://example.com/theartist');

    expect($result['skipped_chrome'])->toBe(3)
        ->and($result['observations'])->toBe(2);
});

it('routes the owner\'s links through the same pipeline everything else uses', function () {
    $pro = createTenant('bio-routes');
    bioPage('<html><body>
        <a href="https://www.instagram.com/theartist">IG</a>
        <a href="https://open.spotify.com/artist/3TVXtAsR1Inumwj472S9r4">Spotify</a>
    </body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/theartist');

    $observations = DB::table('routing.link_observations')->where('source', 'link_in_bio')->get();
    expect($observations)->toHaveCount(2)
        ->and($observations->pluck('surface_key')->all())
        ->toContain('instagram.profile', 'spotify.player');
});

it('treats an unreachable bio page as unavailable, not as a page with no links', function () {
    Http::fake(['*' => Http::response('', 404)]);

    $result = app(LinkInBioImporter::class)->import(createTenant('bio-down'), 'https://example.com/gone');

    expect($result['outcome'])->toBe('unavailable')
        ->and($result['observations'])->toBe(0);
});

it('counts one decision per distinct link', function () {
    $pro = createTenant('bio-dupes');
    bioPage('<html><body>
        <a href="https://www.instagram.com/theartist">Top</a>
        <a href="https://WWW.INSTAGRAM.COM/theartist">Bottom</a>
    </body></html>');

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/theartist');

    expect($result['observations'])->toBe(1);
});

it('records the run under its own kind so imports stay accountable', function () {
    $pro = createTenant('bio-run');
    bioPage('<html><body><a href="https://x.com/theartist">X</a></body></html>');

    app(LinkInBioImporter::class)->import($pro, 'https://example.com/theartist');

    $run = DB::table('routing.import_runs')->where('user_id', $pro->id)->first();
    expect($run->kind)->toBe('link_in_bio')
        ->and($run->outcome)->toBe('ok');
});

it('never leaves a bio page with only chrome looking like a successful haul', function () {
    // A page where everything was chrome is a real outcome worth seeing: it
    // means the unroll found the user nothing.
    $pro = createTenant('bio-allchrome');
    bioPage('<html><body>
        <a href="https://example.com/pricing">Pricing</a>
        <a href="https://example.com/blog">Blog</a>
    </body></html>');

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/theartist');

    expect($result['outcome'])->toBe('ok')
        ->and($result['observations'])->toBe(0)
        ->and($result['skipped_chrome'])->toBe(2)
        ->and(DB::table('routing.source_intents')->where('user_id', $pro->id)->count())->toBe(0);
});
