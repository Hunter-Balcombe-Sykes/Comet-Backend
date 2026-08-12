# Slice 0b — Manual-source write lane · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give owner-authored content a real write lane — one that lands items through the same `ProjectionWriter` spine a connector uses, so a later connector run enriches those items instead of stranding or destroying them.

**Architecture:** `ProjectionWriter` gains two public methods — `ensureManualSource()` and `writeManualItem()` — that reuse its existing private helpers (`upsertSourceItem`, `writeIdentityKeys`, `resolveItems`, `writeFacets`, `refreshItemCaches`, `bumpSite`) rather than duplicating them. `bindGroup()` learns that an owner-authored item outranks a scraped one when a merge picks a survivor. `PoolItemCreateController` — today's hand-rolled manual writer — is repointed onto the lane and its bespoke SQL deleted.

**Tech Stack:** PHP 8.4, Laravel 12, PostgreSQL (Supabase), Pest 4. No new dependencies. **No schema migration** — `content.sources` has admitted `kind='manual'` since `20260727140000_content_schema.sql:29`, and every table this slice writes already exists.

**Source spec:** `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` §6 (slice 0b), §3 (invariants), §8.1, §8.3, §9, §10.

> **This slice carries one live, user-visible behaviour change.** `POST /api/content/pools/{pool}/items` stops being "always creates a row". Task 6 writes the manifest; **the Partna-App team must be told before merge, not after.**

**Revision note.** This is revision 2. Revision 1 was reviewed independently against the codebase and three of its tasks were wrong. The `Resolver` poisoning behaviour in Task 4 was proved by running the pure resolver directly, not by reading it — see that task.

---

## Correction to the spec, established before planning

The spec's §1.7 states the manual lane "does not exist" and that "nothing has ever written a row" with `kind='manual'`. **That is wrong, and the real situation is worse.**

`app/Http/Controllers/Api/Content/PoolItemCreateController.php` is a live, routed, tested manual-source writer (`routes/api/user.php:172`, `tests/Feature/Content/PoolLaneTest.php:365`). Dev holds 0 manual rows because the endpoint has not been exercised, not because no writer exists.

It hand-rolls the writes and omits two of them:

| Writes | Skips |
|---|---|
| `content.sources` (kind=manual), `content.items`, `content.source_items`, `content.f_text`, `content.f_link` | `content.identity_keys`, `content.item_anchors` |

The consequence is a live defect. `ProjectionWriter::resolveItems()` (`app/Ingest/Projection/ProjectionWriter.php:391-404`) unions **every** live `source_item` for `(user_id, kind)` across all sources — not just the stream being projected. A hand-added source item carries zero identity keys, so `Resolver` returns it as a singleton group; `bindGroup()` (`:503-535`) finds no anchor for its `manual:{uuid}` coord, so `$effective` is empty and `createItem()` (`:537-553`) mints a **fresh, empty `content.items` row**. The re-read loop at `:472-491` then repoints the manual `source_item.item_id` off the hand-added item and onto that empty one.

Preconditions, exactly: the same user has (a) a hand-added item, and (b) any connector stream whose projector `kind()` equals that item's kind, producing at least one projection. Net effect: a blank duplicate in their library, and their hand-added item severed from its own source row. It keeps rendering only because `site.section_items` pins it by id and `PoolResolver` reads `content.items` directly.

**The priority claim is also untrue.** `content.sources`' own DDL comment says a user's manual source sits "at max priority: that is what makes 'the user outranks the machine' (C8) a data fact rather than a special case in code." The controller writes `priority => 100` (`PoolItemCreateController.php:75`) — identical to every connection. `ValueResolver::byPriority()` (`app/Content/Values/ValueResolver.php:84-89`) sorts descending on `sourcePriority` and governs `f_text.headline` and `f_link.url`; `PoolResolver::itemPayloads()` orders source links by `sources.priority DESC`. Task 1 sets manual priority to 200 **and corrects any row already written at 100**.

This does not change slice 0b's scope. It changes its justification from "build a missing lane" to "replace a broken one", and it adds Task 5.

---

## Global Constraints

- **Branch:** `feat/content-slice-0b-manual-lane`, cut from `development`. PR → merge to `development`. **Never push to `production`** — slice 0b ships to dev only until the programme's later slices are verified.
- **Never create Laravel migration files.** Schema changes live in `supabase/migrations/` as raw SQL. This slice needs none.
- **Tests run SQLite in-memory; production is Postgres.** Assertions must not depend on FK cascade behaviour — the SQLite stand-ins in `tests/Pest.php` declare no foreign keys at all.
- **SQLite index DDL puts the schema qualifier on the INDEX name, not the table** (`CREATE UNIQUE INDEX IF NOT EXISTS content.idx_foo ON sources (…)`), and every such statement in `tests/Pest.php` is wrapped in `try { … } catch (Throwable $e) {}`. See the existing examples at `tests/Pest.php:838-845` and `:2919-2930`. Getting this wrong throws for **every** test that calls the helper.
- **Timestamps bind at second precision.** Laravel's query grammar formats `DateTimeInterface` bindings as `Y-m-d H:i:s`, so two rows written in the same second tie on `bound_at`. Any test whose outcome depends on anchor age must set the ages explicitly.
- 4-space indent, LF. Comments explain WHY, not what. No banners, no restatements.
- Pest for all new tests. **Prefer `actingAsUser($user)->postJson(route(…))` over calling a controller directly** — a direct call bypasses route middleware and the exception renderer, and has hidden live bugs in this repo before.
- Pest test files share one global function namespace. **Every helper this plan adds is prefixed `manualLane`** so it cannot collide with `projectableBandcamp` / `landCurrentRecord` / `bandcampDoc` in `tests/Feature/Ingest/ProjectionWriterTest.php`.
- Do not wrap `writeManualItem()` in an outer `DB::transaction`. `ProjectionWriter::replaceCollections()` documents at `:800-803` that its own transaction is the outermost one.
- `composer test` must pass before any task is done. Run `php artisan pint` on touched files before committing. Long runs need `COMPOSER_PROCESS_TIMEOUT=0`.

---

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `app/Ingest/Projection/ProjectionWriter.php` | Modify | `ensureManualSource()`, `writeManualItem()`, `preferOwnerAnchored()`; nullable stream/record key; owner-authored merge survivor; `item_links` joins the curation check |
| `app/Http/Controllers/Api/Content/PoolItemCreateController.php` | Modify | Drops ~35 lines of bespoke SQL; delegates to the lane; URL-derived coord; collision-safe pin |
| `tests/Feature/Ingest/ManualSourceLaneTest.php` | Create | The lane at DB grain: source channel, idempotency, identity, merge survival, the poisoning constraint |
| `tests/Pest.php` | Modify | Two stand-in constraints Postgres has and SQLite was missing |
| `tests/Feature/Content/PoolLaneTest.php` | Modify | Two added HTTP-level regression cases |
| `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md` | Modify | §1.7 correction (Task 0) + slice 0b checkpoint (Task 6) |
| `docs/wire-changes/2026-08-11-slice-0b-manual-lane.md` | Create | First file in a directory that does not yet exist — it sets the convention |

---

### Task 0: Correct the spec and baseline dev, before any code

Tasks 1–5 produce commits whose entire justification is a correction the spec on disk contradicts. Land the correction first so a reviewer reading commit 1 is not reading against a spec that says the opposite. Amending a spec in place is the established convention here — `git log docs/superpowers/specs/` shows `f0decc948 docs(spec): revise pool convergence after independent review`, `9de1b94a0 docs(spec): correct the Cloudflare finding`.

**Files:**
- Modify: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`

**Interfaces:**
- Consumes: nothing. Produces: no code.

- [ ] **Step 1: Capture the dev baseline**

Run against dev (`glncumufgaqcmqhzwrxm`) via the Supabase MCP. Record the actual output in the task notes — it is the "before" half of the §3 invariant-1 assertion, and Task 1's design depends on it:

```sql
SELECT kind, count(*) AS sources, min(priority) AS min_priority, max(priority) AS max_priority
FROM content.sources GROUP BY 1 ORDER BY 1;
```

Expected per spec §1.7: one row — `connection`, 25. **If a `manual` row exists at priority 100**, Task 1's corrective UPDATE (Step 5 there) is load-bearing rather than defensive; note that in the task record.

Also baseline the orphan count Task 6 gates on, so that gate is "no increase" rather than an absolute that may already be non-zero:

```sql
SELECT count(*) AS orphan_items
FROM content.items i
WHERE i.removed_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM content.source_items si WHERE si.item_id = i.id);
```

- [ ] **Step 2: Correct §1.7 in the spec**

In `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`, change the §1.7 heading from `### 1.7 The manual lane does not exist` to `### 1.7 The manual lane exists and is broken`, and add beneath the existing `ensureContentSource()` paragraph:

```markdown
**Correction, established during slice 0b planning (2026-08-11).** Revision 2
said "nothing has ever written a row" with `kind='manual'`. That is wrong.
`PoolItemCreateController` (`routes/api/user.php:172`,
`tests/Feature/Content/PoolLaneTest.php:365`) is a live, routed manual-source
writer. Dev holds 0 manual rows because the endpoint has not been exercised,
not because no writer exists.

It is worse than absent. It hand-rolls the writes and skips
`content.identity_keys` and `content.item_anchors`, so `resolveItems()` — which
unions every live source item for `(user_id, kind)` across all sources — sees a
keyless singleton, `createItem()` mints a blank `content.items` row for it, and
the re-read loop repoints the hand-added source item onto that blank. Any user
who hand-adds to a pool and holds a connector for the same kind gets a blank
duplicate in their library and an item severed from its own source row.

The `priority` claim in the `content.sources` DDL comment ("one manual source
per user, at max priority: what makes 'the user outranks the machine' a data
fact") was also untrue — the controller wrote 100, the same as every
connection. Slice 0b sets it to 200 and corrects any row already written.

**A constraint slices 3, 4 and 5 must design around.** `Resolver::poisonedKeys()`
marks a key value poisoned when a SINGLE source contributes it twice, and there
is exactly one manual source per user. So two manual coords carrying the same
canonical URL do not merge — they poison that URL for the whole resolution run,
and any connector item carrying it stops unioning too. Verified by running the
pure resolver directly (three cases, `tests/Feature/Ingest/ManualSourceLaneTest.php`).
**A backfiller must therefore mint at most one manual coord per canonical URL
per user.** The hand-add endpoint satisfies this by deriving its coord from the
URL rather than minting a fresh UUID per request.
```

- [ ] **Step 3: Correct the false "programme is complete" claim §11 already owns**

The spec's §11 requires `docs/2026-08-05-platforms-as-sources.md`'s closing "The program is complete" to be amended, and no slice currently owns it. Do it here — it is one sentence and every later slice reads that document as a map. Replace that closing sentence with:

```markdown
**This programme is NOT complete.** The Media pool never shipped — no billed-effect
driver exists, so no `kind='media'` item has ever been written. See
`docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`, which
supersedes the scope statement here and corrects this checkpoint.
```

- [ ] **Step 4: Commit**

```bash
git checkout -b feat/content-slice-0b-manual-lane development
git add docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md docs/2026-08-05-platforms-as-sources.md
git commit -m "docs(spec): correct §1.7 — the manual lane exists and is broken

PoolItemCreateController has been writing manual sources all along,
without identity keys, without an anchor, at priority 100 instead of the
max the DDL comment promises. Records the Resolver poisoning constraint
that bounds how a backfiller may mint manual coords, and discharges §11's
outstanding amendment to the platforms-as-sources checkpoint.

Precedes the slice 0b implementation so its commits do not read against
a spec that contradicts them."
```

---

### Task 1: The manual source channel

The owner's contribution channel: one row per user, at a priority that outranks every connection.

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php` (add after `ensureContentSource()`, which ends at `:233`)
- Modify: `tests/Pest.php` (`setupContentTables()`, after the `content.sources` CREATE TABLE that begins at `:2321`)
- Test: `tests/Feature/Ingest/ManualSourceLaneTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `ProjectionWriter::MANUAL_SOURCE_PRIORITY` (int const, 200) and
  `ProjectionWriter::ensureManualSource(string $userId): string` returning the `content.sources.id`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Ingest/ManualSourceLaneTest.php`:

```php
<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 0b: owner-authored items land through the SAME writer a connector
// uses. The three things that must be true and were not before this slice:
// a manual item carries identity keys and an anchor (so a connector run
// enriches it instead of minting a blank duplicate beside it); a connector
// run can never merge it away (mergeInto()'s DELETE cascades the facet rows,
// and a manual source has no next run to rewrite them); and the manual source
// outranks every connection on value resolution.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

it('creates exactly one manual source per user, above connection priority', function () {
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;

    $writer = app(ProjectionWriter::class);
    $first = $writer->ensureManualSource($userId);
    $second = $writer->ensureManualSource($userId);

    expect($second)->toBe($first);

    $rows = DB::table('content.sources')->where('user_id', $userId)->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->kind)->toBe('manual')
        ->and($rows[0]->connection_id)->toBeNull()
        // content.sources' DDL comment calls this "max priority: what makes
        // 'the user outranks the machine' a data fact rather than a special
        // case in code". ValueResolver::byPriority() sorts DESC, so 200 is
        // what makes the owner's headline and link beat a connection's 100.
        ->and((int) $rows[0]->priority)->toBe(200);
});

it('raises a manual source left at connection priority by the old writer', function () {
    // The live controller wrote priority 100. Find-or-create alone would
    // return that row unchanged and the C8 guarantee would silently never
    // apply to anyone who had already hand-added.
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $legacyId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $legacyId, 'user_id' => $userId, 'kind' => 'manual',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(app(ProjectionWriter::class)->ensureManualSource($userId))->toBe($legacyId)
        ->and((int) DB::table('content.sources')->where('id', $legacyId)->value('priority'))->toBe(200);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Ingest/ManualSourceLaneTest.php`
Expected: FAIL — `Call to undefined method App\Ingest\Projection\ProjectionWriter::ensureManualSource()`

- [ ] **Step 3: Add the constant**

In `app/Ingest/Projection/ProjectionWriter.php`, add directly below the `URL_COLUMNS` const (which ends at `:68`):

```php
    /**
     * The owner's own channel outranks every connection. ValueResolver sorts
     * source contributions by priority DESC for f_text.headline and
     * f_link.url, so this constant is what makes "the user outranks the
     * machine" (C8) a data fact rather than a branch in code — exactly as
     * content.sources' own DDL comment claims. Connections sit at 100.
     */
    public const MANUAL_SOURCE_PRIORITY = 200;
```

- [ ] **Step 4: Add the method**

Add immediately after `ensureContentSource()` (after `:233`):

```php
    /**
     * The owner's contribution channel — one per user, find-or-create.
     *
     * Deliberately NOT folded into ensureContentSource(): that method is
     * keyed on connection_id, and a manual source has none. The uniqueness
     * that matters here is idx_content_sources_manual, a PARTIAL unique index
     * on (user_id) WHERE kind = 'manual'.
     */
    public function ensureManualSource(string $userId): string
    {
        $existing = DB::table('content.sources')
            ->where('user_id', $userId)
            ->where('kind', 'manual')
            ->first(['id', 'priority']);

        if ($existing !== null) {
            // The writer this method replaces created manual sources at 100,
            // the same as a connection. Find-or-create alone would carry that
            // forward forever and the C8 guarantee would quietly not hold for
            // anyone who had already hand-added.
            if ((int) $existing->priority !== self::MANUAL_SOURCE_PRIORITY) {
                DB::table('content.sources')->where('id', $existing->id)->update([
                    'priority' => self::MANUAL_SOURCE_PRIORITY,
                    'updated_at' => now(),
                ]);
            }

            return (string) $existing->id;
        }

        DB::table('content.sources')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'kind' => 'manual',
            'connection_id' => null,
            'label' => 'manual',
            'priority' => self::MANUAL_SOURCE_PRIORITY,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Re-read rather than assume our row landed — same reasoning as
        // resolveMediaAssets(): insertOrIgnore returns no id, and a concurrent
        // caller may have won the partial-unique race.
        $id = DB::table('content.sources')
            ->where('user_id', $userId)
            ->where('kind', 'manual')
            ->value('id');

        if ($id === null) {
            // Loud rather than a (string) cast of null to '', which would go
            // on to become the source_id on every facet row this call writes.
            throw new \RuntimeException("Could not resolve a manual content source for user {$userId}.");
        }

        return (string) $id;
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Ingest/ManualSourceLaneTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Mirror the partial unique index in the SQLite stand-in**

Postgres enforces one manual source per user; the stand-in did not, so the race the re-read exists to survive was untestable. In `tests/Pest.php`, immediately after the `content.sources` `CREATE TABLE IF NOT EXISTS` statement inside `setupContentTables()`, add — note the schema qualifier sits on the INDEX name, and the try/catch, exactly as `tests/Pest.php:838-845` does it:

```php
    // Mirrors idx_content_sources_manual (20260727140000): one manual source
    // per user. SQLite puts the schema qualifier on the INDEX name, not the
    // table — same quirk as idx_platform_connections_canonical above.
    try {
        $pg->statement('CREATE UNIQUE INDEX IF NOT EXISTS content.idx_content_sources_manual
            ON sources (user_id) WHERE kind = \'manual\'');
    } catch (Throwable $e) {
        // already exists / unsupported — ignore
    }
```

- [ ] **Step 7: Run every suite that calls the helper**

A broken statement here throws for every test that calls `setupContentTables()`, so this is the widest-blast-radius step in the plan.

Run: `./vendor/bin/pest tests/Feature/Ingest/ tests/Feature/Content/ tests/Feature/Site/`
Expected: PASS. If you see `near ".": syntax error`, the schema qualifier is on the wrong side of `ON`.

- [ ] **Step 8: Commit**

```bash
php artisan pint app/Ingest/Projection/ProjectionWriter.php
git add app/Ingest/Projection/ProjectionWriter.php tests/Pest.php tests/Feature/Ingest/ManualSourceLaneTest.php
git commit -m "feat(content): add the manual source channel to ProjectionWriter

One content.sources row per user at priority 200, so the owner's
headline and link outrank every connection's 100 through
ValueResolver::byPriority() — the data fact content.sources' own DDL
comment already claimed but the live writer never delivered. Raises any
row the old writer left at 100. Mirrors the partial unique index in the
SQLite stand-in, which admitted a duplicate Postgres rejects.

Slice 0b."
```

---

### Task 2: `writeManualItem()` — the lane itself

One owner-authored record, landed through the same spine a connector record travels.

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php` (`upsertSourceItem()` signature at `:252-259`; `writeIdentityKeys()` docblock at `:305-307`; new public method)
- Test: `tests/Feature/Ingest/ManualSourceLaneTest.php` (append)

**Interfaces:**
- Consumes: `ProjectionWriter::ensureManualSource(string $userId): string` from Task 1.
- Produces: `ProjectionWriter::writeManualItem(string $userId, string $coord, array $projection): string`.
  `$projection` takes the exact shape `Projector::project()` returns —
  `['kind' => string, 'headline' => ?string, 'facets' => array<string, array<string, mixed>>, 'media' => list<array>, 'offers' => list<array>, 'tags' => list<array>]`.
  Returns the **resolved** item id, which is not necessarily a new item.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Ingest/ManualSourceLaneTest.php`:

```php
/**
 * The projection shape a projector would return, for a hand-authored release.
 * Carries an offer and a tag as well as facets and media, because spec §6
 * names offers and item_tags in the lane's scope and a fixture that omits
 * them would let the lane ship with those paths never executed.
 */
function manualLaneRelease(string $headline, string $url): array
{
    return [
        'kind' => 'release',
        'headline' => $headline,
        'facets' => [
            'f_link' => ['url' => $url],
            'f_authored' => ['creator' => 'The Owner'],
        ],
        'media' => [['role' => 'cover', 'url' => 'https://cdn.test/'.sha1($url).'.jpg']],
        // 'from' is one of the seven values offers_qualifier_check admits
        // (20260727140000_content_schema.sql:394-395). Verified against the
        // DDL, not against a green SQLite suite — spec §10's testing note.
        'offers' => [['channel' => 'base', 'amount_minor' => 2500, 'currency' => 'AUD', 'qualifier' => 'from']],
        'tags' => [['tag' => 'owner-authored', 'tag_type' => 'origin']],
    ];
}

it('lands an owner-authored item with identity keys, an anchor, and every facet family', function () {
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;

    $itemId = app(ProjectionWriter::class)->writeManualItem(
        $userId,
        'manual:'.Str::uuid(),
        manualLaneRelease('My own album', 'https://example.test/mine'),
    );

    $sourceId = DB::table('content.sources')->where('user_id', $userId)->where('kind', 'manual')->value('id');

    $sourceItem = DB::table('content.source_items')->where('source_id', $sourceId)->first();
    expect($sourceItem)->not->toBeNull()
        ->and($sourceItem->item_id)->toBe($itemId)
        ->and($sourceItem->kind)->toBe('release')
        // No stream and no record key: there is no ingest run behind a
        // hand-authored row, and retireAbsentSourceItems() filters on a
        // concrete stream_id, so a null one can never be retired by a
        // connector pass.
        ->and($sourceItem->stream_id)->toBeNull()
        ->and($sourceItem->record_key)->toBeNull();

    // The two keys ProjectionWriter writes for any record. CanonicalUrl is
    // the joining key that lets this fold into a synced item later — its
    // absence is precisely what broke the old hand-rolled writer.
    $keys = DB::table('content.identity_keys')
        ->where('source_item_id', $sourceItem->id)
        ->pluck('key_class')->sort()->values()->all();
    expect($keys)->toBe(['canonical_url', 'platform_object']);

    expect(DB::table('content.item_anchors')
        ->where('user_id', $userId)->where('coord', $sourceItem->coord)->value('item_id'))->toBe($itemId);

    expect(DB::table('content.f_text')->where('item_id', $itemId)->where('source_id', $sourceId)->value('headline'))
        ->toBe('My own album')
        ->and(DB::table('content.f_link')->where('item_id', $itemId)->where('source_id', $sourceId)->value('url'))
        ->toBe('https://example.test/mine')
        ->and(DB::table('content.f_authored')->where('item_id', $itemId)->value('creator'))->toBe('The Owner')
        ->and(DB::table('content.item_media')->where('item_id', $itemId)->where('role', 'cover')->count())->toBe(1)
        ->and(DB::table('content.offers')->where('item_id', $itemId)->where('source_id', $sourceId)->value('qualifier'))
        ->toBe('from')
        ->and((int) DB::table('content.offers')->where('item_id', $itemId)->value('amount_minor'))->toBe(2500)
        ->and(DB::table('content.item_tags')->where('item_id', $itemId)->where('source_id', $sourceId)->value('tag'))
        ->toBe('owner-authored');

    expect(DB::table('content.items')->where('id', $itemId)->value('headline_cache'))->toBe('My own album');
});

it('is idempotent on the coord, so a backfill can be re-run', function () {
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $coord = 'manual:'.Str::uuid();
    $writer = app(ProjectionWriter::class);

    $first = $writer->writeManualItem($userId, $coord, manualLaneRelease('Draft title', 'https://example.test/mine'));
    $second = $writer->writeManualItem($userId, $coord, manualLaneRelease('Corrected title', 'https://example.test/mine'));

    expect($second)->toBe($first)
        ->and(DB::table('content.items')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.source_items')->count())->toBe(1)
        // A re-run overwrites the value rather than appending a second row:
        // f_text is a singleton keyed (item_id, source_id), and the
        // collection facets are replaced wholesale per (item, source).
        ->and(DB::table('content.f_text')->where('item_id', $first)->value('headline'))->toBe('Corrected title')
        ->and(DB::table('content.f_text')->where('item_id', $first)->count())->toBe(1)
        ->and(DB::table('content.offers')->where('item_id', $first)->count())->toBe(1)
        ->and(DB::table('content.item_tags')->where('item_id', $first)->count())->toBe(1);
});

it('bumps the site build state so the public document rebuilds', function () {
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $siteId = (string) DB::table('site.sites')->where('user_id', $userId)->value('id');

    app(ProjectionWriter::class)->writeManualItem(
        $userId,
        'manual:'.Str::uuid(),
        manualLaneRelease('My own album', 'https://example.test/mine'),
    );

    expect((int) DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Ingest/ManualSourceLaneTest.php`
Expected: FAIL — `Call to undefined method App\Ingest\Projection\ProjectionWriter::writeManualItem()`

- [ ] **Step 3: Widen `upsertSourceItem()` to accept a stream-less record**

In `app/Ingest/Projection/ProjectionWriter.php`, change the signature at `:252-259` — only the two parameter types change, the body is untouched:

```php
    private function upsertSourceItem(
        string $contentSourceId,
        string $coord,
        ?string $streamId,
        ?string $recordKey,
        string $kind,
        int $projectorVersion,
    ): string {
```

- [ ] **Step 4: Correct `writeIdentityKeys()`'s docblock**

That docblock currently reads "projectStream() is the only caller; keep it that way" (`:305-307`). It is about to acquire a second caller, and leaving it would make it an actively wrong instruction. Replace that sentence with:

```php
     * projectStream() and writeManualItem() are the only callers; keep it that
     * way. Both wrap it in a transaction that ALSO covers the source item's
     * own upsert, which is the property this method depends on.
```

- [ ] **Step 5: Add `writeManualItem()`**

Add immediately after `ensureManualSource()`:

```php
    /**
     * Land ONE owner-authored item through the same spine a connector record
     * travels: source item, identity keys, resolved item, typed facets.
     *
     * The alternative — each backfiller and each hand-add writing content.*
     * directly — was measured and rejected in the slice 0b design: it
     * duplicates this class's semantics per call site, and any divergence
     * produces items the identity resolver treats inconsistently. The live
     * proof is the hand-rolled writer this method replaces, which skipped
     * identity_keys and item_anchors and so had every hand-added item
     * detached from its own source row by the next connector run.
     *
     * The returned id is the RESOLVED item, not necessarily a new one: a
     * hand-typed URL that matches a synced item folds into it, which is the
     * convergence the content schema exists for.
     *
     * CALLER CONSTRAINT: at most ONE coord per canonical URL per user.
     * Resolver::poisonedKeys() poisons a key value that a SINGLE source
     * contributes twice, and there is exactly one manual source per user — so
     * two manual coords carrying the same URL do not merge, they disable that
     * URL as a joining key for the whole run, taking any connector item
     * carrying it down with them.
     *
     * MUST NOT be called inside a transaction — replaceCollections() documents
     * its own as the outermost one.
     *
     * @param  string  $coord  stable and caller-owned: 'manual:{sha1(canonical url)}'
     *                         for a hand-add, 'manual:{legacy_uuid}' for a backfill
     *                         (spec §8.1), so a re-run updates rather than
     *                         duplicates and the legacy identifier survives its
     *                         table being dropped
     * @param  array<string, mixed>  $projection  the shape Projector::project() returns
     */
    public function writeManualItem(string $userId, string $coord, array $projection): string
    {
        $contentSourceId = $this->ensureManualSource($userId);
        $kind = (string) $projection['kind'];

        // Same one-transaction-per-record boundary the connector path uses,
        // and for the same reason: a committed source item visible with zero
        // identity keys resolves as an unrelated singleton and mints a
        // spurious item. See the long note at the projectStream() call site.
        DB::transaction(function () use ($contentSourceId, $coord, $kind, $projection) {
            $sourceItemId = $this->upsertSourceItem(
                contentSourceId: $contentSourceId,
                coord: $coord,
                streamId: null,
                recordKey: null,
                kind: $kind,
                // 0 = no projector governs this row. Nothing branches on the
                // value (its only reader is the DSAR export), and a real
                // version number would imply a rebuild could re-derive it.
                projectorVersion: 0,
            );
            $this->writeIdentityKeys($sourceItemId, $coord, $projection);
        });

        $itemByCoord = $this->resolveItems($userId, $kind);

        if (! isset($itemByCoord[$coord])) {
            // Unreachable: the row was just written live and resolveItems()
            // reads every live source item for (user, kind). Loud rather than
            // a null return, because a silent miss here would hand a caller
            // an id for the wrong item.
            throw new \RuntimeException("Manual coord {$coord} did not resolve to an item.");
        }

        $this->writeFacets($contentSourceId, $userId, [$coord => $projection], $itemByCoord);
        $this->refreshItemCaches($userId, array_values(array_unique(array_values($itemByCoord))));
        $this->bumpSite($userId);

        return $itemByCoord[$coord];
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Ingest/ManualSourceLaneTest.php`
Expected: PASS (5 tests)

- [ ] **Step 7: Verify the connector path is unbroken**

Run: `./vendor/bin/pest tests/Feature/Ingest/ tests/Unit/Ingest/`
Expected: PASS — the only production change to the connector path is two widened parameter types and a docblock.

- [ ] **Step 8: Commit**

```bash
php artisan pint app/Ingest/Projection/ProjectionWriter.php
git add app/Ingest/Projection/ProjectionWriter.php tests/Feature/Ingest/ManualSourceLaneTest.php
git commit -m "feat(content): land owner-authored items through ProjectionWriter

writeManualItem() reuses the connector spine — source item, identity
keys, resolved item, typed facets, offers, tags — so a hand-authored row
carries the CanonicalUrl key that lets it fold into a synced item, and
the anchor that stops the next projection minting a blank duplicate
beside it. Idempotent on a caller-owned coord, which is what makes a
backfill re-runnable (spec §8.1).

Slice 0b."
```

---

### Task 3: A connector run can never merge away an owner-authored item

This is the data-loss fix spec §8.3 asks for, and the reason slice 0b blocks slices 1, 3, 4 and 5.

**Why it matters, precisely.** `mergeInto()` ends in a hard `DELETE` on `content.items` when the discarded item carries no pin and no override (`:580-585`). Every `content.f_*` table, `item_media`, `offers`, `item_tags`, `item_links`, `item_slugs` and `collection_items` declares `ON DELETE CASCADE` on `items.id`, so that `DELETE` takes them all. For a connection that is survivable — `ItemMerger` states the reasoning outright, that facet rows are derived and "the next projection rewrites the facets under the surviving item". **A manual source has no next projection.** An owner-authored item merged away by a connector run loses the owner's words permanently.

**The fix is winner selection, not delete suppression.** Suppressing the delete would leave a source-item-less ghost row that `PoolResolver` still returns in `library` (it filters on `user_id` + `kind` + `removed_at`, nothing else). Making the owner's item the survivor keeps its facets in place and repoints the connector's source items onto it.

**Two honest consequences of the change**, both stated in the code comment:
- It **inverts which side is destroyed** in a manual↔connector merge. The connector's item row is now the one deleted, cascading its facets — restored on that connection's next `projectStream`, which is the same restoration `ItemMerger` already relies on, but may be hours away.
- The loss it prevents is reachable today **only from a backfiller**, not from the endpoint: `PoolItemCreateController` pins every hand-add, so `hasCuration` was already true for controller-created items. Since slices 3, 4 and 5 are entirely backfillers writing through this lane, that is the case that matters.

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php` (`bindGroup()` at `:503-535`; `mergeInto()` at `:580-585`; new private helper; new import)
- Test: `tests/Feature/Ingest/ManualSourceLaneTest.php` (append)

**Interfaces:**
- Consumes: `ProjectionWriter::writeManualItem()` from Task 2.
- Produces: no public surface. Private `preferOwnerAnchored(Collection $effective): ?string`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Ingest/ManualSourceLaneTest.php`:

```php
/** A bandcamp connection with its ingest source/stream and one landed release doc. */
function manualLaneBandcamp(string $userId, string $key, string $title, string $url): array
{
    $connection = \App\Models\Core\Site\IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => ['url' => 'https://'.Str::lower(Str::random(8)).'.bandcamp.com'],
        'is_active' => true,
    ]);

    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'releases',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $doc = ['title' => $title, 'url' => $url, 'artist' => 'Some Artist', 'type' => 'album'];
    DB::table('ingest.record_versions')->insert([
        'stream_id' => $streamId, 'key' => $key, 'doc_hash' => sha1(json_encode($doc)),
        'doc' => json_encode($doc), 'first_seen_at' => now(), 'is_current' => 1,
    ]);
    $versionId = DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $key)->value('id');
    DB::table('ingest.record_state')->insert([
        'stream_id' => $streamId, 'key' => $key, 'current_version_id' => $versionId, 'last_seen_at' => now(),
    ]);

    return [$source, $streamId];
}

it('keeps the owner-authored item when a connector run merges it with a synced one', function () {
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $album = 'https://artist.bandcamp.com/album/first';
    $writer = app(ProjectionWriter::class);

    // 1. The connector lands first.
    [$source, $streamId] = manualLaneBandcamp($userId, 'album/first', 'First Album', $album);
    $writer->projectStream($source, $streamId, 'releases');
    $syncedItemId = (string) DB::table('content.items')->where('user_id', $userId)->value('id');

    // Age the synced anchor explicitly. Laravel binds timestamps at SECOND
    // precision, so without this the two anchors tie on bound_at and
    // bindGroup()'s orderBy has no tiebreak — the pre-fix run would pass
    // spuriously about half the time and the post-fix assertion would prove
    // nothing. The synced side MUST be strictly older, because "oldest
    // binding wins" is exactly the rule this task overrides.
    DB::table('content.item_anchors')->where('user_id', $userId)->update(['bound_at' => now()->subHour()]);

    // 2. The owner hand-adds something with a DIFFERENT url, so it gets its
    //    own item — nothing unions the two yet.
    $coord = 'manual:'.Str::uuid();
    $ownerItemId = $writer->writeManualItem($userId, $coord, manualLaneRelease('The owner name', 'https://example.test/mine'));
    expect($ownerItemId)->not->toBe($syncedItemId);

    // 3. The owner corrects the link to the one the connector already carries.
    //    Both coords are now anchored to DIFFERENT items and share a
    //    CanonicalUrl — the only union mechanism ProjectionWriter writes, and
    //    the shape that reaches mergeInto()'s DELETE. The two coords sit on
    //    DIFFERENT sources, so the key is not poisoned.
    $resolved = $writer->writeManualItem($userId, $coord, manualLaneRelease('The owner name', $album));

    // The owner's row is the survivor, not the scrape's.
    expect($resolved)->toBe($ownerItemId);

    // Asserted as the ITEM ROW, deliberately: the loss mechanism is a
    // Postgres FK cascade off items.id, and the SQLite stand-ins declare no
    // foreign keys, so asserting "the facets survived" would pass vacuously
    // under `composer test` even with the bug present. "The row was never
    // deleted" is driver-independent and is what stops the cascade firing.
    expect(DB::table('content.items')->where('id', $ownerItemId)->exists())->toBeTrue();

    // Both coords now point at the owner's item.
    expect(DB::table('content.source_items')->where('item_id', $ownerItemId)->count())->toBe(2);

    // And the owner's own words are still there, at a priority that wins.
    $manualSourceId = DB::table('content.sources')->where('user_id', $userId)->where('kind', 'manual')->value('id');
    expect(DB::table('content.f_text')->where('item_id', $ownerItemId)->where('source_id', $manualSourceId)->value('headline'))
        ->toBe('The owner name')
        ->and(DB::table('content.items')->where('id', $ownerItemId)->value('headline_cache'))->toBe('The owner name');
});

it('still merges two connector items on the oldest binding when no owner row is involved', function () {
    // The owner preference must not change the survivor when nothing is
    // owner-authored — otherwise it is not a narrow addition, it is a rewrite
    // of merge semantics.
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $writer = app(ProjectionWriter::class);
    $shared = 'https://artist.bandcamp.com/album/shared';

    [$sourceA, $streamA] = manualLaneBandcamp($userId, 'album/a', 'Album A', 'https://artist.bandcamp.com/album/a');
    $writer->projectStream($sourceA, $streamA, 'releases');
    $firstItemId = (string) DB::table('content.items')->where('user_id', $userId)->value('id');
    // Same second-precision hazard as above: force A's anchor strictly older.
    DB::table('content.item_anchors')->where('user_id', $userId)->update(['bound_at' => now()->subHour()]);

    [$sourceB, $streamB] = manualLaneBandcamp($userId, 'album/b', 'Album B', $shared);
    $writer->projectStream($sourceB, $streamB, 'releases');

    // Repoint A's record at B's url so the two coords union.
    DB::table('ingest.record_versions')->where('stream_id', $streamA)->update([
        'doc' => json_encode(['title' => 'Album A', 'url' => $shared, 'artist' => 'Some Artist', 'type' => 'album']),
    ]);
    $writer->projectStream($sourceA, $streamA, 'releases');

    // Oldest anchor still wins: the first-projected item is the survivor.
    expect(DB::table('content.source_items')->where('item_id', $firstItemId)->count())->toBe(2);
});
```

- [ ] **Step 2: Run the tests**

Run: `./vendor/bin/pest tests/Feature/Ingest/ManualSourceLaneTest.php --filter="owner-authored item when a connector"`
Expected: FAIL — `$resolved` is the synced item id, and `content.items` no longer holds `$ownerItemId`.

Run: `./vendor/bin/pest tests/Feature/Ingest/ManualSourceLaneTest.php --filter="oldest binding when no owner row"`
Expected: PASS — this pins behaviour Task 3 must not change.

- [ ] **Step 3: Add the import**

At the top of `app/Ingest/Projection/ProjectionWriter.php`, add to the existing `use` block:

```php
use Illuminate\Support\Collection;
```

- [ ] **Step 4: Change the winner selection in `bindGroup()`**

Replace the single line at `:516`:

```php
        $winner = $effective->first() ?? $this->createItem($userId, $kind);
```

with:

```php
        $winner = $this->preferOwnerAnchored($effective)
            ?? $effective->first()
            ?? $this->createItem($userId, $kind);
```

- [ ] **Step 5: Add the helper**

Add immediately after `bindGroup()` (before `createItem()`):

```php
    /**
     * The owner outranks the machine (C8) at merge time: when a group already
     * spans more than one item, an item the user authored through their
     * manual source is the one that survives.
     *
     * Not a preference — a correctness requirement. mergeInto() below hard-
     * DELETEs a discarded item that carries no pin and no override, and every
     * content.f_* / item_media / offers / item_tags / item_links / item_slugs
     * table cascades on items.id, so that DELETE takes those rows with it. A
     * connection survives because its next projection rewrites them; a manual
     * source has no next projection, so the owner's words would be gone.
     *
     * Suppressing the DELETE instead would be worse: it leaves a row with no
     * source items that PoolResolver still returns in `library`, since that
     * query filters on user_id + kind + removed_at and nothing else.
     *
     * This DOES invert which side is destroyed in a manual/connection merge —
     * the connection's item row is now the discarded one, and its facets are
     * restored on that connection's next run. That is the same restoration
     * ItemMerger already relies on, and it is the trade the C8 rule requires.
     *
     * Consulted ONLY when a merge is actually about to happen. In steady state
     * every group is a singleton, so this costs zero queries on the hot path.
     *
     * @param  Collection<int, string>  $effective  candidate item ids, oldest binding first
     */
    private function preferOwnerAnchored(Collection $effective): ?string
    {
        if ($effective->count() < 2) {
            return null;
        }

        $ownerAuthored = DB::table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->whereIn('si.item_id', $effective->all())
            ->where('cs.kind', 'manual')
            ->whereNull('si.removed_at')
            ->distinct()
            ->pluck('si.item_id')
            ->map(fn ($id) => (string) $id)
            ->flip();

        // Oldest-binding-wins still applies WITHIN the owner-authored set:
        // $effective arrives in bound_at order, so the first match is oldest.
        return $effective->first(fn (string $itemId) => $ownerAuthored->has($itemId));
    }
```

- [ ] **Step 6: Add `item_links` to the curation check**

`content.item_links` holds the owner's hand-saved cross-platform links (`20260805090000_content_item_links.sql`, written by `ItemLinkController`). It is owner-typed, is never rewritten by any projection, and cascades on `items.id` — so it belongs in the same predicate as pins and overrides. In `mergeInto()`, replace `:580-581`:

```php
        $hasCuration = DB::table('site.section_items')->where('item_id', $discardedItemId)->exists()
            || DB::table('content.manual_overrides')->where('item_id', $discardedItemId)->exists();
```

with:

```php
        // item_links is the owner's hand-saved cross-platform link set. Like a
        // pin and an override it is typed by a person, is never rewritten by a
        // projection, and cascades on items.id — so a merge must not delete it
        // out from under them.
        $hasCuration = DB::table('site.section_items')->where('item_id', $discardedItemId)->exists()
            || DB::table('content.manual_overrides')->where('item_id', $discardedItemId)->exists()
            || DB::table('content.item_links')->where('item_id', $discardedItemId)->exists();
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Ingest/ManualSourceLaneTest.php`
Expected: PASS (7 tests)

- [ ] **Step 8: Verify no connector regression**

Run: `./vendor/bin/pest tests/Feature/Ingest/ tests/Feature/Content/ tests/Unit/Ingest/`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
php artisan pint app/Ingest/Projection/ProjectionWriter.php
git add app/Ingest/Projection/ProjectionWriter.php tests/Feature/Ingest/ManualSourceLaneTest.php
git commit -m "fix(content): an owner-authored item survives a connector merge

mergeInto() hard-deletes a merged-away item carrying no curation, and
content.f_* cascades on items.id — so a scrape could delete the row a
person typed along with every facet on it. A connection survives that
because its next projection rewrites the facets; a manual source has no
next projection.

bindGroup() now prefers an owner-anchored item as the merge survivor,
which keeps the facets in place rather than suppressing the delete and
leaving a source-item-less ghost in the pool library. Consulted only
when a merge is actually happening, so the hot path is unchanged. Also
adds content.item_links — owner-typed and never rewritten by a
projection — to the curation predicate that spares a row.

Spec §8.3. Slice 0b."
```

---

### Task 4: Pin the poisoning constraint the backfillers must design around

This replaces revision 1's "manual-vs-manual merge" task, whose premise was false.

**What was wrong.** Revision 1 assumed two manual coords sharing a URL merge into one item, so a delete of the loser was harmless. They do not merge. `Resolver::poisonedKeys()` (`app/Content/Identity/Resolver.php:102-127`) poisons a key value that a **single source** contributes twice, on the reasoning that a value which does not identify anything within a source cannot identify anything across sources. There is exactly one manual source per user, so two manual coords carrying the same URL poison it — and `keyIndex()` (`:151-153`) then drops it for the whole run.

**Proved by running the pure resolver**, three cases:

```
same-source pair  -> groups: [["manual:1"],["manual:2"]]
cross-source pair -> groups: [["manual:1","bandcamp:acct:x"]]
two-manual + conn -> groups: [["manual:1"],["manual:2"],["bandcamp:acct:x"]]
```

The third is the one that matters: a second manual coord for the same URL does not merely fail to merge, it **takes the connector item down with it**. Convergence for that URL stops for as long as both manual coords are live.

Slices 3, 4 and 5 will each backfill hundreds of owner rows, and duplicate URLs are ordinary in legacy data. This must be a written, tested constraint rather than something a backfiller discovers.

**Files:**
- Test: `tests/Feature/Ingest/ManualSourceLaneTest.php` (append). No production change.

**Interfaces:**
- Consumes: `writeManualItem()` (Task 2).
- Produces: nothing. The constraint is enforced by callers — Task 5 shows how.

- [ ] **Step 1: Write the test**

Append to `tests/Feature/Ingest/ManualSourceLaneTest.php`:

```php
it('poisons a url that two manual coords share, and the connector item with it', function () {
    // NOT a bug to fix here — a deliberate Resolver property (poisonedKeys():
    // a value one source contributes twice identifies nothing), colliding
    // with "exactly one manual source per user". Pinned so slices 3/4/5 read
    // it as a constraint on how a backfiller mints coords, and so nobody
    // "fixes" poisonedKeys() without seeing what it protects.
    //
    // The rule: AT MOST ONE MANUAL COORD PER CANONICAL URL PER USER.
    $userId = createTenant('manual-'.Str::lower(Str::random(6)))->id;
    $album = 'https://artist.bandcamp.com/album/first';
    $writer = app(ProjectionWriter::class);

    [$source, $streamId] = manualLaneBandcamp($userId, 'album/first', 'First Album', $album);
    $writer->projectStream($source, $streamId, 'releases');

    // One manual coord on that url folds into the synced item — the good case.
    $folded = $writer->writeManualItem($userId, 'manual:'.Str::uuid(), manualLaneRelease('Mine', $album));
    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('content.source_items')->where('item_id', $folded)->count())->toBe(2);

    // A SECOND manual coord on the same url poisons it. All three coords
    // separate — including the connector's, which had already folded.
    $writer->writeManualItem($userId, 'manual:'.Str::uuid(), manualLaneRelease('Mine again', $album));

    expect(DB::table('content.items')->where('user_id', $userId)->count())->toBe(3);

    // The damage is scoped to identity, not to data: every coord still has an
    // item and no row was destroyed. That is what makes the caller-side rule
    // (one coord per url) a sufficient remedy.
    expect(DB::table('content.source_items')->whereNull('item_id')->count())->toBe(0);
});
```

- [ ] **Step 2: Run it**

Run: `./vendor/bin/pest tests/Feature/Ingest/ManualSourceLaneTest.php --filter="poisons a url"`
Expected: PASS — it documents behaviour that already exists. If it FAILS, stop and report: either `poisonedKeys()` has changed, or `writeManualItem()` is not reaching the resolver as designed, and both invalidate Task 5's coord scheme.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Ingest/ManualSourceLaneTest.php
git commit -m "test(content): pin the one-coord-per-url constraint on the manual lane

Resolver::poisonedKeys() disables a key value that a single source
contributes twice, and there is exactly one manual source per user — so
two manual coords on one url do not merge, they poison that url for the
whole run and stop the connector item unioning too.

Not a bug to fix: it is a constraint every backfiller in slices 3-5 must
mint coords against, written down with the failure it produces.

Slice 0b."
```

---

### Task 5: Repoint `PoolItemCreateController` onto the lane

Deletes the hand-rolled writer that skips identity keys and anchors, and satisfies Task 4's constraint by construction.

**Two behaviour changes to handle.**

1. **Coord derivation.** Minting a fresh UUID per POST means two hand-adds of the same URL create two manual coords — which Task 4 proves poisons that URL. Deriving the coord as `manual:sha1(strtolower(trim(url)))` makes the second POST an upsert of the first, so one URL is always one coord. The canonicalisation matches `KeyClass::CanonicalUrl->canonicalise()` (which lowercases), so two URLs that would union always share a coord.
2. **Conditional pin.** `writeManualItem()` returns the **resolved** item, which may be an item the user has already pinned — a hand-typed URL matching a synced item now folds into it. `site.section_items` carries `CONSTRAINT section_items_unique UNIQUE (section_id, item_id)` (`20260727150000_sections_and_documents.sql:87`), so today's unconditional `new SectionItem; …->save()` would raise a 23505 and surface as a 500. The SQLite stand-in (`tests/Pest.php:2227-2234`) declares no such constraint, so this would not have been caught by `composer test`.

**Files:**
- Modify: `app/Http/Controllers/Api/Content/PoolItemCreateController.php`
- Modify: `tests/Pest.php` (`site.section_items` stand-in)
- Test: `tests/Feature/Content/PoolLaneTest.php` (append two cases)

**Interfaces:**
- Consumes: `ProjectionWriter::writeManualItem(string $userId, string $coord, array $projection): string` from Task 2.
- Produces: no wire-shape change. `POST /api/content/pools/{pool}/items` still returns `PoolResolver::resolve()` output with HTTP 201.

- [ ] **Step 1: Write the failing regression tests**

Append to `tests/Feature/Content/PoolLaneTest.php`. Both go through the real HTTP stack via `actingAsUser()` (`tests/Pest.php:105`) and the named route `content.pools.items.store` (`routes/api/user.php:172-173`) — the surrounding file's direct-controller calls bypass route middleware and the exception renderer, and the second case below exists to catch a **500**, which a direct call can never observe:

```php
it('hand-adds an item that a later connector run enriches instead of stranding', function () {
    // The defect this slice replaces: the old hand-rolled writer wrote no
    // identity keys and no anchor, so resolveItems() — which unions every
    // live source item for (user, kind) across ALL sources — saw a keyless
    // singleton, minted a blank content.items row for it, and repointed the
    // hand-added source item onto that blank. The owner kept seeing their
    // item only because the pin references it by id.
    [$pro] = poolTenant();

    actingAsUser($pro)
        ->postJson(route('content.pools.items.store', ['pool' => 'watch']), [
            'url' => 'https://vimeo.com/999', 'title' => 'Our showreel',
        ])
        ->assertCreated();

    $sourceId = DB::connection('pgsql')->table('content.sources')
        ->where('user_id', $pro->id)->where('kind', 'manual')->value('id');
    $sourceItem = DB::connection('pgsql')->table('content.source_items')->where('source_id', $sourceId)->first();

    expect(DB::connection('pgsql')->table('content.identity_keys')
        ->where('source_item_id', $sourceItem->id)->count())->toBe(2)
        ->and(DB::connection('pgsql')->table('content.item_anchors')
            ->where('user_id', $pro->id)->where('coord', $sourceItem->coord)->value('item_id'))
        ->toBe($sourceItem->item_id)
        // One coord per url, so a repeat POST cannot poison it (Task 4).
        ->and($sourceItem->coord)->toBe('manual:'.sha1('https://vimeo.com/999'));

    // Exactly one item, and it is the one the source row points at — no blank
    // duplicate, nothing stranded.
    $items = DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->get();
    expect($items)->toHaveCount(1)
        ->and($items[0]->id)->toBe($sourceItem->item_id)
        ->and($items[0]->headline_cache)->toBe('Our showreel');
});

it('re-adding the same url upserts one coord rather than poisoning it', function () {
    // Two coords on one url would poison that url for the whole resolution
    // run (Task 4). The deterministic coord makes the second POST an upsert.
    [$pro] = poolTenant();
    $payload = ['url' => 'https://vimeo.com/999', 'title' => 'Our showreel'];
    $route = route('content.pools.items.store', ['pool' => 'watch']);

    actingAsUser($pro)->postJson($route, $payload)->assertCreated();

    // The second call must not 500 on section_items_unique, and must not add
    // a second item, a second source item, or a second pin.
    $response = actingAsUser($pro)->postJson($route, $payload)->assertCreated();

    expect($response->json('data.selection'))->toHaveCount(1)
        ->and(DB::connection('pgsql')->table('content.items')->where('user_id', $pro->id)->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('content.source_items')->count())->toBe(1);
});
```

Note on the response accessor: `ApiController::success()` wraps the payload, so `selection` sits under `data`. If the repo's envelope differs, read `app/Http/Controllers/Api/ApiController.php` and adjust the two `json()` paths — do not change the assertions themselves.

- [ ] **Step 2: Tighten the `site.section_items` stand-in**

Postgres has this constraint; SQLite did not, so a duplicate pin would go unnoticed under `composer test`. In `tests/Pest.php`, change the `site.section_items` stand-in (`:2227-2234`) — one added line:

```php
    $pg->statement('CREATE TABLE IF NOT EXISTS site.section_items (
        id TEXT PRIMARY KEY NOT NULL,
        section_id TEXT NOT NULL,
        item_id TEXT NOT NULL,
        state TEXT NOT NULL CHECK (state IN (\'pinned\', \'excluded\')),
        sort_key REAL NULL,
        created_at TEXT NOT NULL,
        UNIQUE (section_id, item_id)
    )');
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Content/PoolLaneTest.php`
Expected: FAIL — the first new case fails on `identity_keys` count 0; the second fails on a UNIQUE violation from the duplicate pin.

If any **pre-existing** case in this file now fails on the new UNIQUE constraint, stop and report it — that is a latent double-pin bug the stand-in was hiding, and it needs its own decision.

- [ ] **Step 4: Rewrite the controller**

Replace the whole of `app/Http/Controllers/Api/Content/PoolItemCreateController.php` with:

```php
<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\SectionItem;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The hand-add half of a pool (platforms-as-sources): POST a URL, get a
// MANUAL-source content item, pinned into the selection.
//
// The write goes through ProjectionWriter::writeManualItem() rather than
// bespoke SQL (slice 0b). The earlier hand-rolled version wrote the item and
// its facets but no identity keys and no anchor, so the next connector run
// for the same kind resolved the keyless source item as a singleton, minted a
// blank content.items row for it, and repointed the hand-added source item
// onto that blank — leaving the owner's item detached from its own source row
// and a duplicate in their library.
//
// Deliberately thin on enrichment: the headline defaults to the URL's host
// when the caller sends none. A hand-add is the owner typing a link they
// already know — the honest contract is "it appears, titled what you called
// it". The identity lane may fold it into a synced item, now or later.
class PoolItemCreateController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly PoolResolver $resolver,
        private readonly PoolSectionProvisioner $provisioner,
        private readonly ProjectionWriter $writer,
    ) {}

    /** POST /api/content/pools/{pool}/items  { url, title?, kind? } */
    public function store(Request $request, string $pool): JsonResponse
    {
        if (! PoolRegistry::isPool($pool)) {
            abort(404, 'Unknown pool.');
        }

        $kinds = PoolRegistry::kinds($pool);
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url:https'],
            'title' => ['nullable', 'string', 'max:300'],
            // The pool's own kinds only — "episode" can't land in Watch.
            'kind' => ['nullable', 'string', 'in:'.implode(',', $kinds)],
        ]);

        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        $kind = $data['kind'] ?? $kinds[0];
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = (string) (parse_url($data['url'], PHP_URL_HOST) ?: $data['url']);
        }

        // ONE coord per url, never a fresh uuid per request. Two manual coords
        // carrying the same url poison it for the whole resolution run —
        // Resolver::poisonedKeys() drops a value a single source contributes
        // twice, and a user has exactly one manual source — which would stop
        // the synced item unioning too. Canonicalised the same way
        // KeyClass::CanonicalUrl does it, so two urls that would union always
        // share a coord.
        $coord = 'manual:'.sha1(strtolower(trim($data['url'])));

        // No wrapping transaction: writeManualItem() manages its own, and
        // ProjectionWriter::replaceCollections() documents that no caller
        // holds one over the projection path.
        //
        // The returned id may be an item that already exists — a hand-typed
        // URL matching a synced one folds into it, which is the whole point
        // of routing hand-adds through the identity spine.
        $itemId = $this->writer->writeManualItem($user->id, $coord, [
            'kind' => $kind,
            'headline' => $title,
            'facets' => ['f_link' => ['url' => $data['url']]],
        ]);

        $section = $this->provisioner->ensure($site, $pool);

        // A hand-add is a pick by definition — pin it at the end. Conditional
        // because the fold-in above can hand back an item that is ALREADY
        // pinned, and site.section_items carries UNIQUE (section_id, item_id).
        $alreadyPinned = SectionItem::query()
            ->where('section_id', $section->id)
            ->where('item_id', $itemId)
            ->exists();

        if (! $alreadyPinned) {
            $highest = SectionItem::query()
                ->where('section_id', $section->id)
                ->where('state', SectionItem::STATE_PINNED)
                ->max('sort_key');
            $pin = new SectionItem;
            $pin->section_id = (string) $section->id;
            $pin->item_id = $itemId;
            $pin->state = SectionItem::STATE_PINNED;
            $pin->sort_key = $highest === null ? 1.0 : ((float) $highest) + 1.0;
            $pin->created_at = now();
            $pin->save();
        }

        // writeManualItem() already bumped the build state for the content
        // write; this covers the curation write above. Both are cheap
        // increments, and a missed bump is a stale public document.
        BuildState::bump((string) $site->id);
        if ($site->subdomain !== '') {
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }

        return $this->success($this->resolver->resolve($site, $pool), 201);
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Content/PoolLaneTest.php`
Expected: PASS — including the pre-existing `hand-adds an item by link: manual source, pinned, titled` case at `:365`, whose assertions (one manual source, reused on the second add, headline and url on the selection) all still hold. Note that case adds **two different** urls, so it does not trip the poisoning constraint.

- [ ] **Step 6: Run the full suite**

Run: `COMPOSER_PROCESS_TIMEOUT=0 composer test`
Expected: PASS. `PoolLaneTest`'s `beforeEach` calls `setupContentTables()` but not `setupIngestTables()`; `writeManualItem()` touches no `ingest.*` table, so no helper change is needed there.

- [ ] **Step 7: Commit**

```bash
php artisan pint app/Http/Controllers/Api/Content/PoolItemCreateController.php
git add app/Http/Controllers/Api/Content/PoolItemCreateController.php tests/Pest.php tests/Feature/Content/PoolLaneTest.php
git commit -m "fix(content): route pool hand-adds through the manual write lane

The bespoke SQL wrote the item and its facets but no identity keys and
no anchor, so the next connector run for the same kind resolved the
keyless source item as a singleton, minted a blank content.items row,
and repointed the hand-added source item onto it — the owner's item
detached from its own source row, a blank duplicate in their library.

The coord is now derived from the url instead of a per-request uuid: two
manual coords on one url poison it for the whole resolution run and stop
the synced item unioning too. And because a hand-add can now fold onto
an already-pinned item, the pin is conditional — site.section_items
carries UNIQUE (section_id, item_id), which the SQLite stand-in was
missing and now mirrors.

Slice 0b."
```

---

### Task 6: Verify on dev, record the checkpoint, write the manifest

Spec invariant #1: no slice is done without a live database assertion, run against dev, with output pasted into the checkpoint.

**Files:**
- Modify: `docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md`
- Create: `docs/wire-changes/` (the directory does not exist — this is its first file, and it sets the convention)

**Interfaces:**
- Consumes: everything from Tasks 0–5. Produces: no code.

- [ ] **Step 1: Open the PR and merge to `development`**

Follow `docs/deploy/routine-deploy.md`. There is no migration in this slice, so the migration-ordering section does not apply. `development` is the CI-gated branch. **Do not push to `production`.**

- [ ] **Step 2: Exercise the lane on dev**

The route sits behind the authenticated user group, so drive it server-side rather than minting a JWT. Substitute a real dev handle for `<handle>`:

```bash
cloud command:run partna development "tinker --execute=\"
  \\\$u = App\\\\Models\\\\Core\\\\User\\\\User::whereHas('site', fn(\\\$q) => \\\$q->where('subdomain','<handle>'))->firstOrFail();
  \\\$id = app(App\\\\Ingest\\\\Projection\\\\ProjectionWriter::class)->writeManualItem(
      \\\$u->id,
      'manual:'.sha1('https://vimeo.com/76979871'),
      ['kind' => 'video', 'headline' => 'Slice 0b smoke', 'facets' => ['f_link' => ['url' => 'https://vimeo.com/76979871']]]
  );
  echo \\\$id;
\""
```

This calls the same method the controller calls, with the same coord scheme, so it exercises the lane end to end without an auth round trip. Record the printed item id.

- [ ] **Step 3: Assert the shape**

```sql
SELECT cs.kind        AS source_kind,
       cs.priority,
       si.coord,
       si.stream_id,
       si.item_id,
       i.kind         AS item_kind,
       i.headline_cache,
       (SELECT count(*) FROM content.identity_keys k WHERE k.source_item_id = si.id) AS keys,
       (SELECT count(*) FROM content.item_anchors a WHERE a.coord = si.coord)        AS anchors
FROM content.source_items si
JOIN content.sources cs ON cs.id = si.source_id
LEFT JOIN content.items i ON i.id = si.item_id
WHERE cs.kind = 'manual';
```

Expected per row: `priority` 200, `stream_id` NULL, `item_id` non-null, `keys` = 2, `anchors` = 1.

And re-run the orphan query from Task 0 Step 1. The gate is **no increase over that baseline**, not zero — a pre-existing non-zero count is possible from historical merges that `hasCuration` spared:

```sql
SELECT count(*) AS orphan_items
FROM content.items i
WHERE i.removed_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM content.source_items si WHERE si.item_id = i.id);
```

- [ ] **Step 4: Scan the logs**

Run: `cloud env:logs partna development --minutes 10`
Expected: clean — no `RuntimeException: Manual coord … did not resolve to an item`, no `Could not resolve a manual content source`, no 23505 on `section_items_unique`.

- [ ] **Step 5: Append the checkpoint to the spec**

Append a `## 12. Slice 0b checkpoint` section holding: the SQL from Task 0 Step 1 and Steps 3 above with **real pasted output**; the Pest test names (`tests/Feature/Ingest/ManualSourceLaneTest.php`, 8 cases; `tests/Feature/Content/PoolLaneTest.php`, 2 added cases); and the Step 4 log scan result.

- [ ] **Step 6: Write the wire-change manifest**

The directory does not exist yet. Create `docs/wire-changes/2026-08-11-slice-0b-manual-lane.md` with the four fields §10 specifies — endpoint, before shape, after shape, consuming repo — printing both shapes even though they are identical, so later manifests have a template:

```markdown
# Wire changes — slice 0b, manual write lane (2026-08-11)

**Endpoint:** `POST /api/content/pools/{pool}/items`
**Consuming repos:** Partna-App (dashboard pool page). partna-monorepo is
unaffected — it renders the public sitepage from the built document and never
calls this endpoint.

**Before shape:** HTTP 201, `{ data: { selection: [...], library: [...], latestItemId: string|null } }`
**After shape:**  HTTP 201, `{ data: { selection: [...], library: [...], latestItemId: string|null } }` — identical.

**Behaviour changes** (three, none of them shape):

1. A hand-added URL that matches an item already in the pool now **folds into
   that item** instead of creating a second one. `selection` may not grow by
   one, and `library` may not gain an entry. Any client assuming "POST an item
   → exactly one new row appears" is now wrong — re-render from the returned
   `selection` / `library` rather than appending optimistically.
2. The folded-in item keeps the **owner's** headline and link: the manual
   source sits at priority 200 against a connection's 100, and `ValueResolver`
   resolves `f_text.headline` and `f_link.url` by source priority. A hand-add
   is therefore also an edit of the synced item's displayed title.
3. POSTing the same URL twice is now idempotent — the second call upserts the
   first rather than creating a duplicate. It still returns 201.

**Also worth knowing:** a hand-add restamps `last_seen_at` on every item of
that kind in the user's library (`refreshItemCaches()` batches the whole
resolved set). `library` is ordered by `last_seen_at DESC`, so its order
collapses to a tie after any hand-add. Pre-existing for connector runs, new
for this endpoint.

**No action required** if the client already re-renders from the response body.
```

- [ ] **Step 7: Commit**

```bash
git add docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md docs/wire-changes/
git commit -m "docs(spec): record the slice 0b checkpoint and its wire manifest

Live dev SQL with pasted output per invariant #1, and the first file in
docs/wire-changes/ — the endpoint's shape is unchanged but three
behaviours are not, including a hand-add now folding into a matching
synced item rather than duplicating it."
```

---

## What this slice deliberately does not do

Named so a reviewer reads them as decisions rather than omissions.

- **No new `tests/Postgres/` file.** The data-loss mechanism in Task 3 is a Postgres FK cascade, but the fix is "the row is never deleted", which is driver-independent and fully asserted under SQLite. A Postgres-lane test would only re-prove the pre-fix damage, at the cost of the ~200 lines of per-file duplicated DDL that lane requires. The SQLite assertion is written against the item row, not the facet rows, precisely so it cannot pass vacuously.
- **No batch entry point.** `writeManualItem()` is strictly per-item and calls `resolveItems()` + `refreshItemCaches()` + `bumpSite()` on every invocation, each scoped to the user's whole live set for that kind. Slice 4's 370 dishes would therefore cost 370 full resolves. A `writeManualItems(string $userId, array $projectionsByCoord)` wrapper — per-coord `upsertSourceItem`/`writeIdentityKeys`, then resolve/write/refresh/bump **once** — is deliberately deferred to **slice 3**, the first caller with volume, which can size it against real data. Building it now would ship an untested batch path.
- **`mergeInto()`'s hard `DELETE` stays** for connection-vs-connection merges. It can still destroy a second connection's facet rows when only the first connection's stream is being projected — restored on that source's next run, which is the reasoning `ItemMerger` already states. Widening the fix to repoint facet rows on merge is a change to hot, well-tested code with no manual-lane benefit.
- **`bindGroup()`'s `orderBy('bound_at')` keeps its tie.** Timestamps bind at second precision, so two anchors written in the same second have no deterministic order and the merge survivor is arbitrary. Pre-existing, and Task 3's fix makes the case this slice cares about deterministic regardless (the owner-authored set is a singleton in practice). Adding a tiebreak would change survivor selection for every existing user's data.
- **Analytics are accepted as orphaned (spec §9.7).** `analytics.item_views` and `analytics.content_popularity_scores.content_key` hold item ids, and Task 3 changes **which** id is destroyed in a manual↔connector merge, not **whether** one is. The pre-fix behaviour already orphaned the discarded side's rows. No repointing is added; the position is recorded here rather than discovered later.
- **No backfiller.** `app/Services/Migration/` and the per-type backfillers belong to slices 3, 4 and 5. Slice 0b delivers the lane they write through; `writeManualItem()`'s coord parameter is their seam (`manual:{legacy_uuid}`, spec §8.1), bounded by Task 4's one-coord-per-URL rule.
- **The endpoint's authorization and capability gap is carried forward.** The rewritten controller has no `authorizeForUser()` call and no `AccountCapabilities::for($user)` check — matching every other controller in `app/Http/Controllers/Api/Content/`. Tenancy is not unsafe (`currentUser()`/`currentSite()` scope every write), so this is a convention gap, not a hole. Fixing it across that whole namespace is one change, not six, and does not belong in an infrastructure slice.

## Cache invalidation (spec §9.2)

§9.2 requires each slice to name the keys it invalidates. This one:

| Key | Busted by this slice? |
|---|---|
| `CacheKeyGenerator::publicProfile($handleLc, $site->updated_at->timestamp)` | **No.** A content write does not touch `site.sites.updated_at`, so the key does not roll. TTL-bounded staleness only (`partna.public_profile.cache_ttl_seconds`, default 60). |
| `CacheKeyGenerator::publicSitePayload($subdomain)` and `:stale` | **No.** Busted only from `SiteCacheService` on site mutations. |
| `site.site_build_state.content_revision` | **Yes** — `writeManualItem()` → `bumpSite()` → `BuildState::bump()`, and the controller bumps again for the curation write. |
| Cloudflare edge | **Task 5 only** — the controller's existing `CloudflareCachePurgeJob`. Task 3's change lands on the **connector** path, which has no edge purge; a connector-triggered survivor change is therefore visible at the edge only after TTL. Accepted: the same is already true of every projection run. |

**§9.1 correction.** `BuildState`'s docblock (`app/Site/Documents/BuildState.php:17-19`) claims "a CI check keeps the list [of raw-write seams] current". **No such check exists** — `tests/Feature/Architecture/` holds 23 guards and none concerns `BuildState`, and no registry file exists. The spec's §9.1 inherits that false claim. This slice introduces no raw-write seam outside `ProjectionWriter` (which bumps via `bumpSite()`), so nothing is at risk here — but **slices 3, 4 and 5 add ten backfillers, all raw-write seams, and there is no guard to catch a missing bump.** Building that guard should be raised against the spec and owned by slice 3.

## Self-review notes

- **Spec coverage.** §6's scope sentence names `source_items`, `identity_keys`, `item_anchors`, `items`, `f_*`, `item_media`, `offers`, `item_tags` — all reached by `writeManualItem()` and all asserted in Task 2 (the fixture carries an offer and a tag precisely so those paths execute, per invariant 6). §6's `ensureContentSource()` manual branch is Task 1, as a sibling method since the existing one is keyed on `connection_id`. §6's definition of done — "a manual source can land an item that `PoolResolver` returns and a subsequent connector run does not corrupt" — is Task 3's first test plus Task 5's first test. §8.1 idempotency is Task 2. §8.3's mitigation is Task 3. §3 invariant 1 is Task 0 Step 1 + Task 6 Step 3; invariant 4 (backfill is production code) applies to slices 3–5, not here; invariant 6 is why Task 2's fixture is not minimal.
- **`PoolResolver` returns manual items with no change required.** Its `library` query filters on `user_id` + `kind` + `removed_at` only, and `SectionCandidates`' `from_source` operator already accepts `'manual'`. `latest_per_auto_source` inner-joins `platform_connections` and so never auto-selects a manual item — correct, since an owner-authored item is selected by pinning it.
- **Type consistency.** `ensureManualSource(string): string`, `writeManualItem(string, string, array): string`, `preferOwnerAnchored(Collection): ?string`, and `upsertSourceItem`'s widened `?string $streamId, ?string $recordKey` are used at exactly those signatures in Tasks 2, 3 and 5.
