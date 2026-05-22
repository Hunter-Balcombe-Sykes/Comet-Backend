- [ ] **CACHE-1** · P3 — Two-pass foreach reorder loop produces 2N individual UPDATEs per reorder request
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:284-297
    - **Affects:** Affiliate/brand users reordering gallery or content pool media. At pre-beta (~6 items per pool) the overhead is trivial; at scale with many concurrent reorders on the same site, advisory lock contention could add up.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the two-pass foreach with a single `CASE WHEN` bulk update: `UPDATE site_media SET sort_order = CASE id WHEN ? THEN ? ... END WHERE site_id = ? AND id IN (...)`
        - Keep the explicit `$site->touch()` at the end; it already closes the observer-bypass gap.
    - **Technical:** The reorder method intentionally bypasses `SiteMediaObserver` (mass query-builder updates don't fire Eloquent events), then does two passes — one to move everything to a high offset (so no unique-constraint collisions), one to place items at final positions. This produces 2N `UPDATE` statements inside a transaction that already holds an advisory lock. A single `CASE WHEN` update achieves the same ordering in one round-trip. The documented observer bypass + explicit `$site->touch()` pattern remains correct either way.
    - **Plain English:** When someone drags photos into a new order, the system updates each photo's position twice — once to move it out of harm's way, once to its final spot. For 6 photos, that's 12 database writes inside a single locked operation. A single smarter update could do it in one pass. At 30 brands this is invisible; at 300 during a launch spike those extra writes stack up.
    - **Evidence:**
        ```php
        foreach ($finalIds as $index => $id) {
            SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('id', $id)
                ->update(['sort_order' => $offset + $index]);
        }

        foreach ($finalIds as $index => $id) {
            SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('id', $id)
                ->update(['sort_order' => $index]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-2** · P3 — Two-pass foreach reorder loop on link blocks produces 2N individual UPDATEs
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:126-141
    - **Affects:** Staff reordering a professional's custom link blocks. Bounded by typical link block count (~5–10), so impact is negligible at pre-beta scale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the two-pass foreach with a single `CASE WHEN` bulk update identical to the CACHE-1 recommendation.
    - **Technical:** Same antipattern as CACHE-1 — offset pass followed by final-sort pass, both inside a transaction holding `pg_advisory_xact_lock`. A single `CASE WHEN` update achieves the same ordering atomically without the double-write overhead.
    - **Plain English:** Same double-write pattern as the photo reorder. When support staff reorders a brand's custom links, each link gets moved twice in the database. A single move would be cleaner.
    - **Evidence:**
        ```php
        foreach ($newOrder as $i => $id) {
            Block::query()
                ->where('professional_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->where('block_type', 'link')
                ->where('id', $id)
                ->update(['sort_order' => $offset + $i]);
        }

        foreach ($newOrder as $i => $id) {
            Block::query()
                ->where('professional_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->where('block_type', 'link')
                ->where('id', $id)
                ->update(['sort_order' => $i]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-3** · P3 — Two-pass foreach reorder loop on section blocks produces 2N individual UPDATEs
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSectionManagementController.php:126-141
    - **Affects:** Staff reordering section blocks (gallery, services, shop, booking, bio). Section count per site is typically 5–8, so impact is negligible at pre-beta scale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the two-pass foreach with a single `CASE WHEN` bulk update per the CACHE-1 recommendation.
    - **Technical:** Identical two-pass offset-then-final pattern as CACHE-1 and CACHE-2. All three controllers share the same reorder implementation shape; a single shared helper or `CASE WHEN` pattern applied consistently would eliminate the duplication and the write amplification in one change.
    - **Plain English:** Same story as photos and links. When staff rearranges the sections on a brand's public page, each section gets written twice. Trivial at current scale; worth tidying while the pattern is fresh.
    - **Evidence:**
        ```php
        foreach ($newOrder as $i => $id) {
            Block::query()
                ->where('professional_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'sections')
                ->where('id', $id)
                ->update(['sort_order' => $offset + $i]);
        }

        foreach ($newOrder as $i => $id) {
            Block::query()
                ->where('professional_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'sections')
                ->where('id', $id)
                ->update(['sort_order' => $i]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-4** · P2 — Booking analytics TTL passed as Carbon instance instead of integer seconds — inconsistent with every other `rememberLocked` call site
    - **Where:** app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php:57-58
    - **Affects:** Dashboard booking analytics overview — a hot read for every professional with Square/Fresha connected in smart mode. If `CacheLockService::rememberLocked` passes the TTL through to its internal lock timeout, a Carbon instance cast to int becomes `1` second, rendering the lock ineffective (lock expires before the query completes, defeating single-flight protection).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addMinutes(2)` with `120` and `now()->addMinutes(10)` with `600` to match every other call site.
        - Audit `CacheLockService::rememberLocked` to confirm whether its lock-timeout parameter accepts `DateTimeInterface` or expects `int`. If the lock path also needs the Carbon → second conversion, add it defensively.
    - **Technical:** Every other `rememberLocked` call in the codebase passes an integer (60, 30, 5, etc.). Here, `$ttl = $metricsContext['use_hourly'] ? now()->addMinutes(2) : now()->addMinutes(10)` produces a Carbon instance. If `rememberLocked` passes this directly to `Cache::remember()`, Laravel's `Repository::getSeconds()` will correctly compute `diffInSeconds()` — the cache TTL will be ~120 or ~600s. However, if `rememberLocked` uses the same value for its internal Redis lock timeout (which almost certainly expects an int), the lock may expire in 1 second, removing single-flight protection exactly when the query is slowest (cache miss, DB under load). Switching to plain ints eliminates the ambiguity.
    - **Plain English:** Every other cache in the dashboard uses a number like "60 seconds." The booking analytics cache uses a date object like "2 minutes from now," which means two different things depending on where in the code it lands — as a cache lifetime it works fine, but as a lock timeout it silently collapses to 1 second. If the database is slow, the lock expires before the query finishes, and multiple dashboard visitors can accidentally fire the same heavy query at once.
    - **Evidence:**
        ```php
        $ttl = $metricsContext['use_hourly'] ? now()->addMinutes(2) : now()->addMinutes(10);
        $cacheKey = CacheKeyGenerator::bookingAnalytics(
            $professionalId,
            (string) $metricsContext['range_from'],
            (string) $metricsContext['range_to'],
            (string) $metricsContext['group_by']
        );

        return $this->success($this->cacheLock->rememberLocked($cacheKey, $ttl, function () use (...) {
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **CACHE-5** · P2 — Staff booking analytics TTL has the same Carbon-instead-of-int inconsistency
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffBookingController.php:99-100
    - **Affects:** Staff booking analytics inspector — mirror of CACHE-4. Same lock-timeout fragility when staff view a professional's booking dashboard.
    - **Effort:** S (~0.5–1h) — fix alongside CACHE-4 in one pass.
    - **What to do:**
        - Replace `now()->addMinutes(2)` with `120` and `now()->addMinutes(10)` with `600`.
    - **Technical:** Identical pattern to CACHE-4. The staff-side controller copies the same TTL construction; both should be fixed together to keep the two analytics surfaces in lockstep.
    - **Plain English:** The staff view of booking analytics has the same date-object-instead-of-seconds issue as the professional dashboard. Fix both at once.
    - **Evidence:**
        ```php
        $ttl = $metricsContext['use_hourly'] ? now()->addMinutes(2) : now()->addMinutes(10);
        $cacheKey = CacheKeyGenerator::bookingAnalytics(
            $professionalId,
            (string) $metricsContext['range_from'],
            (string) $metricsContext['range_to'],
            (string) $metricsContext['group_by'],
        );

        return $this->success($this->cacheLock->rememberLocked($cacheKey, $ttl, function () use (...) {
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **CACHE-6** · P2 — Global notification broadcast fan-out lacks visible batching — risk of N child jobs or N eager receipt rows
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:93-95
    - **Affects:** All email subscribers on the target list when staff sends a global notification (policy updates, incidents, feature announcements). At 30 brands × ~50 affiliates = 1,500 subscribers, a naive per-recipient job dispatch would flood Horizon.
    - **Effort:** M (~2–4h) — depends on what `SendStaffBroadcastEmailsJob` actually does internally.
    - **What to do:**
        - Open `SendStaffBroadcastEmailsJob` and verify whether it chunks recipients into batches (e.g., 50 per batch with `->chunk()`) or dispatches one child job / sends one email per recipient in a tight `foreach`.
        - If it's the latter, refactor to chunked dispatch and ensure `NotificationReceipt` rows are created lazily on first read rather than eagerly at fan-out.
    - **Technical:** The controller dispatches a single `SendStaffBroadcastEmailsJob` with the notification ID and a list key. Without seeing the job internals, the canonical concern is that it queries all subscribers for that list and loops — either dispatching N `SendTransactionalNotificationEmailJob` children or inserting N `NotificationReceipt` rows eagerly. The rebuild plan's notification design target is lazy receipt creation (receipt row inserted only when the user reads or dismisses the notification) plus chunked email dispatch. If this job predates that standard, it may still be using eager fan-out.
    - **Plain English:** When Partna staff sends a platform-wide announcement, the system needs to email every subscriber. If the code loops through 1,500 subscribers one at a time — dispatching a separate queue job for each — it'll flood the background worker pipeline. The right approach is to break the list into manageable chunks (say 50 at a time) and only create the "read/unread" tracking rows when someone actually opens the notification, not ahead of time for everyone.
    - **Evidence:**
        ```php
        } elseif ($notification->professional_id === null) {
            // Global: newsletter-style mass email to email_list_key subscribers.
            // Bypasses per-category prefs by design — globals are announcement-class
            // (incidents, policy updates) that should reach the audience regardless.
            SendStaffBroadcastEmailsJob::dispatch($notification->id, $emailListKey);
        }
        ```
    - `[DRAFT, confidence: 0.6]`
