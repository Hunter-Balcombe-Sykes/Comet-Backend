# k6 Load-Testing Harness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the DIY single-origin k6 load-testing harness in `scripts/launch-check/k6/` and produce measured evidence that Partna's public read path, edge cache, and job pipeline survive the named target load at today's dev sizing.

**Architecture:** A set of standalone k6 scripts (one per phase) sharing a `config.js`, run from a local machine against the **dev** environment. Phase 0 seeds a throwaway test handle with representative data via raw SQL; Phases 1–2 measure the read path (baseline latency, edge-cache HIT, origin cache-buster + rate-limiter); Phase 3 (deferred) saturates the job pipeline. Every threshold is baked into the script so its exit code is the pass/fail signal. Measured runs are collaborative checkpoints (Claude drives k6, Josh watches Horizon/Supabase); the agentic worker only builds scripts and runs safe 1-VU smoke runs.

**Tech Stack:** k6 (Grafana), PostgreSQL/Supabase (dev ref `glncumufgaqcmqhzwrxm`), Cloudflare Worker (zone-wide `partna.au`), Laravel Horizon/Redis.

## Global Constraints

_Every task's requirements implicitly include this section. Values copied verbatim from the design spec (`docs/superpowers/specs/2026-07-18-k6-load-testing-design.md`) and the 2026-07-26 pre-flight drift check._

- **Target environment is DEV, not prod.** Origin/baseline/jobs → `https://dev-api.partna.au`. Prod (`api.partna.au`) is now live (post-cutover 2026-07-26) — **never** point the cache-buster (`spike-origin.js`) or `jobs.js` at it without an explicit prod-window decision. The prod capacity run is deferred to `OPS-S4-3`.
- **The edge tier is one zone-wide Worker.** `cloudflare-worker/wrangler.toml` route `*/*` on `zone_name = "partna.au"`, no staging env (EDGE-102). `<handle>.partna.au` (Phase 2a) therefore hits the production Worker + shared `SUBDOMAIN_KV` regardless of handle. Acceptable because it is cache-HITs only (≈0 origin/DB impact), but it cannot be dev-isolated.
- **Named target load = 50 concurrent viewers** (launch-day peak × safety). Every threshold is judged against this. Scripts are parameterized (`SPIKE_VUS` env var) so escalation to **200** is one env change after a joint checkpoint — never escalate solo.
- **Rate limiter (shapes every read scenario):** `public-profile` = **60/min per `CF-Connecting-IP ?? ip`** (`AppServiceProvider.php:372`, tunable via `config('partna.public_profile.rate_limit_per_minute')` / env `SIDEST_RATE_LIMIT_PUBLIC_PROFILE_PER_MINUTE`). One machine = one IP = one bucket. `analytics` limiter = 120/min. A `$throttleEnabled` gate can disable all limiters — confirm it is ON on dev before Phase 2b or the run measures nothing.
- **Guardrails (§6, non-negotiable):** `X-Load-Test: 1` header on every request; agreed VU caps up front (start at the numbers here, escalate only after a joint review); teardown SQL after every write scenario; live kill-switch (Josh Ctrl-C's k6 if Supabase connections or Horizon depth climb toward a ceiling).
- **No Laravel migrations** (composer guard rejects them). All seed/teardown is raw SQL scoped to the fixed test `site_id`.
- **Results are gitignored** (`scripts/launch-check/k6/results/`), never committed. Josh commits; work on a branch off `development`.
- **k6 version:** pin behaviour to k6 ≥ 0.50 (`http.setResponseCallback`, `constant-arrival-rate`, scenario `exec`/`startTime` all required).

## File Structure

```
scripts/launch-check/k6/
  config.js         — shared constants (URLs, test handle/site_id, target load, thresholds, X-Load-Test header)
  seed.sql          — Phase 0: idempotent representative-data seed (one DO block, dev-only)
  teardown.sql      — reverses the seed / cleans write-scenario rows (fixed IDs)
  baseline.js       — Phase 1: read-only baseline, paced under the limiter
  spike-edge.js     — Phase 2a: edge-cache HIT assertion against the Worker
  spike-origin.js   — Phase 2b: origin cache-buster + limiter-engagement assertion
  jobs.js           — Phase 3 (deferred): analytics-write / queue saturation
  README.md         — run commands, per-phase pass criteria, guardrails, teardown, kill-switch
  .gitignore        — ignores results/
  results/          — JSON artifacts (gitignored)
```

Fixed test identifiers used across `seed.sql`, `teardown.sql`, `config.js`, and `jobs.js` (valid v4/variant-a UUIDs, memorable):
- **user id:** `00000000-0000-4000-a000-000000000001`
- **site id:** `00000000-0000-4000-a000-000000000002`
- **handle / subdomain:** `loadtest`

---

### Task 1: Harness scaffolding — directory, config.js, .gitignore, k6 install

**Files:**
- Create: `scripts/launch-check/k6/config.js`
- Create: `scripts/launch-check/k6/.gitignore`
- Create: `scripts/launch-check/k6/results/.gitkeep`

**Interfaces:**
- Produces (consumed by every later script): `ORIGIN`, `TEST_HANDLE`, `TEST_SITE_ID`, `EDGE_HOST`, `TARGET_CONCURRENT`, `SPIKE_VUS`, `PUBLIC_PROFILE_RL_PER_MIN`, `LOAD_HEADERS`, `THRESHOLDS` (object with keys `baseline`, `edge`, `origin`, `jobs`).

- [x] **Step 1: Confirm k6 is installed**

Run: `k6 version`
Expected: prints `k6 v0.5x.x`. If "command not found":
Run: `brew install k6` then re-run `k6 version`.

- [x] **Step 2: Create the results dir and its .gitignore**

Create `scripts/launch-check/k6/results/.gitkeep` (empty file).
Create `scripts/launch-check/k6/.gitignore`:

```gitignore
# k6 run output — local diagnostic artefacts, never a deliverable.
results/*.json
```

- [x] **Step 3: Write config.js**

Create `scripts/launch-check/k6/config.js`:

```javascript
// Shared config for the Partna k6 load-testing harness.
// Every threshold is judged against TARGET_CONCURRENT (§6 named-target guardrail).
//
// ENVIRONMENT (plan 2026-07-26): DIY regression pass targets DEV.
//   Origin / baseline / jobs -> dev-api.partna.au   (isolated from live prod)
//   Edge spike               -> <handle>.partna.au  (ONE zone-wide Worker; no dev edge)
// Prod capacity run is deferred to OPS-S4-3. DO NOT point the cache-buster or
// jobs.js at api.partna.au without an explicit prod-window decision.

export const ORIGIN = __ENV.ORIGIN || 'https://dev-api.partna.au';

// Throwaway test handle provisioned in Task 2 / seeded in Task 3.
export const TEST_HANDLE = __ENV.TEST_HANDLE || 'loadtest';
export const TEST_SITE_ID = __ENV.TEST_SITE_ID || '00000000-0000-4000-a000-000000000002';

// Worker edge host — uses the prod partna.au zone by necessity (one zone-wide Worker).
export const EDGE_HOST = __ENV.EDGE_HOST || `https://${TEST_HANDLE}.partna.au`;

// Named target load: launch-day peak x safety. 50 now; step to 200 after a
// joint checkpoint by exporting SPIKE_VUS=200 (no code change).
export const TARGET_CONCURRENT = 50;
export const SPIKE_VUS = Number(__ENV.SPIKE_VUS || 50);

// public-profile limiter: 60/min per CF-Connecting-IP (AppServiceProvider:372).
export const PUBLIC_PROFILE_RL_PER_MIN = 60;

// §6: every request is tagged so logs/Nightwatch can filter load traffic and
// so writes are attributable to the harness.
export const LOAD_HEADERS = { 'X-Load-Test': '1' };

// Threshold presets — baked into each script so the run's own exit code is the
// pass/fail signal (§9). Origin/jobs count 5xx-only as failure (see those
// scripts' http.setResponseCallback), so 429/201 do not inflate http_req_failed.
export const THRESHOLDS = {
  baseline: {
    http_req_duration: ['p(95)<500'],
    http_req_failed: ['rate<0.01'],
  },
  edge: {
    edge_cache_hit: ['rate>0.9'], // >=90% of post-warmup edge requests served HIT
    http_req_failed: ['rate<0.01'],
  },
  origin: {
    origin_5xx: ['count==0'], // limiter must shield PG: ZERO 5xx under the flood
    origin_429: ['count>0'],  // proof the limiter actually engaged (not a silent pass)
  },
  jobs: {
    jobs_5xx: ['count==0'],
    jobs_accepted: ['count>0'], // proof writes actually landed (queue got fed)
  },
};
```

- [x] **Step 4: Verify config.js is valid k6 module syntax**

Run: `cd scripts/launch-check/k6 && k6 run --quiet -e SMOKE=1 - <<'EOF'
import { TARGET_CONCURRENT, ORIGIN, THRESHOLDS } from './config.js';
export const options = { vus: 1, iterations: 1 };
export default function () {
  console.log('config OK: origin=' + ORIGIN + ' target=' + TARGET_CONCURRENT + ' edgeThr=' + JSON.stringify(THRESHOLDS.edge));
}
EOF`
Expected: run completes; log line prints `config OK: origin=https://dev-api.partna.au target=50 ...`; no import error.

- [x] **Step 5: Commit**

```bash
git add scripts/launch-check/k6/config.js scripts/launch-check/k6/.gitignore scripts/launch-check/k6/results/.gitkeep
git commit -m "feat(k6): scaffold load-test harness config + results dir"
```

---

### Task 2: Provision the throwaway test handle (user + published site + KV)

**Files:**
- No new repo files. Runs SQL against dev + one tinker command. The SQL lives in `seed.sql` (Task 3), but the minimal user+site rows and KV sync are validated here first so Task 3's representative seed has something to attach to.

**Interfaces:**
- Consumes: fixed IDs from the File Structure section.
- Produces: a resolvable `loadtest` handle on dev — `GET https://dev-api.partna.au/api/public/profiles/loadtest` → 200, and `https://loadtest.partna.au/` routes through the Worker.

- [x] **Step 1: Insert the minimal user + published site on DEV**

Run this SQL against the **dev** Supabase (`glncumufgaqcmqhzwrxm`) — via the Supabase MCP `execute_sql` (project_id = dev ref) or `psql "$DEV_DB_URL" -c '...'`. **Confirm the target is dev before running.**

```sql
DO $$
DECLARE
  v_user uuid := '00000000-0000-4000-a000-000000000001';
  v_site uuid := '00000000-0000-4000-a000-000000000002';
BEGIN
  DELETE FROM site.design_kits WHERE site_id = v_site;
  DELETE FROM site.sites WHERE id = v_site;
  DELETE FROM core.users WHERE id = v_user;

  INSERT INTO core.users (id, handle, handle_lc, display_name, first_name, status, account_type)
  VALUES (v_user, 'loadtest', 'loadtest', 'Load Test', 'Load', 'active', 'partna');

  INSERT INTO site.sites (id, user_id, subdomain, is_published, architecture_id)
  VALUES (v_site, v_user, 'loadtest', true, 'staple');
  -- design_kits row is auto-inserted by the on-create trigger.
END $$;
```

- [x] **Step 2: Verify the origin resolves the handle**

Run: `curl -s -o /dev/null -w "%{http_code}\n" https://dev-api.partna.au/api/public/profiles/loadtest`
Expected: `200`.

- [x] **Step 3: Push the subdomain into KV (raw SQL does NOT fire the observer)**

`SyncSubdomainToKvJob` is the ONLY KV writer and takes the user id. Raw SQL inserts do not trigger it, so dispatch it explicitly on the deployed dev env:

Run: `~/.composer/vendor/bin/cloud tinker development`
Then in the tinker shell:

```php
\App\Jobs\Cloudflare\SyncSubdomainToKvJob::dispatchSync('00000000-0000-4000-a000-000000000001');
```

Type `exit` to leave tinker.
_Note: `SUBDOMAIN_KV` is shared between prod and dev (known gap). `loadtest` is a throwaway unlikely to collide; if a real `loadtest` handle ever exists this would clash — pick another handle in `config.js` if so._

- [x] **Step 4: Verify the edge host routes through the Worker**

Run: `curl -s -o /dev/null -w "%{http_code} cf=%{header_json}\n" -I https://loadtest.partna.au/ | head -c 200; echo`
(Simpler:) Run: `curl -sI https://loadtest.partna.au/ | grep -i "cf-cache-status\|^HTTP"`
Expected: an `HTTP/2 200` (or a Worker-served status), proving `<handle>.partna.au` resolves. A `522`/`1016`/DNS error means KV didn't populate — re-check Step 3.

- [x] **Step 5: No commit** — this task only mutates dev DB/KV state, no repo files. Proceed to Task 3.

---

### Task 3: Phase 0 seed + teardown SQL (representative volume)

**Files:**
- Create: `scripts/launch-check/k6/seed.sql`
- Create: `scripts/launch-check/k6/teardown.sql`

**Interfaces:**
- Consumes: fixed IDs; the user+site rows from Task 2 (seed re-creates them idempotently, so `seed.sql` is the single source of truth and supersedes Task 2's minimal insert).
- Produces: a `loadtest` site whose `/profiles/loadtest` payload has non-empty `links`, `gallery`, `services` arrays and populated popularity ranks — so hot-path queries do real work.

- [x] **Step 1: Write seed.sql**

Create `scripts/launch-check/k6/seed.sql`. One `DO` block = one atomic statement (rolls back wholesale on any error; safe for both `psql -f` and Supabase MCP `execute_sql`). Idempotent: deletes the fixed IDs first, then re-seeds. **DEV ONLY.**

```sql
-- Phase 0 seed — representative volume for the load-test handle.
-- Scoped ENTIRELY to the fixed test IDs below. Idempotent (deletes first).
-- RUN AGAINST DEV ONLY (Supabase ref glncumufgaqcmqhzwrxm).
DO $$
DECLARE
  v_user uuid := '00000000-0000-4000-a000-000000000001';
  v_site uuid := '00000000-0000-4000-a000-000000000002';
BEGIN
  -- ---- teardown-then-seed (idempotent re-run) ----
  DELETE FROM analytics.link_clicks   WHERE site_id = v_site;
  DELETE FROM analytics.section_views WHERE site_id = v_site;
  DELETE FROM analytics.site_visits   WHERE site_id = v_site;
  DELETE FROM analytics.content_popularity_scores WHERE site_id = v_site;
  DELETE FROM site.services   WHERE user_id = v_user;
  DELETE FROM site.site_media WHERE site_id = v_site;
  DELETE FROM site.blocks     WHERE site_id = v_site;
  DELETE FROM site.design_kits WHERE site_id = v_site;
  DELETE FROM site.sites      WHERE id = v_site;
  DELETE FROM core.users      WHERE id = v_user;

  -- ---- user + published site ----
  INSERT INTO core.users (id, handle, handle_lc, display_name, first_name, status, account_type)
  VALUES (v_user, 'loadtest', 'loadtest', 'Load Test', 'Load', 'active', 'partna');

  INSERT INTO site.sites (id, user_id, subdomain, is_published, architecture_id)
  VALUES (v_site, v_user, 'loadtest', true, 'staple');
  -- design_kits row auto-inserted by the on-create trigger.

  -- ---- link blocks (links engine) — 10 ----
  INSERT INTO site.blocks
    (user_id, site_id, block_type, block_group, title, url, sort_order, is_active, is_enabled, category, platform)
  SELECT v_user, v_site, 'link', 'links',
         'Link ' || g, 'https://example.com/' || g, g, true, true, 'social', 'instagram'
  FROM generate_series(1, 10) g;

  -- ---- section blocks (must be is_enabled=true to render) ----
  INSERT INTO site.blocks
    (user_id, site_id, block_type, block_group, title, sort_order, is_active, is_enabled, settings)
  VALUES
    (v_user, v_site, 'gallery',    'sections', 'Gallery',    1, true, true, '{}'::jsonb),
    (v_user, v_site, 'services',   'sections', 'Services',   2, true, true, '{}'::jsonb),
    (v_user, v_site, 'contact',    'sections', 'Contact',    3, true, true, '{"subject_options":["General"]}'::jsonb),
    (v_user, v_site, 'newsletter', 'sections', 'Newsletter', 4, true, true, '{"input_placeholder":"Your email"}'::jsonb),
    (v_user, v_site, 'workplace',  'sections', 'Workplace',  5, true, true, '{"name":"Test Studio","city":"Melbourne"}'::jsonb);

  -- ---- gallery media — 40 ready images ----
  INSERT INTO site.site_media
    (site_id, bucket, path, alt_text, sort_order, is_active, pool, media_type, processing_state)
  SELECT v_site, 'public-assets', 'loadtest/img-' || g || '.webp',
         'Gallery image ' || g, g, true, 'gallery', 'image', 'ready'
  FROM generate_series(1, 40) g;

  -- ---- services — 15 owner-authored (source NULL => public-visible) ----
  INSERT INTO site.services
    (user_id, title, description, price_cents, currency_code, duration_minutes, is_active, sort_order)
  SELECT v_user, 'Service ' || g, 'Description for service ' || g,
         5000 + g * 500, 'AUD', 30 + (g % 4) * 15, true, g
  FROM generate_series(1, 15) g;

  -- ---- popularity scores — read on the hot path (services + gallery + link blocks) ----
  INSERT INTO analytics.content_popularity_scores (site_id, content_type, content_key, score, rank)
  SELECT v_site, 'service', s.id::text, random() * 100,
         row_number() OVER (ORDER BY random())
  FROM site.services s WHERE s.user_id = v_user;

  INSERT INTO analytics.content_popularity_scores (site_id, content_type, content_key, score, rank)
  SELECT v_site, 'gallery_item', m.id::text, random() * 100,
         row_number() OVER (ORDER BY random())
  FROM site.site_media m WHERE m.site_id = v_site;

  INSERT INTO analytics.content_popularity_scores (site_id, content_type, content_key, score, rank)
  SELECT v_site, 'block', b.id::text, random() * 100,
         row_number() OVER (ORDER BY random())
  FROM site.blocks b WHERE b.site_id = v_site AND b.block_group = 'links';

  -- ---- raw analytics events — realistic table volume (minimal confirmed columns) ----
  INSERT INTO analytics.site_visits (user_id, site_id)
  SELECT v_user, v_site FROM generate_series(1, 3000);

  INSERT INTO analytics.link_clicks (user_id, site_id, url)
  SELECT v_user, v_site, 'https://example.com/' || (g % 10)
  FROM generate_series(1, 1500) g;

  INSERT INTO analytics.section_views (user_id, site_id, section_key)
  SELECT v_user, v_site, (ARRAY['gallery','services','contact'])[1 + (g % 3)]
  FROM generate_series(1, 1500) g;
END $$;
```

- [x] **Step 2: Write teardown.sql**

Create `scripts/launch-check/k6/teardown.sql`. Reverses everything the seed and any write scenario created, scoped to the fixed IDs. **DEV ONLY.**

```sql
-- Teardown — removes the load-test handle and ALL its rows (seed + write-scenario
-- traffic). Scoped to the fixed test IDs. RUN AGAINST DEV ONLY.
DO $$
DECLARE
  v_user uuid := '00000000-0000-4000-a000-000000000001';
  v_site uuid := '00000000-0000-4000-a000-000000000002';
BEGIN
  DELETE FROM analytics.link_clicks   WHERE site_id = v_site;
  DELETE FROM analytics.section_views WHERE site_id = v_site;
  DELETE FROM analytics.site_visits   WHERE site_id = v_site;
  DELETE FROM analytics.content_popularity_scores WHERE site_id = v_site;
  DELETE FROM site.services   WHERE user_id = v_user;
  DELETE FROM site.site_media WHERE site_id = v_site;
  DELETE FROM site.blocks     WHERE site_id = v_site;
  DELETE FROM site.design_kits WHERE site_id = v_site;
  DELETE FROM site.sites      WHERE id = v_site;
  DELETE FROM core.users      WHERE id = v_user;
END $$;
```

- [x] **Step 3: Apply the seed to DEV**

Run against dev (Supabase MCP `execute_sql` with dev project_id, or `psql "$DEV_DB_URL" -f scripts/launch-check/k6/seed.sql`). **Confirm dev before running.**
Expected: no error; the `DO` block completes.

- [x] **Step 4: Re-sync KV (the seed re-created the site row)**

Run: `~/.composer/vendor/bin/cloud tinker development` then:

```php
\App\Jobs\Cloudflare\SyncSubdomainToKvJob::dispatchSync('00000000-0000-4000-a000-000000000001');
```

- [x] **Step 5: Verify the payload now carries representative data**

Run: `curl -s "https://dev-api.partna.au/api/public/profiles/loadtest" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print('links',len(d['links']),'gallery',len(d['gallery']),'services',len(d['services']))"`
Expected: `links 10 gallery 40 services 15` (non-zero on all three). Zeros mean a seed constraint/column mismatch — the `DO` block would have errored, so re-check Step 3's output.

- [x] **Step 6: Commit**

```bash
git add scripts/launch-check/k6/seed.sql scripts/launch-check/k6/teardown.sql
git commit -m "feat(k6): phase-0 representative-data seed + teardown (dev-scoped)"
```

---

### Task 4: Phase 1 — baseline.js (read-only, paced under the limiter)

**Files:**
- Create: `scripts/launch-check/k6/baseline.js`

**Interfaces:**
- Consumes: `ORIGIN`, `TEST_HANDLE`, `LOAD_HEADERS`, `THRESHOLDS.baseline` from `config.js`.

- [x] **Step 1: Write baseline.js**

Create `scripts/launch-check/k6/baseline.js`. Uses `constant-arrival-rate` at 45/min so the profile endpoint stays safely under the 60/min single-IP limiter — this measures latency, not throttling. Duration/rate are env-overridable for smoke runs.

```javascript
import http from 'k6/http';
import { check } from 'k6';
import { ORIGIN, TEST_HANDLE, LOAD_HEADERS, THRESHOLDS } from './config.js';

// Phase 1 — baseline (read-only, safe). Paced UNDER the 60/min public-profile
// limiter (default 45/min) so p50/p95/p99 reflect real latency, not 429s.
// Smoke: k6 run -e DURATION=15s -e RATE=20 baseline.js
export const options = {
  scenarios: {
    baseline: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.RATE || 45),
      timeUnit: '1m',
      duration: __ENV.DURATION || '5m',
      preAllocatedVUs: 10,
      maxVUs: 20,
    },
  },
  thresholds: THRESHOLDS.baseline,
};

const params = { headers: LOAD_HEADERS };

export default function () {
  const profile = http.get(`${ORIGIN}/api/public/profiles/${TEST_HANDLE}`, params);
  check(profile, {
    'profile 200': (r) => r.status === 200,
    'profile has data envelope': (r) => !!r.body && r.body.includes('"data"'),
  });

  // Aggressively cacheable — exercises the other cheap read surfaces.
  const social = http.get(`${ORIGIN}/api/public/config/social-platforms`, params);
  check(social, { 'social 200': (r) => r.status === 200 });

  const health = http.get(`${ORIGIN}/api/health`, params);
  check(health, { 'health 200': (r) => r.status === 200 });
}
```

- [x] **Step 2: Smoke-run baseline.js (safe, 15s)**

Run: `cd scripts/launch-check/k6 && k6 run -e DURATION=15s -e RATE=20 baseline.js`
Expected: run completes; `checks` ~100% passing; thresholds section shows `http_req_duration` and `http_req_failed` PASS. Any `profile 200` failures → the handle/seed is missing (re-run Task 3).

- [x] **Step 3: Commit**

```bash
git add scripts/launch-check/k6/baseline.js
git commit -m "feat(k6): phase-1 baseline read-path script"
```

- [ ] **Step 4: CHECKPOINT — joint measured run (do NOT run solo at full duration)**

Coordinate with Josh (§8). Claude runs; Josh watches Horizon + Supabase connections + Nightwatch:
```bash
k6 run --out json=results/baseline-run1.json baseline.js
```
Record reference p50/p95/p99 + error rate in README (Task 7). This is the number future releases regress against.

---

### Task 5: Phase 2a — spike-edge.js (edge-cache HIT assertion)

**Files:**
- Create: `scripts/launch-check/k6/spike-edge.js`

**Interfaces:**
- Consumes: `EDGE_HOST`, `SPIKE_VUS`, `LOAD_HEADERS`, `THRESHOLDS.edge` from `config.js`.
- Produces: a custom `Rate` metric `edge_cache_hit` (fraction of post-warmup responses with `Cf-Cache-Status: HIT`).

- [x] **Step 1: Write spike-edge.js**

Create `scripts/launch-check/k6/spike-edge.js`. A short warmup scenario primes the edge cache; the measured scenario (starting after warmup) records the HIT rate. k6 canonicalises the header to `Cf-Cache-Status`.

```javascript
import http from 'k6/http';
import { check } from 'k6';
import { Rate } from 'k6/metrics';
import { EDGE_HOST, SPIKE_VUS, LOAD_HEADERS, THRESHOLDS } from './config.js';

// Phase 2a — edge spike (the real viral path). Hits the zone-wide Worker at
// <handle>.partna.au. After warmup, virtually every identical view must be a
// HIT and never touch origin. A sustained MISS/DYNAMIC = a Worker cache bug.
// Smoke: k6 run -e SPIKE_VUS=2 -e DURATION=15s spike-edge.js
const edgeCacheHit = new Rate('edge_cache_hit');

export const options = {
  scenarios: {
    warmup: {
      executor: 'constant-vus',
      vus: 5,
      duration: '15s',
      exec: 'warm',
      gracefulStop: '0s',
    },
    spike: {
      executor: 'constant-vus',
      vus: SPIKE_VUS,
      duration: __ENV.DURATION || '2m',
      startTime: '15s',
      exec: 'measure',
    },
  },
  thresholds: THRESHOLDS.edge,
};

const params = { headers: LOAD_HEADERS };

// Prime the edge cache; do NOT record the metric during warmup.
export function warm() {
  http.get(`${EDGE_HOST}/`, params);
}

export function measure() {
  const res = http.get(`${EDGE_HOST}/`, params);
  const status = res.headers['Cf-Cache-Status'] || 'NONE';
  edgeCacheHit.add(status === 'HIT');
  check(res, {
    'edge 200': (r) => r.status === 200,
    'served from edge cache (HIT)': () => status === 'HIT',
  });
}
```

- [x] **Step 2: Smoke-run spike-edge.js (safe, tiny)**

Run: `cd scripts/launch-check/k6 && k6 run -e SPIKE_VUS=2 -e DURATION=15s spike-edge.js`
Expected: run completes; the `edge_cache_hit` metric appears; after the 15s warmup most `measure` responses are HIT. If `edge_cache_hit` is ~0, the Worker isn't populating `caches.default` for this route — capture and flag before escalating (a real cost/scale bug per §7), do not "fix" the threshold.

- [x] **Step 3: Commit**

```bash
git add scripts/launch-check/k6/spike-edge.js
git commit -m "feat(k6): phase-2a edge-cache HIT spike script"
```

- [ ] **Step 4: CHECKPOINT — joint measured run**

With Josh watching (Cloudflare analytics / origin request count should stay ~flat):
```bash
k6 run --out json=results/spike-edge-run1.json spike-edge.js
```
Pass = `edge_cache_hit` rate > 0.9 and near-zero origin hits during the sustained phase.

---

### Task 6: Phase 2b — spike-origin.js (cache-buster + limiter engagement)

**Files:**
- Create: `scripts/launch-check/k6/spike-origin.js`

**Interfaces:**
- Consumes: `ORIGIN`, `TEST_HANDLE`, `SPIKE_VUS`, `LOAD_HEADERS`, `THRESHOLDS.origin` from `config.js`.
- Produces: custom `Counter` metrics `origin_5xx` and `origin_429`.

- [x] **Step 1: Confirm the limiter is ON on dev (pre-flight for a meaningful run)**

Run: `~/.composer/vendor/bin/cloud tinker development` then:

```php
config('partna.public_profile.rate_limit_per_minute');
```

Expected: `60` (or the configured value), not `0`/disabled. If limiters are globally disabled (`$throttleEnabled` false), this scenario measures nothing — resolve before running. Type `exit`.

- [x] **Step 2: Write spike-origin.js**

Create `scripts/launch-check/k6/spike-origin.js`. `?rand=` per request defeats the edge cache so every request reaches origin. 200 **and** 429 are expected (the limiter returning 429 is the pass); only 5xx is a real failure.

```javascript
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { ORIGIN, TEST_HANDLE, SPIKE_VUS, LOAD_HEADERS, THRESHOLDS } from './config.js';

// Phase 2b — origin cache-buster (single-attacker sim). Limiter ON (option A).
// PASS = clean 429s engage, origin volume stays bounded, Supabase connections
// stay flat (Josh watches). 200 and 429 are both EXPECTED; 5xx is the failure.
// Smoke: k6 run -e SPIKE_VUS=3 -e DURATION=15s spike-origin.js
http.setResponseCallback(http.expectedStatuses(200, 429));

const origin5xx = new Counter('origin_5xx');
const origin429 = new Counter('origin_429');

export const options = {
  scenarios: {
    cacheBuster: {
      executor: 'constant-vus',
      vus: SPIKE_VUS,
      duration: __ENV.DURATION || '1m',
    },
  },
  thresholds: THRESHOLDS.origin,
};

export default function () {
  // Unique query string per request -> defeats the edge cache -> hits origin.
  const url = `${ORIGIN}/api/public/profiles/${TEST_HANDLE}?rand=${__VU}-${__ITER}`;
  const res = http.get(url, { headers: LOAD_HEADERS });
  if (res.status >= 500) origin5xx.add(1);
  if (res.status === 429) origin429.add(1);
  check(res, {
    'no 5xx': (r) => r.status < 500,
    'served (200) or throttled (429)': (r) => r.status === 200 || r.status === 429,
  });
}
```

- [x] **Step 3: Smoke-run spike-origin.js (safe, tiny)**

Run: `cd scripts/launch-check/k6 && k6 run -e SPIKE_VUS=3 -e DURATION=15s spike-origin.js`
Expected: run completes; `origin_429` > 0 (limiter engaged from one IP), `origin_5xx` == 0. `http_req_failed` stays low because 429 is an expected status.

- [x] **Step 4: Commit**

```bash
git add scripts/launch-check/k6/spike-origin.js
git commit -m "feat(k6): phase-2b origin cache-buster + limiter-engagement script"
```

- [ ] **Step 5: CHECKPOINT — joint measured run**

With Josh watching Supabase connection count (must stay flat — the limiter shields PG):
```bash
k6 run --out json=results/spike-origin-run1.json spike-origin.js
```
Pass = `origin_5xx` == 0, `origin_429` > 0, Supabase connections flat. Optional **Option B** (spec §4): only if Josh decides PG capacity is genuinely unclear, temporarily raise the limiter for a tight watched window and restore after — a conscious, separate checkpoint, not part of this task.

---

### Task 7: Phase 3 — jobs.js (deferred) + README finalize

**Files:**
- Create: `scripts/launch-check/k6/jobs.js`
- Create: `scripts/launch-check/k6/README.md`

**Interfaces:**
- Consumes: `ORIGIN`, `TEST_SITE_ID`, `LOAD_HEADERS`, `THRESHOLDS.jobs` from `config.js`.
- Produces: custom `Counter` metrics `jobs_5xx`, `jobs_accepted`.

- [x] **Step 1: Write jobs.js**

Create `scripts/launch-check/k6/jobs.js`. POSTs pageview beacons (`site_id` from config). On dev `QUEUE_CONNECTION=redis`, so the **QueuedIngestor** is active and each accepted write enqueues a Horizon job — this is what saturates the pipeline. **Held until Phases 1–2 pass** (§5).

```javascript
import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { ORIGIN, TEST_SITE_ID, LOAD_HEADERS, THRESHOLDS } from './config.js';

// Phase 3 — job / upload saturation (DEFERRED until 1 & 2 pass). Analytics
// pageview writes feed the QueuedIngestor -> Horizon on dev. Watch: queue depth,
// worker memory (climbs and does NOT recover?), job failure rate, and cross-queue
// starvation (redis_video vs default/analytics). 201 accepted / 429 throttled are
// both expected; 5xx is the failure.
//
// NOTE: the `analytics` limiter is 120/min per IP. To actually SATURATE the queue
// from one IP, Josh must decide at run time whether to temporarily raise it for a
// watched window (restore after) — see README "Phase 3 limiter decision".
// Smoke: k6 run -e JOB_VUS=2 -e DURATION=10s jobs.js
http.setResponseCallback(http.expectedStatuses(201, 429));

const jobs5xx = new Counter('jobs_5xx');
const jobsAccepted = new Counter('jobs_accepted');

export const options = {
  scenarios: {
    jobs: {
      executor: 'constant-vus',
      vus: Number(__ENV.JOB_VUS || 20),
      duration: __ENV.DURATION || '30s',
    },
  },
  thresholds: THRESHOLDS.jobs,
};

const body = JSON.stringify({ site_id: TEST_SITE_ID });
const params = { headers: { 'Content-Type': 'application/json', ...LOAD_HEADERS } };

export default function () {
  const res = http.post(`${ORIGIN}/api/public/analytics/pageviews`, body, params);
  if (res.status >= 500) jobs5xx.add(1);
  if (res.status === 201) jobsAccepted.add(1);
  check(res, {
    'no 5xx': (r) => r.status < 500,
    'accepted (201) or throttled (429)': (r) => r.status === 201 || r.status === 429,
  });
}
```

- [x] **Step 2: Smoke-run jobs.js (safe, tiny) — then TEAR DOWN**

Run: `cd scripts/launch-check/k6 && k6 run -e JOB_VUS=2 -e DURATION=10s jobs.js`
Expected: run completes; `jobs_accepted` > 0, `jobs_5xx` == 0.
Then immediately clean the write rows the smoke run created:
Run the teardown (dev): `psql "$DEV_DB_URL" -f teardown.sql` (or Supabase MCP `execute_sql` with `teardown.sql`).
_The smoke run is a write scenario — §6 requires teardown after it. (Re-seed via `seed.sql` before any further read-path runs.)_

- [x] **Step 3: Write README.md**

Create `scripts/launch-check/k6/README.md`:

````markdown
# k6 Load-Testing Harness

DIY single-origin load tests for Partna's public read path + job pipeline.
Design: `docs/superpowers/specs/2026-07-18-k6-load-testing-design.md`.
Plan: `docs/superpowers/plans/2026-07-26-k6-load-testing.md`.

## Target: DEV only

- Origin / baseline / jobs → `https://dev-api.partna.au`
- Edge spike → `https://loadtest.partna.au/` (one zone-wide Worker; unavoidably prod edge, cache-HITs only)
- **Never** point the cache-buster or jobs at `api.partna.au` (live prod) — prod capacity run is deferred to `OPS-S4-3`.

## Named target load

50 concurrent viewers (launch-day peak × safety). Escalate to 200 only after a joint checkpoint:
`SPIKE_VUS=200 k6 run spike-edge.js`. Never escalate solo.

## Setup (once)

1. `brew install k6`
2. Seed the test handle on dev: apply `seed.sql` (Supabase MCP `execute_sql`, dev ref, or `psql "$DEV_DB_URL" -f seed.sql`).
3. Sync KV: `cloud tinker development` → `\App\Jobs\Cloudflare\SyncSubdomainToKvJob::dispatchSync('00000000-0000-4000-a000-000000000001');`
4. Verify: `curl -s https://dev-api.partna.au/api/public/profiles/loadtest | ...` → links 10 / gallery 40 / services 15.

## Run order (checkpoint between every phase)

| Phase | Command | Pass criteria |
|-------|---------|---------------|
| 1 Baseline | `k6 run --out json=results/baseline-run1.json baseline.js` | p95 < 500ms, http_req_failed < 0.01. Record p50/p95/p99 below. |
| 2a Edge   | `k6 run --out json=results/spike-edge-run1.json spike-edge.js` | edge_cache_hit > 0.9; origin hits ~flat |
| 2b Origin | `k6 run --out json=results/spike-origin-run1.json spike-origin.js` | origin_5xx == 0; origin_429 > 0; Supabase connections flat |
| 3 Jobs (deferred) | `k6 run --out json=results/jobs-run1.json jobs.js` | jobs_5xx == 0; Horizon depth drains; worker memory recovers; no cross-queue starvation |

## Guardrails (§6)

- `X-Load-Test: 1` on every request.
- Start at the numbers above; escalate only after a joint review.
- **Kill switch:** Josh watches Supabase connections + Horizon depth; Ctrl-C k6 if either climbs toward a ceiling.
- **Teardown after every write scenario** (Phase 3): `psql "$DEV_DB_URL" -f teardown.sql`, then re-seed.

## Phase 3 limiter decision

The `analytics` limiter is 120/min per IP. To saturate the queue from one IP, either
(a) temporarily raise the limiter for a tight watched window and restore after, or
(b) drive jobs via a tinker loop. Decide with Josh at Phase 3 time. Phase 3 stays
deferred until Phases 1 & 2 pass.

## Baseline reference (fill after first Phase 1 run)

- p50: __ ms · p95: __ ms · p99: __ ms · error rate: __
- Date: ____ · target: 50 concurrent · env: dev

## Collaboration (§8)

Claude drives k6 + `cloud env:logs partna development --live`. Josh watches Horizon
(depth, worker memory), Supabase connections (Supavisor headroom), Nightwatch.
Run one phase, stop, review both sides, decide escalate/move-on/abort together.
````

- [x] **Step 4: Commit**

```bash
git add scripts/launch-check/k6/jobs.js scripts/launch-check/k6/README.md
git commit -m "feat(k6): phase-3 job-saturation script (deferred) + harness README"
```

- [ ] **Step 5: CHECKPOINT — Phase 3 measured run is GATED**

Do NOT run the full Phase 3 measured run until Phases 1 & 2 have passed AND the limiter decision is made with Josh. When run, watch Horizon queue depth, worker memory (must recover), job failure rate, and `redis_video` vs default/analytics starvation. Tear down + re-seed after.

---

## Self-Review

**1. Spec coverage:**
- §2 environment (dev, drift-corrected to prod-live) → Global Constraints + config.js comment. ✓
- §3 two layers (edge/origin) → Tasks 5 (edge) + 6 (origin). ✓
- §4 rate-limiter constraint → baseline pacing (Task 4), origin 429-expected (Task 6), Phase 3 limiter note (Task 7). ✓
- §5 Phase 0 seed → Task 3; Phase 1 → Task 4; Phase 2a → Task 5; Phase 2b → Task 6; Phase 3 deferred → Task 7. ✓
- §6 guardrails (named target, caps, X-Load-Test, teardown, kill-switch) → Global Constraints + LOAD_HEADERS + teardown.sql + README. ✓
- §7 proves/does-not-prove → README pass criteria; edge-bug flag in Task 5 Step 2. ✓
- §8 collaboration → CHECKPOINT steps + README. ✓
- §9 layout + run command shape → File Structure + README table (`--out json=results/...`). ✓
- §10 open items (target load 50, test handle `loadtest`, k6 install) → resolved in Task 1/2/config. ✓

**2. Placeholder scan:** No TBD/TODO/"add error handling"/"similar to Task N". All scripts, SQL, and commands are complete. The README "Baseline reference" blanks are an intentional fill-after-run record, not a code placeholder.

**3. Type/name consistency:** `THRESHOLDS` keys (`baseline`/`edge`/`origin`/`jobs`) match each script's `THRESHOLDS.<key>` use. Metric names align with their thresholds: `edge_cache_hit` (edge), `origin_5xx`/`origin_429` (origin), `jobs_5xx`/`jobs_accepted` (jobs). Fixed UUIDs (`...001` user, `...002` site) identical across seed.sql, teardown.sql, config.js `TEST_SITE_ID`, and the KV-sync command. `SyncSubdomainToKvJob::dispatchSync(<userId>)` matches the verified constructor signature.
