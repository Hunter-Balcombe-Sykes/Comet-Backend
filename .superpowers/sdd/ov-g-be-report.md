# OV-G-BE · Analytics updates (backend) — report

Status: COMPLETE (+ 3 review fixes applied). `composer test` green; `pint --dirty` clean.
Real (non-symlink) vendor materialized in the worktree before testing.
Branch `tobias/ov-g-analytics` (rebased onto origin/development past OV-A-BE #255).
PR: https://github.com/Hunter-Balcombe-Sykes/partna-backend/pull/256 (do NOT merge — orchestrator merges).

Review fixes (pre-merge):
1. TRANSITION ALIAS — user summary emits BOTH `top_sections` (section-grain,
   `topSections()`) AND `top_pages` (folded, `topPages()`); each its own true
   projection. (`referrers()` made fail-open so the summary path runs on the
   SQLite test mirror; enabled the new http-level both-keys test.)
2. STAFF COLLISION — `StaffAggregateAnalyticsController` `top_pages` now sourced
   from the folded `topPages()` (was `topSections()`); `topPages()` widened to the
   OV-A `string|array|null $userScope` scope injection so `top_pages` means the
   same folded page-grain on both endpoints.
3. HEADLINE/VALUE CONSISTENCY — the 4 percent insights derive the headline number
   FROM the rounded `supporting_stat.value` (new `formatMagnitude()`), so a FE
   rendering both never shows a mismatch.
Tail (noted, not fixed): per-insight try/catch isolation → dashboard-batch plan Tail.

Pre-existing flaky test note: `tests/Feature/Analytics/RankedActionsComputeTest`
(OV-I-BE's ranked-actions system, not touched here) flaked ONCE under an ad-hoc
multi-path `pest` invocation and passed on immediate re-run + in the canonical
`composer test` order. Root cause is the suite's shared-SQLite state (no
`RefreshDatabase`); my tests use random UUIDs scoped away from its fixed tenant.
Not introduced by this change.

## Goal

1. Stale-metric fix: "section views" → "page views" at the LABEL + QUERY/aggregation
   layer only. Never rewrite stored analytics rows or stored event-type keys
   (`section_view`, table `analytics.section_views`, `section_key` values are
   immutable data). Fold section-grain rows into the 16-page taxonomy
   (`App\Enums\SitepageId::SECTION_KEY_TO_PAGE`) so the dashboard shows correct
   page-level aggregation + terminology.
2. Insight engine (starter set): derived, actionable, plain-English insights
   computed from EXISTING analytics tables, exposed as an `insights` array on the
   analytics endpoint the dashboard consumes + a dedicated `GET /api/analytics/insights`.
   Defensive thresholds: an insight only emits when it has enough data to be true.

## Key constraints discovered

- **SQLite test mirror can't run Postgres-only SQL** (ILIKE / DATE_TRUNC / `::text`).
  The existing summary path is only verified against the deployed backend for that
  reason (see `SummaryRangeTest` note). Design consequence: **split data-fetch
  (Postgres SQL) from insight derivation (pure PHP)**. The pure `InsightEngine` is
  fully unit-testable on any platform; fetch queries are written SQLite-compatibly
  where feasible (driver-branched hour/DOW like the existing `bucketExpressions`)
  and the one genuinely Postgres-only fetch (referrer-source classification, ILIKE)
  is wrapped defensively so it degrades to "insight absent", never an error.
- Column is `user_id` (renamed from `professional_id` by `20260527030000`); the
  SQLite mirror uses `user_id`. Unique-visitor expr is driver-branched
  (`AnalyticsQueryService::uniqueVisitorExpr`).
- Frontend Zod schema is `.passthrough()` + `.optional()` — new keys (`top_pages`,
  `insights`) never break the parse; renaming `top_sections`→`top_pages` is safe
  (old key simply becomes absent) and coordinated with OV-G-FE.
- Analytics reads are fail-open by contract (every click/session query already
  try/catches to empty). Insights follow the same rule.

## Design

### Stale-metric fix
- NEW `AnalyticsQueryService::pageCase()` — SQL `CASE` folding `section_key` → page-id,
  single-sourced from `SitepageId::SECTION_KEY_TO_PAGE` (mirrors the existing
  `sourceCase()` pattern). Unknown keys → excluded (no page).
- NEW `AnalyticsQueryService::topPages()` — page-view rollup: groups
  `analytics.section_views` by folded page, `COUNT(*)` = views,
  `COUNT(DISTINCT visitor)` = unique_viewers, ordered by views. Row shape
  `{key: <page-id>, title, views, unique_viewers}` (parallels old top_sections rows).
- NEW `AnalyticsQueryService::pageTitle()` + `config('partna.analytics.page_titles')`
  (page-id → display label; humanize fallback).
- `AnalyticsCacheService::compose()` payload: `top_sections` → **`top_pages`**.
- Stored keys, the ingest writer, `section_views`/`section_view`/`section_key`,
  and the still-tested `topSections()`/`sectionTitle()` helpers are left untouched
  (query-layer alias only).

### Insight engine
- NEW pure `App\Services\Analytics\InsightEngine` — one public method per insight,
  thresholds as named class constants, returns the insight dict or null/[] below
  threshold. No DB, no config, no clock — fully deterministic + unit-tested.
- NEW fetch methods on `AnalyticsQueryService` (Postgres, SQLite-compatible except
  the defensively-wrapped source one): `hourlyClickBuckets`, `weekdayVisitBuckets`,
  `pageViewsWeekOverWeek`, `sourceCountsForWindow`, `pageDwellStats`.
- NEW `AnalyticsCacheService::insights(User,Site)` — orchestrates fetch → engine,
  cached (own key `analytics:insights:{user}:v{version}`, 1h TTL) over a fixed
  14-day window, wrapped fail-open (→ `[]`). Injected `InsightEngine`.
- `UserAnalyticsController::summary()` merges `insights` into the response;
  NEW `insights()` action + `GET /api/analytics/insights` route (reuses the cache).

### Insights shipped (starter set)
| kind | fires when | headline example |
|------|-----------|------------------|
| `time_of_day` | ≥20 clicks in window, evening(18–23h)/daytime(6–17h) per-hour rate differs ≥15% | "Your visitors click 32% more often in the evening (after 6pm) than during the day." |
| `weekday_peak` | ≥20 visits, ≥3 weekdays with data, peak ≥25% above weekday average | "Saturday is your busiest day — 47% more visits than your typical day." |
| `page_riser` | prior-week page views ≥10, change ≥ +25% WoW | "Your Shop page views are up 60% this week vs last." |
| `page_faller` | prior-week page views ≥10, change ≤ −25% WoW | "Your Gallery page views are down 40% this week vs last." |
| `traffic_source_shift` | both weeks ≥15 visits, top source changed, new top ≥8 visits | "Instagram overtook Direct Link as your top traffic source this week." |
| `dwell_outlier` | ≥8 dwell samples on a page, site avg > 0, page avg ≥1.5× site avg | "Visitors spend 2.1× longer on your Gallery page than your site average." |

## Frontend contract (verbatim)

Consumed via `GET /api/professional/analytics` (alias `/api/analytics`) and the new
`GET /api/professional/analytics/insights`. Both use the shared success envelope:
the payload is the response **root** (no `data` wrapper) — read `payload?.data ?? payload`,
exactly as `lib/analytics/data.ts` already does.

### 1. Stale-metric rename — `top_sections` → `top_pages` (TRANSITION: both emitted)

The summary payload now emits **BOTH** `top_sections` (unchanged section-grain,
via `topSections()` — what the live dashboard still reads) **and** the new
**`top_pages`** (via `topPages()`): the same per-visibility metric, but folded
from raw `section_key` grain into the 16-page sitepage taxonomy
(`SitepageId::SECTION_KEY_TO_PAGE`) and relabelled "page views". Each key is
backed by its own true projection (NOT `top_sections := topPages()`). Stored
analytics rows / event keys are unchanged — folding is query-layer only.
`top_sections` is a transition alias and will be dropped in a one-line follow-up
once OV-G-FE deploys reading `top_pages`.

Row shape (unchanged field names from the old `top_sections` rows, so the FE
mapping is a key-rename + a label tweak):
```jsonc
"top_pages": [
  { "key": "shop", "title": "Shop", "views": 128, "unique_viewers": 96 },
  { "key": "gallery", "title": "Gallery", "views": 41, "unique_viewers": 37 }
]
```
- `key` — page-id (one of the 16 `SitepageId` values), NOT a section_key.
- `title` — display label from `config('partna.analytics.page_titles')`.
- `views` — COUNT of section-view events folded to this page.
- `unique_viewers` — distinct visitors on the page (correct across folded sections).

FE action for OV-G-FE: in `lib/analytics/data.ts`, read `root.top_pages` (was
`root.top_sections`), relabel the card "Page views"/"Top pages" (was "Top sections"),
and rename `TopSectionDatum`→`TopPageDatum` (`topSections`→`topPages`). The Zod
schema is `.passthrough()`+`.optional()` so nothing breaks in the interim; just
switch the reader key. `staff` analytics payload never carried top_sections — no
change there.

### 2. `insights` array (on summary payload AND the dedicated endpoint)

`GET /api/analytics` payload now includes `insights`; `GET /api/analytics/insights`
returns `{ "insights": [...] }` with the identical array (same fixed rolling
window, cached independently of any date range). Empty array = not enough data
to say anything true (never fabricated). Order is not significant.

Each element:
```jsonc
{
  "id": "time_of_day",            // stable, unique within the array (one per kind)
  "kind": "time_of_day",          // category — switch on this to pick an icon/format
  "headline": "Your visitors click 32% more often in the evening (after 6pm) than during the day.",
                                   // plain-English, number already embedded — safe to render as-is
  "supporting_stat": {
    "metric": "clicks",           // clicks | visits | page_views | dwell_seconds
    "value": 32.0,                // signed number; interpret with `unit`
    "unit": "percent_change",     // see per-kind table
    "detail": { /* kind-specific, see below */ }
  },
  "period": { "from": "2026-06-27", "to": "2026-07-11", "label": "Last 14 days" }
}
```

Kinds shipped (each emits 0–1 insight, except page riser/faller which are two
separate kinds):

| kind | unit | value meaning | detail keys |
|------|------|---------------|-------------|
| `time_of_day` | `percent_change` | evening vs daytime per-hour click rate (signed) | `evening_clicks_per_hour`, `daytime_clicks_per_hour`, `sample` |
| `weekday_peak` | `percent_above_average` | busiest weekday vs typical-day visits | `weekday`, `peak_visits`, `average_visits`, `sample` |
| `page_riser` | `percent_change` | page views WoW (positive) | `page`, `this_week`, `prior_week` |
| `page_faller` | `percent_change` | page views WoW (negative) | `page`, `this_week`, `prior_week` |
| `traffic_source_shift` | `percent_share` | new top source's share of this week's visits | `new_top_source`, `previous_top_source`, `new_top_visits`, `week_visits` |
| `dwell_outlier` | `multiple` | page avg dwell ÷ site avg dwell (e.g. 2.1 = 2.1×) | `page`, `page_avg_seconds`, `site_avg_seconds` |

Rendering notes for OV-G-FE:
- `headline` is fully formed — render verbatim; no client-side number formatting needed.
- `period.label` is a ready display string ("Last 14 days" / "This week vs last week").
- Numeric types survive JSON as number; whole-number floats may arrive as ints
  (e.g. `100.0`→`100`) — treat `value` as a number, don't `===` a float literal.
- `supporting_stat.detail` is a bag for a secondary line / tooltip; keys vary by kind.

## Tests
- `tests/Unit/Analytics/InsightEngineTest.php` — each insight: fires w/ sufficient
  data + stat correct; does NOT fire below threshold (no fabrication).
- `tests/Feature/Api/User/Analytics/PageViewsMetricTest.php` — page-view folding +
  terminology + correct numbers on seeded section_views.
- `tests/Feature/Api/User/Analytics/InsightsEndpointTest.php` — endpoint 200 + shape
  + SQLite-runnable insights fire on seeded rows.

## Verification
- Real (non-symlink) vendor materialized in the worktree; `composer test` green;
  `php artisan pint --dirty` clean.
