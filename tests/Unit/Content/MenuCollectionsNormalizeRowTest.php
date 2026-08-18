<?php

use App\Services\Content\MenuCollections;

// PGR-14, mirroring ServiceCollectionsNormalizeRowTest.php: MenuCollections'
// normalizeRow() does the identical PDO_PGSQL coercion (bool as "t"/"f",
// integer as a numeric string) for the identical reason — the editable-category
// gate (EDITABLE_SOURCES et al.) compares `is_user_created === false`. See that
// file's header for why reflection is used instead of widening visibility.
function invokeMenuCollectionsNormalizeRow(stdClass $row): stdClass
{
    $method = new ReflectionMethod(MenuCollections::class, 'normalizeRow');
    $method->setAccessible(true);

    return $method->invoke(new MenuCollections, $row);
}

it('coerces the PDO_PGSQL "t" string to native bool true', function () {
    $row = invokeMenuCollectionsNormalizeRow((object) [
        'is_user_created' => 't',
        'position' => '0',
    ]);

    expect($row->is_user_created)->toBeTrue();
});

it('coerces the PDO_PGSQL "f" string to native bool false — the exact bug this guards against', function () {
    // A broken implementation returning true for any non-empty string (or
    // `(bool) 'f'`, also true) would pass a "t"-only test and misclassify
    // every scraper-owned menu category as owner-editable on real Postgres.
    $row = invokeMenuCollectionsNormalizeRow((object) [
        'is_user_created' => 'f',
        'position' => '0',
    ]);

    expect($row->is_user_created)->toBeFalse();
});

it('coerces PDO_PGSQL numeric-string position to native int', function () {
    $row = invokeMenuCollectionsNormalizeRow((object) [
        'is_user_created' => 'f',
        'position' => '3',
    ]);

    expect($row->position)->toBeInt();
    expect($row->position)->toBe(3);
});

it('coerces the numeric-string "0" to native int 0, not the truthy string "0"', function () {
    // toBeTruthy() would pass on the PHP-falsy-but-non-empty string '0' just
    // as easily as on an int 0 — toBe(0) with a strict int check is the only
    // assertion that actually proves the cast happened.
    $row = invokeMenuCollectionsNormalizeRow((object) [
        'is_user_created' => 't',
        'position' => '0',
    ]);

    expect($row->position)->toBeInt();
    expect($row->position)->toBe(0);
});
