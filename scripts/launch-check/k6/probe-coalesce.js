import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';
import { EDGE_HOST, LOAD_HEADERS } from './config.js';

// Phase 2c — concurrent-miss (coalescing) probe.
//
// QUESTION: when N requests for the SAME cold cache key arrive at one Cloudflare
// PoP simultaneously, does the Worker fetch origin once, or N times?
//
// WHY IT MATTERS: the Worker uses the Cache API (`caches.default`,
// src/index.js:730), and Cloudflare documents that the Cache API — unlike
// standard Workers Caching — does NOT collapse concurrent requests: "a burst of
// traffic to a fresh URL invokes your Worker once per request". Both the cold
// path (src/index.js:755) and the stale-shadow path (:746, which fires
// ctx.waitUntil(fetchAndCache) per request) would then fan out 1:1 to origin.
//
// THE MEASUREMENT IS SERVER-SIDE, NOT HERE. A collapsing edge serves the waiters
// the SAME response the single Worker run produced — so all N would report
// `X-Partna-Cache: origin` either way. The client cannot tell the two apart.
// The discriminator is how many requests reach Laravel:
//   ~1  arrival  -> something coalesces
//   ~N  arrivals -> 1:1 fan-out, stampede confirmed
// Count with:
//   cloud env:logs partna development --minutes 5 | grep -c 'profiles/loadtest'
// Poll IMMEDIATELY — the log buffer caps at ~100 entries and rotates in under a
// minute under any real traffic.
//
// PREREQUISITE: the key must be cold. `CloudflarePurgeService::purgeHandle()`
// purges the primary AND the `/_swr-shadow/` key, so:
//   cloud tinker development --code='app(\App\Services\Cloudflare\CloudflarePurgeService::class)->purgeHandle("loadtest");'
// then run this within a few seconds, before organic traffic re-warms it.
//
// The X-Partna-Cache tally below does NOT answer the coalescing question. It is
// here to prove the burst actually hit a cold key — a run that reports `hit` was
// warm and must be discarded, not interpreted.
//
// Run: k6 run scripts/launch-check/k6/probe-coalesce.js
// Smoke: k6 run -e BURST=3 scripts/launch-check/k6/probe-coalesce.js
const BURST = Number(__ENV.BURST || 50);

const cacheOrigin = new Counter('cache_origin');
const cacheStale = new Counter('cache_stale');
const cacheHit = new Counter('cache_hit');
const cacheOther = new Counter('cache_other');

export const options = {
    scenarios: {
        // per-vu-iterations with 1 iteration each: every VU fires once and stops,
        // so the burst is as close to simultaneous as k6 gets. constant-vus would
        // keep looping and re-warm the key mid-measurement.
        burst: {
            executor: 'per-vu-iterations',
            vus: BURST,
            iterations: 1,
            maxDuration: '1m',
        },
    },
    // No thresholds: this probe is diagnostic. Its pass/fail lives in the origin
    // arrival count, which k6 cannot see.
};

export default function () {
    const res = http.get(`${EDGE_HOST}/`, { headers: LOAD_HEADERS });

    const status = (res.headers['X-Partna-Cache'] || 'none').toLowerCase();
    if (status === 'origin') cacheOrigin.add(1);
    else if (status === 'stale') cacheStale.add(1);
    else if (status === 'hit') cacheHit.add(1);
    else cacheOther.add(1);

    check(res, {
        'edge 200': (r) => r.status === 200,
        'key was cold (not a HIT)': () => status !== 'hit',
    });

    console.log(`vu=${__VU} status=${res.status} cache=${status} ms=${Math.round(res.timings.duration)}`);
}
