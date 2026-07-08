<?php

/**
 * Unit tests for AppleSearch::fetchGenre (#76 Part B) — the keyless iTunes
 * artist-genre lookup that feeds the stored Apple Music payload →
 * IdentityEvidence::musicGenre() → MusicGenreFactor. Mocks SafeUrlFetcher so no
 * live iTunes call is made. Covers the URL fast-path, the bare-name search path,
 * lower-casing, and the null (abstain-preserving) misses.
 */

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\AppleSearch;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

afterEach(function () {
    Mockery::close();
});

/** iTunes response envelope for the SafeUrlFetcher::tryFetch contract. */
function itunesResponse(array $json, int $status = 200): array
{
    return [
        'status' => $status,
        'body' => json_encode($json),
        'finalUrl' => 'https://itunes.apple.com/lookup',
        'contentType' => 'application/json',
        'etag' => null,
        'lastModified' => null,
    ];
}

beforeEach(function () {
    Cache::flush(); // the itunes() helper caches successful lookups by path
});

it('returns the artist primary genre, lower-cased, from a music.apple.com URL', function () {
    // URL input → resolveArtistId short-circuits the search; only the lookup fires.
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->once()
        ->andReturn(itunesResponse([
            'resultCount' => 1,
            'results' => [[
                'wrapperType' => 'artist',
                'artistId' => 5468295,
                'artistName' => 'Daft Punk',
                'primaryGenreName' => 'Dance',
            ]],
        ]));

    $genre = (new AppleSearch($fetcher))->fetchGenre('https://music.apple.com/us/artist/daft-punk/5468295');

    expect($genre)->toBe('dance');
});

it('resolves a bare artist name via search then reads the genre', function () {
    // Bare name → first fetch = /search (→ artistId), second = /lookup (→ genre).
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->twice()
        ->andReturn(
            itunesResponse([ // search
                'resultCount' => 1,
                'results' => [['artistId' => 12345, 'artistName' => 'Some Artist']],
            ]),
            itunesResponse([ // lookup
                'resultCount' => 1,
                'results' => [['wrapperType' => 'artist', 'artistId' => 12345, 'primaryGenreName' => 'Hip-Hop/Rap']],
            ]),
        );

    $genre = (new AppleSearch($fetcher))->fetchGenre('Some Artist');

    expect($genre)->toBe('hip-hop/rap');
});

it('returns null when the artist cannot be resolved', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->andReturn(itunesResponse(['resultCount' => 0, 'results' => []])); // empty search

    expect((new AppleSearch($fetcher))->fetchGenre('Nonexistent Artist'))->toBeNull();
});

it('returns null when the artist record carries no genre', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->once()
        ->andReturn(itunesResponse([
            'resultCount' => 1,
            'results' => [['wrapperType' => 'artist', 'artistId' => 999]], // no primaryGenreName
        ]));

    expect((new AppleSearch($fetcher))->fetchGenre('https://music.apple.com/us/artist/x/999'))->toBeNull();
});

it('returns null (never throws) when the upstream fetch fails', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn(null); // network miss

    expect((new AppleSearch($fetcher))->fetchGenre('https://music.apple.com/us/artist/x/999'))->toBeNull();
});
