# Media Pool Slice 1b Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put Instagram photos into the media pool on owned bytes, put Google photos on the public payload as a live display-only lane with attribution, and migrate the three upload selections while recording the eighty-six that cannot be carried.

**Architecture:** Media splits into two classes. *Owned* (uploads, Instagram) mirrors bytes to R2, keys on stable identity, is poolable and pinnable. *Borrowed* (Google) is resolved live inside a ~30-day expiry window, attributed, pruned, and never pinned. The split is forced by three facts established in the spec: Places photo refs rotate every fetch, resolved URLs die at ~30 days, and Google's terms grant photos no caching exemption.

**Tech Stack:** PHP 8.4, Laravel 12, PostgreSQL (Supabase), Pest 4, Redis/Horizon, Cloudflare R2 via `config('partna.media_disk')`.

**Spec:** `docs/superpowers/specs/2026-08-12-media-pool-slice-1b-design.md`. Read §2 (the three findings) and §3 (D1–D10) before starting. Every task below cites its decision.

## Global Constraints

- **Branch:** `feat/media-pool-slice-1b` off `development`. Never commit to `development` or `production` directly.
- **Never create Laravel migration files.** All schema changes are raw SQL under `supabase/migrations/`, one `CONCURRENTLY` statement per file. A composer guard rejects Laravel migrations.
- **Every outbound fetch goes through `App\Services\Http\SafeUrlFetcher`** (category B). An lh3 or Instagram CDN URL arrived in a third-party payload and is untrusted by definition. Adding a host allowlist entry is **not** the fix. Guarded by `tests/Feature/Architecture/OutboundHttpGuardTest.php`, which runs as its own CI job.
- **Authorization via Policies only.** Never `abort_unless($user->id === $x->user_id, 403)`. Use `$this->authorizeForUser($user, 'ability', $resource)`. CI fails the build on inline 403 aborts.
- **Never return raw Eloquent from an endpoint.** Resource classes for responses, Form Request classes for validation.
- **Tests run SQLite; production is Postgres.** Verify every constraint-bound write against the DDL in `supabase/migrations/`, not against a green suite. Live constraint values are in §6.3 of the spec.
- **`item_media_role_check` = `cover|gallery|poster|avatar|logo`.** Nothing else may be written to `content.item_media.role`.
- **`media_assets_variant_family_check` = `google|shopify|ytimg|native|proxy`, or NULL.**
- **`media_assets_dims_confidence_check` = `measured|declared|guessed`, or NULL.**
- **Cache invalidation is three lanes** on every raw-write seam: `BuildState::bump($siteId)`, touch `site.sites.updated_at`, and `CloudflareCachePurgeJob::dispatch($subdomain)`. Copy `MediaUploadBackfiller::invalidate()`. **Do not copy `PoolController::poolChanged()`** — it runs two lanes on purpose. There is **no CI check** for this; assert it directly in tests.
- **Every migration service is production code** under `app/Services/Migration/` with an artisan command, `--dry-run`, idempotent, re-runnable, counts reported (convergence invariant #4).
- **No unit is done without a live dev assertion** — SQL and its output pasted into the checkpoint (invariant #1).
- **Real logs live in Laravel Cloud.** `cloud env:logs partna development --minutes 10`. Never `mcp__laravel-boost__read-log-entries`.
- **Do not run two `scripts/audit/audit.sh` at once.** Not needed by this plan, noted because the repo enforces it.
- **`git stash` is forbidden** in this worktree — a peer session shares the checkout.

---

## Task 0: Precondition — run 1a's commands against dev

> **SATISFIED 2026-08-12.** Verified against dev (`glncumufgaqcmqhzwrxm`) after the
> commands were run. Evidence below; steps 1–5 ticked on that basis. **Step 6
> (cut the branch) is still open.**
>
> ```
> content.items kind='media'                 : 45   (was 20)   ✅
> content.media_assets site_media_id NOT NULL: 25   (was 0)    ✅
> pool:media carrying latest_per_auto_source : 0    (was 10)   ✅
> manual-coord media source_items            : 25              ✅
> content.media_assets total                 : 526  (was 501)  ✅ = 501 + 25, no duplicates minted
> content.media_assets.attribution           : absent — Task 1's job, correctly not yet present
> ```
>
> The total is the load-bearing number: 526 is exactly 501 + 25, so the backfill
> minted one asset per upload and zero duplicates. That is what 1a's fingerprint
> inversion existed to guarantee, now observed rather than assumed.

1a's code and schema landed (`d45e6bcbc`), but its two commands had **not been run** when this plan was written. Convergence invariant #6: registration is not execution. Task 9 migrates the three upload *selections* on the premise that 1a already gave those items a home, and Task 11's regression asserts 25 upload items survive — neither was true until this ran.

**Files:** none — operational task.

**Interfaces:**
- Consumes: `content:backfill-upload-media` and `content:reshape-media-sections`, both merged in 1a.
- Produces: a dev database where `content.items kind='media'` = 45, `content.media_assets.site_media_id IS NOT NULL` = 25, and zero `pool:media` sections carry `latest_per_auto_source`.

- [x] **Step 1: Confirm the code gate on the branch you are about to build on**

Run:
```bash
git checkout development && git pull --ff-only origin development
grep -n 'fingerprint = \$ref ?? \$url' app/Ingest/Projection/ProjectionWriter.php
```
Expected: one hit at approximately line 1166.

**If this shows `$url ?? $ref`, STOP.** 1a has not landed and Task 5 will silently re-key the twenty existing Google assets, mint duplicates through `resolveMediaAssets()`'s `insertOrIgnore`, and leave `content.item_media.asset_id` pointing at the orphans. Report the gate failure and go no further.

- [x] **Step 2: Confirm the column exists on dev**

Run against dev (`glncumufgaqcmqhzwrxm`):
```sql
SELECT count(*) FROM information_schema.columns
WHERE table_schema='content' AND table_name='media_assets' AND column_name='site_media_id';
```
Expected: `1`.

- [x] **Step 3: Dry-run both 1a commands**

```bash
cloud command:run development "php artisan content:backfill-upload-media --dry-run"
cloud command:run development "php artisan content:reshape-media-sections --dry-run"
```
Expected: the backfiller reports 25 eligible (16 gallery + 9 content); the reshaper reports 10 sections to change. Paste both outputs into the checkpoint.

- [x] **Step 4: Run both for real**

```bash
cloud command:run development "php artisan content:backfill-upload-media"
cloud command:run development "php artisan content:reshape-media-sections"
```

- [x] **Step 5: Assert the post-state on dev**

```sql
SELECT (SELECT count(*) FROM content.items WHERE kind='media' AND removed_at IS NULL) AS media_items,
       (SELECT count(*) FROM content.media_assets WHERE site_media_id IS NOT NULL) AS upload_assets,
       (SELECT count(*) FROM site.sections WHERE kind='collection'
          AND rule::text LIKE '%latest_per_auto_source%' AND rule::text LIKE '%"media"%') AS bad_rule;
```
Expected: `media_items=45, upload_assets=25, bad_rule=0`.

**If `media_items` is not 45, stop and reconcile before continuing.** The most likely cause is uploads in a non-`ready` `processing_state`, which the backfiller counts as skipped rather than dropping silently — read its output, do not guess.

- [x] **Step 6: Create the branch**

Cut as an isolated worktree rather than in the shared checkout, per convergence
§4.3 rule 6 ("one worktree per session; never the main checkout"), which overrides
this plan's Global Constraints wording:

```bash
git worktree add .worktrees/media-pool-slice-1b origin/development -b feat/media-pool-slice-1b
```

Based on `origin/development` explicitly (the repo's default branch is
`production`, so a native worktree tool would pick the wrong base), with a real
copied `vendor/` and `.env` — symlinked ones break the Feature suite's
`TestCase` binding. Verified the autoloader resolves `App\…` to the worktree's
own `app/` before trusting any green run.

> **Prod check — BLOCKED, closed as a deferral (owner decision 2026-08-12).**
> The execute prompt asked for a prod-side confirmation that no
> `content.media_assets` row is keyed off a URL for a ref-emitting projector.
> It cannot be run: prod Supabase MCP SQL fails `28P01` for both `postgres` and
> `supabase_read_only_user`, and `cloud tinker production` reports the
> environment is stopped. Closed on the architecture instead — prod last
> deployed `265f9aa` (2026-07-26, pre-1a), has never had the content-pool
> migrations applied, and carries no users, so there is no row to be keyed
> wrongly. Re-check on the next prod deploy cycle, and fix the MCP credential.

---

## Task 1: `content.media_assets.attribution`

**Decision:** D6. Google's terms require attribution display; `mapPhoto()` collects author names today and `GoogleBusinessMediaProjector` discards them.

**Files:**
- Create: `supabase/migrations/20260813000000_content_media_assets_attribution.sql`
- Test: `tests/Schema/MediaAssetAttributionColumnTest.php`

**Interfaces:**
- Produces: `content.media_assets.attribution` — `jsonb NULL`, no DB default. Shape `{"authors":[{"name":string,"uri":string|null}],"maps_uri":string|null,"flag_uri":string|null}`. Every key optional; the whole column is NULL when Google returned no attribution at all.

- [x] **Step 1: Write the migration**

Create `supabase/migrations/20260813000000_content_media_assets_attribution.sql`:

```sql
-- Slice 1b D6: Google Places terms require photo attribution on display.
-- GoogleBusinessConnector::mapPhoto() collects authorAttributions and the
-- projector currently discards them; this is where they land.
--
-- NULLABLE with no default, deliberately: only ~60 of 110 live Google photo
-- entries carry attribution at all, so "absent" is a real and expected state,
-- not a backfill gap. jsonb rather than columns because the shape is Google's
-- and may gain keys (googleMapsUri, flagContentUri) without a migration.
ALTER TABLE content.media_assets ADD COLUMN attribution jsonb NULL;
```

- [x] **Step 2: Apply to dev and verify**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
supabase db push
```

Then:
```sql
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema='content' AND table_name='media_assets' AND column_name='attribution';
```
Expected: one row, `jsonb`, `YES`, `null`.

- [x] **Step 3: Write the schema test**

> **CORRECTED during execution.** The test as drafted read the migration *file
> text* with `File::get`. Two things were wrong with that, both checkable:
>
> 1. **It asserted the wrong thing.** A migration file saying `jsonb NULL`
>    proves nothing about the deployed column; only the applied schema does.
>    Every other test in `tests/Schema/` probes `information_schema` /
>    `pg_constraint` (`ModerationStateColumnTest`, `SectorSourceCheckTest`) —
>    that is the house idiom and it is the stronger assertion.
> 2. **It would never have run.** `phpunit.xml` registers only `tests/Unit` and
>    `tests/Feature`. `tests/Schema/` runs solely under `composer test:schema`
>    (`phpunit.schema.xml`), so a file-text test placed there is gated behind a
>    Postgres connection it does not use, and is invisible to `composer test`.
>
> Written instead as an applied-schema probe in the repo's idiom
> (`uses(SchemaTestCase::class)`, `->group('postgres')`), asserting
> `data_type='jsonb'`, `is_nullable='YES'` and `column_default IS NULL`. The
> no-default rationale from the original draft is preserved as its comment.

Create `tests/Schema/MediaAssetAttributionColumnTest.php` querying
`information_schema.columns` for `content.media_assets.attribution`.

- [x] **Step 4: Run it**

Runs in the applied-schema lane, not `composer test`. Verified directly against
dev instead, which is the same assertion the test makes:

```
column_name  | data_type | is_nullable | column_default
attribution  | jsonb     | YES         | null
```

- [x] **Step 5: Commit**

```bash
git add supabase/migrations/20260813000000_content_media_assets_attribution.sql tests/Schema/MediaAssetAttributionColumnTest.php
git commit -m "feat(media): content.media_assets.attribution — Places terms require it on display"
```

---

## Task 2: `mapPhoto()` carries attribution

**Decision:** D6.

**Files:**
- Modify: `app/Ingest/Connectors/GoogleBusinessConnector.php:256-277` (`mapPhoto`)
- Test: `tests/Unit/Ingest/Connectors/GoogleBusinessPhotoAttributionTest.php`

**Interfaces:**
- Consumes: the raw Places photo shape returned by `PlacesDetailsDriver` — `{name, widthPx, heightPx, authorAttributions:[{displayName, uri, photoUri}], googleMapsUri, flagContentUri}`.
- Produces: `mapPhoto()` returns an added `attribution` key of the Task 1 shape, or omits the key entirely when Google supplied nothing. Consumed by Task 3.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/Ingest/Connectors/GoogleBusinessPhotoAttributionTest.php`:

```php
<?php

use App\Ingest\Connectors\GoogleBusinessConnector;

/** mapPhoto is private; drive it through the media stream the way pull() does. */
function mapPhotoViaReflection(array $photo): ?array
{
    $method = new ReflectionMethod(GoogleBusinessConnector::class, 'mapPhoto');

    return $method->invoke(new GoogleBusinessConnector, $photo);
}

it('carries author name, author uri, maps uri and flag uri', function () {
    $result = mapPhotoViaReflection([
        'name' => 'places/ChIJtest/photos/AWCwydtoken',
        'widthPx' => 4032,
        'heightPx' => 3024,
        'authorAttributions' => [
            ['displayName' => 'Jo Rivera', 'uri' => 'https://maps.google.com/maps/contrib/1234'],
        ],
        'googleMapsUri' => 'https://maps.google.com/photo/abc',
        'flagContentUri' => 'https://maps.google.com/flag/abc',
    ]);

    expect($result['attribution'])->toBe([
        'authors' => [['name' => 'Jo Rivera', 'uri' => 'https://maps.google.com/maps/contrib/1234']],
        'maps_uri' => 'https://maps.google.com/photo/abc',
        'flag_uri' => 'https://maps.google.com/flag/abc',
    ]);
});

it('omits attribution entirely when Google supplied none', function () {
    // D6's known gap: ~50 of 110 live photos carry no authors. Absent must stay
    // absent — an empty object would render as a credit with no name in it.
    $result = mapPhotoViaReflection([
        'name' => 'places/ChIJtest/photos/AWCwydtoken',
        'widthPx' => 800,
        'heightPx' => 600,
    ]);

    expect($result)->not->toHaveKey('attribution');
});

it('keeps an author whose uri is missing', function () {
    $result = mapPhotoViaReflection([
        'name' => 'places/ChIJtest/photos/AWCwydtoken',
        'authorAttributions' => [['displayName' => 'Sam Okafor']],
    ]);

    expect($result['attribution']['authors'])->toBe([['name' => 'Sam Okafor', 'uri' => null]]);
});
```

- [x] **Step 2: Run it to verify it fails**

Observed: 3 failed, 2 passed. The two that passed are the "omits" cases, which pass trivially today — kept as over-emission guards.

- [x] **Step 3: Implement**

Replace `mapPhoto()` in `app/Ingest/Connectors/GoogleBusinessConnector.php`:

```php
    /** @return array<string, mixed>|null */
    private function mapPhoto(mixed $photo): ?array
    {
        if (! is_array($photo)) {
            return null;
        }
        $ref = is_string($photo['name'] ?? null) ? $photo['name'] : '';
        if ($ref === '') {
            return null;
        }

        $authors = array_values(array_filter(array_map(
            static fn ($a) => is_array($a) && is_string($a['displayName'] ?? null)
                ? ['name' => $a['displayName'], 'uri' => is_string($a['uri'] ?? null) ? $a['uri'] : null]
                : null,
            (array) ($photo['authorAttributions'] ?? []),
        )));

        // D6: Places terms require crediting the author and linking back to the
        // photo on Maps. Absent stays absent — Google supplies attribution for
        // only about half of the photos it returns, and an empty credit block
        // reads as a bug rather than as missing vendor data.
        $attribution = array_filter([
            'authors' => $authors !== [] ? $authors : null,
            'maps_uri' => is_string($photo['googleMapsUri'] ?? null) ? $photo['googleMapsUri'] : null,
            'flag_uri' => is_string($photo['flagContentUri'] ?? null) ? $photo['flagContentUri'] : null,
        ], static fn ($v) => $v !== null);

        return array_filter([
            'ref' => $ref,
            'url' => is_string($photo['url'] ?? null) && $photo['url'] !== '' ? $photo['url'] : null,
            'width_px' => $photo['widthPx'] ?? null,
            'height_px' => $photo['heightPx'] ?? null,
            'attribution' => $attribution !== [] ? $attribution : null,
        ], static fn ($v) => $v !== null && $v !== []);
    }
```

Note the `url` key: Task 5 populates it on the raw photo entry. Reading it here now means Task 5 is a driver change only.

- [x] **Step 4: Run tests to verify they pass**

5 passed. `tests/Feature/Ingest` + `tests/Unit/Ingest` → 445 passed, after updating `GoogleBusinessConnectorTest:155`, which pinned the old flat `authors` contract.

- [x] **Step 5: Verify the redaction manifest did not widen**

`GoogleBusinessConnector::manifest()` declares `redactions: ['author','author_uri','author_photo']` with `when_unclaimed` scopes. Those cover **reviewer** PII on the `reviews` stream, not photographer credits on `media`. Verified unchanged: `tests/Feature/Ingest --filter=Redaction` → 2 passed.

> **THE MANIFEST IS NOT THE ONLY REDACTION REGISTRY — spec D6 checked the wrong
> one. Owner decision taken 2026-08-12; recorded here because it has legal weight.**
>
> `app/Services/Platforms/ThirdPartyPii.php:38` carries a **second, structural**
> rule: `NESTED_KEYS = ['photos' => ['authors']]`, applied at two read
> boundaries — `PublicIntegrationConnectionResource:436` and
> `DsarPayloadFilter:192`. Its docblock names this connector explicitly, and
> justifies stripping the credits with:
>
> > "no attribution obligation attaches — the Places terms require attribution
> > on DISPLAYED reviews and photos, and **photo refs are not yet resolved to
> > images**."
>
> **Task 5 is precisely what makes that false.** Once refs resolve to servable
> urls and `frames[]` renders them, the photos are displayed and the obligation
> attaches. So D6's "the redaction list is not silently widened" was true of the
> manifest and blind to this.
>
> **Resolution — split by surface, following the two obligations rather than
> making the lanes agree:**
>
> | Surface | Credit | Why |
> |---|---|---|
> | Public render (`frames[]`) | **carried** | Places terms require it wherever the photo is displayed |
> | DSAR export | **stripped** | Article 15 is the subject's own data; a contributor is a third party |
> | Legacy integration payload | **stripped** | nothing renders it, so no obligation attaches — original reasoning stands |
>
> **No change to the legacy lane** — it already strips and keeps stripping. The
> only code change is `ThirdPartyPii`'s docblock, corrected in place so the next
> reader does not "fix" the asymmetry in either direction.
>
> **Checked, so it needs no further work:** attribution reaches DSAR by no route
> today. `streamContentSourceItems()` selects an explicit column list with no
> doc column, and `content.media_assets` is not an export section at all — it is
> absent from `DataExportPayloadBuilder::COVERED_PII_TABLES` (`:90-106`) because
> until now it held no PII. Whether the owner's own media catalogue *should* be
> exported is a pre-existing Article 15 gap, not one 1b opens; flagged, not fixed.

- [x] **Step 6: Commit**

```bash
git add app/Ingest/Connectors/GoogleBusinessConnector.php tests/Unit/Ingest/Connectors/GoogleBusinessPhotoAttributionTest.php
git commit -m "feat(ingest): mapPhoto carries photographer attribution and the resolved url"
```

---

## Task 3: The media projector stops discarding attribution

**Decision:** D6, D7.

**Files:**
- Modify: `app/Ingest/Projection/GoogleBusinessMediaProjector.php`
- Test: `tests/Unit/Ingest/Projection/GoogleBusinessMediaProjectorTest.php`

**Interfaces:**
- Consumes: Task 2's `mapPhoto()` output, arriving as a `RecordView`.
- Produces: a media entry carrying `role`, `ref`, `url`, `width`, `height`, `attribution`. `headline` stays `null` (D7). Consumed by Task 4.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/Ingest/Projection/GoogleBusinessMediaProjectorTest.php`:

```php
<?php

use App\Ingest\Projection\GoogleBusinessMediaProjector;
use App\Ingest\Projection\RecordView;

it('projects url and attribution onto the media entry', function () {
    $projected = (new GoogleBusinessMediaProjector)->project(new RecordView([
        'ref' => 'places/ChIJtest/photos/AWCwydtoken',
        'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest=s4800-w1200',
        'width_px' => 4032,
        'height_px' => 3024,
        'attribution' => ['authors' => [['name' => 'Jo Rivera', 'uri' => null]]],
    ]));

    expect($projected['media'][0]['url'])->toBe('https://lh3.googleusercontent.com/place-photos/AG9NLjtest=s4800-w1200')
        ->and($projected['media'][0]['ref'])->toBe('places/ChIJtest/photos/AWCwydtoken')
        ->and($projected['media'][0]['attribution']['authors'][0]['name'])->toBe('Jo Rivera')
        ->and($projected['media'][0]['role'])->toBe('gallery');
});

it('leaves the headline null by contract', function () {
    // D7: a photo does not need a headline. Asserted so a later "fix" cannot
    // quietly reintroduce a synthetic one.
    $projected = (new GoogleBusinessMediaProjector)->project(new RecordView([
        'ref' => 'places/ChIJtest/photos/AWCwydtoken',
    ]));

    expect($projected['headline'])->toBeNull();
});

it('projects without url or attribution when Google supplied neither', function () {
    $projected = (new GoogleBusinessMediaProjector)->project(new RecordView([
        'ref' => 'places/ChIJtest/photos/AWCwydtoken',
    ]));

    expect($projected['media'][0])->not->toHaveKey('url')
        ->and($projected['media'][0])->not->toHaveKey('attribution');
});
```

- [x] **Step 2: Run it to verify it fails**

Observed: 2 failed, 3 passed — the missing `url` key and the un-bumped `version()`.

- [x] **Step 3: Implement**

Replace `project()` in `app/Ingest/Projection/GoogleBusinessMediaProjector.php`, and update the class docblock, which currently claims the projector emits no URL:

```php
/**
 * Places photo → the `media` item kind.
 *
 * Slice 1b D1/D2: the photo is BORROWED, not owned. Google's terms grant photos
 * no caching exemption, the resolved lh3 url dies at ~30 days, and the photo
 * `ref` is reissued on every Details fetch — so this asset is displayed live and
 * re-resolved inside the window, never mirrored and never pinned. The url is
 * resolved in the SAME billed fetch as the ref (PlacesDetailsDriver), because
 * refs and urls are only consistent within one fetch.
 *
 * The headline stays null by contract (D7): a photo does not need one.
 */
class GoogleBusinessMediaProjector implements Projector
{
    public static function version(): int
    {
        // Bumped for slice 1b: the media entry gained url + attribution.
        return 2;
    }

    public static function kind(): string
    {
        return 'media';
    }

    public function project(RecordView $view): ?array
    {
        $ref = $view->string('ref');
        if ($ref === null) {
            return null;
        }

        return [
            'kind' => self::kind(),
            'headline' => null,
            'facets' => [],
            'media' => [array_filter([
                'role' => 'gallery',
                'ref' => $ref,
                'url' => $view->string('url'),
                'width' => $view->int('width_px'),
                'height' => $view->int('height_px'),
                'attribution' => $view->array('attribution'),
            ], static fn ($v) => $v !== null && $v !== [])],
        ];
    }
}
```

**RESOLVED — use `map()`, and add no accessor.** `RecordView` already exposes
`map(string $path): array<string,mixed>` (`:74`), which returns `[]` when the path
is absent — exactly the semantics the `array_filter` below needs. Use
`$view->map('attribution')`, not `$view->array(...)`. Adding an `array()` accessor
would be a second name for `map()`.

Also checked, because `RecordView` instruments every read: `reads()` feeds
`ingest:volatility-audit`, which fails CI when a projector reads a path declared
volatile. All three `GoogleBusinessConnector` streams declare `volatile: []`
(`:76`, `:85`, `:95`), so the new `url` / `attribution` reads add no such conflict.

The `version()` bump to 2 is load-bearing: `content.source_items.projector_version` records which shape produced a row, and leaving it at 1 makes rows written before and after this task indistinguishable.

- [x] **Step 4: Run tests to verify they pass**

5 passed. `tests/Feature/Ingest` + `tests/Unit/Ingest` → 450 passed.

Added a case the plan did not list: the pre-1b projector's reason for existing was that it emitted no keyed url, pinned in `ProjectionTest.php` by `json_encode($projected)` not containing `key=`. Now that a url IS emitted, that guard is restated in this file against a populated url — the resolved lh3 link is unkeyed, so an api key still never reaches a content row.

- [x] **Step 5: Commit**

```bash
git add app/Ingest/Projection/GoogleBusinessMediaProjector.php tests/Unit/Ingest/Projection/GoogleBusinessMediaProjectorTest.php
git commit -m "feat(ingest): google media projector carries url + attribution, headline stays null by contract"
```

---

## Task 4: `resolveMediaAssets()` writes attribution

**Decision:** D6. This is hot code on every projection run for every connector — it gets its own task and its own review.

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php:1219-1240` (the `$rows[] = [...]` block inside `resolveMediaAssets`)
- Test: `tests/Feature/Ingest/Projection/MediaAssetAttributionWriteTest.php`

**Interfaces:**
- Consumes: Task 3's media entry.
- Produces: `content.media_assets.attribution` populated on mint. **Mint only** — `resolveMediaAssets()` never updates an existing row, and that is correct here: Google refs rotate, so every run mints fresh rows anyway.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Ingest/MediaAssetAttributionWriteTest.php` — flat, NOT under a new `Projection/` subdirectory. A new directory under `tests/Feature/` must be wired into the audit pipeline's `codebase_chunks()` (CLAUDE.md), and the existing ingest tests are flat anyway.:

```php
<?php

use Illuminate\Support\Facades\DB;

it('writes attribution as jsonb on a freshly minted asset', function () {
    $user = createUserWithSite();

    projectMediaEntries($user->id, [[
        'role' => 'gallery',
        'ref' => 'places/ChIJtest/photos/AWCwydtoken',
        'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest',
        'attribution' => ['authors' => [['name' => 'Jo Rivera', 'uri' => null]], 'maps_uri' => 'https://maps.google.com/p/1'],
    ]]);

    $row = DB::table('content.media_assets')
        ->where('user_id', $user->id)
        ->where('fingerprint', 'url-'.sha1('places/ChIJtest/photos/AWCwydtoken'))
        ->first();

    expect($row)->not->toBeNull()
        ->and(json_decode($row->attribution, true)['authors'][0]['name'])->toBe('Jo Rivera')
        ->and(json_decode($row->attribution, true)['maps_uri'])->toBe('https://maps.google.com/p/1');
});

it('leaves attribution null when the entry carries none', function () {
    $user = createUserWithSite();

    projectMediaEntries($user->id, [[
        'role' => 'gallery',
        'ref' => 'places/ChIJtest/photos/AWCwydnoattr',
    ]]);

    $row = DB::table('content.media_assets')
        ->where('user_id', $user->id)
        ->where('fingerprint', 'url-'.sha1('places/ChIJtest/photos/AWCwydnoattr'))
        ->first();

    expect($row->attribution)->toBeNull();
});

it('does not disturb the upload shape 1a established', function () {
    // Regression guard: this task edits the same insert array 1a's upload branch
    // writes. An upload must still mint with site_media_id, measured dims and a
    // null source_url — and now a null attribution.
    $user = createUserWithSite();
    $siteMediaId = createReadySiteMediaWithWebpVariant($user, width: 1200, height: 800);

    projectMediaEntries($user->id, [[
        'role' => 'gallery',
        'site_media_id' => $siteMediaId,
        'width' => 1200,
        'height' => 800,
        'mime_type' => 'image/webp',
    ]]);

    $row = DB::table('content.media_assets')
        ->where('user_id', $user->id)
        ->where('fingerprint', 'url-'.sha1('upload:'.$siteMediaId))
        ->first();

    expect($row->site_media_id)->toBe($siteMediaId)
        ->and($row->dims_confidence)->toBe('measured')
        ->and($row->source_url)->toBeNull()
        ->and($row->attribution)->toBeNull();
});
```

> **CORRECTED — none of those helpers exist.** `createUserWithSite()`,
> `createReadySiteMediaWithWebpVariant()` and `projectMediaEntries()` return
> nothing from a grep of `tests/`. 1a did not add them. What it actually used,
> and what this task follows, is in `tests/Feature/Ingest/UploadMediaEntryTest.php`:
>
> - `beforeEach`: `setupUsersTable()`, `setupSitesTable()`, `setupIngestTables()`, `setupContentTables()`
> - a user via `createTenant('prefix-'.Str::lower(Str::random(6)))->id`
> - projection driven directly through `app(ProjectionWriter::class)->writeManualItem($userId, $coord, [...])` — there is no `projectMediaEntries()` wrapper and none is needed
> - assertions straight onto `DB::table('content.media_assets')`
>
> The duplicate-global warning still stands and is why `uploadEntry()` (already
> global in `UploadMediaEntryTest.php`) is not redefined here.

**Three schema copies must move together with this column.** The insert now
carries `attribution`, so every stand-in table the write touches needs it or the
tests fail on `Undefined property` rather than on the assertion:

1. `tests/Pest.php:2455` — the SQLite stand-in.
2. `tests/Postgres/ProjectionWriterBatchingTest.php` — its own hand-written DDL.
3. `tests/Postgres/ProjectionIdentityKeyAtomicityTest.php` — likewise.

`tests/Postgres/BrandAssetPipelineTest.php` and `MediaAssetSiteMediaFkTest.php`
also declare `content.media_assets`, but neither goes through
`resolveMediaAssets()` and both already omit `site_media_id`, so they are left
alone — these copies are deliberately "enough for the test", not replicas.

**Run the PG lane, it is not optional here.** SQLite will not catch a jsonb
insert problem:

```bash
docker exec supabase_db_Partna-Development psql -U postgres -c "CREATE DATABASE partna_test_1b"
PG_LANE_DISPOSABLE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=54322 \
  DB_DATABASE=partna_test_1b DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable \
  ./vendor/bin/pest -c phpunit.pg.xml tests/Postgres/ProjectionWriterBatchingTest.php tests/Postgres/ProjectionIdentityKeyAtomicityTest.php
```

`PG_LANE_DISPOSABLE=1` is required — without it every test silently SKIPS with
"Refusing to provision core.*/site.*", which reads as a pass. Result: 7 passed.

- [x] **Step 2: Run it to verify it fails**

First run failed 4/4 on `Undefined property: stdClass::$attribution` — the WRONG reason, the SQLite stand-in simply lacked the column. After adding it to `tests/Pest.php`, the run failed 1 and passed 3, which is the real red.

- [x] **Step 3: Implement**

In `app/Ingest/Projection/ProjectionWriter.php`, inside `resolveMediaAssets()`, add one key to the `$rows[]` array:

```php
            $rows[] = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'fingerprint' => $fingerprint,
                'source_url' => $url,
                'site_media_id' => $uploadSiteMediaId,
                'mime_type' => $isUpload ? ($entry['mime_type'] ?? null) : null,
                'width' => $entry['width'] ?? null,
                'height' => $entry['height'] ?? null,
                'dims_confidence' => $isUpload ? 'measured' : (isset($entry['width']) ? 'declared' : null),
                // Slice 1b D6. Mint-only, like every other column here: a Google
                // ref rotates every fetch, so a rotated photo arrives as a NEW
                // row and carries its own credit. There is no update path to
                // keep in sync.
                'attribution' => isset($entry['attribution']) && is_array($entry['attribution']) && $entry['attribution'] !== []
                    ? json_encode($entry['attribution'])
                    : null,
                'created_at' => now(),
            ];
```

- [x] **Step 4: Run the new tests and the full projection suite**

```bash
./vendor/bin/pest tests/Feature/Ingest/Projection/MediaAssetAttributionWriteTest.php
./vendor/bin/pest tests/Feature/Ingest tests/Unit/Ingest
```
Expected: PASS. The second command is the regression gate — this method runs for every connector, so a green new test alone proves nothing.

- [x] **Step 5: Commit**

4 passed; `tests/Feature/Ingest` + `tests/Unit/Ingest` + `tests/Feature/Content` → 598 passed; PG lane → 7 passed.

```bash
git add app/Ingest/Projection/ProjectionWriter.php tests/Feature/Ingest/MediaAssetAttributionWriteTest.php
git commit -m "feat(ingest): persist photo attribution on asset mint"
```

---

## Task 5: `PlacesDetailsDriver` resolves photo URLs in the same fetch

**Decision:** D2, D3. **This is the money task.** It adds billed calls and it is the one Task 0's code gate exists to protect.

**Files:**
- Modify: `app/Ingest/Runtime/Effects/PlacesDetailsDriver.php`
- Modify: `app/Ingest/Connectors/GoogleBusinessConnector.php` (`defaultIntervalSeconds` and the `media` StreamSpec)
- Test: `tests/Feature/Ingest/Effects/PlacesDetailsPhotoResolutionTest.php`

**Interfaces:**
- Consumes: `GoogleBusinessService::fetchPlaceDetailsRaw(string $placeId, string $userId)`, already called by this driver.
- Produces: the raw Places response with each `photos[]` entry carrying an added `url` key where resolution succeeded. Task 2's `mapPhoto()` reads it.

- [x] **Step 1: Read the existing resolution path before touching anything**

Run:
```bash
grep -n 'function resolvePhotoUrls\|function fetchPlaceDetailsRaw\|PHOTO_REF_PATTERN' app/Services/Platforms/GoogleBusinessService.php
```

`resolvePhotoUrls()` (~`:516`) already claims one `PlacesBudget` `photos` slot per photo **before** the pool fires, validates each ref against `PHOTO_REF_PATTERN`, and caps concurrency. **Reuse it. Do not write a second resolution path** — `PlacesBudgetGuardTest` enforces that the config key has a single origin, and a parallel path would be an unbudgeted billing route.

If `resolvePhotoUrls()` is private, add a narrowly-typed public method on `GoogleBusinessService` that delegates to it, rather than duplicating the body.

- [x] **Step 2: Write the failing test**

Create `tests/Feature/Ingest/PlacesDetailsPhotoResolutionTest.php` — flat, not under a new `Effects/` subdirectory (a new dir under `tests/Feature/` needs audit-pipeline wiring, and the sibling `PlacesDetailsDriverTest.php` is already flat):

```php
<?php

use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\PlacesDetailsDriver;

it('returns photos carrying resolved urls alongside their refs', function () {
    fakePlacesDetails([
        'displayName' => ['text' => 'Test Cafe'],
        'photos' => [
            ['name' => 'places/ChIJtest/photos/AWCwydone', 'widthPx' => 4032, 'heightPx' => 3024],
            ['name' => 'places/ChIJtest/photos/AWCwydtwo', 'widthPx' => 800, 'heightPx' => 600],
        ],
    ]);
    fakePlacesPhotoResolution([
        'places/ChIJtest/photos/AWCwydone' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjone',
        'places/ChIJtest/photos/AWCwydtwo' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtwo',
    ]);

    $result = app(PlacesDetailsDriver::class)->run(new BilledEffectContext(
        input: ['place_id' => 'ChIJtest'],
        userId: 'user-1',
    ));

    expect($result->data['photos'][0]['url'])->toBe('https://lh3.googleusercontent.com/place-photos/AG9NLjone')
        ->and($result->data['photos'][0]['name'])->toBe('places/ChIJtest/photos/AWCwydone')
        ->and($result->data['photos'][1]['url'])->toBe('https://lh3.googleusercontent.com/place-photos/AG9NLjtwo');
});

it('returns the photo without a url when resolution fails, and does not fail the effect', function () {
    // A photo without a servable url is an already-supported state (the frame is
    // omitted downstream). Failing the whole Details effect over one dead photo
    // would throw away the profile and reviews streams too.
    fakePlacesDetails([
        'displayName' => ['text' => 'Test Cafe'],
        'photos' => [['name' => 'places/ChIJtest/photos/AWCwydone']],
    ]);
    fakePlacesPhotoResolution([]);

    $result = app(PlacesDetailsDriver::class)->run(new BilledEffectContext(
        input: ['place_id' => 'ChIJtest'],
        userId: 'user-1',
    ));

    expect($result->outcome->value)->toBe('answered')
        ->and($result->data['photos'][0])->not->toHaveKey('url');
});

it('does not change the effect digest', function () {
    // D2: photo resolution rides inside the EXISTING api/places.details effect.
    // A new input key would split profile/reviews from media into two billed
    // Details calls at $25/1000 each — far more than the $7/1000 photo calls
    // it would be isolating.
    $before = effectDigestFor('api', 'places.details', ['place_id' => 'ChIJtest']);

    expect($before)->toBe(effectDigestFor('api', 'places.details', ['place_id' => 'ChIJtest']));
});
```

**Helpers:** none of `fakePlacesDetails()`, `fakePlacesPhotoResolution()` or
`effectDigestFor()` exist. Written locally in the test file, following
`tests/Feature/Platforms/PlaceDetailsRawFetchTest.php`'s idiom (a `beforeEach`
setting the Places config keys, plus a single `Http::fake()` closure that
branches on `/media` in the request url and echoes the ref back as a photoUri).

> **The digest test as drafted was vacuous** — it compared
> `effectDigestFor(...)` against a second call to `effectDigestFor(...)` with
> identical arguments, which is true regardless of the code under test.
> Replaced with the property that actually protects D2: run the driver and count
> requests **by endpoint**, asserting exactly ONE Details call and the photo
> calls landing on the media endpoint. Splitting media onto its own effect kind
> would surface as a second Details call, which is the regression the digest
> test was reaching for.
>
> `BilledEffectContext` also takes six required parameters
> (`kind`, `name`, `input`, `runId`, `sourceId`, `userId`), not the two the
> drafted test passed.

- [x] **Step 3: Run it to verify it fails**

Observed: 2 failed, 2 passed. The two that passed are the degradation cases (resolution failure, budget exhausted), which pass trivially before the change — kept because they are what stops a later version from failing the whole effect over one dead photo.

- [x] **Step 4: Implement the driver change**

In `app/Ingest/Runtime/Effects/PlacesDetailsDriver.php`, update the class docblock — it currently states the opposite of what this task does — and resolve photos on the success path:

```php
 * Returns the RAW Places response, not the mapped card payload: the connector
 * reads displayName.text, photos[].name and reviews[].authorAttribution, and its
 * when_unclaimed reviewer-PII redaction is declared over those exact keys.
 *
 * Slice 1b D2: photo refs ARE now resolved to servable urls, inside this same
 * effect. It is not an optimisation — a Places photo ref is reissued on every
 * Details fetch, so a ref and a url are only consistent within ONE fetch. There
 * is no join key between a ref stored yesterday and a url resolved today, which
 * is why the parent spec's "read urls from the legacy payload" recommendation is
 * not implementable. Cost: 10 photos ≈ $0.07 per run at $7/1000, claimed through
 * PlacesBudget's existing 'photos' SKU. Deliberately NOT a separate effect kind:
 * a distinct digest would split profile/reviews from media into two billed
 * Details calls at $25/1000.
```

Then in `run()`, replace the success arm:

```php
        if ($result->place !== null) {
            return BilledEffectResult::answered($this->withResolvedPhotoUrls($result->place, $ctx->userId));
        }
```

And add:

```php
    /**
     * Resolve each photo ref to a servable url through GoogleBusinessService's
     * existing path, so the PlacesBudget 'photos' claim, the PHOTO_REF_PATTERN
     * guard and the pooled-concurrency cap all still apply. A photo that fails
     * to resolve keeps its ref and gains no url — already a supported state.
     *
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>
     */
    private function withResolvedPhotoUrls(array $place, string $userId): array
    {
        $photos = $place['photos'] ?? null;
        if (! is_array($photos) || $photos === []) {
            return $place;
        }

        $place['photos'] = $this->places->resolveRawPhotoUrls($photos, $userId);

        return $place;
    }
```

Add `resolveRawPhotoUrls(array $photos, string $userId): array` to `GoogleBusinessService` — it maps the raw `name`-keyed shape onto the `ref`-keyed shape `resolvePhotoUrls()` expects, delegates, and maps the resolved urls back onto the raw entries by `name`. It must not duplicate the budget-claim or pattern-guard logic.

- [x] **Step 5: Set the media stream cadence to 7 days**

In `app/Ingest/Connectors/GoogleBusinessConnector.php`, the connector's `defaultIntervalSeconds: 172800` (48h) governs all three streams. Per D3 the `media` stream needs 7 days.

**RESOLVED — the second branch applies.** `StreamSpec::__construct` takes
`name`, `target`, `profile`, `requires`, `volatile`, `orderField`,
`authoritativeFields` and nothing else (`app/Ingest/Manifest/StreamSpec.php:22-30`).
There is **no per-stream interval**; cadence lives only on the manifest as
`defaultIntervalSeconds`, which governs all three streams at once.

So: do **not** add the concept here. Leave `defaultIntervalSeconds: 172800`
alone — dropping it to 7 days would also slow `profile` and `reviews`, and
raising it per-stream is a manifest change slice 6 would inherit mid-flight.
Set the cadence operationally instead, in Task 11 step 4:

```sql
UPDATE ingest.sources SET min_interval_secs = 604800 WHERE source_key = 'google_business';
```

Record in the checkpoint that per-stream cadence is unavailable, so D3's 7-day
figure is understood as a source-row setting rather than a manifest guarantee.

- [x] **Step 6: Run the tests**

```bash
./vendor/bin/pest tests/Feature/Ingest/Effects/PlacesDetailsPhotoResolutionTest.php
./vendor/bin/pest tests/Feature/Platforms --filter=Places
./vendor/bin/pest tests/Feature/Ingest
```
Expected: PASS. The Places suite matters — `PlacesBudgetGuardTest` fails the build if a second billing origin appeared.

- [x] **Step 7: Commit**

```bash
git add app/Ingest/Runtime/Effects/PlacesDetailsDriver.php app/Services/Platforms/GoogleBusinessService.php app/Ingest/Connectors/GoogleBusinessConnector.php tests/Feature/Ingest/Effects/PlacesDetailsPhotoResolutionTest.php
git commit -m "feat(ingest): resolve Places photo urls inside the Details effect

Refs rotate per fetch, so a ref and a url are only consistent within one
fetch — resolving elsewhere has no join key. Rides the existing digest to
avoid splitting one billed Details call into two."
```

---

## Task 6: Pins reject borrowed media

**Decision:** D5.

**Files:**
- Modify: `app/Http/Controllers/Api/Content/PoolController.php` (`select`)
- Create: `app/Site/Pools/BorrowedMedia.php`
- Test: `tests/Feature/Api/Content/PoolBorrowedMediaPinTest.php`

**Interfaces:**
- Produces: `BorrowedMedia::isBorrowed(Item $item): bool` — true when the item's `content.sources` row is a `connection` source whose `ingest.sources.source_key` is `google_business`. Used only by `PoolController::select()`.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Content/PoolBorrowedMediaPinTest.php`:

```php
<?php

it('rejects a pin on a google-sourced media item', function () {
    [$user, $site] = createUserWithSite();
    $item = createGoogleBusinessMediaItem($user);

    $this->withSupabaseJwt($user)
        ->postJson("/api/content/pools/media/selection/{$item->id}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'BORROWED_MEDIA_NOT_PINNABLE');
});

it('still surfaces that same item in the auto half', function () {
    // The point of D5 is that the photo stays VISIBLE. Only the promise of
    // permanence is withheld. A test that only asserted the 403 would pass on a
    // design that hid the photo entirely.
    [$user, $site] = createUserWithSite();
    $item = createGoogleBusinessMediaItem($user);

    $response = $this->withSupabaseJwt($user)->getJson('/api/content/pools/media');

    expect(collect($response->json('data.items'))->pluck('id'))->toContain((string) $item->id);
});

it('allows a pin on an upload-backed media item', function () {
    [$user, $site] = createUserWithSite();
    $item = createManualUploadMediaItem($user);

    $this->withSupabaseJwt($user)
        ->postJson("/api/content/pools/media/selection/{$item->id}")
        ->assertOk();
});

it('allows a pin on an instagram media item', function () {
    [$user, $site] = createUserWithSite();
    $item = createInstagramMediaItem($user);

    $this->withSupabaseJwt($user)
        ->postJson("/api/content/pools/media/selection/{$item->id}")
        ->assertOk();
});
```

> **CORRECTED — three things in this task's draft do not match the codebase.**
>
> 1. **`tests/Feature/Api/Content/` does not exist**, and neither does
>    `withSupabaseJwt()`. Pool tests live at `tests/Feature/Content/`, and the
>    auth helper is `actingAsUser($user)` (`tests/Pest.php:105`). Written as
>    `tests/Feature/Content/PoolBorrowedMediaPinTest.php`.
> 2. **There is no `error.code` in this API's error envelope.** Every arm of
>    `bootstrap/app.php`'s handler emits `{"message": …}` with a status. So the
>    drafted `assertJsonPath('error.code', 'BORROWED_MEDIA_NOT_PINNABLE')` could
>    never pass. Asserting status 403 plus the absence of a pin row instead.
> 3. **Route-level, not direct controller calls.** The sibling `PoolLaneTest`
>    calls `PoolController` methods directly with a bare `Request`; that pattern
>    hid three live bugs in one 2026-07-27 audit because it skips routing,
>    middleware and policy resolution. An authorization check verified that way
>    would prove nothing.
>
> **Extra case added:** a stranger hitting the same route still gets 404, not
> 403. The ownership check has to run BEFORE the borrowed test, or the 403
> becomes an enumeration oracle telling a prober which uuids are real Google
> media items.

- [x] **Step 2: Run it to verify it fails**

Observed: 1 failed (200 instead of 403), 4 passed. The four passing are what prove the fixture is sound rather than inert — uploads and Instagram DO pin, the item IS visible in the auto half, and a stranger DOES get 404.

- [x] **Step 3: Implement the predicate**

Join verified against dev before trusting it: returns **20**, matching the 20 Google media items in the spec.


Create `app/Site/Pools/BorrowedMedia.php`:

```php
<?php

namespace App\Site\Pools;

use App\Models\Core\Content\Item;
use Illuminate\Support\Facades\DB;

/**
 * Slice 1b D5. A pin promises the owner that a specific item stays where they
 * put it. For a Google Places photo we cannot keep that promise: the photo's
 * resource name is reissued on every Details fetch, so "this item" is a
 * different row a week later, and the underlying photo may leave the place's
 * set entirely.
 *
 * The photo is still shown — the pool's auto half surfaces it through the
 * kind_is(media) rule with no pin required. Only permanence is withheld.
 */
final class BorrowedMedia
{
    /** Source keys whose media may be displayed but never pinned. */
    private const BORROWED_SOURCE_KEYS = ['google_business'];

    public static function isBorrowed(Item $item): bool
    {
        if ($item->kind !== 'media') {
            return false;
        }

        return DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as s', 's.id', '=', 'si.source_id')
            ->join('ingest.sources as ing', 'ing.connection_id', '=', 's.connection_id')
            ->where('si.item_id', $item->id)
            ->whereNull('si.removed_at')
            ->whereIn('ing.source_key', self::BORROWED_SOURCE_KEYS)
            ->exists();
    }
}
```

**Verify the join before trusting it.** Run against dev:
```sql
SELECT count(*) FROM content.source_items si
JOIN content.sources s ON s.id = si.source_id
JOIN ingest.sources ing ON ing.connection_id = s.connection_id
JOIN content.items i ON i.id = si.item_id
WHERE i.kind='media' AND ing.source_key='google_business';
```
Expected: 20. If it returns 0, `content.sources.connection_id` and `ingest.sources.connection_id` do not refer to the same thing — inspect both and correct the join rather than the expectation.

- [x] **Step 4: Wire it into `select()`**

> **The inline `abort(403)` was NOT viable — took the fallback, as drafted.**
> `InlineAuthBypassGuardTest` scans all of `app/Http/Controllers` via
> `ControllerAbortScanner::inlineForbiddenAborts()` and flags **every** inline
> 403, with paren-depth tracking that catches wrapped and multi-line forms. It
> does not distinguish a capability restriction from an ownership check, so the
> drafted note ("not the pattern the guard targets") is wrong. It is also what
> CLAUDE.md mandates anyway.

Added `pin` to `ContentItemPolicy` and called it from `select()`:

```php
        $this->authorizeForUser($user, 'pin', $item);
```

The policy checks ownership first (`denyAsNotFound()` → 404), then borrowed
(`Response::deny(...)` → `AccessDeniedHttpException` → the handler's 403 arm,
`{"message": …}`). `Response::deny()` rather than `denyWithStatus(403, …)`
deliberately: the latter produces a bare `HttpException(403)`, which
`bootstrap/app.php` only special-cases for 404 and 423 and would fall through.

`deselect()` and `reorder()` are untouched — there is nothing to remove or order.

- [x] **Step 5: Run the tests**

5 passed. `tests/Feature/Architecture` 90 passed (InlineAuthBypassGuardTest included); `tests/Feature/Content` + `tests/Feature/Policies` 158 passed.

```bash
./vendor/bin/pest tests/Feature/Api/Content/PoolBorrowedMediaPinTest.php
./vendor/bin/pest tests/Feature/Api/Content
```
Expected: PASS.

- [x] **Step 6: Commit**

```bash
git add app/Site/Pools/BorrowedMedia.php app/Http/Controllers/Api/Content/PoolController.php app/Policies/ContentItemPolicy.php tests/Feature/Content/PoolBorrowedMediaPinTest.php
git commit -m "feat(pools): borrowed media is displayable but not pinnable"
```

---

## Task 7: The Instagram mirror

**Decision:** D8, D9. The largest task. Split the commit if it grows past one reviewable diff.

**Files:**
- Create: `app/Services/Media/MediaMirror.php`
- Modify: `app/Services/Brand/BrandAssetPipeline.php:187-222` (extract the fetch/encode/store path)
- Create: `tests/Feature/Media/MediaMirrorTest.php`

**Interfaces:**
- Consumes: `SafeUrlFetcher`, `config('partna.media_disk')`.
- Produces: `MediaMirror::mirror(string $userId, string $assetId, string $sourceUrl): bool` — fetches, re-encodes to webp, stores at a content-addressed path, and **updates the existing** `content.media_assets` row with `storage_path`, `mime_type`, measured `width`/`height`, `dims_confidence='measured'`, `variant_family='native'`. Never writes `fingerprint`. Returns false on any failure, leaving the row untouched.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Media/MediaMirrorTest.php`:

```php
<?php

use App\Services\Media\MediaMirror;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('writes storage_path onto the existing asset row without minting a second', function () {
    // D9: the fingerprint collision trap. BrandAssetPipeline keys on a bare
    // content hash; ProjectionWriter keys on url-sha1(...). A mirror that minted
    // its own row would leave two assets for one photo with item_media pointing
    // at one of them.
    Storage::fake('media');
    $user = createUserWithSite();
    $assetId = createProjectedAsset($user->id, fingerprint: 'url-'.sha1('instagram:ABC123:0'));
    fakeImageResponse('https://scontent.cdninstagram.com/v/photo.jpg', width: 1080, height: 1350);

    $before = DB::table('content.media_assets')->where('user_id', $user->id)->count();

    $ok = app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/photo.jpg');

    $row = DB::table('content.media_assets')->where('id', $assetId)->first();

    expect($ok)->toBeTrue()
        ->and(DB::table('content.media_assets')->where('user_id', $user->id)->count())->toBe($before)
        ->and($row->fingerprint)->toBe('url-'.sha1('instagram:ABC123:0'))
        ->and($row->storage_path)->not->toBeNull()
        ->and($row->dims_confidence)->toBe('measured')
        ->and($row->variant_family)->toBe('native')
        ->and($row->width)->toBe(1080)
        ->and($row->height)->toBe(1350);
});

it('content-addresses the path so changed bytes cannot overwrite in place', function () {
    // D8: InstagramConnectionSeeder:82 writes every refresh to
    // platforms/instagram/<connection_created_ts>/photo.jpg — one fixed path,
    // overwritten forever. This must not reproduce that.
    Storage::fake('media');
    $user = createUserWithSite();
    $assetA = createProjectedAsset($user->id, fingerprint: 'url-'.sha1('instagram:ABC123:0'));
    $assetB = createProjectedAsset($user->id, fingerprint: 'url-'.sha1('instagram:DEF456:0'));

    fakeImageResponse('https://scontent.cdninstagram.com/a.jpg', width: 100, height: 100, seed: 'aaa');
    fakeImageResponse('https://scontent.cdninstagram.com/b.jpg', width: 100, height: 100, seed: 'bbb');

    app(MediaMirror::class)->mirror($user->id, $assetA, 'https://scontent.cdninstagram.com/a.jpg');
    app(MediaMirror::class)->mirror($user->id, $assetB, 'https://scontent.cdninstagram.com/b.jpg');

    $paths = DB::table('content.media_assets')->whereIn('id', [$assetA, $assetB])->pluck('storage_path');

    expect($paths->unique())->toHaveCount(2);
});

it('is idempotent — mirroring the same bytes twice writes one object and one path', function () {
    Storage::fake('media');
    $user = createUserWithSite();
    $assetId = createProjectedAsset($user->id, fingerprint: 'url-'.sha1('instagram:ABC123:0'));
    fakeImageResponse('https://scontent.cdninstagram.com/v/photo.jpg', width: 640, height: 640, seed: 'same');

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/photo.jpg');
    $first = DB::table('content.media_assets')->where('id', $assetId)->value('storage_path');

    app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/v/photo.jpg');
    $second = DB::table('content.media_assets')->where('id', $assetId)->value('storage_path');

    expect($second)->toBe($first)
        ->and(Storage::disk('media')->allFiles())->toHaveCount(1);
});

it('leaves the row untouched when the fetch fails', function () {
    Storage::fake('media');
    $user = createUserWithSite();
    $assetId = createProjectedAsset($user->id, fingerprint: 'url-'.sha1('instagram:ABC123:0'));
    fakeFailedFetch('https://scontent.cdninstagram.com/gone.jpg');

    $ok = app(MediaMirror::class)->mirror($user->id, $assetId, 'https://scontent.cdninstagram.com/gone.jpg');

    $row = DB::table('content.media_assets')->where('id', $assetId)->first();

    expect($ok)->toBeFalse()
        ->and($row->storage_path)->toBeNull()
        ->and($row->variant_family)->toBeNull();
});
```

- [x] **Step 2: Run it to verify it fails**

Observed: 7 failed, class not found.

- [x] **Step 3: Read the donor before extracting from it**

```bash
sed -n '150,230p' app/Services/Brand/BrandAssetPipeline.php
```

`storeAsset()` fetches via `SafeUrlFetcher`, re-encodes to webp, puts to `config('partna.media_disk')`, and inserts with measured dims. **It has produced zero rows on dev** (0 of 501 assets carry `storage_path`) — it is built and unexercised, so treat its behaviour as unproven and cover the extracted path with this task's tests rather than trusting it.

- [x] **Step 4: Implement**

Create `app/Services/Media/MediaMirror.php`. Key requirements, each covered by a test above:

- Fetch through `SafeUrlFetcher` — category B. An Instagram CDN url arrived in a third-party payload.
- Re-encode to webp using the same encoder `BrandAssetPipeline` uses.
- Path: `content-media/{userId}/{sha256(bytes)|32}.webp`. Content-addressed, per D8.
- `UPDATE content.media_assets SET storage_path, mime_type, width, height, dims_confidence='measured', variant_family='native' WHERE id = ?`. **Never** touch `fingerprint`.
- Return `false` and leave the row untouched on any failure. Log at warning; do not throw into the projection run.
- Size-cap the download, matching `InstagramConnectionSeeder`'s existing cap, so a pathological file cannot fill the temp disk or R2.

Extract the shared encode body into `App\Services\Media\WebpEncoder`, injected
into both classes. `BrandAssetPipeline` keeps its own content-hash fingerprint
and its own insert — that is its `#PRIV-5` contract and this task does not
change it.

> **The max edge had to become a parameter, not stay a constant.**
> `BrandAssetPipeline::VARIANT_EDGE` is **512**, which is right for a logo and a
> visible quality regression for a gallery photo — the upload pipeline's own
> `optimized` tier allows **2400** (`config('partna.image_variants.optimized.width')`).
> Sharing the encoder verbatim, as drafted, would have silently downsampled every
> mirrored Instagram photo to 512px. `WebpEncoder::encode($body, $maxEdge)` takes
> the edge from the caller; the mirror reads it from that config key so the two
> cannot drift, and a test pins that a 1600px photo survives at 1600px.

Two failure modes are covered beyond the drafted list, because the encoder is a
sanitiser as much as a resizer: undecodable bytes behind an `image/*`
content-type are refused and store nothing, and the fingerprint is asserted
unchanged across a bytes change.

- [x] **Step 5: Run the tests**

7 passed. `OutboundHttpGuardTest` 5 passed (run explicitly — it has its own CI job because the Feature suite can abort first). `tests/Feature/Brand` + `tests/Feature/Media` 75 passed. PG lane `BrandAssetPipelineTest` 9 passed, so the donor is intact against real Postgres.

```bash
./vendor/bin/pest tests/Feature/Media/MediaMirrorTest.php
./vendor/bin/pest tests/Feature/Architecture/OutboundHttpGuardTest.php
./vendor/bin/pest tests/Feature/Brand
```
Expected: PASS. The outbound guard has its own CI job and the Feature suite can abort before reaching it — run it explicitly.

- [x] **Step 6: Commit**

```bash
git add app/Services/Media/MediaMirror.php app/Services/Brand/BrandAssetPipeline.php tests/Feature/Media/MediaMirrorTest.php
git commit -m "feat(media): MediaMirror — owned bytes onto the projection-minted asset row

Content-addressed paths, so a re-sync of changed bytes cannot overwrite a
url a user already picked (the InstagramConnectionSeeder:82 hazard)."
```

---

## Task 8: Instagram stream provisioning and mirror dispatch

**Decision:** D8. Depends on Task 7.

**Files:**
- Modify: `app/Ingest/Projection/ProjectionWriter.php` (dispatch the mirror after media projection)
- Create: `app/Jobs/Media/MirrorMediaAssetJob.php`
- Test: `tests/Feature/Ingest/InstagramMediaMirrorTest.php`

**Interfaces:**
- Consumes: `MediaMirror::mirror()` from Task 7.
- Produces: `MirrorMediaAssetJob::dispatch(string $userId, string $assetId, string $sourceUrl)`. `ShouldBeUnique` on `assetId`.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Ingest/InstagramMediaMirrorTest.php`. `Bus::fake()` belongs in `beforeEach`, not per test: `QUEUE_CONNECTION=sync` under `phpunit.xml`, so an unfaked dispatch runs `MediaMirror` INLINE and sends `SafeUrlFetcher` at a real CDN host — and `assertSafe()` does a genuine DNS lookup even under `Http::fake()`.:

```php
<?php

use App\Jobs\Media\MirrorMediaAssetJob;
use Illuminate\Support\Facades\Bus;

it('dispatches a mirror job for a newly minted instagram asset', function () {
    Bus::fake();
    $user = createUserWithSite();

    projectInstagramMedia($user->id, shortcode: 'ABC123', images: [
        'https://scontent.cdninstagram.com/v/one.jpg',
    ]);

    Bus::assertDispatched(MirrorMediaAssetJob::class);
});

it('does not dispatch a mirror for borrowed google media', function () {
    // D1: Google photos are never stored. A mirror dispatch here would be a
    // terms violation, not just wasted work.
    Bus::fake();
    $user = createUserWithSite();

    projectMediaEntries($user->id, [[
        'role' => 'gallery',
        'ref' => 'places/ChIJtest/photos/AWCwydtoken',
        'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest',
    ]]);

    Bus::assertNotDispatched(MirrorMediaAssetJob::class);
});

it('does not dispatch for an asset that already has storage_path', function () {
    Bus::fake();
    $user = createUserWithSite();

    projectInstagramMedia($user->id, shortcode: 'ABC123', images: ['https://scontent.cdninstagram.com/v/one.jpg']);
    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 1);

    markAssetsMirrored($user->id);
    projectInstagramMedia($user->id, shortcode: 'ABC123', images: ['https://scontent.cdninstagram.com/v/one.jpg']);

    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 1);
});

it('produces no duplicate assets across two consecutive syncs', function () {
    // The parent spec's headline proof, and the property that justifies D1's
    // split: instagram's ref is shortcode-stable, so a re-sync recognises the
    // asset it already minted. Google cannot satisfy this.
    $user = createUserWithSite();

    projectInstagramMedia($user->id, shortcode: 'ABC123', images: ['https://scontent.cdninstagram.com/v/one.jpg?oh=sig1']);
    $first = countAssets($user->id);

    projectInstagramMedia($user->id, shortcode: 'ABC123', images: ['https://scontent.cdninstagram.com/v/one.jpg?oh=sig2']);

    expect(countAssets($user->id))->toBe($first);
});
```

The last test is the one that matters most. The differing `oh=` query parameter is deliberate: `SecretParams::minimiseUrl()` strips `_nc_sid` via the `sid` entry in `SECRET_SEGMENTS` but does **not** strip `oh` / `oe` / `_nc_ohc`, so an Instagram url genuinely re-signs between syncs. After 1a's fingerprint inversion that no longer touches identity — this test is what proves it.

- [x] **Step 2: Run it to verify it fails**

Observed: 4 failed, 3 passed. The no-duplicate-across-two-syncs case passed immediately — that is 1a's fingerprint inversion already holding, which is exactly what it exists to guarantee.

- [x] **Step 3: Implement the job**

Create `app/Jobs/Media/MirrorMediaAssetJob.php`:

```php
<?php

namespace App\Jobs\Media;

use App\Services\Media\MediaMirror;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Slice 1b: fetch an owned-class media asset's bytes to R2 after projection.
 *
 * Deferred to a job rather than run inline because the projection run is on the
 * ingest hot path and a mirror is a network fetch plus an image re-encode.
 */
class MirrorMediaAssetJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Fire only after the projection transaction commits — the asset row must exist. */
    public bool $afterCommit = true;

    public function __construct(
        public readonly string $userId,
        public readonly string $assetId,
        public readonly string $sourceUrl,
    ) {}

    public function uniqueId(): string
    {
        return $this->assetId;
    }

    public function handle(MediaMirror $mirror): void
    {
        $mirror->mirror($this->userId, $this->assetId, $this->sourceUrl);
    }
}
```

**Two traps, both previously hit in this repo:**
- Dispatch with `MirrorMediaAssetJob::dispatch(...)`, never `Bus::dispatch(new MirrorMediaAssetJob(...))` — the latter silently drops `ShouldBeUnique`.

> **THE DRAFTED `$afterCommit` ADVICE IS THE FATAL, NOT THE FIX — corrected.**
> "a plain `public bool` property" is exactly the form that dies.
> `Illuminate\Bus\Queueable` already declares `public $afterCommit;` **untyped**
> (`vendor/laravel/framework/src/Illuminate/Bus/Queueable.php:57`), so any typed
> redeclaration is an incompatible trait composition and PHP fatals at
> CLASS-LOAD time.
>
> **It does not present as a red test.** The runner exits 2 with **zero bytes of
> output** — Pest cannot render an error for a class that never composed — and
> `--filter` narrows it only as far as "every test that touches the job". The
> message is recoverable with:
>
> ```bash
> php -d display_errors=stderr -d log_errors=1 -d error_log=/dev/stderr \
>   vendor/pestphp/pest/bin/pest <file> --filter="<case>"
> ```
>
> The repo's own working form is a constructor assignment, documented against
> this same conflict at `SendAccountDeletionRequestMailJob:72`:
>
> ```php
> $this->afterCommit = true;   // in __construct(), NOT a redeclared property
> ```

- [x] **Step 4: Dispatch from the projection lane**

In `ProjectionWriter`, after `resolveMediaAssets()` returns, dispatch a mirror for each asset that is **owned-class** and not yet mirrored:

```php
        // Owned-class only (D1): a Google photo is borrowed and must never be
        // stored. The discriminator is the ref namespace the projector minted —
        // an instagram ref is 'instagram:{shortcode}:{i}', a Places ref is
        // 'places/...'. site_media_id-backed uploads already own their bytes.
```

Gate on: `storage_path IS NULL`, `site_media_id IS NULL`, and a non-null `source_url`, plus an explicit owned-source check. Do not infer "owned" from the absence of Google — a future borrowed source would silently start mirroring. Add the source key to an explicit allowlist constant next to `BorrowedMedia::BORROWED_SOURCE_KEYS`.

- [x] **Step 5: Provision the Instagram stream on dev**

The `instagram` `ingest.sources` row exists with `auto_sync=false` and has never run.

```sql
SELECT id, source_key, identifier, auto_sync, last_run_at, min_interval_secs
FROM ingest.sources WHERE source_key='instagram';
```

Enable it in Task 11, not here — this step only confirms the row is present and records its id in the checkpoint.

> **There are TWO instagram sources on dev, not one.** The spec's §1 figure
> (`ingest.sources instagram : 1`) is stale. Verified 2026-08-13:
>
> | id | identifier | auto_sync | last_run_at | min_interval_secs |
> |---|---|---|---|---|
> | `f9b9fbc0-642d-4bcd-9352-89d75082a9e4` | `tobiasbalcombe` | false | null | 604800 |
> | `fde32633-18a3-4933-9ae5-4cb2ce4501e9` | `basette_barberia_` | false | null | 604800 |
>
> Both already sit at the connector's 7-day interval, so Task 11 only has to
> flip `auto_sync`. Task 11's no-duplicate proof should name WHICH handle it
> ran, since enabling both doubles the Apify spend.

- [x] **Step 6: Run the tests**

7 passed. Regression: `tests/Feature/Ingest` + `tests/Unit/Ingest` + `tests/Feature/Content` + `tests/Feature/Media` → 672 passed.

```bash
./vendor/bin/pest tests/Feature/Ingest/InstagramMediaMirrorTest.php
./vendor/bin/pest tests/Feature/Ingest tests/Unit/Ingest
```
Expected: PASS.

- [x] **Step 7: Commit**

```bash
git add app/Jobs/Media/MirrorMediaAssetJob.php app/Ingest/Projection/ProjectionWriter.php tests/Feature/Ingest/InstagramMediaMirrorTest.php
git commit -m "feat(ingest): mirror owned-class media bytes after projection"
```

---

## Task 9: `ContentSelectionMigrator`

**Decision:** D10.

**Files:**
- Create: `app/Services/Migration/ContentSelectionMigrator.php`
- Create: `app/Console/Commands/MigrateContentSelectionCommand.php`
- Test: `tests/Feature/Migration/ContentSelectionMigratorTest.php`

**Interfaces:**
- Produces: `ContentSelectionMigrator::run(bool $dryRun = false, ?string $siteId = null): array` returning `['migrated' => int, 'dropped_google' => int, 'dropped_ig' => int, 'skipped_no_item' => int, 'failed' => int]`.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Content/ContentSelectionMigratorTest.php`.

> **NOT `tests/Feature/Migration/`.** A new directory under `tests/Feature/`
> fails `AuditPipelineIntegrityTest` on two counts — "gets zero audit sweep
> coverage" and "read by NO lens in the full-sweep bundle" — and clearing it
> means editing `codebase_chunks()` in `scripts/audit/audit.sh` plus a lens
> scope-group, which is shared infrastructure other slices are mid-flight in.
> 1a put `MediaUploadBackfillerTest` in `tests/Feature/Content/` for the same
> reason; this follows that. Task 10's pruner test goes there too.:

```php
<?php

use App\Services\Migration\ContentSelectionMigrator;
use Illuminate\Support\Facades\DB;

it('migrates an upload selection to a pool pin', function () {
    [$user, $site] = createUserWithSite();
    $item = createManualUploadMediaItem($user);
    createContentSelection($site, entryType: 'upload', mediaId: $item->siteMediaId, position: 1);

    $result = app(ContentSelectionMigrator::class)->run();

    expect($result['migrated'])->toBe(1)
        ->and(DB::table('site.section_items')->where('item_id', $item->id)->where('state', 'pinned')->exists())->toBeTrue();
});

it('counts google selections as dropped and pins nothing', function () {
    // D10: auto-seeded by maybeSeedFromGoogle(), already resolving to nothing
    // because refs rotate, and under D5 there is no destination anyway.
    [$user, $site] = createUserWithSite();
    createContentSelection($site, entryType: 'google-photo', externalRef: 'places/ChIJx/photos/AWCwydold', position: 1);

    $result = app(ContentSelectionMigrator::class)->run();

    expect($result['dropped_google'])->toBe(1)
        ->and($result['migrated'])->toBe(0)
        ->and(DB::table('site.section_items')->count())->toBe(0);
});

it('counts ig selections as dropped — they carry no identifier to migrate by', function () {
    [$user, $site] = createUserWithSite();
    createContentSelection($site, entryType: 'ig-post', position: 1);
    createContentSelection($site, entryType: 'ig-reel', position: 2);

    $result = app(ContentSelectionMigrator::class)->run();

    expect($result['dropped_ig'])->toBe(2)
        ->and(DB::table('site.section_items')->count())->toBe(0);
});

it('never deletes a content_selection row', function () {
    // The migration is ADDITIVE. site.content_selection is dropped in slice 7,
    // not here — so a bad run is recoverable.
    [$user, $site] = createUserWithSite();
    createContentSelection($site, entryType: 'google-photo', externalRef: 'places/ChIJx/photos/AWCwydold', position: 1);

    app(ContentSelectionMigrator::class)->run();

    expect(DB::table('site.content_selection')->count())->toBe(1);
});

it('is idempotent across two runs', function () {
    [$user, $site] = createUserWithSite();
    $item = createManualUploadMediaItem($user);
    createContentSelection($site, entryType: 'upload', mediaId: $item->siteMediaId, position: 1);

    app(ContentSelectionMigrator::class)->run();
    app(ContentSelectionMigrator::class)->run();

    expect(DB::table('site.section_items')->where('item_id', $item->id)->count())->toBe(1);
});

it('writes nothing on a dry run', function () {
    [$user, $site] = createUserWithSite();
    $item = createManualUploadMediaItem($user);
    createContentSelection($site, entryType: 'upload', mediaId: $item->siteMediaId, position: 1);

    $result = app(ContentSelectionMigrator::class)->run(dryRun: true);

    expect($result['migrated'])->toBe(1)
        ->and(DB::table('site.section_items')->count())->toBe(0);
});

it('bumps all three cache lanes for a touched site', function () {
    // No CI check enforces this — BuildState's docblock claims one that does
    // not exist. Assert it directly.
    Bus::fake();
    [$user, $site] = createUserWithSite();
    $item = createManualUploadMediaItem($user);
    createContentSelection($site, entryType: 'upload', mediaId: $item->siteMediaId, position: 1);
    $before = DB::table('site.sites')->where('id', $site->id)->value('updated_at');

    app(ContentSelectionMigrator::class)->run();

    expect(DB::table('site.sites')->where('id', $site->id)->value('updated_at'))->not->toBe($before);
    Bus::assertDispatched(App\Jobs\Cloudflare\CloudflareCachePurgeJob::class);
});

it('skips an upload selection whose item was never backfilled, and counts it', function () {
    [$user, $site] = createUserWithSite();
    createContentSelection($site, entryType: 'upload', mediaId: (string) Str::uuid(), position: 1);

    $result = app(ContentSelectionMigrator::class)->run();

    expect($result['skipped_no_item'])->toBe(1)
        ->and($result['failed'])->toBe(0);
});
```

- [x] **Step 2: Run it to verify it fails**

Observed: 10 failed, class not found.

- [x] **Step 3: Implement**

Create `app/Services/Migration/ContentSelectionMigrator.php`, modelled closely on `MediaUploadBackfiller` — same constructor-injection style, same `run(bool $dryRun, ?string $siteId): array` shape, same `invalidate(array $siteIds)` three-lane method copied verbatim.

Behaviour:
- `entry_type='upload'` → find the media item whose `content.source_items.coord` is `manual:{media_id}`, pin it via `site.section_items` with `state='pinned'` and a `sort_key` derived from the selection's `position`. Missing item → `skipped_no_item`, counted, not fatal.
- `entry_type='google-photo'` → `dropped_google`, no write.
- `entry_type` in `ig-post`/`ig-reel`/`ig-photo` → `dropped_ig`, no write.
- Never deletes from `site.content_selection`.
- Idempotent: an existing pinned `section_items` row for the same item is left alone.

- [x] **Step 4: Implement the command**

Create `app/Console/Commands/MigrateContentSelectionCommand.php` with signature:

```php
    protected $signature = 'content:migrate-selection
                            {--dry-run : Report what would change without writing}
                            {--site= : Limit to one site id}';
```

It must print each count on its own line and, for dropped rows, print the affected `site_id` values — D10 requires the drop to be recorded with its site ids, and the command output is that record.

- [x] **Step 5: Run the tests**

10 passed (the drafted 8, plus two the plan did not list — see below). `tests/Feature/Content` + `tests/Feature/Console` → 316 passed.

- [x] **Step 6: Commit**

Two cases added beyond the drafted list:

- **Cache lanes are NOT touched for a site with only dropped rows.** Dropping changes no payload; purging the edge for all 86 would be a self-inflicted stampede across every Google-only site. The drafted plan bumped unconditionally.
- **Owner ordering survives.** `sort_key` carries the selection's `position`, so a curated gallery is not silently reshuffled.

Also: an unrecognised `entry_type` now throws rather than falling through. Swallowing an undecided type into a drop count is exactly the silent data loss D10 exists to prevent.

```bash
git add app/Services/Migration/ContentSelectionMigrator.php app/Console/Commands/MigrateContentSelectionCommand.php tests/Feature/Content/ContentSelectionMigratorTest.php
git commit -m "feat(migration): carry the 3 upload selections, record the 86 that cannot be carried"
```

---

## Task 10: The borrowed-asset prune

**Decision:** D4.

**Files:**
- Create: `app/Services/Migration/BorrowedAssetPruner.php`
- Create: `app/Console/Commands/PruneBorrowedAssetsCommand.php`
- Modify: `routes/console.php` (schedule it)
- Test: `tests/Feature/Migration/BorrowedAssetPrunerTest.php`

**Interfaces:**
- Produces: `BorrowedAssetPruner::run(bool $dryRun = false): array` returning `['pruned' => int, 'spared_owned' => int, 'spared_referenced' => int, 'spared_recent' => int]`.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Content/BorrowedAssetPrunerTest.php` (same reason as Task 9 — a new `tests/Feature/` dir fails `AuditPipelineIntegrityTest`):

```php
<?php

use App\Services\Migration\BorrowedAssetPruner;
use Illuminate\Support\Facades\DB;

it('prunes an unreferenced borrowed asset older than 30 days', function () {
    $user = createUserWithSite();
    $assetId = createProjectedAsset($user->id, fingerprint: 'url-'.sha1('places/x/photos/old'), createdAt: now()->subDays(31));

    $result = app(BorrowedAssetPruner::class)->run();

    expect($result['pruned'])->toBe(1)
        ->and(DB::table('content.media_assets')->where('id', $assetId)->exists())->toBeFalse();
});

it('spares an asset with storage_path — owned bytes are never pruned', function () {
    $user = createUserWithSite();
    $assetId = createProjectedAsset($user->id, fingerprint: 'url-'.sha1('instagram:ABC:0'), createdAt: now()->subDays(90), storagePath: 'content-media/x/abc.webp');

    $result = app(BorrowedAssetPruner::class)->run();

    expect($result['spared_owned'])->toBe(1)
        ->and(DB::table('content.media_assets')->where('id', $assetId)->exists())->toBeTrue();
});

it('spares an asset still referenced by a live item_media row', function () {
    $user = createUserWithSite();
    $assetId = createProjectedAsset($user->id, fingerprint: 'url-'.sha1('places/x/photos/live'), createdAt: now()->subDays(90));
    linkAssetToLiveItem($user, $assetId);

    $result = app(BorrowedAssetPruner::class)->run();

    expect($result['spared_referenced'])->toBe(1)
        ->and(DB::table('content.media_assets')->where('id', $assetId)->exists())->toBeTrue();
});

it('spares an asset younger than 30 days', function () {
    $user = createUserWithSite();
    $assetId = createProjectedAsset($user->id, fingerprint: 'url-'.sha1('places/x/photos/new'), createdAt: now()->subDays(29));

    $result = app(BorrowedAssetPruner::class)->run();

    expect($result['spared_recent'])->toBe(1)
        ->and(DB::table('content.media_assets')->where('id', $assetId)->exists())->toBeTrue();
});

it('writes nothing on a dry run', function () {
    $user = createUserWithSite();
    createProjectedAsset($user->id, fingerprint: 'url-'.sha1('places/x/photos/old'), createdAt: now()->subDays(31));

    $result = app(BorrowedAssetPruner::class)->run(dryRun: true);

    expect($result['pruned'])->toBe(1)
        ->and(DB::table('content.media_assets')->count())->toBe(1);
});
```

- [x] **Step 2: Run it to verify it fails**

Observed: 8 failed, class not found.

- [x] **Step 3: Implement**

Create `app/Services/Migration/BorrowedAssetPruner.php`. Delete
`content.media_assets` rows where **all FOUR** hold:

1. `storage_path IS NULL` — no mirrored bytes.
2. **`site_media_id IS NULL`** — not an owner's upload.
3. No live `content.item_media` row references it. `item_media_asset_id_fkey` is `ON DELETE SET NULL`, so a delete nulls a stale link rather than cascading into items — that is what makes this safe.
4. `created_at < now() - interval '30 days'` — past the url expiry, so the row is dead regardless.

> **CONDITION 2 IS NEW, AND THE PLAN'S THREE-CONDITION VERSION DELETES OWNER
> DATA.** An upload's bytes live in `site.media_variants`; its asset row is a
> POINTER into that pipeline rather than a snapshot of it (1a §3.2), so it
> legitimately carries **no `storage_path`**. Under the drafted conditions, any
> upload whose item had been tombstoned — the exact state `mergeInto()`
> produces — matches "storage_path IS NULL + unreferenced + old" and gets
> deleted. `storage_path IS NULL` does not mean "not ours". Covered by
> *"it spares an upload-backed asset even with no storage_path"*.

Chunk the delete. Report all four counts, computed separately rather than
derived, so an operator can see WHY a row survived instead of inferring it from
a shrinking total.

- [x] **Step 4: Schedule it**

Matched the surrounding entries rather than the drafted two-liner: `->onOneServer()`, `->withoutOverlapping(120)` and `->runInBackground()` alongside the `onFailure` hook, as `builds:prune-expired` does. A registration test pins it, so removing the schedule turns a silent no-op into a red test.

In `routes/console.php`, following the surrounding style and the existing `->onFailure($reportScheduledFailure(...))` pattern:

```php
Schedule::command('content:prune-borrowed-assets')
    ->dailyAt('03:50')
    ->onFailure($reportScheduledFailure('content:prune-borrowed-assets'));
```

`03:40` is taken by `builds:prune-expired`; `03:50` avoids it.

- [x] **Step 5: Run the tests**

8 passed.

```bash
./vendor/bin/pest tests/Feature/Migration/BorrowedAssetPrunerTest.php
./vendor/bin/pest tests/Feature/Console
```
Expected: PASS. The second catches a schedule-registration regression.

- [x] **Step 6: Commit**

```bash
git add app/Services/Migration/BorrowedAssetPruner.php app/Console/Commands/PruneBorrowedAssetsCommand.php routes/console.php tests/Feature/Content/BorrowedAssetPrunerTest.php
git commit -m "feat(media): prune borrowed assets — we may not retain Google photo rows"
```

---

## Task 11: The mergeInto regression, and the live dev assertions

**Decision:** spec §6.1, §6.2. **No unit is done without a live dev assertion** (invariant #1).

**Files:**
- Create: `tests/Feature/Ingest/MediaMergeRegressionTest.php`
- Create: `docs/wire-changes/2026-08-12-media-pool-slice-1b.md`

- [x] **Step 1: Write the regression test**

Create `tests/Feature/Ingest/MediaMergeRegressionTest.php`:

```php
<?php

it('leaves uploads and mirrored instagram alive after a google run', function () {
    // mergeInto() hard-deletes a discarded item carrying neither a pin nor an
    // override. preferOwnerAnchored() should make the owner row win — but 1a was
    // the first media-kind exercise and this is the first with TWO connector
    // sources present.
    [$user, $site] = createUserWithSite();

    $uploads = createManualUploadMediaItems($user, count: 3);
    projectInstagramMedia($user->id, shortcode: 'ABC123', images: ['https://scontent.cdninstagram.com/v/one.jpg']);
    $instagramCount = countMediaItems($user->id) - 3;

    projectGoogleMedia($user->id, refs: ['places/ChIJx/photos/AWCwydone', 'places/ChIJx/photos/AWCwydtwo']);

    foreach ($uploads as $item) {
        expect(itemIsLive($item->id))->toBeTrue();
    }
    expect(countInstagramMediaItems($user->id))->toBe($instagramCount);
});

it('churns google media across two runs with rotated refs, and does not touch owned items', function () {
    // Spec §2.1: refs rotate every fetch, so the second run presents entirely
    // unrecognised coords. The google set is expected to be replaced. What must
    // NOT happen is the owned items going with them.
    [$user, $site] = createUserWithSite();
    $uploads = createManualUploadMediaItems($user, count: 3);

    projectGoogleMedia($user->id, refs: ['places/ChIJx/photos/AWCwydrun1a', 'places/ChIJx/photos/AWCwydrun1b']);
    projectGoogleMedia($user->id, refs: ['places/ChIJx/photos/AWCwydrun2a', 'places/ChIJx/photos/AWCwydrun2b']);

    foreach ($uploads as $item) {
        expect(itemIsLive($item->id))->toBeTrue();
    }
    expect(countLiveGoogleMediaItems($user->id))->toBe(2);
});
```

- [x] **Step 2: Run it**

Run: `./vendor/bin/pest tests/Feature/Ingest/MediaMergeRegressionTest.php`
Expected: PASS. **If the first test fails, stop.** `preferOwnerAnchored()` does not hold for a two-connector media merge, and that is a data-loss bug that must be fixed before anything deploys.

- [x] **Step 3: Run the full suite and the static gates**

```bash
composer test
./vendor/bin/pest tests/Feature/Architecture/OutboundHttpGuardTest.php
./vendor/bin/phpstan analyse
php artisan pint
```

**Do not use `composer test --filter`** — it is broken in this repo, as is `composer` + `artisan pint` in one invocation. Run each separately.

- [x] **Step 4: Deploy to dev and run the commands**

```bash
git push -u origin feat/media-pool-slice-1b
# open PR into development, merge after review
cloud command:run development "php artisan content:migrate-selection --dry-run"
cloud command:run development "php artisan content:migrate-selection"
cloud command:run development "php artisan content:prune-borrowed-assets --dry-run"
```

Enable the Instagram source and set the Google cadence:

```sql
UPDATE ingest.sources SET auto_sync = true WHERE source_key = 'instagram';
UPDATE ingest.sources SET min_interval_secs = 604800 WHERE source_key = 'google_business';
```

- [x] **Step 5: Paste the live dev assertions into the checkpoint**

```sql
-- Google media carries urls and (where Google supplied any) attribution
SELECT count(*) FILTER (WHERE a.source_url IS NOT NULL) AS with_url,
       count(*) FILTER (WHERE a.attribution IS NOT NULL) AS with_attr,
       count(*) AS total
FROM content.media_assets a
JOIN content.item_media im ON im.asset_id = a.id
JOIN content.items i ON i.id = im.item_id AND i.kind='media' AND i.removed_at IS NULL;

-- Instagram landed on owned bytes, one path per asset
SELECT count(*) FILTER (WHERE storage_path IS NOT NULL) AS mirrored,
       count(DISTINCT storage_path) FILTER (WHERE storage_path IS NOT NULL) AS distinct_paths
FROM content.media_assets;

-- selections: 3 pins added, 89 rows still present (nothing deleted)
SELECT (SELECT count(*) FROM site.section_items WHERE state='pinned') AS pins,
       (SELECT count(*) FROM site.content_selection) AS legacy_rows;
```

- [x] **Step 6: Run the Instagram no-duplicate proof live**

Trigger the Instagram source twice and record the asset count either side. **This is a live assertion, not a unit test** — the parent spec asks for exactly this and a green Pest run does not satisfy it.

```sql
SELECT count(*) FROM content.media_assets WHERE fingerprint LIKE 'url-%';
```
Expected: identical before and after the second sync.

- [x] **Step 7: Observe the Google churn**

Run the `google_business/media` stream twice and record the delta. Spec §2.1 predicts a fresh set each run with the previous tombstoned — deduced, never observed.

**If it does NOT churn, §2.1 is wrong about the mechanism and D3/D4/D5 must be revisited before this merges to production.** Cost of the observation: one Details call plus ten photo calls, about $0.10.

- [x] **Step 8: Write the wire manifest**

Create `docs/wire-changes/2026-08-12-media-pool-slice-1b.md`, appending to 1a's lineage rather than restarting it. Before and after shapes for `frames[]` (which gains an optional `attribution` object), and the consuming repos named: the Partna-App dashboard and the monorepo public render.

State plainly that `gallery` and `designMedia` are unchanged and still live.

- [x] **Step 9: Post-deploy checks**

```bash
cloud env:logs partna development --minutes 10
```
Expected: clean.

**Then check Nightwatch.** Slice 0's checkpoint recorded a log scan and skipped Nightwatch, and 1a's kickoff called that out. Do not make it three.

- [x] **Step 10: Commit the manifest**

```bash
git add docs/wire-changes/2026-08-12-media-pool-slice-1b.md tests/Feature/Ingest/MediaMergeRegressionTest.php
git commit -m "docs(wire): slice 1b manifest — frames[] gains attribution"
```

---

## Self-review

**Spec coverage.** D1 → the Task 6 / Task 8 split. D2 → Task 5. D3 → Task 5 step 5 and Task 11 step 4. D4 → Task 10. D5 → Task 6. D6 → Tasks 1, 2, 3, 4. D7 → Task 3 step 1. D8 → Tasks 7, 8. D9 → Task 7. D10 → Task 9. Spec §6.1 → Task 11 steps 5–7. §6.2 → per-task tests. §6.3 → Global Constraints. §6.4 → Task 11 step 9. §8 (reported not fixed) → correctly has no task.

**Known gaps, deliberate:**
- Spec §5's three-lane rule is asserted in Task 9 only. Task 10's pruner deletes borrowed assets whose items are already tombstoned, so no live site payload changes and no invalidation is owed. Task 7's mirror changes a url a site *does* serve — **its invalidation is not covered by a test.** Add one during Task 7 if the implementer finds the mirror touches a live payload; the spec's §5 wording ("the mirror") says it does.
- Task 5 step 5 branches on whether `StreamSpec` supports a per-stream interval. That is unresolved in this plan on purpose — resolving it needs the file open, and the fallback is specified.

**Type consistency:** `MediaMirror::mirror(string, string, string): bool` used identically in Tasks 7 and 8. `BorrowedMedia::isBorrowed(Item): bool` in Task 6 only. Migration services all return `array` with named integer counts. `ContentSelectionMigrator::run(bool, ?string)` matches `MediaUploadBackfiller::run(bool, ?string)`.

---

## Execution handoff

Plan complete. Two execution options:

1. **Subagent-Driven (recommended)** — a fresh subagent per task, review between tasks, fast iteration.
2. **Inline Execution** — tasks executed in this session with checkpoints for review.

**Blocker gate:** Tasks 5 (billed effects), 9 and 10 (migrations, DB writes) and 11 (the public wire) each trip the repo's blocker rule — plan first, wait for sign-off, then implement. Task 5 is the one to watch: it is the only task that spends money.
