<?php

// Plan §28.17 audit DATA-1 schema-doc test.
//
// brand_status_history.professional_id was CASCADE — meaning a hard-deleted
// professional took every audit row with them. The migration switches to
// SET NULL so audit history outlives the actor.

it('DATA-1 migration switches brand_status_history.professional_id FK to SET NULL', function () {
    $sql = file_get_contents(base_path('supabase/migrations/20260520020000_brand_status_history_set_null_professional_fk.sql'));

    expect($sql)
        ->toContain('BEGIN;')
        ->toContain('COMMIT;')
        ->toContain('DROP CONSTRAINT IF EXISTS brand_status_history_professional_id_fkey')
        ->toContain('ALTER COLUMN professional_id DROP NOT NULL')
        ->toContain('ON DELETE SET NULL NOT VALID')
        ->toContain('VALIDATE CONSTRAINT brand_status_history_professional_id_fkey')
        ->toContain('-- To revert:');
});
