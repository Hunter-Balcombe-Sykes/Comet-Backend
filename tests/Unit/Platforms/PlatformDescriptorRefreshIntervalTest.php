<?php

use App\Services\Platforms\Registry\PlatformDescriptor;

it('returns null refreshInterval when none is set', function () {
    expect(PlatformDescriptor::make('x')->label('X')->refreshInterval())->toBeNull();
});

it('stores a per-platform refresh interval via refreshEvery', function () {
    $d = PlatformDescriptor::make('x')->label('X')->refreshEvery(3600);
    expect($d->refreshInterval())->toBe(3600);
});
