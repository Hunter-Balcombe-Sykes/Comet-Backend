<?php

// Applied-schema lane (composer test:schema) — NOT composer test.
use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

it('cascades storefront rows when the collection goes', function () {
    $cols = DB::connection('pgsql')->select(<<<'SQL'
        SELECT column_name, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = 'content' AND table_name = 'storefronts'
    SQL);

    expect(collect($cols)->pluck('column_name'))
        ->toContain('referral_query', 'products_curated_at', 'products_autoselected_at', 'connect_status');

    $fk = DB::connection('pgsql')->select(<<<'SQL'
        SELECT confdeltype FROM pg_constraint
        WHERE conrelid = 'content.storefronts'::regclass AND contype = 'f'
    SQL);

    // 'c' = ON DELETE CASCADE. A storefront outliving its collection is an
    // orphan nothing would ever clean up.
    expect($fk[0]->confdeltype)->toBe('c');
});
