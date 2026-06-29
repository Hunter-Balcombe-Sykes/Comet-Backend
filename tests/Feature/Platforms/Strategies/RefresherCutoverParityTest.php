<?php

use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\YoutubeScraper;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('success path persists the new payload and resets failure state (status=ok)', function () {
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andReturn([
        ['videoId' => 'v9', 'name' => 'New', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
    ]));

    $row = gmSeed(gmUser('rc1'), 'youtube', ['handle' => 'chan', 'consecutive' => 0]);
    $row->update(['consecutive_failures' => 3]);

    app(PlatformRefresher::class)->refresh($row->fresh());

    $row->refresh();
    expect($row->last_refresh_status)->toBe('ok');
    expect($row->last_refresh_error)->toBeNull();
    expect($row->consecutive_failures)->toBe(0);
    expect($row->payload['name'])->toBe('New');
    expect($row->last_refreshed_at)->not->toBeNull();
});

it('shape error logs bad_shape, sets status=error, increments consecutive_failures', function () {
    $row = gmSeed(gmUser('rc2'), 'youtube', ['name' => 'no handle']);

    app(PlatformRefresher::class)->refresh($row);

    $row->refresh();
    expect($row->last_refresh_status)->toBe('error');
    expect($row->last_refresh_error)->toBe('missing_key: handle');
    expect($row->consecutive_failures)->toBe(1);
});

it('unavailable miss sets status=unavailable, increments, preserves last-known payload', function () {
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andReturn([]));

    $row = gmSeed(gmUser('rc3'), 'youtube', ['handle' => 'chan', 'name' => 'Kept']);

    app(PlatformRefresher::class)->refresh($row);

    $row->refresh();
    expect($row->last_refresh_status)->toBe('unavailable');
    expect($row->consecutive_failures)->toBe(1);
    expect($row->payload['name'])->toBe('Kept'); // last-known-good preserved
});

it('an unregistered/non-refreshable platform is an unsupported_platform error', function () {
    // 'instagram' is registered but NOT refreshable → mirrors the old default arm.
    $row = gmSeed(gmUser('rc4'), 'instagram', ['username' => 'ig']);

    app(PlatformRefresher::class)->refresh($row);

    $row->refresh();
    expect($row->last_refresh_status)->toBe('error');
    expect($row->last_refresh_error)->toBe('unsupported_platform');
    expect($row->consecutive_failures)->toBe(1);
});

it('a generic (non-Fetch*) exception bubbles out of refresh() — it is NOT swallowed as unavailable', function () {
    $this->mock(YoutubeScraper::class, fn ($m) => $m->shouldReceive('fetchRecentVideos')->andThrow(new RuntimeException('scraper boom')));

    $row = gmSeed(gmUser('rc5'), 'youtube', ['handle' => 'chan']);
    $row->update(['last_refresh_status' => 'ok', 'consecutive_failures' => 0]);

    expect(fn () => app(PlatformRefresher::class)->refresh($row->fresh()))->toThrow(RuntimeException::class, 'scraper boom');

    // Row untouched — refresh() threw before persisting anything.
    $row->refresh();
    expect($row->last_refresh_status)->toBe('ok');
    expect($row->consecutive_failures)->toBe(0);
});
