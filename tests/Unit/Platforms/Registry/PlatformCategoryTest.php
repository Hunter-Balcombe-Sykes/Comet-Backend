<?php

use App\Services\Platforms\Registry\PlatformCategory;

it('exposes the integration categories and resolves from a string key', function () {
    expect(PlatformCategory::Booking->value)->toBe('booking');
    expect(PlatformCategory::fromKey('shop'))->toBe(PlatformCategory::Shop);
});
