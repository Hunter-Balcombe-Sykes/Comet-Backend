<?php

// Applied-schema lane. Ties SectorProvenance's rank table to the real CHECK, so
// widening the constraint forces a rank decision instead of a silent rank-0.

use App\Services\Profile\SectorProvenance;
use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

it('ranks exactly the sources users_sector_source_check permits', function () {
    $def = DB::connection('pgsql')->selectOne(
        "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'users_sector_source_check'"
    );

    expect($def)->not->toBeNull('users_sector_source_check is missing');

    preg_match_all("/'([a-z-]+)'::text/", $def->def, $matches);
    $allowed = array_values(array_unique($matches[1]));

    $ranked = array_keys((new ReflectionClass(SectorProvenance::class))->getConstant('RANKS'));

    sort($allowed);
    sort($ranked);

    expect($ranked)->toBe($allowed);
})->group('postgres');
