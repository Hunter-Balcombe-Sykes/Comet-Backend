# Edge Worker Audit — 2026-07-28

**Branch:** development
**Lens:** Edge worker: Cloudflare routing, KV contract, edge-cache correctness, takedown latency, poisoning
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- cloudflare-worker/src/index.js
- cloudflare-worker/wrangler.toml
- cloudflare-worker/README.md
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Moderation/PurgeModerationCacheJob.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Platforms/IntegrationConnectionCacheRefresher.php
- app/Services/Site/SubdomainAvailabilityService.php
- app/Services/User/AccountDeletionService.php (excerpt)
- app/Observers/Core/SiteObserver.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/User/UserObserver.php
- config/partna.php (reserved_subdomains, cache.*, handle.*, cloudflare_purge.*)
- config/horizon.php (excerpt)
- tests/Feature/Subdomain/ReservedSubdomainWorkerSyncTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 1 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [x] **#EDGE-1** · P1 — A hard-deleted user's stale edge cache can survive to serve a different professional who reclaims the same handle
    - **Where:** app/Observers/User/UserObserver.php:161-190 (`deleted()`), app/Services/User/AccountDeletionService.php:787-815 (force-delete + KV retire), app/Observers/Core/SiteObserver.php:25-84 (`saved()`), app/Services/Site/SubdomainAvailabilityService.php:69-74
    - **Affects:** Any professional who claims a handle recently vacated by a hard-deleted account (GDPR purge, staff force-delete, or a moderation-driven deletion); their visitors, who may be served the previous (deleted / possibly moderation-actioned) owner's cached HTML instead of the new owner's page.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `AccountDeletionService`, alongside the existing KV-retire dispatch at the force-delete step, unconditionally dispatch `CloudflareCachePurgeJob::dispatch($handleSnapshot, $retireCustomDomain)` using the same pre-captured handle/custom-domain snapshot — don't rely on a future reclaimer's own `SiteObserver::saved()` purge to clean up the vacated handle's cache.
        - This purges both the primary and `/_swr-shadow` copies for the vacated handle (via `CloudflarePurgeService::purgeHandle()`, which already covers both) at the moment of deletion, closing the window regardless of how soon — or how — the handle is reclaimed.
    - **Technical:** `SubdomainAvailabilityService::check()` treats a handle as available the instant no row in `site.sites` (or an unexpired alias) claims it (`Site::whereRaw('lower(subdomain) = ?', ...)->exists()`). A hard-deleted account's `site.sites` row is removed via DB-level FK CASCADE as part of `AccountDeletionService`'s force-delete step — bypassing Eloquent events entirely — so the vacated handle can be claimed by a *different* professional with zero cooldown (unlike a rename, which reserves the old handle behind an alias for up to `redirect_days`=90). `UserObserver::deleted()` only dispatches `SyncSubdomainToKvJob` (KV retire) for the gone user — no `CloudflareCachePurgeJob` is ever dispatched for the vacated handle at deletion time. The Worker's KV-gates-cache design means this is harmless *while the handle stays unclaimed* (the `!entry` 404 branch never reaches `cache.match()`). But when a new owner claims the same handle, `SiteObserver::saved()` dispatches `CloudflareCachePurgeJob` (which purges the stale cache) and `SyncSubdomainToKvJob` (which flips KV back to `type:"individual"`, re-enabling `cache.match()` reads) as two independently queued jobs with no ordering dependency between them. `CloudflareCachePurgeJob` has `$tries=3`, `backoff=[5,15,60]` — a transient failure on its first attempt releases it back to the queue with a delay rather than blocking the worker, so the same single-process `cloudflare` queue worker moves on to `SyncSubdomainToKvJob` immediately. If that KV write lands before the purge retry succeeds (or if the purge silently exhausts all 3 tries — `failed()` only pages on-call when `moderationCaseId` is set, which it isn't for a routine site-save), a visitor hitting the new owner's freshly-claimed subdomain during that window is served the deleted owner's still-cached HTML from `caches.default`.
    - **Plain English:** When someone deletes their account, the system removes their listing from the routing table but never explicitly clears the cached copy of their old page. That's usually fine, because the routing table blocks the cached copy from being shown to anyone while the address is unclaimed. But if a completely different person grabs that same address soon after (which the system allows immediately once the account is gone), two separate background chores race to finish: one turns the address back on, the other clears out the old cached page. If clearing the old page hiccups or is a touch slow, visitors to the new owner's brand-new page can briefly see the previous owner's old content instead — a stranger's leftover page showing up under someone else's address.
    - **Evidence:**
        ```php
        // app/Observers/User/UserObserver.php — deleted()
        if ($professional->handle) {
            try {
                SyncSubdomainToKvJob::dispatch((string) $professional->id, (string) $professional->handle);
            } catch (\Throwable $e) {
                Log::warning('UserObserver: KV retire dispatch failed on delete', $this->logContext(__METHOD__, [
                    'user_id' => $professional->id,
                    'message' => $e->getMessage(),
                ]));
            }
        }
        ```
        ```php
        // app/Services/User/AccountDeletionService.php
        // EDGE-1: retire the now-orphaned custom-domain KV pointer (the handle key
        // is retired by UserObserver::deleted). Fires only when an active custom
        // domain existed at capture time.
        if ($retireCustomDomain) {
            SyncSubdomainToKvJob::dispatch((string) $professional->id, $handleSnapshot, $retireCustomDomain);
        }
        ```
        ```php
        // app/Services/Site/SubdomainAvailabilityService.php — check()
        $exists = Site::whereRaw('lower(subdomain) = ?', [$value])
            ->when($excludeSiteId, static fn ($q) => $q->where('id', '!=', $excludeSiteId))
            ->exists();
        if ($exists) {
            return $result(false, self::REASON_TAKEN);
        }
        ```
        ```php
        // app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
        public int $tries = 3;
        /** @var list<int> */
        public array $backoff = [5, 15, 60];
        ```

## P2 — Should fix

- [ ] **#EDGE-2** · P2 — `cloudflare-worker/README.md` describes an obsolete brand/affiliate architecture that no longer matches the Worker's actual KV contract
    - **Where:** cloudflare-worker/README.md:1-9, 92-106
    - **Affects:** Any engineer following this README during setup, DNS reprovisioning, or incident response for the Worker that fronts 100% of public sitepage traffic — the Worker has no test suite and no Nightwatch, so this README is one of the few written references for how it's supposed to behave.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rewrite the README's routing description, KV entry shapes, and observer list to match the current `individual`/`alias` contract in `cloudflare-worker/src/index.js` and the actual dispatchers (`SiteObserver`, `UserObserver`, `CustomDomainController`, `PurgeModerationCacheJob`, `ClaimSiteService`) — grep `SyncSubdomainToKvJob::dispatch` for the current call sites (already enumerated in this audit) rather than hand-guessing.
        - Update the smoke-test section's example KV entry (`{"type":"affiliate","redirect":...}`) and curl examples (brand storefront pass-through) to the current individual-sitepage-only model.
    - **Technical:** The README documents a pre-strip-down architecture — brand subdomains passing through to a Shopify Hydrogen storefront, affiliate subdomains 301-ing to `brand.partna.au/{handle}`, and observers (`ProfessionalObserver`, `BrandPartnerLinkObserver`, `BrandStoreSettingsObserver`) that no longer exist in this codebase. The live Worker (`index.js`) only understands `{type:"individual"}` and `{type:"alias", redirect:"https://<handle>.partna.au"}`, written exclusively by `SyncSubdomainToKvJob`. Per CLAUDE.md, the brand/affiliate model was removed 2026-05-22 as part of the standalone strip-down; this README was never updated to match. An engineer debugging a live incident (the Worker's only safety net, per this lens's own framing) who trusts this doc could seed KV with the wrong shape or misdiagnose routing behavior.
    - **Plain English:** The setup guide for the traffic router still describes an old version of the business — one with "brands" and "affiliates" and a Shopify storefront — that doesn't exist anymore. The actual code only knows about individual professionals' pages and old-handle redirects. If someone reads this guide during an outage or a fresh setup, they'll be working from an inaccurate map, which risks the wrong fix or wasted time exactly when speed matters most.
    - **Evidence:**
        ```md
        Routes every `*.partna.au` request:

        - **Brand subdomain** → pass through to origin (Shopify Hydrogen storefront)
        - **Affiliate subdomain** → `301` redirect to `brand.partna.au/{handle}`
        - **Reserved / unknown** → 404 or pass-through
        ```
        ```md
        Laravel's `SyncSubdomainToKvJob` writes one KV entry per professional. It's dispatched by:

        - `ProfessionalObserver` — when `handle` changes (the KV key itself changes)
        - `BrandPartnerLinkObserver` — when an affiliate joins or leaves a brand
        - `SiteObserver` — when a site is created or its subdomain changes (cascades to all linked affiliates if the site belongs to a brand)
        - `BrandStoreSettingsObserver` — when a brand's custom domain changes (cascades to affiliates)
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Handle-reclaim purge gap:** #EDGE-1
    - **Why grouped:** single-file, single-method fix (`AccountDeletionService`) with a narrow, well-defined blast radius.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#EDGE-2 — Stale Worker README** · reason: touches operational documentation only, independent of the code-fix bundle above; no shared file or root cause with #EDGE-1, so bundling would just delay a trivial doc fix behind a code review.
