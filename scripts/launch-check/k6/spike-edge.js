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
