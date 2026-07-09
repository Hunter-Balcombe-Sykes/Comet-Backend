<?php

use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;

// gmUser()/gmSeed() are loaded globally by tests/Pest.php:72 (it require_once's
// Feature/Platforms/GoldenMaster/golden_master_helpers.php for the whole suite),
// so no local require is needed — same as IntegrationContractGoldenMasterTest.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

// The spotify endpoint builder used by both the refresher (PlatformRefresher.php:61)
// and the strategy attached in the service provider — kept identical here.
function spotifyEndpoint(): Closure
{
    return fn (string $link) => 'https://open.spotify.com/oembed?url='.rawurlencode($link);
}

it('OEmbedFetch(spotify) produces the same success payload as the refresher', function () {
    $this->mock(OEmbedService::class, function ($m) {
        $m->shouldReceive('resolve')->andReturn([
            'name' => 'Fresh Name', 'thumbnail' => 'https://i.scdn.co/new.jpg',
            'embedUrl' => 'https://open.spotify.com/embed/artist/abc',
        ]);
    });

    $stored = [
        'url' => 'https://open.spotify.com/artist/abc', 'name' => 'Old', 'thumbnail' => 'https://old.jpg',
        'embedUrl' => 'https://open.spotify.com/embed/artist/abc', 'link' => 'https://open.spotify.com/artist/abc',
    ];

    // Refresher path — the behaviour we must preserve.
    $refresherRow = gmSeed(gmUser('gmspf1'), 'spotify', $stored);
    app(PlatformRefresher::class)->refresh($refresherRow);

    // Strategy path — must equal it.
    $strategyRow = gmSeed(gmUser('gmspf2'), 'spotify', $stored);
    $result = (new OEmbedFetch(app(OEmbedService::class), spotifyEndpoint(), 'spotify'))->fetch($strategyRow);

    expect($result)->toEqual($refresherRow->fresh()->payload);
});

it('OEmbedFetch throws FetchShapeException where the refresher records status=error (missing link)', function () {
    $row = gmSeed(gmUser('gmspf3'), 'spotify', ['name' => 'No link here']);

    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('error');

    $strategyRow = gmSeed(gmUser('gmspf4'), 'spotify', ['name' => 'No link here']);
    expect(fn () => (new OEmbedFetch(app(OEmbedService::class), spotifyEndpoint(), 'spotify'))->fetch($strategyRow))
        ->toThrow(FetchShapeException::class);
});

it('OEmbedFetch throws FetchUnavailableException where the refresher records status=unavailable (oEmbed miss)', function () {
    $this->mock(OEmbedService::class, fn ($m) => $m->shouldReceive('resolve')->andReturnNull());

    $stored = ['url' => 'https://open.spotify.com/artist/abc', 'link' => 'https://open.spotify.com/artist/abc'];

    $row = gmSeed(gmUser('gmspf5'), 'spotify', $stored);
    app(PlatformRefresher::class)->refresh($row);
    expect($row->fresh()->last_refresh_status)->toBe('unavailable');

    $strategyRow = gmSeed(gmUser('gmspf6'), 'spotify', $stored);
    expect(fn () => (new OEmbedFetch(app(OEmbedService::class), spotifyEndpoint(), 'spotify'))->fetch($strategyRow))
        ->toThrow(FetchUnavailableException::class);
});
