<?php

// Slice 1a: a hard-deleted site.site_media row must not dangle a pointer.
// SET NULL (not CASCADE — item_media may still reference the asset; not
// RESTRICT — SiteMedia::forceDelete() runs in user-deletion flows).

use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

it('sets media_assets.site_media_id to NULL when the upload row is hard-deleted', function () {
    $rule = DB::connection('pgsql')->selectOne("
        SELECT rc.delete_rule
        FROM information_schema.referential_constraints rc
        JOIN information_schema.table_constraints tc
          ON tc.constraint_name = rc.constraint_name
         AND tc.constraint_schema = rc.constraint_schema
        WHERE tc.table_schema = 'content'
          AND tc.table_name = 'media_assets'
          AND rc.constraint_schema = 'content'
          AND rc.unique_constraint_schema = 'site'
    ");

    expect($rule)->not->toBeNull()
        ->and($rule->delete_rule)->toBe('SET NULL');
});
