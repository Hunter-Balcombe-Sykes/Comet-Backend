# Test↔Prod Schema Parity Audit — 2026-08-24

**Branch:** development
**Lens:** Test↔prod schema parity: application writes that pass SQLite CI but violate Postgres constraints (PARITY)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- supabase/migrations/20260820100000_storefronts_products_autoselected_at.sql
- supabase/migrations/20260820110000_single_account_social_convergence.sql
- supabase/migrations/20260823100000_unified_actions.sql
- supabase/migrations/20260823100001_unified_actions_validate.sql
- supabase/migrations/20260823120000_item_scores_keyed_by_id.sql
- supabase/migrations/20260823130000_service_category_family.sql
- supabase/migrations/20260823130001_service_category_family_validate.sql
- supabase/migrations/20260726000000_baseline_pilot.sql (baseline DDL cross-reference)
- supabase/migrations/20260819000100_content_storefronts_user_id.sql
- app/Models/Analytics/ActionEvent.php
- app/Models/Core/User/PreAccountBuild.php
- app/Services/PreAccount/PreAccountBuildService.php
- app/Services/Shop/ShopContentWriter.php
- app/Services/Analytics/ItemFamily.php
- app/Services/Analytics/ActionScorer.php
- app/Http/Requests/Api/PublicSite/Analytics/ItemSeenRequest.php
- tests/Pest.php (setupPreAccountBuildsTable, setupActionEventsTable, setupContentTables)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

- [ ] **PARITY-1** · P3 — SQLite `core.pre_account_builds` seed omits prod NOT NULL + CHECK on `build_state`, `built_via`, `source_type`
    - **Where:** tests/Pest.php:564-579 (setupPreAccountBuildsTable)
    - **Affects:** Test-harness fidelity only today — no current write path sets an invalid or null value on these three columns (verified: `PreAccountBuildService::requestBuild()` always writes `build_state` from `PreAccountBuild::STATE_*` constants, `built_via` from `VIA_*` constants or the single caller `ApproveEarlyAccessBuildJob` passing `VIA_EARLY_ACCESS`, and `source_type` is a required `string` parameter routed through `SourceGeneratorRegistry`). This is a preventive gap: a future write path that bypasses those constants would pass CI green and 500 on Postgres.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Tighten `setupPreAccountBuildsTable()` in `tests/Pest.php` to `source_type TEXT NOT NULL`, `built_via TEXT NOT NULL`, `build_state TEXT NOT NULL DEFAULT 'pending'` (SQLite still can't enforce the CHECK domains, but NOT NULL alone catches an accidental `null` write).
        - Optionally add a PG-lane test (`tests/Postgres/`) asserting the three CHECK constraints reject out-of-domain literals, since SQLite structurally cannot.
    - **Technical:** `core.pre_account_builds` in the baseline (`supabase/migrations/20260726000000_baseline_pilot.sql:1018-1039`) declares `source_type`, `source_ref`, `source_ref_lc`, `built_via` and `build_state` all `NOT NULL`, with `pre_account_builds_build_state_check`, `pre_account_builds_built_via_check` and `pre_account_builds_source_type_check` CHECK constraints pinning each to a closed vocabulary. The SQLite stand-in in `tests/Pest.php` declares all three as `TEXT NULL` with no CHECK. SQLite enforces neither NOT NULL-as-declared (since the column is literally nullable here) nor CHECK, so a future write path that omits one of these columns, or that constructs a value outside the enum (e.g. string-built rather than sourced from the model's `STATE_*`/`VIA_*` constants), would pass the full test suite and raise `not_null_violation`/`check_violation` on real Postgres. Today every write path is constant-sourced (`PreAccountBuild.php:43-64`, `PreAccountBuildService.php:174,184,302`), so this is a seed-fidelity gap, not an active defect.
    - **Plain English:** The test version of the database that checks new-account "starter kit" builds is missing some of the safety rules the real database has — like requiring certain fields to always have a valid value from an approved list. Right now nothing in the code actually breaks those rules, but because the test copy doesn't enforce them, a future code change that slips up here wouldn't get caught until it broke in front of real users. Tightening the test copy now means any future mistake gets caught immediately instead of in production.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260726000000_baseline_pilot.sql:1021-1038
        "source_type" "text" NOT NULL,
        ...
        "built_via" "text" NOT NULL,
        ...
        "build_state" "text" DEFAULT 'pending'::"text" NOT NULL,
        ...
        CONSTRAINT "pre_account_builds_build_state_check" CHECK (("build_state" = ANY (ARRAY['pending'::"text", 'building'::"text", 'ready'::"text", 'failed'::"text"]))),
        CONSTRAINT "pre_account_builds_built_via_check" CHECK (("built_via" = ANY (ARRAY['signup'::"text", 'staff'::"text", 'early_access'::"text"]))),
        CONSTRAINT "pre_account_builds_source_type_check" CHECK (("source_type" = ANY (ARRAY['instagram'::"text", 'google_business'::"text"])))
        ```
        ```php
        // tests/Pest.php:564-579 setupPreAccountBuildsTable()
        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.pre_account_builds (
            id TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            source_type TEXT NULL,
            source_ref TEXT NULL,
            source_ref_lc TEXT NULL,
            built_via TEXT NULL,
            built_by_staff_id TEXT NULL,
            build_state TEXT NULL DEFAULT \'pending\',
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Pre-account build seed hardening:** #PARITY-1
    - **Why grouped:** single file, single function (`setupPreAccountBuildsTable()`), no other findings share this root cause.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
