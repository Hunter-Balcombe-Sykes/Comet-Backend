<?php

use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRegistry;

it('registers, looks up, and lists descriptors', function () {
    $r = new PlatformRegistry;
    $r->register(PlatformDescriptor::linkOnly('linkedin', 'LinkedIn', 'R'))
        ->register(PlatformDescriptor::make('spotify')->label('Spotify')->resource('R')->refreshable(true));

    expect($r->has('linkedin'))->toBeTrue();
    expect($r->get('spotify')->getLabel())->toBe('Spotify');
    expect($r->get('missing'))->toBeNull();
    expect($r->keys())->toContain('linkedin', 'spotify');
});

it('returns only refreshable descriptors from refreshable()', function () {
    $r = new PlatformRegistry;
    $r->register(PlatformDescriptor::linkOnly('linkedin', 'LinkedIn', 'R')) // not refreshable
        ->register(PlatformDescriptor::make('spotify')->label('Spotify')->resource('R')->refreshable(true));

    expect(array_keys($r->refreshable()))->toBe(['spotify']);
});
