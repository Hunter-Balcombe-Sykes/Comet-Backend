# Test↔Prod Schema Parity Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Test↔prod schema parity: application writes that pass SQLite CI but violate Postgres constraints (PARITY)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude`
**Source files audited:**
- supabase/migrations/20260724120000_create_item_slugs.sql
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- tests/Pest.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/MenuItemObserver.php
- app/Services/Platforms/EventSlugSync.php
- app/Services/Site/ItemSlugAllocator.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **PARITY-1** · P2 — `site.menus.user_id` / `site.menu_items.menu_id` / `site.menu_items.name` are `NOT NULL` in prod but nullable in the SQLite test seed
    - **Where:** tests/Pest.php:669-687 (`site.menus`), tests/Pest.php:718-738 (`site.menu_items`)
    - **Affects:** Test-suite fidelity for the whole menu feature (`MenuContentController`, `MenuScanApplier`, `MenuFetchJob`) — a future write path that forgets `user_id`/`menu_id`/`name` would pass CI green today and 500 on Postgres with `23502 not_null_violation`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `tests/Pest.php`, change `site.menus.user_id` from `TEXT NULL` to `TEXT NOT NULL`, and `site.menu_items.menu_id` / `site.menu_items.name` from `TEXT NULL` to `TEXT NOT NULL`, matching the real migrations.
        - Re-run the full menu Pest suite — every current write path (`MenuContentController::createItem`/`resolveMenu`, `MenuScanApplier::resolveMenu`/apply loop, `MenuFetchJob::updateOrCreate`) already supplies all three columns on every call, so this is expected to be a no-op test-wise; it only closes the coverage hole for future writers.
    - **Technical:** `supabase/migrations/20260617130000_create_menus.sql` declares `user_id uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE` on `site.menus`, and `supabase/migrations/20260619050000_menu_relational_redesign.sql` declares `menu_id uuid NOT NULL REFERENCES site.menus (id) ON DELETE CASCADE` and `name text NOT NULL` on `site.menu_items`. Neither constraint was ever relaxed by a later migration (checked all subsequent `ALTER TABLE site.menus`/`site.menu_items` statements through `20260721090000`). The SQLite seed in `tests/Pest.php` declares all three columns nullable, so SQLite would silently accept a mass-assignment or `->save()` that omits them — Postgres would raise `not_null_violation`. Verified every current write path (`MenuContentController.php:190,463`, `MenuScanApplier.php:147,272`, `MenuFetchJob.php:154`) explicitly sets all three columns, so there is no live green-CI/prod-500 path today — this is a test-coverage gap that would only bite a future writer, not an active bug. No `MenuFactory`/`MenuItemFactory` exists to check separately.
    - **Plain English:** Our practice database (used for automated tests) currently allows a menu or a dish to be saved without an owner or a name — like a form that lets you submit without filling in the required fields. The real database enforces those fields strictly and would reject an incomplete submission. Every place that currently creates a menu or dish already fills in those fields correctly, so nothing is broken today — but if a future change accidentally forgot to set one of them, our tests wouldn't catch it, and it would only break once it reached the live site. Tightening the practice database to match the real one closes that blind spot before it can bite us.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260617130000_create_menus.sql
        user_id         uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
        ```
        ```sql
        -- supabase/migrations/20260619050000_menu_relational_redesign.sql
        menu_id         uuid NOT NULL REFERENCES site.menus (id) ON DELETE CASCADE,
        category_id     uuid NOT NULL REFERENCES site.menu_categories (id) ON DELETE CASCADE,
        position        integer NOT NULL DEFAULT 0,
        name            text NOT NULL,
        ```
        ```sql
        -- tests/Pest.php (SQLite seed — both columns nullable)
        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menus (
            id TEXT PRIMARY KEY,
            user_id TEXT NULL,
            ...
        DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS site.menu_items (
            id TEXT PRIMARY KEY,
            menu_id TEXT NULL,
            name TEXT NULL,
            ...
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Menu SQLite-seed NOT NULL parity:** #PARITY-1
    - **Why grouped:** single self-contained `tests/Pest.php` edit; no other surviving finding shares this file/subsystem.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
