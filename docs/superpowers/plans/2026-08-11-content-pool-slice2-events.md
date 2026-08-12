# Content Pool Convergence — Slice 2 (Events Pool) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the orphaned `event` kind a real pool — registry entry, an auto-rule that suits dated content, event fields on the wire, presence gating, and a public render — then repair the 14 existing rows and retire the legacy events lane.

**Architecture:** `event` joins `PoolRegistry::POOLS` as its own pool keyed to the existing `SitepageId::Events` page. Because the pool contract's auto half (`latest_per_auto_source`) emits exactly one item per source, `PoolRegistry` grows a per-pool **section shape** (rule predicates + `order_by`) and `PoolSectionProvisioner` stops hardcoding one. Events use a new `upcoming_occurrence` predicate and a new `occurrence` ordering. `PoolResolver`'s item payload gains nullable occurrence/place/offer fields so a dated, located, priced item can render.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, PostgreSQL (Supabase). No new dependencies.

**Branch:** `feat/content-pool-slice2-events` off `development`.

**Spec:** `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §7 "Slice 2".

**Revision 2, 2026-08-11.** Revision 1 was independently reviewed against the codebase and dev DB. Four blockers and four majors were confirmed and are corrected here. The corrections that changed the plan's *shape*, not just its details:

| Revision 1 said | Actually true |
|---|---|
| The new rule operator is a `SectionCandidates` change; unknown ops are ignored by `default => null` | `RuleOperator` is a **closed enum**, `RulePredicate::fromArray()` **throws** on an unknown op, and `phrase()` is a `match ($this)` with no default. Writing the rule before the enum case exists 500s `SectionResource`. Operator registration is now Task 3, **before** anything writes the rule |
| `order_by` is "a code change only" — no CHECK constraint | True in the DB, false in the app: `SectionRuleRules::ORDER_BY` is a closed allowlist and a section PATCH round-trip 422s |
| `keyBy()` keeps the first row, so ordering picks the soonest occurrence and cheapest offer | `keyBy()` **overwrites** — last row wins. Revision 1 would have shipped the furthest date and the most expensive tier |
| `site.item_slugs.item_key` joins to `source_items.coord` by suffix | `item_key` is a payload hex id (`a53103b7e77fd64f`). The join returns **13 nulls**. Slugs map via the event URL, and 3 of the 13 have no content item at all |
| `BuildState` keeps a raw-write registry with a CI check | It does not. That is prose in a docblock. Spec §9.1 asserts it too and is also wrong |
| Retiring an item is reversible | `content.items.removed_at` is **never** cleared by reappearance (`ProjectionWriter:272-275`, `ItemController:14-18`). It is one-way |
| `channel` is architecturally blocked | Overstated. Two pools *can* share a page. The real objection is a product one — see "Scope decisions" |

---

## Scope decisions taken before this plan

Recorded here because they narrow the spec's stated slice-2 scope and a reviewer must be able to challenge them.

**`channel` and `article` are deferred; this slice is events only.** (Owner decision, 2026-08-11.)

- **`channel` is a product decision, not a hard block.** Revision 1 called it architecturally blocked; that was wrong and is corrected here. `PAGE_KEYS` maps *pool → page key* and nothing prevents two pools sharing a page — `PoolSectionProvisioner::ensurePage()` is a find-or-create on `(site_id, page_key)` and section keys are `pool:{pool}`, so `'channels' => ['channel']` with `PAGE_KEYS['channels'] = 'watch'` would provision cleanly with no change to `PoolResolver`, `PoolController` or `ItemLinkRules`. What is genuinely closed is one-pool-per-kind, and `channel` is one kind. The real objections are: (a) the 7 live rows split across platforms that already own **two different** pages — Twitch 3 → Watch, Spotify 3 + SoundCloud 1 → Listen — so any single pool mixes them onto one; and (b) a channel card is a *profile*, not a piece of content, so folding it into Watch changes what Watch means. Both are the owner's call, unmade.
- **`article` needs a product decision that has been taken as "no".** Nothing blocks it technically; it needs a new `SitepageId` case mirrored into `partna-monorepo/packages/design-system/src/engines/page-taxonomy.ts`. Owner declined a Writing page. The single dev row is `"Please update feed subscription"` — Substack feed housekeeping, not content.

Task 1 pins both deferrals with a guard test so neither leaks into a pool by accident and so the next person reads the reason rather than rediscovering it.

**Task 9 (legacy lane retirement) is separable.** It is the only task that changes the public wire for an existing rendered surface and the only one that touches the 301 permalink lane. If the slice needs to ship earlier, Tasks 1–8 stand alone and Task 9 becomes slice 2b. Note this fails the spec's own "the legacy events lane has no readers" done-criterion — say so explicitly in the checkpoint rather than ticking it.

---

## Global Constraints

Every task's requirements implicitly include this section.

- **Never create Laravel migration files.** The Composer guard rejects them. All schema changes go in `supabase/migrations/` as raw SQL. This slice needs **no** schema change — verify that claim before writing one.
- **Tests run SQLite; production is Postgres.** Any constraint-bound write must be checked against the DDL in `supabase/migrations/`, not against a green suite. Verified against dev `glncumufgaqcmqhzwrxm` and `20260727140000_content_schema.sql` on 2026-08-11:
  - `content.items.items_kind_check` admits 14 kinds including `event`.
  - `content.f_occurrence` — PK `(item_id, source_id)`; `updated_at timestamptz NOT NULL`; live `zone_confidence` CHECK admits `NULL | explicit | inferred | assumed | offset_only` (widened by `20260731230000`, validated). **The baseline file still shows the pre-widening three-value CHECK — read the live constraint, not `20260727140000:238`.**
  - `content.offers` — columns are `id, item_id, source_id, channel, variant_label, amount_minor, currency, qualifier, amount_max_minor, url, availability, updated_at`. **There is no `created_at`.** `updated_at` is NOT NULL. `qualifier` CHECK admits 7 values including `from` and `free`.
  - `content.f_place` — `venue_name, address, locality, region, country_code, latitude, longitude, updated_at NOT NULL`.
  - `site.sections.sections_mode_check` admits `hand_picked | automatic | mixed`.
  - `site.sections.order_by` has no DB CHECK, but `SectionRuleRules::ORDER_BY` is a closed app-level allowlist — see Task 3.
- **Business logic lives in `Services/`** (here: `app/Site/Pools/`, `app/Site/Sections/`), not controllers.
- **Every cache key carries a TTL.** Never `Cache::forever()`. Guarded by `tests/Feature/Cache/CacheKeyspaceConstraintsTest.php`.
- **Raw writes bypass observers**, so any `DB::table()->update()` in this slice must explicitly `BuildState::bump()` and purge the edge. **There is no raw-write registry and no CI check for one** — `BuildState.php:15-19` describes one in prose but the class holds only `bump()` / `read()` / `commit()`. Spec §9.1 repeats the claim and is wrong. Treat this as a discipline you must apply by hand; nothing will catch you.
- **Authorization via Policies**, never inline `abort_unless(...403)`. `ContentItemPolicy` is kind-agnostic (authorises on `user_id`), so `event` needs no new policy.
- **No slice may cite another slice's checkpoint as evidence** (spec §3 invariant 5).
- **Registration is not execution** (spec §3 invariant 6). A `PoolRegistry` entry is not proof a pool renders. Every "done" claim needs a live dev assertion with pasted output.
- **Do not use `git stash`** at any point.
- Run `php artisan pint` before each commit. Note `composer test --filter` is broken in this repo — run targeted Pest via `./vendor/bin/pest --filter`.

---

## Verified starting state (dev `glncumufgaqcmqhzwrxm`, 2026-08-11)

Every figure below was read live and independently re-confirmed.

```
content.items WHERE kind='event'    → 14 rows, 14 live (removed_at IS NULL), 2 users
content.items WHERE kind='channel'  →  7 rows   (deferred, Task 1 guards)
content.items WHERE kind='article'  →  1 row    (deferred, Task 1 guards)
site.item_slugs WHERE item_type='event' AND is_current → 13 rows
content.item_slugs                  →  0 rows
```

Health of the 14 event rows:

| Group | Count | State |
|---|---|---|
| Eventbrite, healthy | 5 | headline, `f_text`, `f_occurrence`, `f_place`, `offers`, `item_media` all present; upcoming |
| Eventbrite, stale | 3 | same facets, but `source_items.removed_at = 2026-08-05 16:45:11+00` while `content.items.removed_at IS NULL` — spec §9.8 asymmetry. All three start in the **past**, so `upcoming_occurrence` already excludes them from the selection; they pollute the library only |
| Humanitix, broken | 6 | `headline_cache` NULL, `facets_cache` `[]`, `f_text` 0, `f_occurrence` 0; 2 of them carry `f_link`/`offers`/`item_media`, 4 carry nothing |

**Root cause of the 6 broken rows — already-fixed bugs, unrepaired data.** `ingest.anomalies` recorded it on 2026-07-28:

```
kind=projection severity=warning
"Projection failed after a successful landing: SQLSTATE[23514]: Check violation:
 new row for relation "f_occurrence" violates check constraint
 "f_occurrence_zone_confidence_check""
```

`SchemaOrgEventProjector` emits `zone_confidence => 'offset_only'`; the original CHECK admitted only `explicit|inferred|assumed`. The insert threw and `ProjectionWriter::writeFacets()` aborted mid-loop. The projector's facet emission order is `f_link → f_occurrence → f_place → f_text`, which is exactly why the two affected items hold `f_link` and nothing after it.

Both underlying bugs are **already fixed in the repo**:
- `supabase/migrations/20260731230000_…` widened the CHECK (Nightwatch #370); dev shows it applied and `convalidated = true`.
- `app/Ingest/Runtime/RunExecutor.php:196` now writes projection anomalies at `severity='critical'` (they were `warning`, and `IngestAnomaliesCommand` filters on `critical` — so nobody was ever woken). The 14 `warning` rows on dev are historical.

**Why the data never healed, and why a plain re-projection fixes it.** `RunExecutor.php:168` gates projection on `($landed['changed'] > 0 || $landed['tombstoned'] > 0)`, and every Humanitix run since 2026-07-31 has `records_changed = 0` — so the widened CHECK was never applied to the existing docs. `IngestProjectCommand` has **no such gate**: it re-derives every live `record_state` row unconditionally, and `upsertSingletonFacet()` is an upsert, so healthy rows are untouched. Eventbrite healed because its records did change after 07-31. **The repair is `ingest:project`, not a code change** — Task 2.

---

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `tests/Unit/Site/Pools/PoolRegistryTest.php` | **Create.** Pins the one-kind-one-pool invariant the `PoolRegistry` docblock already claims is pinned but is not, plus the `channel`/`article` deferral guard | 1 |
| `app/Console/Commands/ContentRepairEventItemsCommand.php` | **Create.** Reports and repairs event items whose facets are incomplete or whose source items are all retired | 2, 5 |
| `app/Site/Sections/RuleOperator.php` | **Modify.** New `UpcomingOccurrence` case + `phrase()` arm + a `default` so the next case cannot 500 | 3 |
| `app/Site/Sections/SectionCandidates.php` | **Modify.** `EXECUTED_OPERATORS` entry, the `upcoming_occurrence` predicate, the `occurrence` ordering | 3 |
| `app/Http/Requests/Api/User/Sections/SectionRuleRules.php:21` | **Modify.** `'occurrence'` joins the `ORDER_BY` allowlist | 3 |
| `app/Site/Pools/PoolRegistry.php` | **Modify.** Add the `events` pool; add `SECTION_SHAPE` so rule + ordering are per-pool | 4 |
| `app/Site/Pools/PoolSectionProvisioner.php:50-55` | **Modify.** Read the section shape from the registry instead of hardcoding `latest_per_auto_source` / `recency` | 4 |
| `app/Site/Pools/ItemLinkRules.php:22-45` | **Modify.** Events roster + hosts | 6 |
| `app/Site/Pools/PoolResolver.php:181-295` | **Modify.** Occurrence / place / offer fields on the item payload | 7 |
| `app/Services/PublicSite/SitepageDataResolverService.php:322-334` | **Modify.** Events presence via the pool | 8 |
| `tests/Feature/Content/EventsPoolTest.php` | **Create.** The slice's behaviour suite | 3–8 |
| `docs/wire-changes/2026-08-11-slice2-events-pool.md` | **Create.** Wire manifest for Partna-App and partna-monorepo | 8, 9 |

**Testing note — do not copy `PoolLaneTest.php`'s style.** That file calls controllers directly (`app(PoolController::class)->show($request, $pool)` at `:90` and five more sites). Direct-controller invocation skips middleware, route-model binding and the auth stack, and has already hidden three live bugs in this codebase. Tests that exercise an endpoint go through the HTTP layer. Tests that exercise a *service* (`PoolResolver`, `PoolSectionProvisioner`) may call it directly — that is a unit boundary, not a controller bypass.

---

### Task 1: Pin the pool registry invariants and the deferrals

The `PoolRegistry` docblock says "PoolRegistryTest pins that". No such file exists. This task makes the claim true before the registry grows.

**Files:**
- Create: `tests/Unit/Site/Pools/PoolRegistryTest.php`

**Interfaces:**
- Consumes: `App\Site\Pools\PoolRegistry` as it exists today.
- Produces: nothing consumed by later tasks. A guard that later tasks must keep green.

- [ ] **Step 1: Write the test**

Create `tests/Unit/Site/Pools/PoolRegistryTest.php`:

```php
<?php

use App\Site\Pools\PoolRegistry;

// The registry is a closed map reached from a URL segment. These pin the
// three properties the rest of the pool lane assumes and never re-checks.

it('assigns every kind to at most one pool', function () {
    $seen = [];
    foreach (PoolRegistry::POOLS as $pool => $kinds) {
        foreach ($kinds as $kind) {
            // Message built lazily — an eager "{$seen[$kind]}" would read an
            // unset key on every passing iteration.
            if (isset($seen[$kind])) {
                throw new RuntimeException(
                    "kind '{$kind}' is claimed by both '{$seen[$kind]}' and '{$pool}' — "
                    .'an item in two pools is curated twice and excluded once',
                );
            }
            $seen[$kind] = $pool;
        }
    }
    expect($seen)->not->toBeEmpty();
});

it('gives every pool a page key and a label', function () {
    foreach (array_keys(PoolRegistry::POOLS) as $pool) {
        expect(PoolRegistry::PAGE_KEYS)->toHaveKey($pool);
        expect(PoolRegistry::PAGE_LABELS)->toHaveKey($pool);
    }
});

it('names only real pools in the latest-tag list', function () {
    foreach (PoolRegistry::LATEST_TAG_POOLS as $pool) {
        expect(PoolRegistry::isPool($pool))->toBeTrue();
    }
});

// Slice 2 deliberately leaves `channel` and `article` poolless. Both are
// live kinds with rows in content.items, so "no pool" must be a decision on
// the record rather than an oversight the next reader silently corrects.
//
//  channel — 7 rows: Twitch 3, Spotify 3, SoundCloud 1. NOT architecturally
//    blocked: two pools may share a page key, so a 'channels' pool pinned to
//    an existing page would provision fine. The objection is product — those
//    platforms own TWO different pages (Watch and Listen), so one pool mixes
//    them; and a channel card is a profile, not content. Owner's call, unmade.
//  article — 1 row (Substack). Unblocked technically; needs a Writing page,
//    which is a new SitepageId case in LOCKSTEP with the frontend taxonomy.
//    Owner declined 2026-08-11.
//
// Delete the relevant line here when you build the pool — the failure is the
// reminder to also update the deferral note in the slice-2 plan.
it('keeps the deferred kinds out of every pool', function (string $kind) {
    expect(PoolRegistry::poolForKind($kind))->toBeNull();
})->with(['channel', 'article']);
```

- [ ] **Step 2: Run it**

Run: `./vendor/bin/pest tests/Unit/Site/Pools/PoolRegistryTest.php`

Expected: 5 PASS (the last is a dataset of 2). This is a characterisation guard, not red-green — the invariants hold today. If any fails, stop and report: something is already broken and this plan's premise is wrong.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Site/Pools/PoolRegistryTest.php
git commit -m "test(pools): pin the registry invariants the docblock already claimed

PoolRegistry's docblock cites a PoolRegistryTest that has never existed.
Pins one-kind-one-pool, page-key/label completeness, and the deliberate
channel/article deferrals ahead of adding the events pool."
```

---

### Task 2: Repair the six broken Humanitix event items

Not a code change to the ingest lane. The two bugs that broke them are already fixed; the data was never re-derived. This task proves the repair works and leaves a command behind.

**Files:**
- Create: `app/Console/Commands/ContentRepairEventItemsCommand.php`
- Test: `tests/Feature/Content/EventItemRepairTest.php`

**Interfaces:**
- Consumes: `ingest.sources` / `ingest.streams` rows; `IngestProjectCommand` unchanged.
- Produces: `content:repair-event-items --dry-run` printing `incomplete: <n>` and `orphaned: <n>`; extended with `--retire` in Task 5.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/EventItemRepairTest.php`. Note every facet insert carries `updated_at` — it is NOT NULL on `f_occurrence`, `f_place`, `f_text`, `f_link` and mirrored NOT NULL in the SQLite harness (`tests/Pest.php` singletons loop).

```php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function repairSource(string $userId): string
{
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => (string) Str::uuid(), 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

// The 2026-07-28 signature: writeFacets() aborted on f_occurrence, so the
// item holds f_link and nothing the projector emits after it. The command
// must find these by absence-of-facets — no marker exists.
it('reports an event item whose facets are incomplete', function () {
    $pro = createTenant('repair-'.Str::lower(Str::random(6)));
    $sourceId = repairSource($pro->id);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'event',
        'headline_cache' => null, 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'humanitix:acct-test:broken-event', 'item_id' => $itemId,
        'kind' => 'event', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_link')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'url' => 'https://events.humanitix.com/broken-event', 'updated_at' => now(),
    ]);

    $this->artisan('content:repair-event-items --dry-run')
        ->expectsOutputToContain('incomplete: 1')
        ->assertExitCode(0);

    expect(DB::table('content.items')->where('id', $itemId)->value('headline_cache'))->toBeNull();
});

it('does not report a healthy event item', function () {
    $pro = createTenant('repair-'.Str::lower(Str::random(6)));
    $sourceId = repairSource($pro->id);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'event',
        'headline_cache' => 'Beginner sewing workshop', 'facets_cache' => '[]',
        'eligible_cache' => '[]', 'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'eventbrite:acct-test:healthy-event', 'item_id' => $itemId,
        'kind' => 'event', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => 'Beginner sewing workshop', 'updated_at' => now(),
    ]);
    DB::table('content.f_occurrence')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'starts_at_local' => '2026-08-24 11:30:00',
        'starts_at_utc' => '2026-08-24 01:30:00',
        'zone_confidence' => 'offset_only', 'is_all_day' => 0,
        'updated_at' => now(),
    ]);

    $this->artisan('content:repair-event-items --dry-run')
        ->expectsOutputToContain('incomplete: 0')
        ->assertExitCode(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Content/EventItemRepairTest.php`

Expected: FAIL — `The command "content:repair-event-items" does not exist.`

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/ContentRepairEventItemsCommand.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds event items that projection left in a bad state.
 *
 * Two distinct populations, both observed on dev 2026-08-11:
 *
 *  incomplete — the item has a live source_item but no f_text row. Signature
 *    of ProjectionWriter::writeFacets() aborting mid-loop, which is what the
 *    f_occurrence zone_confidence CHECK violation did to six Humanitix items
 *    on 2026-07-28 (constraint since widened by 20260731230000). The record
 *    log still holds the good docs, so `ingest:project` is the whole repair —
 *    RunExecutor gates projection on records having CHANGED, which is why
 *    the widened constraint never reached this data on its own.
 *
 *  orphaned — every source_item for the item carries removed_at, but the item
 *    itself does not (spec §9.8). Retired by --retire; see that flag's note
 *    on irreversibility.
 *
 * Read-only unless --retire. Nothing here re-fetches a byte.
 */
class ContentRepairEventItemsCommand extends Command
{
    protected $signature = 'content:repair-event-items
        {--dry-run : Report counts without writing}
        {--user= : Only items belonging to this user id}';

    protected $description = 'Report event items left incomplete or orphaned by projection.';

    public function handle(): int
    {
        $incomplete = $this->incompleteQuery()->get(['content.items.id', 'content.items.user_id']);
        $orphaned = $this->orphanedQuery()->get(['content.items.id', 'content.items.user_id']);

        $this->line('incomplete: '.$incomplete->count());
        $this->line('orphaned: '.$orphaned->count());

        if ($incomplete->isNotEmpty()) {
            // Re-projection is per ingest SOURCE, not per item — projectStream()
            // resolves identity across the whole stream, so asking for one item
            // would give a different (and wrong) merge result.
            $this->warn('Re-project the affected users with:');
            foreach ($incomplete->pluck('user_id')->unique() as $userId) {
                $this->line("  php artisan ingest:project --user={$userId}");
            }
        }

        return self::SUCCESS;
    }

    /** Live event items with a live source item but no headline facet. */
    private function incompleteQuery()
    {
        return DB::connection('pgsql')->table('content.items')
            ->where('content.items.kind', 'event')
            ->whereNull('content.items.removed_at')
            ->when($this->option('user'), fn ($q, $u) => $q->where('content.items.user_id', $u))
            ->whereExists(fn ($e) => $e->from('content.source_items')
                ->whereColumn('content.source_items.item_id', 'content.items.id')
                ->whereNull('content.source_items.removed_at'))
            ->whereNotExists(fn ($e) => $e->from('content.f_text')
                ->whereColumn('content.f_text.item_id', 'content.items.id'));
    }

    /** Live event items whose every source item has been retired. */
    private function orphanedQuery()
    {
        return DB::connection('pgsql')->table('content.items')
            ->where('content.items.kind', 'event')
            ->whereNull('content.items.removed_at')
            ->when($this->option('user'), fn ($q, $u) => $q->where('content.items.user_id', $u))
            ->whereExists(fn ($e) => $e->from('content.source_items')
                ->whereColumn('content.source_items.item_id', 'content.items.id'))
            ->whereNotExists(fn ($e) => $e->from('content.source_items')
                ->whereColumn('content.source_items.item_id', 'content.items.id')
                ->whereNull('content.source_items.removed_at'));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Content/EventItemRepairTest.php`

Expected: PASS (2 tests).

- [ ] **Step 5: Assert against dev, then repair**

```bash
php artisan content:repair-event-items --dry-run
```

Expected before repair: `incomplete: 6`, `orphaned: 3`.

Now re-project. Run **without** `--rebuild`: the facet writes are upserts, so a plain run inserts the missing rows and leaves the healthy Eventbrite items untouched. `--rebuild` drops `identity_keys` first, which can churn item identity and — via `mergeInto()`'s hard DELETE of uncurated losers (`ProjectionWriter:560-586`) — destroy rows.

```bash
php artisan ingest:project --dry-run --user=019e5c37-9a69-725c-b3a9-6a345af0376d
php artisan ingest:project --user=019e5c37-9a69-725c-b3a9-6a345af0376d
php artisan content:repair-event-items --dry-run
```

Expected after: `incomplete: 0`, `orphaned: 3`. Paste both outputs into the checkpoint.

Then confirm and paste:

```sql
SELECT count(*) AS live_events,
       count(*) FILTER (WHERE i.headline_cache IS NOT NULL) AS with_headline,
       count(*) FILTER (WHERE EXISTS (SELECT 1 FROM content.f_occurrence o WHERE o.item_id = i.id)) AS with_occurrence
FROM content.items i
WHERE i.kind = 'event' AND i.removed_at IS NULL;
```

**Gate: `with_headline` and `with_occurrence` must equal `live_events`.** Do *not* gate on `live_events = 14`. Re-projection writes identity keys for these six items for the first time, and if any two unify, `mergeInto()` legitimately collapses them — a lower count is a correct merge, not a failure. If the count drops, check `content.item_merges` before concluding anything is wrong.

If any item stays without a headline or occurrence, **stop and report** — the premise that both underlying bugs are fixed is then wrong.

- [ ] **Step 6: Scan the logs**

Run: `cloud env:logs partna development --minutes 10`

Expected: no `projection` errors, no `23514` check violations. Record in the checkpoint.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/ContentRepairEventItemsCommand.php tests/Feature/Content/EventItemRepairTest.php
git commit -m "feat(content): add content:repair-event-items

Finds event items projection left incomplete (no f_text despite a live
source item) or orphaned (every source item retired). The six broken
Humanitix items date to the 2026-07-28 f_occurrence zone_confidence CHECK
violation, fixed in schema by 20260731230000 but never re-derived: RunExecutor
gates projection on records having changed, and theirs never did."
```

---

### Task 3: Register the `upcoming_occurrence` operator and the `occurrence` ordering

**This task must land before anything writes the events rule.** The section rule DSL is closed at four separate sites, and revision 1 named only one of them:

| Site | What breaks without it |
|---|---|
| `RuleOperator` enum | `RulePredicate::fromArray():18-21` **throws** `InvalidArgumentException` on an unknown op. `Section::ruleObject()` has no try/catch and `SectionResource:33` calls it on every serialise — a **500**, not a degraded sentence |
| `RuleOperator::phrase()` | `match ($this)` with no `default` → `UnhandledMatchError` on the same path |
| `SectionCandidates::EXECUTED_OPERATORS` | `tests/Feature/Site/SectionTraceTest.php:109` asserts `toEqualCanonicalizing(RuleOperator::cases())` — CI fails either way round |
| `SectionRuleRules::ORDER_BY` | `'occurrence'` 422s any section PATCH round-trip from the dashboard |

**Semantics of `upcoming_occurrence`:** the item has an `f_occurrence` row whose `starts_at_utc` is not in the past, with one day of grace so an event running today does not vanish at its start time. An item with **no** `f_occurrence` row does not match — an undated event cannot be asserted upcoming. It stays in the library and can be pinned by hand.

**Files:**
- Modify: `app/Site/Sections/RuleOperator.php`
- Modify: `app/Site/Sections/SectionCandidates.php`
- Modify: `app/Http/Requests/Api/User/Sections/SectionRuleRules.php:21`
- Test: `tests/Unit/Site/Sections/RuleOperatorTest.php` (create)

**Interfaces:**
- Produces: `RuleOperator::UpcomingOccurrence = 'upcoming_occurrence'`; `'upcoming_occurrence'` in `EXECUTED_OPERATORS` and in `applyPredicate()`'s match; `'occurrence'` accepted by `ruleCandidates()` and by `SectionRuleRules::ORDER_BY`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Site/Sections/RuleOperatorTest.php`:

```php
<?php

use App\Site\Sections\RulePredicate;
use App\Site\Sections\RuleOperator;
use App\Site\Sections\SectionCandidates;

// Adding an operator touches four registries. Miss one and the failure is a
// 500 on a dashboard endpoint, not a red test — so pin all four here.

it('parses the upcoming_occurrence operator without throwing', function () {
    $predicate = RulePredicate::fromArray(['op' => 'upcoming_occurrence', 'values' => ['event']]);

    expect($predicate->operator)->toBe(RuleOperator::UpcomingOccurrence);
});

it('renders a phrase for every operator', function () {
    foreach (RuleOperator::cases() as $case) {
        expect($case->phrase())->toBeString()->not->toBe('');
    }
});

it('keeps the executed-operator list and the enum in lockstep', function () {
    expect(SectionCandidates::EXECUTED_OPERATORS)
        ->toEqualCanonicalizing(array_map(fn (RuleOperator $c) => $c->value, RuleOperator::cases()));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Site/Sections/RuleOperatorTest.php`

Expected: FAIL — `InvalidArgumentException: Unknown rule operator: upcoming_occurrence`.

- [ ] **Step 3: Add the enum case and a defaulted phrase**

In `app/Site/Sections/RuleOperator.php`, add the case after `LatestPerAutoSource`:

```php
    // The auto half of a DATED pool (events, 2026-08-11): the item occurs at
    // or after now, with a day of grace so something running today does not
    // vanish at its start time. An item with no f_occurrence row does NOT
    // match — "upcoming" asserts a date we do not have.
    case UpcomingOccurrence = 'upcoming_occurrence';
```

and give `phrase()` an arm plus a default, so the next case added cannot 500 a dashboard read:

```php
    public function phrase(): string
    {
        return match ($this) {
            self::KindIs => 'is a',
            self::HasFacet => 'has',
            self::FromSource => 'comes from',
            self::InCollection => 'is in',
            self::TaggedWith => 'is tagged',
            self::PublishedWithin => 'was published within',
            self::LatestPerAutoSource => "is a platform's newest",
            self::HasAction => 'can be',
            self::UpcomingOccurrence => 'is upcoming',
            // A missing arm used to be an UnhandledMatchError on a path that
            // renders a sentence — a broken phrase beats a 500.
            default => str_replace('_', ' ', $this->value),
        };
    }
```

Also update the class docblock: it says "EIGHT" operators. It is now nine.

- [ ] **Step 4: Add the predicate, the executed-operator entry, and the ordering**

In `app/Site/Sections/SectionCandidates.php`, add `'upcoming_occurrence'` to `EXECUTED_OPERATORS`, then add this arm to `applyPredicate()`'s `match`, immediately before `default => null`:

```php
            // See RuleOperator::UpcomingOccurrence. `values` is ignored: kind
            // narrowing is kind_is' job and the pool rule always pairs the two.
            'upcoming_occurrence' => $this->applyExists($query, $negated, function ($q) {
                $q->orWhereExists(fn ($e) => $e->from('content.f_occurrence')
                    ->whereColumn('content.f_occurrence.item_id', 'content.items.id')
                    ->whereNotNull('content.f_occurrence.starts_at_utc')
                    ->where('content.f_occurrence.starts_at_utc', '>=', now()->subDay()));
            }),
```

Then replace the `$ordered = match (...)` block in `ruleCandidates()`:

```php
        $ordered = match ($section->order_by ?? 'recency') {
            'alphabetical' => $query->orderBy('content.items.headline_cache'),
            // Dated pools (events): soonest first, undated last. A correlated
            // MIN scalar — not a join — because f_occurrence is keyed
            // (item_id, source_id), so an item carried by two sources would
            // emit two candidate rows through a join.
            'occurrence' => $query->orderByRaw(
                '(SELECT MIN(fo.starts_at_utc) FROM content.f_occurrence fo'
                .' WHERE fo.item_id = content.items.id) ASC NULLS LAST'
            ),
            default => $query->orderByDesc('content.items.last_seen_at'),
        };
```

`NULLS LAST` is safe in both lanes: the bundled SQLite here is 3.45.2 (supported since 3.30) and the harness `ATTACH`es `content` as a schema, so the three-part reference resolves. Verify anyway in Step 6.

- [ ] **Step 5: Widen the order_by validator**

In `app/Http/Requests/Api/User/Sections/SectionRuleRules.php:21`:

```php
    /** Orderings the document builder understands. */
    private const ORDER_BY = ['recency', 'alphabetical', 'popularity', 'manual', 'occurrence'];
```

- [ ] **Step 6: Run the operator, section and trace suites**

Run:
```
./vendor/bin/pest tests/Unit/Site/Sections/RuleOperatorTest.php
./vendor/bin/pest tests/Feature/Site --filter=Section
```

Expected: PASS, including `SectionTraceTest`'s lockstep assertion. Then run the Postgres schema lane so the `orderByRaw` is exercised on the real engine — a Postgres-only syntax error is invisible to the SQLite lane. See `docs/` for the schema-lane invocation; it needs shell `DB_*` vars.

- [ ] **Step 7: Commit**

```bash
git add app/Site/Sections/RuleOperator.php app/Site/Sections/SectionCandidates.php app/Http/Requests/Api/User/Sections/SectionRuleRules.php tests/Unit/Site/Sections/RuleOperatorTest.php
git commit -m "feat(sections): add the upcoming_occurrence operator and occurrence ordering

The rule DSL is closed at four sites — the enum, phrase(), EXECUTED_OPERATORS
and the request validator. RulePredicate::fromArray() throws on an unknown op
and SectionResource calls it on every serialise, so a rule written before the
enum case exists is a 500, not a degraded sentence. phrase() gains a default
so the next case cannot repeat that."
```

---

### Task 4: Per-pool section shape, and the events pool

`PoolSectionProvisioner::ensure()` hardcodes one rule for every pool. `latest_per_auto_source` resolves to exactly one item per connection source (`SectionCandidates:236-283`) — right for "newest release per platform", wrong for a dated list. This moves the shape onto the registry, then adds `events`.

**Files:**
- Modify: `app/Site/Pools/PoolRegistry.php`
- Modify: `app/Site/Pools/PoolSectionProvisioner.php:50-55`
- Test: `tests/Feature/Content/EventsPoolTest.php` (create)

**Interfaces:**
- Consumes: `RuleOperator::UpcomingOccurrence` from Task 3.
- Produces: `PoolRegistry::SECTION_SHAPE`; `PoolRegistry::sectionShape(string $pool): array{rule: list<array<string,mixed>>, order_by: string}`; pool key `events` with kinds `['event']`, `PAGE_KEYS['events'] = 'events'`, `PAGE_LABELS['events'] = 'Events'`, **not** in `LATEST_TAG_POOLS`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/EventsPoolTest.php`:

```php
<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

function eventsConnection(string $userId): string
{
    $id = (string) Str::uuid();
    DB::table('site.platform_connections')->insert([
        'id' => $id, 'user_id' => $userId, 'surface_key' => 'eventbrite.organiser',
        'routing_class' => 'content', 'resource_id' => 'acct-'.Str::random(6),
        'payload' => '{}', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function eventsSource(string $userId, string $connectionId): string
{
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => $connectionId, 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function eventItem(string $userId, string $sourceId, string $headline, ?string $startsAtUtc): string
{
    $id = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'event',
        'headline_cache' => $headline, 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now()->subDays(10), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'eventbrite:acct-test:'.Str::random(10), 'item_id' => $id,
        'kind' => 'event', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    if ($startsAtUtc !== null) {
        DB::table('content.f_occurrence')->insert([
            'item_id' => $id, 'source_id' => $sourceId,
            'starts_at_local' => $startsAtUtc, 'starts_at_utc' => $startsAtUtc,
            'zone_confidence' => 'offset_only', 'is_all_day' => 0,
            'updated_at' => now(),
        ]);
    }

    return $id;
}

it('registers events as a pool on the events page without a latest tag', function () {
    expect(PoolRegistry::isPool('events'))->toBeTrue();
    expect(PoolRegistry::kinds('events'))->toBe(['event']);
    expect(PoolRegistry::PAGE_KEYS['events'])->toBe('events');
    expect(PoolRegistry::PAGE_LABELS['events'])->toBe('Events');
    // A rolling list of dated events has no single "latest" — the soonest is
    // simply the first item, and a Latest badge on it would read as "new".
    expect(PoolRegistry::carriesLatestTag('events'))->toBeFalse();
});

it('provisions the events section with the upcoming rule, not latest-per-source', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    $section = app(PoolSectionProvisioner::class)->ensure($site, 'events');
    $ops = array_column(json_decode((string) $section->rule, true)['all'], 'op');

    expect($ops)->toContain('kind_is');
    expect($ops)->toContain('upcoming_occurrence');
    expect($ops)->not->toContain('latest_per_auto_source');
    expect($section->order_by)->toBe('occurrence');
    expect($section->mode)->toBe('mixed');
});

it('leaves the watch and listen sections on the latest-per-source rule', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    foreach (['watch', 'listen'] as $pool) {
        $section = app(PoolSectionProvisioner::class)->ensure($site, $pool);
        $ops = array_column(json_decode((string) $section->rule, true)['all'], 'op');

        expect($ops)->toContain('latest_per_auto_source');
        expect($section->order_by)->toBe('recency');
    }
});

it('hangs the events section off the existing events page', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();

    $section = app(PoolSectionProvisioner::class)->ensure($site, 'events');
    $pageKey = DB::connection('pgsql')->table('site.pages')->where('id', $section->page_id)->value('key');

    expect($pageKey)->toBe('events');
});

it('selects every upcoming event, not one per source', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    eventItem($pro->id, $source, 'Sewing workshop', now()->addDays(3)->toDateTimeString());
    eventItem($pro->id, $source, 'Grant writing', now()->addDays(10)->toDateTimeString());
    eventItem($pro->id, $source, 'Clothes swap', now()->addDays(20)->toDateTimeString());

    $resolved = app(PoolResolver::class)->resolve($site, 'events');

    // Three events from ONE source. latest_per_auto_source would give one.
    expect(array_column($resolved['selection'], 'headline'))
        ->toBe(['Sewing workshop', 'Grant writing', 'Clothes swap']);
});

it('drops a past event from the selection but keeps it in the library', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    eventItem($pro->id, $source, 'Last month', now()->subDays(30)->toDateTimeString());
    eventItem($pro->id, $source, 'Next week', now()->addDays(7)->toDateTimeString());

    $resolved = app(PoolResolver::class)->resolve($site, 'events');

    expect(array_column($resolved['selection'], 'headline'))->toBe(['Next week']);
    expect(array_column($resolved['library'], 'headline'))->toContain('Last month', 'Next week');
});

it('keeps an event that started earlier today', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    eventItem($pro->id, $source, 'Running now', now()->subHours(2)->toDateTimeString());

    expect(array_column(app(PoolResolver::class)->resolve($site, 'events')['selection'], 'headline'))
        ->toBe(['Running now']);
});

it('does not auto-select an undated event but keeps it pinnable', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    eventItem($pro->id, $source, 'No date given', null);

    $resolved = app(PoolResolver::class)->resolve($site, 'events');

    expect($resolved['selection'])->toBe([]);
    expect(array_column($resolved['library'], 'headline'))->toBe(['No date given']);
});

it('orders the events selection soonest first regardless of ingest order', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    // Inserted furthest-first on purpose — recency ordering would keep this.
    eventItem($pro->id, $source, 'Furthest', now()->addDays(30)->toDateTimeString());
    eventItem($pro->id, $source, 'Middle', now()->addDays(14)->toDateTimeString());
    eventItem($pro->id, $source, 'Soonest', now()->addDays(2)->toDateTimeString());

    expect(array_column(app(PoolResolver::class)->resolve($site, 'events')['selection'], 'headline'))
        ->toBe(['Soonest', 'Middle', 'Furthest']);
});

it('does not duplicate an event carried by two sources', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $sourceA = eventsSource($pro->id, eventsConnection($pro->id));
    $sourceB = eventsSource($pro->id, eventsConnection($pro->id));

    $itemId = eventItem($pro->id, $sourceA, 'Double-listed', now()->addDays(5)->toDateTimeString());
    DB::table('content.f_occurrence')->insert([
        'item_id' => $itemId, 'source_id' => $sourceB,
        'starts_at_local' => now()->addDays(5)->toDateTimeString(),
        'starts_at_utc' => now()->addDays(5)->toDateTimeString(),
        'zone_confidence' => 'offset_only', 'is_all_day' => 0,
        'updated_at' => now(),
    ]);

    expect(app(PoolResolver::class)->resolve($site, 'events')['selection'])->toHaveCount(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Content/EventsPoolTest.php`

Expected: FAIL on `PoolRegistry::isPool('events')`.

- [ ] **Step 3: Add the registry entry and the section shape**

In `app/Site/Pools/PoolRegistry.php`, replace the four constants:

```php
    public const POOLS = [
        'watch' => ['video'],
        'listen' => ['track', 'release', 'episode'],
        'media' => ['media'],
        'events' => ['event'],
    ];

    /**
     * Pools whose selection carries the single Latest tag (owner). Events is
     * absent on purpose: a dated list is already ordered by when it happens,
     * so a Latest badge would label the soonest event "new".
     */
    public const LATEST_TAG_POOLS = ['watch', 'listen', 'media'];

    /** The page each pool's section lives on (site.pages.key). */
    public const PAGE_KEYS = [
        'watch' => 'watch',
        'listen' => 'listen',
        'media' => 'gallery',
        'events' => 'events',
    ];

    public const PAGE_LABELS = [
        'watch' => 'Watch',
        'listen' => 'Listen',
        'media' => 'Gallery',
        'events' => 'Events',
    ];

    /**
     * The rule + ordering a pool's section is provisioned with.
     *
     * Watch and Listen want "each auto-source's newest item" — one row per
     * platform, rolling. Events does not: `latest_per_auto_source` emits
     * exactly ONE item per connection source, which for a ticketing platform
     * means a visitor sees one event and never the other four. Dated content
     * wants the whole upcoming list, soonest first.
     *
     * A pool with no entry here gets the watch/listen default, so adding a
     * pool stays a one-line change unless its semantics genuinely differ.
     *
     * @var array<string, array{rule: list<array{op: string}>, order_by: string}>
     */
    public const SECTION_SHAPE = [
        'events' => [
            'rule' => [
                ['op' => 'kind_is'],
                ['op' => 'upcoming_occurrence'],
            ],
            'order_by' => 'occurrence',
        ],
    ];
```

Add after `carriesLatestTag()`:

```php
    /**
     * The rule predicates and ordering to provision a pool's section with.
     * `values` is filled from the pool's kinds here so SECTION_SHAPE stays a
     * declaration of SHAPE and never restates the kind list.
     *
     * @return array{rule: list<array<string, mixed>>, order_by: string}
     */
    public static function sectionShape(string $pool): array
    {
        $shape = self::SECTION_SHAPE[$pool] ?? [
            'rule' => [
                ['op' => 'kind_is'],
                ['op' => 'latest_per_auto_source'],
            ],
            'order_by' => 'recency',
        ];

        $kinds = self::kinds($pool);

        return [
            'rule' => array_map(
                static fn (array $predicate): array => $predicate + ['values' => $kinds],
                $shape['rule'],
            ),
            'order_by' => $shape['order_by'],
        ];
    }
```

- [ ] **Step 4: Read the shape in the provisioner**

In `app/Site/Pools/PoolSectionProvisioner.php`, add after `$pageId = $this->ensurePage($site, $pool);`:

```php
        $shape = PoolRegistry::sectionShape($pool);
```

and replace lines 50-55 (the `rule` / `mode` / `order_by` entries of the insert) with:

```php
                // The pool contract: pins are the hand-picks, the rule is the
                // auto half, excludes are removals. Mixed mode is exactly that
                // composition. WHICH rule is the pool's own business — see
                // PoolRegistry::sectionShape().
                'rule' => json_encode(['all' => $shape['rule']]),
                'mode' => 'mixed',
                'order_by' => $shape['order_by'],
```

- [ ] **Step 5: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Content/EventsPoolTest.php tests/Unit/Site/Pools/PoolRegistryTest.php tests/Feature/Content/PoolLaneTest.php`

Expected: PASS. `PoolLaneTest` must stay green — if watch/listen behaviour changed, `sectionShape()`'s default arm does not reproduce the old hardcoded rule. Fix that, do not adjust the test.

- [ ] **Step 6: Commit**

```bash
git add app/Site/Pools/PoolRegistry.php app/Site/Pools/PoolSectionProvisioner.php tests/Feature/Content/EventsPoolTest.php
git commit -m "feat(pools): register the events pool with its own section shape

latest_per_auto_source emits one item per connection source, which is the
product for Watch/Listen and wrong for a dated list. PoolRegistry now
declares rule + ordering per pool; the provisioner reads it."
```

---

### Task 5: Retire event items whose every source item is gone

Three Eventbrite events on dev carry `source_items.removed_at` while their `content.items.removed_at` is NULL. The connector dropped them; the item stayed live.

**Decision: an item whose every source item is retired is itself retired**, by setting `content.items.removed_at`.

**This is one-way. Say so, do not call it reversible.** Revision 1 did. `ProjectionWriter:272-275` clears `source_items.removed_at` on reappearance but explicitly never touches `content.items.removed_at`; `bindGroup():503-535` re-binds a reappearing coord to the same still-retired item with no `removed_at` filter; and `ItemController:14-18` states the contract outright — *"removed_at is THE user-delete mechanism and is never cleared by reappearance"*. If Eventbrite re-lists an event, it does not come back, and the only undo is `UPDATE content.items SET removed_at = NULL` by hand.

That is acceptable here because for a *synced* item with no live source, "the user deleted it" and "the platform dropped it" want the same outcome. It is not acceptable silently, hence this paragraph.

Do **not** write `source_items.removed_at`. Spec §7 slice 3 is explicit: it is cleared on reappearance, so writing it by hand would resurrect a user-deleted row.

**Verified side effects on dev: none.** The 3 items have 0 `section_items` rows, 0 `manual_overrides`, 0 `analytics.item_views`, 0 `content.item_slugs`. `site.item_slugs` is untouched by this task.

**Files:**
- Modify: `app/Console/Commands/ContentRepairEventItemsCommand.php`
- Test: `tests/Feature/Content/EventItemRepairTest.php`

**Interfaces:**
- Consumes: `orphanedQuery()` from Task 2.
- Produces: `content:repair-event-items --retire`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Content/EventItemRepairTest.php`:

```php
it('retires an event item whose every source item is removed', function () {
    $pro = createTenant('repair-'.Str::lower(Str::random(6)));
    $sourceId = repairSource($pro->id);

    $gone = (string) Str::uuid();
    $kept = (string) Str::uuid();
    foreach ([$gone => now()->subDay(), $kept => null] as $itemId => $removedAt) {
        DB::table('content.items')->insert([
            'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'event',
            'headline_cache' => 'Workshop', 'facets_cache' => '[]', 'eligible_cache' => '[]',
            'first_seen_at' => now(), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $sourceId,
            'coord' => 'eventbrite:acct-test:'.$itemId, 'item_id' => $itemId,
            'kind' => 'event', 'removed_at' => $removedAt,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        DB::table('content.f_text')->insert([
            'item_id' => $itemId, 'source_id' => $sourceId,
            'headline' => 'Workshop', 'updated_at' => now(),
        ]);
    }

    $this->artisan('content:repair-event-items --retire')->assertExitCode(0);

    expect(DB::table('content.items')->where('id', $gone)->value('removed_at'))->not->toBeNull();
    expect(DB::table('content.items')->where('id', $kept)->value('removed_at'))->toBeNull();

    // The source item's own marker is untouched — it is cleared on
    // reappearance, and rewriting it would resurrect a user-deleted row.
    expect(DB::table('content.source_items')->where('item_id', $gone)->value('removed_at'))->not->toBeNull();
});

it('leaves an orphaned item alone without --retire', function () {
    $pro = createTenant('repair-'.Str::lower(Str::random(6)));
    $sourceId = repairSource($pro->id);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'event',
        'headline_cache' => 'Workshop', 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'eventbrite:acct-test:orphan', 'item_id' => $itemId,
        'kind' => 'event', 'removed_at' => now()->subDay(),
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => 'Workshop', 'updated_at' => now(),
    ]);

    $this->artisan('content:repair-event-items')->assertExitCode(0);

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Content/EventItemRepairTest.php`

Expected: FAIL — `The "--retire" option does not exist.`

- [ ] **Step 3: Add the retire flag**

Extend the signature with `{--retire : Set removed_at on items whose every source item is retired. ONE-WAY — see the class docblock}` and add these imports:

```php
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Site\Documents\BuildState;
```

Insert in `handle()`, before the `return self::SUCCESS`:

```php
        if ($this->option('retire') && $orphaned->isNotEmpty()) {
            // Raw write — no Eloquent, so no observer fires. Three things must
            // happen by hand, and nothing in CI will catch a missing one:
            //
            //  1. content.items.removed_at         (the retirement itself)
            //  2. site.sites.updated_at            (bumped via touch below) —
            //     IndividualProfilePayloadBuilder::cacheKey() is keyed on it,
            //     and BuildState::bump() does NOT move it, so without this the
            //     60s Redis payload cache keeps serving the retired event.
            //  3. the Cloudflare edge purge — same reason PoolController::
            //     poolChanged() does it: the CDN outlives a pool edit.
            DB::connection('pgsql')->table('content.items')
                ->whereIn('id', $orphaned->pluck('id')->all())
                ->update(['removed_at' => now(), 'updated_at' => now()]);

            $sites = DB::connection('pgsql')->table('site.sites')
                ->whereIn('user_id', $orphaned->pluck('user_id')->unique()->all())
                ->get(['id', 'subdomain']);

            foreach ($sites as $site) {
                BuildState::bump((string) $site->id);
                DB::connection('pgsql')->table('site.sites')
                    ->where('id', $site->id)->update(['updated_at' => now()]);
                if (($site->subdomain ?? '') !== '') {
                    CloudflareCachePurgeJob::dispatch($site->subdomain);
                }
            }

            $this->info('Retired '.$orphaned->count().' orphaned event item(s). This is not reversible by re-sync.');
        }
```

- [ ] **Step 4: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Content/EventItemRepairTest.php`

Expected: PASS (4 tests). `Queue::fake()` is not set in this file — add it to `beforeEach` if the purge job dispatch causes a connection error.

- [ ] **Step 5: Assert against dev**

```bash
php artisan content:repair-event-items --dry-run   # expect orphaned: 3
php artisan content:repair-event-items --retire
```

Paste:

```sql
SELECT count(*) FILTER (WHERE i.removed_at IS NULL) AS live,
       count(*) FILTER (WHERE i.removed_at IS NOT NULL) AS retired
FROM content.items i WHERE i.kind = 'event';
```

Expected: `retired = 3`.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/ContentRepairEventItemsCommand.php tests/Feature/Content/EventItemRepairTest.php
git commit -m "feat(content): retire event items whose every source item is gone

Settles spec 9.8's retirement asymmetry for events. ONE-WAY: items.removed_at
is never cleared by reappearance (ProjectionWriter:272-275), so a re-listed
event does not return. source_items.removed_at is never rewritten — it IS
cleared on reappearance and would resurrect a user-deleted row."
```

---

### Task 6: The events link roster

`ItemLinkRules::ROSTER` has no `events` key, so `rosterFor('events')` returns `[]` and every hand-saved link 422s.

**Files:**
- Modify: `app/Site/Pools/ItemLinkRules.php:22-45`
- Test: `tests/Feature/Content/EventsPoolTest.php`

**Interfaces:**
- Produces: `ItemLinkRules::ROSTER['events'] = ['eventbrite', 'humanitix']` with matching `HOSTS`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Content/EventsPoolTest.php`:

```php
it('rosters the ticketing platforms for events and refuses the rest', function () {
    expect(App\Site\Pools\ItemLinkRules::rosterFor('events'))->toBe(['eventbrite', 'humanitix']);
    expect(App\Site\Pools\ItemLinkRules::allowsPlatform('events', 'spotify'))->toBeFalse();
    // Real dev URL shapes: www-prefixed eventbrite, subdomained humanitix.
    expect(App\Site\Pools\ItemLinkRules::urlBelongsTo('eventbrite', 'https://www.eventbrite.com/e/x-tickets-1993572537124'))->toBeTrue();
    expect(App\Site\Pools\ItemLinkRules::urlBelongsTo('humanitix', 'https://events.humanitix.com/26-rotary-disco'))->toBeTrue();
    expect(App\Site\Pools\ItemLinkRules::urlBelongsTo('eventbrite', 'https://example.com/e/x'))->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Content/EventsPoolTest.php --filter="rosters the ticketing"`

Expected: FAIL — `rosterFor('events')` returns `[]`.

- [ ] **Step 3: Add the roster**

In `app/Site/Pools/ItemLinkRules.php`, add to `ROSTER`:

```php
        'events' => ['eventbrite', 'humanitix'],
```

and to `HOSTS`:

```php
        'eventbrite' => ['eventbrite.com', 'eventbrite.com.au'],
        'humanitix' => ['humanitix.com'],
```

`urlBelongsTo()` strips `www.` then matches exact host or `.`-suffix, so `www.eventbrite.com` → `eventbrite.com` exact, and `events.humanitix.com` → `.humanitix.com` suffix.

- [ ] **Step 4: Run and commit**

Run: `./vendor/bin/pest tests/Feature/Content/EventsPoolTest.php` — expect PASS.

```bash
git add app/Site/Pools/ItemLinkRules.php tests/Feature/Content/EventsPoolTest.php
git commit -m "feat(pools): events link roster"
```

---

### Task 7: Event fields on the pool item payload

`PoolResolver::itemPayloads()` returns a closed shape. An event carries a start time, a venue and a price, and none can reach the wire.

**Shape decision: flat, nullable keys on every pool item, not a nested `event` object.** `durationSeconds` is already null for a release; this follows the idiom and keeps the payload shape stable across pools.

**Aggregate in SQL, do not rely on `keyBy()` ordering.** Revision 1 claimed `keyBy()` keeps the first row. It does not — it overwrites, so the *last* row wins, which would have shipped the furthest date and the most expensive tier. Use the `selectRaw(...)->groupBy(...)` idiom already used two blocks up for `$published` and `$durations` (`PoolResolver:227-239`).

**Files:**
- Modify: `app/Site/Pools/PoolResolver.php:181-295`
- Test: `tests/Feature/Content/EventsPoolTest.php`

**Interfaces:**
- Produces: eight new keys on every pool item — `startsAt`, `startsAtLocal`, `endsAtLocal`, `timezone`, `venue`, `locality`, `price`, `availability`. `price` is `null` or `{amountMinor, amountMaxMinor, currency, qualifier}`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Content/EventsPoolTest.php`. Note the offers insert uses `updated_at` — `content.offers` has **no** `created_at` column.

```php
it('serves the soonest occurrence and the cheapest offer on an event payload', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $sourceA = eventsSource($pro->id, eventsConnection($pro->id));
    $sourceB = eventsSource($pro->id, eventsConnection($pro->id));

    $itemId = eventItem($pro->id, $sourceA, 'Beginner sewing workshop', now()->addDays(5)->toDateTimeString());

    // A SECOND source dates the same event later, and prices it higher. The
    // section orders by MIN(starts_at_utc), so the payload must agree — a
    // last-row-wins map would print the later date beside the earlier order.
    DB::table('content.f_occurrence')->insert([
        'item_id' => $itemId, 'source_id' => $sourceB,
        'starts_at_local' => now()->addDays(9)->toDateTimeString(),
        'starts_at_utc' => now()->addDays(9)->toDateTimeString(),
        'zone_confidence' => 'offset_only', 'is_all_day' => 0, 'updated_at' => now(),
    ]);
    DB::table('content.f_place')->insert([
        'item_id' => $itemId, 'source_id' => $sourceA,
        'venue_name' => 'Reginald Murphy Community Centre',
        'locality' => 'Potts Point', 'updated_at' => now(),
    ]);
    DB::table('content.offers')->insert([
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceA,
         'amount_minor' => 661, 'currency' => 'AUD', 'qualifier' => 'from',
         'availability' => 'sold_out', 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceB,
         'amount_minor' => 4500, 'currency' => 'AUD', 'qualifier' => 'from',
         'availability' => 'available', 'updated_at' => now()],
    ]);

    $item = app(PoolResolver::class)->resolve($site, 'events')['selection'][0];

    expect($item['venue'])->toBe('Reginald Murphy Community Centre');
    expect($item['locality'])->toBe('Potts Point');
    expect($item['startsAt'])->toStartWith(now()->addDays(5)->format('Y-m-d'));
    expect($item['price']['amountMinor'])->toBe(661);
    expect($item['price']['currency'])->toBe('AUD');
    expect($item['price']['qualifier'])->toBe('from');
});

it('keeps the event keys present and null on a non-event pool item', function () {
    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    $id = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $id, 'user_id' => $pro->id, 'kind' => 'video',
        'headline_cache' => 'A clip', 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $source,
        'coord' => 'youtube:acct-test:clip', 'item_id' => $id, 'kind' => 'video',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $item = app(PoolResolver::class)->resolve($site, 'watch')['selection'][0];

    // Stable shape across pools — the FE never branches on kind to find a key.
    foreach (['startsAt', 'startsAtLocal', 'endsAtLocal', 'timezone', 'venue', 'locality', 'price', 'availability'] as $key) {
        expect($item)->toHaveKey($key);
        expect($item[$key])->toBeNull();
    }
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Content/EventsPoolTest.php --filter="soonest occurrence|present and null"`

Expected: FAIL — the keys do not exist.

- [ ] **Step 3: Add the three facet reads**

In `PoolResolver::itemPayloads()`, alongside the existing `$published` / `$durations` block. One query per facet — never one per item.

```php
        // Soonest occurrence per item, aggregated in SQL. A collection keyed
        // by item_id would be LAST-row-wins, which is the opposite of what
        // the section's MIN(starts_at_utc) ordering does.
        $occursAt = DB::connection('pgsql')->table('content.f_occurrence')
            ->whereIn('item_id', $ids)
            ->whereNotNull('starts_at_utc')
            ->selectRaw('item_id, MIN(starts_at_utc) as starts_at_utc')
            ->groupBy('item_id')
            ->pluck('starts_at_utc', 'item_id');

        // The local/venue detail belongs to whichever source supplied the
        // soonest time; ordering DESC and letting keyBy overwrite leaves the
        // EARLIEST row in the map.
        $occurrenceDetail = DB::connection('pgsql')->table('content.f_occurrence')
            ->whereIn('item_id', $ids)
            ->whereNotNull('starts_at_utc')
            ->orderByDesc('starts_at_utc')
            ->get(['item_id', 'starts_at_local', 'ends_at_local', 'timezone'])
            ->keyBy('item_id');

        $places = DB::connection('pgsql')->table('content.f_place')
            ->whereIn('item_id', $ids)
            ->get(['item_id', 'venue_name', 'locality'])
            ->keyBy('item_id');

        // Cheapest offer per item — the scrape sees the lowest tier and the
        // projector stamps qualifier='from' to say so. Ordered DESC so the
        // cheapest row is written LAST and survives keyBy's overwrite.
        $offers = DB::connection('pgsql')->table('content.offers')
            ->whereIn('item_id', $ids)
            ->orderByRaw('amount_minor IS NULL DESC, amount_minor DESC')
            ->get(['item_id', 'amount_minor', 'amount_max_minor', 'currency', 'qualifier', 'availability'])
            ->keyBy('item_id');
```

Then extend the payload array, immediately after `'thumbnail' => ...`:

```php
                // Dated / located / priced facets. Present on every pool item
                // and null off events, so the wire shape does not change with
                // kind — same contract durationSeconds already has.
                'startsAt' => $occursAt[$itemId] ?? null,
                'startsAtLocal' => $occurrenceDetail[$itemId]->starts_at_local ?? null,
                'endsAtLocal' => $occurrenceDetail[$itemId]->ends_at_local ?? null,
                'timezone' => $occurrenceDetail[$itemId]->timezone ?? null,
                'venue' => $places[$itemId]->venue_name ?? null,
                'locality' => $places[$itemId]->locality ?? null,
                'price' => isset($offers[$itemId]) ? [
                    'amountMinor' => $offers[$itemId]->amount_minor === null ? null : (int) $offers[$itemId]->amount_minor,
                    'amountMaxMinor' => $offers[$itemId]->amount_max_minor === null ? null : (int) $offers[$itemId]->amount_max_minor,
                    'currency' => $offers[$itemId]->currency,
                    'qualifier' => $offers[$itemId]->qualifier,
                ] : null,
                'availability' => $offers[$itemId]->availability ?? null,
```

- [ ] **Step 4: Run and commit**

Run: `./vendor/bin/pest tests/Feature/Content/EventsPoolTest.php tests/Feature/Content/PoolLaneTest.php` — expect PASS. `PoolLaneTest` must stay green; the new keys are additive.

```bash
git add app/Site/Pools/PoolResolver.php tests/Feature/Content/EventsPoolTest.php
git commit -m "feat(pools): occurrence, place and price on the pool item payload

Aggregated in SQL, not via keyBy — keyBy overwrites, so a collection keyed by
item_id holds the LAST row, which for a two-source event is the furthest date
and the dearest tier."
```

---

### Task 8: Presence gating and the public wire

`IndividualProfilePayloadBuilder::buildPools()` iterates `array_keys(PoolRegistry::POOLS)` (`:315`), so `events` reaches `profile.pools.events` with no change — verified, but assert it rather than assume. Presence does need a change.

**Files:**
- Modify: `app/Services/PublicSite/SitepageDataResolverService.php:322-334`
- Create: `docs/wire-changes/2026-08-11-slice2-events-pool.md`
- Test: `tests/Feature/Content/EventsPoolTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Content/EventsPoolTest.php`:

```php
it('ships the events pool selection on the public payload', function () {
    setupMediaTables();
    setupContentSelectionTable();
    setupBlocksTable();
    setupServicesTable();
    setupDesignKitsTable();

    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    eventItem($pro->id, $source, 'Ultimo clothes swap', now()->addDays(6)->toDateTimeString());
    eventItem($pro->id, $source, 'Last year', now()->subDays(300)->toDateTimeString());

    $payload = app(App\Services\PublicSite\IndividualProfilePayloadBuilder::class)
        ->build($pro->fresh(), $site);

    // The resource casts pools to an object so an empty map serializes {}.
    $events = $payload['profile']['pools']->events ?? null;

    expect($events)->not->toBeNull();
    expect(array_column($events['items'], 'headline'))->toBe(['Ultimo clothes swap']);
    expect($events['latestItemId'])->toBeNull();
    expect($events['items'][0])->not->toHaveKey('selected');
    expect($events['items'][0]['startsAt'])->not->toBeNull();
});

it('grants the events page presence from a non-empty pool selection', function () {
    setupMediaTables();
    setupBlocksTable();
    setupServicesTable();

    $pro = createTenant('evpool-'.Str::lower(Str::random(6)));
    $site = Site::query()->where('user_id', $pro->id)->firstOrFail();
    $source = eventsSource($pro->id, eventsConnection($pro->id));

    eventItem($pro->id, $source, 'Grant writing', now()->addDays(9)->toDateTimeString());

    $caps = App\Services\Accounts\AccountCapabilities::for($pro->fresh());
    $present = app(App\Services\PublicSite\SitepageDataResolverService::class)
        ->presentPageIds($site, $caps, collect());

    expect($present)->toContain('events');
});
```

- [ ] **Step 2: Run to verify**

Run: `./vendor/bin/pest tests/Feature/Content/EventsPoolTest.php --filter="public payload|page presence"`

Expected: the payload test may already PASS (`buildPools()` is registry-driven). The presence test FAILS. If the payload test passes, note it in the commit as evidence the registry-driven wire works — not as a reason to skip it.

- [ ] **Step 3: Add events to the presence-via-pools loop**

`SitepageDataResolverService.php:322`:

```php
            foreach (['watch' => 'watch', 'listen' => 'listen', 'events' => 'events'] as $pool => $poolPage) {
```

Leave the surrounding logic intact: pools **add** presence and never veto it, so an Eventbrite connection with no upcoming events keeps the Events page it has today. Flipping that to a veto belongs in Task 9.

- [ ] **Step 4: Write the wire manifest**

Create `docs/wire-changes/2026-08-11-slice2-events-pool.md`:

```markdown
# Wire change — slice 2, events pool (2026-08-11)

Backend-only execution; the frontends are told, not designed around
(spec `2026-08-11-content-pool-convergence-design.md`, owner decision).

## `GET /api/public/profiles/{handle}`

**Consuming repo:** partna-monorepo (`@partnaau/design-system`), Partna-App.

### New: `profile.pools.events`

Before — key absent. After:

    "pools": {
      "events": { "items": [ { ...poolItem } ], "latestItemId": null }
    }

Same envelope as `pools.watch` / `pools.listen`. `latestItemId` is always
null for events: a dated list is already ordered by when it happens, so a
Latest badge on the soonest event would read as "new".

Ordering: **pins first, in the owner's drag order, then upcoming events
soonest-first.** Pins are not date-ordered — `PoolResolver::resolve()` builds
`[...$pinned, ...$ruleIds]`. A hand-added event (which `PoolItemCreateController`
pins and which carries no `f_occurrence`) therefore sits first with
`startsAt: null`. Render accordingly.

An event with no start date is never auto-selected but can be pinned.

### Changed: every `poolItem` gains eight keys

Additive and nullable on every pool, not just events — same contract
`durationSeconds` already has. No existing key changed or removed.

| Key | Type | Null when |
|---|---|---|
| `startsAt` | ISO 8601 UTC \| null | no `f_occurrence` |
| `startsAtLocal` | string \| null | no `f_occurrence` |
| `endsAtLocal` | string \| null | no end date |
| `timezone` | IANA string \| null | zone unknowable from the offset (usual for scraped events) |
| `venue` | string \| null | no `f_place` |
| `locality` | string \| null | no `f_place` |
| `price` | `{amountMinor, amountMaxMinor, currency, qualifier}` \| null | no offer |
| `availability` | string \| null | no offer |

Where several sources describe one event, `startsAt` is the **soonest** and
`price` the **cheapest** — matching the section's ordering.

`price.qualifier` ∈ `exact｜from｜upto｜range｜free｜variable｜on_request`.
Scraped events land `from`, or `free` at zero: the scrape sees the lowest
tier of a multi-tier offer set and `from` is the only honest reading.

### Page presence

`page_order` may now include `events` for a user with a non-empty events pool
selection and no active ticketing connection — e.g. hand-added events. Pools
add presence and never remove it, so no user loses the Events page.

## Not changed in this slice

The legacy events lane on `GET /api/public/integrations/{handle}`
(`eventbrite` / `humanitix` payload keys, `hiddenEventIds` curation) is
untouched. Until it is retired, an Eventbrite-connected user's events appear
in **both** surfaces — consumers should pick one. See slice 2 Task 9.
```

- [ ] **Step 5: Assert against dev**

Deploy the branch to dev, then:

```bash
curl -s https://dev-api.partna.au/api/public/profiles/<handle-of-user-019f936e> \
  | jq '.data.pools.events.items | map({headline, startsAt, venue, price})'
```

Expected: 5 upcoming Eventbrite events, soonest first, with start times. Paste the output.

```sql
SELECT count(*) FROM site.sections WHERE key = 'pool:events';
SELECT count(*) FROM site.pages WHERE key = 'events';
```

Sections provision on demand at first read, so both are non-zero after the curl. Paste.

- [ ] **Step 6: Scan the logs and commit**

Run: `cloud env:logs partna development --minutes 10` — expect clean.

```bash
git add app/Services/PublicSite/SitepageDataResolverService.php docs/wire-changes/2026-08-11-slice2-events-pool.md tests/Feature/Content/EventsPoolTest.php
git commit -m "feat(pools): events pool presence and public wire

buildPools() is registry-driven so the payload key needed no change; the
presence gate did. Pools add presence and never veto it."
```

---

### Task 9: Retire the legacy events lane — SEPARABLE

**Read this before starting.** Everything above ships without this. This task changes an existing rendered public surface and touches the 301 permalink lane. If in doubt, stop after Task 8, record that the spec's "legacy events lane has no readers" criterion is **not** met, and carry this into slice 2b.

**The slug mapping does not work the way you would guess, and cannot be total.** `site.item_slugs` holds 13 current `item_type='event'` rows; `content.item_slugs` holds 0. The tables key differently: `site.item_slugs` is `(item_type, item_key)` where `item_key` is a **16-char hex id** minted from the connection payload (`EventSlugSync::syncEvents():126` → `ItemSlugAllocator::ensureCurrent($userId, TYPE_EVENT, $event['id'], …)` where `$event['id']` is `platform_connections.payload.upcoming[*].id`); `content.item_slugs` is keyed `item_id`. A coord-suffix join returns **13 nulls** — verified. And at least 3 of the 13 (`nerve-melbourne-2026`, `hobart-mens-hair-workshop-…`, `inclusive-aquatics-…`) have **no `content.items` row at all** — `nerve-melbourne-2026` belongs to a standalone `resource_kind='event'` connection that lands no ingest records.

- [ ] **Step 1: Map legacy slugs to content items via the URL**

Both sides carry the event URL: `payload.upcoming[*].link` and `content.f_link.url`. Verified viable — the payload link `https://www.eventbrite.com/e/connect-sydney-grant-writing-workshop-tickets-1993572537124` is present verbatim in `content.f_link.url`.

Write a read-only query that expands `platform_connections.payload->'upcoming'` with `jsonb_array_elements`, joins `->>'id'` to `site.item_slugs.item_key` and `->>'link'` to `content.f_link.url`, and reports each of the 13 slugs as mapped or unmapped. Paste the output into the checkpoint.

**Do not gate on totality.** Standalone-event connections legitimately have no content item and always will. Instead, produce an explicit disposition per unmapped slug: keep the legacy row serving its permalink, or accept the 404 with the owner's agreement. Record the choice.

- [ ] **Step 2: Backfill `content.item_slugs`**

Write the backfiller under `app/Services/Migration/` per spec §3 invariant 4 — production code, tested, idempotent, re-runnable, artisan-driven with `--dry-run`. Preserve `is_current` and `retired_at` so retired slugs keep 301-ing. Bump `BuildState` and touch `site.sites.updated_at` (the payload cache key) as in Task 5.

- [ ] **Step 3: Migrate `hiddenEventIds` into section excludes**

Each connection's `payload.hiddenEventIds` is the owner's existing curation. Map each hidden id to its `content.items.id` via the Step 1 URL join, then write `site.section_items` rows with `state = 'excluded'` on the site's `pool:events` section. Without this, retiring the legacy lane un-hides every event an owner hid.

Dev shows `hidden = 0` on every connection, so this is a **no-op on dev and therefore unverifiable there**. Test against fabricated data and say so in the checkpoint rather than claiming a live assertion you do not have.

- [ ] **Step 4: Drop the event keys from the public integrations wire**

Remove `eventbrite` and `humanitix` from `PublicIntegrationConnectionResource::ALLOWLIST` (`:95-96`). Check `annotateEventSlugs()` in `PublicIntegrationController` and remove the dead branch. Leave `DsarPayloadFilter`'s entries alone — the 2026-08-05 precedent is that DSAR allowlists retain legacy keys so previously-stored payloads stay disclosable (spec §9.5).

- [ ] **Step 5: Flip presence from connection-derived to pool-derived**

Remove `'eventbrite'`, `'humanitix'` and `'events-custom'` from `SitepageDataResolverService::PLATFORM_TO_PAGE` (`:87`) so the pool is the only grantor. Add a test proving a connected user with no upcoming events gets no Events page.

- [ ] **Step 6: Decide the dashboard lane**

`EventsPlatformController` and its `hiddenEventIds` verb are dashboard state for a lane that no longer renders. Either repoint the dashboard at `/api/content/pools/events` or leave it and document it as vestigial. **This is a Partna-Frontend decision** — raise it with the owner rather than deciding it here.

- [ ] **Step 7: Extend the wire manifest**

Append a "Removed" section covering the dropped `eventbrite`/`humanitix` keys and the presence change; delete the "Not changed in this slice" paragraph.

- [ ] **Step 8: Assert, log-scan, commit**

```sql
SELECT count(*) FROM content.item_slugs;   -- expect the mapped subset from Step 1
SELECT count(*) FROM site.item_slugs WHERE item_type = 'event' AND is_current;  -- still 13
```

`site.item_slugs` is **not** dropped here — that is slice 7's teardown. Run `cloud env:logs partna development --minutes 10` and paste.

---

## Slice checkpoint

Append to the spec per §10. Required:

1. **SQL run against dev, output pasted** — the event-health query (Task 2 Step 5), retirement counts (Task 5 Step 5), section/page counts (Task 8 Step 5), slug mapping (Task 9 Step 1) if it ran.
2. **Pest test names** proving connector → projector → item → pool → wire. Name them; do not summarise.
3. **`cloud env:logs partna development --minutes 10` scan result.**
4. **What was deferred and why** — `channel` and `article` per "Scope decisions"; Task 9 if it did not run, stated as an explicit failure of the spec's own done-criterion rather than a tick.

Do not tick the spec's slice-2 checkbox unless Task 9 ran. A ticked box means "resolved as an open question", and "we shipped half and did not say so" is not a resolution.

---

## Self-review

**Spec coverage.** §7 asks for "`PoolRegistry` entries, `PAGE_KEYS`, `PAGE_LABELS`, section provisioning for existing users, and read paths for `event`, `channel`, `article`."

- Registry entry, `PAGE_KEYS`, `PAGE_LABELS` → Task 4. ✅
- Section provisioning for existing users → Task 4; on-demand at first read, so no backfill command. Verified in Task 8 Step 5. ✅
- Read paths → Tasks 3, 6, 7, 8. ✅
- `channel`, `article` → **not covered**, deliberately. Guarded by Task 1. ⚠️
- "the 22 existing rows are reachable and rendered" → **partially**: 11 of 14 event rows (3 retired as genuinely dead); the 8 channel/article rows deferred. ⚠️
- "the legacy events lane has no readers" → Task 9 only. ⚠️

**Invariants.** #1 live DB assertion: Tasks 2, 5, 8, 9 carry SQL with expected output. #2 kind-not-adopted-until-read: projector, registry entry and read path all land here. #3 legacy deletion last: Task 9 drops no table. #4 backfill is production code: Task 9 Step 2. #6 registration≠execution: Task 2 proves projection runs before anything downstream is claimed.

**Type consistency.** `sectionShape()` returns `{rule, order_by}`; the provisioner reads exactly those. `'upcoming_occurrence'` is spelled identically in `RuleOperator`, `SECTION_SHAPE`, `EXECUTED_OPERATORS`, the `match` arm and the tests. `'occurrence'` matches across `SECTION_SHAPE`, `ruleCandidates()`, `SectionRuleRules::ORDER_BY` and the tests. The eight payload keys in Task 7 match the manifest table one for one.

**Risks named, not designed away.**
- Task 5's retirement is **one-way**. Stated in the task, the command output and the commit message.
- Task 9 Step 3 is **unverifiable on dev** (zero `hiddenEventIds`). Stated.
- Task 9's slug mapping **cannot be total**. Stated, with a disposition step instead of a false gate.
- There is **no `BuildState` raw-write CI check**, contrary to spec §9.1. Every raw write in this plan names its bump, its `site.sites.updated_at` touch and its edge purge by hand because nothing will catch a missing one.
- Task 3's `orderByRaw` must be exercised on Postgres, not just the SQLite lane.
