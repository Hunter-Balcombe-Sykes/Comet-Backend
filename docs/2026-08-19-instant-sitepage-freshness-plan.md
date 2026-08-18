# Instant sitepage freshness — plan (2026-08-19)

Run this as its OWN session (backend lane + the router worker in this repo;
one small dashboard edit in the monorepo). Delete this file when shipped.

## Goal

An owner edits something in the dashboard (pool item, category, logo, design
kit, contact…) and the live sitepage `<handle>.partna.au` reflects it in
**~1–3 s**, every time — including the second and third edit made in quick
succession. Today a single edit takes ~20–40 s and a rapid follow-up edit can
sit stale for **2–4 minutes**.

## Why it is slow today (measured from the code, not guessed)

| Layer | Behaviour | Verdict |
|---|---|---|
| Backend payload cache (`IndividualProfilePayloadBuilder`, 60 s) | Keyed on `site.sites.updated_at`; every `SiteCacheLanes::bust()` write bumps it → instant | fine |
| Router edge cache (`cloudflare-worker/src/index.js`) | HTML cached **24 h** (`PRIMARY_CACHE_TTL_S`), leaves only by purge | fine — IF purge is fast |
| **`CloudflarePurgeService::purgeHandle()`** | Enumerates up to **~2,481 URLs** (root + every page + ≤1,200 item deep-links × 2 hosts + `/_swr-shadow` twins + 3 API URLs), chunked 30/request → **~83 sequential purge calls**, paced (`paceBetweenChunks`) | **the bottleneck: ~20–40 s per purge** |
| **`CloudflareCachePurgeJob`** | `ShouldBeUnique`, `$uniqueFor = 240` — any dispatch for the same handle while a purge is queued/running is **dropped**; only the delayed follow-ups (≥120 s) cover it | **the collision: rapid second edit → 2–4 min stale** |
| Visitor browser | `max-age=15` on the page | minor |

The pages app's `PAGE_CACHE`/`PROFILE_FETCH_CACHE_TTL` (launch.ts, 30 s
pre-launch) are NOT the lever — the router overlays its own 24 h `s-maxage` on
top (`withCacheTtl`). Do not shorten the 24 h TTL to "fix" this; it trades
correctness for load and leaves the collision in place.

## Fact established from Cloudflare docs (2026-08-19)

Purge by **hostname / prefix / tag** is available on **all plans** since
2025-04-03 (changelog "All cache purge methods now available for all plans").
Limits per account: Free 5 req/min, Pro 5 req/s, Business 10 req/s; max 100
operations per request. One prefix purge per edit is well inside every tier.

## Files in scope (NOTHING else)

- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `tests/Unit/Services/Cloudflare/CloudflarePurgeServiceTest.php`
- `tests/Unit/Jobs/CloudflareCachePurgeJobTest.php`
- `tests/Feature/PublicSite/PresenceProbeEscalationTest.php` (only if it pins purge shape)
- `config/partna.php` (purge follow-up schedule keys, if any need retiring)
- `cloudflare-worker/src/index.js` + its `test/` (Phase 2 only)
- monorepo `apps/dashboard` — the "View site" link (Phase 2 only; find it with
  `grep -rn "View site" components/` — it lives in the account menu)

## Phase 0 — verify prefix purge evicts Worker-cache entries (10 min, dev)

The router writes HTML with `caches.default` (Workers Cache API) under keys
like `https://<handle>.partna.au/<path>` and `/_swr-shadow/<path>`. Single-URL
purge evicts those today, so prefix purge should too — prove it before
touching code:

1. Pick a dev handle with a live sitepage (e.g. `gsnwilliams`). `curl -sI
   https://<handle>.partna.au/` twice → second is `cf-cache-status: HIT`.
2. Purge by prefix once, by hand:
   `POST https://api.cloudflare.com/client/v4/zones/{zone}/purge_cache`
   body `{"prefixes":["<handle>.partna.au/"]}` (token from the same secret
   `CloudflarePurgeService` uses — see its constructor / `.env`).
3. `curl -sI` again → must be `MISS` (or `EXPIRED`), and
   `https://<handle>.partna.au/about` too (proves all paths went, including
   the shadow keys, which sit under the same host prefix).
4. Also purge the API host once and confirm `dev-api.partna.au/api/public/profiles/<handle>`
   misses — OR keep the 3 API URLs on the single-file purge (cheap: one
   chunk). Either is fine; note which you chose.

If step 3 fails, STOP: switch the plan to purge-by-**tag** — router adds
`Cache-Tag: handle:<handle>` on every cached response (`withCacheTtl`) and the
backend purges `{"tags":["handle:<handle>"]}`. Same shape, one extra router
line.

## Phase 1 — one purge call per edit (backend)

1. `CloudflarePurgeService::purgeHandle($handle, $customDomain)`:
   - Replace the URL enumeration (root variants, page list, item deep-links,
     swr-shadow twins) with ONE request:
     `{"prefixes": ["<handle>.<baseDomain>/", "<customDomain>/" (when set)]}`.
   - Keep the 3 backend API URLs (`/api/public/profiles/<h>`, `/integrations`,
     `/platforms`) as a single-file purge in the same job (one chunk) unless
     Phase 0 step 4 showed the API host can go by prefix too.
   - Delete `addressableItemSegments()` and the pacing budget prose/const —
     nothing left to pace. `paceBetweenChunks()` can stay for the API chunk
     path but will fire zero times.
   - Keep the volume-signal log line but log `{handle, mode: 'prefix'}`.
2. `CloudflareCachePurgeJob`:
   - `$timeout` 180 → 30. `$uniqueFor` 240 → **≤ 10** (must still exceed
     `$timeout`? — NO: the invariant "uniqueFor > timeout" was there because a
     killed job leaves the lock to expire; with a 30 s timeout set
     `$uniqueFor = 35` and rely on the fast happy path releasing it in ~1 s.
     Update the test that pins the relationship).
   - Follow-up purges (`$followUp`, `$followUpDepth`, the 120 s+ schedule):
     they existed to cover edits swallowed by the long lock. With the lock
     ~1 s wide they cover nothing — retire the schedule (config key too) and
     the `|fu` uniqueId discriminator. Keep `$bulk` / `cloudflare_bulk`
     routing (takedown fan-out is a different concern).
3. Tests: `CloudflarePurgeServiceTest` re-pins the request body (prefixes +
   the API chunk), the deleted enumeration cases go; `CloudflareCachePurgeJobTest`
   re-pins the new timeout/uniqueFor and drops the follow-up cases.
4. Gates: `vendor/bin/pint --dirty` · `php artisan test --compact
   tests/Unit/Services/Cloudflare tests/Unit/Jobs tests/Feature/PublicSite`
   · then the full `--parallel` suite (3 pre-existing subdomain-redirect
   failures are known — `PublicSiteControllerShowTest` ×2,
   `SubdomainChangeTest` ×1 — anything else is yours).
5. Live proof on dev after deploy: edit a pool item in the dashboard, hit
   `https://<handle>.partna.au/` with curl in a loop — expect the new HTML in
   ≤3 s; then make two edits 5 s apart and confirm the SECOND is live within
   ≤5 s (this is the case that used to take minutes).

## Phase 2 — owner always sees origin (router + dashboard, ~20 lines)

The router already bypasses the edge cache when the request carries
`?preview` (finalize with `noStore: true`, `cloudflare-worker/src/index.js`
~line 705). The pages app ignores unknown query params. So:

1. Dashboard "View site" (account menu, `apps/dashboard/components/…`): open
   `https://<handle>.partna.au/?preview=1` instead of the bare URL. One-line
   change; the owner's own click always renders fresh, independent of purge.
2. Optional hardening in the router (only if you see abuse): rate-limit
   `?preview` per IP; the code already documents this as the accepted
   trade-off, so it is NOT required for this plan.

## Out of scope

- Shortening `PRIMARY_CACHE_TTL_S` (24 h) — no.
- Touching `SiteCacheLanes` semantics or the 60 s payload cache.
- The pages app (`partna-monorepo/apps/pages`) — nothing to change there.
- Cache-tag purge — only if Phase 0 forces it.

## Verification (definition of done)

- Phase 0 evidence pasted into the commit message (the three `cf-cache-status`
  lines).
- Backend suite green (minus the 3 known), pint clean.
- Dev-live: single edit ≤3 s; two edits 5 s apart both live ≤5 s.
- Dashboard "View site" carries `?preview=1` and shows the edit instantly.

## If you get stuck

Write `/tmp/deepseek-blocked-<ts>.md` (or just stop and report) — do not
half-land: the enumeration removal and the lock shortening must ship
together, or a short lock with the old 83-call purge just stacks jobs.
