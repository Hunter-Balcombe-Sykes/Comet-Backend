- [ ] **CACHE-1** · P2 — AffiliateProjectionsService has no caching layer, executing 5+ DB queries per request on a dashboard read path
    - **Where:** app/Services/Analytics/AffiliateProjectionsService.php (entire `build()` method)
    - **Affects:** Affiliate dashboards viewing run-rate, momentum, YTD, and year-end forecast projections
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the `build()` method in `CacheLockService::rememberLocked` with a 60–120s TTL + jitter.
        - Push-invalidate the cache key on every `brand_affiliate_rollup` upsert (the trigger that updates the source table), or version-token bust on any commission movement.
        - Consider exposing the version token from `CacheKeyGenerator` so writes automatically roll the cache key forward (the `analyticsSummaryVersion` pattern).
    - **Technical:** The `build()` method calls `resolveDataHistoryDays` (1 query), `fetchPerCurrencyAggregates` (1), `fetchPriorWindowAggregates` (1), `fetchYtdAggregates` (1), and `fetchBestMonthPerCurrency` (1 subquery) — five DB round-trips per request with no cache barrier. This is purely a read projection; it has no side effects and is an ideal candidate for `CacheLockService::rememberLocked`, which provides single-flight lock, TTL jitter, and SWR semantics. The source table `commerce.brand_affiliate_rollup` is trigger-maintained, so invalidation can be coupled to the upsert trigger or a version-token increment.
    - **Plain English:** Imagine the dashboard that shows an affiliate "you're on track to earn £X this year" recalculates that number from scratch every single time the page loads — five separate trips to the database. The numbers don't change between commission events, so we're doing fresh math when the answer hasn't changed. Wrapping it in a short-lived cache (like putting the answer on a sticky note for 60 seconds) eliminates the redundant work without making the numbers stale.
    - **Evidence:**
        ```php
        public function build(Professional $professional, ?int $windowDaysOverride = null): array
        {
            // ... no cache — every call runs:
            $dataHistoryDays = $this->resolveDataHistoryDays($professional->id, $now);
            $perCurrency = $this->fetchPerCurrencyAggregates(...);
            $priorByCurrency = $this->fetchPriorWindowAggregates(...)->keyBy('currency_code');
            $ytdByCurrency = $this->fetchYtdAggregates(...)->keyBy('currency_code');
            $bestMonthByCurrency = $this->fetchBestMonthPerCurrency(...)->keyBy('currency_code');
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-2** · P1 — PublicSiteResolver has no caching on the hottest read path in the application
    - **Where:** app/Services/PublicSite/PublicSiteResolver.php:18-38
    - **Affects:** Every public site visitor; subdomain → Site resolution runs on every page view
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `resolvePublishedSite()` in `CacheLockService::rememberLocked` with a 30–60s TTL + jitter.
        - Push-invalidate on every `Site` publish/unpublish, subdomain change, and `Professional` status change to `active`/`suspended`.
        - Use a versioned key (`public_site:v{site_version}`) so the cache auto-rolls on any relevant mutation.
    - **Technical:** `resolvePublishedSite()` runs on every public page request. It queries `Site` by subdomain (1 query), and if that misses, queries `SiteSubdomainAlias` (1 query) and then `Site` again by `site_id` (1 query). At target scale — 30 brands × 50 affiliates × an unknown but non-trivial public traffic volume — this is the single most frequently executed read path in the entire application. The `CacheLockService::rememberLocked` pattern with push-invalidation is already proven on the commerce analytics path and fits perfectly here; the `SiteCacheService` already has invalidation hooks that could be extended.
    - **Plain English:** Every person who visits an affiliate's storefront triggers up to three separate database lookups just to figure out which site to show them. If a thousand people visit in a minute, that's three thousand database queries asking "which site is this subdomain?" when the answer hasn't changed since the last deployment. Putting the answer in a short-lived cache is like putting the site address on a whiteboard instead of walking to the filing cabinet every time someone asks.
    - **Evidence:**
        ```php
        public function resolvePublishedSite(string $subdomain): ?Site
        {
            $subdomain = strtolower($subdomain);
            $siteQuery = Site::query()
                ->where('is_published', true)
                ->with('professional')
                ->whereHas('professional', function ($q) { $q->where('status', 'active'); });

            $site = (clone $siteQuery)
                ->whereRaw('lower(subdomain) = ?', [$subdomain])
                ->first();

            if ($site) { return $site; }

            $alias = SiteSubdomainAlias::query()
                ->whereRaw('lower(subdomain) = ?', [$subdomain])
                ->first();

            if (! $alias) { return null; }

            return (clone $siteQuery)->where('id', $alias->site_id)->first();
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **CACHE-3** · P2 — BrandDesignMediaService::deletePlaceholder uses double-UPDATE repack loop — write amplification on every placeholder delete
    - **Where:** app/Services/Media/BrandDesignMediaService.php:145-176 (the repack loop)
    - **Affects:** Brand dashboard users deleting placeholder images; unnecessary DB write load
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Compute the final `sort_order` values in PHP, then issue a single bulk `UPDATE` using a `CASE WHEN` statement or a batch upsert.
        - Alternatively, accept gaps in `sort_order` and let the `listDesignMedia` query renumber on read with `ROW_NUMBER()` — the list is always sorted anyway.
    - **Technical:** When a placeholder is deleted, the method re-packs the remaining placeholders' `sort_order` to `(0, 1, 2, ...)` using two UPDATE passes: first to a high offset (`PLACEHOLDER_MAX + 1000`) to avoid unique-index collisions, then back to the final values. Each pass executes one UPDATE per remaining row — up to 4 placeholders × 2 passes = 8 UPDATE statements for a single delete. This is write amplification: one user action triggers up to 8 DB writes on a table whose cardinality is bounded at 5. The two-pass technique correctly avoids the unique-index collision, but a single `UPDATE ... SET sort_order = CASE WHEN id = ... THEN ... END` would achieve the same result in one statement.
    - **Plain English:** When a brand deletes one of their five placeholder images, the code doesn't just remove it — it renumbers every remaining placeholder by moving them to temporary numbers and then to their final positions, generating up to eight database updates for one delete. It's like re-filing every folder in a drawer when you remove one file, instead of just closing the gap with one shuffle. The fix is to send all the new positions in a single instruction.
    - **Evidence:**
        ```php
        $offset = self::PLACEHOLDER_MAX + 1000;
        foreach ($remaining as $idx => $r) {
            SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $offset + $idx]);
        }
        foreach ($remaining as $idx => $r) {
            SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $idx]);
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **CACHE-4** · P2 — BrandDesignMediaService::reorderPlaceholders uses identical double-UPDATE repack loop
    - **Where:** app/Services/Media/BrandDesignMediaService.php:188-214
    - **Affects:** Brand dashboard users reordering placeholder images; same write-amplification profile as delete
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same single-`CASE WHEN` bulk UPDATE approach as CACHE-3.
        - Or delegate renumbering to `ROW_NUMBER()` at read time so the write path is a single pass with the caller-supplied order.
    - **Technical:** Identical antipattern to `deletePlaceholder` — two UPDATE passes per placeholder, up to 10 UPDATEs for a 5-placeholder reorder. The two passes guard against the partial unique index on `(site_id, pool, purpose, sort_order)`, but a single `CASE WHEN` update avoids the collision entirely because all new values are assigned atomically. The cardinality is capped at 5, so the blast radius is tiny, but the pattern is duplicated and both call sites should be fixed together.
    - **Plain English:** Same as deleting a placeholder — reordering them also triggers the double-shuffle update pattern. If they reorder all five images, that's ten database writes when one could do it. It's like rewriting every index card's position twice instead of once.
    - **Evidence:**
        ```php
        $offset = self::PLACEHOLDER_MAX + 1000;
        foreach ($orderedIds as $idx => $id) {
            SiteMedia::query()->where('id', $id)->update(['sort_order' => $offset + $idx]);
        }
        foreach ($orderedIds as $idx => $id) {
            SiteMedia::query()->where('id', $id)->update(['sort_order' => $idx]);
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **CACHE-5** · P2 — VideoVariantService::processVariants uploads HLS segments in a sequential per-file loop — network amplification proportional to video duration
    - **Where:** app/Services/Media/VideoVariantService.php:186-200 (the `scandir` loop inside HLS upload)
    - **Affects:** Video upload processing jobs; worker time grows linearly with video length
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Batch segment uploads using Laravel's HTTP pool or `Storage::disk()->put()` with a concurrent stream wrapper, or use `aws s3 sync` via a subprocess for the HLS directory.
        - As a lighter touch: at minimum, wrap the uploads in a `collect()->chunk()` with a note that R2 supports multipart upload for the directory.
    - **Technical:** For each HLS variant, `processVariants()` scans the temp directory and issues one `$disk->put()` per segment file. HLS segments are typically 6 seconds each, so a 5-minute video produces ~50 segments per variant, × 2 variants = ~100 sequential `put()` calls. Each `put()` is a network round-trip to R2 (or S3-compatible storage). This is not write amplification in the database sense — the segments are necessary artifacts — but the sequential loop amplifies total processing wall-clock time linearly with video duration. The canonical fix is a concurrent upload (multi-threaded or async HTTP pool) or a directory-level sync. At pre-beta with occasional uploads this is fine; at target scale with 30 brands potentially uploading training/intro videos, worker throughput becomes a bottleneck.
    - **Plain English:** When the system processes a video, it breaks it into short segments for streaming and uploads each segment one at a time — like mailing 100 postcards individually instead of putting them all in one envelope. For a 5-minute video, that's about 100 separate uploads, each waiting for the previous one to finish. This doesn't break anything, but it makes video processing slower than it needs to be. Sending the whole batch at once cuts the waiting time significantly.
    - **Evidence:**
        ```php
        foreach ($hlsDirs as $variantKey => $hlsDir) {
            $remoteHlsBase = "{$basePath}/hls/{$variantKey}";
            foreach (scandir($hlsDir) ?: [] as $file) {
                if ($file === '.' || $file === '..') { continue; }
                $localFile = "{$hlsDir}/{$file}";
                $remotePath = "{$remoteHlsBase}/{$file}";
                // ...
                $stream = fopen($localFile, 'rb');
                $disk->put($remotePath, $stream, ['visibility' => 'public', 'ContentType' => $mime]);
                if (is_resource($stream)) { fclose($stream); }
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **CACHE-6** · P3 — BrandDesignMediaService::getLogoFullUrls has no caching for a batch-read path called by partner cards and invite flows
    - **Where:** app/Services/Media/BrandDesignMediaService.php:290-309
    - **Affects:** Partner card displays, invite emails showing brand logos — any caller that needs multiple site logos at once
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheLockService::rememberLocked` with a 60s TTL + jitter, keyed by `implode(',', sort($siteIds))` and a version token tied to `SiteCacheService`.
        - Push-invalidate per-site logo caches in the existing `invalidateSiteCache()` method (which already runs `forgetBrandDesign`).
    - **Technical:** `getLogoFullUrls()` is the batch counterpart to `getLogoFullUrl()` and queries `site_media` with a `WHERE IN` on `site_id` plus eager-loaded `mediaVariants`. It's used anywhere multiple brand logos need to be displayed simultaneously (partner cards on the affiliate dashboard, invite emails). Without caching, every render re-queries. The method is already side-effect free and the invalidation hook exists in `BrandDesignMediaService::invalidateSiteCache()` — `forgetBrandDesign` is already called on every logo upload/delete. A simple `rememberLocked` wrap completes the read-path cache hygiene.
    - **Plain English:** When the system builds a list of partner cards showing multiple brand logos, it asks the database for every logo from scratch each time. The logos don't change between uploads — they're the same PNGs that were stored last time. A short cache (60 seconds) means the list renders from memory instead of re-querying, and the cache is automatically cleared whenever someone updates their logo.
    - **Evidence:**
        ```php
        public function getLogoFullUrls(array $siteIds): array
        {
            if (empty($siteIds)) { return []; }

            return SiteMedia::query()
                ->whereIn('site_id', $siteIds)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_LOGO_FULL)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where('processing_state', '!=', SiteMedia::PROCESSING_STATE_FAILED)
                ->with('mediaVariants')
                ->get()
                ->mapWithKeys(fn (SiteMedia $m): array => [
                    (string) $m->site_id => $m->variantUrls()['optimized'] ?? null,
                ])
                ->filter()
                ->all();
        }
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **CACHE-7** · P2 — AnalyticsService::windowedDistinctCount and windowedCartSessions execute 6 separate COUNT DISTINCT queries (one per time window) instead of a single query
    - **Where:** app/Services/Analytics/AnalyticsService.php (windowedDistinctCount at ~line 200, windowedCartSessions at ~line 212)
    - **Affects:** Cold-cache analytics page loads (post-deploy, eviction); latency for the one unlucky request that fills the cache
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the per-window loop with a single `SELECT COUNT(DISTINCT col) FILTER (WHERE occurred_at >= ?) ...` query using PostgreSQL's `FILTER` clause — one query, six columns.
        - Apply the same treatment to `windowedCartSessions`, `brandWindowedCartSessions`, `brandWindowedViews`, and `brandWindowedUniqueVisitors`.
    - **Technical:** The `windowedDistinctCount` method iterates over 6 `self::WINDOWS` keys and issues a separate `COUNT DISTINCT` query per window. `windowedCartSessions` does the same. `brandWindowedCartSessions`, `brandWindowedViews`, and `brandWindowedUniqueVisitors` each add a preliminary `pluck('affiliate_professional_id')` query plus 6 more. This is 12+ queries for `computeAffiliate()` and 25+ for `computeBrand()` on a cold cache. The `CacheLockService::rememberLocked` wrapper provides single-flight protection, so only one request pays this cost, but that request still suffers avoidable DB latency. PostgreSQL's `FILTER` clause (already in use in `AffiliateProjectionsService`) allows all six windows to be aggregated in one query. At target scale (30 brands × 50 affiliates with cold-cache after deploys), the latency hit is noticeable but not catastrophic — the cache absorbs it 99% of the time.
    - **Plain English:** When the analytics dashboard loads for the first time after a deploy, it asks the database the same question six times in a row — "how many unique visitors in the last 24 hours?" then "…in the last 7 days?" then "…in the last 30 days?" and so on. It's like calling someone six times to ask six questions when you could ask them all in one phone call. The dashboard is smart enough to remember the answers for five minutes after that, so only the first person pays the price — but the fix is simple enough to be worth doing.
    - **Evidence:**
        ```php
        private function windowedDistinctCount(string $table, string $distinctColumn, array $bounds, array $where): array
        {
            $result = [];
            foreach (self::WINDOWS as $w) {   // 6 separate queries
                $query = DB::table($table)->whereNotNull($distinctColumn);
                foreach ($where as $col => $val) { $query->where($col, $val); }
                if ($bounds[$w] !== null) { $query->where('occurred_at', '>=', $bounds[$w]); }
                $result[$w] = (int) $query->distinct()->count($distinctColumn);
            }
            return $result;
        }
        ```
        ```php
        private function windowedCartSessions(string $professionalId, array $bounds): array
        {
            $result = [];
            foreach (self::WINDOWS as $w) {   // 6 more separate queries
                $query = DB::table('analytics.cart_events')
                    ->where('professional_id', $professionalId)
                    ->where('event_type', 'checkout_start')
                    ->whereNotNull('session_id');
                if ($bounds[$w] !== null) { $query->where('occurred_at', '>=', $bounds[$w]); }
                $result[$w] = (int) $query->distinct()->count('session_id');
            }
            return $result;
        }
        ```
    - `[DRAFT, confidence: 0.80]`
