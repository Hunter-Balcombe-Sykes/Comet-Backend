<?php

use App\Ingest\Connectors\FreshaConnector;

/**
 * T11 (2026-08-27 unclaimed-signup quality plan): Fresha service names arrive
 * in whatever casing the salon typed — the same service appears as "Refresh"
 * in one scrape list and "REFRESH" in another, and SHOUTING names serve on
 * the public services page. Names with NO casing signal (all-caps or
 * all-lower) are normalised to Title Case at ingest write; a mixed-case name
 * is the merchant's own deliberate casing and passes through untouched. The
 * sitepage's uppercase STYLING stays a design-kit concern (CSS), not storage.
 */
function freshaMappedName(string $rawName): ?string
{
    $item = [
        'name' => $rawName,
        'caption' => '45min',
        'description' => null,
        'price' => ['formatted' => 'A$90'],
        'primaryAction' => ['id' => '{"catalogId":"s:123"}'],
    ];

    $mapped = (new ReflectionMethod(FreshaConnector::class, 'mapServiceItem'))
        ->invoke(new FreshaConnector, $item, 'SERVICES', 'c1');

    return $mapped['name'] ?? null;
}

it('title-cases an all-caps service name', function () {
    expect(freshaMappedName('REFRESH'))->toBe('Refresh');
    expect(freshaMappedName('HAIRCUT & BEARD TRIM'))->toBe('Haircut & Beard Trim');
});

it('title-cases an all-lower service name', function () {
    expect(freshaMappedName('beard trim'))->toBe('Beard Trim');
});

it('leaves deliberate mixed-case names untouched', function () {
    expect(freshaMappedName('Kids Cut (Under 12)'))->toBe('Kids Cut (Under 12)');
    expect(freshaMappedName('Skin Fade & Beard + Color enhancement'))->toBe('Skin Fade & Beard + Color enhancement');
});
