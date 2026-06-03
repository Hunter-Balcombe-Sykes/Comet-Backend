- [ ] **#CCH-1** · P2 — Design kit column list cache missing single-flight lock and unjittered TTL
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:248-253
    - **Affects:** All design kit saves; on cold cache, multiple concurrent saves can trigger simultaneous database metadata queries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::remember(...)` with `$this->cache->rememberLocked(CacheKeyGenerator::designKitColumns(), 3600, fn() => ...)` after injecting `CacheLockService`.
        - Add a static method `CacheKeyGenerator::designKitColumns()` to centralise the key, so any future invalidation uses the same string.
    - **Technical:** Direct `Cache::remember` with a literal integer TTL of 3600 lacks both single-flight locking (stampede risk) and ±20 % jitter (thundering herd on expiry). The gold standard requires `CacheLockService::rememberLocked`, which provides a per-key lock and jittered TTL via `JitteredTtl`. Additionally the key is hard-coded, not routed through `CacheKeyGenerator`, which risks drift if another component needs to bust it.
    - **Plain English:** The system remembers which columns exist in the design kit table to speed up saving. If many users save at the same time right after a cache expiry, all of them rush to the database to figure out the column list. A shared lock ensures only one does the work while others wait. Also, the expiry time is exactly one hour, so all servers could hit the database at once — adding a random fudge factor spreads that load.
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
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCH-2** · P2 — handle.resolve cache invalidation forgets only the primary key, leaving :stale companion alive
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:106
    - **Affects:** After a deleted-user race condition, the stale resolve copy can persist for up to 5 minutes, causing repeat 404s.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `Cache::forget(CacheKeyGenerator::handleResolve($handleLc));` to `Cache::deleteMultiple([CacheKeyGenerator::handleResolve($handleLc), CacheKeyGenerator::handleResolve($handleLc).':stale']);`.
    - **Technical:** The handle.resolve key is populated via `CacheLockService::rememberLocked`, which writes both a primary key and a `:stale` companion (10× TTL) for stale-while-revalidate. The controller’s direct `Cache::forget` only removes the primary key, so the `:stale` entry can continue serving out-of-date resolve data to subsequent requests.
    - **Plain English:** Imagine you have a primary phone list and a backup copy. When you delete an old phone number from the primary list but forget the backup, anyone checking the backup will still find the outdated entry. That’s what happens here — we clear the main entry but not the backup, causing confusion.
    - **Evidence:**
        ```php
        Cache::forget(CacheKeyGenerator::handleResolve($handleLc));
        ```
    - `[DRAFT, confidence: 0.9]`
