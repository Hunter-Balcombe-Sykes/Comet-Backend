- [ ] **#LIFE-1** · P2 — `staffAnalyticsSummary` cache key does not embed the version token internally; relies on every caller to append `:v{version}` manually
    - **Where:** app/Services/Cache/CacheKeyGenerator.php (staffAnalyticsSummary method)
    - **Affects:** Staff-facing analytics dashboards — stale summaries survive `bumpAnalyticsVersion()` if any caller forgets the version suffix. Every other analytics key (`brandCommerceAnalytics`, `affiliateCommerceAnalytics`) embeds the version internally.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Embed the version token inside `staffAnalyticsSummary` itself (mirror `brandCommerceAnalytics`: read `analyticsSummaryVersion`, interpolate into the key).
        - Audit the one or two call sites that currently append `:v{version}` manually and remove the suffix — the key generator owns versioning now.
    - **Technical:** Every other analytics cache key in this class reads `Cache::get(analyticsSummaryVersion(...), 0)` and includes the version in the returned string. `staffAnalyticsSummary` is the sole exception — it returns an unversioned key and instructs callers to append the version themselves. This violates the single-point-of-truth discipline that makes `bumpAnalyticsVersion()` atomically invalidate every windowed key. At the scale target (200 brands with staff dashboards), a single forgotten suffix at a call site means staff see frozen data for up to 24h of TTL. Canonical replacement: **version-keyed cache** (`27c1b7a`).
    - **Plain English:** Imagine every door in a building auto-locks when the security guard presses one button — except the staff kitchen door, which only locks if whoever used it last remembers to turn the deadbolt. Same guard, same button, but the kitchen door's lock isn't wired in. When the guard presses the button, the kitchen stays unlocked. That's this cache key — when the system bumps the analytics version to invalidate all dashboards, the staff dashboard key doesn't get the memo unless the developer writing the dashboard code remembered to glue it on.
    - **Evidence:**
        ```php
        // ✅ brandCommerceAnalytics — version embedded internally
        public static function brandCommerceAnalytics(string $professionalId, string $from, string $to): string
        {
            $version = \Illuminate\Support\Facades\Cache::get(self::analyticsSummaryVersion($professionalId), 0);
            return "analytics:commerce:brand:v7:{$professionalId}:{$version}:{$from}:{$to}";
        }

        // ❌ staffAnalyticsSummary — version left to caller
        public static function staffAnalyticsSummary(string $professionalId, string $from, string $to): string
        {
            return "staff:analytics:summary:{$professionalId}:{$from}:{$to}";
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-2** · P2 — `SiteObserver::cascadeAffiliateKvSync` dispatches `SyncSubdomainToKvJob` in a synchronous `->each()` loop; no chunking, no single-job fan-out
    - **Where:** app/Observers/Core/SiteObserver.php (cascadeAffiliateKvSync method)
    - **Affects:** Brand site saves (subdomain change, publish toggle) — every linked affiliate gets a job dispatched synchronously inside the observer. At 50 affiliates this is ~50ms of Redis writes; at 500+ (scale target upper bound) the observer blocks the HTTP response for hundreds of milliseconds.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the affiliate-ID enumeration + dispatch loop into a single `CascadeAffiliateKvSyncJob` that chunks `BrandPartnerLink` rows internally (500 per chunk), mirroring `InvalidateBrandAffiliatesCacheJob`.
        - Replace the `->each(... dispatch ...)` loop with a single `CascadeAffiliateKvSyncJob::dispatch($brandProfessionalId)`.
    - **Technical:** `SiteObserver::saved` already ends with `InvalidateBrandAffiliatesCacheJob::dispatch($professionalId)` for cache invalidation — that job chunks internally, avoiding the O(N) synchronous-dispatch anti-pattern. The KV sync path one method earlier (`cascadeAffiliateKvSync`) was never updated to match and still does `BrandPartnerLink::pluck(...)->each(fn => SyncSubdomainToKvJob::dispatch(...))`. Each `dispatch()` call is a Redis LPUSH + serialization; at 500 affiliates that's ~500 sequential Redis commands inside a single observer execution. `SyncSubdomainToKvJob` already has `ShouldBeUnique` with a 45s lock, so the duplicated work is bounded, but the dispatch storm itself is unnecessary. Canonical replacement: **jittered per-tenant invalidation** (`38ff4fb`) — the cache-invalidation sibling already uses the chunking pattern; KV sync should match.
    - **Plain English:** When a brand changes their website address, the system needs to tell every one of their affiliates "your routing record needs updating." Right now it picks up the phone and calls each affiliate one at a time, waiting for the phone to ring before dialing the next. At 50 affiliates this takes a blink. At 500 affiliates the brand sits waiting while the system works through its phone list. The fix is to hand the whole list to one assistant who makes all the calls — the brand can hang up and get on with their day.
    - **Evidence:**
        ```php
        // SiteObserver.php — synchronous O(N) dispatch loop
        private function cascadeAffiliateKvSync(string $brandProfessionalId): void
        {
            if ($brandProfessionalId === '') {
                return;
            }

            BrandPartnerLink::query()
                ->where('brand_professional_id', $brandProfessionalId)
                ->pluck('affiliate_professional_id')
                ->each(function (string $affiliateId): void {
                    SyncSubdomainToKvJob::dispatch($affiliateId);
                });
        }

        // Same file, same observer — the cache-invalidation sibling already
        // uses the correct chunking pattern:
        InvalidateBrandAffiliatesCacheJob::dispatch($professionalId);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#LIFE-3** · P2 — `BrandAffiliateInviteObserver` sends invite emails synchronously via `Mail::send()` instead of queuing
    - **Where:** app/Observers/Core/BrandAffiliateInviteObserver.php (created method)
    - **Affects:** Bulk CSV invite imports — each `Mail::send()` blocks the observer (and thus the HTTP response) for the duration of an SMTP round-trip. At 100+ invites in a single CSV upload, this adds seconds of latency. Single invites are negligible.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `Mail::send(new AffiliateInvitedMail(...))` to `Mail::to($recipientEmail)->queue(new AffiliateInvitedMail(...))`.
        - The `AffiliateInvitedMail` Mailable already supports queuing (it's a standard Laravel Mailable); no Mailable-side changes needed.
    - **Technical:** The observer uses `Mail::send()` directly, which resolves the Mailable, renders the template, and talks to the SMTP server synchronously. The rest of the codebase already uses the queue pattern — `NotifyHandleAliasExpiry` does `Mail::to($email)->queue(...)`. The fix is a one-line change. This is not a race condition or correctness bug, but at the scale target (200 brands doing seasonal affiliate CSV imports), a brand uploading 200 invites would see their request hang for the cumulative SMTP latency of all 200 renders + sends. Canonical replacement: the **fan-out dedup** pattern — notifications should fan out through the queue, not block the write path. The `NotificationPublisher` path in the same observer already publishes asynchronously; the email path was simply missed.
    - **Plain English:** When a brand uploads a spreadsheet of 100 affiliate invites, the system sends each invite email before it says "done." If each email takes half a second to send, the brand stares at a loading spinner for 50 seconds. The rest of the system already knows how to hand emails to a queue and move on — this one spot just forgot. The fix is switching from "deliver now" to "hand to the delivery person and keep moving."
    - **Evidence:**
        ```php
        // BrandAffiliateInviteObserver.php — synchronous Mail::send()
        Mail::send(new AffiliateInvitedMail(
            recipientEmail: $recipientEmail,
            recipientFirstName: is_string($invite->first_name ?? null) && trim((string) $invite->first_name) !== ''
                ? trim((string) $invite->first_name)
                : null,
            brandName: $brandName,
            acceptUrl: $acceptUrl,
            expiresInDays: $expiresInDays,
        ));

        // Elsewhere in the codebase — the correct queued pattern is already in use:
        // NotifyHandleAliasExpiry.php
        Mail::to($email)->queue(new HandleAliasExpiringMail($alias, $bucket));
        ```
    - `[DRAFT, confidence: 0.95]`
