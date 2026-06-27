`★ Insight ─────────────────────────────────────`
**STRIP-1 is a complete hallucination**: 9 policy files exist and AppServiceProvider registers 20+ `Gate::policy()` entries covering all major models. **STRIP-2's pending-deletion concern is already handled**: `EnforcePendingDeletionReadOnly` middleware is applied at the route group level to all professional routes. The `authorizeCustomLinks` no-op is an accurate comment — individual users have no capability gate needed. Both findings should be dropped before they waste engineering time.
`─────────────────────────────────────────────────`

# Strip / Standalone-User-Only Audit — 2026-05-22

**Branch:** strip/standalone-user-only
**Lens:** dead code, broken references, and consistency gaps after the standalone-user strip
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/PublicSiteController.php
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
- app/Http/Controllers/Api/PublicSite/PublicConfigController.php
- app/Http/Controllers/Api/PublicSite/BootstrapController.php
- app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php
- app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSectionBlockController.php
- app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php
- app/Policies/* (verified via Glob)
- app/Providers/AppServiceProvider.php (verified via Grep)
- config/partna.php (verified via Grep)

**Dropped findings:**
- **STRIP-1** (P0 — missing policies): Hallucinated. `Glob app/Policies/*.php` reveals 9 policy classes; `AppServiceProvider::boot()` contains 20+ `Gate::policy()` registrations covering every model the controllers authorise against. No finding.
- **STRIP-2** (P1 — missing `authorizeForUser` on section/link write paths): `EnforcePendingDeletionReadOnly` is applied at the route-group level to all professional routes (`routes/api/professional.php:32`), closing the pending-deletion gap. Ownership is enforced via `where('professional_id', $pro->id)` query scoping on every section/link mutation. The `authorizeCustomLinks` no-op is an accurate comment — no capability gate is needed for individual users. No finding.
- **STRIP-6** (P3 — `integrations` endpoint Hydrogen reference): Draft confidence 0.6 < 0.7 threshold; Google Maps key may still serve address lookup on individual profiles; cannot confirm removal without frontend source. Dropped per precision rule.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **STRIP-4** · P2 — `'shop'` and `'booking'` remain in `section_block_types` config and analytics defaults, creating phantom DB rows and misleading operator reports
    - **Where:** config/partna.php:621 · app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php:291–294 · app/Http/Controllers/Api/PublicSite/AnalyticsController.php (click handler's `$trackableSectionTypes`)
    - **Affects:** Every professional's site — `syncAllowedSections` will insert `shop` and `booking` section block rows for every account on first dashboard load. Analytics `top_sections` reporting will attempt joins against these phantom rows. Staff ops dashboards using section analytics will silently undercount trackable clicks.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'shop'` and `'booking'` from `config/partna.php` line 621's `section_block_types` array.
        - Update the hardcoded fallback default in `ProfessionalAnalyticsController::summary` to match (remove `'shop'`, `'booking'`).
        - Update the identical hardcoded fallback in `AnalyticsController::click` to match.
        - Update the match-expression title map in `ProfessionalAnalyticsController` (the `'shop' => 'Shop'`, `'booking' => 'Booking'` arms are now dead).
        - Write a one-off migration to soft-delete or deactivate any `shop`/`booking` section block rows already seeded for existing professionals.
    - **Technical:** `ProfessionalSectionBlockController::syncAllowedSections` reads `config('partna.section_block_types')` and inserts a block row for every type that has no row. With `shop` and `booking` still present, every professional's first dashboard GET triggers two phantom inserts into `site.blocks`. The analytics ingest side (`AnalyticsController::click`) also uses the same config to determine which section types are "trackable" — leaving shop/booking there means the ingest controller accepts click events for block_type='shop' or 'booking', which can never produce rows after the commerce removal, causing silent 404s for any legacy frontend still emitting those events.
    - **Plain English:** Think of it like a restaurant menu that still lists "Steak" and "Fish" even after the kitchen stopped cooking them. Customers keep asking for those dishes (phantom database entries get created every time someone opens the dashboard), the kitchen checks the fridge every time (wasted database queries), and the end-of-night report shows zero orders for Steak and Fish — making it look like a feature is live but broken rather than simply gone. Removing the items from the menu stops the wasted work.
    - **Evidence:**
        ```php
        // config/partna.php:621
        'section_block_types' => ['gallery', 'services', 'shop', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter', 'countdown', 'contact', 'credentials', 'experience', 'bio'],
        ```
        ```php
        // ProfessionalAnalyticsController::summary (hardcoded fallback)
        $trackableSectionTypes = collect(config('partna.section_block_types', [
            'gallery', 'services', 'shop', 'booking',
        ]))
        ```
        ```php
        // AnalyticsController::click (identical hardcoded fallback)
        $trackableSectionTypes = collect(config('partna.section_block_types', [
            'gallery',
            'services',
            'shop',
            'booking',
        ]))
        ```

---

## P3 — Nice to have

- [ ] **STRIP-3** · P3 — PHPDoc and inline comments reference removed brand, Hydrogen, and affiliate concepts
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicSiteController.php:12 · app/Http/Controllers/Api/PublicSite/IndividualProfileController.php (docblock) · app/Http/Controllers/Api/PublicSite/PublicConfigController.php:docblock + `integrations()` · app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php:215 · app/Http/Controllers/Api/PublicSite/BootstrapController.php (class-level comment no longer exists but controller comment references brand)
    - **Affects:** Developer comprehension — future contributors (or the audit orchestrator's fix sessions) will be misled about whether Hydrogen, affiliate dashboards, or multi-account types are live concerns.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `PublicSiteController.php`: Replace "Hydrogen storefronts fetch brand config via separate Storefront API endpoint" with accurate description of the Cloudflare Worker + Astro path.
        - `IndividualProfileController.php` docblock: Remove "Professional is a brand (served by Hydrogen at <handle>.partna.au)" and "brand-fallback content" references; update the comment to reflect individual-only architecture.
        - `PublicConfigController.php`: Replace "Used by the affiliate dashboard and brand dashboard" with "Used by the professional dashboard"; remove "Hydrogen checkout form → Google Places Autocomplete" from `integrations()` docblock and replace with accurate current consumer (or "consumer TBD post-commerce removal").
        - `ProfessionalDocumentController.php`: Remove "Mirrors BrandGalleryController" from the `store()` comment (commit `281b1676` removed BrandGalleryController).
    - **Technical:** Strip commit `797fff2e` deleted the Brand domain and `281b1676` rewrote docs; neither pass cleaned up cross-references inside PHPDoc comments. These are not runtime errors, but they increase the cognitive cost of every future PR that touches these files and will cause confusion if the audit orchestrator references these comments when deciding what to fix.
    - **Plain English:** After renovating a building, someone forgot to update the directory sign in the lobby. It still lists offices for tenants who moved out months ago. Visitors waste time looking for departments that no longer exist. These comments are that lobby directory — a quick edit to say what the rooms actually contain now.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/PublicSite/PublicSiteController.php
        // V2: Serves published mini-site data by subdomain (cached, 95% of traffic). Hydrogen storefronts fetch brand config via separate Storefront API endpoint.
        ```
        ```php
        /**
         * §28.8 — Public profile API for individual professionals.
         * ...
         * 404 rules (audit: avoid existence leak on public endpoints):
         *   - Handle not found
         *   - Professional is a brand   (served by Hydrogen at <handle>.partna.au)
         *   - Professional is a partner (Worker 301s to <brand>.partna.au/<handle>)
         */
        // app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
        ```
        ```php
        /**
         * Public, unauthenticated endpoints that expose static frontend-facing config.
         *
         * Used by the affiliate dashboard and brand dashboard to drive UI affordances ...
         * ...
         * Current consumers:
         *   - Hydrogen checkout form → Google Places Autocomplete for addresses.
         */
        // app/Http/Controllers/Api/PublicSite/PublicConfigController.php
        ```
        ```php
        // Master Pattern 16 (DB-E#SCALE-1): ... Mirrors BrandGalleryController.
        // app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php
        ```

- [ ] **STRIP-5** · P3 — `StaffStatsController` groups by `account_type` which will always return a single `'individual'` bucket
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php:55–60
    - **Affects:** Staff ops dashboard — the `by_account_type` breakdown in the response always contains one key (`individual`) and provides no differentiation. The GROUP BY adds a pointless query step and the class-level comment ("Counts by account_type") is misleading.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `selectRaw`/`groupByRaw` with a simple `count('*')`.
        - Change the response shape from `{total: N, by_account_type: {individual: N}}` to `{total: N}`.
        - Update the class-level comment from "Counts by account_type" to reflect the flattened shape.
        - Coordinate with any staff frontend that renders the `by_account_type` breakdown (if it exists).
    - **Technical:** `COALESCE(account_type::text, 'unknown')` and the GROUP BY clause exist to bucket brand/partner/individual counts. Post-strip, `account_type` is always `'individual'` (enforced by `AccountType::Individual` enum having one case). The query still compiles and runs correctly, but the GROUP BY is semantically dead: `array_sum($accountTypeCounts)` equals `$accountTypeCounts['individual']` every time.
    - **Plain English:** A weekly sales report has a column for "Sales by region" that will now forever show only one region. The extra column takes time to generate, takes up space in the report, and makes the report harder to read — all without adding any information. Collapsing it to a single total number is cleaner and more honest about what the system actually tracks.
    - **Evidence:**
        ```php
        // StaffStatsController::buildPayload
        $accountTypeCounts = DB::table('core.users')
            ->whereNull('deleted_at')
            ->selectRaw("COALESCE(account_type::text, 'unknown') as account_type, count(*) as total")
            ->groupByRaw("COALESCE(account_type::text, 'unknown')")
            ->pluck('total', 'account_type')
            ->all();
        ```
