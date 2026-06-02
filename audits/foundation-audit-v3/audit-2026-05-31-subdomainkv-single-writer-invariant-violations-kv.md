`★ Insight ─────────────────────────────────────`
The key investigation here: `RetireSubdomainFromKvJob::dispatch()` has zero call sites in PHP — the Grep confirms it only appears in comments and the class definition itself. DeepSeek's conditional framing ("if still live") masked the real finding: the deletion path in KV is permanently uncovered. Meanwhile, `UpdateSiteAction` creates both `UserHandleAlias` and `SiteSubdomainAlias` rows inside a DB transaction before `SyncSubdomainToKvJob` is dispatched, so DeepSeek's KV-3 staff-bypass hypothesis doesn't survive verification — `StaffUpdateUserRequest` also doesn't even expose `handle` as a field.
`─────────────────────────────────────────────────`

# KV & Subdomain Routing Audit — 2026-05-31

**Branch:** development
**Lens:** SUBDOMAIN_KV single-writer invariant violations, KV/DB drift, sync job idempotency, alias expirationTtl correctness, 301 alias-vs-canonical correctness at scale
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `cloudflare-worker/src/index.js`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `app/Services/Cloudflare/CloudflareKvService.php`
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Services/Site/UpdateSiteAction.php`
- `app/Services/Site/ReclaimHandleAction.php`
- `app/Observers/User/UserObserver.php`
- `app/Observers/Core/SiteObserver.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffSiteManagementController.php`
- `app/Http/Controllers/Api/User/Site/HandleReclaimController.php`
- `app/Http/Requests/Api/Staff/UserSite/StaffUpdateUserRequest.php`
- `routes/console.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#KV-1** · P1 — Alias 301 redirects discard the request path and query string, breaking every deep link through a renamed handle
    - **Where:** `cloudflare-worker/src/index.js` (alias branch, ~line 200–210)
    - **Affects:** Any visitor following a bookmarked or shared deep link (e.g. `/gallery`, `/services?category=photography`) to an old handle after a professional renames. They land on the new homepage instead of the intended page.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the Worker's alias branch, replace `Location: entry.redirect` with a URL that appends the incoming request's pathname and query string.
        - Strip any trailing slash from `entry.redirect` before concatenating to avoid double `//`.
        - The Laravel backend (`SyncSubdomainToKvJob`) does not need changes — `entry.redirect` is always a bare origin (`https://newhandle.partna.au`), so path/query must be appended in the Worker.
    - **Technical:** `SyncSubdomainToKvJob` writes alias entries where `entry.redirect = $canonical`, and `$canonical` is always a root URL (e.g. `https://newhandle.partna.au` — no path). When the Worker matches an alias entry it constructs the `Location` header from `entry.redirect` verbatim, discarding `url.pathname` and `url.search` from the incoming request. The fix is one line in the Worker: `Location: entry.redirect.replace(/\/$/, "") + url.pathname + url.search`. The Worker already has `url.pathname` in scope (used for the `staleShadowKey` calculation), so no new parsing is needed.
    - **Plain English:** When a professional changes their public web address, the old address should forward anyone who visits it to the new one — including the specific page they were trying to reach. Right now the system keeps the page link but throws away the destination. If someone bookmarked the photography gallery at the old address (`/gallery`), clicking that link after the rename dumps them on the new homepage. It's like a post-office forwarding your letters but throwing away everything written inside the envelope — the letter arrives, but completely blank.
    - **Evidence:**
        ```js
        // cloudflare-worker/src/index.js — alias branch
        if (entry.type === "alias" && typeof entry.redirect === "string") {
            const h = new Headers({
                Location: entry.redirect,
                // Without this, browsers cache 301s indefinitely. A handle rename
                // would leave stale redirects in client caches until users manually clear.
                "Cache-Control": "max-age=0, must-revalidate",
            });
            applySecurityHeaders(h);
            return new Response(null, {status: 301, headers: h});
        }
        ```

---

## P2 — Should fix

- [ ] **#KV-2** · P2 — Professional deletion leaves the KV routing entry permanently live; `RetireSubdomainFromKvJob` is a dead class with no dispatch sites
    - **Where:** `app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php` (dead class); `app/Observers/User/UserObserver.php:deleted` (missing dispatch); `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php` (contradictory comment)
    - **Affects:** Soft-deleted and hard-deleted professionals whose handles are not reclaimed for up to 7 days (the weekly backfill window). The old KV entry keeps routing traffic through the `PARTNA_PAGES` Worker, which then hits the backend and receives a 404. Visitors see a PARTNA_PAGES error page rather than the cleaner Worker-level 404. More critically, the comment in `SyncSubdomainToKvJob` states "Genuine deletes go through `RetireSubdomainFromKvJob`, NOT this job" — creating false confidence that deletion is handled when it provably isn't.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete `app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php` — it has zero dispatch sites and the alias-write architecture made it obsolete.
        - In `UserObserver::deleted`, dispatch a KV cleanup for the deleted professional's handle. Since `SyncSubdomainToKvJob` returns early when the user isn't found via `User::query()->find()` (soft-deleted models are excluded), you need either: (a) a thin `DeleteSubdomainFromKvJob` that calls `CloudflareKvService::delete()` with the handle captured before deletion, or (b) dispatch `SyncSubdomainToKvJob` and change it to also handle the null-user case by calling `$kv->delete($capturedHandle)`.
        - Remove the contradictory comment from `SyncSubdomainToKvJob`: "Genuine deletes go through `RetireSubdomainFromKvJob`, NOT this job."
    - **Technical:** `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController::forceDestroy` calls `$professional->forceDelete()`, which fires `UserObserver::deleted`. That observer only calls `$this->userCache->invalidateUser($professional)` — it dispatches no KV job. `SyncSubdomainToKvJob::handle()` opens with `User::query()->find($this->userId); if (!$pro || !$pro->handle) { return; }` — after soft deletion, `find()` returns null and the job silently no-ops. There is no code path that calls `CloudflareKvService::delete()` on a deleted professional's handle. The weekly `partna:backfill-subdomain-kv --all` cron (Sunday 04:00 UTC) is the only recovery mechanism, giving a worst-case 7-day window of stale KV routing. For soft deletes the consequence is an unnecessary PARTNA_PAGES round-trip to a 404; for hard deletes the handle could also sit in KV for up to a week before another user can cleanly reclaim it with a fresh `individual` entry.
    - **Plain English:** When a professional's account is deleted (either by themselves or by staff), the routing table that tells the internet where their web address points is never cleaned up. The old address keeps sending traffic through the system — which correctly tells visitors "this page doesn't exist," but it takes an extra detour to do so. Worse, there's a comment in the code that confidently says "deletions are handled by a separate cleanup job," but that cleanup job is never actually triggered. It's like decommissioning a post office sorting station but leaving the signage up — mail doesn't go missing, but it still travels the long way around before bouncing back.
    - **Evidence:**
        ```php
        // RetireSubdomainFromKvJob.php — zero dispatch sites found in codebase
        class RetireSubdomainFromKvJob implements ShouldQueue
        {
            public function handle(CloudflareKvService $kv): void
            {
                if ($this->handle === '') {
                    return;
                }
                $kv->delete($this->handle);
            }
        }
        ```
        ```php
        // UserObserver.php::deleted — no KV dispatch
        public function deleted(User $professional): void
        {
            try {
                $this->userCache->invalidateUser($professional);
            } catch (\Throwable $e) {
                Log::warning('Professional cache invalidation failed on delete', ...);
            }
        }
        ```
        ```php
        // SyncSubdomainToKvJob.php — contradictory comment
        // Genuine deletes go through
        // RetireSubdomainFromKvJob, NOT this job.
        ```

---

## P3 — Nice to have

- [ ] **#KV-3** · P3 — Alias TTL floor grants an extra 60 seconds of KV lifetime to aliases that expire between the DB query and job execution
    - **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:writeAliasEntries` (TTL calculation)
    - **Affects:** Aliases whose `expires_at` falls in the narrow window between the DB query (`expires_at > now()` filter) and when the queued job actually executes. They receive a 60-second KV lifetime instead of being skipped or expiring immediately. Practically invisible to users — the worst case is a visitor following a just-expired alias and getting a redirect instead of a 404 for up to 60 seconds.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After computing `$ttl`, add an early-continue guard: `if ($ttl !== null && $ttl <= 0) { continue; }` to skip KV writes for aliases that have already crossed their expiry by the time the job runs.
        - Alternatively, compute the floor as `max(1, ...)` and accept the one-second KV lifetime (Cloudflare enforces 60s minimum anyway, so the key will exist exactly 60 seconds — but this keeps the intent explicit rather than silently dropping to the 60s floor).
    - **Technical:** `Carbon::diffInSeconds($date, false)` returns a negative integer when `$date` is in the past (the `false` argument disables Carbon's default absolute-value behaviour). A DB alias row satisfying `expires_at > now()` at query time can expire by the time the job dequeues — common when the worker queue is busy. `max(60, negative_int)` evaluates to 60, granting a full additional minute of KV life beyond the configured expiry. `handles:prune-expired-aliases` hard-deletes the DB row daily, so the KV entry outlives the DB row by at most 60 seconds. The `CloudflareKvService::put` docblock already notes "Cloudflare KV enforces a minimum of 60s; callers should pre-clamp" — a skip-if-expired guard is the correct complement to that constraint.
    - **Plain English:** When a temporary forwarding address expires, the database marks it dead right away. But if the background job that updates the forwarding table was slightly delayed, it might still write a 60-second entry for an address that was supposed to be dead. For 60 seconds, someone clicking that link still gets forwarded instead of seeing "not found." It's harmless — arguably friendlier than a dead link — but it's technically one minute past the intended cutoff. The fix is a simple "if it's already expired by the time we get to it, just skip it."
    - **Evidence:**
        ```php
        // SyncSubdomainToKvJob.php::writeAliasEntries
        $ttl = $alias->expires_at
            ? max(60, (int) now()->diffInSeconds(Carbon::parse($alias->expires_at), false))
            : null;

        $kv->put($handle, ['type' => 'alias', 'redirect' => $canonical], $ttl);
        ```

`★ Insight ─────────────────────────────────────`
The most important adjudication call here: DeepSeek's KV-3 (staff bypass) was dropped because `UpdateSiteAction` is the single handle-change path for both user and staff routes — confirmed by reading `StaffSiteManagementController` and `StaffUpdateUserRequest`. The form request doesn't even include `handle` in its rules, making the bypass structurally impossible. Meanwhile, the real gap (deletion events leaving stale KV entries) was hidden inside DeepSeek's conditional framing of KV-2 ("if still live") — tooling confirmed the job is truly dead, recentering the finding on the uncovered deletion path.
`─────────────────────────────────────────────────`
