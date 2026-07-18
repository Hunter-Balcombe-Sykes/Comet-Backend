# Data Integrity & Privacy Audit — 2026-07-08

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- supabase/migrations/
- app/Models/
- database/factories/
- app/Observers/
- app/Jobs/Gdpr/
- app/Services/User/

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 5 complete

---

## P2 — Should fix

- [ ] **#DINT-1** · P2 — `ContentSelection::media()` has no soft-delete guard, leaving a dangling `media_id` after the picked photo is deleted
    - **Where:** app/Models/Core/Site/ContentSelection.php:72-75
    - **Affects:** Dashboard "Content Selection" feature — any user who soft-deletes a gallery/content media item currently used as one of their up-to-15 background-content picks sees a broken/blank tile in that slot for up to 30 days (until the routine purge hard-deletes the trashed media and the `ON DELETE CASCADE` finally removes the stale pick).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the read path that assembles Content Selection for the dashboard (`ContentSelectionService` or equivalent), skip/placeholder any entry whose `media` relation resolves to `null`.
        - Alternatively, unselect the slot proactively: add a `SiteMedia`-soft-delete hook that deletes (or nulls) the corresponding `content_selection` row when its `media_id` is soft-deleted, mirroring the migration comment's "unselect != delete upload" intent.
    - **Technical:** `ContentSelection::media()` is a bare `BelongsTo(SiteMedia::class, 'media_id')`. `SiteMedia` uses `SoftDeletes`, so once a picked media row is soft-deleted, Eloquent's global scope hides it and `$contentSelection->media` silently resolves to `null` — but the `content_selection.media_id` column still holds the old UUID and the `content_selection` row itself is untouched (soft-delete is an `UPDATE`, not a `DELETE`, so the `media_id ... ON DELETE CASCADE` FK never fires). The row only disappears once the routine 30-day purge hard-deletes the trashed `SiteMedia`, at which point the CASCADE finally removes the stale pick.
    - **Plain English:** Users pick up to 15 photos to feature as background content on their page. If they later delete one of those photos from their gallery, the "pick" doesn't notice — it keeps pointing at a photo that no longer exists, so the dashboard shows an empty or broken square where that photo used to be. This fixes itself automatically after about a month, but until then it looks broken to the user.
    - **Evidence:**
        ```php
        public function media(): BelongsTo
        {
            return $this->belongsTo(SiteMedia::class, 'media_id');
        }
        ```

- [ ] **#DINT-2** · P2 — `SiteMedia` soft-delete does not trigger R2 storage cleanup — only `forceDeleting` does
    - **Where:** app/Models/Core/Site/SiteMedia.php:156-202, app/Observers/Core/SiteMediaObserver.php:58-63
    - **Affects:** Any user who soft-deletes a gallery/content photo or video — the original upload and every processed variant remain live in R2 storage for up to 30 days (storage cost, and a modest privacy-retention gap if the media depicts identifiable people/locations).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `static::deleting()` hook (or a `SiteMediaObserver::deleted()` branch) that dispatches the same R2 cleanup used by `forceDeleting` — or explicitly document that soft-delete intentionally retains files for the 30-day recovery window and treat this as accepted, since `SiteMedia` is already in `PurgeSoftDeleted::PURGE_HANDLED` and gets swept correctly.
        - If immediate cleanup is chosen, verify a subsequent `restore()` doesn't try to re-serve a URL for a now-deleted R2 object.
    - **Technical:** `SiteMedia::booted()` registers only a `forceDeleting` hook that walks `mediaVariants` and deletes both variant files and the original from storage. `SiteMediaObserver::deleted()` (the soft-delete event) only busts cache and re-evaluates section visibility — it does no storage cleanup. Since `SiteMedia` is already in `PurgeSoftDeleted::PURGE_HANDLED`, the files ARE eventually removed when the 30-day retention window elapses and `forceDelete()` fires the existing hook — this is not a permanent leak, but the gap between "user deletes" and "files actually removed" is the full 30-day grace window with no code path narrowing it.
    - **Plain English:** When someone deletes a photo from their gallery, the actual image file keeps sitting in cloud storage for up to a month before it's really wiped. It's like throwing a piece of paper in the bin but the cleaner only empties that bin once a month — the paper looks gone from your desk, but it's still in the building the whole time.
    - **Evidence:**
        ```php
        protected static function booted(): void
        {
            // Collect variant storage paths BEFORE forceDelete fires — the DB cascade
            // wipes media_variants rows at the same time the parent row is deleted,
            // so forceDeleted (after-event) would find an empty relation.
            static::forceDeleting(function (SiteMedia $media): void {
        ```
        ```php
        public function deleted(SiteMedia $media): void
        {
            // Always bust on delete — presence/count always changes.
            $this->reevaluateIfRelevant($media);
            $this->touchParentSite($media, 'delete');
        }
        ```

- [ ] **#DINT-3** · P2 — Workplace-card PII is not scrubbed when an account deletion is confirmed
    - **Where:** app/Services/User/AccountDeletionService.php:267-282 (`pseudonymiseAccountPii`), app/Models/Core/Site/Workplace.php:24-47
    - **Affects:** Any user with a workplace/business card who confirms account deletion — their `site.workplaces` row (address, phone, `contact_email`) stays fully live and queryable (by staff tooling / internal jobs) for the entire 30-day grace period, even though their own profile columns are pseudonymised the instant they confirm.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `redact()` method on `Workplace` (mirroring the existing `Customer::redact()` / `Enquiry::redact()` pattern) that nulls `phone`, `contact_email`, `address`, `address_line1`, `city`, `state`, `postcode`, `country`.
        - Call it from `AccountDeletionService::executeConfirmation()` alongside `pseudonymiseAccountPii()`, inside the same transaction.
    - **Technical:** `pseudonymiseAccountPii()` immediately redacts 11 identity/contact columns on the `core.users` row the moment a deletion is confirmed. `site.workplaces` — a 1:1 table promoted out of `site.sites.settings->'workplace'` on 2026-07-01 — carries its own contact PII and is never touched by this method. The site is unpublished in the same transaction (`is_published = false`), so the workplace card stops being publicly reachable immediately, and the row IS correctly hard-deleted at day 30 via the existing cascade (`site.workplaces.site_id → site.sites ON DELETE CASCADE` → `site.sites.professional_id → core.users ON DELETE CASCADE`, confirmed in the baseline migration) — this is not a permanent leak. The gap is that, unlike the identity fields on the user row itself, workplace contact PII sits live and internally queryable for the full 30-day window instead of being redacted at confirm time. Note: `DataExportPayloadBuilder::site()` already correctly includes the workplace row in GDPR exports (`site.workplaces` joined by `site_id`) — the export side of this table is fine; only the deletion-redaction side has the gap.
    - **Plain English:** When a user clicks "delete my account," we immediately scramble their name, email, and phone number on their profile. But their business/workplace card — which can include a shop address, phone number, and public contact email — is left completely untouched for the full month-long waiting period before final deletion. It's like shredding someone's ID card but leaving their business card sitting in an unlocked filing cabinet for a month.
    - **Evidence:**
        ```php
        protected function pseudonymiseAccountPii(User $professional): void
        {
            $professional->forceFill([
                'phone' => 'redacted',
                'primary_email' => "deleted+{$professional->id}@partna.au",
                'first_name' => 'Deleted',
                'last_name' => null,
                'public_contact_email' => null,
                'public_contact_number' => null,
                'location_street_address' => null,
                'location_postcode' => null,
                'location_city' => null,
                'location_state' => null,
                'location_country' => null,
            ])->save();
        }
        ```
        ```php
        protected $fillable = [
            'site_id',
            'name',
            'address',
            'address_line1',
            'city',
            'state',
            'postcode',
            'country',
            'latitude',
            'longitude',
            'phone',
            'website',
            'previous_website',
            'category',
            'description',
            'opening_hours',
            'contact_email',
        ];
        ```

## P3 — Nice to have

- [ ] **#DINT-4** · P3 — `public.failed_jobs.failed_at` uses timezone-naive `timestamp` while every other table uses `timestamptz`
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql:1294
    - **Affects:** Staff/ops queries that compare `failed_jobs.failed_at` against `timestamptz` columns elsewhere in the schema — this is the stock Laravel `failed_jobs` migration and is never joined against business/PII data in practice, so the practical blast radius is limited to Horizon/ops tooling.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE public.failed_jobs ALTER COLUMN failed_at TYPE timestamptz USING failed_at AT TIME ZONE 'UTC';`
    - **Technical:** Every Partna-authored table uses `timestamptz`; `public.failed_jobs` is the unmodified stock Laravel migration and is the only exception, storing `timestamp(0) without time zone`. Comparing it to any `timestamptz` column requires Postgres to assume a session timezone, which can silently differ between the app server and a SQL client.
    - **Plain English:** Every clock in the system records its timezone — except one, on the internal "failed background jobs" log. It's a minor inconsistency that only affects internal engineering tools, not real users.
    - **Evidence:**
        ```sql
        failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
        ```

- [ ] **#DINT-5** · P3 — Three moderation factories write JSON arrays (`[]`) where the DB column defaults to a JSON object (`{}`)
    - **Where:** database/factories/Moderation/ActionLogEntryFactory.php:20, database/factories/Moderation/CaseSignalFactory.php:20, database/factories/Moderation/AuditEventFactory.php:23
    - **Affects:** Test suites only — code that reads these columns as an object shape (e.g. `jsonb_typeof()` checks, or property access) against real Postgres rows would see an `array` type instead of `object`, a divergence the SQLite test DB can't catch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `'action_target' => []` to `'action_target' => new \stdClass()` (or a JSON string `'{}'`) in `ActionLogEntryFactory`; apply the same fix to `'signal_data'` in `CaseSignalFactory` and `'payload'` in `AuditEventFactory`.
    - **Technical:** All three columns are `JSONB NOT NULL DEFAULT '{}'::JSONB` in `20260528000000_create_moderation_schema.sql`. PHP's empty array `[]` always `json_encode`s to `[]` (a JSON array), never `{}` (a JSON object) — there's no way to distinguish an empty list from an empty map in a bare PHP array. On Postgres, `jsonb_typeof('[]')` returns `'array'`; on the SQLite test DB, JSONB typing isn't enforced at all, so the mismatch is invisible in CI.
    - **Plain English:** The test data for the moderation system writes an empty checklist `[]` where the real database expects a blank form `{}`. Tests pass, but they don't accurately simulate what real database rows look like.
    - **Evidence:**
        ```php
        'action_target' => [],
        ```
        ```php
        'signal_data' => [],
        ```
        ```php
        'payload' => [],
        ```
        ```sql
        action_target   JSONB NOT NULL DEFAULT '{}'::JSONB,
        ```

- [ ] **#DINT-6** · P3 — `ShopBrand`/`ShopProduct` lack `SoftDeletes` while parent `IntegrationConnection` does — bounded, self-resolving orphan rows
    - **Where:** app/Models/Core/Site/IntegrationConnection.php:28-31, app/Models/Core/Site/ShopBrand.php:16-20, app/Models/Core/Site/ShopProduct.php:13-17, app/Observers/Core/IntegrationConnectionObserver.php:149-155
    - **Affects:** Users who disconnect and later reconnect a shop integration — the old `shop_brands`/`shop_products` rows tied to the disconnected `platform_connections` row linger, unused, until the routine 30-day purge sweep removes them. No user-facing code path ever queries these orphaned rows directly (all reads scope through the live `connection_id`), so there is no functional or privacy impact — this is pure DB bloat, not a leak.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Optional hardening: add a `IntegrationConnectionObserver::deleted()` branch that force-deletes `ShopBrand` (and cascading `ShopProduct`) rows for a disconnected `'shop'` connection, mirroring the existing Instagram `cleanupMirroredMedia()` handling in the same observer.
        - Or explicitly document this as accepted (the model is already in `PurgeSoftDeleted::PURGE_HANDLED`, so cleanup is bounded to 30 days regardless).
    - **Technical:** `IntegrationConnection::class` is confirmed in `PurgeSoftDeleted::PURGE_HANDLED` (`app/Console/Commands/PurgeSoftDeleted.php`), so a disconnected connection's `shop_brands`/`shop_products` rows ARE hard-deleted via `ON DELETE CASCADE` once the parent connection is force-deleted at day 30 — this is not an unbounded accumulation risk, contrary to the original draft's framing. `IntegrationConnectionObserver::deleted()` currently only refreshes design-preset contributions and cleans up mirrored Instagram media; it has no shop-specific branch, so between disconnect and the day-30 sweep, the old child rows are inert dead weight with no code path that reads them.
    - **Plain English:** When a user disconnects a shop, the shop's product list isn't cleaned up right away — it just sits unused until the monthly cleanup job removes it along with the disconnected shop record itself. Nothing displays or uses that leftover data in the meantime, so it's invisible clutter rather than a real problem.
    - **Evidence:**
        ```php
        class IntegrationConnection extends BaseModel
        {
            use HasUuids;
            use SoftDeletes;
        ```
        ```php
        class ShopBrand extends BaseModel
        {
            use HasUuids;

            protected $table = 'site.shop_brands';
        ```
        ```php
        public function deleted(IntegrationConnection $connection): void
        {
            // Disconnect drops this integration's contributions; affected columns
            // re-resolve to the next-best source / manual / default.
            $this->refresher->refresh($connection);
            $this->cleanupMirroredMedia($connection);
        }
        ```

- [ ] **#DINT-7** · P3 — `MenuCategory`/`MenuItem`/`MenuItemPlatform`/`MenuPlatformLink` lack `SoftDeletes` while parent `Menu` does — bounded, self-resolving orphan rows
    - **Where:** app/Models/Core/Site/Menu.php:25-28, app/Models/Core/Site/MenuCategory.php:10-18, app/Jobs/Platforms/MenuFetchJob.php:104
    - **Affects:** Users who disconnect all menu-ordering platforms and later reconnect — the old menu's categories/items/platform-availability rows linger until the routine 30-day purge sweep removes them via cascade. No code path queries these orphans directly (every rebuild scopes on the current `$menu->id`), so there is no functional impact.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Optional hardening: add a `MenuObserver::deleted()` hook that force-deletes categories/items/platform-links/item-platforms when a `Menu` is soft-deleted, consistent with the `MenuCategory` docblock's own stated intent ("rebuilt wholesale on every scrape — no soft delete").
        - Or explicitly document as accepted (the model is already in `PurgeSoftDeleted::PURGE_HANDLED`).
    - **Technical:** `Menu::class` is confirmed in `PurgeSoftDeleted::PURGE_HANDLED`, so a soft-deleted `Menu` (triggered by `MenuFetchJob::handle()` at line 104 when a user's last connected ordering platform is removed) is hard-deleted at day 30, cascading correctly to `menu_categories`/`menu_items`/`menu_platform_links`/`menu_item_platforms` (all `ON DELETE CASCADE`). Between the soft-delete and that sweep, `Menu::updateOrCreate(['user_id' => ...], ...)` on reconnect misses the trashed row (SoftDeletes global scope) and creates a fresh `Menu` with a new UUID, leaving the old children as inert rows nothing queries — not an unbounded leak, but a structural inconsistency worth closing since no observer currently exists for this model.
    - **Plain English:** If a user disconnects their online-ordering platforms and later reconnects, the old menu's dishes and categories aren't cleaned up immediately — they sit unused until the monthly cleanup job removes them along with the old menu record. Nothing on the dashboard shows or uses that leftover data in the meantime.
    - **Evidence:**
        ```php
        class Menu extends BaseModel
        {
            use HasUuids;
            use SoftDeletes;
        ```
        ```php
        // One menu category (e.g. "Mains", "Sides") under a site.menus row. Categories
        // are rebuilt wholesale on every scrape — no soft delete — so the menu always
        // mirrors the live store. `source_platform` records which platform's structure
        // this group came from (the content source).
        class MenuCategory extends BaseModel
        {
            use HasUuids;
        ```
        ```php
        if ($plan === null) {
            Menu::query()->where('user_id', $this->userId)->delete();

            return;
        }
        ```

- [ ] **#DINT-8** · P3 — Ten post-baseline tables are missing `BEFORE UPDATE` triggers for `updated_at`
    - **Where:** supabase/migrations/20260701150000_create_workplaces.sql, supabase/migrations/20260617130000_create_menus.sql, supabase/migrations/20260619050000_menu_relational_redesign.sql, supabase/migrations/20260701130000_design_kit_contributions.sql, supabase/migrations/20260701140000_menu_platform_links.sql, supabase/migrations/20260701140100_menu_item_platforms_table.sql, supabase/migrations/20260704160000_shop_brands_products.sql, supabase/migrations/20260705150200_create_content_selection.sql
    - **Affects:** Direct SQL writes (migrations, admin operations, future data scripts) on `site.workplaces`, `site.menus`, `site.menu_categories`, `site.menu_items`, `site.menu_platform_links`, `site.menu_item_platforms`, `site.design_kit_contributions`, `site.shop_brands`, `site.shop_products`, `site.content_selection`. Eloquent-driven writes still update the column correctly (set in PHP by `BaseModel`), so this only bites raw SQL / `DB::table()->update()` paths.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `DROP TRIGGER IF EXISTS ... / CREATE TRIGGER set_timestamp_<table> BEFORE UPDATE ON <table> FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();` for each of the ten tables, matching the pattern already used for `site.design_kits` (20260603000003) and `site.platform_connections` (20260624010000).
        - For the tables whose `updated_at` is nullable with no column default (`site.workplaces`, `site.menus`, `site.menu_categories`, `site.menu_items`, `site.menu_platform_links`, `site.menu_item_platforms`), also add `ALTER COLUMN updated_at SET DEFAULT now()`.
    - **Technical:** The established Partna convention is that every timestamped table gets a `BEFORE UPDATE` trigger wired to `public.set_updated_at()` as defense-in-depth against raw SQL bypassing Eloquent. Ten post-baseline tables across the menu, shop, design-kit-contribution, workplace, and content-selection subsystems were created without this trigger; `site.design_kit_contributions` additionally already has `NOT NULL DEFAULT now()` on both columns but still lacks the trigger, so an `UPDATE` via raw SQL leaves `updated_at` frozen at insert time.
    - **Plain English:** Most tables in the system have an automatic clock that stamps "last changed" any time a row is modified, no matter who makes the change. Ten newer tables are missing this automatic stamp — application code updates the timestamp itself today, but a future direct database fix or admin script would silently leave a stale timestamp.
    - **Evidence:**
        ```sql
        -- site.workplaces (20260701150000) — nullable, no default, no trigger
        CREATE TABLE IF NOT EXISTS site.workplaces (
            ...
            created_at       timestamptz,
            updated_at       timestamptz
        );
        ```
        ```sql
        -- site.menu_categories (20260619050000) — same gap
        CREATE TABLE IF NOT EXISTS site.menu_categories (
            id              uuid PRIMARY KEY,
            menu_id         uuid NOT NULL REFERENCES site.menus (id) ON DELETE CASCADE,
            name            text NOT NULL,
            position        integer NOT NULL DEFAULT 0,
            source_platform text CHECK (source_platform IN ('uber-eats', 'doordash')),
            created_at      timestamptz,
            updated_at      timestamptz
        );
        ```
        ```sql
        -- The correct pattern, from 20260603000003 (site.design_kits):
        DROP TRIGGER IF EXISTS set_timestamp_design_kits ON site.design_kits;
        CREATE TRIGGER set_timestamp_design_kits
            BEFORE UPDATE ON site.design_kits
            FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Soft-delete lifecycle cleanup gaps:** #DINT-1, #DINT-2, #DINT-3, #DINT-6, #DINT-7
    - **Why grouped:** Same root-cause pattern — data associated with a soft-deleted parent (a picked photo, a media file, a workplace card, a shop's products, a menu's dishes) isn't actively cleaned up/redacted at soft-delete time; cleanup is left entirely to the eventual day-30 purge sweep. All fixes are pure application code (models/observers/services) with no DB migration required.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Moderation factory JSON-shape parity:** #DINT-5
    - **Why grouped:** Isolated single-file-family fix (three factory files), no shared subsystem with the other bundle.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#DINT-4 — `failed_jobs.failed_at` timezone type change** · DB migration/schema change (`ALTER COLUMN ... TYPE timestamptz`).
- **#DINT-8 — Ten tables missing `BEFORE UPDATE` triggers** · DB migration/schema change across ten tables (`CREATE TRIGGER` × 10 plus column-default alterations).
