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
  // Thresholds here are p50/p95 only, DELIBERATELY. Reviewed 2026-08-03 against
  // four runs (results/2026-08-03-baseline-warmup-comparison.md):
  //
  //   NEVER add a `max` threshold. Runs A and C were identical conditions and
  //   gave 4562.9ms and 550.3ms — an 8x spread. `max` samples a rare transport
  //   event, not Partna. Never quote it as a Partna latency figure either.
  //
  //   No p(99) either. Observed: 293.9 / 320.7 / 376.0 / 889.8ms. Anything below
  //   890 fails run A on a one-in-2100 TTFB event that hit /api/health (no DB
  //   work) at the same instant, i.e. container-or-path, not application code.
  //   Anything above 890 cannot fire before p(95)<500 does: the origin's own log
  //   puts server p95 at 91ms and its worst request at 351ms over 970 requests.
  //   Flaky or dead; neither is worth a gate.
  //
  //   p50/p95 held within ~8% across four runs and ARE trustworthy. Tightening
  //   p(95) is defensible on the data (worst observed 241.4) — deferred to
  //   COV-TAIL-10, which will run these scripts and can set it from more points.
  //
  //   Before attributing any future tail to Partna, split http_req_waiting (TTFB)
  //   from http_req_receiving. http_req_duration is blended client+network+edge.
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
