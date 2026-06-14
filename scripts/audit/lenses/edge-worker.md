# Edge worker: Cloudflare routing, KV contract, edge-cache correctness, takedown latency, poisoning

Hunt **routing bugs, cache-correctness failures, KV-contract drift, and poisoning vectors** in the Cloudflare Worker that fronts **100% of public sitepage traffic** (`cloudflare-worker/src/index.js`) and in the backend jobs that feed it (`SyncSubdomainToKvJob`, `CloudflareCachePurgeJob`). This is the only non-PHP runtime in the repo: the Worker is **JavaScript on the Cloudflare Workers runtime** (Service Bindings, Workers KV, the Cache API) — read it as such, not as Laravel code.

The architecture (from the Worker header + CLAUDE.md, both canonical):

- `<handle>.partna.au` → Worker reads `SUBDOMAIN_KV`. `{type:"individual"}` → edge Cache API, on miss Service Binding to the Astro app (`PARTNA_PAGES`); `{type:"alias", redirect}` → 301 to the canonical subdomain.
- **`SyncSubdomainToKvJob` is the ONLY KV writer.** Alias entries carry `expirationTtl` so the edge auto-evicts in parallel with the DB's `expires_at`.
- Freshness is **push-based**: primary edge cache is 24h TTL, busted by `CloudflareCachePurgeJob` on mutation. A 7-day **stale shadow** (`/_swr-shadow` path prefix) serves last-good instantly on a cold miss while the refresh happens in `ctx.waitUntil`.
- The Worker must explicitly `caches.default.put(...)` — `Cache-Control` alone does not populate the edge cache.

A correct Worker that caches the wrong thing, or a correct purge that misses the shadow copy, fails silently: visitors see stale or cross-contaminated content with no backend error. **The Worker has no test suite and no Nightwatch** — this lens is its primary safety net.

## Use the lens prefix `EDGE` for findings

Number them `EDGE-1`, `EDGE-2`, … sequentially across the whole audit. **P0 for anything that caches one visitor's personalized response for another, or that keeps serving a taken-down/unpublished page. P1 for purge-coverage gaps, routing drift, and poisoning vectors. P2 for header/TTL hygiene. P3 for logging/observability polish.**

## Findings categories

### (1) Routing & subdomain parsing

- The Worker's `RESERVED` set must mirror `reserved_subdomains` in `config/partna.php` — diff them token by token; any subdomain reserved in one place but not the other is a finding (a label missing from the Worker set goes to KV lookup and 404s a reserved surface; a label missing from config can be claimed as a handle and then shadowed at the edge).
- Hostname parsing: case normalisation, multi-level subdomains (`a.b.partna.au`), empty labels, the apex, and non-`partna.au` hosts must all pass through or reject deterministically. Quote the parse chain and check each branch.
- Pass-through branches that call `fetch(request)` (apex, reserved, unknown KV type, KV failure): identify where that request actually lands (origin DNS for the same hostname) and whether that destination exists for a `<handle>.partna.au` host — a pass-through that re-resolves to the same Worker route is an infinite loop; one that resolves to nothing is a silent 5xx. Flag any pass-through whose destination cannot be confirmed.

### (2) KV contract & single-writer discipline

- Diff the entry shapes the Worker reads (`entry.type`, `entry.redirect`) against what `SyncSubdomainToKvJob` writes — every field read must be written, every field written must be read or documented. Shape drift between writer and reader is a silent 404.
- **Single-writer rule:** grep the whole repo for any `SUBDOMAIN_KV` write outside `SyncSubdomainToKvJob` (including wrangler scripts, artisan commands, other jobs). Any second writer is a P1 finding by doctrine.
- Alias `redirect` values are trusted blindly by the Worker (it 301s wherever the entry points). Confirm the job only ever writes `https://<handle>.partna.au` origins — any code path that could write an attacker-influenced URL into an alias entry turns the edge into an open redirect for the old handle's traffic.
- `expirationTtl` on alias entries vs `expires_at` on `site.site_subdomain_aliases` rows: the two clocks must agree. An alias pruned from the DB but alive in KV redirects to a handle that may have been re-claimed; an alias expired in KV but alive in DB splits behaviour between edge and origin.
- KV lookup failure currently fails **open** (`return fetch(request)`): assess what a visitor actually receives during a KV outage and whether that is the intended degradation. If fail-open serves an error or loops, flag it.

### (3) Edge-cache purge coverage (the highest-stakes category)

The purge path must clear **every cached representation** of a page. For each mutation that changes public content, trace backend write → `CloudflareCachePurgeJob` → edge:

- The Worker caches TWO copies per URL: the primary (keyed by the request URL) and the stale shadow (keyed by the `/_swr-shadow`-prefixed URL, 7-day TTL). **Confirm the purge job clears both.** A purge that only clears the primary means the next visitor is served the pre-edit render from the shadow — by design for normal edits, catastrophic for takedowns.
- Deep links: the cache is keyed per-path (and per query string). A purge that only clears `/` leaves `/gallery`, `/services`, etc. stale. Confirm purge enumerates or wildcard-purges every cached path for the subdomain.
- **Takedown latency:** moderation takedowns, account deletion, site unpublish, and handle renames must NOT be served from the 7-day stale shadow. For each of those flows, trace whether the purge (both copies, all paths) is dispatched, and what the worst-case window is. A taken-down page surviving at the edge is a P0.
- Purge dispatch discipline: `CloudflareCachePurgeJob` dispatches must follow the transaction-boundary rule (after commit, not inside) — a purge fired for a rolled-back write re-warms the cache with old content *correctly*, but a commit whose purge was swallowed leaves stale content for 24h (primary) / 7d (shadow). Check `$tries`/`$backoff`/`failed()` on the purge job: a silently failed purge is invisible staleness.

### (4) Cache poisoning & response safety

- `withCacheTtl` forces `public` onto `Cache-Control` and overlays `s-maxage` before `cache.put`. That is safe ONLY while every origin response is fully anonymous. Flag anything that could make a cached response visitor-specific: `Set-Cookie` headers from the Astro origin (confirm they are stripped or never cached), `Authorization`/`Cookie`-dependent rendering, CSRF tokens in HTML. **A personalized response cached under a shared key is a P0.**
- Cache keys include the full URL **including the query string**: any visitor can mint unlimited cache entries (`/?x=1`, `/?x=2`, …) — assess cardinality abuse (cache eviction pressure, per-zone limits) and whether a query-string allowlist/normalisation is warranted on a page that ignores query params.
- The stale-shadow key is built with `new Request(u.toString(), {headers: request.headers})` — confirm forwarding visitor headers into the cache-key request cannot vary the match (Cache API keys on URL + `Vary`; check what `Vary` the origin emits and whether visitor-controlled headers can fragment or poison the shadow).
- Only `fresh.ok && request.method === "GET"` responses are cached — verify the non-OK and non-GET branches really skip both `cache.put` calls, and that error responses carry `no-store`.

### (5) Redirect & header security

- HTTP→HTTPS 301 and alias 301s: confirm `Location` is built from parsed components (not string-concatenated from raw input), that path+query preservation on alias redirects cannot inject headers or change the target origin, and that the alias 301 carries `max-age=0, must-revalidate` (browsers cache 301s indefinitely otherwise — a renamed handle must not leave permanent client-side redirects).
- Security headers (`Strict-Transport-Security`, `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`) are applied in `applySecurityHeaders` — diff against the backend `SecureHeaders` middleware; the Worker mirrors it by intent, so any header added to one and not the other is drift. Note the Worker ships no CSP — flag as P2 hardening with a concrete `frame-ancestors`/`default-src` starting point.
- Headers applied on EVERY response path: hit, stale, origin, 404, 503, redirects. Quote any return path that skips `applySecurityHeaders` or `tagResponse`.

### (6) Failure semantics & operational blindness

- 503 on missing Service Binding, 404 `no-store` on missing KV entry, `origin-error` tag on non-OK origin: confirm each is correct and none is cacheable.
- `console.error` is the Worker's only telemetry. Identify failure modes that produce NO signal (purge raced with refresh, shadow served beyond intent, KV shape mismatch) and propose the minimal observable (a tag header value or structured log line) — P2.
- `ctx.waitUntil` background refresh failures are invisible by design; confirm a failed background refresh cannot leave the caches in a worse state than before (e.g., half-written pair: primary updated, shadow stale or vice versa).

### (7) Config & deploy hygiene (`wrangler.toml`)

- Bindings (`SUBDOMAIN_KV`, `PARTNA_PAGES`), routes/zone patterns, and `compatibility_date` declared and current; no secrets committed in the toml; dev/prod environment split matches the backend's two-environment model.
- Anything in the Worker that hardcodes a value owned by backend config (domain, reserved list, TTLs that the backend assumes) — flag for a documented sync point: a comment on BOTH sides naming the mirror.

## Per-finding requirements

For every finding:
- Cite the category number (1–7).
- Quote verbatim evidence — JavaScript from `cloudflare-worker/src/index.js` / `wrangler.toml`, or PHP from the jobs — including the enclosing function.
- For purge-coverage findings (3): name BOTH sides — the backend mutation site (file + method) and the edge cache copy it fails to clear.
- Name the canonical fix: purge both primary + `/_swr-shadow` keys, per-subdomain wildcard purge, strip `Set-Cookie` before `cache.put`, query-string normalisation, `Cache-Control: no-store` on error paths, mirror-comment sync points, `DB::afterCommit` dispatch.
- State the staleness window a visitor could observe (24h primary / 7d shadow / permanent browser 301) — this drives the tier.

## Out of scope — do NOT re-flag

- The Astro app itself (`partna-pages` lives in another repo) — only its contract with this Worker.
- The fail-open-on-KV-error *choice* as a philosophy — assess its actual behaviour (category 2), don't relitigate availability-over-consistency.
- `SiteCacheService` internals — `caching-gold-standard.md` (CCH) owns backend cache discipline; this lens owns the edge.
- Cloudflare account/zone settings not represented in the repo.

## Suggested per-domain scope groups

### Group A — The Worker + its feeders (the whole lens in one pass)
```
--scope cloudflare-worker/src
--scope cloudflare-worker/wrangler.toml
--scope app/Jobs/Cloudflare
--scope app/Services/Cloudflare
--scope app/Services/Cache/SiteCacheService.php
--scope config/partna.php
```

## Exhaustiveness directive

The Worker is ~300 lines fronting all public traffic with no tests — read every line and every return path. For each `return` statement, ask: is this response correctly cacheable, correctly headed, and correctly purgeable? For each backend mutation of public content (publish, edit, rename, unpublish, delete, moderation takedown), trace the full purge chain to BOTH edge copies. Emit a finding per distinct quotable instance. The adjudicator dedupes; **a stale-takedown gap missed here ships a legal/abuse problem, not a perf problem.**
