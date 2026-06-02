`★ Insight ─────────────────────────────────────`
The Grep across all PHP files confirms that `getPayloadById`, `getPayloadByHandle`, and `getPayloadByAuthId` are **defined but never called** — no controller, middleware, or route references them. The `/me` endpoint (`ProfessionalController::show`) reads site data from the `professionalModel` cache (which `invalidateSite` correctly busts, including the `:stale` copy). The DeepSeek CACHE-1 finding's described stale-data scenario does not affect any live request path. This re-tiers the finding significantly.
`─────────────────────────────────────────────────`

**CACHE-1** → re-tier **P1→P3** (gap is real but inert; dead payload methods, active `/me` path uses correctly-busted model cache)

**CACHE-2** → **drop** (confidence 0.65 < 0.7 threshold, confirmed customer count not in any public or dashboard cached payload, DeepSeek itself gates the finding on verifying this)

---

# Cache Layer Audit — 2026-05-25

**Branch:** development
**Lens:** cache invalidation gaps, stampede risk, stale reads, KV/Redis/HTTP cache layering correctness
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/Concerns/JitteredTtl.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Cache/SiteCacheService.php
- app/Jobs/Cache/AggregateCacheMetricsJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Observers/Concerns/LogsWithRequestContext.php
- app/Observers/Core/BlockObserver.php
- app/Observers/Core/CustomerObserver.php
- app/Observers/Core/ServiceCategoryObserver.php
- app/Observers/Core/ServiceObserver.php
- app/Observers/Core/SiteMediaObserver.php
- app/Observers/Core/SiteObserver.php
- app/Observers/Professional/ProfessionalObserver.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/PublicSiteResolver.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/Site/LinkBlockFieldBuilder.php
- app/Services/Site/ReclaimHandleAction.php
- app/Services/Site/SocialLinkNormalizer.php
- app/Services/Site/UpdateSiteAction.php
- app/Http/Controllers/Api/Professional/Account/ProfessionalController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

- [ ] **#CACHE-1** · P3 — `invalidateSite` omits three professional payload cache keys that embed site data — latent stale-read if wired to an endpoint
    - **Where:** app/Services/Cache/SiteCacheService.php (`invalidateSite`) + app/Services/Cache/ProfessionalCacheService.php (`getPayloadById/ByHandle/ByAuthId`)
    - **Affects:** Currently no live request path — `getPayloadByAuthId/ById/ByHandle` are fully implemented but uncalled. The active `/me` endpoint (`ProfessionalController::show`) reads site data from the `professionalModel` cache, which `invalidateSite` does correctly bust. Risk activates the moment any endpoint is wired to `getPayloadByAuthId` or its siblings.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `invalidateSite`, inside the existing `$professionalId !== ''` guard, add `CacheKeyGenerator::professionalPayloadById($professionalId)` to `$keys`.
        - To also bust `professionalPayloadByHandle` and `professionalPayloadByAuthId`, load `$site->professional` (or a slim `SELECT handle_lc, auth_user_id FROM core.users WHERE id = ?`) to obtain those values, then append the remaining two keys before the `Cache::deleteMultiple` call.
        - No `:stale` suffix needed for these three keys — `rememberLockedNullable` (which populates them) deliberately omits SWR stale copies per its docblock.
    - **Technical:** `ProfessionalCacheService::toPayload()` embeds `site.subdomain`, `site.is_published`, and `site.settings` into the three `pro:payload:*` cache keys. `SiteObserver::saved` calls `invalidateSite`, which correctly busts `professionalModel` (the path the live `/me` endpoint uses) but not the payload keys. `invalidateProfessional` does bust all three payload keys, but it only fires on Professional model saves — a pure site-only mutation (settings merge, publish toggle, or a block `touch()`) hits `invalidateSite` exclusively. The gap is harmless today because no controller calls `getPayloadBy*`, but `invalidateProfessional` already busts them for symmetry on the professional side; bringing `invalidateSite` into parity closes the latent inconsistency before a future endpoint activates it.
    - **Plain English:** Imagine two filing cabinets with copies of the same document. When someone edits the original, a cleanup crew reliably empties the first cabinet (the one everyone actually opens today) but ignores the second cabinet entirely. Nobody reads the second cabinet yet — but as soon as someone does, they'll see outdated information. The fix is to teach the cleanup crew to empty both cabinets at the same time, so the second one is ready to use correctly from day one.
    - **Evidence:**
        ```php
        // app/Services/Cache/SiteCacheService.php — invalidateSite busts professionalModel but not the three payload keys
        if ($professionalId !== '') {
            $modelKey = CacheKeyGenerator::professionalModel($professionalId);
            $keys[] = $modelKey;
            $keys[] = $modelKey.':stale';
            // Missing: CacheKeyGenerator::professionalPayloadById($professionalId)
            // Missing: CacheKeyGenerator::professionalPayloadByHandle($handleLc)   — needs $site->professional
            // Missing: CacheKeyGenerator::professionalPayloadByAuthId($authUserId) — needs $site->professional
        }
        ```
        ```php
        // app/Services/Cache/ProfessionalCacheService.php — toPayload() embeds site fields
        'site' => $site ? [
            'id' => $site->id,
            'subdomain' => $site->subdomain,
            'is_published' => (bool) $site->is_published,
            'settings' => $siteSettings,
        ] : null,
        ```
        ```php
        // app/Services/Cache/ProfessionalCacheService.php — invalidateProfessional already busts all three (for reference)
        $keys = [
            CacheKeyGenerator::professionalPayloadById($professional->id),
            CacheKeyGenerator::professionalPayloadByHandle($handleLc),
            CacheKeyGenerator::professionalPayloadByAuthId($professional->auth_user_id),
            // ... model, ID maps, services, customer count ...
        ];
        ```
