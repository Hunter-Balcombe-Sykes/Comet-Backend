<?php

// Plan §28.17 audit DATA-1 schema-doc test.
//
// brand_status_history.professional_id was CASCADE — meaning a hard-deleted
// professional took every audit row with them. The migration switches to
// SET NULL so audit history outlives the actor.

it('DATA-1 part 1 swaps the FK to SET NULL via NOT VALID', function () {
    $sql = file_get_contents(base_path('supabase/migrations/20260520020000_brand_status_history_set_null_professional_fk.sql'));

    expect($sql)
        ->toContain('DROP CONSTRAINT IF EXISTS brand_status_history_professional_id_fkey')
        ->toContain('ALTER COLUMN professional_id DROP NOT NULL')
        ->toContain('ON DELETE SET NULL NOT VALID')
        ->not->toContain('VALIDATE CONSTRAINT')
        ->toContain('-- To revert:');
});

it('DATA-1 part 2 validates the FK in its own transaction (CONVENTIONS §4)', function () {
    // Per PR #75 review feedback: NOT VALID + immediate VALIDATE in the same
    // transaction is strictly worse than plain ADD because it adds a
    // constraint-check pass under ACCESS EXCLUSIVE. Splitting VALIDATE into
    // its own file/txn is the documented safe pattern.
    $sql = file_get_contents(base_path('supabase/migrations/20260520020100_validate_brand_status_history_fk.sql'));

    expect($sql)
        ->toContain('VALIDATE CONSTRAINT brand_status_history_professional_id_fkey');
});
