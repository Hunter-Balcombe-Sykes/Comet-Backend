<?php

use App\Services\Content\ServiceCollections;

// PGR-14: ServiceCollections::normalizeRow() coerces PDO_PGSQL's driver-shaped
// scalars (boolean columns as the strings "t"/"f", bigint/integer columns as
// numeric strings) to native PHP bool/int, because the wire mapping compares
// `is_user_created === false`. The rest of this suite runs against the SQLite
// stand-in, which already hands back native types — so nothing exercises this
// coercion without reflection constructing a driver-shaped row by hand.
// normalizeRow() is private on purpose (Fix round 1, Finding 1's docblock: the
// whole point is that no caller reads the raw row) — reflection is used here
// instead of widening its visibility, matching the pattern already used
// throughout tests/ (e.g. tests/Unit/Platforms/MenuItemNameNormalizationTest.php).
function invokeServiceCollectionsNormalizeRow(stdClass $row): stdClass
{
    $method = new ReflectionMethod(ServiceCollections::class, 'normalizeRow');
    $method->setAccessible(true);

    return $method->invoke(new ServiceCollections, $row);
}

it('coerces the PDO_PGSQL "t" string to native bool true', function () {
    $row = invokeServiceCollectionsNormalizeRow((object) [
        'is_user_created' => 't',
        'item_count' => '0',
        'position' => '0',
    ]);

    expect($row->is_user_created)->toBeTrue();
});

it('coerces the PDO_PGSQL "f" string to native bool false — the exact bug this guards against', function () {
    // A broken implementation that returns true for any non-empty string
    // (or that does `(bool) 'f'`, which is also true) would pass a "t"-only
    // test and misclassify every scraper-owned category as owner-editable.
    $row = invokeServiceCollectionsNormalizeRow((object) [
        'is_user_created' => 'f',
        'item_count' => '0',
        'position' => '0',
    ]);

    expect($row->is_user_created)->toBeFalse();
});

it('coerces PDO_PGSQL numeric-string item_count/position to native int', function () {
    $row = invokeServiceCollectionsNormalizeRow((object) [
        'is_user_created' => 'f',
        'item_count' => '7',
        'position' => '2',
    ]);

    expect($row->item_count)->toBeInt();
    expect($row->item_count)->toBe(7);
    expect($row->position)->toBeInt();
    expect($row->position)->toBe(2);
});

it('coerces the numeric-string "0" to native int 0, not the truthy string "0"', function () {
    // toBeTruthy() would pass on the PHP-falsy-but-non-empty string '0' just
    // as easily as on an int 0 — toBe(0) with a strict int check is the only
    // assertion that actually proves the cast happened.
    $row = invokeServiceCollectionsNormalizeRow((object) [
        'is_user_created' => 't',
        'item_count' => '0',
        'position' => '0',
    ]);

    expect($row->item_count)->toBeInt();
    expect($row->item_count)->toBe(0);
    expect($row->position)->toBeInt();
    expect($row->position)->toBe(0);
});
