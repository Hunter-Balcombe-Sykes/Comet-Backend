<?php

// Non-vacuous companion to CheckConstraintsTest's Postgres-only introspection.
// That file used to markTestSkipped on the default SQLite CI driver and prove
// nothing on its own — fixed as of commit 5ea53445, which moved it into the
// applied-schema lane (tests/Schema/, composer test:schema) where it runs for
// real. This file has NO Postgres guard — it runs for real on SQLite in
// every CI run.
//
// WHY THIS EXISTS
// ----------------
// The SCHEMA-1/SCHEMA-2 audit findings (audits/archive/sweeps/2026-07-11-full-work-sweep/
// TRIAGE-2-P2.md) specified CHECK vocabularies copied from stale SQL DDL
// comments — 7 item types instead of the real 10, 8 content types instead of
// the real 12 — because nothing kept the DDL comment, the migration's CHECK,
// and the Form Request's validated vocabulary in lockstep. Shipping the
// stale lists would have failed VALIDATE CONSTRAINT against real data and
// broken the nightly analytics:compute-popularity job. This test makes that
// class of drift a CI failure instead of a manual-review catch.
//
// HOW IT WORKS
// ------------
// For each CHECK added by the 2026-07-20 SCHEMA/DINT batch, this test reads
// the migration .sql file as text, regexes out the literal IN (...) list, and
// compares it against BOTH:
//   1. the live app-side vocabulary (the Form Request rule / model constant
//      the CHECK is meant to mirror), and
//   2. a HARDCODED expected set written directly into this test.
//
// The hardcoded set is the anti-tautology anchor: comparing the migration
// only against the app-side source would still pass if BOTH drifted together
// (e.g. someone widens ItemSeenRequest::ITEM_TYPES without touching the CHECK
// and this test also derives its expectation from ITEM_TYPES — a silent
// no-op). Anchoring one side to a value written by hand, independent of
// either live source, means adding an 11th item type to the app WITHOUT
// widening the CHECK — the exact drift that produced the stale audit
// finding — fails this test, because the app's list no longer equals the
// hardcoded set even though the migration (unchanged) still does.

use App\Http\Requests\Api\PublicSite\Analytics\ItemSeenRequest;
use App\Http\Requests\Platforms\UpdateShopBrandRequest;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\Site;
use App\Services\Analytics\RankedActionsComputer;

/**
 * Read a migration file's contents by basename, from supabase/migrations/ or the
 * archive. The 2026-07-26 baseline collapse moved every incremental into
 * supabase/migrations-archive/, so the file defining a given constraint usually
 * lives there now; its DDL is still the origin of the live constraint.
 */
function lockstepMigrationSql(string $basename): string
{
    foreach (['supabase/migrations/', 'supabase/migrations-archive/'] as $dir) {
        $path = base_path($dir.$basename);
        if (file_exists($path)) {
            return (string) file_get_contents($path);
        }
    }

    expect(false)->toBeTrue("Expected migration file [$basename] in supabase/migrations/ or supabase/migrations-archive/.");

    return '';
}

/**
 * Extract the literal string values from the first `<column> IN ('a', 'b', ...)`
 * clause found in raw migration SQL. Relies on none of the quoted literals
 * containing a `)` character, which holds for every vocabulary in this batch —
 * so a plain [^)]* is sufficient to find the closing paren of the IN list
 * without a full SQL parser.
 *
 * @return list<string>
 */
function lockstepExtractInList(string $sql, string $column): array
{
    if (! preg_match('/\b'.preg_quote($column, '/').'\s+IN\s*\(([^)]*)\)/', $sql, $m)) {
        throw new RuntimeException("Could not find an IN (...) list for column [$column] in the migration SQL.");
    }

    $values = array_map(
        fn (string $v) => trim(trim($v), "'"),
        explode(',', $m[1])
    );

    return array_values(array_filter($values, fn (string $v) => $v !== ''));
}

/**
 * Pull the CSV list out of a Laravel `in:a,b,c` validation rule string.
 *
 * @param  array<int, string>  $rules
 * @return list<string>
 */
function lockstepExtractInRule(array $rules): array
{
    foreach ($rules as $rule) {
        if (is_string($rule) && str_starts_with($rule, 'in:')) {
            return explode(',', substr($rule, 3));
        }
    }

    throw new RuntimeException('No in: rule found in the given rule set.');
}

/**
 * Sort-independent equality: the CHECK's IN (...) order need not match the
 * app-side array order for the two to be the same vocabulary.
 *
 * @param  list<string>  $a
 * @param  list<string>  $b
 */
function lockstepAssertSameSet(array $a, array $b, string $label): void
{
    sort($a);
    sort($b);
    expect($a)->toEqual($b, "Vocabulary mismatch for [$label]: ".implode(',', $a).' vs '.implode(',', $b));
}

// ─── analytics.item_views.item_type (SCHEMA-1) ───────────────────────────────

it('item_views_item_type_check matches ItemSeenRequest::ITEM_TYPES and the hardcoded expected set', function () {
    // Hand-written anchor, independent of both the migration and the app class.
    $expected = [
        'shop_product', 'menu_item', 'menu_category', 'service', 'block',
        'gallery_item', 'engine_item', 'listen_item', 'watch_item', 'link_item',
    ];

    $sql = lockstepMigrationSql('20260720100400_item_views_item_type_check.sql');
    $migrationList = lockstepExtractInList($sql, 'item_type');

    lockstepAssertSameSet($migrationList, $expected, 'item_views_item_type_check (migration vs hardcoded)');
    lockstepAssertSameSet(ItemSeenRequest::ITEM_TYPES, $expected, 'ItemSeenRequest::ITEM_TYPES (app vs hardcoded)');
});

// ─── analytics.content_popularity_scores.content_type (SCHEMA-2) ────────────

it('content_popularity_scores_content_type_check matches the item taxonomy + page + action', function () {
    // The content_type vocabulary is the full item-type taxonomy plus the two
    // non-item grains: 'page' (section/page scores) and 'action'
    // (RankedActionsComputer's derived ranked-action layer).
    $expected = [
        'page', 'action', 'shop_product', 'menu_item', 'menu_category',
        'service', 'block', 'gallery_item', 'engine_item', 'listen_item',
        'watch_item', 'link_item',
    ];

    $sql = lockstepMigrationSql('20260720100300_content_popularity_scores_content_type_check.sql');
    $migrationList = lockstepExtractInList($sql, 'content_type');

    lockstepAssertSameSet($migrationList, $expected, 'content_popularity_scores_content_type_check (migration vs hardcoded)');

    // App-side: item taxonomy (ItemSeenRequest) + 'page' + RankedActionsComputer's
    // owned 'action' type, derived from two independent live sources.
    $appList = [...ItemSeenRequest::ITEM_TYPES, 'page', RankedActionsComputer::CONTENT_TYPE];
    lockstepAssertSameSet($appList, $expected, 'item taxonomy + page + action (app vs hardcoded)');
});

// ─── site.sites.shop_link_mode (SCHEMA-5 / SCHEMA-102) ───────────────────────

it('sites_shop_link_mode_check matches Site::SHOP_LINK_MODES and the hardcoded expected set', function () {
    $expected = ['checkout', 'product'];

    $sql = lockstepMigrationSql('20260720100000_sites_shop_link_mode_check.sql');
    $migrationList = lockstepExtractInList($sql, 'shop_link_mode');

    lockstepAssertSameSet($migrationList, $expected, 'sites_shop_link_mode_check (migration vs hardcoded)');
    lockstepAssertSameSet(Site::SHOP_LINK_MODES, $expected, 'Site::SHOP_LINK_MODES (app vs hardcoded)');
});

// ─── site.design_kits (DINT-101) — NOTHING TO PIN, DELIBERATELY ─────────────
//
// The typography_tracking and theme_contrast lockstep pairs were removed on
// 2026-08-06: both columns and both CHECK constraints left the schema with the
// design-kit simplification (20260806090001), so there was no vocabulary left
// to keep in step. theme_mode's single legal value lives in the request rule
// alone — it never had a CHECK constraint to pair with.
//
// 2026-08-09 (preset-only, 20260809090001) added FOUR new vocabularies —
// text_size, spacing, corners and border_thickness — and gave none of them a
// CHECK either. That was a decision, not an oversight: it follows theme_mode's
// precedent, and the request layer is the only write path that carries a
// vocabulary at all (DesignKitAutopilot and DesignKitAccentApplier write
// color_accent only; DesignKitRestyleService replays autopilot's proposals).
// UpdateSiteValidationTest and WriteDesignKitTest cover both sides of each
// vocabulary — the reject and the accept — which is the tooth those four have.
//
// IF CHECKS ARE EVER ADDED, THREE THINGS MOVE TOGETHER: a NOT VALID migration
// plus a separate VALIDATE one (CONVENTIONS §2 — site.design_kits is a hot
// table), and one `it(...)` block per column here reading the IN (...) list
// straight out of that migration's text. Adding the constraints without the
// blocks leaves the drift this file exists to catch; adding the blocks without
// the constraints fails CI on SQLite for a reason that looks unrelated.
// DesignKitValidationRules is deliberately NOT imported until then — an unused
// import reads as coverage that isn't there.

// ─── site.shop_brands.selection_mode / link_mode (SCHEMA-4) ─────────────────

it('shop_brands_selection_mode_check matches UpdateShopBrandRequest and the hardcoded expected set', function () {
    $expected = ['manual', 'latest'];

    $sql = lockstepMigrationSql('20260720100200_shop_brands_mode_checks.sql');
    $migrationList = lockstepExtractInList($sql, 'selection_mode');

    $rules = (new UpdateShopBrandRequest)->rules();
    $appList = lockstepExtractInRule($rules['selectionMode']);

    lockstepAssertSameSet($migrationList, $expected, 'shop_brands_selection_mode_check (migration vs hardcoded)');
    lockstepAssertSameSet($appList, $expected, 'UpdateShopBrandRequest selectionMode (app vs hardcoded)');
});

it('shop_brands_link_mode_check matches UpdateShopBrandRequest and the hardcoded expected set', function () {
    $expected = ['product', 'checkout'];

    $sql = lockstepMigrationSql('20260720100200_shop_brands_mode_checks.sql');
    $migrationList = lockstepExtractInList($sql, 'link_mode');

    $rules = (new UpdateShopBrandRequest)->rules();
    $appList = lockstepExtractInRule($rules['linkMode']);

    lockstepAssertSameSet($migrationList, $expected, 'shop_brands_link_mode_check (migration vs hardcoded)');
    lockstepAssertSameSet($appList, $expected, 'UpdateShopBrandRequest linkMode (app vs hardcoded)');
});

// ─── site.shop_brands.connect_status (W9) ────────────────────────────────────

it('shop_brands_connect_status_check matches ShopBrand::CONNECT_STATUSES and the hardcoded expected set', function () {
    $expected = ['pending', 'failed'];

    $sql = lockstepMigrationSql('20260724150000_shop_brands_connect_status.sql');
    $migrationList = lockstepExtractInList($sql, 'connect_status');

    lockstepAssertSameSet($migrationList, $expected, 'shop_brands_connect_status_check (migration vs hardcoded)');
    lockstepAssertSameSet(ShopBrand::CONNECT_STATUSES, $expected, 'ShopBrand::CONNECT_STATUSES (app vs hardcoded)');
});
