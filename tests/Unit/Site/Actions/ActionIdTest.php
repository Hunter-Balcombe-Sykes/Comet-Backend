<?php

use App\Site\Actions\ActionId;

it('accepts the four kinds and rejects legacy shapes', function () {
    expect(ActionId::isValid('page:services'))->toBeTrue()
        ->and(ActionId::isValid('platform:instagram'))->toBeTrue()
        ->and(ActionId::isValid('item:0b1e6b2e-2f6f-4c0e-9e4a-1d3a2c7e9f10'))->toBeTrue()
        ->and(ActionId::isValid('category:menu-cat-1'))->toBeTrue()
        ->and(ActionId::isValid('instagram'))->toBeFalse()
        ->and(ActionId::isValid('ordering:abc'))->toBeFalse()
        ->and(ActionId::isValid('custom:https://x'))->toBeFalse()
        ->and(ActionId::isValid('page:'))->toBeFalse()
        ->and(ActionId::isValid('page:'.str_repeat('a', 161)))->toBeFalse();
});

it('reports the kind prefix', function () {
    expect(ActionId::kind('platform:tiktok'))->toBe('platform')
        ->and(ActionId::kind('nope'))->toBeNull();
});
