- [ ] **#CACHE-1** · P3 — Missing cache on section‑block dashboard read
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSectionBlockController.php:88-92
    - **Affects:** Every brand/admin loading the Sections management page; repeated DB hits for per‑professional section list.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the section‑block query in `CacheLockService::rememberLocked` with a per‑professional cache key and a 60 s TTL (±20 % jitter).
        - Invalidate the key on every save/reorder/destroy that touches section blocks (already covered by `SiteCacheService::invalidateSite` if the key is scoped under the site).
    - **Technical:** The `index()` method directly hits Postgres on every dashboard page load. At target scale (30 brands × ~50 affiliates) this is moderate, but adding a short‑lived cache with single‑flight lock removes load from repeated admin polling without any staleness risk because the observer already fires `SiteCacheService::invalidateSite` on writes.
    - **Plain English:** Every time a brand owner opens the “Sections” tab, the system reads the list of sections from the database. Adding a short memory cache (like a sticky note that lasts 60 seconds) removes those repeated lookups and makes the page faster, especially if they click around a lot.
    - **Evidence:**
        ```php
        $allSectionBlocks = $pro->sectionBlocks()
            ->where('site_id', $site->id)
            ->orderBy('sort_order')
            ->get();
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CACHE-2** · P3 — Missing cache on service‑category dashboard read
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php:48-50
    - **Affects:** Every brand/admin loading the Services / Categories management page; repeated per‑professional queries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Cache the category list with `CacheLockService::rememberLocked` using a per‑professional key and a 60 s TTL.
        - Invalidate the cache on any store/update/destroy/reorder of categories (can reuse the same touch‑site invalidation chain through `SiteCacheService::invalidateSite` since categories are site‑scoped).
    - **Technical:** The `index()` method runs a direct `ServiceCategory::where('professional_id', …)` query on every page load. While the total number of categories per professional is small, caching removes the round‑trip for admin page loads that happen in bursts (clicking between tabs). The existing cache‑invalidation hooks in the Site observer cover this once the key is registered under the site.
    - **Plain English:** Like the Sections list, the Categories list is fetched from the database every time the Services page opens. A 60‑second cache reduces that so the user sees the page right away, and any changes automatically push a fresh copy.
    - **Evidence:**
        ```php
        $categories = $q->orderBy('sort_order')->orderBy('created_at')->get();
        ```
    - `[DRAFT, confidence: 0.8]`
