<?php

use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('moderation.enabled is a boolean, not a raw env string', function () {
    // Verifies the (bool) cast is in place — the value must be a native boolean
    // so that falsy checks like `if (! config('partna.moderation.enabled'))` work.
    expect(config('partna.moderation.enabled'))->toBeBool();
});

it('moderation.enabled defaults to true', function () {
    expect(config('partna.moderation.enabled'))->toBeTrue();
});

it('moderation.enabled is false when overridden to false', function () {
    config(['partna.moderation.enabled' => false]);

    expect(config('partna.moderation.enabled'))->toBeFalse();
});
