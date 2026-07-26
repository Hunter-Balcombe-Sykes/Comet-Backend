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
