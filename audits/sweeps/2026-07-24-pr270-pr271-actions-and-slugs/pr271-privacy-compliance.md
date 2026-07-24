# Privacy & Data-Rights Compliance Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Privacy & data-rights compliance — PII inventory, export/delete completeness, retention enforcement, processor flows (chunks: rights-machinery, console-mail, schema-pii)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Console/Commands/BackfillItemSlugs.php
- supabase/migrations/20260724120000_create_item_slugs.sql
- (cross-checked against) app/Services/User/DataExport/DataExportPayloadBuilder.php, app/Services/User/AccountDeletionService.php, app/Services/Platforms/Payloads/GoogleBusinessPayload.php, app/Services/Platforms/DisplaySettingsFilter.php, app/Services/Site/ItemSlugAllocator.php, app/Observers/MenuItemObserver.php, routes/console.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **PRIV-1** · P2 — Retired item-url slugs (`site.item_slugs`) accumulate forever with no retention column or purge job
    - **Where:** supabase/migrations/20260724120000_create_item_slugs.sql:31-44; no corresponding entry in routes/console.php
    - **Affects:** Every professional who renames a menu item or synced event more than once — each rename mints a permanent `is_current = false` row derived from the item's name
    - **Effort:** M (~2–4h) — schema change required, see Standalone note below
    - **What to do:**
        - Add a `retired_at timestamptz` column, stamped by `ItemSlugAllocator::demoteExcept()`/`promote()` at the moment a row stops being current (today the table only has `created_at`, which records mint time, not retirement time — insufficient to age out retired rows accurately).
        - Add `config('partna.item_slug_retention_days')` (e.g. 180d — long enough that a bookmarked/shared old-name link keeps working through a reasonable grace window) and a scheduled `slugs:prune-retired-item-slugs` command wired into `routes/console.php` alongside the other daily/weekly sweeps (`handles:prune-expired-aliases`, `gdpr:prune-completed-exports`, etc.).
        - Log the purge count on each run, matching the pattern every other retention job in `routes/console.php` already follows.
    - **Technical:** `site.item_slugs` was added 2026-07-24 (migration `20260724120000_create_item_slugs.sql`, part of the same commit series as `3c152ca9`/`eee84edd`/`b66b5ee7`) specifically to keep old name-derived URLs alive as 301 redirect targets. The table comment states retired rows (`is_current = false`) are "kept as 301 redirect targets" with no stated lifespan, and the schema has no `expires_at`/`retired_at` column. I confirmed via `routes/console.php` (which already enforces every other declared retention window — soft-delete purge, 90-day raw analytics, 7-year handle audit, 30-day export artifacts, etc.) that no scheduled command touches `item_slugs` at all; the only removal path is the table's `ON DELETE CASCADE` to `core.users`, which only fires on full account hard-deletion. A professional who renames dishes/events regularly over years accumulates an unbounded, permanent history of every name they've ever used — names that can embed personal information (a customer's name in an event title, a family member's name in a menu item). This is a genuine gap in the retention ledger, not a security bug: the data serves a real purpose (redirects) for a bounded window, but nothing bounds that window today.
    - **Plain English:** When a professional renames a menu item or event, we keep the old web address alive so anyone who bookmarked or shared it still lands on the right page — that's a good feature. But those old addresses, and the personal names or details they were built from, are never cleaned up; they pile up forever with no expiry date. If someone renames things often over several years, we end up permanently hoarding a full history of every name they ever used. The fix is to give old redirects a reasonable shelf life (a few months) and add an automatic cleanup job, the same way we already do for old handles and old analytics data.
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

        COMMENT ON TABLE site.item_slugs IS
          'Per-profile human-readable URL slug registry for public sitepage detail items (events + menu items). is_current=false rows are retired slugs kept as 301 redirect targets. Owned by App\Services\Site\ItemSlugAllocator.';
        ```

- [ ] **PRIV-2** · P2 — Google Business reviewer PII (name, photo, review text) is republished on the public CDN-cached sitepage by default for every claimed connection, while the identical data is deliberately stripped for provisional (pre-claim) builds
    - **Where:** app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php:128-130 (`reviews` in the public allowlist); app/Services/Platforms/DisplaySettingsFilter.php:31-34,65-67,104 (the `reviews` toggle defaults ON); app/Services/Platforms/Payloads/GoogleBusinessPayload.php:96-127 (`stripThirdPartyPii()`, provisional-only)
    - **Affects:** Third-party Google reviewers — customers of the professional — whose name, profile photo, and review text get served to every unauthenticated visitor of a claimed professional's sitepage, CDN-cached, unless the professional manually opts out
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Make a deliberate product/legal call on whether claimed-connection `reviews` should carry reviewer identity fields (name/photo/text) at all, or whether `GoogleBusinessPayload::stripThirdPartyPii()` — currently scoped to provisional builds only — should also apply post-claim.
        - If reviewer identity is kept, flip the `reviews` toggle's default to OFF (opt-in) in `DisplaySettingsFilter::TOGGLE_DEFAULTS`, matching the existing opt-in precedent already used for `bandcamp.show_all_releases` in the same class, instead of today's opt-out default.
        - Document the resulting data-flow (professional's Google Business reviews cached and served from Partna's own CDN) as a second-subject processing relationship, and confirm the DSAR export path treats `reviews` as the professional's business record, not a bulk PII export vector.
    - **Technical:** `GoogleBusinessPayload::stripThirdPartyPii()` already exists and is explicitly labelled a PII fix (its own docblock says "PRIV-1: strip third-party reviewer PII... drops `reviews` entirely (author name/uri/photo/text)"), proving the team has already recognised reviewer identity data as sensitive. But that method's docblock also states it runs "ONLY on the provisional (pre-claim) write paths... The authenticated connect() / GoogleBusinessEnrichJob / refresh-for-claimed-user paths never call this and keep the full payload." Once a professional claims their account and connects Google Business through the normal authenticated flow, the full `reviews` array (with reviewer names/photos/text) is stored and is in `PublicIntegrationConnectionResource::ALLOWLIST['google-business']`, so it ships to the public, CDN-cached sitepage. `DisplaySettingsFilter::TOGGLE_DEFAULTS` has no entry for `google-business`, so `reviews` defaults to visible (`$default = ... ?? true`) — a professional who never touches the toggle is republishing customers' identities and free-text reviews to a platform those customers never agreed to, by default. This is the canonical APP 6 secondary-purpose gap the lens calls out, and the existing provisional-build fix shows the platform already agrees reviewer PII needs handling — it just stops short of covering the far more common claimed-account path.
    - **Plain English:** Picture a customer leaving a Google review for a hairdresser — their name, photo, and comment. The hairdresser connects their Google Business page to their Partna site. That customer's name, face, and words now appear on a completely different website they've never heard of, cached on servers around the world. The system already noticed this problem and quietly fixed it for brand-new, not-yet-claimed sites — but the fix was never extended to real, live professional accounts, which is the vast majority of the platform. The reviewer never had a say in this, and right now the setting to hide it defaults to "on display," not "hidden." This is worth a real decision — either stop showing reviewer identities by default, or document clearly why it's acceptable to keep doing so.
    - **Evidence:**
        ```php
        // google-business: placeId / phoneIntl / priceLevel / priceRange /
        // detailsFetchedAt stay private. photos now public for home bg.
        'google-business' => ['url', 'name', 'address', 'lat', 'lng', 'rating', 'reviewCount', 'businessStatus', 'category', 'phone', 'website', 'hours', 'links', 'reviews', 'reviewSummary', 'editorialSummary', 'amenities', 'photos'],
        ```
        ```php
        /**
         * PRIV-1: strip third-party reviewer PII from a mapped/stored payload —
         * drops `reviews` entirely (author name/uri/photo/text) and `authors`
         * from each `photos[]` entry (contributor display names) — while keeping
         * every other field ...
         *
         * Used ONLY on the provisional (pre-claim) write paths —
         * GoogleBusinessSourceGenerator's initial build and GoogleBusinessFetch's
         * refresh for an unclaimed owner. The authenticated connect() /
         * GoogleBusinessEnrichJob / refresh-for-claimed-user paths never call
         * this and keep the full payload.
         */
        ```

## Suggested Bundled Sessions

None — both surviving findings sit in unrelated subsystems (item-slug redirects vs. platform-connection review data) with no shared file or root cause to bundle.

## Standalone — do NOT bundle

- **PRIV-1 — Retired item-url slugs accumulate with no retention** · requires a `supabase/migrations/` schema change (new `retired_at` column) — DB migration, always standalone.
- **PRIV-2 — Google reviewer PII republished by default** · requires a product/legal decision (default review-display behaviour, APP 6 secondary-purpose basis) before any code change — not a mechanical fix, needs sign-off first.
