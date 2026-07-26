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
