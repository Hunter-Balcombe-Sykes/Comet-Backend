<?php

// Applied-schema lane. Slice 1b D6: Google's Places terms require crediting the
// photographer on display, and the connector's credits were being discarded.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

it('adds attribution to content.media_assets as nullable jsonb with no default', function () {
    $col = DB::connection('pgsql')->selectOne(<<<'SQL'
        SELECT data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = 'content' AND table_name = 'media_assets'
          AND column_name = 'attribution'
    SQL);

    expect($col)->not->toBeNull('content.media_assets.attribution is missing');
    expect($col->data_type)->toBe('jsonb');

    // NULL must stay reachable and must stay the default. Google supplies
    // attribution for only about half the photos it returns, so "absent" is a
    // real vendor state — a DB default would make "Google gave us nothing"
    // indistinguishable from "we never asked", and D6's known gap depends on
    // being able to tell those apart.
    expect($col->is_nullable)->toBe('YES');
    expect($col->column_default)->toBeNull();
})->group('postgres');
