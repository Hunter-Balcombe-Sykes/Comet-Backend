# Link-in-bio unroll onto the new router, with harvest auto-connect

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate `LinkInBioScanJob` off the legacy `LinkRouter` onto `LinkRoutingService` (the first of P8's nine consumers), and retune placement so harvest paths auto-connect every link that clears the suggest threshold — the owner's 2026-08-18 ruling: "connect as many as possible auto, for pre-account and always."

**Architecture:** One policy change in `PlacementPolicy` (harvest-origin suggest-band collapses Choose→Place when the margin is clean), one documented-tripwire fix (tombstone memo gets a TTL), then `LinkInBioImporter` gains the three behaviours the caller-owned side of the legacy job provided (note→custom-link card, unknown→commerce probe within a budget, zero-yield→bio-URL floor, conflict→notification), and finally `LinkInBioScanJob::handle()` swaps its body to the importer. Dispatchers, queue, and uniqueId are untouched.

**Tech Stack:** Laravel 12, Pest 4 (SQLite stand-in — `tests/Pest.php` DDL), the `app/Routing/*` pipeline, compiled catalog.

**Spec:** No standalone spec. The arguments live in: `docs/plans/2026-07-28-p8-deletion-readiness.md` (consumer table, B1–B5 blockers), `docs/reviews/2026-08-18-instagram-build-wave-RESULTS.md` finding N-B, and the owner rulings recorded in this plan's header. Measured verdicts that motivate the policy change (2026-08-18, `bio_harvest` origin, pre-penalty confidence): bare Bandcamp 60→choose, Bandcamp deep 52→note, Mixcloud 32→note, RA 28→note, YouTube 75→choose, Facebook 75→choose.

## Global Constraints

- **No Laravel migrations** — schema changes only via `supabase/migrations/` raw SQL. This plan needs none.
- **B5 gate is discharged**: `20260728120000_backfill_item_tombstones` is in dev's ledger (verified 2026-08-18). Do not re-check.
- **Owner ruling 2026-08-18**: harvest origins auto-apply the suggest band, for all users, always. Direct `paste` keeps its interactive flow.
- **Resident Advisor stays a link card** — detect-only by design; no connector exists. Do NOT "fix" it via detector strength or host tables. (Mixcloud WAS in this list; upstream `134f55853` made it connectable mid-plan, so under Task 1 it now places — the pins were updated to match.)
- **Regression pins that MUST keep passing unchanged**: `tests/Feature/Platforms/CatalogBackedClassificationTest.php` (classify() shapes), `tests/Feature/Routing/TombstoneResurrectionTest.php`, `tests/Feature/Platforms/LinkInBioScanJobTest.php` N2-floor tests (added at `019ce48f3`).
- **Tests that legitimately change**: any test pinning a `choose` verdict for a harvest origin now expects `place` — update the expectation and say so in the commit body, never delete the test.
- `IntegrationConnectionObserver` side-effects (ingest provisioning, cache lanes) hang off connection creation and are `$afterCommit` — no task below touches them.
- Run `composer test` before declaring any task done; the routing suite is `./vendor/bin/pest tests/Feature/Routing tests/Feature/Platforms tests/Feature/Brand`.

---

### Task 1: Harvest suggest-band auto-apply (PlacementPolicy)

**Files:**
- Modify: `app/Routing/PlacementPolicy.php:110-123` (the confidence block)
- Test: `tests/Feature/Routing/HarvestAutoApplyTest.php` (create)

**Interfaces:**
- Consumes: `RoutingContext::isDirectRequest()` (`app/Routing/RoutingContext.php:39` — true only for origin `'paste'`), `RoutingPolicy::{autoThreshold,suggestThreshold,minMargin,indirectPenalty}`.
- Produces: `PlacementPolicy::decide()` returns `Verdict::Place` for any matched, gate-passing projection whose post-penalty confidence ≥ `suggestThreshold(class)` AND margin ≥ `minMargin()`, when `!isDirectRequest()`. Direct paste behaviour is byte-identical to today. Tasks 3–5 rely on this.

- [ ] **Step 1: Write the failing tests**

```php
<?php

// Owner ruling 2026-08-18: "connect as many as possible auto, for pre-account
// and always." On a HARVEST origin (anything but 'paste'), a suggestion is
// useless-to-harmful: pre-claim there is no inbox to see it, post-claim it is
// one more tap for a link the user demonstrably published themselves. So the
// suggest band auto-applies — but ONLY when the margin is clean: a projection
// where two rules matched too closely (margin < minMargin) stays Choose, or
// we would be guessing WHICH surface to connect, not whether.

use App\Models\Core\User\User;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
});

// Bare Bandcamp measured 60 pre-penalty on 2026-08-18: suggest band for
// 'content' (45–69 post-penalty 50). Was 'choose'; now places.
it('auto-places a suggest-band content link from a harvest origin', function () {
    $pro = createTenant('harvest-band');

    $out = app(LinkRoutingService::class)->route(
        'https://kimcosmik.bandcamp.com/',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('place')
        ->and($out['connectionId'])->not->toBeNull();
});

// YouTube measured 75 pre-penalty: 65 post-penalty, below auto (70), inside
// suggest (45). The signup build connected YouTube on the legacy path; the
// new path must not regress that.
it('auto-places a suggest-band social link from a harvest origin', function () {
    $pro = createTenant('harvest-social');

    $out = app(LinkRoutingService::class)->route(
        'https://www.youtube.com/channel/UCCY6-AIHHvrmZW5J8IAjk-A',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('place')
        ->and($out['connectionId'])->not->toBeNull();
});

// Direct paste keeps the interactive flow — the dashboard's confirm UI is a
// wire contract (RoutingController), and paste already auto-applies at 70+.
it('keeps the suggest band as a suggestion on a direct paste', function () {
    $pro = createTenant('paste-band');

    $out = app(LinkRoutingService::class)->route(
        'https://kimcosmik.bandcamp.com/',
        RoutingContext::forUser($pro, 'paste'),
    );

    expect($out['verdict'])->toBe('choose')
        ->and($out['connectionId'])->toBeNull();
});

// Below the suggest floor nothing changes: still a Note, never a connection.
it('keeps a below-suggest harvest link as a note', function () {
    $pro = createTenant('harvest-note');

    $out = app(LinkRoutingService::class)->route(
        'https://www.mixcloud.com/KimCosmik/', // 32 pre-penalty, and unservable anyway
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('note')
        ->and($out['connectionId'])->toBeNull();
});

// Tombstones still beat maximisation: a harvest must never resurrect a
// refusal (C8). Same shape as TombstoneResurrectionTest, pinned here so the
// new Place path is the one proven, not the old Choose path.
it('does not auto-place a tombstoned surface from a harvest origin', function () {
    $pro = createTenant('harvest-tombstoned');

    \Illuminate\Support\Facades\DB::table('routing.item_tombstones')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => $pro->id,
        'source_ref' => 'bandcamp.artist:kimcosmik',
        'scope' => 'this_source',
        'reason' => 'test refusal',
        'created_at' => now(),
    ]);

    $out = app(LinkRoutingService::class)->route(
        'https://kimcosmik.bandcamp.com/',
        RoutingContext::forUser($pro, 'bio_harvest'),
    );

    expect($out['verdict'])->toBe('reject')
        ->and($out['connectionId'])->toBeNull();
});
```

- [ ] **Step 2: Run to verify the new expectations fail**

Run: `./vendor/bin/pest tests/Feature/Routing/HarvestAutoApplyTest.php`
Expected: the two auto-place tests FAIL (verdict is `choose`); paste/note/tombstone tests PASS (they pin current behaviour).

- [ ] **Step 3: Implement — the confidence block in `PlacementPolicy::decide()`**

Replace lines 110–122 (`if ($confidence >= $auto …` through the Choose return) with:

```php
        if ($confidence >= $auto && $projection->margin >= RoutingPolicy::minMargin()) {
            return new Placement(Verdict::Place, $surfaceKey, $projection->identifier);
        }

        if ($confidence >= $suggest) {
            // Harvest maximisation (owner ruling 2026-08-18): on any indirect
            // origin the suggest band auto-applies. Pre-claim there is no
            // inbox to see a suggestion; post-claim it is friction on a link
            // the user demonstrably published. Margin still gates — a
            // too-close projection stays Choose because the doubt there is
            // WHICH surface, not whether. Direct paste keeps the confirm
            // flow: it is a wire contract with the dashboard preview.
            if (! $context->isDirectRequest() && $projection->margin >= RoutingPolicy::minMargin()) {
                return new Placement(Verdict::Place, $surfaceKey, $projection->identifier);
            }

            $why = $confidence < $auto
                ? 'below auto-apply threshold'
                : 'two rules matched too closely to decide automatically';

            return new Placement(Verdict::Choose, $surfaceKey, $projection->identifier, 'below_threshold', $why);
        }

        return new Placement(Verdict::Note, $surfaceKey, $projection->identifier, 'below_threshold', 'kept as a link');
```

- [ ] **Step 4: Run the new test file, then the wider routing suites**

Run: `./vendor/bin/pest tests/Feature/Routing/HarvestAutoApplyTest.php` → all PASS.
Run: `./vendor/bin/pest tests/Feature/Routing tests/Feature/Brand tests/Feature/Platforms` → any failure will be a test pinning `choose`/`suggested` on a harvest origin (WebsiteImporter, SuggestionsInbox, StoreBrandSeeder shapes). For each: if it pins an origin ≠ `paste`, update the expected verdict to `place` (and expected connection to non-null) with a one-line comment citing the 2026-08-18 ruling. If it pins `paste`, the code is wrong, not the test — stop and re-check Step 3.

- [ ] **Step 5: Commit**

```bash
git add app/Routing/PlacementPolicy.php tests/Feature/Routing/HarvestAutoApplyTest.php <any updated pins>
git commit -m "feat(routing): harvest origins auto-apply the suggest band (owner ruling 2026-08-18)"
```

---

### Task 2: Tombstone memo TTL (the documented tripwire)

**Files:**
- Modify: `app/Routing/PlacementPolicy.php:26-36` (memo property) and `:178-213` (`isTombstoned`)
- Test: `tests/Feature/Routing/TombstoneMemoTtlTest.php` (create)

**Interfaces:**
- Consumes: nothing new.
- Produces: the memo self-expires, so a tombstone written concurrently (SuggestionsController::dismiss in another request) is honoured within `TOMBSTONE_MEMO_TTL_SECONDS`. This discharges the tripwire at `PlacementPolicy.php:171-176` ("if either importer is ever wired into a live request path, this memo needs invalidation or a narrower scope **before that ships**") — which Task 5 trips.

- [ ] **Step 1: Write the failing test**

```php
<?php

// SCALE-20 gave batch imports a per-run tombstone memo; its own comment
// declares the tripwire: wiring an importer into a live path requires
// invalidation or narrower scope first, because a dismissal landing mid-run
// (a DIFFERENT request/process — in-memory invalidation cannot reach it) is
// invisible for the whole run. A TTL narrows the staleness window back to
// approximately the pre-memo per-link window without giving up the batch
// query savings.

use App\Routing\PlacementPolicy;
use App\Routing\Projection;
use App\Routing\RoutingContext;
use App\Routing\Verdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function bandcampProjection(): Projection
{
    return new Projection(
        surfaceKey: 'bandcamp.artist',
        detectorId: 'test',
        captures: [],
        confidence: 90,
        margin: 100,
        identifier: 'kimcosmik',
        reason: null,
    );
}

it('honours a tombstone inserted after the memo was primed, once the ttl lapses', function () {
    $pro = createTenant('memo-ttl');
    $policy = new PlacementPolicy;
    $ctx = new RoutingContext($pro, 'bio_harvest', false, (string) Str::uuid());

    // Prime the memo: no tombstones yet, link places.
    expect($policy->decide(bandcampProjection(), $ctx)->verdict)->toBe(Verdict::Place);

    // A concurrent dismiss lands (different request in production).
    DB::table('routing.item_tombstones')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'source_ref' => 'bandcamp.artist:kimcosmik',
        'scope' => 'this_source',
        'reason' => 'dismissed mid-run',
        'created_at' => now(),
    ]);

    // Within the TTL the memo may still answer stale — not asserted either
    // way. After the TTL it MUST see the refusal.
    $policy->agePrimedMemoForTest();

    expect($policy->decide(bandcampProjection(), $ctx)->verdict)->toBe(Verdict::Reject);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Routing/TombstoneMemoTtlTest.php`
Expected: FAIL — `agePrimedMemoForTest` undefined (and without it the memo would answer stale forever).

- [ ] **Step 3: Implement the TTL**

In `PlacementPolicy`, extend the memo shape and expiry check:

```php
    /** Seconds a primed memo may serve before re-reading the table. Bounds the
     * concurrent-dismiss race (see the Accepted-not-fixed note below) to a
     * window comparable to the pre-memo per-link EXISTS. */
    private const TOMBSTONE_MEMO_TTL_SECONDS = 5;

    /** @var array{key: string, at: float, refs: array<string, int>}|null */
    private ?array $tombstoneMemo = null;

    /** Test seam: expire the primed memo without sleeping. */
    public function agePrimedMemoForTest(): void
    {
        if ($this->tombstoneMemo !== null) {
            $this->tombstoneMemo['at'] = 0.0;
        }
    }
```

and in `isTombstoned()` replace the prime condition:

```php
        $key = $user->id.'|'.$context->importRunId;
        $expired = $this->tombstoneMemo !== null
            && (microtime(true) - $this->tombstoneMemo['at']) > self::TOMBSTONE_MEMO_TTL_SECONDS;

        if ($this->tombstoneMemo === null || $this->tombstoneMemo['key'] !== $key || $expired) {
            $sourceRefs = DB::table('routing.item_tombstones')
                ->where('user_id', $user->id)
                ->pluck('source_ref');

            $this->tombstoneMemo = ['key' => $key, 'at' => microtime(true), 'refs' => array_flip($sourceRefs->all())];
        }
```

Then rewrite the "Accepted, not fixed" paragraph of the `isTombstoned()` docblock (lines ~171-176): the race window is now ≤ `TOMBSTONE_MEMO_TTL_SECONDS`, the tripwire is discharged, and the importer wiring lands in this same plan (cite this file).

- [ ] **Step 4: Run the test file + the tombstone pins**

Run: `./vendor/bin/pest tests/Feature/Routing/TombstoneMemoTtlTest.php tests/Feature/Routing/TombstoneResurrectionTest.php`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Routing/PlacementPolicy.php tests/Feature/Routing/TombstoneMemoTtlTest.php
git commit -m "fix(routing): tombstone memo self-expires — discharges the SCALE-20 live-path tripwire"
```

---

### Task 3: LinkInBioImporter parity — noted links become cards, unknowns get probed, zero-yield floors

**Files:**
- Modify: `app/Routing/Importers/LinkInBioImporter.php`
- Test: `tests/Feature/Routing/LinkInBioImporterParityTest.php` (create)

**Interfaces:**
- Consumes: `CustomLinkSeeder::seedCustom(User $user, string $url): ?IntegrationConnection` (`app/Services/Platforms/CustomLinkSeeder.php:73`), `CommerceProbeJob::dispatch(string $userId, string $url)` (`app/Jobs/Platforms/CommerceProbeJob.php:66-73`), `LinkRoutingService::route()` return keys `verdict` / `reason` / `connectionId`.
- Produces: `import()` return array gains `'probed' => int` and `'bio_url_seeded' => bool`. Task 4's regression test and Task 5's job swap rely on: note-verdict matched links exist as custom-link cards; unknown-domain notes dispatch `CommerceProbeJob` (≤ 6 per run) and are NOT carded by the importer (the probe job cards its own failures — double-writing is the bug to avoid); a run whose routable yield is zero seeds the bio URL itself.

The verdict-to-action table this task implements (mirrors what `LinkInBioScanJob` + `CommerceProbeJob` do today, expressed on the new pipeline):

| `route()` outcome | action |
|---|---|
| `place` | nothing — connection exists (tally `connected`) |
| `choose` / `hold` | nothing — intent exists, folded by `SyncFindingsBridge` (tally `suggested`) |
| `note`, reason `unknown-domain` / `no-rule-matched`, probe budget left | `CommerceProbeJob::dispatch()` — no card now; the job seeds product / store / custom-link itself (tally `probed`) |
| `note`, any other reason (unservable, retired, gate, below_threshold), or budget spent | `CustomLinkSeeder::seedCustom()` (tally `noted`) |
| `reject` | nothing — unroutable by design (tally `noted`, no card; matches legacy skip) |

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\User\User;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Content\LinkPoolReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    setupRoutingTables();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function importerSite(User $user): User
{
    $site = new \App\Models\Core\Site\Site(['subdomain' => 'imp'.substr((string) $user->id, 0, 8), 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

it('cards a matched-but-unconnectable link instead of dropping it', function () {
    Queue::fake();
    $pro = importerSite(createTenant('imp-noted'));
    Http::fake(['linktr.ee/*' => Http::response(
        '<a href="https://www.mixcloud.com/KimCosmik/">Mixes</a>', 200,
    )]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/kimcosmik');

    $cards = app(LinkPoolReader::class)->cards($pro->refresh());
    expect($result['noted'])->toBe(1)
        ->and($cards)->toHaveCount(1)
        ->and($cards[0]['url'])->toBe('https://www.mixcloud.com/KimCosmik/');
    Queue::assertNotPushed(CommerceProbeJob::class); // recognised host: no probe
});

it('probes an unknown host instead of carding it, within a budget of six', function () {
    Queue::fake();
    $pro = importerSite(createTenant('imp-probe'));
    $anchors = '';
    foreach (range(1, 8) as $i) {
        $anchors .= '<a href="https://unknown-shop-'.$i.'.example/">S'.$i.'</a>';
    }
    Http::fake(['linktr.ee/*' => Http::response($anchors, 200)]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/shops');

    Queue::assertPushed(CommerceProbeJob::class, 6);          // budget parity with legacy
    expect($result['probed'])->toBe(6);
    // The two past-budget links must be CARDED — silent truncation is the
    // failure mode 3.9 hunts; a probe-starved link still lands somewhere.
    $urls = array_column(app(LinkPoolReader::class)->cards($pro->refresh()), 'url');
    expect($urls)->toContain('https://unknown-shop-7.example/')
        ->and($urls)->toContain('https://unknown-shop-8.example/');
});

it('seeds the bio url itself when the page yields nothing routable', function () {
    Queue::fake();
    $pro = importerSite(createTenant('imp-floor'));
    // The linkin.bio shape: 200, chrome only, zero anchors.
    Http::fake(['linkin.bio/*' => Http::response('<html><body><div id="app"></div></body></html>', 200)]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linkin.bio/supernormal_180', 'bio_harvest');

    expect($result['bio_url_seeded'])->toBeTrue();
    $cards = app(LinkPoolReader::class)->cards($pro->refresh());
    expect($cards)->toHaveCount(1)
        ->and($cards[0]['url'])->toBe('https://linkin.bio/supernormal_180');
});

it('does not seed the bio url when something routed', function () {
    Queue::fake();
    $pro = importerSite(createTenant('imp-no-floor'));
    Http::fake(['linktr.ee/*' => Http::response(
        '<a href="https://www.mixcloud.com/KimCosmik/">Mixes</a>', 200,
    )]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/kimcosmik');

    expect($result['bio_url_seeded'])->toBeFalse();
    $urls = array_column(app(LinkPoolReader::class)->cards($pro->refresh()), 'url');
    expect($urls)->not->toContain('https://linktr.ee/kimcosmik');
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Routing/LinkInBioImporterParityTest.php`
Expected: FAIL — `import()` has no `probed`/`bio_url_seeded` keys, no cards are written, no probes dispatched.

- [ ] **Step 3: Implement in `LinkInBioImporter`**

Constructor gains the seeder (the router stays out of card-writing — Note cards are caller-owned on this pipeline, same as `RoutingController`):

```php
    /** Probe budget per RUN — parity with the legacy RouteContext::DEFAULT_MAX_PROBES. */
    private const MAX_PROBES = 6;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly WebsiteLinkHarvester $harvester,
        private readonly LinkRoutingService $routing,
        private readonly CustomLinkSeeder $seeder,
    ) {}
```

`import()`: extend `$tally` with `'probed' => 0`; after the page loop, add the floor before `ImportRun::finish` (and record both in `detail:` and the return):

```php
        // Zero-yield floor (N2): a MATCHED bio host that unrolls to nothing is
        // a silent total loss — the detector claimed the URL, so no other path
        // will ever write it. linkin.bio (an Ember SPA, zero anchors in the
        // delivered shell) is the live case. Keyed on nothing-routable, so an
        // all-own-chrome page floors identically.
        $bioUrlSeeded = false;
        if ($fetched > 0 && count($seen) === 0 && $context->user !== null) {
            $this->seeder->seedCustom($context->user, $pages[0]);
            $bioUrlSeeded = true;
        }
```

`unroll()`: replace the verdict match with the action table:

```php
            $result = $this->routing->route($url, $context);

            match ($result['verdict']) {
                'place' => $tally['connected']++,
                'choose', 'hold' => $tally['suggested']++,
                default => $this->handleUnrouted($url, $result, $context, $tally),
            };
```

and add:

```php
    /**
     * A link the router did not connect still belongs to the user. Unknown
     * domains get the legacy commerce probe (the new pipeline's probe set is
     * still 1 of 5 — P8 blocker 1), which seeds a product, a store, or its
     * own custom-link fallback; everything else is carded here, because Note
     * cards are caller-owned on this pipeline (RoutingController does the
     * same). Past the probe budget, unknowns are carded directly — a starved
     * link must land somewhere visible, never vanish.
     *
     * @param  array<string, mixed>  $result
     * @param  array{connected:int, suggested:int, noted:int, probed:int, skipped_chrome:int}  $tally
     */
    private function handleUnrouted(string $url, array $result, RoutingContext $context, array &$tally): void
    {
        if ($context->user === null) {
            $tally['noted']++;

            return;
        }

        $unknown = $result['verdict'] === 'note'
            && in_array($result['reason'] ?? null, ['unknown-domain', 'no-rule-matched'], true);

        if ($unknown && $tally['probed'] < self::MAX_PROBES) {
            CommerceProbeJob::dispatch((string) $context->user->id, $url);
            $tally['probed']++;

            return;
        }

        $tally['noted']++;

        if ($result['verdict'] === 'note') {
            $this->seeder->seedCustom($context->user, $url);
        }
        // 'reject' is carded nowhere, deliberately: unroutable by the
        // canonicaliser (own-infra, shortener, malformed) — legacy skipped
        // these too.
    }
```

Check `describe()` in `LinkRoutingService` exposes `reason` — if the preview shape lacks a top-level `reason` key for Note verdicts, add it there (one line, `'reason' => $placement->blockReason`) rather than parsing explanations.

- [ ] **Step 4: Run the file, then the existing importer tests**

Run: `./vendor/bin/pest tests/Feature/Routing/LinkInBioImporterParityTest.php` → PASS.
Run: `./vendor/bin/pest tests/Feature/Routing/ --filter Importer` → existing `LinkInBioImporter`/`WebsiteImporter` tests still green (constructor change is additive via the container).

- [ ] **Step 5: Commit**

```bash
git add app/Routing/Importers/LinkInBioImporter.php app/Routing/LinkRoutingService.php tests/Feature/Routing/LinkInBioImporterParityTest.php
git commit -m "feat(routing): LinkInBioImporter cards notes, probes unknowns in budget, floors zero-yield runs"
```

---

### Task 4: Conflict notification parity

**Files:**
- Modify: `app/Routing/Importers/LinkInBioImporter.php` (end of `import()`)
- Test: append to `tests/Feature/Routing/LinkInBioImporterParityTest.php`

**Interfaces:**
- Consumes: `FindingsNotifier::notify(string $userId, string $dedupeKey, string $title, string $body): void` (`app/Services/Notifications/FindingsNotifier.php:19`); Hold intents written by `SourceReconciler` carry `import_run_id`, `state = 'blocked'`, `block_reason = 'conflict'`.
- Produces: one deduped notification per run that produced ≥ 1 conflict — the legacy `mergeFindingsBack` raised exactly this; `SyncFindingsBridge` folds the findings into the synced modal at read time (B4), but the bridge never notifies, so without this task a conflict discovered in an unroll is silent.

- [ ] **Step 1: Write the failing test**

```php
it('raises one notification when the unroll finds a conflict', function () {
    Queue::fake();
    $pro = importerSite(createTenant('imp-conflict'));
    // An incumbent booking connection…
    \App\Models\Core\Site\IntegrationConnection::create([
        'user_id' => $pro->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/incumbent-venue'], 'is_active' => true,
    ]);
    // …and a bio page carrying a DIFFERENT venue: XOR holds it as a conflict.
    Http::fake(['linktr.ee/*' => Http::response(
        '<a href="https://www.fresha.com/a/other-venue-x1y2z3">Book</a>', 200,
    )]);

    app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/venue', 'bio_harvest');

    expect(\Illuminate\Support\Facades\DB::connection('pgsql')
        ->table('notifications.notifications')->where('user_id', $pro->id)->count())->toBe(1);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Routing/LinkInBioImporterParityTest.php --filter conflict`
Expected: FAIL — 0 notifications.

- [ ] **Step 3: Implement — after `ImportRun::finish()` in `import()`**

```php
        // Conflict parity with the legacy job: the findings themselves are
        // folded into GET /platforms/instagram/synced at read time
        // (SyncFindingsBridge, B4) — but the legacy path also TOLD the user,
        // and a fold nobody opens is a finding nobody sees. Same dedupe-key
        // shape as LinkInBioScanJob's, so re-runs do not stack.
        if ($context->user !== null) {
            $conflicts = DB::table('routing.source_intents')
                ->where('import_run_id', $runId)
                ->where('state', 'blocked')
                ->where('block_reason', 'conflict')
                ->count();

            if ($conflicts > 0) {
                app(FindingsNotifier::class)->notify(
                    (string) $context->user->id,
                    'link-in-bio-findings:'.$context->user->id.':'.sha1($pages[0]),
                    'We found more in your bio link',
                    'Your link-in-bio page mentions an integration that clashes with one you have connected — review it in Integrations.',
                );
            }
        }
```

(Add `use App\Services\Notifications\FindingsNotifier;` and `use Illuminate\Support\Facades\DB;`.)

- [ ] **Step 4: Run the parity file**

Run: `./vendor/bin/pest tests/Feature/Routing/LinkInBioImporterParityTest.php` → all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Routing/Importers/LinkInBioImporter.php tests/Feature/Routing/LinkInBioImporterParityTest.php
git commit -m "feat(routing): unroll conflicts notify like the legacy path (B4 fold covers the modal, not the ping)"
```

---

### Task 5: Swap LinkInBioScanJob onto the importer

**Files:**
- Modify: `app/Jobs/Platforms/LinkInBioScanJob.php` (handle() body + constructor deps; class name, queue, `uniqueId()`, dispatch sites all unchanged)
- Test: `tests/Feature/Platforms/LinkInBioScanJobTest.php` (existing — expectations updated where the ruling changes them)

**Interfaces:**
- Consumes: `LinkInBioImporter::import(User, string, 'link_in_bio'): array` (Tasks 3–4 shape).
- Produces: the job becomes a thin shell: resolve user → `import()` → one completion log line. `mergeFindingsBack()`, the `RouteContext` loop, `FindingsNotifier` import and the in-job N2 floor are DELETED — each behaviour now lives in the importer or the bridge. The four dispatchers (`RoutingController`, `GoogleBusinessEnrichJob`, `ScanPreviousWebsiteContentJob`, `InstagramAutoSync`) keep working unchanged.

- [ ] **Step 1: Update the existing test file's changed expectations FIRST**

In `tests/Feature/Platforms/LinkInBioScanJobTest.php`:
- The `handle()` signature changes — update every direct `->handle(...)` call to `->handle(app(\App\Routing\Importers\LinkInBioImporter::class))`.
- Tests that assert a payload `syncFindings` write (`mergeFindingsBack` behaviour, ~lines 160–345) now assert the NEW contract: a blocked intent exists (`routing.source_intents`, `block_reason = 'conflict'`) and one notification row — cite B4/`SyncFindingsBridge` in a comment. Do not delete the scenarios; they are the conflict coverage.
- The three N2-floor tests (`019ce48f3`) must pass UNCHANGED — the floor moved into the importer, behaviour identical.
- Booking/gate tests asserting `custom` outcomes keep their card assertions (`LinkPoolReader`), since the importer cards notes the same way.

- [ ] **Step 2: Run to verify the updated file fails against the old job**

Run: `./vendor/bin/pest tests/Feature/Platforms/LinkInBioScanJobTest.php`
Expected: FAIL — the old `handle()` signature doesn't match.

- [ ] **Step 3: Rewrite `handle()`**

```php
    public function handle(LinkInBioImporter $importer): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        // Everything the legacy body did line-by-line now lives behind
        // import(): chrome skip, per-link routing (LinkRoutingService — the
        // P8 successor), note→card, unknown→probe budget, zero-yield floor,
        // conflict notification. This job exists only as the queued shell the
        // four dispatch sites already know how to reach.
        $result = $importer->import($user, $this->bioPageUrl, 'link_in_bio');

        Log::info('platforms.link_in_bio_scan.completed', [
            'user_id' => $this->userId,
            'bio_page_url' => $this->bioPageUrl,
            ...$result,
        ]);
    }
```

Delete: the `SafeUrlFetcher`/`WebsiteLinkHarvester`/`LinkRouter`/`CustomLinkSeeder` imports and params, the whole routing loop, the in-job floor block, `mergeFindingsBack()` and its `FindingsNotifier`/`Platform`/`RouteContext` imports. Keep `$autoConnectBooking` on the constructor (dispatchers pass it) but mark it vestigial in a docblock: on the new pipeline booking exclusivity is reconciler-owned XOR — a second venue holds as a conflict regardless of the flag.

- [ ] **Step 4: Run the job file + the four dispatchers' suites**

Run: `./vendor/bin/pest tests/Feature/Platforms/LinkInBioScanJobTest.php` → PASS.
Run: `./vendor/bin/pest tests/Feature/Platforms tests/Feature/Routing tests/Feature/PreAccount` → PASS (the PreAccount suite exercises `InstagramAutoSync`'s dispatch of this job).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Platforms/LinkInBioScanJob.php tests/Feature/Platforms/LinkInBioScanJobTest.php
git commit -m "feat(routing): LinkInBioScanJob runs on LinkInBioImporter — first P8 consumer migrated (1 of 9)"
```

---

### Task 6: End-to-end wave regression pin + docs

**Files:**
- Create: `tests/Feature/Routing/KimcosmikWavePinTest.php`
- Modify: `docs/plans/2026-07-28-p8-deletion-readiness.md` (consumer table: LinkInBioScanJob → migrated), `docs/reviews/2026-08-12-instagram-build-wave-DEFERRED.md` (N2 entry: floor shipped at `019ce48f3`, importer parity here)

**Interfaces:**
- Consumes: everything above.
- Produces: one test that pins the whole 2026-08-18 `kimcosmik` ledger through the NEW path, so the next build wave can diff against an executable expectation instead of a markdown table.

- [ ] **Step 1: Write the pin (it should PASS immediately — it is a pin, not TDD)**

```php
<?php

// The 2026-08-18 build wave's kimcosmik ledger, replayed through the migrated
// unroll path. If a future change moves any of these buckets, this test names
// the link that moved. Fixture anchors mirror the live Linktree as fetched
// 2026-08-18 (15 off-host anchors).

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Content\LinkPoolReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    setupRoutingTables();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

it('replays the kimcosmik ledger: connections, cards, and probes land where the ruling says', function () {
    Queue::fake();
    $pro = createTenant('kimcosmik-pin');
    $site = new \App\Models\Core\Site\Site(['subdomain' => 'kcpin', 'is_published' => true, 'settings' => []]);
    $site->user()->associate($pro);
    $site->save();
    $pro->refresh();

    $anchors = implode('', array_map(fn ($u) => '<a href="'.$u.'">x</a>', [
        'https://obskurmusic.bandcamp.com/track/carissa-illy-i-look-good-kim-cosmik-remix',
        'https://kimcosmik.bandcamp.com/album/star-glider',
        'https://kimcosmik.bandcamp.com/',
        'https://www.mixcloud.com/KimCosmik/',
        'https://www.youtube.com/channel/UCCY6-AIHHvrmZW5J8IAjk-A',
        'https://ra.co/dj/kimcosmik',
        'https://www.instagram.com/kimcosmik/',
        'https://www.facebook.com/kimcosmik/',
        'https://www.discogs.com/search?q=kim+cosmik&type=all',
        'https://cybersoul.bandcamp.com/',
        'https://www.youtube.com/@cybersoul9038',
        'https://www.facebook.com/groups/3004349706304446/',
        'https://www.facebook.com/hybridrave',
        'https://www.juno.co.uk/products/kim-cosmik-arsonist-recorder-hybrid-collective-vol-1-vinyl/952291-01/',
        'https://discord.com/invite/q3FvffbQ',
    ]));
    Http::fake(['linktr.ee/*' => Http::response($anchors, 200)]);

    $result = app(LinkInBioImporter::class)->import($pro, 'https://linktr.ee/kimcosmik', 'bio_harvest');

    // Connections: platforms whose projection clears the suggest band with a
    // clean margin under the 2026-08-18 ruling. Bare Bandcamp (60) is in;
    // the deep album/track Bandcamp URLs (52 pre-penalty → 42 post) are
    // BELOW suggest and stay cards. Mixcloud/RA are unservable by design.
    $platforms = IntegrationConnection::where('user_id', $pro->id)->pluck('platform');
    expect($platforms)->toContain('youtube')
        ->and($platforms)->toContain('facebook')
        ->and($platforms)->toContain('discord')
        ->and($platforms)->toContain('bandcamp');

    // Exactly one connection per platform — the dedupe that 3.7 measures.
    expect($platforms->count())->toBe($platforms->unique()->count());

    // Unknown hosts (discogs, juno) were probed, not carded here.
    Queue::assertPushed(CommerceProbeJob::class, fn ($job) => str_contains($job->url, 'discogs.com'));
    Queue::assertPushed(CommerceProbeJob::class, fn ($job) => str_contains($job->url, 'juno.co.uk'));

    // Known-but-unconnectable landed as cards, not nothing.
    $urls = array_column(app(LinkPoolReader::class)->cards($pro->refresh()), 'url');
    expect($urls)->toContain('https://www.mixcloud.com/KimCosmik/')
        ->and($urls)->toContain('https://ra.co/dj/kimcosmik');

    // Ledger balance — §3.6, executable: every anchor accounted for.
    expect($result['connected'] + $result['suggested'] + $result['noted'] + $result['probed'])
        ->toBe(15 - 1); // minus instagram.com/kimcosmik: rejected as the user's own source platform, or held — assert whichever the run reports, and comment the actual bucket here when first run
});
```

Note for the implementer: the Instagram self-link's bucket (`reject` vs `hold`) and the Facebook trio's exact split (first `place`, later ones cap-held vs noted) must be read from the FIRST run's `$result` and pinned as observed — the point is to freeze the real behaviour, not to guess it in this plan. Adjust the two commented assertions accordingly on first run, with a comment naming each bucket.

- [ ] **Step 2: Run it, resolve the two observed-bucket assertions, run again**

Run: `./vendor/bin/pest tests/Feature/Routing/KimcosmikWavePinTest.php` → PASS after pinning observed buckets.

- [ ] **Step 3: Update the two docs**

In `docs/plans/2026-07-28-p8-deletion-readiness.md`, consumer table: `LinkInBioScanJob` row → `**MIGRATED 2026-08-18** — thin shell over LinkInBioImporter; see docs/superpowers/plans/2026-08-18-linkinbio-unroll-migration.md`, and change the status line to "9 consumers, 1 migrated". In `docs/reviews/2026-08-12-instagram-build-wave-DEFERRED.md`, tick N2's checkbox and append: floor shipped `019ce48f3`, importer-path parity + migration in this plan; real SPA unroll (headless render / linkin.bio API) remains open and deliberately unstarted.

- [ ] **Step 4: Full suite**

Run: `composer test`
Expected: green. Any failure outside the files this plan touches → stop, investigate with superpowers:systematic-debugging, do not "fix forward".

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Routing/KimcosmikWavePinTest.php docs/plans/2026-07-28-p8-deletion-readiness.md docs/reviews/2026-08-12-instagram-build-wave-DEFERRED.md
git commit -m "test(routing): kimcosmik wave ledger pinned end-to-end; P8 ledger 1 of 9 migrated"
```

---

## Explicitly out of scope (say no here so nobody re-litigates)

- **Resident Advisor connector** — detect-only by owner design; a connection with no fetch path is the F7 bare-row mistake again. Link card is the correct terminal state until a connector exists. (Mixcloud got its connector upstream in `134f55853` the same day.)
- **`InstagramAutoSync` migration** (bio-direct links) — consumer 2 of 9, same recipe, its own plan. The policy change in Task 1 already applies to it the day it migrates.
- **Real SPA unroll for linkin.bio** (headless render or their private API) — new SSRF surface, new dependency, owner decision. The floor keeps it non-lossy meanwhile.
- **Threshold retuning** (`RoutingPolicy::THRESHOLDS`) — the suggest-band collapse achieves the ruling without touching the numbers; retune from `detector_observations` data later, not from this wave's n=6.
