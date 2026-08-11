<?php

use App\Services\Platforms\InstagramConnectionSeeder;

/**
 * F4 (2026-08-10): a degraded actor run returned businessCategoryName "None",
 * which was stored and published — businessCategory is on
 * PublicIntegrationConnectionResource::ALLOWLIST['instagram'].
 */
it('normalises placeholder business categories to null', function (?string $raw) {
    $method = new ReflectionMethod(InstagramConnectionSeeder::class, 'categoryOrNull');

    expect($method->invoke(app(InstagramConnectionSeeder::class), $raw))->toBeNull();
})->with(['None', 'none', ' NONE ', 'null', 'N/A', '-', '', '   ', null]);

it('keeps a real business category verbatim', function () {
    $method = new ReflectionMethod(InstagramConnectionSeeder::class, 'categoryOrNull');
    $seeder = app(InstagramConnectionSeeder::class);

    expect($method->invoke($seeder, 'Hair Stylist'))->toBe('Hair Stylist')
        ->and($method->invoke($seeder, '  Tattoo & Piercing Shop  '))->toBe('Tattoo & Piercing Shop');
});
