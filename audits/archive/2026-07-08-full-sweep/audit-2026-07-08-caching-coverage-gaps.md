# Caching Coverage Gaps Audit — 2026-07-08

**Branch:** development
**Lens:** Caching coverage gaps: hot, expensive reads with no cache at all
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Services/Site`
- `app/Services/PublicSite`
- `app/Services/Accounts`
- `app/Services/Cache`
- `app/Http/Middleware`
- `app/Http/Controllers/Api/PublicSite`
- `app/Services/Streaming`
- `app/Services/Platforms`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#CCG-1** · P2 — `PublicSiteResolver::resolvePublishedSite()` is uncached, but three public POST endpoints call it on every request
    - **Where:** app/Services/PublicSite/PublicSiteResolver.php:19-62 (called from app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php:63, PublicEnquiryController.php:66, PublicEmailSubscriptionController.php:76)
    - **Affects:** Every public lead-capture, contact-enquiry, and newsletter-signup submission across every professional's site — an unauthenticated, bot-reachable write surface with no cache layer at all between it and Postgres.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Inject `CacheLockService` into `PublicSiteResolver` and wrap the site-resolution logic in `rememberLocked()`, keyed by `CacheKeyGenerator::publicSite($subdomain)` — this key is already defined but currently dead code (no caller anywhere in the repo).
        - Cache the whole `{site, alias_hit, canonical_subdomain}` return array. `rememberLocked`'s "callback must not return null" contract is already satisfied: the method always returns a non-null array (even the not-found case is `['site' => null, ...]`), so no `rememberLockedNullable`/sentinel handling is needed.
        - Use a short TTL (30–60s). `rememberLocked` already applies ±20% jitter and writes a `:stale` SWR twin internally (see `CacheLockService::writeWithJitter`) — do **not** hand-roll a second stale key, DeepSeek's draft proposal duplicates functionality the helper already provides.
        - Bust the new key from `SiteCacheService::invalidateSitePayload()` alongside the existing subdomain-keyed busts (including the old subdomain on a rename) — that method already enumerates exactly this case for `publicSitePayload()` and `handleResolve()`, so the new key slots into the same list.
    - **Technical:** `resolvePublishedSite()` runs a `Site` query with `->with('user')->whereHas('user', ...)` (a correlated-subquery gate plus a separate eager-load query for the relation — two round-trips on a direct hit, up to four on an alias hit) with zero cache in front of it. This is a structurally different code path from `PublicSiteController`, which resolves the same kind of subdomain→site mapping through the cached, single-flight `SiteCacheService::getPublicSitePayload()`. `PublicCustomerLeadController`, `PublicEnquiryController`, and `PublicEmailSubscriptionController` all call the resolver directly on every POST, so the same DB work DeepSeek's siblings correctly identified as cached on the read path is recomputed uncached on the write path. `CacheKeyGenerator::publicSite(string $subdomain)` already exists for exactly this purpose and is unused — confirmed via repo-wide grep, no caller references it anywhere outside its own definition.
    - **Plain English:** Every time someone fills out a contact form, joins a newsletter, or submits a lead on a professional's page, the system re-looks-up which professional owns that web address from scratch — checking the main listing, then (if renamed) the forwarding record, then re-verifying the professional is still active. That lookup already has a fast "sticky note" shortcut built for the page-view side of the site, but nobody wired the form-submission side up to use it. A short-lived cached answer (refreshed automatically every 30-60 seconds) would handle the vast majority of these without extra database work, while still catching a professional publishing or renaming their site quickly.
    - **Evidence:**
        ```php
        // PublicSiteResolver::resolvePublishedSite — no cache, called directly from three form controllers
        $siteQuery = Site::query()
            ->where('is_published', true)
            ->with('user')
            ->whereHas('user', function ($q) {
                $q->where('status', 'active');
            });

        $site = (clone $siteQuery)
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();
        ```
        ```php
        // PublicCustomerLeadController::store (identical pattern in PublicEnquiryController::submit
        // and PublicEmailSubscriptionController::subscribe)
        $site = $resolver->resolvePublishedSite($subdomain)['site'];
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Public form-submission site-resolution cache:** #CCG-1
    - **Why grouped:** single finding; the fix is self-contained to `PublicSiteResolver` + `SiteCacheService::invalidateSitePayload()`.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
