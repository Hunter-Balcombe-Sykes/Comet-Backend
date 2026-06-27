# Scaling Antipatterns Audit — 2026-05-19

**Branch:** development
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Observers/Core/SiteObserver.php`
- `app/Observers/Core/BrandPartnerLinkObserver.php`
- `/Users/joshuahunter/Downloads/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md` (architecture plan)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

> **Adjudication note — CACHE-1 dropped:** DeepSeek claimed `BrandPartnerLinkObserver` dispatches "three separate downstream jobs." Actual source (verified via `Read`) shows one queued job (`SyncSubdomainToKvJob::dispatch()`) plus two synchronous in-process service calls (`siteCache->forgetHydrogen*()`, `publisher->publish()`). The evidence block cited §28.16 of the planning doc, which explicitly states "No change required, verified." Finding was both factually wrong about the dispatch count and cited evidence that contradicts its own conclusion — dropped.

---

## P2 — Should fix

- [ ] **#CACHE-2** · P2 — SiteObserver does not push-invalidate Cloudflare edge cache on site content changes
    - **Where:** `app/Observers/Core/SiteObserver.php:39–63`
    - **Affects:** All individual-type professionals once individual sitepages ship — profile edits will not purge the Cloudflare `caches.default` entry, so visitors see stale HTML until the `s-maxage=300` window expires. At the planned scale (any individual professional who edits their profile), every save takes up to 5 minutes to propagate to new visitors.
    - **Effort:** M (~2–4h) — requires building `CloudflarePurgeService` + `CloudflareCachePurgeJob` (§28.7 of the architecture plan) before this dispatch site can be wired.
    - **What to do:**
        - Build `app/Services/Cloudflare/CloudflarePurgeService.php` wrapping the Cloudflare cache purge API (`POST /zones/{zone_id}/purge_cache`).
        - Build `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php` with its own inlined backoff (max 3 attempts, exponential) — not the `HasCloudflareRetryPolicy` trait which is KV-specific.
        - In `SiteObserver::saved()`, after the existing `siteCache->invalidateSite()` call, dispatch `CloudflareCachePurgeJob::dispatch($site->professional->handle)` unconditionally (not only on subdomain change) so content edits purge the edge.
        - Apply the same dispatch from `AccountTypeTransitionService::transition()` when account type changes, since the KV routing entry changes even if Site content doesn't.
    - **Technical:** The application-level Redis cache is correctly invalidated on every `saved()` via `siteCache->invalidateSite()`. But the Cloudflare Worker edge cache (`caches.default`) is populated explicitly via `ctx.waitUntil(cache.put(request, response.clone()))` — per Cloudflare docs, `Cache-Control` headers alone do NOT auto-expire Workers cache entries; they must be purged via the API. Without `CloudflareCachePurgeJob`, the cache is TTL-only: content changes are invisible to edge visitors for up to 300 seconds. This is category (3) — push-invalidation missing on the write path. The canonical fix is the same push-invalidate-on-write pattern used by `CacheLockService::rememberLocked` in the commerce read path.
    - **Plain English:** When a professional updates their profile page, the app correctly clears its own internal memory of the old version. But it forgets to tap the front-door bouncer (Cloudflare's edge servers) on the shoulder. Anyone visiting that professional's page in the next 5 minutes sees the old version, because Cloudflare is still holding onto its cached copy. The fix is a quick "hey, please forget that page" call to Cloudflare every time a profile is saved.
    - **Evidence:**
        ```php
        // app/Observers/Core/SiteObserver.php:39–63
        public function saved(Site $site): void
        {
            try {
                $this->siteCache->invalidateSite($site);  // Redis only — no Cloudflare purge
            } catch (\Throwable $e) { ... }

            // Warm cache asynchronously if published
            if ($site->is_published) {
                try {
                    WarmPublicSiteCacheJob::dispatch(strtolower($site->subdomain))->afterCommit();
                } catch (\Throwable $e) { ... }
            }

            // Sync KV when site is first created or subdomain changes.
            if ($site->wasRecentlyCreated || $site->wasChanged('subdomain')) {
                // ... SyncSubdomainToKvJob + ProvisionBrandDnsJob dispatched
                // No CloudflareCachePurgeJob dispatch exists anywhere
            }
        }
        ```

- [ ] **#CACHE-3** · P2 — `SyncSubdomainToKvJob` hard-deletes the KV entry for professionals with no brand link instead of upserting an `individual` routing record
    - **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:58–71`
    - **Affects:** All non-brand professionals without an active `BrandPartnerLink` — their `<handle>.partna.au` subdomains 404 at the Cloudflare Worker immediately after their KV entry is deleted. When the individual sitepages feature ships, this code path fires for every individual professional and for every partner who leaves a brand, producing a 404 window until the KV entry is manually recreated. At the pre-beta scale (handful of professionals) the impact is small; at 30 brands × 50 affiliates the churn volume makes this a steady source of stale-404 gaps.
    - **Effort:** S (~0.5–1h) — the branch is a single conditional swap; requires `SyncSubdomainToKvJob` update + a backfill to write `{type:'individual'}` for existing unaffiliated professionals.
    - **What to do:**
        - Replace the `$kv->delete($current)` branch with `$kv->put($current, ['type' => 'individual'], null)` when `$siteUrl` is null/empty and the professional is not a brand.
        - Keep genuine delete calls (handle retirement, professional hard-deletion) in `RetireSubdomainFromKvJob` — which already exists for that purpose.
        - After deploying, run a one-off backfill to write `{type:'individual'}` for every non-brand, non-affiliate professional whose KV entry is currently absent.
        - Update the inline comment that currently says "retire the canonical entry so the Worker 404s" to reflect the new intent.
    - **Technical:** This is the delete-then-rebuild antipattern in miniature — the same shape eliminated from commerce aggregates. The KV DELETE forces the Cloudflare Worker into a 404 branch; a later KV PUT when a brand link is created requires a full round-trip write. The canonical replacement is an upsert: always write a routing record and change its `type` field to reflect current state (`brand`, `affiliate`, or `individual`). This mirrors the signed-delta upsert pattern from the commerce rollup fix. Genuine deletion (professional removal, handle retirement) belongs exclusively in `RetireSubdomainFromKvJob`, which already exists — the architecture test `SubdomainKvWritersTest` (planned in §51) will enforce this boundary.
    - **Plain English:** When a professional has no active brand partnership, the system currently rips their address out of the routing phone book entirely — so anyone who dials their subdomain gets a "number not found" message. When they later join a brand, the system has to add them back from scratch. The fix is to never remove the entry — just update it to say "this person is a solo professional right now" — the same way the commerce system switched from "erase and rebuild" to "update in place."
    - **Evidence:**
        ```php
        // app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:52–71
        $siteUrl = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $pro->id)
            ->whereNotNull('site_url')
            ->orderBy('slot')
            ->value('site_url');

        if (! $siteUrl) {
            // No brand connection — retire the canonical entry so the Worker 404s.
            try {
                $kv->delete($current);
            } catch (\Throwable $e) {
                Log::warning('SyncSubdomainToKvJob: delete failed for unconnected affiliate', [
                    'professional_id' => $pro->id,
                    'handle'          => $current,
                    'message'         => $e->getMessage(),
                ]);
            }

            return;
        }
        ```

`★ Insight ─────────────────────────────────────`
**On adjudication precision:** CACHE-1 was dropped because the DeepSeek evidence block cited a planning doc paragraph that literally ends "No change required, verified" — the draft found a finding in text that documented *why the finding doesn't apply*. This is a common DeepSeek pattern: it scores context-proximity over semantic meaning when pulling evidence.

**On upsert vs delete-then-rebuild (CACHE-3):** The `SyncSubdomainToKvJob` delete branch is structurally identical to the `DELETE`+`INSERT` aggregate rebuilds the commerce phase eliminated. The KV store's consistency guarantee only holds if writes are monotonic — deleting the entry creates a 404 window proportional to queue latency between the delete job completing and the next create job running.

**On cache layer separation (CACHE-2):** The SiteObserver correctly handles the Redis (application) cache layer but has no awareness of the Cloudflare edge cache layer above it. Systems with multiple caching tiers need push-invalidation at every tier on the write path — invalidating only the closest tier leaves the outer tiers serving stale content until TTL.
`─────────────────────────────────────────────────`
