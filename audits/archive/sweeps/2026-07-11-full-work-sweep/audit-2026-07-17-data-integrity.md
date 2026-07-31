# Data Integrity & Privacy Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Data integrity & privacy — FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Enums/AccountType.php
- app/Enums/SitepageId.php
- database/factories/Core/Site/SiteFactory.php
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql
- supabase/migrations/20260619050000_menu_relational_redesign.sql
- supabase/migrations/20260617130000_create_menus.sql
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Models/Core/Site/MenuCategory.php
- app/Models/Core/Site/MenuPlatformLink.php
- app/Models/Core/Site/Site.php
- app/Models/Core/User/User.php
- app/Observers/User/UserObserver.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Console/Commands/PurgeSoftDeleted.php
- app/Console/Commands/PruneExpiredHandleAliases.php
- app/Http/Requests/Concerns/DesignKitValidationRules.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#DINT-1** · P2 — `typography_tracking` / `theme_contrast` design-kit columns have no DB CHECK backing their fixed vocabulary
    - **Where:** supabase/migrations/20260714220000_add_aesthetic_axes.sql:15-18
    - **Affects:** `site.design_kits` rows written by any path that bypasses `UpdateSiteRequest`/`DesignKitValidationRules` — direct DB fixes, restore/import tooling, seeders, or a future admin script. A bad value renders as broken/missing CSS on the public sitepage since `@partnaau/design-system` trusts the DB to hold only valid selections.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CHECK (typography_tracking IS NULL OR typography_tracking IN ('tight', 'normal', 'wide'))` to `site.design_kits.typography_tracking`.
        - Add `CHECK (theme_contrast IS NULL OR theme_contrast IN ('soft', 'normal', 'stark'))` to `site.design_kits.theme_contrast`.
        - Use the `ADD CONSTRAINT ... NOT VALID` → `VALIDATE CONSTRAINT` pattern this repo already uses for zero-lock rollout, or the `site.sites` low-row-count exemption pattern (`20260714200000`) if `site.design_kits` is similarly small.
        - `weight_heading` is documented as a VALUE (not SELECTION) axis — correctly left unconstrained; no CHECK needed there.
    - **Technical:** Confirmed via `app/Http/Requests/Concerns/DesignKitValidationRules.php:70,148` that the HTTP write path already validates both fields (`'sometimes', 'nullable', 'string', 'in:tight,normal,wide'` and `'in:soft,normal,stark'`), so this is not a live user-facing gap — the Always-Drop rule for "generic input validation on routes with a Form Request" does not disqualify this finding, though, because the concern here is specifically about **non-HTTP write paths** (direct DB writes, restore jobs, future tooling) that the Form Request can't reach. The DB CHECK is the only enforcement point those paths see. This matches the lens's canonical pattern (`site.sites.architecture_id CHECK`, `site.site_media.pool` CHECK) that every other SELECTION-type column in this schema already follows — these two new columns are the exception, not the rule.
    - **Plain English:** These two new design knobs each have exactly three valid settings, and the dashboard already double-checks that when you use it. But the database itself has no such check — so any other tool that writes directly to it (a support fix, a data-restore script) could put in an invalid value the sitepage doesn't know how to render. This finding closes that second door to match every other similar setting in the system.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260714220000_add_aesthetic_axes.sql
        ALTER TABLE site.design_kits
            ADD COLUMN IF NOT EXISTS typography_tracking TEXT NULL,
            ADD COLUMN IF NOT EXISTS theme_contrast TEXT NULL,
            ADD COLUMN IF NOT EXISTS weight_heading TEXT NULL;
        ```

- [ ] **#DINT-2** · P2 — `Menu`'s only soft-delete is safe today, but nothing in the schema or model layer stops a future call site from soft-deleting it without first clearing `MenuItem`/`MenuCategory` children
    - **Where:** app/Models/Core/Site/MenuItem.php (class body), app/Models/Core/Site/MenuCategory.php (class body), app/Jobs/Platforms/MenuFetchJob.php:400-421
    - **Affects:** Any future code path that calls `$menu->delete()`. Today there is exactly one such call site (`MenuFetchJob::clearScrapedContent()`), and it explicitly hard-deletes every `MenuItemPlatform`/`MenuItem`/`MenuCategory` row before soft-deleting the parent `Menu` — so no orphans exist in production today. But `site.menu_items` and `site.menu_categories` reference `site.menus` with `ON DELETE CASCADE`, which only fires on a hard `DELETE`, never on the `UPDATE ... SET deleted_at` that `SoftDeletes` performs. If a second soft-delete path is ever added (admin tooling, a new lifecycle hook) without replicating `clearScrapedContent()`'s manual cleanup, `MenuItem`/`MenuCategory` rows will silently orphan under a `deleted_at`-stamped `Menu`, since neither child model has a `deleted_at` column to mark itself as belonging to a trashed parent.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Do **not** add `SoftDeletes` to `MenuItem`/`MenuCategory` — `MenuCategory`'s docblock explicitly documents "rebuilt wholesale on every scrape — no soft delete" as intentional; both are ephemeral scraped content, not tenant-authored content needing 30-day retention.
        - Instead, add a `static::deleting` guard on `Menu` (or a `MenuObserver::deleting`) that asserts `! $menu->categories()->exists()` before allowing a soft-delete, throwing (or logging to Nightwatch) if children remain — this turns the current implicit "always clear children first" contract into an enforced one.
        - Add a regression test asserting `Menu::delete()` on a menu with live categories either fails loudly or is rejected, so a future developer can't reintroduce the orphan path silently.
    - **Technical:** DB-level `ON DELETE CASCADE` only fires on a hard `DELETE` statement; Laravel's `SoftDeletes` performs an `UPDATE`, so the cascade never triggers on `$menu->delete()`. Verified the current single call site (`MenuFetchJob::clearScrapedContent()`, lines 400-421) deletes `MenuItemPlatform`/`MenuItem`/`MenuCategory` rows via hard `->delete()` on the query builder *before* checking `! $menu->categories()->exists()` and only then soft-deleting `$menu` — so today's behavior is correct by construction, not by DB guarantee. The invariant "a soft-deleted Menu has zero children" exists only as a convention inside one private method, with no schema-level or model-level enforcement stopping a second, less careful call site from violating it.
    - **Plain English:** Right now, whenever the system clears out a menu, it's careful to remove every dish and category first before marking the menu itself as deleted — like emptying a filing cabinet before labeling it "empty." That's working correctly today. But nothing forces the *next* person who writes code touching menus to follow the same careful order — if they take a shortcut, dishes and categories could get left behind, invisible in the trash but still findable by anyone who searches for them directly. Adding a safety check now closes that gap before it becomes a real bug.
    - **Evidence:**
        ```php
        // MenuItem.php — NO SoftDeletes trait, NO deleted_at in $casts
        class MenuItem extends BaseModel
        {
            use HasUuids;

            protected $casts = [
                'position' => 'integer',
                'badges' => 'array',
                'rating' => 'float',
                'rating_count' => 'integer',
                'base_price' => 'float',
                'pickup_price' => 'float',
                'delivery_price' => 'float',
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
            ];
        }
        ```
        ```php
        // MenuFetchJob.php:400-421 — the ONLY current call site that soft-deletes Menu,
        // and it already clears children first (by convention, not by guarantee)
        private function clearScrapedContent(string $userId): void
        {
            $menu = Menu::query()->where('user_id', $userId)->first();
            if ($menu === null) {
                return;
            }

            DB::connection('pgsql')->transaction(function () use ($menu) {
                $categoryIds = $this->rebuildableCategoryIds($menu->id);
                $itemIds = MenuItem::query()->whereIn('category_id', $categoryIds)->pluck('id');
                MenuItemPlatform::query()->whereIn('menu_item_id', $itemIds)->delete();
                MenuItem::query()->whereIn('category_id', $categoryIds)->delete();
                MenuCategory::query()->whereIn('id', $categoryIds)->delete();
                $menu->platformLinks()->delete();

                if (! $menu->categories()->exists()) {
                    $menu->delete();
                } else {
                    $menu->forceFill(['content_source' => 'scan'])->save();
                }
            });
        }
        ```

## P3 — Nice to have

- [ ] **#DINT-3** · P3 — `AccountType::Individual` enum case has outlived its documented purpose and can no longer be written to the DB
    - **Where:** app/Enums/AccountType.php:28
    - **Affects:** Code hygiene only. Confirmed no application write path can currently produce `account_type = 'individual'` — `app/Http/Requests/Api/User/UpdateUserRequest.php` explicitly rejects it at validation, and `database/factories/UserFactory.php` defaults to `'partna'`. All ~90 test-suite references to `'individual'` are explicit fixture overrides running against the SQLite test mirror, which doesn't enforce the Postgres CHECK.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm via Larastan/grep that no remaining code path reads or writes `AccountType::Individual` as a live gating condition.
        - Remove the `case Individual = 'individual';` member and update the pinned `AccountTypeFoundationTest.php` expectation.
        - Leave the test-suite fixture usages of the literal string `'individual'` as-is unless they specifically assert enum-casting behavior — they exercise a different (still-valid) legacy-tolerance code path.
    - **Technical:** The enum's own docblock states `'individual'` is kept "ONLY so Eloquent casting never throws on a row read between the code deploy and the backfill migration (20260612120000_account_type_partna_business)." That backfill ran over a month ago (2026-06-12), and the `core.users` CHECK constraint has excluded `'individual'` since that same migration (further narrowed to drop `'staff'` on 2026-07-12, per `20260712000000_retire_staff_account_type.sql`, verified line 22-23). The stated justification for retaining the case is now factually obsolete. This is genuine cleanup debt, not intentional dormancy — the codebase has already set the precedent of removing dead `AccountType` cases (`AccountType::Staff`, retired 2026-07-12) once their purpose expired.
    - **Plain English:** The system has an old, unused option ("Individual") left over from a one-time data migration that finished more than a month ago. It causes no harm today — nothing can actually pick it or save it — but it's exactly the kind of forgotten leftover that confuses a future developer who wonders why it's still there. Low priority cleanup, not a live risk.
    - **Evidence:**
        ```php
        // app/Enums/AccountType.php:28 — the dead case still present
        case Individual = 'individual';
        ```
        ```sql
        -- supabase/migrations/20260712000000_retire_staff_account_type.sql:22-23
        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business')) NOT VALID;
        ```

- [x] **#DINT-4** · P3 — `site.menus.dining_modes` JSONB has no shape enforcement beyond app-layer normalization · **FIXED (2026-07-31, Josh signed off):** `menus_dining_modes_is_array` CHECK — `dining_modes IS NULL OR jsonb_typeof(dining_modes) = 'array'` — added NOT VALID by `20260731220000` and validated by `...220001` (CONVENTIONS.md §2 file pair; a single ADD would validate every row under ACCESS EXCLUSIVE and fail the migration-safety guard). **Structure only, deliberately not content:** the finding's original enum proposal was rejected because `UberEatsMenuDriver::diningModes()` passes through whatever `supportedDiningModes` strings Uber Eats returns, so a value CHECK would reject a legitimate new mode the day Uber Eats added one and surface as a silently-failing scrape. NULL stays legal (DoorDash exposes none). Applied to dev 2026-07-31, verified `convalidated = true`, ledger realigned to the repo filenames. Pinned by `tests/Schema/CheckConstraintsTest`. ⚠️ **PROD NOT MIGRATED.**
    - **Where:** supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql:11-18
    - **Affects:** `site.menus.dining_modes` — any future consumer beyond the current single reader (`MenuController`/public menu payload) must independently defend against a non-array value reaching it via a direct DB write, since the column's documented `["DELIVERY","PICKUP"]` shape is enforced only by `UberEatsMenuDriver::diningModes()` on the write path, not by the schema.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CHECK (dining_modes IS NULL OR jsonb_typeof(dining_modes) = 'array')` — validates the outer shape only.
        - Do **not** constrain the element values to a fixed list. Confirmed `UberEatsMenuDriver::diningModes()` passes through whatever mode-name strings Uber Eats' `supportedDiningModes` API returns, with no app-side vocabulary; an external-vocabulary CHECK (as originally proposed) would reject legitimate new dining-mode strings the moment Uber Eats introduces one, silently failing future scrapes.
    - **Technical:** The column comment documents an array-of-strings shape and `UberEatsMenuDriver::diningModes()` (verified) already normalizes to `list<string>|null`, tolerating both `[{mode, isAvailable}]` and bare string-list API shapes. But this normalization is PHP-side only — nothing at the DB layer stops a non-array value from being written by a future direct-DB path. Since the vocabulary is externally controlled (Uber Eats' API), the fix should validate structure, not content.
    - **Plain English:** The "dining modes" column is supposed to always be a short list of delivery options like "DELIVERY, PICKUP." The one piece of code that writes it already double-checks this, but the database itself doesn't — so a future bug or manual fix could accidentally store something that isn't a list, and the next reader would crash trying to use it. Adding a lightweight "this must be a list" rule at the database level is cheap insurance, without trying to lock down which specific option names are allowed (that list comes from Uber Eats, not from us, and could grow).
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql
        ALTER TABLE site.menus
            ADD COLUMN IF NOT EXISTS dining_modes jsonb NULL;

        COMMENT ON COLUMN site.menus.dining_modes IS
            'Store-level supported dining modes from the Uber Eats scrape (e.g. ["DELIVERY","PICKUP"]); NULL when unavailable (DoorDash exposes none).';
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Code-only hygiene (no schema change):** #DINT-2, #DINT-3
    - **Why grouped:** Both are code-only changes (a model-level guard + regression test; an enum-case removal) with no DB migration, no auth/money surface, and no shared file — safe to execute together in one low-risk session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#DINT-1 — SELECTION-axis design-kit columns lack CHECK constraints** · standalone: DB migration/schema change (ADD CONSTRAINT).
- **#DINT-4 — `dining_modes` JSONB column lacks shape validation** · standalone: DB migration/schema change (ADD CONSTRAINT).
