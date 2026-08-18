<?php

// tests/Unit/Support/RecordedLoaderTest.php

use Tests\Support\Fixtures\Recorded;

it('resolves a relative fixture path under tests/fixtures/recorded', function () {
    expect(Recorded::path('shop/bluelane-store-api.json'))
        ->toBe(dirname(__DIR__, 2).'/fixtures/recorded/shop/bluelane-store-api.json');
});

it('reads a recorded JSON fixture as an array', function () {
    $data = Recorded::json('shop/bluelane-store-api.json');
    expect($data)->toBeArray()->not->toBeEmpty();
});

it('reads a recorded HTML fixture as a string', function () {
    expect(Recorded::html('shop/bluelane-homepage-head.html'))->toContain('<');
});

it('throws a clear error for a missing fixture', function () {
    Recorded::raw('shop/does-not-exist.html');
})->throws(RuntimeException::class, 'Recorded fixture missing: shop/does-not-exist.html');
