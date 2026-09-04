<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\BandcampScraper;

// Get Started's setup-variant ConnectionSheet (owner, 2026-09-04): a manual
// connect made from there writes hidden, same visibility column and reveal
// mechanism (HiddenConnections, via SetupBatchApplier::acceptOne()) the
// automatic pre-scrape path already uses. See tests/Feature/Setup/
// SetupControllerTest.php for the accept-side reveal + the pass-row shape.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('a single-selection connect with hidden=true writes the row hidden', function () {
    $pro = createTenant('hidden-single');

    actingAsUser($pro)->postJson('/api/platforms/facebook/connect', [
        'username' => 'somepage',
        'hidden' => true,
    ])->assertOk();

    $connection = IntegrationConnection::query()
        ->where('user_id', $pro->id)->where('platform', 'facebook')->firstOrFail();
    expect($connection->visibility)->toBe('hidden');
});

it('an ordinary connect (no hidden flag) still writes visible', function () {
    $pro = createTenant('hidden-single-default');

    actingAsUser($pro)->postJson('/api/platforms/facebook/connect', [
        'username' => 'anotherpage',
    ])->assertOk();

    $connection = IntegrationConnection::query()
        ->where('user_id', $pro->id)->where('platform', 'facebook')->firstOrFail();
    expect($connection->visibility)->toBe('visible');
});

it('a multi-account connect with hidden=true writes the account row hidden', function () {
    $pro = createTenant('hidden-account');

    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://artist.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'Mock Artist', 'thumbnail' => null, 'items' => [
            ['itemId' => 'album-1', 'name' => 'A Record', 'thumbnail' => null, 'link' => 'https://artist.bandcamp.com/album/a'],
        ]]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn ($tiles) => $tiles);
    });

    actingAsUser($pro)->postJson('/api/platforms/bandcamp/connect', [
        'url' => 'https://artist.bandcamp.com/music',
        'hidden' => true,
    ])->assertOk();

    $connection = IntegrationConnection::query()
        ->where('user_id', $pro->id)->where('platform', 'bandcamp')->firstOrFail();
    expect($connection->visibility)->toBe('hidden');
});
