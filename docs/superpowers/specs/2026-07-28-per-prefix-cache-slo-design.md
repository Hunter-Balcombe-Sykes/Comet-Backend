# Per-prefix cache hit-rate SLO

**Date:** 2026-07-28
**Status:** Approved, not yet implemented
**Origin:** `docs/superpowers/research/cache-gold-standard-2026-07-28.md` §2.2 (P1)
**Effort:** ~2h

## Problem

`AggregateCacheMetricsJob` applies a single `min_hit_rate` (default 0.9) to every
tracked cache prefix. A TTL cache's hit rate is bounded by `1 − e^(−λT)`,
approximated as `1 − 1/(λT)` when `λT ≫ 1` — the ceiling is a property of the
prefix's TTL and the traffic against it, not of cache health.

The two tracked prefixes have TTLs an order of magnitude apart:

| Prefix | Dominant TTL source | TTL | Reads/min/key needed for 90% |
|--------|--------------------|-----|------------------------------|
| `site` | `cache.ttls.public_payload` | 900s | ~0.67 |
| `pro`  | `cache.ttls.professional_model` | 60s | ~10 |

Dev measured ~5.7 hits per recompute on `pro` — an ~87% ceiling. A 90% target
against an ~87% ceiling fires every hour regardless of cache health: the
"alerting on traffic, not health" anti-pattern (SRE Workbook, *Alerting on
SLOs*).

The current mitigation makes this worse. The dev Cloud environment carries
`PARTNA_CACHE_SLO_MIN_HIT_RATE=0.6`, which silences `pro` by also blinding
`site` — `site` may now sit at 61% indefinitely without alerting. The blanket
knob cannot separate the two because there is only one knob.

**Explicitly out of scope:** raising `professional_model`'s 60s TTL. That TTL is
a freshness contract on the authenticated user's own profile — an edit must be
visible within a minute. Weakening a correctness guarantee to make a dashboard
green is the wrong trade.

## Design

### 1. Config (`config/partna.php`, `cache.slo`)

Add a per-prefix map beside the existing scalar. The scalar is retained as the
fallback, not replaced.

```php
'min_hit_rate' => (float) env('PARTNA_CACHE_SLO_MIN_HIT_RATE', 0.9),
'min_hit_rate_by_prefix' => [
    'site' => (float) env('PARTNA_CACHE_SLO_MIN_HIT_RATE_SITE', 0.90),
    'pro'  => (float) env('PARTNA_CACHE_SLO_MIN_HIT_RATE_PRO',  0.80),
],
```

**Why the scalar must survive.** `RecordCacheMetrics::extractPrefix()` derives a
prefix from the first `:`-segment of any cache key, minus a skip-list and
hash-shaped single-use keys. Prefixes are therefore open-ended, not an enum. Any
prefix added to `PARTNA_CACHE_SLO_PREFIXES` without a matching map entry falls
back to the scalar rather than to zero or to an error.

**Why per-prefix env vars rather than a parsed CSV.** Explicit, greppable, no
parser to get wrong, and each is independently settable in Laravel Cloud. A
typo'd CSV key would silently fall back to the scalar with no signal.

**Derivation of the two numbers.**

- `site = 0.90` — at a 900s TTL, 90% needs under one read per minute per key.
  Reachable in every environment; a breach here means real cache trouble.
- `pro = 0.80` — the worst observed ceiling on `pro` is ~87% (dev). 0.80 leaves
  ~7 points of headroom under that, so the alert has room to distinguish a
  genuine regression from ordinary traffic variance. `pro` is not purely
  `professional_model` (the un-staled read path serves most of the prefix per
  `RecordCacheMetrics` line 36), so this is a defensible floor rather than a
  computed ceiling — it should not be treated as exact.

`min_sample` stays a scalar. It is a statistical noise floor ("is this bucket
large enough to mean anything"), not a TTL-derived ceiling; one value serves both
prefixes. A second map is unjustified until a concrete problem demands it.

Extend the existing `cache.slo` comment block — which already carries the
`1 − 1/(λT)` explanation — to record the derivation above, so the numbers are not
mistaken for arbitrary picks.

`.env.example` (currently lines 354–356) gains the two new vars, commented, next
to the existing three.

### 2. Job (`app/Jobs/Cache/AggregateCacheMetricsJob::handle()`)

The threshold lookup moves inside the existing per-prefix loop. No control-flow
change.

```php
$byPrefix = (array) config('partna.cache.slo.min_hit_rate_by_prefix', []);
$fallback = (float) config('partna.cache.slo.min_hit_rate');

// inside foreach ($stats as $prefix => $counts):
$minHitRate = (float) ($byPrefix[$prefix] ?? $fallback);
```

Untouched: the `min_sample` gate, the `in_array($prefix, $sloPrefixes, true)`
gate, the `Log::info('cache.metrics', …)` payload, the message format, and the
trailing-zero trim on `$slo` (which already renders `0.80` as `≥80%`).

**Known one-time consequence.** The Nightwatch issue title embeds the threshold,
so both prefixes fork a new issue on deploy — `pro` from `≥60%` to `≥80%`, `site`
from `≥60%` to `≥90%`. Two apparently-new issues appearing is expected, not a
regression.

### 3. Tests (`tests/Feature/Cache/AggregateCacheMetricsJobTest.php`)

- **Update** the "confirms slo prefixes and threshold defaults are as documented"
  test to assert the new map alongside the existing scalar.
- **Add** the test that actually pins the fix: one bucket containing both
  prefixes, each at 85% hit rate over a sample above `min_sample`. Exactly one
  report must fire, naming `site`. A single-prefix test would pass under the old
  scalar and prove nothing.
- **Add** a fallback test: a tracked prefix absent from the map is judged against
  the scalar.
- **Keep** the existing `min_sample`, prefix-list, and genuine-breach tests
  unchanged.

### 4. Environment (manual, post-merge to `development`)

```bash
cloud environment:variables development --action=set --key=PARTNA_CACHE_SLO_MIN_HIT_RATE --value=0.9
```

Neutralises the 0.6 blanket so both prefixes take their per-prefix defaults. This
is the step that restores `site`'s alarm; without it the code change has no
observable effect on dev.

**Set to the default rather than deleted, deliberately.** The `cloud` CLI has no
per-variable delete — `environment:variables` *replaces all* variables from a
file (`--action` accepts `append`, `set`, `replace`), so removing one key means
round-tripping the entire set and risking clobbering unrelated vars. Setting the
key to its config default is equivalent in effect and carries no such risk. The
key's value is now only the fallback for prefixes absent from the map, so 0.9 is
the honest value to leave there.

`PARTNA_CACHE_SLO_MIN_SAMPLE=100` stays. Dev traffic is genuinely thin and a
100-read floor is a reasonable trustworthiness bar there — unlike the 0.6, it
addresses a real problem.

Production carries no override and is unaffected until it has traffic worth
judging.

## Non-goals

- Raising any TTL.
- Making `min_sample` per-prefix.
- Burn-rate or multi-window alerting (research §2.2 P2, separate work).
- Any schema, queue, or migration change. There are none.

## Verification

- `composer test -- --filter=AggregateCacheMetricsJob` green.
- After the env-var deletion, the next hourly run should produce a `pro` bucket
  that no longer reports at ~87%, and a `site` bucket that would report if it
  fell under 90%.
