# Partna Subdomain Router (Cloudflare Worker)

`partna-subdomain-router` is the public wire for **100% of `<handle>.partna.au`
traffic**, plus every Cloudflare-for-SaaS custom hostname in the `partna.au`
zone. It is a single file — `src/index.js` — with no framework, no build step and
no dependencies at runtime.

Its whole job: read one routing entry out of Workers KV and decide which of five
things to do — render a sitepage through the `partna-pages` Astro Worker, `301`
to a renamed handle, serve a branded 404, or get out of the way and pass the
request to origin.

There is no brand concept, no affiliate concept, no Shopify and no storefront in
this Worker. If you find a doc that says otherwise, that doc is stale.

---

## 1. Request flow — the nine ordered branches

`export default { fetch(request, env, ctx) }` evaluates these **in order**. The
first match wins; there is no fallthrough past a `return`.

| # | Condition | Outcome |
|---|-----------|---------|
| 1 | `url.protocol === "http:"` | `301` to the same URL on `https:`. HSTS (branch-independent, see §5) covers the repeat visit. |
| 2 | `hostname === "partna.au"` (apex) | `passThrough(request)` — plain `fetch(request)` to the zone origin, security headers stamped on the way back. |
| 3 | `hostname` does **not** end in `.partna.au` | Custom-domain lookup: `SUBDOMAIN_KV.get("domain:<host>", {type:"json"})`. `{type:"individual", handle:"…"}` → `serveIndividual()` with that handle injected. Anything else, or a KV throw (logged, **fails open**) → `passThrough`. |
| 4 | subdomain is empty, contains a `.` (multi-level), or is in `RESERVED` | `passThrough` — **with no KV read at all**. This is what keeps `api.partna.au` alive. |
| 5 | `SUBDOMAIN_KV.get(subdomain)` **throws** | Branded `unclaimedHtml(subdomain)` 404, `Cache-Control: no-store`, tagged `X-Partna-Cache: kv-error`. Deliberately the *same page* a genuine miss serves (an outage should degrade gracefully, not visibly differently) but a *different tag*, so ops can separate a KV outage from routine enumeration traffic. |
| 6 | KV returns no entry (genuine miss) | Same branded `unclaimedHtml(subdomain)` 404, `no-store`, **no** `X-Partna-Cache` header. Copy offers the address — unclaimed handles are a growth surface. |
| 7 | `entry.type === "alias"` and `entry.redirect` is a string | Validate first (§5), then `301` to `` `${redirectBase.origin}${url.pathname}${url.search}` `` with `Cache-Control: max-age=0, must-revalidate`. A rejected or unparseable target **fails closed** to the generic `unclaimedHtml(null)` 404 — never a redirect. |
| 8 | `entry.type === "individual"` | `serveIndividual(env, ctx, request, null)` — `partna-pages` derives the handle from `Host`, so no override. |
| 9 | anything else (unknown `type`) | `passThrough`. |

### `serveIndividual()` — the six sub-branches

Reached from branch 3 (custom domain, with a `handleOverride`) and branch 8
(`<handle>.partna.au`, `handleOverride === null`).

1. `env.PARTNA_PAGES` missing or not a fetcher → `503 Service Unavailable`,
   `no-store`, `console.error`. Fail-fast on an undeployed binding.
2. `?preview=`, `?skeleton=` or `?architecture=` present → straight to
   `PARTNA_PAGES`, `no-store`, **no cache read and no cache write** (see §4).
3. `request.method !== "GET"` → straight to `PARTNA_PAGES`, uncached.
4. Primary cache **hit** → served immediately, `X-Partna-Cache: hit`.
5. Primary miss but the **stale shadow** hits → shadow served immediately
   (`X-Partna-Cache: stale`) and `ctx.waitUntil(fetchAndCache(…))` refreshes
   both copies in the background.
6. Cold miss → origin fetch, both cache copies populated inside `waitUntil`,
   tagged `origin` (or `origin-error` + `no-store` if `!fresh.ok`).

Every forwarded request is built by `withHandleHeader()`, which strips
visitor-supplied headers before origin ever sees them (§5).

---

## 2. The KV contract

One namespace, `SUBDOMAIN_KV`, three key shapes. All values are JSON, read with
`{type: "json"}`.

| Key | Value | Meaning |
|-----|-------|---------|
| `<subdomain>` | `{"type":"individual"}` | Live sitepage. Rendered via `partna-pages`. TTL is normally none; for an unclaimed pre-account build it carries `expirationTtl` derived from `pre_account_builds.expires_at`. |
| `<old-subdomain>` | `{"type":"alias","redirect":"https://<current>.partna.au"}` | Renamed handle. `301`s to the canonical host, preserving path + query. Written with `expirationTtl` from the alias's `expires_at` (+90 d) so it self-cleans. `redirect` is a **full URL** — the Worker reads it as-is and uses only its `origin`. |
| `domain:<host>` | `{"type":"individual","handle":"<handle>"}` | Cloudflare-for-SaaS custom domain → the handle it belongs to. No TTL; retired explicitly on takedown/disconnect. |

**`app/Jobs/Cloudflare/SyncSubdomainToKvJob` is the only permitted writer to
`SUBDOMAIN_KV`, ever.** This is a CLAUDE.md non-negotiable and is enforced in CI
by `tests/Feature/Architecture/SubdomainKvWritersTest.php`, which fails the build
if any file other than that job calls `put()` / `bulkPut()` / `delete()` on
`CloudflareKvService`. Any other writer bypasses the job's canonical branch logic
and can produce entries this Worker does not know how to interpret; parallel
writers also race, and last-write-wins can land on stale state.

Eleven places dispatch that job (`grep -rn 'SyncSubdomainToKvJob::dispatch' app/`
to re-verify):

| Dispatcher | Trigger |
|-----------|---------|
| `app/Observers/User/UserObserver.php` | handle change, status change, restore |
| `app/Observers/Core/SiteObserver.php` | site created / subdomain changed (`->afterCommit()`) |
| `app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php` | custom domain attach / verify / detach |
| `app/Services/User/AccountDeletionService.php` | takedown — retires handle + `domain:<host>` |
| `app/Services/PreAccount/ClaimSiteService.php` | a build is claimed |
| `app/Jobs/PreAccount/GeneratePreAccountSiteJob.php` | a pre-account site is generated |
| `app/Jobs/Moderation/PurgeModerationCacheJob.php` | moderation action removes/restores a page |
| `app/Console/Commands/PruneExpiredHandleAliases.php` | alias hard-delete |
| `app/Console/Commands/PruneExpiredPreAccountBuilds.php` | expired build hard-delete (`->afterCommit()`) |
| `app/Console/Commands/BackfillSubdomainKvCommand.php` | operational backfill |
| `app/Console/Commands/BackfillUserKvEntries.php` | operational backfill |

---

## 3. Caching and stale-while-revalidate

Sitepage responses are fronted by the **edge Cache API** (`caches.default`), not
by Cloudflare's automatic HTTP cache. Two copies per page:

- **Primary** — `PRIMARY_CACHE_TTL_S` (`wrangler.toml [vars]`, currently 24 h;
  `PRIMARY_CACHE_TTL_S_DEFAULT` in `src/index.js` is only the fallback if the var
  is absent or unparseable). Freshness is **push-based**: backend mutations
  dispatch `CloudflareCachePurgeJob`, which evicts the entry.
- **Stale shadow** — `STALE_SHADOW_TTL_S` (currently 7 d), stored under
  `/_swr-shadow<path>`. A wide window on purpose: even a multi-day backend outage
  still serves the last good render. Each successful origin hit re-extends it.

> **The Cache API is NOT auto-populated from `Cache-Control`.** The router must
> call `caches.default.put()` explicitly, which `fetchAndCache()` does inside
> `ctx.waitUntil`. If those `put` calls are ever dropped, response bodies stay
> perfectly correct and 100% of traffic silently becomes an origin hit — a
> failure with no visible symptom until an origin bill or a latency graph. The
> only detector is `X-Partna-Cache` (§6) and `test/cache.test.mjs` T6.

**`cacheKeyFor()`** drops the query string and fragment before keying, so
`?utm_source=…`, `?fbclid=…` and every other tracking variant of a shared link
collapse onto one entry instead of minting unbounded distinct ones. The request
forwarded to origin keeps its full query — only the *key* is normalised.

**`staleShadowKey()`** prefixes the (already normalised) path with
`/_swr-shadow`. That literal prefix is hardcoded a second time, independently, in
`app/Services/Cloudflare/CloudflarePurgeService.php` (`purgeHandle()`, the
`{$base}/_swr-shadow/…` URL construction). Rename it on one side only and every
purge misses the shadow, so stale pages survive a purge for up to 7 days.
`test/cache.test.mjs` T9 asserts the Worker's side of that shape.

**Bypass:** `?preview=`, `?skeleton=` or `?architecture=` skip the cache in both
directions. The read is skipped so the dashboard live preview is never stale; the
write is skipped because `cacheKeyFor()` strips the query, so a cached preview
would pin itself under the plain URL's key for the full primary TTL. Accepted
trade-off (2026-07-25 cache-freshness design): these params are a cache-busting
lever for anonymous traffic. Cloudflare bot protection sits in front; origin
rate-limiting for bypass params is separate work.

---

## 4. Security posture

**Baseline headers — every return path.** `finalize()` is the single exit point,
and `applySecurityHeaders()` sets:

- `Strict-Transport-Security: max-age=31536000; includeSubDomains`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `X-Frame-Options: SAMEORIGIN`

Each uses a `has()` guard: if the origin already set the header, the origin's
value wins. That is deliberate — origin (`partna-pages`, or the Laravel apex)
knows more about its own response than the router does, and a router that
clobbers a deliberately-stricter origin header is a downgrade.

**Framing.** On sitepage responses `finalize()` **deletes** `X-Frame-Options` and
sets an **enforcing** `Content-Security-Policy: frame-ancestors 'self'
https://app.partna.au`, because `X-Frame-Options` cannot allow-list a
cross-origin embedder and the `/account/design` preview iframe on
`app.partna.au` needs to embed the page. Everything else stays refused.

`SITEPAGE_CSP` — the full policy — ships as
`Content-Security-Policy-Report-Only` and is **inert**: Report-Only does not
block, and navigation directives like `frame-ancestors` are ignored entirely in
Report-Only mode. Its `script-src 'unsafe-inline' https:` is a non-constraining
placeholder. State it plainly: **today's clickjacking defence is the enforcing
`frame-ancestors` header on sitepages and `X-Frame-Options: SAMEORIGIN`
everywhere else — not `SITEPAGE_CSP`.** Tighten and flip the header name once a
real render has been validated against it.

**Shared-cache hygiene.** `withCacheTtl()` deletes `Set-Cookie` and `Vary` from
the copy it stores. An origin session cookie must never be edge-cached and
replayed to the next visitor, and a stale or varying origin `Vary` must not be
able to poison a shared representation.

**Alias-target validation.** Before any `301`, the value from KV must parse as a
URL **and** satisfy both conjuncts: `protocol === "https:"` **and** hostname is
`partna.au` or ends `.partna.au`. Either one failing (or a parse throw) falls
through to a 404. A poisoned KV entry must not become an open redirect.

**Forwarded-header stripping.** `withHandleHeader()` always deletes
`x-partna-handle` (a router-only signal — a spoofed value reaching `partna-pages`
would let a visitor render someone else's page), plus `Cookie` and
`Authorization`. Sitepages are public and static; they must never receive
visitor credentials. The header is set only for custom-domain requests, from the
handle the router itself resolved out of KV.

**Log hygiene.** Every `console.error` in this file passes only
`{err: String(err)}` — never the raw hostname, subdomain or cache-key URL.
Those are visitor-controlled values we do not want persisted verbatim.

---

## 5. Diagnostics — `X-Partna-Cache`

The first header to read when a page looks wrong.

| Value | Means | What it tells you |
|-------|-------|-------------------|
| `hit` | Primary cache served it | Normal steady state. If content is stale, the purge did not land. |
| `stale` | Primary missed, shadow served, refresh in flight | Recently purged, or primary TTL lapsed. The *next* request should be `hit`. |
| `origin` | Cold miss, fetched and cached | Expected on first view. **If you see this on ~every request, `cache.put` is failing** — check the Worker tail for `primary cache.put failed` / `shadow cache.put failed`. |
| `origin-error` | Origin returned non-2xx | `partna-pages` is unhealthy. Response also carries `no-store`, so nothing bad is cached. |
| `kv-error` | KV `get` threw | KV outage. The visitor sees the same branded 404 a real miss shows; this tag is the only way to tell the two apart. Distinguishing a spike here from an enumeration spike is the point. |
| *(absent)* | Not a cache-eligible path | Genuine miss 404, alias `301`, `passThrough`, preview bypass, non-GET. |

`npm run tail` (`wrangler tail`) streams live logs.

---

## 6. Configuration (`wrangler.toml`)

```toml
main = "src/index.js"
compatibility_date = "2025-01-01"
```

**Route — `pattern = "*/*"` with `zone_name = "partna.au"`.** Zone-wide, and
deliberately so. Cloudflare-for-SaaS custom hostnames arrive at Worker Routes as
**the customer's own hostname** (e.g. `tuesdae.co`), not as the fallback origin,
so a `*.partna.au/*` pattern would never see them. Only a zone-wide wildcard
catches that traffic. The Worker passes apex and reserved subdomains through
itself (branches 2 and 4), which is what makes the broad pattern safe.

**`[vars]`** — `PRIMARY_CACHE_TTL_S = 86_400`, `STALE_SHADOW_TTL_S = 604_800`.
The configured source of truth; the `src/index.js` consts are fallbacks only.
Keep them in step with the figures cited in `CloudflarePurgeService::purgeHandle()`'s
docblock.

**`[[kv_namespaces]]`** — binding `SUBDOMAIN_KV`. Production and preview ids are
already committed; there is nothing to create or paste for an existing checkout.
`preview_id` is what `wrangler dev` uses.

**`[[services]]`** — binding **`PARTNA_PAGES`** → service `partna-pages`,
environment `production`. **The binding name is load-bearing**: the router calls
`env.PARTNA_PAGES.fetch(...)` against that exact identifier. Rename it and
`serveIndividual()` takes its 503 fail-fast branch for every sitepage request.

**No `[env.staging]` block (EDGE-102).** There are exactly two backend
environments, development and production. The block that used to be here carried
unresolved `REPLACE_WITH_STAGING_KV_*` placeholders and would have failed a real
`--env staging` deploy anyway. If a genuine staging tier ever appears, add it
then with real KV ids — staging must never share the production `SUBDOMAIN_KV`.

**No `[assets]` / `[site]` block.** Only `src/index.js` and its import graph are
bundled and deployed; everything else in this directory (`test/`, configs, this
README) exists on disk and in git but never reaches the edge. Verify with
`npx wrangler deploy --dry-run --outdir=/tmp/bundle`. **Do not add an `[assets]`
or `[site]` block** without re-checking what it would upload — as written it
would ship `test/` to production.

---

## 7. The two hand-mirrors (and the guards that catch drift)

This Worker is plain JS with no build-time link to Laravel config, so two values
are maintained by hand on both sides. Each has a PHP test that parses this
source file and goes red on drift.

| Worker | Backend | Guard | What drift costs |
|--------|---------|-------|------------------|
| `const PARTNA_DOMAIN` (`src/index.js`) | `config('partna.public_domain')` | *none* — EDGE-3, manual only | Change the backend's public domain without mirroring it here and the router stops recognising its own zone: every subdomain falls to branch 3 (custom-domain lookup) and passes through. |
| `const RESERVED` (`src/index.js`) | `config('partna.reserved_subdomains')` | `tests/Feature/Subdomain/ReservedSubdomainWorkerSyncTest.php` | A label reserved in PHP but missing here gets sent to KV and 404s forever; reserved here but free in PHP lets someone claim a handle that can never render. |

A third, narrower guard — `tests/Feature/Cache/WorkerPreviewBypassTest.php` —
parses the `previewParams.has(...)` bypass condition and fails if `preview`,
`skeleton` or `architecture` is dropped from it.

Both PHP guards read this file as **text**. If you restructure
`const RESERVED = new Set([…]);` or the `if (previewParams.has(...))` block, they
throw a loud "could not locate" error rather than silently passing — but you must
then update their regexes.

---

## 8. Tests

```bash
npm install
npm test          # vitest run
npm run test:watch
```

`test/` runs the real Worker inside **workerd** via the Miniflare programmatic
API — no network, no Cloudflare account, no KV writes to any real namespace.

| File | Covers |
|------|--------|
| `test/helpers.mjs` | The Miniflare factory: KV seeding, the `PARTNA_PAGES` recording stub, and the `outboundService` stub that intercepts `passThrough`. |
| `test/routing.test.mjs` | The dispatch branches: individual render, alias `301` with path+query preserved, both halves of the alias-target rejection, the unclaimed 404, reserved short-circuit, custom domains, forwarded-header stripping. |
| `test/cache.test.mjs` | `hit` / `stale` / `origin` transitions, query-stripped keying, `Set-Cookie` never cached, the `/_swr-shadow` key shape, preview bypass. |
| `test/headers.test.mjs` | The baseline header set across five different return paths, and the framing split between sitepage and non-sitepage responses. |
| `test/config.test.mjs` | Parses `wrangler.toml` and asserts `main`, both bindings and both TTL vars exist — the harness *declares* bindings, so without this nothing notices if the real config loses one. |

**`outboundService` is mandatory.** `passThrough()` calls plain
`fetch(request)`; without a stub, the reserved-subdomain and apex tests would
make **real requests to `https://api.partna.au`** from your machine and from CI.
`helpers.mjs` stubs it to return the literal body `APEX-ORIGIN`, and those tests
assert on that exact string, so a missing stub fails loudly instead of quietly
passing against the live internet.

**Deliberately not covered** (documented at the top of each suite): the KV-throw
branch (Miniflare offers no clean way to make a KV `get` reject), the
`101`/WebSocket raw-return in `passThrough`, and real TTL expiry (no clock
control — T9 simulates it by deleting the primary key, which is the faithful
shape of the production event).

CI runs this as the `worker-tests` job in `.github/workflows/ci.yml`. It needs
**Node 22+** — both `miniflare` and `wrangler` declare `engines: node >= 22`.

---

## 9. Deploy and smoke test

```bash
npm install
npx wrangler login          # first time only
npm run deploy              # wrangler deploy
```

Always dry-run first when the change is anything other than obvious:

```bash
npx wrangler deploy --dry-run --outdir=/tmp/partna-worker-bundle
```

Smoke test after deploy (`jane` = a real claimed handle, `old-jane` = a renamed
one):

```bash
# Live sitepage — served through partna-pages, edge-cached
curl -sSI https://jane.partna.au/ | grep -Ei 'HTTP/|x-partna-cache|x-frame-options|content-security-policy'
# → HTTP/2 200
# → x-partna-cache: origin        (then `hit` on the second call)
# → content-security-policy: frame-ancestors 'self' https://app.partna.au
# → (no x-frame-options — sitepages replace it with frame-ancestors)

# Renamed handle — 301 to the canonical host, path and query preserved
curl -sSI 'https://old-jane.partna.au/gallery?x=1' | grep -Ei 'HTTP/|location'
# → HTTP/2 301
# → location: https://jane.partna.au/gallery?x=1

# Unclaimed subdomain — branded edge 404, never cached
curl -sSI https://does-not-exist.partna.au | grep -Ei 'HTTP/|cache-control|x-partna-cache'
# → HTTP/2 404
# → cache-control: no-store
# → (no x-partna-cache header — that would mean kv-error)

# Reserved label — must reach the API, not the router's 404
curl -sS -o /dev/null -w '%{http_code}\n' https://api.partna.au/api/health
# → 200
```

---

## 10. Local development

```bash
npm run dev     # wrangler dev
```

**Caveat:** `wrangler dev` reads `wrangler.toml`, so it tries to resolve the
`PARTNA_PAGES` service binding, which does not exist locally. Sitepage requests
therefore take `serveIndividual()`'s 503 branch. That is fine for exercising
routing, headers and the KV branches, but it cannot render a page. To actually
render, either run the `partna-pages` Worker alongside and point the binding at
it, or use the Miniflare test harness (§8), which stubs the binding — that is
what the tests do and it is usually the faster loop.

Seeding KV — **wrangler 4 removed the colon syntax**. It is `kv key put`, not
`kv:key put`; every `kv:namespace` / `kv:key` command you find in older notes is
unrunnable.

`wrangler dev` defaults to *local simulated* storage, so that is what to seed:

```bash
npx wrangler kv key put --binding SUBDOMAIN_KV --local 'jane' '{"type":"individual"}'
npx wrangler kv key put --binding SUBDOMAIN_KV --local 'old-jane' '{"type":"alias","redirect":"https://jane.partna.au"}'
npx wrangler kv key list  --binding SUBDOMAIN_KV --local
```

Swap `--local` for `--preview` only if you are running `wrangler dev --remote`
against the real preview namespace (`preview_id` in `wrangler.toml`).

Only ever write `{"type":"individual"}`, `{"type":"alias","redirect":…}` or
`domain:<host>` entries. An unhandled `type` falls through to branch 9 —
`passThrough` — and a `<handle>.partna.au` request passed through to its own
zone **self-loops into a visitor-facing 522**.

> **Never write to the production `SUBDOMAIN_KV` by hand.**
> `SyncSubdomainToKvJob` is the only permitted writer (§2). A manual production
> write puts the routing table out of step with the database and the next job run
> will fight it.

---

## 11. Cost drivers

No pricing table here — Cloudflare's numbers move and a stale table is worse
than none. What matters is which dials this Worker turns:

- **Worker invocations** = one per request, including `passThrough` traffic to
  the apex and the API. The zone-wide `*/*` route means *everything* in the zone
  counts.
- **KV reads** = one per non-reserved subdomain request, plus one per
  custom-domain request. Reserved labels cost zero reads (branch 4), which is
  most bot and infrastructure traffic.
- **KV writes** = only `SyncSubdomainToKvJob`, i.e. handle/site/domain mutations.
  Low and bounded.
- **Cache API** reads and writes are not separately billed, but the primary and
  shadow TTLs (§3) are what keep origin (`partna-pages`) subrequest volume — and
  the Laravel API calls behind it — off the floor. Shortening those TTLs raises
  origin cost roughly linearly.

Current pricing: <https://developers.cloudflare.com/workers/platform/pricing/>.
