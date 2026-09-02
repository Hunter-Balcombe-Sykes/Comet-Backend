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
// build (empty engine outputs) reported 100% pass. These parse the body and
// check real array lengths against seed.sql's known invariants (services and
// links counts are seed.sql's own generate_series bounds).
//
// The gallery arm was DROPPED 2026-09-02: `profile.gallery` left the wire on
// 2026-08-14 (slice 7 unit E) and the site_media 'gallery' pool behind it on
// 2026-09-01, so the check had been asserting an always-undefined key. It is
// not repointed at `profile.pools.media` because that pool reads content.items
// — seed.sql writes raw site.site_media rows, which never reach it. Restoring
// media coverage means teaching seed.sql to mint content items (source +
// source_item + item + media_asset), not swapping the key here.
//
// SEPARATELY BROKEN: seed.sql still inserts into site.services, DROPPED
// 2026-08-18 (services cutover) — the seed raises 42P01 before it finishes, so
// this harness cannot currently run at all. Both are one job.
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
  if (!profile) {
    return false;
  }

  return (
    Array.isArray(profile.services) && profile.services.length === 15 &&
    Array.isArray(profile.links) && profile.links.length === 10
  );
}

export default function () {
  const profile = http.get(`${ORIGIN}/api/public/profiles/${TEST_HANDLE}`, params);
  check(profile, {
    'profile 200': (r) => r.status === 200,
    'profile has seeded services/links counts (15/10)': hasSeededProfileShape,
  });

  // Aggressively cacheable — exercises the other cheap read surfaces.
  const social = http.get(`${ORIGIN}/api/public/config/social-platforms`, params);
  check(social, { 'social 200': (r) => r.status === 200 });

  const health = http.get(`${ORIGIN}/api/health`, params);
  check(health, { 'health 200': (r) => r.status === 200 });
}
