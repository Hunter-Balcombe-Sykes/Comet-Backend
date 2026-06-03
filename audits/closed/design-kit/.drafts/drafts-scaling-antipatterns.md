- [ ] **#CACHE-1** · P3 — `Cache::remember` without single-flight lock on `design_kits:columns` metadata key
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:260-266
    - **Affects:** Design-kit save path — a cold cache after deploy lets concurrent saves stampede `information_schema.columns`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::remember` with `CacheLockService::rememberLocked` and add ±20% jitter on the TTL so concurrent `writeDesignKit()` calls after a deploy single-flight through one metadata query.
        - Or: bake the column list into a config constant (it changes only during migration deploys) and drop the runtime `information_schema` query entirely.
    - **Technical:** The `design_kits:columns` key uses vanilla `Cache::remember` with a hard 3600s TTL and zero jitter. After `artisan cache:clear` (every deploy), the first N concurrent `writeDesignKit()` calls each run `information_schema.columns` independently — a textbook cold-cache stampede. The key is global (not per-site), so every concurrent save across all sites collides on the same lock-free fetch. `CacheLockService::rememberLocked` would collapse them into one query + one cache write. The blast radius is small (this is an infrequent save path), but the fix is trivial and aligns the codebase with the established caching doctrine.
    - **Plain English:** Imagine a restaurant where 10 waiters all rush to the kitchen to check the menu at the same time because the menu board was wiped during a renovation. Each waiter independently asks the chef "what's on the menu today?" instead of one waiter checking and pinning the answer to the board. The fix is to let one waiter check and the others wait 100 milliseconds for the answer. Or better yet, print the menu once when the renovation happens and stop asking the chef at all.
    - **Evidence:**
        ```php
        $columns = Cache::remember('design_kits:columns', 3600, fn () => DB::connection('pgsql')
            ->table('information_schema.columns')
            ->where('table_schema', 'site')
            ->where('table_name', 'design_kits')
            ->pluck('column_name')
            ->all()
        );
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-2** · P3 — Triple `invalidateSite()` cascade per design-kit-only update
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:240-256
    - **Affects:** Every design-kit save — three full cache-invalidation sweeps fire for one write, with redundant DB queries and Redis operations.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Suppress the Observer-driven `invalidateSite` during `$site->touch()` by wrapping it in `Site::withoutEvents()` (the explicit post-write bust in the controller already covers the authoritative invalidation).
        - Or: consolidate to one explicit `invalidateSite()` call after the design-kit write completes, and have `UpdateSiteAction::execute()` skip the `save()`-triggered Observer when `$data` is empty (a no-op PATCH).
    - **Technical:** The controller's design-kit update path triggers three `invalidateSite()` calls: (1) `$action->execute($professional, [])` → Eloquent fires `saved` unconditionally in `finishSave()` → `SiteObserver::saved()` → `invalidateSite()`. (2) `$site->touch()` → `save()` with dirty timestamp → same Observer chain → second `invalidateSite()`. (3) Explicit `app(SiteCacheService::class)->invalidateSite($site)` after `writeDesignKit()`. Each `invalidateSite()` call runs `invalidateSitePayload()` + `invalidateSiteImages()`, which together query `SiteSubdomainAlias` and enumerate ~30 Redis keys for `Cache::deleteMultiple`. Busts 1 and 2 fire BEFORE the raw `site.design_kits` UPDATE is visible, so any cache rebuilt between them would be stale. Only bust 3 is authoritative. At 30 brands × ~50 affiliates each saving a few times daily, the wasted ops are negligible, but the pattern is untidy and the comment in the code already acknowledges the redundancy.
    - **Plain English:** When a user changes their design colours, the system rings the fire alarm three times — once before the change is saved, once during the save, and once after. The first two alarms make the fire trucks check a building that hasn't changed yet. The third alarm is the only one that matters. The first two are wasted trips that query the database and clear caches for no benefit. The fix is to ring the bell just once, after the change is fully written.
    - **Evidence:**
        ```php
        // UserSiteController.php:240-256
        $site = $action->execute($professional, $data);

        if (is_array($designKit)) {
            $this->writeDesignKit($site->id, $designKit);
            if (!$site->wasChanged()) {
                $site->touch();
            }
            // execute() already fired invalidateSite via $site->save(), but that
            // ran BEFORE the raw design_kits write above — bust again so the new
            // kit (and the email-brand bundle that reads it) is reflected.
            app(SiteCacheService::class)->invalidateSite($site);
        }
        ```
        ```php
        // WriteDesignKitTest.php — confirming the triple bust
        // Bust 1: execute([]) → $site->save() (non-dirty) → Eloquent fires 'saved'
        //         unconditionally in finishSave() → SiteObserver → invalidateSite.
        // Bust 2: $site->touch() → $site->save() (dirty, updates updated_at) →
        //         SiteObserver → invalidateSite.
        // Bust 3: explicit invalidateSite() in the controller after writeDesignKit.
        $spy->shouldHaveReceived('invalidateSite')->times(3);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CACHE-3** · P3 — `design_kits:columns` cache key has no version token — deploy-time `artisan cache:clear` flushes the entire cache to bust a single metadata key
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:260
    - **Affects:** Every deploy — `artisan cache:clear` cold-starts all cached payloads (public profiles, email brands, blocks) when only the `design_kits:columns` metadata key needed busting.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `design_kits_columns_version` config key incremented in any migration that touches `site.design_kits` columns, and suffix the cache key with it (e.g. `design_kits:columns:v3`).
        - Or: replace the runtime `information_schema` query with a deploy-time constant (the column list is static between deploys) and eliminate the cache key entirely.
    - **Technical:** The column list from `information_schema.columns` is deploy-time stable — it changes only when a `supabase/migrations/*_design_kit_*.sql` migration adds or drops a column. The current bust mechanism for `design_kits:columns` is `artisan cache:clear` in the deploy script, which flushes EVERY cache key in Redis (including hot public-profile payloads, email-brand bundles, and block caches). This causes a thundering herd on the next wave of public traffic. A version-token suffix (the `analyticsSummaryVersion` pattern already used elsewhere in `CacheKeyGenerator`) would let the deploy flip the version and bust only this one key, leaving all other caches warm. Alternatively, baking the column list into a config array (generated at deploy time from the same `information_schema` query) removes the need for any runtime cache on this data.
    - **Plain English:** To update a small whiteboard in the office, you currently cut power to the entire building. When the power comes back, every appliance, computer, and light has to restart from scratch. The fix is to just erase that one whiteboard — leave everything else running.
    - **Evidence:**
        ```php
        // Column list is deploy-time stable; cache for 1 h so each save doesn't
        // pay an extra metadata round-trip. Busted by `artisan cache:clear`
        // in the deploy script whenever a design_kit migration adds/drops columns.
        $columns = Cache::remember('design_kits:columns', 3600, fn () => DB::connection('pgsql')
            ->table('information_schema.columns')
            ->where('table_schema', 'site')
            ->where('table_name', 'design_kits')
            ->pluck('column_name')
            ->all()
        );
        ```
    - `[DRAFT, confidence: 0.75]`
