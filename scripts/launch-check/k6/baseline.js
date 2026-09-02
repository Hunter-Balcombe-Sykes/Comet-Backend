import http from 'k6/http';
import { check } from 'k6';
import { ORIGIN, TEST_HANDLE, LOAD_HEADERS, THRESHOLDS } from './config.js';

// Phase 1 — baseline (read-only, safe). Paced UNDER the 60/min public-profile
// limiter (default 45/min) so p50/p95/p99 reflect real latency, not 429s.
// Smoke: k6 run -e DURATION=15s -e RATE=20 baseline.js
//
// WARM-UP (opt-in, -e WARMUP=45s). Default OFF so a bare `k6 run baseline.js`
// stays byte-for-byte comparable to the recorded reference in README.md.
// When set, an identically-paced `warmup` scenario runs FIRST and the measured
// `baseline` scenario starts the instant it stops. Warm-up traffic is still
// issued and still appears in the raw JSON — it is excluded from the PASS/FAIL
// decision only, by scoping every threshold to `{scenario:baseline}`.
// k6 tags every metric point with its scenario name automatically, so this
// needs no custom tag plumbing.
//
// Why it exists: run 1 (2026-07-31) recorded max 805ms against p99 376ms and
// attributed it to cold start WITHOUT testing that. Comparing a warm run to a
// cold one is the test.
const RATE = Number(__ENV.RATE || 45);
const DURATION = __ENV.DURATION || '5m';
const WARMUP = __ENV.WARMUP || '';

// Identical pacing in both scenarios: the warm-up must warm the SAME code path
// at the SAME rate, or it is warming something the measured phase does not use.
const pacing = {
  executor: 'constant-arrival-rate',
  rate: RATE,
  timeUnit: '1m',
  preAllocatedVUs: 10,
  maxVUs: 20,
};

const scenarios = {};
if (WARMUP) {
  // gracefulStop 0s: hand over to the measured phase with no idle gap, so the
  // server cannot re-cool between the two.
  scenarios.warmup = Object.assign({}, pacing, {
    duration: WARMUP,
    startTime: '0s',
    gracefulStop: '0s',
  });
}
scenarios.baseline = Object.assign({}, pacing, {
  duration: DURATION,
  startTime: WARMUP || '0s',
});

// Scope thresholds to the measured scenario when warming up; otherwise use the
// shared preset verbatim. Numbers stay owned by config.js either way.
const thresholds = {};
for (const metric of Object.keys(THRESHOLDS.baseline)) {
  thresholds[WARMUP ? `${metric}{scenario:baseline}` : metric] = THRESHOLDS.baseline[metric];
}

export const options = { scenarios, thresholds };

const params = { headers: LOAD_HEADERS };

// COV-TAIL-10: r.body.includes('"data"') passed for {"data":{}} — a degraded
// build (empty engine outputs) reported 100% pass. This parses the body and
// checks real array lengths against seed.sql's own generate_series bounds.
//
// REPOINTED 2026-09-02, and the old assertions were worse than stale — they
// were unfalsifiable. This checked profile.{gallery,services,links}; NONE of
// those three keys exists on the wire any more (verified against dev-api:
// data.profile carries accountType, bio, brand, contact, displayName,
// document, handle, headshot, newsletter, pools, publicContact, site_id,
// workplace — and nothing else). `undefined` is not an Array, so the whole
// check returned false on every request... which the run still passed,
// because a k6 `check` that returns false is recorded, not fatal. Curated
// content moved to data.profile.pools.<pool>.items; that is what is asserted
// now, and seed.sql mints the content.* rows that fill them.
//
// The counts are seed.sql's: 6 media, 15 services, 10 custom_links. All three
// were confirmed non-zero end-to-end against dev-api after seeding — an
// assertion nobody has watched pass is how the last one rotted.
//
// NOTE for anyone re-seeding by hand: the payload is SWR-cached off
// site.sites.updated_at, and a rebuild is deferred until AFTER the response.
// The first request following a seed serves the STALE body. Fetch twice.
function hasSeededProfileShape(r) {
  if (!r.body) {
    return false;
  }

  let body;
  try {
    body = JSON.parse(r.body);
  } catch (e) {
    return false;
  }

  const profile = body && body.data && body.data.profile;
  if (!profile || !profile.pools) {
    return false;
  }

  const count = (pool) => {
    const p = profile.pools[pool];
    return p && Array.isArray(p.items) ? p.items.length : -1;
  };

  return (
    count('media') === 6 &&
    count('services') === 15 &&
    count('custom_links') === 10
  );
}

export default function () {
  const profile = http.get(`${ORIGIN}/api/public/profiles/${TEST_HANDLE}`, params);
  check(profile, {
    'profile 200': (r) => r.status === 200,
    'profile pools carry seeded media/services/links counts (6/15/10)': hasSeededProfileShape,
  });

  // Aggressively cacheable — exercises the other cheap read surfaces.
  const social = http.get(`${ORIGIN}/api/public/config/social-platforms`, params);
  check(social, { 'social 200': (r) => r.status === 200 });

  const health = http.get(`${ORIGIN}/api/health`, params);
  check(health, { 'health 200': (r) => r.status === 200 });
}
