<?php

// T24 (owner, 2026-08-28): the four URL-intelligence fixes, each pinned to
// the live failure that motivated it (issues 17/18/19/21).

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\LinkCardScraper;
use App\Services\Platforms\LinkInBioInlinePayloadReader;
use App\Services\Platforms\LinkSnapshotQuality;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

// ── Issue 21: shortlink resolution survives a bot-blocked destination ───────

it('tryResolveFinalUrl keeps the resolved destination when the terminal host refuses the GET', function () {
    Http::fake([
        'spoti.fi/*' => Http::response('', 302, ['Location' => 'https://open.spotify.com/album/abc123']),
        'open.spotify.com/*' => fn () => throw new ConnectionException('bot-blocked'),
    ]);

    $final = app(SafeUrlFetcher::class)->tryResolveFinalUrl('https://spoti.fi/xyz');

    expect($final)->toBe('https://open.spotify.com/album/abc123');
});

it('tryResolveFinalUrl returns the terminal url even on a 403 answer', function () {
    Http::fake([
        'spoti.fi/*' => Http::response('', 301, ['Location' => 'https://open.spotify.com/album/abc123']),
        'open.spotify.com/*' => Http::response('blocked', 403),
    ]);

    expect(app(SafeUrlFetcher::class)->tryResolveFinalUrl('https://spoti.fi/xyz'))
        ->toBe('https://open.spotify.com/album/abc123');
});

it('tryResolveFinalUrl returns null when the chain never moves', function () {
    Http::fake([
        'bit.ly/*' => fn () => throw new ConnectionException('dead'),
    ]);

    expect(app(SafeUrlFetcher::class)->tryResolveFinalUrl('https://bit.ly/dead'))->toBeNull();
});

// ── Issue 19: the Linktree socialLinks icon row survives a missing tile list ─

it('reads the socialLinks row even when the links array is absent', function () {
    $next = json_encode(['props' => ['pageProps' => [
        'socialLinks' => [
            ['url' => 'https://benbohmer.bandcamp.com'],
            ['url' => 'https://www.instagram.com/benbohmer'],
        ],
    ]]]);
    $body = '<script id="__NEXT_DATA__" type="application/json">'.$next.'</script>';

    $urls = app(LinkInBioInlinePayloadReader::class)->read('https://linktr.ee/benbohmer', $body);

    expect($urls)->toBe(['https://benbohmer.bandcamp.com', 'https://www.instagram.com/benbohmer']);
});

// ── Issue 18: catalog-shaped store paths are root-equivalent ────────────────

it('treats /collections paths as root-equivalent and /pages as the FI-10 question', function () {
    $shape = function (string $url): bool {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path !== '' && preg_match('#^(collections(/[^/]+)?|shop|store)/?$#i', $path) !== 1;
    };

    // drsleek's live link: catalog view → NOT a deep page any more.
    expect($shape('https://drsleek.com.au/collections/all'))->toBeFalse()
        ->and($shape('https://drsleek.com.au/shop'))->toBeFalse()
        // FI-10's live incident shape stays a question.
        ->and($shape('https://4barbers.com.au/pages/matsui-discount'))->toBeTrue()
        ->and($shape('https://store.example/products/one-item'))->toBeTrue()
        ->and($shape('https://store.example/'))->toBeFalse();
});

// ── Issue 17: the snapshot quality gate ─────────────────────────────────────

it('rejects search/listing/noindex/markdown identities', function () {
    // playlunch's live junk shapes.
    expect(LinkSnapshotQuality::acceptable('https://tickets.example/search?q=playlunch', 'Tickets', null))->toBeFalse()
        ->and(LinkSnapshotQuality::acceptable('https://tickets.example/de/suche/playlunch', 'Karten', null))->toBeFalse()
        ->and(LinkSnapshotQuality::acceptable('https://tickets.example/events', '128 results for Playlunch', null))->toBeFalse()
        ->and(LinkSnapshotQuality::acceptable('https://tickets.example/events', '**PLAYLUNCH** live', null))->toBeFalse()
        ->and(LinkSnapshotQuality::acceptable('https://a.example/page', 'A Page', 'noindex, nofollow'))->toBeFalse()
        // Honest pages pass.
        ->and(LinkSnapshotQuality::acceptable('https://playlunch.band/', 'Playlunch — band', 'index, follow'))->toBeTrue()
        ->and(LinkSnapshotQuality::acceptable('https://shop.example/collections/all', 'Shop All', null))->toBeTrue();
});

it('downgrades a junk snapshot to the minimal host card, keeping the link', function () {
    Http::fake([
        'www.oztix.com.au/*' => Http::response(
            '<html><head><title>128 results for Playlunch | Search</title>'
            .'<meta property="og:title" content="128 results for Playlunch | Search">'
            .'</head><body></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $card = app(LinkCardScraper::class)->snapshot('https://www.oztix.com.au/search?q=playlunch');

    expect($card)->not->toBeNull()
        ->and($card['name'])->toBe('oztix.com.au')
        ->and($card['description'])->toBeNull();
});
