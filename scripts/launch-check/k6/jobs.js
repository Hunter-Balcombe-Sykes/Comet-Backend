import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { ORIGIN, EDGE_HOST, TEST_SITE_ID, LOAD_HEADERS, THRESHOLDS } from './config.js';

// Phase 3 — job / upload saturation (DEFERRED until 1 & 2 pass). Analytics
// pageview writes feed the QueuedIngestor -> Horizon on dev. Watch: queue depth,
// worker memory (climbs and does NOT recover?), job failure rate, and cross-queue
// starvation (redis_video vs default/analytics). 201 accepted / 429 throttled are
// both expected; 5xx is the failure.
//
// Origin header REQUIRED: AnalyticsController::originAllowed() (SEC-1,
// 2026-07-24) fails closed with 404 "Site not found" on any pageview POST
// with no Origin/Referer header — site_id/subdomain are public values and
// can't authenticate a caller on their own. EDGE_HOST already resolves to
// https://{TEST_HANDLE}.partna.au, which matches the seeded site's allowed
// origin exactly (verified against config('partna.public_domain') = partna.au).
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
const params = { headers: { 'Content-Type': 'application/json', 'Origin': EDGE_HOST, ...LOAD_HEADERS } };

export default function () {
  const res = http.post(`${ORIGIN}/api/public/analytics/pageviews`, body, params);
  if (res.status >= 500) jobs5xx.add(1);
  if (res.status === 201) jobsAccepted.add(1);
  check(res, {
    'no 5xx': (r) => r.status < 500,
    'accepted (201) or throttled (429)': (r) => r.status === 201 || r.status === 429,
  });
}
