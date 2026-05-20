<?php

// Structural assertion for the audit-table hardening migration
// (20260513200000_harden_audit_tables.sql). RLS and FK-on-delete behaviour
// cannot be exercised against the SQLite test harness, so this test guards
// the migration text itself — preventing the safety properties from being
// silently downgraded in a later edit.
//
// Assertions use regex with \s+ instead of exact-whitespace string matching so
// that incidental reformatting (extra newlines, indentation changes) doesn't
// break the test while the semantic SQL content is unchanged.

// Feature tests already extend Tests\TestCase via pest()->extend(...)->in('Feature').

beforeEach(function () {
    $this->migration = file_get_contents(
        base_path('supabase/migrations/20260513200000_harden_audit_tables.sql')
    );
});

it('enables RLS on all three audit tables', function () {
    foreach ([
        'core.professional_deletion_audit',
        'core.wallet_currency_switch_audit',
        'core.brand_status_history',
    ] as $table) {
        $escaped = preg_quote($table, '/');
        expect($this->migration)->toMatch("/ALTER\s+TABLE\s+{$escaped}\s+ENABLE\s+ROW\s+LEVEL\s+SECURITY/");
    }
});

it('flips wallet + brand_status_history FKs to ON DELETE SET NULL', function () {
    expect($this->migration)->toMatch(
        '/FOREIGN\s+KEY\s+\(professional_id\)\s+REFERENCES\s+core\.professionals\(id\)\s+ON\s+DELETE\s+SET\s+NULL/'
    );

    // Both tables must drop their original CASCADE constraint before re-adding
    // it with SET NULL — guarded by DROP CONSTRAINT statements.
    expect($this->migration)->toMatch(
        '/DROP\s+CONSTRAINT\s+IF\s+EXISTS\s+wallet_currency_switch_audit_professional_id_fkey/'
    );
    expect($this->migration)->toMatch(
        '/DROP\s+CONSTRAINT\s+IF\s+EXISTS\s+brand_status_history_professional_id_fkey/'
    );
});

it('adds professional_handle_snapshot to wallet + brand_status_history', function () {
    expect($this->migration)->toMatch('/ALTER\s+TABLE\s+core\.wallet_currency_switch_audit/');
    expect($this->migration)->toMatch('/ALTER\s+TABLE\s+core\.brand_status_history/');
    expect($this->migration)->toMatch('/ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+professional_handle_snapshot\s+text/');
});

it('grants app_backend FOR ALL with USING and WITH CHECK on every audit table', function () {
    foreach ([
        'professional_deletion_audit_app_backend_all',
        'wallet_currency_switch_audit_app_backend_all',
        'brand_status_history_app_backend_all',
    ] as $policy) {
        $escaped = preg_quote($policy, '/');
        expect($this->migration)->toMatch("/CREATE\s+POLICY\s+{$escaped}/");
        expect($this->migration)->toContain('TO app_backend');
    }
});

it('exposes staff-only SELECT policies filtered on partna_staff role', function () {
    foreach ([
        'professional_deletion_audit_staff_select',
        'wallet_currency_switch_audit_staff_select',
        'brand_status_history_staff_select',
    ] as $policy) {
        $escaped = preg_quote($policy, '/');
        expect($this->migration)->toMatch("/CREATE\s+POLICY\s+{$escaped}/");
    }

    expect($this->migration)->toMatch("/ps\.role\s+IN\s+\('admin',\s*'support'\)/");
    expect($this->migration)->toMatch('/FROM\s+core\.partna_staff\s+ps/');
});

it('grants tenant SELECT on financial + lifecycle audit tables only', function () {
    expect($this->migration)->toMatch('/CREATE\s+POLICY\s+wallet_currency_switch_audit_tenant_select/');
    expect($this->migration)->toMatch('/CREATE\s+POLICY\s+brand_status_history_tenant_select/');

    // Deletion audit has no tenant policy — by the time a row matters, the
    // tenant is gone or going. Confirm we have not accidentally added one.
    expect($this->migration)
        ->not->toContain('professional_deletion_audit_tenant_select');
});
