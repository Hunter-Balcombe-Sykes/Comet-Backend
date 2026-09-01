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

    // Scoped by PARENT, not by array position. content.storefronts has TWO
    // foreign keys — collection_id -> content.collections (20260813100000) and
    // storefronts_user_id_fkey -> core.users (20260819000100) — and
    // pg_constraint returns them unordered. The old `$fk[0]->confdeltype` was
    // therefore a coin flip that passed only because both happen to cascade:
    // it would have kept passing if the collection FK were changed to NO ACTION
    // and the user FK were read instead. Both parents are asserted; a storefront
    // must not outlive either one.
    $cascadeTo = function (string $parent): ?string {
        $rows = DB::connection('pgsql')->select(<<<'SQL'
            SELECT confdeltype FROM pg_constraint
            WHERE conrelid = 'content.storefronts'::regclass
              AND contype = 'f'
              AND confrelid = ?::regclass
        SQL, [$parent]);

        // Exactly one FK per parent — two would mean a duplicate constraint.
        expect($rows)->toHaveCount(1, "expected exactly one FK to {$parent}");

        return $rows[0]->confdeltype;
    };

    // 'c' = ON DELETE CASCADE. A storefront outliving its collection (or its
    // owner) is an orphan nothing would ever clean up.
    expect($cascadeTo('content.collections'))->toBe('c')
        ->and($cascadeTo('core.users'))->toBe('c');
});
