import http from 'k6/http';
import { check } from 'k6';
import { ORIGIN, TEST_HANDLE, LOAD_HEADERS } from './config.js';

// Phase 1s — instrumented stall probe. NOT a regression gate; it has no
// thresholds and its numbers are not comparable to the baseline reference.
//
// WHY IT EXISTS. Raw JSON from five baseline runs (2026-08-03 x3, 08-06, 08-09)
// shows a multi-second stall in 2 of 5. Reconstructing each request's START time
// (k6 timestamps at COMPLETION, so start = completion - duration) shows requests
// issued 1.3-5s apart completing within ~110ms of each other — a queue draining
// in waves, not scattered slow requests. It hits /api/health, which is a bare
// closure with no Redis and no DB, so the time is not spent doing work.
//
// Three things were never measured, and each one is a whole branch of the tree:
//
//   1. Is it Partna at all, or this laptop's link? Nothing in the harness
//      separates them. Two controls on unrelated hosts answer it:
//        - control_cf     -> Cloudflare's own edge (Laravel Cloud fronts with CF)
//        - control_google -> anycast, NOT Cloudflare
//      Both stall  -> local link/CPU.  Only CF stalls -> Cloudflare-wide.
//      Neither stalls while /api/health does -> Partna's origin. That last case
//      is the only one worth engineering against.
//   2. Was a stalled profile request an edge HIT or a revalidation? Recorded
//      per request from cf-cache-status/age, so it is classifiable on sight
//      instead of inferred from an origin/edge ratio after the fact.
//   3. What did the ORIGIN think the duration was? Answered by pairing this with
//      poll-origin-logs.sh: cf-ray here, duration_ms there. ~5ms at the origin
//      means the wait was in FRONT of PHP; ~2700ms means it was inside it.
//
// Duration defaults to 20m, not the baseline's 5m: the event lands roughly once
// per affected run, so exposure per sitting is the only lever that matters.
//
// Both outputs are required — see the note on record() for why. Delete the trace
// first: --console-output APPENDS, so a stale file silently mixes runs and the
// join fails on records from a previous format.
//
//   rm -f results/stall-trace.jsonl
//   k6 run --out json=results/stall.json \
//          --log-format=raw --console-output=results/stall-trace.jsonl \
//          probe-stall.js
//
// Every line on the console output is one iteration, as JSON. Partna requests
// keep baseline.js's order and pacing verbatim so the traffic that produced the
// stall is the traffic being reproduced; the controls are issued after them and
// do not change Partna's arrival rate.
const RATE = Number(__ENV.RATE || 45);
const DURATION = __ENV.DURATION || '20m';

// Controls: tiny, cacheable-free, unauthenticated endpoints on hosts with no
// relationship to Partna. At 45/min for 20m that is ~900 requests each, which is
// ordinary browsing volume — but keep the rate here if this probe is ever
// lengthened.
const CONTROL_CF = 'https://www.cloudflare.com/cdn-cgi/trace';
const CONTROL_GOOGLE = 'https://www.google.com/generate_204';

export const options = {
  scenarios: {
    probe: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1m',
      duration: DURATION,
      preAllocatedVUs: 10,
      maxVUs: 20,
    },
  },
  // No thresholds ON PURPOSE. A threshold here would turn an unexplained
  // transport event into a red build, which is exactly the mistake the baseline
  // preset's "NEVER add a max threshold" note exists to prevent.
  thresholds: {},
};

// `ep` splits the JSON output per endpoint without parsing URLs; `it` is the
// join key back to the console trace.
//
// `it` is a CUSTOM tag carrying vu+iter rather than k6's `vu`/`iter` system
// tags: passing those to --system-tags is accepted silently and they never
// appear on the points, which collapsed a 150-record join to 5 keys on the
// smoke run. Custom tags always propagate. Cardinality is one value per
// iteration (~900 over a 20m run), which is fine for a hand-run diagnostic and
// would not be for a gate.
const partna = (ep, it) => ({ headers: LOAD_HEADERS, tags: { ep, it } });
const control = (ep, it) => ({ tags: { ep, it } });

// k6 canonicalises response header names, but the casing it picks is not worth
// depending on for a diagnostic that only runs by hand.
function header(res, name) {
  const wanted = name.toLowerCase();
  for (const key of Object.keys(res.headers || {})) {
    if (key.toLowerCase() === wanted) {
      return res.headers[key];
    }
  }
  return null;
}

// TIMESTAMPS DO NOT COME FROM HERE. k6's Date.now() does not advance inside an
// iteration — a smoke run stamped all five requests with one identical value,
// because http.get blocks within a single event-loop tick. Reconstructing a
// start from it would be worse than useless: it would look precise and be wrong.
//
// The authoritative per-request timestamp is the one k6 writes into --out json=,
// which is what the 2026-08-03/08-09 re-analysis used. So the JSON output owns
// timing, and this trace owns only what JSON cannot carry: the response headers
// that classify WHERE a request was served. The two join on (it, ep) — hence
// `ep` is both a request tag and a field here.
function record(label, res) {
  return {
    ep: label,
    st: res.status,
    dur: Math.round((res.timings || {}).duration * 10) / 10,
    cf: header(res, 'cf-cache-status'),
    age: header(res, 'age'),
    ray: header(res, 'cf-ray'),
  };
}

export default function () {
  const it = `${__VU}-${__ITER}`;
  const reqs = [];

  // --- Partna: identical to baseline.js, same order, same params. ---
  const profile = http.get(`${ORIGIN}/api/public/profiles/${TEST_HANDLE}`, partna('profile', it));
  reqs.push(record('profile', profile));
  check(profile, { 'profile 200': (r) => r.status === 200 });

  const social = http.get(`${ORIGIN}/api/public/config/social-platforms`, partna('social', it));
  reqs.push(record('social', social));
  check(social, { 'social 200': (r) => r.status === 200 });

  const health = http.get(`${ORIGIN}/api/health`, partna('health', it));
  reqs.push(record('health', health));
  check(health, { 'health 200': (r) => r.status === 200 });

  // --- Controls: same laptop, same link, same instant, different origins. ---
  reqs.push(record('control_cf', http.get(CONTROL_CF, control('control_cf', it))));
  reqs.push(record('control_google', http.get(CONTROL_GOOGLE, control('control_google', it))));

  console.log(JSON.stringify({ it, reqs }));
}
