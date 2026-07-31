# Edge Worker Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Cloudflare Worker routing, KV contract, edge-cache correctness, takedown latency, poisoning
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `cloudflare-worker/src/index.js`
- `cloudflare-worker/wrangler.toml`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Services/Moderation/ModerationActionDispatcher.php`
- `app/Jobs/Moderation/PurgeModerationCacheJob.php`
- `config/partna.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **EDGE-1** · P1 — `hide_content` moderation decisions have no KV-level redundancy; a failed edge purge leaves the reported content live for up to 7 days (Category 3)
    - **Where:** `app/Services/Moderation/ModerationActionDispatcher.php:31` (`ACTIONS_BY_DECISION`), `app/Jobs/Moderation/PurgeModerationCacheJob.php:45-66`, `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php:29-35`
    - **Affects:** Any case resolved with `decision_type = hide_content` (the standard "take this specific content down" outcome) — visitors to the professional's page, and the reporter/staff who believe the content is gone.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a scheduled reconciliation sweep (mirroring `handles:prune-expired-aliases`) that finds `action_log` rows with `action_type='purge_cloudflare_cache'` still not confirmed complete after N minutes and re-dispatches `CloudflareCachePurgeJob`.
        - Alternatively/additionally, escalate `CloudflareCachePurgeJob::failed()` to page on-call the same way `NotifyOnCallStaffJob` does for CSAM, rather than a log-only `report($e)` + `Log::error`.
        - Document the asymmetry explicitly in `ModerationActionDispatcher`'s docblock so a future decision-type addition doesn't assume KV retirement is a universal safety net.
    - **Technical:** `ACTIONS_BY_DECISION['hide_content'] = ['notify_reported_user', 'purge_cloudflare_cache']` — unlike `hide_site`/`suspend_user`/`ban_user`/`csam_auto_suspend`, it does **not** dispatch `sync_subdomain_kv`'s site-suspending sibling actions. `PurgeModerationCacheJob::handle()` does call `SyncSubdomainToKvJob::dispatch($ownerId)` unconditionally, but that job only retires the KV entry when `!$pro->isActive() || $site->moderation_state === 'hidden'` (see `SyncSubdomainToKvJob.php:94,114`) — neither is true for a `hide_content` decision, so the KV entry stays `{"type":"individual"}` and the Worker keeps consulting `caches.default` on every request (`index.js:519-521` → `serveIndividual`). The **only** thing that can evict the pre-takedown render from the edge is `CloudflareCachePurgeJob` succeeding. That job's own retry ceiling (`$tries = 3`, `$backoff = [5, 15, 60]`, `$maxExceptions = 2`) is real but finite, and on exhaustion `failed()` only logs + reports — there is no automatic re-attempt and no secondary gate (unlike the whole-site-hide paths, which get the KV-retirement backstop for free). Worst case: the reported content — the exact thing staff decided to take down — continues rendering from the primary cache (24h) and then the stale shadow (7d) with zero further signal beyond one Nightwatch alert.
    - **Plain English:** When staff decide to hide one piece of reported content (not the whole page), the system's only way to make that change show up for visitors is to tell the edge network "please forget your copy of this page." If that one instruction fails to get through — say, a temporary hiccup talking to Cloudflare — nothing else catches it. The whole-site takedown path (suspending an entire account) has a second independent lock that also blocks the old page from being shown, but the single-piece-of-content takedown doesn't get that backup. So a reported item that staff believe is gone could keep showing to the public for up to a week, with only a quiet error log — no second check confirms it actually disappeared.
    - **Evidence:**
        ```php
        // app/Services/Moderation/ModerationActionDispatcher.php
        'hide_content' => ['notify_reported_user', 'purge_cloudflare_cache'],
        ```
        ```php
        // app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
        public int $tries = 3;
        public array $backoff = [5, 15, 60];
        public int $maxExceptions = 2;
        ```
        ```javascript
        // cloudflare-worker/src/index.js — cache is still consulted whenever KV says "individual"
        if (entry.type === "individual") {
          return serveIndividual(env, ctx, request, null);
        }
        ```

## P2 — Should fix

- [ ] **EDGE-2** · P2 — No automated check keeps the Worker's `RESERVED` set in sync with `reserved_subdomains` (Category 1)
    - **Where:** `cloudflare-worker/src/index.js:44-110` (`RESERVED`) vs `config/partna.php:71-143` (`reserved_subdomains`)
    - **Affects:** Public routing — a future edit that adds/removes an entry on only one side goes undetected until a real handle collision or 404 surfaces in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a CI check (Pest test or standalone script) that pulls `config('partna.reserved_subdomains')` and asserts it's a subset/superset match against a copy of the Worker's `RESERVED` set (or generate the Worker constant from the PHP config at build time).
        - Add the reciprocal `@sync` comment in `config/partna.php` naming `cloudflare-worker/src/index.js` explicitly (the Worker side already names the config file).
    - **Technical:** Diffed token-by-token, the two lists are currently identical (verified: all ~230 entries across every category match in both files). The gap is process, not a live bug: nothing fails a build if the two drift going forward, and the existing `SubdomainAvailabilityTest.php` only checks the PHP-side list in isolation. This matches the P2 hardening anchor — the failure mode requires a future edit that isn't policed today, not a value collision that exists now.
    - **Plain English:** Two files list the same "these website addresses are off-limits" words — one used by the traffic router, one by the backend that lets people claim an address. Right now they match, but nothing double-checks that they keep matching after future edits. It's like two people keeping the same blocklist in two separate notebooks with no one comparing them — eventually they'll drift, and either a real page will stop working or someone will be able to grab an address that should've been blocked.
    - **Evidence:**
        ```javascript
        // cloudflare-worker/src/index.js
        // Mirrors `reserved_subdomains` in config/partna.php (EDGE-6/EDGE-11). KEEP IN
        // SYNC: a subdomain missing here is sent to KV and 404s instead of passing
        // through to the apex origin.
        const RESERVED = new Set([
          "www", "api", "admin", /* ... */
        ]);
        ```
        ```php
        // config/partna.php
        'reserved_subdomains' => [
            'www', 'api', 'admin', /* ... */
        ],
        ```

- [ ] **EDGE-3** · P2 — Hardcoded `PARTNA_DOMAIN` / cache TTLs carry no `@sync` comment pointing at the backend config that assumes them (Category 7)
    - **Where:** `cloudflare-worker/src/index.js:42,112-118` vs `config/partna.php` (`public_domain`, `cache.purge_followup_seconds`)
    - **Affects:** Deploy correctness — a backend-side change to `PARTNA_PUBLIC_DOMAIN` or to the purge follow-up delay silently stops matching the Worker's hardcoded assumptions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment on `PARTNA_DOMAIN` naming `config('partna.public_domain')` as its mirror; add the reciprocal comment at the `public_domain` config key naming the Worker constant.
        - Add a comment on `PRIMARY_CACHE_TTL_S`/`STALE_SHADOW_TTL_S` noting that `CloudflareCachePurgeJob`'s `purge_followup_seconds` delay (120s default) is deliberately shorter than these TTLs and must stay that way.
    - **Technical:** The Worker hardcodes `"partna.au"` while the backend derives `public_domain` from `env('PARTNA_PUBLIC_DOMAIN', env('SIDEST_PUBLIC_DOMAIN', parse_url(env('APP_URL', ...))))` — a three-level fallback chain that could resolve differently per environment. `CloudflarePurgeService::purgeHandle()` already reads `config('partna.public_domain')` to build purge target URLs (line 103), so if that value ever diverges from the Worker's literal `"partna.au"`, purges would target the wrong host while the Worker still routes and caches under the old one. Same risk for the two TTL constants against `purge_followup_seconds`. The `RESERVED` set already carries a sync comment (EDGE-2); these constants don't.
    - **Plain English:** The Worker has the website's domain name and two cache-lifespan numbers baked directly into its code. The backend server also has opinions about these same values, driven by settings that can change per environment. If someone changes the backend setting without remembering the Worker has its own hardcoded copy, the two systems quietly start disagreeing — purges could target the wrong address, or a cleanup pass could fire before the cache it's meant to sweep is even due for its own refresh.
    - **Evidence:**
        ```javascript
        // cloudflare-worker/src/index.js
        const PARTNA_DOMAIN = "partna.au";
        const PRIMARY_CACHE_TTL_S = 86_400;
        const STALE_SHADOW_TTL_S = 7 * 86_400;
        ```
        ```php
        // config/partna.php
        'public_domain' => env(
            'PARTNA_PUBLIC_DOMAIN',
            env('SIDEST_PUBLIC_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost')
        ),
        ```

- [ ] **EDGE-4** · P2 — KV lookup failure for a `<handle>.partna.au` host falls through to `passThrough(request)`, an unconfirmed origin destination (Category 1/6)
    - **Where:** `cloudflare-worker/src/index.js` — `catch (err)` block in the subdomain KV lookup, inside the default `fetch` handler
    - **Affects:** Every visitor to any `<handle>.partna.au` page during a transient KV outage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - On KV failure for a subdomain (not the custom-domain branch, which already fails open reasonably since it has no dedicated 404), serve the branded `unclaimedHtml(subdomain)` with a distinct `X-Partna-Cache: kv-error` tag (still `noStore: true`) instead of `passThrough(request)`.
        - This keeps the UX consistent with the "unclaimed" 404 case and avoids sending traffic to a hostname the zone's actual origin DNS was never built to serve (this Worker is the only thing that knows how to route `<handle>.partna.au` — there is no confirmed non-Worker origin for it).
    - **Technical:** `wrangler.toml`'s `[[routes]] pattern = "*/*"` on `zone_name = "partna.au"` means this Worker is the sole handler for every request under the zone (including the wildcard subdomain). `passThrough()`'s `fetch(request)` on a KV error for `<handle>.partna.au` re-resolves against whatever DNS record backs the wildcard — almost certainly not a real application server, since individual-handle routing is Worker+KV+Service-Binding only. The actual destination can't be confirmed from the repo (Cloudflare zone/DNS settings are out of this lens's scope), which is itself the finding per the audit brief: any pass-through whose destination can't be confirmed should be flagged. Best case it's a generic error page; worst case a slow/hanging connection attempt against a placeholder IP.
    - **Plain English:** If the Worker's routing lookup service has a brief outage, instead of showing visitors the same clean "not found" page it normally shows, it tries to forward them to a backend address that most likely doesn't know how to answer for personal handles. That's confusing and inconsistent — visitors would get a different, probably uglier error depending on whether the outage hit the lookup service or something else entirely.
    - **Evidence:**
        ```javascript
        let entry = null;
        try {
          entry = await env.SUBDOMAIN_KV.get(subdomain, {type: "json"});
        } catch (err) {
          // KV transient failure — fail open to avoid blocking user traffic.
          console.error("KV lookup failed", {subdomain, err: String(err)});
          return passThrough(request);
        }
        ```

## P3 — Nice to have

- [ ] **EDGE-5** · P3 — `unclaimedHtml`'s CTA link hardcodes `https://partna.au` regardless of environment (Category 7)
    - **Where:** `cloudflare-worker/src/index.js` — `unclaimedHtml()`
    - **Affects:** Anyone viewing the branded 404 on a non-production deploy of this Worker (e.g., the `[env.staging]` target once it's wired up) — the CTA always points at prod.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Derive the link from `PARTNA_DOMAIN` (or the request's own hostname suffix) instead of the literal string, so a staging deploy links back to staging.
    - **Technical:** `unclaimedHtml(subdomain)` builds `<a href="https://partna.au">${cta}</a>` unconditionally. `PARTNA_DOMAIN` is already a module constant the function could reference; it just doesn't. Low impact since the function is only reachable when `RESERVED`/KV logic has already scoped the request to a `partna.au`-family host.
    - **Plain English:** The "go to the main site" button on the not-found page always sends people to the real production site, even when someone is testing a non-production copy of the router. A small polish item, not a safety issue.
    - **Evidence:**
        ```javascript
        const cta = safe ? "Claim this address" : "Go to partna.au";
        return `<!doctype html>
        ...
            <a href="https://partna.au">${cta}</a>
        ...`;
        ```

- [ ] **EDGE-6** · P3 — Staging KV namespace in `wrangler.toml` is still a placeholder TODO (Category 7)
    - **Where:** `cloudflare-worker/wrangler.toml:42-53`
    - **Affects:** Anyone who runs `wrangler deploy --env staging` before the referenced namespace exists — the deploy fails outright rather than silently writing into production (fail-safe), but the staging override is currently non-functional.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Either create the staging KV namespace (`wrangler kv namespace create SUBDOMAIN_KV_STAGING` + `--preview`) and paste the real IDs in, or remove the `[env.staging]` block entirely if it's vestigial now that the actual deployment model is "development env serves both API domains" with no separate Cloudflare staging tier (per current Laravel Cloud reality).
    - **Technical:** The block is self-documented with a `TODO(josh)` and exact commands, added specifically to prevent a staging deploy from clobbering the production `SUBDOMAIN_KV` namespace (EDGE-10, prior audit). It's a safe placeholder (deploy-time failure, not runtime corruption) but is dead config as written — worth resolving one way or the other so it doesn't linger indefinitely.
    - **Plain English:** There's a setup note left in the routing configuration reminding someone to create a separate practice-run address book before a test deployment can work, so a test run can never accidentally scribble on the real one. It's safe as-is (the test deploy would just fail loudly), but it's an unfinished chore worth closing out or removing.
    - **Evidence:**
        ```toml
        # EDGE-10: staging MUST NOT share the production SUBDOMAIN_KV — without this
        # override a `--env staging` deploy (or a staging backend KV backfill) would
        # write into the production routing table and poison prod. Give staging its own
        # namespace. TODO(josh): create it and paste the id below —
        [[env.staging.kv_namespaces]]
        binding = "SUBDOMAIN_KV"
        id = "REPLACE_WITH_STAGING_KV_NAMESPACE_ID"
        preview_id = "REPLACE_WITH_STAGING_KV_PREVIEW_ID"
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Worker/config sync hygiene:** #EDGE-2, #EDGE-3, #EDGE-6
    - **Why grouped:** All three are documentation/enforcement gaps between the Worker and its backend/config mirrors (RESERVED list, domain/TTL constants, staging namespace) — same files (`index.js` + `wrangler.toml` + `config/partna.php`), no behavioral risk, low effort each.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — KV-outage UX polish:** #EDGE-4, #EDGE-5
    - **Why grouped:** Both are minor Worker response-shape tweaks (fail-open branded 404 + CTA link) with no cross-cutting risk; both touch only `index.js`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#EDGE-1 — `hide_content` purge-only redundancy gap:** standalone — P1, touches the moderation enforcement pipeline (`ModerationActionDispatcher`/`PurgeModerationCacheJob`), needs its own plan for the reconciliation-sweep or escalation design and its own sign-off given the content it protects is, by definition, already flagged as reported/harmful.
