# Data Integrity & Privacy Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Data integrity & privacy — FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention (chunks: schema, models-gdpr)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- supabase/migrations/20260724120000_create_item_slugs.sql
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/MenuItemObserver.php
- app/Jobs/Platforms/MenuFetchJob.php (traced — not in original scope list, but required to verify DINT-1's premise)
- app/Services/Site/ItemSlugAllocator.php (traced)
- supabase/migrations/20260619050000_menu_relational_redesign.sql, 20260704170000_drop_menu_platform_checks.sql, 20260701140000_menu_platform_links.sql (traced — required to verify DB CHECK claims)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **DINT-1** · P1 — `MenuFetchJob`'s bulk scrape-rebuild writes bypass Eloquent events, so `site.item_slugs` never mints for new scraped dishes and never frees on removed ones
    - **Where:** app/Jobs/Platforms/MenuFetchJob.php:412, 580, 758; app/Observers/MenuItemObserver.php:23-33; supabase/migrations/20260724120000_create_item_slugs.sql:10-15,35
    - **Affects:** Every site with a connected Uber Eats/DoorDash menu — i.e. most menu-enabled Partna profiles. The pretty-URL slug feature (`slug`/`aliases` on the public menu payload, shipped this session) only ever activates for owner-authored (`is_manual`) dishes; every scraped dish — the primary content source, per the model's own docblock ("Items are rebuilt wholesale on every scrape") — permanently degrades to the raw-UUID fallback the feature was built to replace. `site.item_slugs` also silently accumulates orphaned rows for every dish a re-scrape removes.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `MenuFetchJob`'s rebuild path (around line 580), after `MenuItem::query()->insert($itemRows)`, loop `$itemRows` and call `app(ItemSlugAllocator::class)->ensureCurrent($menu->user_id, ItemSlugAllocator::TYPE_MENU_ITEM, $itemId, $name)` for each newly-inserted row — mirroring what `IntegrationConnectionObserver::syncEventSlugs()` already does for the event side of this same feature.
        - Before the two bulk-delete sites (lines 412 and 758), capture `$deletableItemIds` and call `ItemSlugAllocator::forget()` for each before (or after, in the same transaction) the row is removed.
        - Add a Feature test asserting a `MenuFetchJob` run mints a `site.item_slugs` row for a brand-new scraped dish, and frees the row for a dish dropped from the next scrape.
    - **Technical:** Laravel's query-builder bulk operations (`Model::query()->insert(...)`, `Model::query()->whereIn(...)->delete()`) do not hydrate individual Eloquent models and therefore never fire model events — `created`/`deleted` on `#[ObservedBy(MenuItemObserver::class)]` simply never run. `MenuFetchJob::handle()` inserts every scraped dish via `MenuItem::query()->insert($itemRows)` (line 580) and removes stale ones via `MenuItem::query()->whereIn('id', $deletableItemIds)->delete()` (lines 412, 758, the latter in `clearScrapedContent()`). `MenuItemObserver::created()`/`deleted()` are the *only* code paths that call `ItemSlugAllocator::ensureCurrent()`/`forget()` for menu items (confirmed by repo-wide grep — no other call site references `ItemSlugAllocator` for `TYPE_MENU_ITEM`), and the migration's own doc comment claims a "platform-sync writer" also feeds the allocator, but no such writer exists for menu items (only `EventSlugSync`, wired through `IntegrationConnectionObserver::saved()`, exists for the event side, and that path correctly stays on the standard Eloquent `save()` lifecycle). The one-off `slugs:backfill` command mints slugs retroactively but is not scheduled anywhere in `routes/console.php`, so it only helps once, at rollout. Net effect: a dish that first appears in a scrape after the initial backfill never gets a slug (stuck at `slug: null, aliases: [id]`, per `PublicMenuController`'s documented fallback), and a dish removed by a scrape leaves its `item_slugs` row permanently `is_current = true` pointing at a deleted `menu_item_id`, occupying that slug forever under the table's non-partial `(user_id, slug)` unique index. This degrades gracefully (no 500s, no broken links — DeepSeek's original "broken redirect" framing was not borne out: `lookupCurrent()` is only ever queried with currently-live item IDs), which is why the underlying gap is P1 rather than P0: the feature silently doesn't work for its primary content source, not that it corrupts data or crashes.
    - **Plain English:** Partna just shipped pretty web addresses for menu dishes (like `/menu/fish-tacos` instead of a long random ID) — but the automatic "reminting" only happens when a customer edits a dish by hand on the dashboard. The much more common way dishes get added or removed — the nightly re-scan of a restaurant's Uber Eats or DoorDash menu — completely skips that reminting step, as if the pretty-address system doesn't even know the re-scan happened. So after the very first re-scan, every new dish quietly goes back to the ugly ID-based address, and every removed dish leaves a stale "reserved" entry in the address book that never gets cleaned up. Nothing breaks or shows an error — the feature just silently stops doing its job for almost all real menu content.
    - **Evidence:**
        ```sql
        -- Owned + written exclusively by App\Services\Site\ItemSlugAllocator (via the
        -- MenuItem observer, the platform-sync writer, and the slugs:backfill command).
        ```
        ```php
        // MenuFetchJob.php — bulk insert, no model events fire
        if ($itemRows !== []) {
            // Bulk insert (bypasses casts — badges already JSON).
            MenuItem::query()->insert($itemRows);
        }
        ```
        ```php
        // MenuFetchJob.php — bulk delete, no model events fire
        MenuItemPlatform::query()->whereIn('menu_item_id', $deletableItemIds)->delete();
        DB::connection('pgsql')->table('site.menu_item_categories')->whereIn('menu_item_id', $deletableItemIds)->delete();
        MenuItem::query()->whereIn('id', $deletableItemIds)->delete();
        ```
        ```php
        // MenuItemObserver.php — the ONLY code path that mints/frees item_slugs rows
        public function created(MenuItem $item): void
        {
            $this->sync($item);
        }
        ```

## P3 — Nice to have

- [ ] **DINT-2** · P3 — `site.item_slugs` has no `updated_at`, so a retired-slug timestamp is unrecoverable
    - **Where:** supabase/migrations/20260724120000_create_item_slugs.sql:31-41
    - **Affects:** Operators debugging slug-retirement timing (support/on-call tracing "when did this link stop working"). No user-facing impact.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `updated_at timestamptz NOT NULL DEFAULT now()` to `site.item_slugs`, plus a `BEFORE UPDATE ... FOR EACH ROW` trigger that sets it to `now()` — the table has no backing Eloquent model (`ItemSlugAllocator` writes through the raw query builder exclusively), so there is no `BaseModel`/Eloquent timestamp hook to lean on; the trigger is the only enforcement point that covers every writer.
    - **Technical:** `ItemSlugAllocator::promote()`/`demoteExcept()` mutate existing rows in place (`is_current` flips `true`↔`false` on rename/retire) via `DB::table(...)->update(...)`, but the table only carries `created_at`. With no `updated_at`, there's no DB-level way to answer "when did slug X stop being current" without cross-referencing application logs. Low value given item_slugs holds no PII and the table is small/internal, but it's a one-line gap relative to every other mutable table in the schema.
    - **Plain English:** Every row in this address book has a "created on" stamp but no "last changed" stamp. If someone later asks "when did the old fish-tacos link stop working?", there's no way to answer that from the database alone — you'd have to dig through logs. A last-changed stamp costs almost nothing to add and closes that gap.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.item_slugs (
            id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            user_id    uuid NOT NULL,
            item_type  text NOT NULL,
            item_key   text NOT NULL,   -- menu item UUID, or event SHA1 hex (EventsPayload::id)
            slug       text NOT NULL,
            is_current boolean NOT NULL DEFAULT true,
            created_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT item_slugs_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE,
            CONSTRAINT item_slugs_type_check CHECK (item_type IN ('event', 'menu_item'))
        );
        ```

## Suggested Bundled Sessions

- **Bundle 1 — item_slugs lifecycle completeness:** DINT-1, DINT-2
    - **Why grouped:** both touch the `site.item_slugs` table's write path in the same `execute audit` session — DINT-2's migration is a trivial addition that's natural to ship alongside DINT-1's `MenuFetchJob` wiring fix.
    - **Model:** Plan: Opus · Implement: Sonnet (escalate DINT-1's implement step to Opus — `MenuFetchJob::handle()` is a 300+ line transactional rebuild with identity-reuse semantics (`takeReusedId`); an incorrect edit risks breaking scrape correctness, not just the slug feature) · Review: Sonnet.

## Standalone — do NOT bundle

None.
ng purposes ("wholesale rebuild via query builder bypasses the model observers") without extending that awareness to slug cleanup. `MenuContentController::deleteCategory()` has the identical pattern at line 144. Because `item_slugs_unique_slug` is a *non-partial* unique index over `(user_id, slug)` — unlike `item_slugs_one_current`, which is scoped `WHERE is_current` — an orphaned `is_current = true` row permanently blocks that exact slug string from ever being cleanly reissued, even though nothing references the dead `item_key` again.
    - **Plain English:** Every time a restaurant's menu is re-scraped and a dish disappears (a very normal, routine event — menus change), the system deletes the dish using a fast "bulk delete" shortcut that skips the cleanup step that would normally free up its pretty web address. That address is now permanently squatted by a ghost entry. If the restaurant re-adds the exact same dish later, it can't get its old clean address back — it gets an uglier one with a number tacked on, forever, and the ghost entries just pile up in the database with no exit.
    - **Evidence:**
        ```php
        // MenuFetchJob.php persist()
        MenuItemPlatform::query()->whereIn('menu_item_id', $deletableItemIds)->delete();
        DB::connection('pgsql')->table('site.menu_item_categories')->whereIn('menu_item_id', $deletableItemIds)->delete();
        MenuItem::query()->whereIn('id', $deletableItemIds)->delete();
        ```
        ```php
        // MenuFetchJob.php — the job's own acknowledgment of the bypass
        // Menu content changed (wholesale rebuild via query builder bypasses the
        // model observers) — bust the public-page edge cache.
        ```
        ```sql
        -- 20260724120000_create_item_slugs.sql
        CREATE UNIQUE INDEX IF NOT EXISTS item_slugs_unique_slug
            ON site.item_slugs (user_id, slug);
        ```

- [ ] **DINT-4** · P2 — Event `item_slugs` rows are never retired — no code path anywhere calls `forget()` for `item_type = 'event'`
    - **Where:** app/Services/Platforms/EventSlugSync.php:56-69 (`syncEvents()`); app/Observers/Core/IntegrationConnectionObserver.php:241-247 (`deleted()`)
    - **Affects:** Every user with a connected Eventbrite/Humanitix/custom-events integration — an event that ends, is deleted upstream, or whose whole integration is disconnected leaves its `site.item_slugs` row permanently `is_current = true`, occupying that slug forever with zero cleanup mechanism (unlike the menu-item side, which at least has a `forget()` call for the direct-delete path).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `EventSlugSync::syncEvents()`, diff the incoming event-id set against the previously-synced ids for that `(user_id, item_type='event')` scope (a `lookupCurrent`-style read) and call `ItemSlugAllocator::forget()` for any id no longer present in the payload — mirroring the before/after diff pattern `IntegrationConnectionObserver::updated()` already uses for Instagram folder cleanup (`getOriginal('payload')` vs the new payload).
        - In `IntegrationConnectionObserver::deleted()`, forget every event slug tied to the disconnected connection's extracted event ids (reuse `EventSlugSync::extractEvents()` against `$connection->payload` before it's gone, or against `$connection->getOriginal('payload')` if called post-delete).
    - **Technical:** Confirmed via a full-codebase grep: `ItemSlugAllocator::forget(` is called exactly once in the entire app, from `MenuItemObserver::deleted()`. `EventSlugSync::syncEvents()` (the only writer for `item_type = 'event'`, invoked from `IntegrationConnectionObserver::saved()` and `slugs:backfill`) only ever calls `ensureCurrent()` — there is no corresponding "this event disappeared, retire its slug" branch, and `IntegrationConnectionObserver::deleted()` (the disconnect hook) calls `$this->refresher->refresh($connection)` and `$this->cleanupMirroredMedia($connection)` but never touches `item_slugs`. This is a strictly wider version of DINT-3's pattern: for menu items there's at least a `forget()` call on the direct-delete path; for events there is none, ever. The consequence is identical — a dead `is_current = true` row permanently squats a slug via the non-partial `item_slugs_unique_slug` index, degrading future re-use.
    - **Plain English:** When an event finishes, gets deleted, or the organizer disconnects their Eventbrite account, the system never tells the pretty-URL system "this one's gone." The web address stays reserved forever pointing at nothing, so if the same event (or one with the same name) comes back next year, it can't reuse its old clean link — it gets a clunkier one instead, and the reservation for the dead one never gets released.
    - **Evidence:**
        ```php
        // EventSlugSync.php — the only write path for event slugs, mint-only
        public function syncEvents(string $userId, array $events): void
        {
            foreach ($events as $event) {
                // ...
                $this->slugs->ensureCurrent($userId, ItemSlugAllocator::TYPE_EVENT, $id, $name);
            }
        }
        ```
        ```php
        // IntegrationConnectionObserver.php — disconnect hook, no slug cleanup
        public function deleted(IntegrationConnection $connection): void
        {
            $this->refresher->refresh($connection);
            $this->cleanupMirroredMedia($connection);
        }
        ```

## P3 — Nice to have

- [ ] **DINT-5** · P3 — `site.item_slugs` has no `updated_at`, so the in-place `is_current` flip (retire/promote) leaves no modification timestamp
    - **Where:** supabase/migrations/20260724120000_create_item_slugs.sql:31-41
    - **Affects:** Operators debugging slug-retirement timing; no functional impact.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `updated_at timestamptz NOT NULL DEFAULT now()` and a trigger (or `BaseModel`-level hook, if this table gets an Eloquent model later) that sets it on every `UPDATE` — matching every other mutable table in the schema (`site.sites`, `site.menu_items`, `core.users`).
    - **Technical:** `ItemSlugAllocator::promote()`/`demoteExcept()` perform in-place `UPDATE ... SET is_current = ...` against existing rows, but the table only has `created_at`. There is no way to reconstruct "when did this slug stop being current" from the row alone. Low-risk, purely additive column.
    - **Plain English:** Every row records when it was born, but not when it last changed. If someone needs to know exactly when a link stopped working, there's currently no timestamp to check.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.item_slugs (
            id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            user_id    uuid NOT NULL,
            item_type  text NOT NULL,
            item_key   text NOT NULL,   -- menu item UUID, or event SHA1 hex (EventsPayload::id)
            slug       text NOT NULL,
            is_current boolean NOT NULL DEFAULT true,
            created_at timestamptz NOT NULL DEFAULT now(),
            CONSTRAINT item_slugs_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE,
            CONSTRAINT item_slugs_type_check CHECK (item_type IN ('event', 'menu_item'))
        );
        ```

- [ ] **DINT-6** · P3 — `site.menus.dining_modes` JSONB column has no shape enforcement
    - **Where:** app/Models/Core/Site/Menu.php:26 (`@property array|null $dining_modes`), :89 (`'dining_modes' => 'array'`)
    - **Affects:** `site.menus` row quality — a malformed Uber Eats response shape would land silently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CHECK (dining_modes IS NULL OR jsonb_typeof(dining_modes) = 'array')` — validate the JSON **shape** only.
        - Do **not** enumerate the specific allowed mode strings (`'DELIVERY'`, `'PICKUP'`, etc.) in the CHECK. `supabase/migrations/20260704170000_drop_menu_platform_checks.sql` (FOUND-23) deliberately removed the equivalent hardcoded-vocabulary CHECKs on `pickup_source`/`delivery_source`/`content_source` for exactly this reason: third-party scrape vocabulary (Uber Eats' own mode list) can change without a Partna migration, and the team already decided that enforcement for vendor-controlled vocabulary belongs at the app layer, not a DB CHECK.
    - **Technical:** `dining_modes` is `jsonb NULL` (added by `20260715090000_menu_item_currency_and_dining_modes.sql`) with no constraint on its contents — Postgres accepts any valid JSON value there (object, number, array of non-strings). The column is cast to `'array'` in Eloquent but nothing validates that assumption at write time; `MenuMerger::mergeStore()` gap-fills it straight from the scraper response with no shape check. A type-only CHECK catches "not an array at all" without repeating the FOUND-23 anti-pattern of baking a third party's vocabulary into a migration.
    - **Plain English:** This column is supposed to hold a simple list like "delivery, pickup" scraped from a food app. Right now the database will accept literally anything in that slot — a single word, a number, whatever — with no check at all. A basic "is this actually a list?" check would catch garbage without needing a migration every time Uber Eats invents a new mode name.
    - **Evidence:**
        ```php
        // Menu.php
        /**
         * @property array|null $dining_modes
         */
        protected $casts = [
            // ...
            'dining_modes' => 'array',
            // ...
        ];
        ```

## Suggested Bundled Sessions

- **Bundle 1 — item_slugs lifecycle robustness:** DINT-2, DINT-4
    - **Why grouped:** Both are pure-application-code fixes (no schema change) to the same subsystem — `ItemSlugAllocator` consumers failing to retire/mint slugs correctly across an entity's full lifecycle (soft-deleted parent skip; disconnected-integration/vanished-event skip).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **DINT-1 — Menu/MenuItem SoftDeletes architectural decision:** requires a deliberate decide-then-implement call (possible `deleted_at` migration on `site.menu_items`) — DB / architectural ambiguity.
- **DINT-3 — menu-item `item_slugs` cascade-cleanup trigger:** requires a migration adding a `DELETE` trigger on `site.menu_items` — DB.
- **DINT-5 — `site.item_slugs.updated_at` column:** requires a migration — DB.
- **DINT-6 — `dining_modes` shape CHECK:** requires a migration — DB.
