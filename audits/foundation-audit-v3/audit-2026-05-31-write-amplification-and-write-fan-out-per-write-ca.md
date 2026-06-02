Good — I now have the full picture:
- `invalidateUser()` has **5 call sites**: `ServiceObserver`, `UserObserver` (×3), `LoadCurrentUser` middleware (email-sync path), `UserBootstrapService`. The middleware and bootstrap callers do NOT call `touch()` after, so they need the `invalidateSite()` catch-all. Only `ServiceObserver` unconditionally pairs `invalidateUser()` with `touch()`.
- `SectionVisibilityService::reevaluateEnabled` confirmed at line 378.
- `LoadCurrentUser` calls `invalidateUser()` manually after `$professional->save()` — the `save()` already fired `UserObserver::updated` which called `invalidateUser()`, so there's actually a third redundant path there, but it's out of scope for this lens.

`★ Insight ─────────────────────────────────────`
- The "conservative catch-all" comment in `invalidateUser()` was written from UserObserver's perspective (where touch is conditional on `PUBLIC_PROFILE_USER_FIELDS`), but ServiceObserver's unconditional `touch()` breaks that assumption — the same comment simultaneously justifies the catch-all and obscures the double-bust in the ServiceObserver path.
- All five DeepSeek findings have evidence that is verbatim-verifiable from the provided source. Tiers need re-calibration: WAMP-1 and WAMP-2 are efficiency issues (data is over-busted, not under-busted; no user-facing bad behavior) → P2, not P1. The threshold "ships bad behavior in known scenarios" is not met.
- The `ShouldBeUnique` guards on `CloudflareCachePurgeJob` (120s) and `WarmPublicSiteCacheJob` (120s) already prevent external API amplification. The unmitigated problem is the Redis DEL amplification only.
`─────────────────────────────────────────────────`

# Write Amplification & Cache Fan-Out Audit — 2026-05-31

**Branch:** development
**Lens:** Write amplification and write fan-out, per-write cache busting, per-write KV sync, observer cascades multiplying work, rebuild storms, cost of a single dashboard edit at 10k sites
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Observers/Core/ServiceObserver.php
- app/Observers/Core/ServiceCategoryObserver.php
- app/Observers/Core/SiteObserver.php
- app/Observers/Core/BlockObserver.php
- app/Observers/Core/SiteMediaObserver.php
- app/Observers/User/UserObserver.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/UserCacheService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/CacheLockService.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#WAMP-1** · P2 — ServiceCategoryObserver's touch() cascade defeats its own fine-grained key optimization
    - **Where:** app/Observers/Core/ServiceCategoryObserver.php:bust()
    - **Affects:** Category renames, reorders, and deletes — the explicit optimization to spare 13+ keys is partially undone, with `professionalModel` + `:stale` and `emailBrand` + `:stale` evicted as collateral damage even though neither is stale after a category change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - The comment is correct that calling `invalidateUser()` is too broad; the problem is that `touch()` was chosen as a mechanism to fire `SiteObserver` (for payload + CF purge), but `SiteObserver::saved` now calls `invalidateSite()`, which has grown to include `emailBrand` (added in commit `a0a35444`) and `professionalModel`.
        - Replace the `$pro?->site?->touch()` call with: (1) a direct call to `SiteCacheService::invalidateSite($pro->site)` for the Redis bust, plus (2) an explicit `CloudflareCachePurgeJob::dispatch($pro->site->subdomain)` for the edge purge. This gives precise control over what fires instead of delegating to the touch cascade.
        - If the "call `invalidateSite()` + dispatch CF purge directly" pattern would be reused by other callers, consider extracting a `SiteCacheService::invalidateSiteAndPurgeEdge()` helper that both ServiceCategoryObserver and any future scoped-bust callers can share.
        - Remove the comment claiming only services keys are stale — after the fix, the code should be honest about the scope of keys it actually busts.
    - **Technical:** `ServiceCategoryObserver::bust()` deliberately deletes only 4 keys (professionalDashboardServices + professionalServices, primary + stale) to avoid the 13+-key sweep of `invalidateUser()`. The comment records the rationale. It then calls `$pro?->site?->touch()` to propagate the category rename to the public site cache via SiteObserver. `SiteObserver::saved` calls `SiteCacheService::invalidateSite()`, which since commit `a0a35444` busts `emailBrand + :stale` (2 keys) and since the auth-path model caching work busts `professionalModel + :stale` (2 keys) — neither is stale after a category rename. The site payload and block keys that `invalidateSite()` busts ARE legitimately stale (category titles embed in the services JSON via `SitepageDataResolverService::buildServicesData`). Replacing `touch()` with a direct service call separates "what Redis keys to bust" from "fire a saved event" and prevents future additions to `invalidateSite()` from silently expanding the blast radius of category changes.
    - **Plain English:** The code explicitly says "I'll only clean the four shelves that got dusty." Then it rings the building's main cleaning bell, and the cleaning crew that shows up has a checklist that was recently updated to include two additional shelves — ones the code was specifically trying to skip. The four-shelf plan is still better than cleaning all seventeen, but two of the thirteen you were protecting now get wiped for no reason. The fix is to stop using the bell and instead call the two cleaning crews directly: one for the pages that are stale, one to freshen the front door. That way a future update to the crew's checklist can't silently expand what gets wiped.
    - **Evidence:**
        ```php
        // ServiceCategoryObserver::bust — manually deletes 4 keys to avoid invalidateUser(),
        // then calls touch() which fires SiteObserver → invalidateSite(), which busts
        // professionalModel + emailBrand as collateral (both added to invalidateSite() after this
        // optimization was written).
        try {
            Cache::deleteMultiple([
                CacheKeyGenerator::professionalDashboardServices($userId),
                CacheKeyGenerator::professionalDashboardServices($userId).':stale',
                CacheKeyGenerator::professionalServices($userId),
                CacheKeyGenerator::professionalServices($userId).':stale',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Services cache bust failed on ServiceCategory change', [
                'category_id' => $category->id,
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $pro = User::query()->with('site')->find($userId);
            $pro?->site?->touch();
        } catch (\Throwable $e) {
            Log::warning('Site touch failed on ServiceCategory change', [
                'category_id' => $category->id,
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }
        ```

---

- [ ] **#WAMP-2** · P2 — UserObserver double-invalidates site cache when public profile fields change
    - **Where:** app/Observers/User/UserObserver.php:updated(), app/Services/Cache/UserCacheService.php:invalidateUser()
    - **Affects:** Every professional updating display name, bio, about, handle, first name, or last name — approximately 29 Redis DELs fire twice in sequence against the same keys.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `bool $bustSite = true` parameter to `UserCacheService::invalidateUser()` and gate the `invalidateSite()` tail call on it. Default stays `true` so the five other call sites (`UserObserver::deleted`, `UserObserver::restored`, `LoadCurrentUser`, `UserBootstrapService`) retain current behaviour without modification.
        - In `UserObserver::updated`, evaluate `wasChanged(PUBLIC_PROFILE_USER_FIELDS)` once before calling `invalidateUser`: pass `bustSite: false` when `$publicFieldChanged` is true (because the subsequent `touchParentSiteIfPublicFieldChanged` will handle the site bust via SiteObserver), and pass the default `bustSite: true` when it is false (because no touch will follow and the catch-all is needed for non-public-field changes).
        - Update the comment in `invalidateUser()` — the current text says "not redundant with UserObserver's touch" but does not acknowledge the double-bust on the public-field path. After the fix, document both paths explicitly.
    - **Technical:** `UserObserver::updated` calls `$this->userCache->invalidateUser($professional)` unconditionally. At the tail of `invalidateUser()`, a "conservative catch-all" calls `app(SiteCacheService::class)->invalidateSite($professional->site)`, sweeping ~29 Redis keys (payload+stale, two block groups+stale, images, emailBrand+stale, 18 image-view variants, professionalModel+stale). When any `PUBLIC_PROFILE_USER_FIELDS` column changed, `touchParentSiteIfPublicFieldChanged` fires, calling `$professional->site?->touch()`. `SiteObserver::saved` then calls `invalidateSite()` again — the same ~29 keys a second time. The existing comment correctly notes the catch-all is "not redundant with UserObserver's touch, which only fires for PUBLIC_PROFILE_USER_FIELDS" — this is true for non-public changes — but it does not acknowledge the redundancy on the public-field path itself. `ShouldBeUnique` on `CloudflareCachePurgeJob` (120s uniqueness window) prevents CF API duplication; the Redis DEL amplification is unmitigated.
    - **Plain English:** When a professional changes their bio or display name, two separate processes each go and clear the same set of cached pages. They both work from the same list, in the same order, seconds apart. The site ends up correctly refreshed — it was refreshed twice. It's like two coffee-shop employees each going to the window to flip the "Open" sign in the morning: harmless, but one of them is making an unnecessary trip. The fix is straightforward: let the one who always walks past the window do it, and tell the other to skip it when that person is already going.
    - **Evidence:**
        ```php
        // UserObserver::updated — invalidateUser (→ invalidateSite at its tail), then
        // touchParentSiteIfPublicFieldChanged (→ touch → SiteObserver → invalidateSite again).
        public function updated(User $professional): void
        {
            try {
                $this->userCache->invalidateUser($professional);
            } catch (\Throwable $e) {
                Log::warning('Professional cache invalidation failed on update', $this->logContext(__METHOD__, [
                    'user_id' => $professional->id,
                    'message' => $e->getMessage(),
                ]));
            }

            $this->touchParentSiteIfPublicFieldChanged($professional);
            // ...
        }

        // touchParentSiteIfPublicFieldChanged → site.touch() → SiteObserver::saved → second invalidateSite()
        private function touchParentSiteIfPublicFieldChanged(User $professional): void
        {
            if (! $professional->wasChanged(self::PUBLIC_PROFILE_USER_FIELDS)) {
                return;
            }
            try {
                $professional->site?->touch();
            } catch (\Throwable $e) { ... }
        }

        // UserCacheService::invalidateUser — tail catch-all fires even when touch() will also fire it.
        // Conservative catch-all: bust the site payload for ANY professional change.
        // Kept deliberately (not redundant with UserObserver's touch, which only
        // fires for PUBLIC_PROFILE_USER_FIELDS). Removing this risks under-invalidation
        // if a non-listed column ever leaks into the public payload — invalidate-only
        // here is cheaper than that staleness class of bug.
        if ($professional->site) {
            app(SiteCacheService::class)->invalidateSite($professional->site);
        }
        ```

---

- [ ] **#WAMP-3** · P2 — ServiceObserver always double-invalidates site cache on every service mutation
    - **Where:** app/Observers/Core/ServiceObserver.php:runHooks(), app/Services/Cache/UserCacheService.php:invalidateUser()
    - **Affects:** Every service save, delete, and restore — the most common mutation path in the app. Approximately 29 Redis DELs fire twice, unconditionally, on every operation. Combined with WAMP-4, each service edit incurs at least 36 wasted DELs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Using the `bustSite` flag introduced for WAMP-2: in `ServiceObserver::bust()`, call `$this->userCache->invalidateUser($pro, bustSite: false)`. The `touchParentSite()` call that always follows in `runHooks()` fires `SiteObserver::saved` → `invalidateSite()` — that is the correct and sufficient single bust path for all service mutations.
        - This is a one-line change to ServiceObserver once the flag exists; all other callers keep the default `bustSite: true`.
        - Add an integration test asserting that a single service save fires `SiteCacheService::invalidateSite()` exactly once. This protects the invariant from regressing silently.
        - Note: the "conservative catch-all" comment in `invalidateUser()` was written from UserObserver's perspective ("not redundant with UserObserver's touch, which only fires for PUBLIC_PROFILE_USER_FIELDS"). ServiceObserver is a separate caller that unconditionally calls both `invalidateUser()` and `touch()`, so the "not redundant" argument does not apply there.
    - **Technical:** `ServiceObserver::runHooks` calls `$this->bust($service)`, which calls `$this->userCache->invalidateUser($pro)`. `invalidateUser()`'s tail calls `app(SiteCacheService::class)->invalidateSite($professional->site)`, a ~29-key Redis DEL sweep (payload+stale, blocks+stale, images, emailBrand+stale, 18 image-view variants, professionalModel+stale). `runHooks` then calls `$this->touchParentSite($service, $pro)` — `$pro?->site?->touch()` fires `SiteObserver::saved` → `invalidateSite()` again for the same ~29 keys. This happens for every `saved`, `deleted`, and `restored` event with no conditional gate; toggling `is_active`, updating a price, or changing a title all produce the same double sweep. The `ShouldBeUnique` guard on `CloudflareCachePurgeJob` (120s window) prevents CF API amplification; the Redis amplification is unmitigated. At 200 users this is invisible; at 10k users making daily edits this is a sustained unnecessary load multiplier.
    - **Plain English:** Every time a professional touches a service — even just changing the price — the app goes through its cache-cleaning list twice, back to back. The first pass and the second pass delete exactly the same things. Nothing is left cleaner after the second pass; it just costs double the housekeeping. It is the most frequent edit professionals make, so this is the highest-volume place where the problem shows up. A single flag on the call prevents the double-pass without changing anything about how the data ends up looking.
    - **Evidence:**
        ```php
        // ServiceObserver::runHooks — bust() calls invalidateUser (→ invalidateSite at its tail),
        // then touchParentSite() fires SiteObserver::saved → invalidateSite() a second time.
        private function runHooks(Service $service): void
        {
            try {
                $pro = $this->bust($service);
                $this->reevaluateBooking($service, $pro);
                $this->touchParentSite($service, $pro);
            } catch (\Throwable $e) {
                Log::error('ServiceObserver hook failed', [
                    'service_id' => $service->id,
                    'user_id' => $service->user_id,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // ServiceObserver::bust → UserCacheService::invalidateUser tail — first invalidateSite()
        if ($professional->site) {
            app(SiteCacheService::class)->invalidateSite($professional->site);
        }

        // ServiceObserver::touchParentSite → SiteObserver::saved → second invalidateSite()
        private function touchParentSite(Service $service, ?User $pro): void
        {
            try {
                $pro?->site?->touch();
            } catch (\Throwable $e) {
                Log::warning('Parent site touch() failed on service mutation', [
                    'service_id' => $service->id,
                    'user_id' => $service->user_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        ```

---

- [ ] **#WAMP-4** · P2 — All 18 image-view cache variants busted on every invalidateSite() regardless of mutation type
    - **Where:** app/Services/Cache/SiteCacheService.php:invalidateSite(), app/Services/Cache/CacheKeyGenerator.php:siteImagesViewVariants()
    - **Affects:** Every block reorder, service edit, profile update, category rename, design-kit change, and any other mutation routed through `invalidateSite()`. Each call issues 18 Redis DELs for image-gallery view variants that are unaffected by non-image mutations. Combined with the double-invalidation in WAMP-2 and WAMP-3, a single service edit can incur 36 wasted image-view DELs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Split `invalidateSite()` into two scoped methods on `SiteCacheService`: `invalidateSitePayload(Site $site)` (payload+stale, blocks+stale, emailBrand+stale, professionalModel+stale) and `invalidateSiteImages(Site $site)` (the 18 image-view variant keys + siteImages). Keep `invalidateSite()` as a wrapper that calls both.
        - `SiteObserver::saved` continues calling `invalidateSite()` — a full site save should always bust everything.
        - Callers that know images are unaffected — such as `ServiceCategoryObserver` after WAMP-1's fix — call `invalidateSitePayload()` directly instead of routing through `touch()`.
        - `SiteMediaObserver`'s touch path stays as-is: media changes make the image-view keys genuinely stale, so the full sweep is correct there.
        - This split also resolves WAMP-1 structurally: once `ServiceCategoryObserver` can call `invalidateSitePayload()` directly, there is no need to route through `touch()` at all.
    - **Technical:** `SiteCacheService::invalidateSite()` iterates `CacheKeyGenerator::siteImagesViewVariants()` — 3 `pool` values (`null`, `'gallery'`, `'content'`) × 3 `mediaType` values (`'image'`, `'video'`, `'all'`) = 9 combinations × 2 (primary + `:stale`) = 18 Redis DELs. These keys cache filtered gallery views for `/api/images?pool=X&media_type=Y` in the dashboard. A service title edit, block reorder, profile bio change, or category rename does not mutate any `site_media` row. When those mutations also trigger the double-invalidation from WAMP-2 or WAMP-3, the 18 image-view DELs fire twice (36 total), all against keys that were either empty or correctly populated. The polling path (`?ids[]=`) uses per-batch fingerprint keys and correctly relies on a 5s TTL rather than enumeration — the split must preserve that distinction.
    - **Plain English:** Any time anything on a site changes — a service price, a block reorder, someone's bio — the app also discards eighteen pre-computed image gallery lists from its fast memory. The images haven't changed; those lists are still perfectly accurate. It's like a hotel concierge shredding the list of available room-service items every time a guest asks to change their wake-up call. The list gets rebuilt quickly, but the work was pointless. Splitting the cleanup into "content changed" versus "images changed" means each type of edit only throws away what actually got stale — and the structural fix pays dividends every time a new mutation type is added to the codebase.
    - **Evidence:**
        ```php
        // SiteCacheService::invalidateSite() — busts all 18 image-view keys on every call,
        // regardless of whether the triggering mutation touched any media rows.
        foreach (CacheKeyGenerator::siteImagesViewVariants() as [$pool, $mediaType]) {
            $variantKey = CacheKeyGenerator::siteImagesView($site->id, $pool, $mediaType);
            $keys[] = $variantKey;
            $keys[] = $variantKey.':stale';
        }

        // CacheKeyGenerator::siteImagesViewVariants() — 3 pools × 3 media types = 9 variants × 2 = 18 DELs per call.
        public static function siteImagesViewVariants(): array
        {
            $variants = [];
            foreach ([null, 'gallery', 'content'] as $pool) {
                foreach (['image', 'video', 'all'] as $mediaType) {
                    $variants[] = [$pool, $mediaType];
                }
            }

            return $variants;
        }
        ```

## P3 — Nice to have

- [ ] **#WAMP-5** · P3 — ServiceObserver re-evaluates section visibility on every service save, even when only non-relevant fields changed
    - **Where:** app/Observers/Core/ServiceObserver.php:reevaluateBooking()
    - **Affects:** Every service title, price, description, or sort-order edit — two `SectionVisibilityService::reevaluateEnabled` calls with at least one DB query run when `is_active` has not changed and the section state cannot have changed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `ServiceObserver`, determine the triggering event before calling `runHooks`. Pass a flag or split the handler: for `saved` events, gate `reevaluateBooking` on `$service->wasChanged('is_active')` — only toggling `is_active` on a service can flip a `booking` or `services` section block between enabled and disabled.
        - For `deleted` events, always re-evaluate unconditionally — a deletion can push the active count to zero. For `restored`, also always re-evaluate.
        - One clean approach: `runHooks(Service $service, bool $forceVisibilityCheck = false)`, called from `saved` with the `wasChanged('is_active')` guard and from `deleted`/`restored` with `forceVisibilityCheck: true`.
    - **Technical:** `ServiceObserver::reevaluateBooking` calls `$this->visibilityService->reevaluateEnabled()` twice (for `'booking'` and `'services'` block types) on every `saved`, `deleted`, and `restored` event. `SectionVisibilityService::reevaluateEnabled` exists at line 378 and almost certainly queries block state and active-service counts to determine whether `is_enabled` should flip. For the common case of title, price, description, or duration edits — the most frequent service mutations — `is_active` has not changed and neither block type's `is_enabled` can flip. The comment in `touchParentSite` acknowledges "visibility re-evaluation is a no-op when state is unchanged," but the no-op still incurs the DB round-trips to determine that nothing changed. Gating on `wasChanged('is_active')` eliminates those queries for the hot path.
    - **Plain English:** Every time a professional changes a service price, the system runs a background count to check whether any section of their website should appear or disappear. The answer is always the same: sections only toggle based on whether you have any active services at all, not on what they cost. It's like checking whether the "Open" sign is lit every time a staff member adjusts a table — harmless, but entirely wasted effort on the most common type of edit in the system.
    - **Evidence:**
        ```php
        // reevaluateBooking runs on every service save — including title/price/duration edits
        // where is_active hasn't changed and section state cannot have flipped.
        private function reevaluateBooking(Service $service, ?User $pro): void
        {
            try {
                $site = $pro?->site;
                if (! $site) {
                    return;
                }

                foreach (['booking', 'services'] as $blockType) {
                    $this->visibilityService->reevaluateEnabled(
                        (string) $service->user_id,
                        (string) $site->id,
                        $blockType,
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('Section visibility reevaluation failed on service change', [
                    'service_id' => $service->id,
                    'user_id' => $service->user_id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        ```
