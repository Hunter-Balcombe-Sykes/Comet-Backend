# Media mirror follow-ups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix five latent/structural weaknesses in the media-mirror lane, shipping all five units in one PR. (Unit 1 was scoped as fixing one live defect; Correction 4 below found that claim false — both bugs it fixes are latent.)

**Architecture:** Five independent, code-only changes. Unit 1 replaces a vendor-URL predicate in `PoolResolver::pending()` with a row-state one. Unit 2 freezes the thumbnail edge as a const and deletes its config knob. Unit 3 extracts the shared "is this a video" rule to one place. Unit 4 puts a memoised seam in front of `ProjectionWriter`'s cross-domain `site.sites.is_published` read. Unit 5 is comments only. No migration, no data write, no config default change that alters behaviour.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, PostgreSQL (Supabase) in production / SQLite in the cheap test lane.

**Spec:** `docs/superpowers/specs/2026-09-05-media-mirror-followups-design.md`

**Branch:** `fix/media-mirror-followups-2026-09-05` off `development`.

---

## Global Constraints

- **No Laravel migration files.** Nothing here needs schema. Both columns Unit 1 reads (`content.media_assets.mirror_eligible`, `.mirror_attempts`) already exist on dev and in every relevant stand-in.
- **Comment for WHY, not what.** Brief docblocks on public methods, one line above non-trivial blocks. No paragraphs, no banners, no restatements. 4-space indent, LF.
- **Tests run SQLite, prod is Postgres.** Never compare a boolean column with `=== true` — SQLite hands back `1`, Postgres may hand back `true` or `'t'` depending on the read path. Cast: `(bool) $row->col`. Same for integers: `(int) $row->col`. Precedent: `PoolResolver.php:1342` `(bool) $row->is_active`.
- **`composer test:pg` is REQUIRED, not optional.** Units 1, 3 and 4 change what `PoolResolver` and `ProjectionWriter` read. A green SQLite run says nothing about that lane; this has bitten twice (slice 5a, `da958493e`).
- **A PG-lane finding is fixed by ADDING the column** (`ALTER TABLE … ADD COLUMN IF NOT EXISTS …`, so it survives first-creator-wins) — never by thinning a stand-in or relaxing an assertion.
- **`Bus::fake()` in every test that projects.** `QUEUE_CONNECTION` is `sync` under `phpunit.xml`, so an unfaked dispatch runs `MediaMirror` INLINE, sends `SafeUrlFetcher` at a real CDN host, and `assertSafe()` does a genuine DNS lookup even under `Http::fake()`. Forgetting it takes the runner down with no output, not a red test.
- **`::dispatch()`, never `Bus::dispatch(new …)`** — the latter silently drops `ShouldBeUnique`.

---

## Corrections to the spec, already agreed (owner, 2026-09-05)

Three of the spec's statements were checked against the code and are false. This plan implements the corrected form; do not "restore" the spec's wording.

1. **Unit 3's ordering premise is FALSE.** The spec says "`dispatchMirrors` runs after the projection has written `content.item_media`". It does not. `resolveMediaAssets()` calls `dispatchMirrors()` at `ProjectionWriter.php:3191` and `:3244`; the `item_media` rows for this pass are only *built* at `:2687` — after `resolveMediaAssets()` returns at `:2682` — and written inside the chunked transaction at `:2839`. At dispatch time the table holds the *previous* pass's rows, or nothing on a first pull. The spec instructed the implementer to stop and re-plan in exactly this case. **Owner decision: share the RULE, keep the two reads** (the spec's own headline). `dispatchMirrors` does NOT gain a `content.item_media` query.

2. **Unit 4's `SourceScheduler` half is not a row read.** `scoreDue()` (`SourceScheduler.php:137-141`) uses a correlated `whereExists` subquery inside a set query with `limit($limit * 2)`. A per-user boolean memo would make it N+1 *and* change which sources the limit considers. **Owner decision: `SitePublishState` serves `ProjectionWriter` only**; `SourceScheduler` keeps its subquery and gains a comment saying why.

3. **Unit 1's PG-lane risk is overstated — it is zero.** `AppColumnReadScanner::BARE_COLUMN_METHODS` includes `select` (which `PoolResolver` uses) but not `get([...])`. Of the 12 `tests/Postgres/` files provisioning `content.media_assets`, exactly one imports `PoolResolver` — `MergeFacetFoldTest.php` — and it already declares both columns at lines 264-265. Expect no new findings. Run the guard anyway (Task 1 Step 8) and fix by ADDING if one appears.

4. **Unit 1's live-impact claim is FALSE.** The spec says 25 of 29 in-flight assets are
   TikTok, report `pending:false`, and render "the empty frame the flag exists to prevent".
   They do not render an empty frame: `MediaUrlResolver::unservableMetaImage()` blocks raw
   passthrough for the two Meta hosts ONLY, so a non-Meta cover is served straight from the
   vendor CDN and `pending:false` is correct for it. Measured on dev 2026-09-05: of the
   in-flight assets, 25 non-Meta all carry a `source_url`, 6 are Meta, 0 have no url. **Both
   bugs Unit 1 fixes are therefore LATENT, not live**, and its only behavioural delta is the
   false side — a capped row and a borrowed row stop claiming "loading". Holding the other
   three platforms back until their bytes are ours would change what the public wire serves
   and is a separate product decision, not taken here.

Two smaller notes:

- **`PARTNA_MEDIA_THUMB_EDGE` is not in `.env.example`.** The spec's deletion step there is a no-op. Only `config/partna.php:1548` exists.
- **The role test the lane flag depends on lives in `dispatchMirrors`, not `budgetMirrors`.** `ProjectionWriter.php:3316` is `$bucket = (string) ($entry['role'] ?? '') === 'video' ? 'videos' : 'images';`. `budgetMirrors()` merely consumes that bucketing to fill `$videoIds`. That one line is Unit 3's edit site.

---

## File Structure

**Created:**
- `app/Site/SitePublishState.php` — the memoised `site.sites.is_published` seam. One responsibility: answer "is this user's site published?" for one job/request.
- `tests/Feature/Content/MediaPoolPendingTest.php` — Unit 1's four behavioural cases.
- `tests/Unit/Media/MirrorRoleRuleTest.php` — Unit 3's rule + lockstep guard.
- `tests/Feature/Site/SitePublishStateTest.php` — Unit 4's memo and null-semantics cases.

**Modified:**
- `app/Site/Pools/PoolResolver.php` — import `MediaMirror`; +2 columns on the cover-rows select (`:1550-1561`); rewrite `pending()` (`:2547-2564`).
- `app/Services/Media/MediaMirror.php` — add `THUMB_EDGE` const and `rolesIndicateVideo()`; `thumbEdge()` returns the const; `isImageOnlyAsset()` calls the rule.
- `config/partna.php` — delete `thumb_edge` (`:1548`); comment `pull_budget` (`:1540-1543`).
- `app/Ingest/Projection/ProjectionWriter.php` — route `:3316` through the rule; inject `SitePublishState` and rewrite `siteInSetup()` (`:3523-3527`); comment `budgetMirrors()`.
- `app/Ingest/Runtime/SourceScheduler.php` — comment only, on why it does not use the seam.
- `app/Providers/AppServiceProvider.php` — `scoped()` binding for `SitePublishState`.
- `tests/Feature/Media/MediaMirrorTest.php` — Unit 2's frozen-tier guard.

---

### Task 1: `pending` derives from row state

The latent defect (see Correction 4 — not live, contrary to what this section originally claimed). `PoolResolver::pending()` asks "is this URL a Meta CDN URL?" when the question is "is this row still expecting bytes?". `InstagramMediaUrl::isMetaCdn()` knows `cdninstagram.com` and `fbcdn.net`; the mirror lane owns four platforms (`MediaMirror::OWNED_REF_PREFIXES` — instagram, tiktok, facebook, threads). On dev, 25 of 29 in-flight assets are TikTok CDN and report `pending: false` — correctly, since `MediaUrlResolver` serves their vendor url straight through and something is on screen — while a capped or url-less row would wrongly report `pending: true` forever, which is the bug actually fixed here.

`InstagramMediaUrl::isMetaCdn` is **NOT deleted** — `MediaMirror` still uses it for the expired-URL pre-flight, and `PoolResolver` still injects `InstagramMediaUrl` for `isExpired()` at `:2656`. Only `pending()` stops calling it.

**Files:**
- Modify: `app/Site/Pools/PoolResolver.php:13` (imports), `:1550-1561` (select), `:2542-2564` (docblock + `pending()`)
- Test: `tests/Feature/Content/MediaPoolPendingTest.php` (create)

**Interfaces:**
- Consumes: `MediaMirror::maxAttempts(): int` (already `public static`, `MediaMirror.php:684`).
- Produces: nothing new. `pending` stays a `bool` in `DASHBOARD_ONLY_ITEM_KEYS`, never on the public wire.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Content/MediaPoolPendingTest.php`:

```php
<?php

use App\Models\Core\Site\Site;
use App\Services\Media\MediaMirror;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// `pending` answers "are bytes genuinely still coming for this item?" from the
// row's own mirror state. It used to answer it by matching the source url
// against the Meta CDN hosts, which is a different question with a different
// answer for the three non-Meta platforms the mirror lane owns.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupMediaTables();
    Storage::fake('media');
    Queue::fake();
});

/** An in-flight owned asset: eligible, no bytes yet, retries left. */
function pendingItem(string $userId, string $sourceId, array $assetOverrides): string
{
    $itemId = poolItem($userId, $sourceId, 'media', 'Pending', '2026-08-01T00:00:00Z');
    $asset = frameAsset($userId, array_merge([
        'mirror_eligible' => true,
        'mirror_attempts' => 0,
    ], $assetOverrides));
    frameRow($itemId, $sourceId, $asset, 'cover', 0);

    return $itemId;
}

function pendingFlag(string $siteId, string $itemId): ?bool
{
    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'media');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    return $item['pending'] ?? null;
}

it('reports pending for an in-flight tiktok asset, not just a meta one', function () {
    // The latent defect (Correction 4: not live — MediaUrlResolver serves a
    // non-Meta url straight through, so this case already rendered correctly
    // in practice; the row-state rewrite is still the right fix).
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);

    $tiktok = pendingItem($pro->id, $sourceId, ['source_url' => 'https://p16-sign.tiktokcdn-us.com/o/x.jpeg']);
    $meta = pendingItem($pro->id, $sourceId, ['source_url' => 'https://scontent.cdninstagram.com/v/one.jpg']);

    expect(pendingFlag($siteId, $tiktok))->toBeTrue()
        ->and(pendingFlag($siteId, $meta))->toBeTrue();
});

it('stops reporting pending once retries are exhausted', function () {
    // A skeleton that never resolves is worse than an honest empty frame:
    // storage_path never becomes non-null for a link that cannot be fetched.
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);

    $dead = pendingItem($pro->id, $sourceId, [
        'source_url' => 'https://scontent.cdninstagram.com/v/gone.jpg',
        'mirror_attempts' => MediaMirror::maxAttempts(),
    ]);

    expect(pendingFlag($siteId, $dead))->toBeFalse();
});

it('reports false for a borrowed asset, which is never coming', function () {
    // A Google Places photo is correctly never mirrored — the licence forbids
    // storing it. "Not coming" and "still coming" must not look the same.
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);

    $borrowed = pendingItem($pro->id, $sourceId, [
        'source_url' => 'https://lh3.googleusercontent.com/places/x',
        'mirror_eligible' => false,
    ]);

    expect(pendingFlag($siteId, $borrowed))->toBeFalse();
});

it('short-circuits to false when a cover already resolves', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);

    $itemId = poolItem($pro->id, $sourceId, 'media', 'Resolved', '2026-08-01T00:00:00Z');
    $landed = frameAsset($pro->id, [
        'source_url' => 'https://scontent.cdninstagram.com/v/one.jpg',
        'storage_path' => 'content-media/ab/cd.webp',
        'mirror_eligible' => true,
    ]);
    $inFlight = frameAsset($pro->id, [
        'source_url' => 'https://scontent.cdninstagram.com/v/two.jpg',
        'mirror_eligible' => true,
        'mirror_attempts' => 0,
    ]);
    frameRow($itemId, $sourceId, $landed, 'cover', 0);
    frameRow($itemId, $sourceId, $inFlight, 'gallery', 1);

    expect(pendingFlag($siteId, $itemId))->toBeFalse();
});

it('keeps pending off the public wire', function () {
    // DASHBOARD_ONLY_ITEM_KEYS. Changing what pending MEANS is safe only
    // because nothing public reads it.
    expect(PoolResolver::DASHBOARD_ONLY_ITEM_KEYS)->toContain('pending');
});
```

- [ ] **Step 2: Run the test to verify the first two cases fail**

```bash
php artisan test tests/Feature/Content/MediaPoolPendingTest.php
```

Expected: the tiktok case FAILS (`false` where `true` expected) and the exhausted-retries case FAILS (`true` where `false` expected). The borrowed, short-circuit and wire cases already pass — they are regression pins, not new behaviour.

If the tiktok case *passes*, stop: the defect is not reproduced and something about the fixture (most likely `mirror_eligible` landing NULL) is wrong.

- [ ] **Step 3: Add the `MediaMirror` import to `PoolResolver`**

In `app/Site/Pools/PoolResolver.php`, beside the existing `use App\Services\Media\InstagramMediaUrl;` at line 13:

```php
use App\Services\Media\MediaMirror;
```

Keep the `InstagramMediaUrl` import — the injected instance is still used by `isExpired()` at `:2656`.

- [ ] **Step 4: Add the two columns to the cover-rows select**

In the `->select([...])` list ending at `PoolResolver.php:1561`, after `'content.media_assets.mime_type',`:

```php
                'content.media_assets.mime_type',
                // pending() asks the ROW whether bytes are still coming.
                'content.media_assets.mirror_eligible',
                'content.media_assets.mirror_attempts',
            ]);
```

Same rows, no extra round trip — 10 columns to 12. This query sits on TWO hot paths: `PoolWire::forSite` (public profile) and `SetupPayload::forPass` (`GET /api/site/setup`, p50 569ms on dev). Two columns on rows already fetched is not measurable, but weigh any future addition here.

- [ ] **Step 5: Rewrite `pending()` and its docblock**

Replace `PoolResolver.php:2542-2564` (the docblock and method) with:

```php
    /**
     * No cover resolves, and at least one cover-role row is still expecting
     * bytes — eligible, unmirrored, not an upload, retries left. Read from the
     * ROW, not from the source url: the url test recognised two Meta hosts
     * while the mirror lane owns four platforms (MediaMirror::OWNED_REF_PREFIXES),
     * so every TikTok asset in flight reported "no image" instead of "loading".
     *
     * @param  array<string, array{url: string, width: int|null, height: int|null, thumb: string|null}>  $resolved
     */
    private function pending(Collection $rows, array $resolved): bool
    {
        $coverRows = $rows->filter(fn (object $row): bool => in_array((string) $row->role, ['cover', 'poster', 'gallery'], true));
        foreach ($coverRows as $row) {
            if (isset($resolved[(string) $row->asset_id])) {
                return false;
            }
        }

        $max = MediaMirror::maxAttempts();
        foreach ($coverRows as $row) {
            // Casts, not ===: SQLite hands a boolean back as 1/0 and Postgres
            // may too depending on the read path.
            if ((bool) $row->mirror_eligible
                && $row->storage_path === null
                && $row->site_media_id === null
                // storage_path never becomes non-null for a link that cannot
                // be fetched, so a capped row is a skeleton that never
                // resolves — it is not coming, and must not claim to be.
                && (int) $row->mirror_attempts < $max) {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 6: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Content/MediaPoolPendingTest.php
```

Expected: 5 passed.

- [ ] **Step 7: Run the neighbouring pool + setup suites**

```bash
php artisan test tests/Feature/Content tests/Feature/Setup
```

Expected: all pass. `SetupPoolBatchingTest` in particular pins the batched seam this query sits on.

- [ ] **Step 8: Run the PG-lane read-coverage guard**

```bash
php artisan test tests/Feature/Architecture/PostgresLaneReadCoverageTest.php
```

Expected: PASS with no new findings (see Correction 3 — `MergeFacetFoldTest.php` is the only PG-lane file importing `PoolResolver` and already declares both columns).

If a finding DOES appear, fix it by ADDING the column to the named stand-in, e.g.:

```sql
ALTER TABLE content.media_assets ADD COLUMN IF NOT EXISTS mirror_attempts integer NOT NULL DEFAULT 0;
```

Never thin a stand-in, never relax the assertion, never add the file to `$exempt`.

- [ ] **Step 9: Commit**

```bash
git add app/Site/Pools/PoolResolver.php tests/Feature/Content/MediaPoolPendingTest.php
git commit -m "$(cat <<'MSG'
Derive the media pending flag from row state, not the CDN host

pending() matched the source url against InstagramMediaUrl::isMetaCdn,
which knows two Meta hosts, while the mirror lane owns four platforms, so
an owned asset on any other platform reported "no image" rather than
"loading" the moment its cover could not render. A row whose retries were
spent also reported pending:true forever — a skeleton that never resolves.

The row already carries the answer: mirror_eligible, storage_path,
site_media_id, mirror_attempts. Costs two columns on a select that was
already fetching those rows.

Scope correction, measured on dev 2026-09-05 (Correction 4): the design
claimed 25 of 29 in-flight assets render an empty frame. They do not.
MediaUrlResolver blocks raw passthrough for the two Meta hosts only, so
those 25 serve their vendor url and pending:false is correct for them.
Both bugs fixed here are therefore latent, not live. Holding the other
three platforms back until their bytes are ours would change what the
public wire serves and is left as a separate decision.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Fo1Gn9bVHv8SG5ivrwZGxd
MSG
)"
```

---

### Task 2: Freeze the thumb edge

`MediaMirror::THUMB_SUFFIX` is `'.640.webp'`, frozen so an edge change cannot orphan existing objects. But the *rendered* edge reads `partna.media.thumb_edge` / `PARTNA_MEDIA_THUMB_EDGE`. Setting that to 480 writes 480px bytes to a path claiming 640, mixed indistinguishably with genuine 640s, with no signal a backfill is owed. A knob that is unsafe to exercise is worse than no knob.

`thumb_quality` stays configurable — the filename promises nothing about quality.

**No backfill.** The var was never set on dev or prod (`cloud environment:get`, 2026-09-05), so no mislabelled object exists.

**Files:**
- Modify: `app/Services/Media/MediaMirror.php:48-56` (const + docblock), `:507-512` (`thumbEdge()`)
- Modify: `config/partna.php:1544-1548` (delete `thumb_edge`, keep the tier comment)
- Test: `tests/Feature/Media/MediaMirrorTest.php` (append)

**Interfaces:**
- Produces: `MediaMirror::THUMB_EDGE` (`public const int` = 640), readable by the guard test.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Media/MediaMirrorTest.php`:

```php
// ── The frozen thumbnail tier ────────────────────────────────────────────────
// The suffix is a promise about SIZE. Moving the edge without moving the
// suffix writes mislabelled bytes indistinguishable from genuine 640s; moving
// the suffix without a backfill orphans every object already on R2. So the
// two are frozen together, and a tier change is a NEW suffix plus a backfill,
// never an edit to one of these.

it('encodes the thumb edge in the thumb suffix', function () {
    expect(MediaMirror::THUMB_SUFFIX)->toBe('.'.MediaMirror::THUMB_EDGE.'.webp');
});

it('has no configurable thumb edge', function () {
    // A setting that needs a re-encode of the whole bucket to take effect is a
    // constant with extra steps. thumb_quality stays configurable because the
    // filename promises nothing about quality.
    expect(config()->has('partna.media.thumb_edge'))->toBeFalse()
        ->and(config('partna.media.thumb_quality'))->toBeInt();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Media/MediaMirrorTest.php --filter="thumb edge"
```

Expected: FAIL — `Undefined constant App\Services\Media\MediaMirror::THUMB_EDGE`, and the config key still present.

- [ ] **Step 3: Add the const and freeze `thumbEdge()`**

In `app/Services/Media/MediaMirror.php`, replace the `THUMB_SUFFIX` docblock and const (currently `:48-55`) with:

```php
    /**
     * The thumbnail tier's key is DERIVED from the master's, not stored: no
     * schema change, and `storage_path` stays the one column that says
     * "bytes are ours". MediaUrlResolver derives the thumb url by string
     * substitution with no existence check, so it cannot know which edge a
     * given row used — which is why the edge is a CONST, not config.
     *
     * THUMB_SUFFIX and THUMB_EDGE are frozen TOGETHER. Changing the tier means
     * a new suffix AND a re-encode of every object already on R2; editing one
     * of these alone either mislabels new bytes or orphans old ones. The
     * flexibility worth having one day is MORE tiers (320, 1280) each in its
     * own filename with a map on the wire — the srcset shape, a different
     * design. Build it when a consumer asks.
     */
    public const THUMB_SUFFIX = '.640.webp';

    public const THUMB_EDGE = 640;
```

Then replace `thumbEdge()` (currently `:507-512`) with:

```php
    private function thumbEdge(): int
    {
        return self::THUMB_EDGE;
    }
```

- [ ] **Step 4: Delete the config key**

In `config/partna.php`, remove line 1548. The surrounding comment block stays and gains one line — the result reads:

```php
        // Thumbnail tier written beside every mirrored image master
        // (`{sha}.webp` → `{sha}.640.webp`): setup tiles and cards load ~32 KB
        // instead of the 2400px master's ~260 KB. The EDGE is not configurable
        // — MediaMirror::THUMB_EDGE, frozen with THUMB_SUFFIX. Quality is,
        // because the filename promises nothing about it.
        'thumb_quality' => (int) env('PARTNA_MEDIA_THUMB_QUALITY', 80),
```

`.env.example` needs no edit — `PARTNA_MEDIA_THUMB_EDGE` was never listed there.

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Media/MediaMirrorTest.php
```

Expected: all pass, including the two new cases.

- [ ] **Step 6: Confirm nothing else reads the key**

```bash
grep -rn "thumb_edge\|THUMB_EDGE\|PARTNA_MEDIA_THUMB_EDGE" app/ config/ tests/ .env.example
```

Expected: only `MediaMirror.php` (const + `thumbEdge()`), `MediaMirrorTest.php`, and the `config/partna.php` comment. No `config('partna.media.thumb_edge')` call survives.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Media/MediaMirror.php config/partna.php tests/Feature/Media/MediaMirrorTest.php
git commit -m "$(cat <<'MSG'
Freeze the thumbnail edge as a const beside its suffix

THUMB_SUFFIX ('.640.webp') was already frozen so an edge change could not
orphan existing objects, but the rendered edge read config. Setting it to
480 wrote 480px bytes to a path claiming 640, indistinguishable from
genuine 640s, with no signal a backfill was owed.

The var was never set on dev or prod, so nothing mislabelled exists. A
guard now pins the suffix to the const so neither can move alone.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Fo1Gn9bVHv8SG5ivrwZGxd
MSG
)"
```

---

### Task 3: One image-vs-video rule

Two independent determinations of "is this asset a video":

- `ProjectionWriter::dispatchMirrors()` at `:3316` — `(string) ($entry['role'] ?? '') === 'video'` buckets the asset, `budgetMirrors()` turns that bucket into `$videoIds`, and the dispatch passes `video: isset($videoIds[$assetId])`. That flag chooses the queue lane.
- `MediaMirror::isImageOnlyAsset()` at `:422` — re-derives it from `content.item_media.role` at mirror time.

They agree today because both trace to the same role data. If they diverge, a video rides the managed queue and takes `MirrorMediaAssetJob::MANAGED_TIMEOUT` (85s) instead of 120s, and a 15 MB reel over a cold edge dies at the platform with no reason recorded on the row. This repo has a documented history of exactly this — two independent link classifiers, three independent name-casers — where the duplicate was harmless until it wasn't.

**Share the RULE, not the read.** The two call sites legitimately need different reads, and — per Correction 1 — they *must*: `content.item_media` is not written until after `dispatchMirrors()` has returned, so the dispatch side has projection entries and nothing else. Forcing them onto one query is not merely the wrong fix, it is impossible. What unifies is the predicate.

`budgetMirrors()`'s own images/videos *budget* accounting is untouched — that is a different question ("which bucket does this post spend from"), not the lane question.

**Files:**
- Modify: `app/Services/Media/MediaMirror.php` (add `rolesIndicateVideo()`, rewrite `isImageOnlyAsset()`'s return)
- Modify: `app/Ingest/Projection/ProjectionWriter.php:3316`
- Test: `tests/Unit/Media/MirrorRoleRuleTest.php` (create), `tests/Feature/Ingest/InstagramMediaMirrorTest.php` (append)

**Interfaces:**
- Produces: `MediaMirror::rolesIndicateVideo(array $roles): bool` — `public static`, pure, no I/O. Consumed by `MediaMirror::isImageOnlyAsset()` and `ProjectionWriter::dispatchMirrors()`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Media/MirrorRoleRuleTest.php`:

```php
<?php

use App\Services\Media\MediaMirror;

// One predicate, two reads. The reads MUST differ: MediaMirror asks about one
// asset from inside its job, where content.item_media is written; the
// projection writer asks at dispatch time, BEFORE those rows exist, so it has
// only the projection entries. What must not differ is the rule.

it('calls a role set with a video in it a video', function () {
    expect(MediaMirror::rolesIndicateVideo(['video']))->toBeTrue()
        ->and(MediaMirror::rolesIndicateVideo(['cover', 'video']))->toBeTrue();
});

it('calls image-only and empty role sets not-video', function () {
    expect(MediaMirror::rolesIndicateVideo(['cover', 'gallery']))->toBeFalse()
        ->and(MediaMirror::rolesIndicateVideo([]))->toBeFalse();
});

it('keeps the role decision in exactly one place', function () {
    // Drift insurance is the whole point of this unit — it has no live
    // failure. A behavioural test alone would not stop a third copy appearing,
    // so pin the source: the literal membership test lives in the rule only.
    // Single-quoted: "$roles" in a double-quoted string interpolates to ''.
    $ruleBody = 'return in_array(\'video\', $roles, true);';

    $offenders = [];
    foreach ([
        'app/Services/Media/MediaMirror.php',
        'app/Ingest/Projection/ProjectionWriter.php',
        'app/Jobs/Media/MirrorMediaAssetJob.php',
    ] as $rel) {
        foreach (file(base_path($rel)) as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === $ruleBody) {
                continue; // the rule itself
            }
            // A role test, not a value assignment: MediaMirror:183 builds the
            // string 'video' from a url shape and is legitimately not this.
            if (preg_match("/in_array\\(\\s*'video'|===\\s*'video'|'video'\\s*===/", $trimmed)) {
                $offenders[] = basename($rel).':'.($i + 1).' '.$trimmed;
            }
        }
    }

    expect($offenders)->toBe([], "A second image-vs-video decision has appeared:\n".implode("\n", $offenders));
});
```

Append to `tests/Feature/Ingest/InstagramMediaMirrorTest.php`:

```php
// ── The queue lane flag ──────────────────────────────────────────────────────
// video:true keeps a reel on Horizon (120s). video:false sends it to the
// managed queue, where MirrorMediaAssetJob::MANAGED_TIMEOUT kills it at 85s —
// which a 15 MB reel over a cold edge loses, with no reason on the row.

it('dispatches a video-role asset with the horizon lane flag set', function () {
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:reel-1', [
        'kind' => 'media',
        'headline' => null,
        'media' => [
            ['role' => 'video', 'url' => 'https://scontent.cdninstagram.com/v/reel.mp4', 'ref' => 'instagram:REEL1:0'],
            ['role' => 'cover', 'url' => 'https://scontent.cdninstagram.com/v/poster.jpg', 'ref' => 'instagram:REEL1:1'],
        ],
    ]);

    Bus::assertDispatched(MirrorMediaAssetJob::class, fn ($job) => str_ends_with($job->sourceUrl, 'reel.mp4') && $job->video === true);
    Bus::assertDispatched(MirrorMediaAssetJob::class, fn ($job) => str_ends_with($job->sourceUrl, 'poster.jpg') && $job->video === false);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
php artisan test tests/Unit/Media/MirrorRoleRuleTest.php
```

Expected: FAIL — `Call to undefined method App\Services\Media\MediaMirror::rolesIndicateVideo()`.

```bash
php artisan test tests/Feature/Ingest/InstagramMediaMirrorTest.php --filter="horizon lane flag"
```

Expected: PASS. This one is a **regression pin**, not a red-first case — the behaviour is correct today and Unit 3 must not change it. If it fails now, stop: the flag is already wrong and that is a different bug.

- [ ] **Step 3: Add the rule and route `isImageOnlyAsset` through it**

In `app/Services/Media/MediaMirror.php`, add above `isImageOnlyAsset()`:

```php
    /**
     * The ONE image-vs-video decision. Two call sites need different READS —
     * this class asks about one asset from inside its job, where
     * content.item_media is written; ProjectionWriter asks at dispatch time,
     * before those rows exist, from the projection entries. Only the rule is
     * shared, and it has to be: the flag it produces chooses the queue lane,
     * and a reel on the managed queue dies at MANAGED_TIMEOUT.
     *
     * @param  list<string>  $roles
     */
    public static function rolesIndicateVideo(array $roles): bool
    {
        return in_array('video', $roles, true);
    }
```

Then change the return of `isImageOnlyAsset()` from

```php
        return $roles !== [] && ! in_array('video', $roles, true);
```

to

```php
        return $roles !== [] && ! self::rolesIndicateVideo($roles);
```

- [ ] **Step 4: Route the dispatch bucket through the rule**

In `app/Ingest/Projection/ProjectionWriter.php`, replace line 3316:

```php
            $bucket = (string) ($entry['role'] ?? '') === 'video' ? 'videos' : 'images';
```

with:

```php
            // The same rule MediaMirror applies at mirror time. The reads
            // differ by necessity — content.item_media is not written until
            // after this method returns — so the predicate is what is shared.
            $bucket = MediaMirror::rolesIndicateVideo([(string) ($entry['role'] ?? '')]) ? 'videos' : 'images';
```

`MediaMirror` is already imported in `ProjectionWriter` (`MediaMirror::isOwnedEntry` at `:3291`). No new import.

- [ ] **Step 5: Run both tests to verify they pass**

```bash
php artisan test tests/Unit/Media/MirrorRoleRuleTest.php tests/Feature/Ingest/InstagramMediaMirrorTest.php
```

Expected: all pass.

If the lockstep case fails listing an offender you believe is legitimate, do NOT widen the regex — a third decision point is exactly what this unit exists to prevent. Route it through the rule instead.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Media/MediaMirror.php app/Ingest/Projection/ProjectionWriter.php tests/Unit/Media/MirrorRoleRuleTest.php tests/Feature/Ingest/InstagramMediaMirrorTest.php
git commit -m "$(cat <<'MSG'
Share one image-vs-video rule between dispatch and mirror

ProjectionWriter decided the queue lane from the projection entry's role;
MediaMirror re-decided it from content.item_media at mirror time. They
agree today, and a divergence would put a reel on the managed queue where
MANAGED_TIMEOUT kills it at 85s with no reason recorded.

The reads stay separate by necessity: content.item_media is not written
until after dispatchMirrors() returns, so the dispatch side has only the
projection entries. Only the predicate is shared, pinned by a lockstep
test so a third copy cannot appear quietly.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Fo1Gn9bVHv8SG5ivrwZGxd
MSG
)"
```

---

### Task 4: `SitePublishState`

`ProjectionWriter::siteInSetup()` reads `site.sites.is_published` with the query builder, straight across a domain boundary, un-memoised — so it re-queries on every dispatch call. This unit does NOT undo the nine PG-lane stand-ins PR #335 had to give the column; the read still happens and the column is still required. What it buys is one stub point for tests, one place to change when "published" stops being a single boolean — which the pre-account carve-out means it already nearly isn't, since `is_published` and "publicly visible" diverged in the public read path on 2026-09-01 — and the removal of a redundant per-dispatch query.

**`null` is not `false`.** No site row must keep `siteInSetup()` returning `false` (i.e. NOT in setup — the published/videos-first default), exactly as today.

**`SourceScheduler` is deliberately untouched** (Correction 2): its `whereExists` is a correlated subquery inside a set query with a limit. Replacing it with a per-user memo would be N+1 and would change which sources the limit considers. It gains a comment only.

**Files:**
- Create: `app/Site/SitePublishState.php`
- Modify: `app/Providers/AppServiceProvider.php` (binding), `app/Ingest/Projection/ProjectionWriter.php` (ctor + `siteInSetup()`), `app/Ingest/Runtime/SourceScheduler.php` (comment)
- Test: `tests/Feature/Site/SitePublishStateTest.php` (create)

**Interfaces:**
- Produces: `App\Site\SitePublishState::isPublished(string $userId): ?bool` — `true` published, `false` unpublished, `null` no site row. Memoised per instance.
- Consumes: `scoped()` container binding, the idiom already used for `FetchBudget` (`AppServiceProvider.php:186`) and `ProbeBudget` (`:196`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Site/SitePublishStateTest.php`:

```php
<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Media\MirrorMediaAssetJob;
use App\Site\SitePublishState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// One seam for the ingest layer's cross-domain read of site.sites.is_published.
// Three states, not two: null ("no site row") is what keeps siteInSetup()
// answering false for a user who has no site at all.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Bus::fake();
});

it('reports true, false and null distinctly', function () {
    $published = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    $unpublished = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    $siteless = createTenant('sps-'.Str::lower(Str::random(6)))->id;

    DB::table('site.sites')->where('user_id', $published)->update(['is_published' => true]);
    DB::table('site.sites')->where('user_id', $unpublished)->update(['is_published' => false]);
    DB::table('site.sites')->where('user_id', $siteless)->delete();

    $state = app(SitePublishState::class);

    expect($state->isPublished($published))->toBeTrue()
        ->and($state->isPublished($unpublished))->toBeFalse()
        ->and($state->isPublished($siteless))->toBeNull();
});

it('issues one query for repeated reads of the same user', function () {
    // siteInSetup() was un-memoised and ran per dispatch call.
    $userId = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    $state = app(SitePublishState::class);

    $state->isPublished($userId);

    DB::enableQueryLog();
    $state->isPublished($userId);
    $state->isPublished($userId);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('memoises a null so a siteless user is not re-queried either', function () {
    $userId = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    DB::table('site.sites')->where('user_id', $userId)->delete();

    $state = app(SitePublishState::class);
    $state->isPublished($userId);

    DB::enableQueryLog();
    expect($state->isPublished($userId))->toBeNull();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('keeps a siteless user on the published mirror ordering', function () {
    // The null carve-out, end to end. No site row must NOT read as "in setup":
    // videos go first, per Item 9f.
    $userId = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    DB::table('site.sites')->where('user_id', $userId)->delete();

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:orderless-1', [
        'kind' => 'media',
        'headline' => null,
        'media' => [
            ['role' => 'cover', 'url' => 'https://scontent.cdninstagram.com/v/c.jpg', 'ref' => 'instagram:ORD1:0'],
            ['role' => 'video', 'url' => 'https://scontent.cdninstagram.com/v/v.mp4', 'ref' => 'instagram:ORD1:1'],
        ],
    ]);

    $order = collect(Bus::dispatched(MirrorMediaAssetJob::class))
        ->map(fn ($job) => $job->video)
        ->all();

    expect($order[0])->toBeTrue();
});

it('puts images first while the site is unpublished', function () {
    // The setup walk's media step is tiles of covers, and that is the screen
    // a new user is waiting on.
    $userId = createTenant('sps-'.Str::lower(Str::random(6)))->id;
    DB::table('site.sites')->where('user_id', $userId)->update(['is_published' => false]);

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:setup-1', [
        'kind' => 'media',
        'headline' => null,
        'media' => [
            ['role' => 'video', 'url' => 'https://scontent.cdninstagram.com/v/v.mp4', 'ref' => 'instagram:SET1:0'],
            ['role' => 'cover', 'url' => 'https://scontent.cdninstagram.com/v/c.jpg', 'ref' => 'instagram:SET1:1'],
        ],
    ]);

    $order = collect(Bus::dispatched(MirrorMediaAssetJob::class))
        ->map(fn ($job) => $job->video)
        ->all();

    expect($order[0])->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Feature/Site/SitePublishStateTest.php
```

Expected: FAIL — `Target class [App\Site\SitePublishState] does not exist.`

The last two cases (ordering) describe behaviour that is correct TODAY and must survive the refactor. If either fails once the class exists, the `null` semantics were not preserved.

- [ ] **Step 3: Create the class**

Create `app/Site/SitePublishState.php`:

```php
<?php

namespace App\Site;

use Illuminate\Support\Facades\DB;

/**
 * The ingest layer's one read of site.sites.is_published.
 *
 * Two raw cross-domain query-builder reads used to answer this, one of them
 * per dispatch call. This is a seam, not a cache: one stub point for tests,
 * one place to change when "published" stops being a single boolean — which
 * it already nearly isn't, since is_published and "publicly visible" diverged
 * in the public read path with the pre-account carve-out (2026-09-01).
 *
 * Bound scoped(), so the memo lives for one job / one request and is reset
 * between queue jobs. NOT a cache: nothing to invalidate, no TTL. Publish
 * state does not change mid-projection.
 */
final class SitePublishState
{
    /** @var array<string, bool|null> */
    private array $memo = [];

    /**
     * True published, false unpublished, NULL when the user has no site row.
     * Callers distinguish all three: a missing site is not an unpublished one.
     */
    public function isPublished(string $userId): ?bool
    {
        // array_key_exists, not ??=: a memoised null would otherwise re-query
        // on every call, which is the cost this class exists to remove.
        if (! array_key_exists($userId, $this->memo)) {
            $this->memo[$userId] = $this->read($userId);
        }

        return $this->memo[$userId];
    }

    private function read(string $userId): ?bool
    {
        $published = DB::table('site.sites')->where('user_id', $userId)->value('is_published');

        return $published === null ? null : (bool) $published;
    }
}
```

Note the `array_key_exists` guard: the spec's sketch used `??=`, which cannot memoise a `null` — the siteless case would re-query forever.

- [ ] **Step 4: Bind it scoped**

In `app/Providers/AppServiceProvider.php`, beside the existing `scoped()` bindings (near `:186-204`):

```php
        // scoped(), not singleton(): a queue worker's container resets scoped
        // instances between jobs, so the memo cannot outlive the projection
        // that built it. Same reasoning as FetchBudget above.
        $this->app->scoped(SitePublishState::class);
```

Add `use App\Site\SitePublishState;` to that file's imports.

- [ ] **Step 5: Inject it into `ProjectionWriter` and rewrite `siteInSetup()`**

Add to `ProjectionWriter`'s constructor (currently five promoted properties):

```php
    public function __construct(
        private readonly Resolver $resolver,
        private readonly ValueResolver $values,
        private readonly ContentItemSlugAllocator $slugs,
        private readonly IdentityKeyDeriver $identityKeys,
        private readonly IdentityScope $scope,
        private readonly SitePublishState $publishState,
    ) {}
```

Add `use App\Site\SitePublishState;` to its imports.

Replace `siteInSetup()` (currently `:3518-3527`, docblock included):

```php
    /**
     * True while the owner's site is unpublished — the window in which the
     * Get Started walk is the consumer, and covers matter more than reels.
     * No site row (null) reads as published: the 9f order is the safe default.
     */
    private function siteInSetup(string $userId): bool
    {
        $published = $this->publishState->isPublished($userId);

        return $published !== null && ! $published;
    }
```

- [ ] **Step 6: Comment why `SourceScheduler` does not use the seam**

In `app/Ingest/Runtime/SourceScheduler.php`, above the `whereExists` at `:138`:

```php
                    // NOT App\Site\SitePublishState (2026-09-05): this is a
                    // correlated subquery inside a set query with a LIMIT.
                    // A per-user boolean seam would fetch unfiltered rows and
                    // filter in PHP — N+1, and a different set of sources
                    // reaches the limit. The seam is for single-row callers.
                    ->whereExists(fn ($site) => $site
```

- [ ] **Step 7: Run the test to verify it passes**

```bash
php artisan test tests/Feature/Site/SitePublishStateTest.php
```

Expected: 5 passed.

- [ ] **Step 8: Run the ingest suite**

```bash
php artisan test tests/Feature/Ingest
```

Expected: all pass. `ProjectionWriter`'s constructor changed, so anything constructing it directly rather than through the container will surface here.

- [ ] **Step 9: Commit**

```bash
git add app/Site/SitePublishState.php app/Providers/AppServiceProvider.php app/Ingest/Projection/ProjectionWriter.php app/Ingest/Runtime/SourceScheduler.php tests/Feature/Site/SitePublishStateTest.php
git commit -m "$(cat <<'MSG'
Put a memoised seam in front of the ingest is_published read

ProjectionWriter::siteInSetup() read site.sites.is_published with the
query builder across a domain boundary, un-memoised, once per dispatch
call. SitePublishState gives it one stub point and one place to change
when "published" stops being a single boolean — which it already nearly
isn't, since is_published and "publicly visible" diverged in the public
read path on 2026-09-01.

null stays distinct from false: a user with no site row is NOT in setup.

SourceScheduler keeps its correlated subquery deliberately — routing a
set query with a LIMIT through a per-user seam would be N+1 and would
change which sources the limit considers. Commented in place.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Fo1Gn9bVHv8SG5ivrwZGxd
MSG
)"
```

---

### Task 5: Document the budget's real meaning, and verify the PR

Comments only, plus the full verification sweep for all five units.

`budgetMirrors()` caps at 10 image posts + 6 video posts per platform per pull, one asset per post, newest first, with already-mirrored posts consuming slots (the anti-creep property). The consequence nobody has written down: a signup gets one eager pass, so **a new site's grid can show at most 10 mirrored images**, with the rest arriving over subsequent 15-minute `ingest:dispatch` ticks. A second undocumented property: the anti-creep guarantee depends on `publishedByItem` being populated — when both sides are null the sort falls back to arrival order and "newest first" quietly becomes "whatever the vendor returned first".

The review's original framing — that 10/6 was coupled to the setup grid's tile count — was **wrong**. `media-grid.tsx` renders every item it is given and caps nothing. Do not write that.

**Files:**
- Modify: `config/partna.php` (the `pull_budget` comment block), `app/Ingest/Projection/ProjectionWriter.php` (the `budgetMirrors()` docblock)

- [ ] **Step 1: Extend the `pull_budget` config comment**

In `config/partna.php`, append to the existing comment block above `'pull_budget'`:

```php
        // A signup gets ONE eager pass, so a new site's grid shows at most
        // `images` mirrored pictures on arrival; the rest land over the
        // following 15-minute ingest:dispatch ticks. Not a grid setting — the
        // dashboard renders every item it is given and caps nothing.
        'pull_budget' => [
```

- [ ] **Step 2: Extend the `budgetMirrors()` docblock**

In `app/Ingest/Projection/ProjectionWriter.php`, add to the existing docblock above `budgetMirrors()`, after the anti-creep sentence:

```php
     * The anti-creep guarantee DEPENDS on $publishedByItem being populated.
     * With both sides null the sort falls through to arrival order, and
     * "newest first" quietly becomes "whatever the vendor returned first" —
     * the window still bounds the bytes, but stops meaning what it says.
```

- [ ] **Step 3: Commit the comments**

```bash
git add config/partna.php app/Ingest/Projection/ProjectionWriter.php
git commit -m "$(cat <<'MSG'
Write down what the mirror pull budget actually bounds

A signup gets one eager pass, so a new site's grid shows at most 10
mirrored images on arrival and the rest land over following dispatch
ticks — undocumented until now, and the thing someone reading "10" would
otherwise have to derive. The anti-creep property also depends on
publishedByItem being populated; without it the sort falls back to
arrival order.

Not a grid setting: the dashboard renders every item it is given.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Fo1Gn9bVHv8SG5ivrwZGxd
MSG
)"
```

- [ ] **Step 4: Formatting and static analysis**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: both clean. `pint --test` is the gate, not `pint` — run the `--test` form and read its output. If phpstan reports findings in files this branch did not touch, they are latent findings surfaced by the changed files; report them rather than fixing them here.

- [ ] **Step 5: The cheap lane**

```bash
composer test
```

Expected: green. ~20 minutes. This includes `PostgresLaneReadCoverageTest`, `PolicyCoverageTest`, the architecture guards and the no-Laravel-migrations guard.

- [ ] **Step 6: The Postgres lane — REQUIRED**

```bash
composer test:pg
```

Expected: green. **Do not skip this and do not substitute the cheap lane's result.** Units 1, 3 and 4 change what `PoolResolver` and `ProjectionWriter` read; CLAUDE.md is explicit that a green SQLite run says nothing about this lane, and it has been missed twice (slice 5a turned it red for 7 tests; `da958493e` left it red for ~15 consecutive runs while `postgres-tests` — a REQUIRED CI check — gave no signal at all).

If the lane is not runnable locally, say so plainly rather than reporting the cheap lane as if it covered this.

- [ ] **Step 7: Confirm no schema change slipped in**

```bash
git diff --stat production...HEAD -- supabase/ database/
```

Expected: empty. Every column Unit 1 reads already exists; nothing to push to Supabase, and production lacks the `content` schema entirely so none of this reaches it.

- [ ] **Step 8: Open the PR**

```bash
gh pr create --base development --title "Media mirror follow-ups: pending state, frozen thumb tier, one role rule, publish seam" --body "$(cat <<'MSG'
Five follow-ups from the review of PR #335, all latent or structural (Unit 1
was scoped as fixing one live defect; measurement against dev found that
claim false — see Correction 4 in the plan). Spec:
`docs/superpowers/specs/2026-09-05-media-mirror-followups-design.md`.

**1. `pending` derives from row state (latent).** It matched the source url
against two Meta CDN hosts while the mirror lane owns four platforms. On dev,
25 of 29 in-flight assets are TikTok and reported `pending: false` —
correctly, since `MediaUrlResolver` blocks raw passthrough for Meta hosts
only and serves a TikTok cover straight from its vendor CDN. A capped or
url-less row, however, reported `pending: true` forever — a permanent
skeleton, which is the real bug. Now read from `mirror_eligible` /
`storage_path` / `site_media_id` / `mirror_attempts` / `source_url` — extra
columns on a select already fetching those rows. Dashboard-only key; no wire change.

**2. Frozen thumb tier.** `THUMB_SUFFIX` promised 640 while the rendered
edge read config, so setting the var wrote mislabelled bytes with no
signal a backfill was owed. The var was never set on dev or prod, so
nothing mislabelled exists. `thumb_quality` stays configurable.

**3. One image-vs-video rule.** Dispatch and mirror decided the queue lane
independently; a divergence puts a reel on the managed queue where
`MANAGED_TIMEOUT` kills it at 85s. The reads stay separate — see below —
and only the predicate is shared, pinned by a lockstep test.

**4. `SitePublishState`.** One memoised seam for `ProjectionWriter`'s
cross-domain `is_published` read, replacing a per-dispatch query.
`null` (no site row) stays distinct from `false`.

**5. Budget documentation.** Comments only.

### Two spec premises were false and the plan corrects them

- **Unit 3's ordering premise.** The spec had `dispatchMirrors` read
  `content.item_media`. Those rows are not written until after
  `resolveMediaAssets()` returns, so the read would be empty for every
  freshly-minted asset and would flag every reel `video: false` — making
  the bug live. Implemented as the spec's own headline instead: share the
  RULE, keep the two reads.
- **Unit 4's `SourceScheduler` half.** That is a correlated `whereExists`
  inside a set query with a LIMIT, not a row read; a per-user memo would
  be N+1 and would change which sources the limit considers. It keeps its
  subquery, with a comment saying why.

Owner signed off on both, 2026-09-05.

### Verification

- `composer test` — green
- `composer test:pg` — green (required: Units 1/3/4 change what
  `ProjectionWriter` and `PoolResolver` read)
- `vendor/bin/phpstan analyse`, `vendor/bin/pint --test` — clean
- No migration. Every column read already exists; production lacks the
  `content` schema entirely, so none of this reaches it.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01Fo1Gn9bVHv8SG5ivrwZGxd
MSG
)"
```

---

## Out of scope — recorded, not actioned

**The thumb tier is unconsumed.** `MediaMirror` writes a 640px thumbnail beside every master, `MediaUrlResolver` returns it as `thumb`, `PoolResolver` ships it on items and frames. Nothing reads it — the dashboard's grid tile renders the master (`media-grid.tsx:314`, `PartnaAu/partna-monorepo` @ `73a845e2`). So the ~32 KB-vs-~260 KB win motivating the thumb-first work in `09edde246` describes a capability, not a measured outcome. The fix is one line in the frontend. **Owner decision 2026-09-05: do not change the frontend.**

Note the observability cost this exposes: `thumb` is derived from `storage_path` rather than stored, so there is no column and no failed lookup — an unconsumed field is indistinguishable from a consumed one on the backend side.

**Also out of scope:** multi-tier / `srcset` thumbnails (the future extension point, not built); changing the 10/6 budget numbers (Task 5 documents them, it does not tune them); prod reconciliation.
