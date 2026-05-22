
<!-- ═══ SUB-CHUNK: s1 (app/Jobs/Shopify) ═══ -->

I did not identify any scaling antipatterns in the provided files. The Shopify job implementations already reflect the post-rebuild patterns: append-only event logs, trigger-maintained rollups, push invalidation, LWW upserts, and chunked operations where needed. No findings to report.

<!-- ═══ SUB-CHUNK: s2 (app/Jobs/Cache app/Jobs/Cloudflare app/Jobs/Concerns app/Jobs/Exports app/Jobs/Fresha app/Jobs/Gdpr app/Jobs/Notifications app/Jobs/Square app/Jobs/Store app/Jobs/Streaming app/Jobs/Stripe app/Jobs/DeleteMediaArtifactsJob.php app/Jobs/ProcessImageVariantsJob.php app/Jobs/ProcessVideoVariantsJob.php) ═══ -->

- [ ] **#CACHE-1** · P2 — `VoidExpiredPayoutsJob::fireGraceWarnings()` unbounded `->get()` loads all candidate payouts into memory plus per-payout synchronous notification publishes
    - **Where:** app/Jobs/Stripe/VoidExpiredPayoutsJob.php:142-150 (unbounded get) and :166-175 (per-payout publish loop)
    - **Affects:** Stripe payout grace-warning delivery; ops visibility of stuck payouts. Memory pressure if pending-payout volume grows unexpectedly; job timeout risk from serial notification INSERTs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the unbounded `->get()` with `->chunkById(200)` and process tiers inside the chunk callback.
        - Batch per-payout `$publisher->publish()` calls into a bulk-notification dispatch (or at minimum collect publish payloads and flush once per chunk).
    - **Technical:** The method fetches every pending payout whose `void_at` falls within a 30-day window using a single `->get()` — no `limit`, no `chunkById`. The result set is then iterated in-memory three times (once per T-30/T-7/T-1 tier) and each qualifying payout triggers a synchronous `NotificationPublisher::publish()` call plus a `$payout->save()` to update the `grace_notifications_sent` JSONB column. At 30 brands × ~50 affiliates × ~100 orders/affiliate/year the absolute row count is bounded, but the pattern is fragile: a single brand with an anomalously large affiliate roster or a Stripe outage that stalls payout creation could produce enough pending rows to exhaust the 300s job timeout on the serial publish loop alone. The chunked+bulk replacement mirrors the pattern already used by `FanOutBrandStatusNotificationJob`.
    - **Plain English:** This is like a mailroom clerk who, once a day, picks up every single piece of outgoing mail from the entire building in one armload, then walks to the postbox and posts each letter one at a time. At 50 letters a day it works fine; at 500 letters the clerk drops the pile or the postbox closes before they finish. The fix is to carry the mail in small stacks and drop whole stacks into the postbox at once.
    - **Evidence:**
        ```php
        $allCandidates = CommissionPayout::query()
            ->where('status', 'pending')
            ->whereBetween('void_at', [$windowStart, $windowEnd])
            ->where(function ($q) use ($brandSideCodes) {
                $q->whereIn('failure_code', $brandSideCodes)
                    ->orWhereDoesntHave('affiliateProfessional', fn ($a) => $a->where('stripe_connect_status', 'active'));
            })
            ->get();

        // ... then iterated per-tier:
        foreach ([30, 7, 1] as $daysOut) {
            // ...
            foreach ($candidates as $payout) {
                // ...
                $publisher->publish(/* ... */);  // synchronous per-payout
                $payout->forceFill([/* ... */])->save();  // synchronous per-payout save
            }
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#CACHE-2** · P3 — `InviteExpirySweepJob` per-invite synchronous notification publish inside chunk loop without batching
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php:72-97
    - **Affects:** Brand managers receiving invite-expiry notifications. At pre-beta scale this is invisible; at target scale a brand with hundreds of pending invites hitting expiry on the same day sees serial INSERT latency inside the daily sweep.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect publish payloads per chunk and dispatch a single bulk-notification job or call a batch-publish method on `NotificationPublisher`.
        - Apply the same `Bus::batch()` fan-out pattern used by `FanOutBrandStatusNotificationJob` if per-recipient dedupe isolation is required.
    - **Technical:** Inside `chunkById(500)` the job iterates every expired invite row and calls `NotificationPublisher::publish()` synchronously — one INSERT (plus likely one notification-receipt INSERT) per expired invite. The bulk status update (`whereIn -> update`) is already batched; only the notification side remains per-row. At the scaling target (30 brands, each with perhaps 50–200 outstanding invites), the total daily volume is low enough that this is cosmetic, but the pattern is still a synchronous N× write loop where N is unbounded by the sweep event payload.
    - **Plain English:** After the clerk marks all the expired invitations in the ledger with one efficient stamp, they then walk to each brand manager's desk one at a time to hand-deliver a note about each individual invite. Grouping the notes by destination and delivering them in a single folder would be faster, but at current office size nobody notices the extra footsteps.
    - **Evidence:**
        ```php
        DB::table('brand.brand_affiliate_invites')
            ->whereIn('id', $ids)
            ->where('status', 'pending')
            ->update(['status' => 'expired', 'updated_at' => $now]);

        // ...

        foreach ($chunk as $invite) {
            try {
                // ...
                $publisher->publish(
                    professionalId: $brandId,
                    frontendType: 'Warning',
                    category: 'invites',
                    title: 'Invite expired',
                    body: "Your invite to {$label} has expired.",
                    dedupeKey: "invite.expired.{$invite->id}",
                    ctaUrl: '/account/affiliates',
                    retentionConfigKey: 'invite',
                );
                $notified++;
            } catch (\Throwable $e) {
                // ...
            }
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CACHE-3** · P3 — `NudgeStuckOnboardingJob` per-professional synchronous notification publish inside chunk loop without batching
    - **Where:** app/Jobs/Notifications/NudgeStuckOnboardingJob.php:124-151
    - **Affects:** Brands stuck in onboarding. Notification delivery latency within the daily sweep; at target scale (30 brands) the stuck-onboarding cohort is trivially small.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror the fix for CACHE-2: collect publish payloads across the chunk and flush them as a batch rather than one-by-one.
        - Alternatively, since the stuck-onboarding cohort is intrinsically tiny (brands that signed up exactly 3/10/30 days ago and haven't advanced), this can be deferred until the NotificationPublisher itself gains a batch interface.
    - **Technical:** Same synchronous-per-row publish antipattern as `InviteExpirySweepJob`. The SQL query already chunks by 500 professionals; inside each chunk every qualifying professional triggers a `NotificationPublisher::publish()` call. The per-milestone dedupe key (`onboarding.nudge.{proId}.day_{day}`) means each brand gets at most one nudge per milestone, so the absolute row count is tiny — 30 brands × 3 milestones = 90 max notifications per day. The structural fix is low-effort but the operational impact at target scale is negligible, hence P3.
    - **Plain English:** Same mailroom pattern as the invite sweep, but for a much smaller pile of mail — at most three letters per brand, and only for the handful of brands that signed up exactly 3, 10, or 30 days ago. The clerk is still walking each letter individually, but there are so few letters that nobody would notice unless the business grew 100× overnight.
    - **Evidence:**
        ```php
        ->chunkById(500, function ($chunk) use ($publisher, $day, $milestone, &$nudged) {
            // ...
            foreach ($chunk as $row) {
                try {
                    // ...
                    $publisher->publish(
                        professionalId: $proId,
                        frontendType: $milestone['severity'],
                        category: 'profile_tasks',
                        title: $milestone['title'],
                        body: $milestone['body'],
                        dedupeKey: "onboarding.nudge.{$proId}.day_{$day}",
                        ctaUrl: '/account/overview',
                        primaryActionLabel: 'Continue setup',
                        retentionConfigKey: 'profile_task',
                    );
                    $nudged++;
                } catch (\Throwable $e) {
                    // ...
                }
            }
        }, 'p.id', 'id');
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-4** · P3 — `SendWeeklyAnalyticsNotificationJob` per-professional synchronous notification publish without batching
    - **Where:** app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php:87-94
    - **Affects:** Weekly analytics digest delivery to ~1,500 active professionals at target scale. Serial INSERT latency inside the Monday 09:00 UTC cron; no data loss risk.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect publish calls per chunk and dispatch a bulk-notification job or call a batch method on `NotificationPublisher`.
        - The chunk size of 200 already bounds per-query work; adding per-chunk publish batching keeps the total number of writes identical but collapses them into fewer round-trips.
    - **Technical:** The job queries professionals in chunks of 200, runs one batched metrics query per chunk against `commerce.orders`, then iterates each professional that has non-zero metrics and calls `NotificationPublisher::publish()`. At 1,500 active professionals the worst case is ~1,500 synchronous publish calls once per week — well within the 300s job timeout, but structurally the same per-row-loop antipattern as the other notification sweeps. The batched-metrics query (one per chunk) is optimal; only the publish side remains unbatched.
    - **Plain English:** Every Monday morning the clerk pulls a list of everyone who earned commission last week, then visits each person's desk individually to hand them a printed summary. At 50 desks this is a pleasant walk; at 1,500 desks the clerk is still walking at lunchtime. Dropping stacks of summaries at each department's mailroom instead of desk-by-desk would finish before the first coffee.
    - **Evidence:**
        ```php
        foreach ($professionals as $professional) {
            try {
                // ...
                $metrics = $metricsByPro->get($professional->id);
                if ($this->notifyProfessional($publisher, $professional, $metrics, $yearWeek)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                // ...
            }
        }

        // notifyProfessional() calls:
        $publisher->publish(
            professionalId: $professional->id,
            frontendType: 'Info',
            category: 'analytics_weekly',
            title: 'Your weekly analytics',
            body: $body,
            dedupeKey: "analytics.weekly.{$professional->id}.{$yearWeek}",
            ctaUrl: '/account/store?section=analytics',
            retentionConfigKey: 'analytics_weekly',
        );
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CACHE-5** · P3 — `CheckStreamingLiveStatusJob` loads full `Block` Eloquent models to extract two scalar settings fields
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:64-84
    - **Affects:** Streaming live-status polling every 2 minutes. Hydrates full Eloquent models for every block with `live_check_enabled=true` when only `settings->platform` and `settings->handle` are needed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Block::query()->chunkById(...)` with a `DB::table('site.blocks')->select('id', 'settings')->...` that hydrates only the two JSONB fields needed.
        - Or use `->select(['id', 'settings'])` on the Eloquent query and avoid hydrating relations/timestamps.
    - **Technical:** Category 5 — eager-loaded Eloquent collections that hydrate full models where a lightweight query would do. The job runs every 2 minutes and iterates every active streaming block. At pre-beta scale (tens of blocks) the overhead is negligible; at target scale with 30 brands each potentially running streaming blocks, the total block count is still small (< 200). The `chunkById(500)` bounds memory per chunk, but each chunk still instantiates full `Block` models with all attributes, casts, and relations — `settings` is JSONB-cast, `created_at`/`updated_at` are Carbon-cast, soft-delete checks fire. A `selectRaw` on the JSONB fields would avoid all of that.
    - **Plain English:** Every two minutes the system opens every streaming-enabled block's full personnel file just to read two lines — the platform name and the streamer handle. It's like pulling a heavy filing-cabinet drawer all the way out to read a sticky note on the front. The drawer action is chunked so it never pulls more than 500 files at once, but using a lighter index-card system would be faster and use less energy.
    - **Evidence:**
        ```php
        Block::query()
            ->where('block_group', 'links')
            ->whereRaw("settings->>'live_check_enabled' = ?", ['true'])
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->chunkById(500, function ($blocks) use (&$handlesByPlatform, $streamingPlatforms): void {
                foreach ($blocks as $block) {
                    $settings = is_array($block->settings) ? $block->settings : [];
                    $platform = $settings['platform'] ?? null;
                    $handle = $settings['handle'] ?? null;
                    if (
                        $platform
                        && $handle
                        && in_array($platform, $streamingPlatforms, true)
                    ) {
                        $handlesByPlatform[$platform][] = $handle;
                    }
                }
            });
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#CACHE-6** · P2 — No `Cache::remember` / `cache()->remember` guarded by single-flight lock found in job layer, but missing audit of controller/service hot-read paths
    - **Where:** app/Jobs/ (all files reviewed — no `Cache::remember` calls present); controllers and services outside this file set are unexamined
    - **Affects:** Dashboard and public-site controllers that may use bare `Cache::remember` without `CacheLockService::rememberLocked` — cold-cache stampede risk after deploy or eviction.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Run `rg "Cache::remember\|cache\(\)->remember" app/Http app/Services` and sweep every call site for a missing `CacheLockService::rememberLocked` guard.
        - Apply the canonical replacement: `rememberLocked` + 60s TTL + ±20% jitter + SWR stale serving + push-invalidate on every write path.
    - **Technical:** The job layer is clean — every cache interaction in the provided files uses either `CacheLockService::rememberLocked` (WarmPublicSiteCacheJob), `Cache::deleteMultiple` with proper :stale-key preservation (InvalidateBrandAffiliatesCacheJob), or Redis locks (ProcessImageVariantsJob, ProcessVideoVariantsJob). However, the high-value targets listed in the lens (`ProfessionalAnalyticsController`, `StaffStatsController`, site-analytics ingest paths, `CacheKeyGenerator` call sites) were not included in the provided file set. The commerce analytics rebuild deployed 2026-05-06 proved that bare `Cache::remember` in dashboard controllers caused thundering-herd stampedes on cold cache after every deploy. The job-layer audit alone cannot confirm that the controller/service layer has been brought up to the same standard. This finding is a pointer to complete the sweep.
    - **Plain English:** The engine room (jobs) is spotless — every cache interaction uses the right locking pattern. But we haven't checked the shop floor (the dashboard controllers that serve pages to users). The last time we looked there, we found bare cache calls that would all expire at the same moment after a deploy and cause a stampede of expensive database queries. Think of it like checking all the circuit breakers in the basement but not the outlets upstairs. This finding is a nudge to finish the inspection.
    - **Evidence:**
        ```
        # Job layer: clean
        $ grep -r "Cache::remember\|cache()->remember" app/Jobs/
        (no results in provided files)

        # Controller/service layer: unexamined
        # Scope gap — the files listed as high-value targets in the lens
        # (ProfessionalAnalyticsController, StaffStatsController, etc.)
        # were not provided in this audit batch.
        ```
    - `[DRAFT, confidence: 0.6]`
