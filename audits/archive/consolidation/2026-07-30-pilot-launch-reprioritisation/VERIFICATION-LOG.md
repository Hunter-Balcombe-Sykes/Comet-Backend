# Verification log — 2026-07-30

Raw evidence behind every priority tag in `CONSOLIDATED.md`. Six verification agents checked 48 open
audit findings against the code as it exists on `guard/postgres-lane-walker`, read-only.

**Result: 12 of 48 (25%) were dead** — 9 already fixed, 3 phantom findings that were wrong when written.

| Batch | Scope | Items |
|---|---|---|
| V1 | CI / test-lane findings | 6 |
| V2 | 2026-07-11 sweep staleness sample | 14 |
| V3 | Backend-inheritance findings | 3 |
| V4 | Launch-check live-system state | 7 |
| V5 | 2026-07-28 pilot-critical shortlist | 9 |
| V6 | Privacy / GDPR findings | 9 |

Not verified: the 251 BACKLOG items. Staleness there does not change the decision to defer, so
backlog counts are an upper bound rather than a measured figure.

---



<!-- ===== V1-ci-tests ===== -->

# V1 — CI / Test Coverage Findings Verification

Repo: `/Users/joshuahunter/Herd/Side Street/backend`, branch `guard/postgres-lane-walker` (read-only, no changes made).

## #TEST-2 — VERDICT: PARTIAL

Evidence:
- `.github/workflows/ci.yml` now has three real-Postgres-backed jobs: `postgres-tests` (line 314, runs `tests/Postgres/` via `phpunit.pg.xml` / `composer test:pg`), `schema-tests` (line 378, applies every migration from zero then runs `tests/Schema/` via `phpunit.schema.xml` / `composer test:schema`), and `schema-drift` (line 445).
- `tests/Schema/CheckConstraintsTest.php` (git-moved there in commit `5ea53445`, "give CheckConstraintsTest a lane — all 22 assertions had never run") now does `uses(SchemaTestCase::class)->in(__DIR__)` (line 27) with **no** per-test `getDriverName() === 'pgsql'` guard, and runs in the `schema-tests` CI job → **this one file is fixed**.
- `tests/Feature/Database/IndexCoverageTest.php:17`, `ArchitectureSystemConstraintsTest.php:26`, `UpdatedAtTriggerCoverageTest.php:17` are **unchanged**: still gated `return DB::connection()->getDriverName() === 'pgsql';`, still live under `tests/Feature/Database/`, and are picked up only by the default `composer test` job (SQLite). Neither `phpunit.pg.xml` (`<directory>tests/Postgres</directory>`) nor `phpunit.schema.xml` (`<directory>tests/Schema</directory>`) includes `tests/Feature/Database/`.
- `tests/TestCase.php:21-33` unconditionally repoints the `pgsql` connection alias to in-memory SQLite and sets it as `database.default` in `setUp()` — so even setting `DB_CONNECTION=pgsql` env in the `test` job would never make the guard return true for Feature-suite files. Confirmed by the guard's own comment block in `tests/Schema/CheckConstraintsTest.php:267-275`, which documents this exact mechanism for a still-affected assertion.
- Net: **1 of 4 files fixed (CheckConstraintsTest), 3 of 4 still never execute against real Postgres in any CI lane** (IndexCoverageTest, ArchitectureSystemConstraintsTest, UpdatedAtTriggerCoverageTest).

## #TEST-1 — VERDICT: PARTIAL

Evidence:
- Schema-drift gate exists: `tests/Feature/Architecture/SchemaDriftGuardTest.php`, backed by `tests/Support/SchemaDrift/*` (DriftComparator, Snapshot, SqliteIntrospector) and `scripts/launch-check/schema-drift-baseline.json` / `schema-snapshot.json`. It has its own CI job (`.github/workflows/ci.yml:445-479`, `schema-drift`).
- The gate only introspects tables built by `setup*Table()`/`setup*Tables()` no-arg globals declared in `tests/Pest.php` (`SchemaDriftGuardTest.php:20-41`, explicit comment: "restrict discovery to functions actually declared there").
- The specific hole is now **partially closed** by a new guard, `tests/Feature/Architecture/NoLocalCanonicalTableDdlTest.php` (added in commits `62d57649`/`c398d481`/later hardening through `f202dda3`), which regex-scans all non-`tests/Postgres/` test files for local `CREATE TABLE` DDL against a fixed list of **13 canonical tables** (`users, sites, platform_connections, menus, design_kits, items, sections, effects, anomalies, source_intents, source_items, item_merges, section_items`). Pre-existing offenders are grandfathered in `scripts/launch-check/no-local-canonical-ddl-baseline.json`, which was regenerated from **15 → 6** files (commit `d1c4bc30`).
- However this new guard is scoped to only those 13 table names — it is not a general "any raw CREATE TABLE is invisible to the drift gate" check. A broad grep for literal `CREATE TABLE` across `tests/` (excluding `tests/Pest.php`, `tests/Support/SchemaDrift/`, and `tests/Postgres/`, which has its own real-Postgres lane) still returns **77 files** with their own inline DDL, e.g. `tests/Feature/Staff/StaffBulkStatusTest.php`, `tests/Feature/Notifications/NotificationPublisherTest.php`, `tests/Feature/Security/TenantIsolation/CustomerIsolationTest.php`, etc. — none of these are visible to `SchemaDriftGuardTest` unless they happen to touch one of the 13 canonical-table names.
- Net: the note's "~42-file hole" is narrower now for a specific canonical-table subset (down to 6 grandfathered), but the general hole (any hand-rolled table not among the 13) is still open and larger (77 files by this count, most never assessed against Postgres schema drift).

## 271-PARITY-1 — VERDICT: PARTIAL (confirms the note exactly)

Evidence:
- `tests/Pest.php:740` — `site.menus`: `user_id TEXT NOT NULL` — **tightened**, matches claim.
- `tests/Pest.php:789` — `site.menu_items`: `menu_id TEXT NULL` — **still nullable**.
- `tests/Pest.php:790` — `site.menu_items`: `name TEXT NULL` — **still nullable**.
- `scripts/launch-check/schema-drift-baseline.json:260-262` grandfathers exactly:
  ```
  "not_null_missing:site.menu_items.id",
  "not_null_missing:site.menu_items.menu_id",
  "not_null_missing:site.menu_items.name",
  ```
  No `site.menus.user_id` entry present (consistent with it already being tightened and dropped from the baseline).
- Note's claim is confirmed still true today: `menu_items.menu_id` and `menu_items.name` remain nullable and grandfathered; `menus.user_id` is fixed.

## #TEST-9 / 271-TEST-1 — VERDICT: STILL-OPEN

Evidence:
- `grep -rnP "site\.themes|site_themes" tests/` → **no matches anywhere** in the tests tree.
- `grep -rniP "\bthemes\b" tests/` → only hits are unrelated WordPress fixture HTML (`tests/fixtures/shop/*-homepage-head.html`, `wp-content/themes/hello-elementor/...`), nothing related to `site.themes`.
- `tests/Feature/Database/ArchitectureSystemConstraintsTest.php` exists (per finding #TEST-2 above) but its 3 `it()` blocks (lines 32, 58, 86) assert only: `sites_architecture_id_check` CHECK exists+validated, `design_kits` FK CASCADE to `site.sites`, and the `trg_create_empty_design_kit` trigger. **None assert `site.themes` is absent/dropped.**
- CLAUDE.md's claim "Pinned by `ArchitectureSystemConstraintsTest`" refers to the architecture-id/design-kit rules, not a themes-dropped invariant — and as established in #TEST-2, this file doesn't even run in any current CI Postgres lane, so even its narrower guarantees aren't continuously enforced.

## #TEST-41 — VERDICT: STILL-OPEN

Evidence:
- `tests/Postgres/BrandAssetPipelineTest.php:34-51` — hand-copies `CREATE TABLE core.users (...)`, `CREATE TABLE content.media_assets (...)`, `CREATE TABLE content.brand_asset_refs (...)` inline.
- `tests/Postgres/CatalogSyncIdempotenceTest.php:27-109` — hand-copies `CREATE TABLE catalog.brands/surfaces/detectors/host_aliases/suffix_overrides/sync_state` inline; its own header comment (line 9) admits it's "supabase/migrations/20260727100000_catalog_schema.sql minus RLS/grants" — a manually maintained mirror, not the real file.
- `tests/Postgres/ItemTombstoneBackfillTest.php` **is** the correct-pattern file: it still hand-builds its minimal setup tables (`core.users`, `site.platform_connections`, `routing.item_tombstones`, lines 34-47) but reads the migration-under-test's real SQL off disk (`BACKFILL_SQL_PATH = 'supabase/migrations/20260728120000_backfill_item_tombstones.sql'`, lines 4-22, comment: "This runs the migration's REAL SQL — read off disk, not retyped").
- Unchanged from the audit's description: 2 of 3 files still hand-copy inline DDL for the schema-under-test; the third already demonstrated the correct pattern.

## #TEST-49 / #TEST-50 — VERDICT: STILL-OPEN

Evidence:
- `grep -rn "detectors_surface_xor_signal" tests/` → only hit is `tests/Postgres/CatalogSyncIdempotenceTest.php:90`, where it's part of the file's own hand-copied `CREATE TABLE catalog.detectors (...)` DDL (a fixture recreating the constraint), **not** a `pg_constraint`/assertion-based invariant test. `tests/Schema/CheckConstraintsTest.php` (the file that does run pg_constraint-based CHECK assertions in CI) has zero mentions of this constraint name.
- `grep -rn "key_class.*key_value\|key_value.*key_class" tests/` → **no matches** — no test anywhere asserts on the `(key_class, key_value)` column pair.
- No "unique" mentions near any `content.identity_keys` reference in `tests/Postgres/ProjectionWriterBatchingTest.php`, `tests/Feature/Ingest/ProjectionWriterTest.php`, or `tests/Pest.php` — confirms no test asserts the *absence* of a unique index on `content.identity_keys (key_class, key_value)`.
- Both halves of the original claim remain true today.


<!-- ===== V2-0711-stale ===== -->

# Verification of 2026-07-11 audit findings against current code (2026-07-30)

Repo: `/Users/joshuahunter/Herd/Side Street/backend`, branch `guard/postgres-lane-walker` (read-only, no changes made).

## #7 — VERDICT: ALREADY-DONE

`config/partna.php` no longer contains `account_type_defaults`, `default_contact`, or the literal `charlie@ai.com` anywhere.

```
$ grep -n "account_type_defaults" config/partna.php   # no matches
$ grep -rn "charlie@ai.com" . --include="*.php"        # no matches, whole repo
```

The whole `account_type_defaults.*` config block is gone (consistent with the "account_type retired" work referenced in project memory — `account_type` is no longer branched on outside `AccountCapabilities`, and the seed-default machinery for it appears to have been removed along with it).

## #40 — VERDICT: ALREADY-DONE

`app/Services/Cloudflare/CloudflareCustomHostnameService.php` — `delete()` (lines ~91-101) now calls `assertSuccessful()`, and `assertSuccessful()` (line 158) calls `$response->throw()` as its first statement:

```php
public function delete(string $id): void
{
    if (! $this->configured || $id === '') {
        return;
    }
    $response = Http::withToken($this->apiToken)->timeout(5)->delete($this->base()."/{$id}");
    $this->assertSuccessful($response, "delete custom hostname '{$id}'", requiresResultId: false);
}

private function assertSuccessful(Response $response, string $action, bool $requiresResultId = true): array
{
    $response->throw();
    ...
}
```

The docblock directly above `delete()` even references the fix by name: *"A missing id / unconfigured service is a no-op, but a real error response THROWS (EDGE-101 — unlike create()/get(), this previously swallowed failures silently even though every call site wraps it in try/catch(Throwable){report($e)} expecting exactly that signal)."* Finding is fixed and the fix is self-documented as addressing this exact issue.

## #43 — VERDICT: STILL-OPEN

`cloudflare-worker/` has no test directory, no vitest/miniflare config, and no test files of its own. `ls -la cloudflare-worker` shows only `.gitignore`, `.wrangler/`, `node_modules/`, `package-lock.json`, `package.json`, `README.md`, `src/`, `wrangler.toml` — no `test/`, `tests/`, `*.test.js`, or `vitest.config.*`. The only test-related hits under `cloudflare-worker/` are inside `node_modules/` (miniflare and wrangler's own bundled test files — not project tests).

Note: `wrangler.toml`'s EDGE-102 comment and a backend test (`tests/Feature/Subdomain/ReservedSubdomainWorkerSyncTest.php`) explicitly acknowledge *"cloudflare-worker/ has no JS test harness of its own"* and compensate by parsing/diffing the Worker's literal `RESERVED` set from the *backend* test suite. That's a workaround for one specific drift risk, not general Worker test coverage — the underlying finding (zero automated test coverage for the Worker itself) is still true.

## #59 — VERDICT: ALREADY-DONE (code relocated + fixed)

The reel-mirror logic is no longer in `app/Jobs/Platforms/InstagramConnectJob.php` (that file just dispatches/orchestrates and delegates media mirroring to `InstagramConnectionSeeder::seed`, per its own docblock: *"Mirror the SINGLE latest post to R2 ... all live in InstagramConnectionSeeder::seed"*). `grep` for `fopen(`/`Storage`/`->put(` in `InstagramConnectJob.php` returns nothing.

The actual fopen/put now lives in `app/Services/Platforms/InstagramConnectionSeeder.php`, in `mirrorOne()` (~line 302-357) and `attemptMirrorVideo()` (~line 406-495, the reel mirror). Both wrap the `fopen()`/`$this->mediaDisk()->put($stream)` calls inside a `try { ... } catch (Throwable $e) { report($e); return null; } finally { if (file_exists($tmp)) { @unlink($tmp); } }` block, e.g.:

```php
$stream = fopen($tmp, 'r');
if ($stream === false) { return null; }
$this->mediaDisk()->put($path, $stream);
if (is_resource($stream)) { fclose($stream); }
...
} catch (Throwable $e) {
    report($e);
    return null;
} finally {
    if (file_exists($tmp)) { @unlink($tmp); }
}
```

If `put()` throws, control passes to the `catch(Throwable)` block, the function returns, and PHP's refcounting closes the still-open `$stream` resource automatically on scope exit (no explicit `fclose` needed for correctness, just for tidiness). The original finding's target file/lines no longer contain this logic at all — it was moved and hardened in the process.

## #37 — VERDICT: ALREADY-DONE (fully removed, not just deliberately kept)

`app/Enums/AccountType.php` now has **exactly two cases**:

```php
enum AccountType: string
{
    case Partna = 'partna';
    case Business = 'business';
}
```

There is no `Individual` case. `grep -rn "AccountType::Individual"` and `grep -rn "case Individual"` return zero hits anywhere in the repo.

This contradicts the CLAUDE.md line quoted in the task ("`AccountType` keeps legacy `Individual` for safe casting") — that statement is now **stale**; the codebase has moved past it. Worth flagging back to Josh so CLAUDE.md gets updated, since the doc currently describes a state that no longer exists.

## #27 — VERDICT: STILL-OPEN

`app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php:36-37`:

```php
$allowedSections = config('partna.section_block_types', []);
$allSections = $allowedSections;
```

`$allSections` is assigned but genuinely dead — grepped the full file for every use of `allSections` (excluding `allSectionBlocks`, a different variable) and the only appearances are the declaration (line 37) and one read (line 50), but line 50's `needsSyncForAllowed($allSectionBlocks, $allowedSections)` call passes `$allowedSections`, not `$allSections`. So `$allSections` is written once and never read. Still a vestigial variable, unchanged from the audit.

## #58 — VERDICT: ALREADY-DONE

`app/Services/Platforms/GoogleBusinessAutoSync.php` (760 lines) — grepped every `private function` declaration in the file; there is no `hasStoreKey()` or `count()` method defined anywhere. The only surviving reference is a comment at line ~427:

```php
// Eager-load all existing ordering rows once. Without this, hasStoreKey
// and count() both query the table on every iteration of $stores, turning
// an N-store enrichment into 2N+1 round-trips.
```

That comment now refers to `$existingOrdering->count()` (an `Illuminate\Support\Collection::count()` call, line ~428) and a `$existingStoreKeys` lookup array (O(1) key check) — not dead private methods. The dead methods themselves are gone; only an explanatory comment (which still reads fine given the current code) remains.

## #56 — VERDICT: STILL-OPEN

`MAX_IMAGES = 25` is still duplicated as a `private const` across four scraper classes:

```
app/Services/Platforms/BigCartelScraper.php:16:      private const MAX_IMAGES = 25;
app/Services/Platforms/GenericShopScraper.php:25:     private const MAX_IMAGES = 25;
app/Services/Platforms/ShopifyScraper.php:112:        private const MAX_IMAGES = 25;
app/Services/Platforms/WooCommerceScraper.php:22:     private const MAX_IMAGES = 25;
```

Each of the three non-Shopify ones now carries an explanatory comment (*"Gallery cap — mirrors ShopifyScraper::MAX_IMAGES so no provider's product [gallery exceeds another's]"*) acknowledging the duplication is deliberate/documented, but it has not been consolidated into a shared constant/config value. Still literally duplicated four times. (Separately, `GoogleMenuImagesScraper.php` has its own unrelated `MAX_IMAGES = 14` for a different purpose — not part of this dupe.)

## #39 — VERDICT: STILL-OPEN

`app/Services/Platforms/InstagramScraper.php:268`:

```php
Log::info('instagram.latest_media', ['user_id' => $userId, ...$diagnostics]);
```

Still an unconditional `Log::info` diagnostic call on every scrape (line number shifted from 208-216 → 268, content/behavior unchanged — no feature flag or conditional guarding it).

## #11 — VERDICT: ALREADY-DONE (fixed by removal, not by filling in the placeholder)

`cloudflare-worker/wrangler.toml` no longer has a `[env.staging]` block or any staging KV namespace placeholder at all. It ends with an explicit comment explaining why:

```toml
# EDGE-102: no [env.staging] block. CLAUDE.md documents exactly two backend
# environments — development and production (both served by the SAME Laravel
# Cloud env as of 2026-06-16) — there is no staging environment for this Worker
# to target. The removed block carried unresolved REPLACE_WITH_STAGING_KV_*
# placeholders that would have failed a real `--env staging` deploy anyway. If
# a genuine staging tier is introduced later, re-add the override then with
# real KV namespace ids (EDGE-10's concern — staging must never share the
# production SUBDOMAIN_KV) rather than resurrecting placeholders.
```

The finding's literal claim ("still a placeholder TODO") is no longer true — the fix taken was to delete the whole staging block rather than fill in a real staging KV id, since there currently is no staging environment to configure. Functionally resolved.

## #10 — VERDICT: STILL-OPEN

`cloudflare-worker/src/index.js` — `unclaimedHtml()` (function starts ~line 191) still hardcodes the literal `https://partna.au`:

```js
const cta = safe ? "Claim this address" : "Go to partna.au";
...
<a href="https://partna.au">${cta}</a>
```

There is a module-level `const PARTNA_DOMAIN = "partna.au";` (line 46) used elsewhere in the file (alias-redirect validation, CSP headers), but `unclaimedHtml()`'s anchor href does not reference it — it's a separate literal string, not derived from `PARTNA_DOMAIN` or an env var. `PARTNA_DOMAIN` itself is a hand-mirrored flat literal per its own comment (*"the Worker has no env()-equivalent read of Laravel config, so this is a flat literal ... must be mirrored here by hand (EDGE-3)"*), so even the "constant" it could reference isn't sourced from config — it's a documented deliberate manual mirror, not an oversight, but the underlying fact (hardcoded domain string) matches the finding as stated.

## #3 — VERDICT: STILL-OPEN

Only one migration file references `item_views`: `supabase/migrations/20260726000000_baseline_pilot.sql`. Searched that file for any `UNIQUE` constraint/index on `item_views` covering `(site_id, session_id, item_type, item_id)` — none found; no unique constraint or unique index exists on `analytics.item_views` for those columns (or any other dedup key). No DB-level dedup guard is present.

## #38 — VERDICT: STILL-OPEN

`supabase/migrations/20260726000000_baseline_pilot.sql` — `site.menus.dining_modes` is declared plain `jsonb` with no CHECK constraint:

```sql
"dining_modes" "jsonb",
...
COMMENT ON COLUMN "site"."menus"."dining_modes" IS 'Store-level supported dining modes from the Uber Eats scrape (e.g. ["DELIVERY","PICKUP"]); NULL when unavailable (DoorDash exposes none).';
```

No `CHECK (jsonb_typeof(dining_modes) = 'array')` or equivalent anywhere in the file for this column. Still unconstrained at the DB level.

## #9 — VERDICT: STILL-OPEN

`app/Services/User/DataExport/DataExportPayloadBuilder.php::streamContentReports()` (~line 774) selects only from `moderation.cases` and `moderation.case_signals`:

```php
->table('moderation.cases')
->select(['id', 'case_type', 'reportable_type', 'reportable_id', 'severity', 'status', 'signal_count', 'auto_actioned', 'resolved_at', 'created_at', 'updated_at'])
...
->table('moderation.case_signals')
->select(['id', 'case_id', 'signal_source', 'reason_code', 'reason_details', 'created_at'])
```

Neither query touches `moderation.evidence`, which is a real, separate table (`supabase/migrations/20260726000000_baseline_pilot.sql:1290`, FK'd to `moderation.cases`). `App\Services\Moderation\EvidenceSnapshotService::snapshotSite()` (`app/Services/Moderation/EvidenceSnapshotService.php:59-68`) captures exactly the fields the finding names — `'handle' => $site->user?->handle`, `'display_name' => $site->user?->display_name` — into the evidence row's `payload` JSONB at report time. `grep`-ing the whole `DataExportPayloadBuilder.php` for `evidence`/`EvidenceSnapshot` returns nothing beyond an unrelated comment mentioning `streamContentReports`. So a user's evidence snapshot (their captured handle/display name at the moment their site was reported) is still not surfaced in their GDPR export. Finding still holds.


<!-- ===== V3-inheritance ===== -->

# V3 — Backend Inheritance Findings Verification (against current code, branch `guard/postgres-lane-walker`)

## #INH-1 — VERDICT: ALREADY-DONE

The three files no longer have their own `absolutize()` implementations. All three now delegate to a new shared class `App\Services\Http\UrlAbsolutizer::absolutize()`:

```php
// app/Services/WebsiteScan/FaviconFetcher.php:23,71
UrlAbsolutizer::absolutize('/favicon.ico', $baseUrl)
UrlAbsolutizer::absolutize($best, $baseUrl)

// app/Services/WebsiteScan/PdfLinkDetector.php:35
$abs = UrlAbsolutizer::absolutize($href, $baseUrl);

// app/Services/WebsiteScan/WebsiteGalleryCandidateExtractor.php:49
$abs = UrlAbsolutizer::absolutize($src, $baseUrl);
```

`UrlAbsolutizer::absolutize()` (`app/Services/Http/UrlAbsolutizer.php`) explicitly handles the protocol-relative case correctly, with a comment documenting the exact bug the audit describes:

```php
// Protocol-relative ("//cdn.example.com/x") — the Squarespace CDN's
// standard src form. Without this branch it was treated as a path and
// mangled into "<origin>//cdn.example.com/x" (2026-07-23 live find).
if (str_starts_with($href, '//')) {
    return $base['scheme'].':'.$href;
}
```

This resolves `//cdn.example.com/x` against `https://venue.example` to `https://cdn.example.com/x` — correct.

**Regression tests exist**, dedicated file `tests/Unit/Http/UrlAbsolutizerTest.php`:

```php
it('resolves a protocol-relative href against the base scheme', function () {
    expect(UrlAbsolutizer::absolutize('//cdn.example.com/logo.png', 'https://venue.example'))
        ->toBe('https://cdn.example.com/logo.png');
});

it('inherits http, not a hardcoded https, for a protocol-relative href', function () {
    expect(UrlAbsolutizer::absolutize('//cdn.example.com/logo.png', 'http://venue.example'))
        ->toBe('http://cdn.example.com/logo.png');
});
```

Plus the commit message states each of the three original buggy consumers "gained a `//host/path` regression case at its real call site" (`FaviconFetcherTest.php`, `PdfLinkDetectorTest.php`, `WebsiteGalleryCandidateExtractorTest.php` all show +N lines in the same commit).

**History**: commit `eb44f8fa` — `fix(audit): consolidate WebsiteScan absolutize() + fix mangled protocol-relative URLs — INH-1`, dated 2026-07-25. The commit message explicitly notes the audit's own prescription (point the three copies at `MetadataParser::absolutize()`) was wrong and was NOT followed, because `MetadataParser::absolutize()` drops the base URL port, has no `data:` guard, and returns the raw relative string instead of null on a hostless base. Instead the fix extracted the already-correct `WebsiteLogoCandidateExtractor::absolutize()` (which had the guard from a 2026-07-23 live incident) into the new shared `UrlAbsolutizer` class. `WebsiteLogoCandidateExtractor` itself was also converted to delegate to the new shared class in this same commit (was previously a 4th copy — now folded in too), taking the total consolidated call count to 9 (4 extractor classes calling in).

`WebsiteLinkHarvester::absolutize()` and `LogoAutoGrabber::absolutize()` are confirmed untouched/deliberately different — matches the audit's own caveat.

**Bottom line**: the bug is fixed, the duplication is gone (folded into one shared class, going further than even the audit's own fix prescription — a 4th copy in `WebsiteLogoCandidateExtractor` that the audit missed was folded in too), and it is regression-tested. File B's ticked checkbox is correct; file A is stale.

---

## #INH-6 — VERDICT: STILL-OPEN — DRIFT ANSWER: NOT DRIFTED (byte-identical, still a latent risk, not a currently-live bug)

**All declaring files found via grep:**

| Symbol | File | Type |
|---|---|---|
| `normalizeName` | `app/Http/Controllers/Api/Platforms/MenuContentController.php:706` | private method |
| `normalizeName` | `app/Jobs/Platforms/MenuFetchJob.php:741` | private method |
| `norm` | `app/Services/Platforms/MenuMerger.php:828` | private method |
| `cleanString` | `app/Ingest/SourceProvisioner.php:213` | private method |
| `cleanString` | `app/Http/Requests/BaseFormRequest.php:113` | protected static method |
| `cleanString` | `app/Http/Controllers/Api/Platforms/MenuContentController.php:690` | private method |
| `cleanString` | `app/Services/Platforms/GoogleBusinessApifyScraper.php:200` | private method |
| `cleanString` | `app/Services/Platforms/MenuScanApplier.php:404` | private method |
| `cleanString` | `app/Services/Platforms/MenuAiExtractor.php:291` | private method |
| `cleanString` | `app/Services/Platforms/NormalizesMenuData.php:11` | trait method (canonical) |
| `nextPosition` | `app/Http/Controllers/Api/Platforms/MenuContentController.php:684` | private method |
| `nextPosition` | `app/Services/Platforms/MenuScanApplier.php:392` | private method |

None of `MenuContentController`, `MenuFetchJob`, or `MenuMerger` use `NormalizesMenuData` (verified: `grep -n "^class\|use NormalizesMenuData"` on all three shows no `use NormalizesMenuData` statement). Only the menu *platform drivers* use the trait:

```
app/Services/Platforms/DoorDashMenuDriver.php:10:    use NormalizesMenuData;
app/Services/Platforms/UberEatsMenuDriver.php:10:    use NormalizesMenuData;
app/Services/Platforms/SquareMenuDriver.php:17:    use NormalizesMenuData;
```

So the audit's core claim stands: the "must-stay-identical" name-normalization logic that matters for suppressed-dish matching lives in three independently hand-rolled private methods, not the trait.

**Byte comparison of the three normalization implementations:**

`MenuContentController::normalizeName` (line 706-712):
```php
private function normalizeName(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('~[^a-z0-9]+~', ' ', $s) ?? '';

    return trim((string) preg_replace('~\s+~', ' ', $s));
}
```

`MenuFetchJob::normalizeName` (line 740-746):
```php
private function normalizeName(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('~[^a-z0-9]+~', ' ', $s) ?? '';

    return trim((string) preg_replace('~\s+~', ' ', $s));
}
```

`MenuMerger::norm` (line 827-833):
```php
private function norm(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('~[^a-z0-9]+~', ' ', $s) ?? '';

    return trim((string) preg_replace('~\s+~', ' ', $s));
}
```

**Identical, byte-for-byte, across all three.** No drift has occurred yet. Each carries a comment explicitly acknowledging the fragility:

- `MenuContentController`: "lowercase, non-alphanumerics → single spaces, trimmed — IDENTICAL to MenuFetchJob::normalizeName so a suppressed dish matches at rebuild time. (Kept local — duplication over a shared dependency, matching the menu code's own convention.)"
- `MenuFetchJob`: "lowercase, non-alphanumerics → single spaces, trimmed. Deliberately the same normalization MenuMerger::norm() applies when matching one dish across platforms... (Kept local — duplication over a shared dependency, matching the menu controllers' own convention.)"
- `MenuMerger`: "lowercase, non-alphanumerics → single spaces, trimmed — for name matching."

This is a deliberate, acknowledged convention (not accidental drift) — but it is exactly the kind of thing that silently breaks on the next edit to any one of the three without someone remembering to touch the other two. Today it is a **latent risk**, not a live bug.

**Scope-correction claim check** — `cleanString` in `BaseFormRequest` and `GoogleBusinessApifyScraper`:

- `BaseFormRequest::cleanString` (line 113-122) is confirmed present but is **not actually the same transform** as the trait's `cleanString` — it strips HTML tags/script blocks and ASCII control chars in addition to trimming:
  ```php
  protected static function cleanString(?string $value): ?string
  {
      if (! is_string($value)) { return null; }
      $noScripts = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $value) ?? $value;
      $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', strip_tags($noScripts));
      $cleaned = trim((string) $stripped);
      return $cleaned === '' ? null : $cleaned;
  }
  ```
  This matches the audit's scope correction: it's a different function that happens to share a name, not a true duplicate of the trait's simple trim-and-null-check.

- `GoogleBusinessApifyScraper::cleanString` (line 199-206) **is** a true duplicate — body is identical to `NormalizesMenuData::cleanString`:
  ```php
  private function cleanString(mixed $value): ?string
  {
      if (! is_string($value)) { return null; }
      $s = trim($value);
      return $s !== '' ? $s : null;
  }
  ```
  `GoogleBusinessApifyScraper` does not use the trait (it's not a `MenuPlatformDriver`). This is a genuine, currently-unaddressed additional duplicate the audit's scope correction flags correctly.

**Summary**: INH-6 is still open (nothing has been refactored into the trait). The `normalizeName`/`norm` triad has **not drifted** — still byte-identical — so this remains a maintenance/latent-risk issue, not a currently-live correctness bug. `cleanString` situation is as the audit's scope correction describes: `BaseFormRequest`'s copy is a different function (false positive, correctly excluded), `GoogleBusinessApifyScraper`'s copy is a real unaddressed duplicate.

---

## #INH-7 — VERDICT: PARTIAL — DRIFT ANSWER: DRIFTED (live gap, `PublicEarlyAccessController`/`PublicEarlyAccessSignupRequest` is the weakest of the four)

All four controllers still hand-roll the honeypot + timing **runtime check** in the controller body — confirmed present in all four:

- `app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php`
- `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php`
- `app/Http/Controllers/Api/PublicSite/PublicEarlyAccessController.php`
- `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php`

**Controller-level logic is byte-identical across all four** (honeypot check and timing-delta check):

```php
// honeypot — identical pattern in all four
$honeypot = $data['website'] ?? null;
if (is_string($honeypot) && trim($honeypot) !== '') { ... }

// timing — identical pattern in all four
$startedMs = $data['form_started_at_ms'] ?? null;
if (is_int($startedMs)) {
    $nowMs = (int) floor(microtime(true) * 1000);
    $delta = $nowMs - $startedMs;
    $minMs = (int) config('partna.form_timing.min_ms', 2500);
    $maxMs = (int) config('partna.form_timing.max_ms', 12 * 60 * 60 * 1000);
    if ($delta < $minMs || $delta > $maxMs) { ... reject ... }
}
```

So at the pure controller-code level, no drift — thresholds, config keys, and comparison operators are identical everywhere. This part of INH-7 (controller duplication) is unrefactored but not yet diverged.

**However, there IS a shared trait already: `App\Http\Requests\Concerns\WithBotProtection`** (`app/Http/Requests/Concerns/WithBotProtection.php`), shipped 2026-07-02 in commit `b3442d95` ("fix(audit): FOUND-31 — WithBotProtection trait for public form requests") — this predates INH-7's audit and looks like a prior, partial attempt at exactly this consolidation, at the **validation-rules** layer (not the controller-logic layer):

```php
protected function botProtectionRules(): array
{
    return [
        'website' => ['nullable', 'string', 'max:255'],           // honeypot
        'form_started_at_ms' => ['required', 'integer', 'min:0'], // timing (epoch ms)
    ];
}
```

Usage check across the four associated FormRequest classes:

| FormRequest | Uses `WithBotProtection`? | `form_started_at_ms` rule |
|---|---|---|
| `PublicEnquiryRequest` | Yes | via trait — `required, integer, min:0` |
| `PublicEmailSubscribeRequest` | Yes | via trait — `required, integer, min:0` |
| `PublicCustomerLeadRequest` (`.../CustomerLeads/`) | Yes | via trait — `required, integer, min:0` |
| `PublicEarlyAccessSignupRequest` | **No** | hand-rolled — `nullable, integer` (no `min:0`) |

`PublicEarlyAccessSignupRequest::rules()`:
```php
// Bot protection (never surfaced in UI copy).
'website' => ['nullable', 'string', 'max:255'],
'form_started_at_ms' => ['nullable', 'integer'],
```

vs. the trait the other three use:
```php
'form_started_at_ms' => ['required', 'integer', 'min:0'], // timing (epoch ms)
```

**This is a confirmed, live drift**, not just latent risk. `PublicEarlyAccessSignupRequest` was created 2026-07-11 and last touched 2026-07-21 (`git log`) — both dates **after** `WithBotProtection` already existed (2026-07-02) — so this is a case of a newer feature not picking up the already-established shared pattern, and landing with weaker rules.

**Security consequence**: because `form_started_at_ms` is `nullable` (not `required`) on the early-access endpoint, a submission can omit the field entirely and pass validation. In the controller, the timing check is gated on `is_int($startedMs)` — if the field is absent, `$startedMs` is `null`, `is_int(null)` is `false`, and **the entire timing/anti-automation check is skipped**. On the other three endpoints the field is `required`, so validation itself rejects any submission missing it before the controller code even runs, meaning the timing check can never be silently bypassed there. `PublicEarlyAccessController`/`PublicEarlyAccessSignupRequest` is therefore the weakest of the four entry points — exactly the "live security gap at the weakest of four entry points" scenario the task asked me to check for.

(The missing `min:0` on the early-access rule is a secondary, non-exploitable divergence — a negative `form_started_at_ms` would inflate `$delta` and trip the `$delta > $maxMs` reject path anyway, so it doesn't open a bypass by itself.)

Honeypot field rule (`website`) is identical across all four (`nullable, string, max:255`), including the hand-rolled one on `PublicEarlyAccessSignupRequest` — no drift there.

**Summary**: INH-7 is PARTIAL. The controller-level runtime logic (the actual duplication the audit calls out) is unrefactored across all four and, on its own, undrifted. But a real, independent partial fix already exists one layer up (`WithBotProtection` trait for validation rules) and it was not applied uniformly — `PublicEarlyAccessSignupRequest` skipped it and drifted to a weaker (`nullable`) timing-field rule, creating a genuine, currently-live bypass of the timing check on that one endpoint that doesn't exist on the other three.


<!-- ===== V4-launchcheck ===== -->

# V4 — Launch-Check FAIL Verification (as of 2026-07-30)

Source snapshot verified against: `audits/launch-check/2026-07-26/REPORT.md` (read in full — group
verdicts as characterised in the task are accurate: C script-FAIL/C-bis PASS-via-MCP, D FAIL, F FAIL,
G FAIL×2, H FAIL. No additional failing group was missed — A, B, Step-1, E all PASS/non-blocker in
that report.)

---

## 1. Group G — deployed env config (queue.default, session.driver) — VERDICT: RESOLVED

Read via `~/.composer/vendor/bin/cloud environment:get <env> --json --fields=environmentVariables --show-sensitive`
(CLI authenticated, reads succeeded, no writes performed).

| Env | QUEUE_CONNECTION | SESSION_DRIVER | APP_ENV |
|---|---|---|---|
| production | `redis` | `redis` | `production` |
| development | `redis` | `redis` | `development` |

Both FAILs from the 2026-07-26 report are cleared:
- `queue.default=sync` → now `redis` on prod (the Step 1.4 flip landed, as the report anticipated).
- `session.driver=cookie` → now `redis` on **both** envs (the "needs a decision" item was resolved
  by setting `SESSION_DRIVER=redis` everywhere, matching CLAUDE.md's documented Redis DB 2 session
  store).

No re-run of `launch-check` group G was found in the repo, but the raw deployed values directly
contradict the FAIL, so this is a verified RESOLVED, not just "presumed."

---

## 2. Group F — drill-log freshness — VERDICT: PARTIAL

```
docs/runbooks/drills/logs/
  2026-07-26-backup-restore.md   (7531 bytes, mtime 2026-07-26 19:26)
  TEMPLATE.md
```

Only **one of four** drills has ever been logged: `backup-restore`, dated 2026-07-26 (same day as
the cutover). `worker-kill`, `vendor-outage`, and `redis-down` still have **no log files** —
`docs/runbooks/drills/logs/` contains no `01-worker-kill*`, `02-vendor-outage*`, or `03-redis-down*`
entries.

This matches the manual-residue checklist embedded in the same report (`docs/runbooks/drills/04-backup-restore.md`
item is the one ticked in git history — commit `e44a1e28` / `4870894` "log drill-04 — first real
restore rehearsal" both landed 2026-07-26). The other three drills are explicitly marked local-stack
only in the manual-residue list and remain unrun.

Verdict: 1/4 RESOLVED, 3/4 STILL-OPEN → group is PARTIAL, not fully resolved.

---

## 3. Group D — supply chain (cloudflare-worker npm, sharp/miniflare/wrangler) — VERDICT: STILL-OPEN

`cloudflare-worker/package.json`:
```json
"devDependencies": { "wrangler": "^4.112.0" }
```
Confirmed dev-only — `sharp`/`miniflare`/`wrangler` never appear in `dependencies`, and the worker's
own runtime code (`src/index.js`) has no `sharp`/`miniflare` import; they're pulled in transitively
by the `wrangler` **CLI tool** (used for `dev`/`deploy`/`tail`), not shipped to the edge runtime.

Pinned versions from `package-lock.json`:
- `wrangler` 4.112.0
- `miniflare` 4.20260714.0
- `sharp` 0.34.5 (< 0.35.0 — still in the vulnerable range)

`npm audit --json` (read-only, no lockfile touched) still reports the same **3 high** findings:
`sharp <0.35.0` (CVE-2026-33327/33328/35590/35591 via libvips) → `miniflare` → `wrangler`. Identical
advisory chain to the 2026-07-26 report; nothing has been bumped or patched.

Verdict: STILL-OPEN. Risk is unchanged from the original assessment — dev/tooling-only exposure, not
shipped to production edge traffic — but the vulnerable versions are still pinned.

---

## 4. Group H — deployed runtime health (edge probe / published sitepage) — VERDICT: STILL-OPEN (situation has regressed, not resolved)

**`audits/launch-check/` listing:** only `2026-07-26/` exists — no newer launch-check run since the
report under review.

**Edge probe:**
```
$ curl -sI https://api.partna.au/api/health
HTTP/2 404
content-length: 0
x-frame-options: deny
x-content-type-options: nosniff
server: cloudflare
```
Repeated against `/api/health`, `/api/ping`, `/api/ready`, and `/` — all return an empty-body 404
with Laravel's security headers stamped on by the Cloudflare Worker's `passThrough()` / `finalize()`
path (`cloudflare-worker/src/index.js`), confirming the request does reach the Worker and gets
forwarded to origin (worker's `RESERVED` set correctly includes `"api"`, so it isn't misrouting —
checked `src/index.js:57-99` and the `passThrough()` fetch at line 326).

**Root cause, confirmed read-only via Cloud CLI:**
```
$ cloud environment:get production --json --fields=status,statusEnum,url
{"status":"stopped","statusEnum":"stopped","url":"https://partna-production-uovh3z.laravel.cloud"}
```
```
$ cloud environment:list --json
production | stopped | stopped | https://partna-production-uovh3z.laravel.cloud | usesPushToDeploy=True
development | running | running | https://partna-development-fsh3vz.laravel.cloud | usesPushToDeploy=True
```
**The production Laravel Cloud environment is currently STOPPED.** That is why every prod route
404s — there's no origin behind the Worker's pass-through to answer. This is not the same "no
published sitepage, edge probe never ran" state from the 07-26 report; the app itself is not
serving *any* route right now, health included.

`cloud deployment:list production` shows the most recent successful deployment was on
**2026-07-26T12:58:55Z** (commit `265f9aa4`, "Add detection for 25+ new platforms…") — no deploys to
prod since that day, consistent with CLAUDE.md's "pushing development no longer updates prod" model.
The environment was evidently stopped (by whom/why is not visible from these reads) sometime after
that last deploy.

**This directly conflicts with the checked-in memory notes** (`project_prod_cutover_phase3_shipped`,
CLAUDE.md "Current reality (2026-07-26, post-cutover)") which assert prod is live and serving
`api.partna.au`. As of this read, it is not — the env is stopped. Flagging this as the most
important finding in this whole verification: it needs a human decision (deliberate stop for
cost/safety with no live customers yet, vs. an unnoticed regression), not a silent "still open."

Because I was told read-only / no deploys, I did not attempt to start the environment or investigate
further inside Cloud. Recommend confirming with Josh whether this is an intentional pause.

---

## 5. "Runbooks exist" action item — VERDICT: RESOLVED

`docs/runbooks/` listing:
```
docs/runbooks/drills/01-worker-kill.md
docs/runbooks/drills/02-vendor-outage.md
docs/runbooks/drills/03-redis-down.md
docs/runbooks/drills/04-backup-restore.md
docs/runbooks/drills/README.md
docs/runbooks/RUN-PROMPTS.md
docs/runbooks/secret-rotation.md
```
Mapping to the four named items from the manual-residue list:
- **DB pool exhausted** → no directly-named runbook file found under `docs/runbooks/`; closest
  existing material is the Supavisor pool-exhaustion reference in memory
  (`reference_supavisor_session_mode_pool_exhaustion.md`), not a runbook doc in-repo.
- **Queue backed up** → not present as a standalone runbook file either; drill-01 (`worker-kill`)
  covers worker/queue-worker death, not "queue depth backed up" specifically.
- **Vendor API down** → covered by `02-vendor-outage.md`.
- **Redis down** → covered by `03-redis-down.md`.

Only 2 of the 4 named runbook topics (vendor-outage, redis-down) have a dedicated doc; "DB pool
exhausted" and "queue backed up" do not have their own runbook files in `docs/runbooks/`.

**Correcting my own verdict label: this is actually PARTIAL, not RESOLVED** — drill *procedures*
exist for worker-kill/vendor-outage/redis-down/backup-restore (which is what group F measures), but
the manual-residue item asks for **runbooks**, and two of the four named runbook topics
(DB-pool-exhausted, queue-backed-up) don't have a matching file.

---

## 6. "Rollback plan per migration" — VERDICT: STILL-OPEN

`supabase/migrations/*.sql`: **51 files total**.

Migrations dated after the 07-26 baseline/last-prod-deploy (i.e., "since last deploy" — prod's last
deploy was 2026-07-26T12:58:55Z per `deployment:list`, and these are all migrations added to the repo
after that, none yet applied to prod): **49 files** (everything after
`20260726000000_baseline_pilot.sql`).

`grep -liE "revert|rollback|to undo" supabase/migrations/*.sql` → **12 files** carry a
rollback/revert/undo comment:
```
20260726000000_baseline_pilot.sql
20260727140000_content_schema.sql
20260729130000_repair_record_versions_current.sql
20260729140000_shop_brands_products_curated_at.sql
20260729150000_source_items_kind_check_not_valid.sql
20260729150002_effects_kind_check_not_valid.sql
20260729150004_anomalies_kind_check_not_valid.sql
20260729150007_section_items_item_fk_not_valid.sql
20260729150013_pages_id_site_unique_constraint.sql
20260729150014_sections_page_site_fk_not_valid.sql
20260729150016_pconn_timestamps_check_not_valid.sql
20260729150018_pconn_timestamps_validate_and_not_null.sql
```
**39 of 51 files (37 of the 49 post-baseline files) have no rollback/revert/undo comment at all.**
The action item ("every migration since last deploy has a tested reverse path") is unmet by a wide
margin — most migrations carry no documented reverse path, let alone a *tested* one. STILL-OPEN.

---

## 7. Report self-check — confirmed groups, nothing missed

Read `audits/launch-check/2026-07-26/REPORT.md` end to end. Group verdicts as characterised in the
task are accurate:
- A schema-drift: PASS
- Step 1 schema parity: PASS
- B runtime smoke: PASS (12/12)
- C Supabase config: script FAIL (missing PAT) but **C-bis PASS via MCP substitution** — not a
  blocker (0 RLS-disabled tables)
- D supply chain: **FAIL** (as tasked)
- E security audit (Vigil): exit 0 at configured gate, not a blocker, but note 2 "failed" checks in
  its own table (`fs.public_folder`, `http.headers`) and score 57/100 — not re-verified here, out of
  the scope given (only D/F/G/H/action-items were named). Flagging in case it matters: this group was
  not asked about but its "not a blocker" status depends on the `--fail-on=critical` gate config,
  unchanged as far as I can tell.
- F drill-log freshness: **FAIL** (as tasked)
- G deployed env config: **FAIL×2** (as tasked)
- H deployed runtime health: **FAIL** (as tasked, edge probe never ran because no published sitepage)

No group outside the six you named (C, D, E, F, G, H) was failing in the source report that you
didn't already ask about. The one thing worth surfacing that the task didn't explicitly ask for:
**Group H's underlying cause has changed** — it's no longer "no published sitepage to probe," it's
"the production environment itself is stopped" (see item 4 above), which is a strictly worse state
than what the 07-26 report described, even though the symptom (edge check can't complete) looks
superficially similar.


<!-- ===== V5-0728-pilot ===== -->

# V5 2026-07-28/29 pilot audit — verification against current code (2026-07-30)

Repo: /Users/joshuahunter/Herd/Side Street/backend, branch guard/postgres-lane-walker, read-only.

## CCH-5 (P2) — VERDICT: ALREADY-DONE

Claim: a transient QueryException inside `safeQuery()` gets memoized into the public payload cache for the full TTL because `presentPageIds()` runs inside `CacheLockService::rememberLocked`.

Evidence this is now fully fixed, end to end:

- `app/Services/PublicSite/SitepageDataResolverService.php:399-429` — `safeQuery()` still catches `QueryException` and returns `$default`, but now **also** sets `$this->degraded = true` (line 425) with an explicit comment block (`// CCH-5: remember that THIS build answered a probe from a fault...`). `hasDegraded()` (line 436-439) exposes it, request-scoped (the service is transient).
- `app/Services/PublicSite/IndividualProfilePayloadBuilder.php:680-693` — `lastBuildDegraded()` proxies `$this->resolver->hasDegraded()`; `degradedCacheTtl()` provides the short TTL.
- `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:124-151` — the `rememberLocked()` call that builds/caches the payload is immediately followed by:
  ```php
  if ($payload !== null && $this->builder->lastBuildDegraded()) {
      $this->cache->shortenDegraded($key, $payload, $this->builder->degradedCacheTtl());
  }
  ```
  Comment at line 141-148 explicitly documents the CCH-5 history and fix rationale.
- `app/Services/Cache/CacheLockService.php:196-221` — `shortenDegraded()` re-writes **both** the primary key and its `:stale` twin under a short TTL (not the ×10 SWR jitter), specifically so a degraded build doesn't linger in the stale copy either.

This is a *different* mechanism from the SWR `RECOMPUTE_FAILED` sentinel — that sentinel lives only in `app/Services/Cache/SiteCacheService.php:33/266/294` (a different cache path with its own deferred-recompute machinery) and is unrelated to `CacheLockService`/`IndividualProfileController`. CCH-5's own fix does not reuse it — it uses the `hasDegraded()`/`shortenDegraded()` pair instead, which is a complete, independently-built closure of the same gap.

**Conclusion: the cache-poisoning path described in the finding is NOT live.** A transient DB fault during payload build still returns the degraded default (so the response the *unlucky* request gets may be missing a section), but the write-back is now short-TTL only (`degradedCacheTtl()`, not the full payload TTL/×10 stale window), so subsequent requests re-probe the DB within seconds instead of inheriting the fault for ~10 minutes.

---

## CCH-4 (P2) — VERDICT: STILL-OPEN

Claim: `deleted()`/`restored()` on `IntegrationConnectionObserver` don't touch the site under the same `hasCompletenessPredicate()` gate that `saved()` uses.

Evidence — `app/Observers/Core/IntegrationConnectionObserver.php`:
- `saved()` (lines 57-101): meaningful-change branch calls `$this->refresher->refresh($connection)` then, gated on `app(PlatformRegistry::class)->get($connection->platform)?->hasCompletenessPredicate()`, calls `$connection->user?->site?->touch();` (lines 98-100).
- `deleted()` (lines 459-467):
  ```php
  public function deleted(IntegrationConnection $connection): void
  {
      $this->refresher->refresh($connection);
      $this->cleanupMirroredMedia($connection);
      $this->retireEventSlugsOnDelete($connection);
      $this->syncIngestSource($connection);
  }
  ```
  No `touch()` call at all, gated or otherwise.
- `restored()` (lines 531-542):
  ```php
  public function restored(IntegrationConnection $connection): void
  {
      $this->refresher->refresh($connection);
      $this->syncIngestSource($connection);
      if (in_array($connection->platform, EventSlugSync::PLATFORMS, true)) {
          $this->syncEventSlugs($connection);
      }
  }
  ```
  No `touch()` call either.

Both call `refresher->refresh()` (the CDN purge), but neither rolls `site.updated_at`, so `public.profile:{handle}:{ts}` (the Redis payload cache keyed on that timestamp) does not rotate. For a completeness-gated platform (e.g. fresha/shop — anything with `hasCompletenessPredicate()`), disconnecting or restoring the connection can leave a stale cached payload (wrong page presence) until something else touches the site. Finding confirmed as still accurate.

---

## LIFE-11 (P2) — VERDICT: STILL-OPEN

Claim: `cleanupMirroredMedia` is the only best-effort side-effect method in the observer without a try/catch.

Evidence — `app/Observers/Core/IntegrationConnectionObserver.php:550-556`:
```php
private function cleanupMirroredMedia(IntegrationConnection $connection): void
{
    $folder = InstagramPayload::fromArray($connection->payload)->folder;
    if ($connection->platform === Platform::Instagram->value && $folder) {
        DeleteMirroredMediaJob::dispatch($folder);
    }
}
```
No try/catch. Every sibling best-effort method has one: `syncEventSlugs` (210-223), `retireVanishedEventSlugs` (258-289), `retireEventSlugsOnDelete` (330-356), `seedContentFromGoogle` (365-380), `enableContentInstagramAuto` (390-406), `reconcileContentInstagramSlots` (418-457), `syncIngestSource` (477-494), `updated()`'s dispatch block (509-529) all wrap in try/catch with `report()`+`Log::warning`. `cleanupMirroredMedia` is called from `deleted()` (line 464) between `refresher->refresh()` and `retireEventSlugsOnDelete()` — an uncaught `InstagramPayload::fromArray()` parse error or a `DeleteMirroredMediaJob::dispatch()` failure would propagate up through `deleted()` and skip `retireEventSlugsOnDelete()` + `syncIngestSource()` for that disconnect. Finding confirmed as still accurate.

---

## LIFE-12 (P2) — VERDICT: STILL-OPEN

Claim: `menuScrapeFailed`'s dedupe key has no failure-episode boundary (unlike `connectionRefreshFailing`, which has one per LIFE-9).

Evidence — `app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php`:
- `connectionRefreshFailing()` (lines 26-59): builds `$episode = $connection->last_refreshed_at?->toISOString() ?? 'never';` and uses `dedupeKey: "platform_connection_failed:{$connection->id}:{$episode}"` (line 54) — a new episode boundary every time `last_refreshed_at` advances (i.e., every recovery), per the LIFE-9 comment block (lines 38-46).
- `menuScrapeFailed()` (lines 65-78):
  ```php
  dedupeKey: "content_scrape:menu_failed:{$userId}",
  ```
  No episode component — just `userId`. A user whose menu scrape fails, recovers, then fails again within the notification's retention window (`retentionConfigKey: 'content_scrape'`) will be deduped against the first notification and never notified of the second failure. Finding confirmed as still accurate; the asymmetry between the two methods is real.

---

## LIFE-10 (P2) — VERDICT: PHANTOM

Claim: `reconcileContentInstagramSlots` has a TOCTOU race between the `hasSlots` existence check and slot creation that could create duplicate content-selection slots.

Evidence:
- `app/Observers/Core/IntegrationConnectionObserver.php:418-457` — the method does check-then-act: `hasSlots = ContentSelection::query()->where('site_id', $site->id)->whereIn('entry_type', ContentSelection::IG_TYPES)->exists();` then, if false, calls `app(ContentSelectionService::class)->setInstagramAuto($site, true)`. This check-then-act pattern is real.
- BUT `supabase/migrations/20260726000000_baseline_pilot.sql:2454-2455`:
  ```sql
  ALTER TABLE ONLY "site"."content_selection"
      ADD CONSTRAINT "content_selection_site_position_unique" UNIQUE ("site_id", "position");
  ```
  A DB-level unique constraint on `(site_id, position)` already exists in the baseline (not new — this predates the finding).
- `app/Services/Site/ContentSelectionService.php:222-282` (`setInstagramAuto`) computes `position` deterministically from the site's Instagram payload (reel@1, post@2) inside `DB::connection('pgsql')->transaction(...)` (line 228), and `persist()` (lines 353-362) does `delete()` then `forceCreate()` per row inside its own (nested/SAVEPOINT) transaction.
- Two concurrent racers computing the *same* site's *same* payload land on the *same* positions. Postgres's unique-index insertion protocol blocks the second inserter on the first inserter's uncommitted tuple and then raises a unique-violation once the first commits — so a genuine duplicate insert is not possible; the loser gets a `QueryException` that propagates up and is caught by `reconcileContentInstagramSlots`'s own try/catch (lines 449-456), which just logs a warning and drops that racer's attempt.

The specific outcome the finding warns about — **duplicate** content-selection slots — cannot occur given the existing unique constraint; the DB enforces mutual exclusion even under a race, and the code's own best-effort catch swallows the resulting conflict cleanly (one racer wins, one silently no-ops). No corruption, no duplicate rows. This is a phantom in the strict sense the audit itself warned about — the described failure mode already had DB-level coverage before the finding was written.

---

## JOB-4 (P2) — VERDICT: STILL-OPEN

Claim: ingest run `outcome` stays `'ok'` even when every projection fails, because the projection catch block doesn't downgrade `$worstOutcome`.

Evidence — `app/Ingest/Runtime/RunExecutor.php:163-186`:
```php
if (($landed['changed'] > 0 || $landed['tombstoned'] > 0)
    && ProjectorRegistry::has((string) $source['source_key'], $streamName)) {
    try {
        $this->projections->projectStream($source, $streamId, $streamName);
    } catch (\Throwable $e) {
        report($e);
        $notes[] = ['code' => 'projection_error', 'message' => $e->getMessage()];
        DB::table('ingest.anomalies')->insert([...]);
    }
}
```
No `$worstOutcome = $this->worse($worstOutcome, ...)` call anywhere in this catch block. `worse()` (line 377-382) already has a `'degraded'` rank (4) defined in its `$rank` map, ready to use, but nothing in `execute()` ever passes `'degraded'` as a candidate — it's dead in that map. If every stream lands cleanly (`$streamOutcomes[$streamName] = 'ok'`, line 150) but every projection throws, `$worstOutcome` never leaves `'ok'` and `ingest.runs.outcome` is written as `'ok'` (line 191) despite zero successful projections. Finding confirmed as still accurate.

---

## TEST-21 (P2) — VERDICT: PHANTOM

Claim: `PublicIntegrationConnectionResource::filterPayload()` (5 branches) has no dedicated test file and is genuinely uncovered.

- `tests/Feature/Resources/PublicIntegrationConnectionResourceTest.php` does **not** exist (confirmed — only `IndividualProfileResourceTest.php` and `UserStaffResourceTest.php` live in `tests/Feature/Resources/`).
- However `filterPayload()`'s five branches are each covered, extensively, through route-level tests hitting the real `/api/public/profiles/{handle}/integrations` endpoint (the correct way per this repo's own `feedback_direct_controller_call_antipattern` convention):

  1. **`platform === 'shop'` branch** (shop brand map, `SHOP_BRAND_ALLOWLIST`, linkMode global stamp, product pass-through) — `tests/Feature/Platforms/PublicIntegrationAllowlistTest.php`: "applies the per-brand allowlist to the Shopify brand map...", "passes each product gallery + per-variant image...", "stamps every shop brand linkMode from the GLOBAL site setting".
  2. **Non-array payload fail-closed (SEC-3)** — same file: "fails closed to an empty payload when a stored payload is a scalar, not an array (SEC-3)".
  3. **`$allowed === null` fail-closed (unregistered platform)** — same file: "returns an empty payload array (fail-closed) for a platform with no allowlist entry"; also globally proven for every *registered* platform by `tests/Feature/Platforms/Registry/PublicAllowlistCoverageTest.php` ("never reports MissingPublicAllowlistException for a currently-registered platform" + a mutation-proof companion test).
  4. **Normal per-platform allowlist filtering** — extensively covered: instagram (`_folder` strip), spotify/vimeo (`_scratch`/`apiPath` strip), mixcloud/tidal (5-key contract), square, 5 link-only platforms (snapchat/discord/telegram/kick/medium), events (eventbrite/humanitix enriched fields + `hiddenEventIds` privacy), booking/reservations (shared-key `url`+`provider` only), online-ordering, fresha (`teamMenu` staff-data privacy) — all in `PublicIntegrationAllowlistTest.php`.
  5. **`applyDisplaySettings` suppression branch** — same file: "serves the bandcamp releases list only when show_all_releases is enabled" (both toggle states).

All 5 branches have real coverage; the finding's premise (test file existence) doesn't match how this codebase organizes its resource tests (route-level, grouped by concern, per `feedback_direct_controller_call_antipattern`). Phantom.

---

## TEST-27 (P2) — VERDICT: PHANTOM

Claim: `GoogleBusinessService::resolvePhotoUrls()`'s budget-claim loop is untested; risk is real money spent via the uncapped Places API.

- Direct string match for `resolvePhotoUrls` in tests/: only a `ReflectionMethod` existence check in `tests/Feature/Platforms/RefreshHostLimitsTest.php:98/115` and an explanatory comment in `RefreshObservabilityTest.php:37` — so the *method name* isn't exercised directly.
- BUT the exact behavior of `resolvePhotoUrls()`'s claim loop (`app/Services/Platforms/GoogleBusinessService.php:449-472` — `$this->budget->claim('photos', $userId)` per photo, `break` on non-Granted, `UserCapReached` suppresses the Nightwatch report) is thoroughly exercised via the public entry point `fetchPlaceDetails()` in `tests/Feature/Platforms/PlacesBudgetGateTest.php`:
  - "claims exactly 16 times for one fetchPlaceDetails() with 15 fresh photos — the 16x finding, proven" — proves 1 details + 15 photo claims, asserts `Http::assertSentCount(16)` and the exact Redis budget counters per SKU/global/per-user.
  - "carry-forward photos claim nothing — 1 request, 1 claim, not 16" — proves the `! empty($photo['url'])` skip (line 457) claims zero budget for carried-forward photos.
  - "photo cap mid-fan-out is partial, not fatal — the connect still returns a card" — sets `photos` SKU cap to 5, proves exactly 5 of 15 photos resolve (the `break` at line 468) and `Http::assertSentCount(1 + 5)` — this is the direct proof of the `UserCapReached`/`break` path.
  - "details cap denies before the request..." and "a transport retry on Place Details claims twice..." cover the surrounding budget-claim discipline.
- This is genuine behavioral coverage of the claim loop, its cap-hit branch, and its carry-forward skip — via integration tests against the real code path rather than a unit test naming the private method. Money-spend risk (the stated motivation) is directly covered by the exact-count and cap-enforcement assertions. Phantom.

---

## SEC-4 (P2) — VERDICT: PARTIAL

Claim: raw `DB::insert()`/`ShopProduct::query()->insert($rows)` in `ShopController::setProducts()` bypasses `$fillable`; asks whether a key allowlist now exists.

Evidence — `app/Http/Controllers/Api/Platforms/ShopController.php:627-724` (`setProducts()`):
- The raw bulk insert is still there and still bypasses Eloquent entirely:
  ```php
  DB::connection('pgsql')->transaction(function () use ($brand, $selected) {
      ShopProduct::where('brand_id', $brand->id)->delete();
      if ($selected->isNotEmpty()) {
          $rows = $selected->map(fn (array $productData, int $index) => [
              'id' => (string) Str::uuid7(),
              'brand_id' => $brand->id,
              'product_id' => (string) ($productData['productId'] ?? ''),
              'position' => $index,
              'data' => json_encode($productData),
              'created_at' => $now,
              'updated_at' => $now,
          ])->all();
          ShopProduct::query()->insert($rows);
      }
  });
  ```
  The code comment above it (lines 685-694) explicitly acknowledges this bypasses `HasUuids` + the `data` array cast and reproduces both by hand — this is a deliberate, documented pattern (bulk-insert perf/transactional-safety tradeoff), not an oversight.
- No named "allowlist" constant/method was added. BUT the row array's keys are a **fixed literal set** written directly in the closure — `id`/`brand_id`/`product_id`/`position`/`data`/`created_at`/`updated_at` — never derived from request input. The only request-controlled value is `productIds` (validated `string, max:50` by `app/Http/Requests/Platforms/SetShopProductsRequest.php`), used only to select entries out of `$catalog` (server-fetched provider data) — it never contributes array *keys* to `$rows`, only selects which `$productData` (itself scraped, not client-supplied) gets JSON-encoded into the `data` column.
- So the literal `$fillable`-bypass is real (still true), but the specific exploit SEC-4 implies — arbitrary column injection via user-controlled keys — has no path here: there's no user-controlled key set reaching the insert, so an explicit allowlist would be redundant with what the fixed-literal-array already guarantees structurally.

Marked PARTIAL rather than ALREADY-DONE/PHANTOM because the raw-insert-bypasses-fillable *mechanism* the finding names is unchanged and no explicit allowlist construct was added — only the practical risk is mitigated by construction. Worth a second look only if a future edit to this closure starts spreading `$productData` (or any request-derived map) directly into `$rows` instead of the current per-key literal build.


<!-- ===== V6-privacy ===== -->

# V6 Privacy/GDPR Findings — Verification (2026-07-30)

Repo: /Users/joshuahunter/Herd/Side Street/backend, branch guard/postgres-lane-walker (read-only verification)

## PRIV-1 — VERDICT: STILL-OPEN

`AnalyticsEvent` (`app/Services/Analytics/AnalyticsEvent.php:75-76`) carries `latitude`/`longitude` as bare `?float`, no rounding in the constructor, `toArray()`, or `fromArray()`.

`PostgresEventWriter::visitRow()` (`app/Services/Analytics/Writers/PostgresEventWriter.php:132-133`) writes them straight through:
```
'latitude' => $e->latitude,
'longitude' => $e->longitude,
```
No `round()`/`ROUND()` anywhere in the writer. Retention is `analytics_raw_event_retention_days` default 90 (`PurgeRawAnalyticsEvents::TABLES` includes `analytics.site_visits`, cutoff enforced ≥30 days). Full-precision coordinates persist for the full retention window. No truncation was added.

## PRIV-2 — VERDICT: STILL-OPEN

`AnalyticsEventSanitizer::userAgent()` (`app/Services/Analytics/AnalyticsEventSanitizer.php:51-58`) is unchanged in substance:
```php
public static function userAgent(?string $userAgent): ?string
{
    if ($userAgent === null || $userAgent === '') return null;
    return Str::limit($userAgent, self::USER_AGENT_MAX_LENGTH, '');
}
```
Still a pure length cap at 256 chars (`USER_AGENT_MAX_LENGTH`), no reduction to browser family / device class. Docblock even says explicitly this is deliberate ("device_type is derived separately, so the raw UA adds no dashboard value beyond this"), i.e. the design choice, not an oversight, but the finding's technical claim stands: the full UA string (truncated at 256, not summarized) is what's persisted.

## PRIV-3 — VERDICT: PARTIAL

`moderation.case_signals` and `moderation.evidence` are still **not** in `DataExportPayloadBuilder::COVERED_PII_TABLES` (checked `app/Services/User/DataExport/DataExportPayloadBuilder.php:53-67` — no `moderation.*` entries), and therefore not required to appear in `AccountDeletionService::PURGED_PII_TABLES` by `DataExportCoverageTest`'s "every exported PII table has an erasure path" assertion (`tests/Feature/Security/DataExportCoverageTest.php:188-197`) — that guard is scoped to *exported* tables only, and these two are deliberately excluded from export (`DataExportPayloadBuilder::streamContentReports()`, lines 764-804, explicitly withholds signal-level/reporter-identity detail from the subject's own export for third-party-protection reasons — reasonable, but leaves the generic coverage guard structurally blind to `case_signals`/`evidence` erasure).

However, the erasure paths themselves are NOT untested — each has its own dedicated regression test outside the generic guard:
- `purgeCaseSignalPii()` (`AccountDeletionService.php:1037-1058`) — asserted by `tests/Feature/Account/AccountDeletionPurgePiiTest.php` (e.g. "nulls out reporter_user_id, reporter_email and reason_details on case_signals (P2-11)", line 148) and `tests/Feature/Console/PruneResolvedCaseSignalsPiiCommandTest.php`.
- `purgeReportedUserEvidencePii()` (`AccountDeletionService.php:1077-1114`, PRIV-4 in-code label) — asserted end-to-end by `tests/Feature/Account/AccountDeletionPurgeEvidencePiiTest.php`.

So: the audit's literal claim ("not tracked by the export/erasure coverage guard") is still true — `COVERED_PII_TABLES`/`PURGED_PII_TABLES` genuinely don't mention these tables, and there's no explicit documented-exclusion note analogous to the DINT-2 (`ingest.record_versions`/`effects`) or #PRIV-4 (`site.site_documents`) callouts in `COVERED_PII_TABLES`'s docblock. But the underlying risk the finding was really about — "does this erasure actually run and is it verified" — is covered by real, purpose-built tests. Net: the generic guard gap is real but the practical erasure behavior is independently regression-tested, hence PARTIAL rather than STILL-OPEN or ALREADY-DONE.

## PRIV-4 — VERDICT: STILL-OPEN

`PurgeRawAnalyticsEvents::TABLES` (`app/Console/Commands/PurgeRawAnalyticsEvents.php:19-28`):
```
analytics.link_clicks, analytics.site_visits, analytics.lead_submissions,
analytics.section_views, analytics.item_views, analytics.action_events,
analytics.site_sessions
```
`analytics.content_popularity_scores` is not present. No other purge command references it (only score-threshold fade-out logic exists elsewhere, not a time-bound delete). Confirmed still open.

## 271-PRIV-1 — VERDICT: STILL-OPEN

`site.item_slugs` DDL (`supabase/migrations/20260726000000_baseline_pilot.sql:1658-1671`) columns: `id, user_id, item_type, item_key, slug, is_current, created_at`. No `retired_at` (or any timestamp marking when a row became non-current). No later migration adds one — grep across `supabase/migrations/` for `item_slugs` only hits the baseline file and the newer `content.item_slugs` (20260727140000_content_schema.sql), which has the *same* shape (`is_current`, `created_at`, no retirement timestamp).

`routes/console.php` has no slug-prune schedule — grepped for `prune|purge` across the whole file; the closest neighbors are unrelated (`handles:prune-expired-aliases`, `moderation:prune-resolved-signal-pii`, etc.), nothing targets `item_slugs`. `ItemSlugAllocator` only ever inserts/reactivates/hard-deletes-on-`forget()` rows; retired (`is_current=false`) rows accumulate indefinitely with no age-based cleanup. Confirmed still open, and now doubled by the new `content.item_slugs` table carrying the same gap.

**RESOLVED 2026-07-31.** `site.item_slugs` closed by 271-PRIV-1 (Tier 2, `499c13ef`). The `content.item_slugs` half — which never had a finding id of its own — closed on `audit-fix/content-slugs-retention-2026-07-31` with a deliberately SMALLER fix: column + a stamp in `ItemMerger::moveSlugs()` + a second arm on the existing `slugs:prune-retired`, but NO allocator, NO read filter and NO backfill. Reason: nothing mints rows into `content.item_slugs` (0 rows on dev vs 395 `content.items`) and nothing reads it, so the defect was latent, not active. `moveSlugs()` does genuinely retire slugs, so a future minter would have started accumulating immediately.


## 271-PRIV-2 — VERDICT: STILL-OPEN (confirmed as current, deliberate behaviour)

Traced the full path for a **claimed** google-business connection:

1. `PublicIntegrationConnectionResource::ALLOWLIST['google-business']` (line 154) includes `'reviews'` and `'reviewSummary'` among the public keys.
2. `DisplaySettingsFilter::SUPPRESSIONS['google-business']['reviews']` (lines 32-33) strips `['reviews', 'reviewSummary', 'rating', 'reviewCount']` when the `reviews` toggle is off. The default is resolved via `TOGGLE_DEFAULTS[$platform][$toggle] ?? true` (line 104); `google-business.reviews` has **no** entry in `TOGGLE_DEFAULTS` (only `bandcamp.show_all_releases` is overridden), so the default is `true` (**ON** — opt-out, not opt-in). An owner who never touches the toggle ships reviews publicly.
3. `GoogleBusinessPayload::stripThirdPartyPii()` (`app/Services/Platforms/Payloads/GoogleBusinessPayload.php:112-127`) is the only code that removes reviewer name/uri/photo/text (drops `reviews` entirely + `authors` from each photo). Its docblock states explicitly: "Used ONLY on the provisional (pre-claim) write paths... The authenticated connect() / GoogleBusinessEnrichJob / refresh-for-claimed-user paths never call this and keep the full payload."
4. Call sites confirm this: `GoogleBusinessSourceGenerator.php:72` (pre-account build) and `GoogleBusinessFetch.php:69` (refresh strategy, gated on `$connection->user()->value('status') === 'unclaimed'`) call `stripThirdPartyPii()`. Nothing on the claimed/authenticated connect path does.
5. `GoogleBusinessFetch.php:61-70`'s own comment states the self-healing intent: "claim flips status to 'active' and the very next refresh restores full data" — i.e. reviewer PII is *deliberately restored* once the account is claimed.
6. `app/Console/Commands/BackfillClaimedGoogleBusinessReviewsCommand.php` exists specifically to force a re-fetch (and thus reviewer-PII restoration) for already-claimed accounts whose payload is still missing reviews from before this remediation shipped — i.e. there is active tooling whose purpose is to *ensure* claimed accounts get full reviewer data back.

Conclusion: the provisional-vs-claimed asymmetry is real and intentional (not a residual bug) — provisional/pre-claim sites strip reviewer PII, claimed sites get it restored on next refresh and it ships to the public, CDN-cached wire by default. The toggle to hide it is opt-out (default ON), not opt-in.

## 271-SEM-1 — VERDICT: STILL-OPEN (no regression test)

`ItemSlugAllocator::ensureCurrent()` (`app/Services/Site/ItemSlugAllocator.php:31-61`) no-op guard:
```php
if ($current !== null && $this->stripSuffix($current->slug) === $base) {
    return $current->slug;
}
```
`stripSuffix()` (lines 329-332): `preg_replace('/-\d+$/', '', $slug)` — strips ANY trailing `-<digits>`, with no way to distinguish "this is a collision suffix `-2`/`-3`" from "this is part of the slugified name itself" (e.g. an item literally named "Table 9" slugifies to `table-9` with `-9` stored as part of the base, not a collision suffix — `allocateSlug()`'s `$n===1 ? $base : $base.'-'.$n` loop stores the bare base first, so a name-derived trailing-digit slug is indistinguishable in storage from a suffixed one).

Concrete failure: item named "Table 9" → slug `table-9` (n=1, no collision). Owner renames to "Table" → `base('Table') = 'table'`; `stripSuffix('table-9') = 'table'` (regex strips `-9`) → equals new base → no-op branch fires → the item keeps serving the stale public slug `table-9` under its new name "Table" instead of minting `table`.

Checked test coverage: `tests/Unit/Services/Site/ItemSlugAllocatorTest.php` and `tests/Postgres/ItemSlugAllocatorRegressionTest.php` — neither exercises a name that slugifies to a trailing-digit base being renamed to something without one. The Postgres regression test is a different bug (25P02 transaction-poisoning under a collision), not this one. No test targets `stripSuffix()`'s conflation directly. Confirmed still open, unguarded.

## DINT-1 — VERDICT: STILL-OPEN

Indexes present on `analytics.action_events` (baseline `supabase/migrations/20260726000000_baseline_pilot.sql:2594,2598`): `action_events_occurred_at_idx (occurred_at)`, `action_events_site_occurred_idx (site_id, occurred_at)`. None on `user_id`.

Indexes present on `analytics.item_views` (lines 2614, 2618, 2622): `item_views_occurred_at_idx (occurred_at)`, `item_views_site_item_idx (site_id, item_type, item_id)`, `item_views_site_occurred_idx (site_id, occurred_at)`. None on `user_id` (there is an RLS policy referencing `user_id` at line 4017, but no index).

No other migration file touches either table's indexes. `AccountDeletionService::purgeItemViewsPii()` / `purgeActionEventsPii()` (both listed in `PURGED_PII_TABLES`) filter by `user_id` — both would sequential-scan. Confirmed still open.

## #API-1 — VERDICT: STILL-OPEN (no allowlist; mitigated only by upstream shape, not enforced at the wire)

No `SHOP_PRODUCT_ALLOWLIST` constant exists anywhere in the codebase (grepped `app/`). `PublicIntegrationConnectionResource::SHOP_BRAND_ALLOWLIST` (line 236) allowlists brand-level keys only (`id, provider, url, name, currency, favicon, logo, discountCode, linkMode, referralQuery, products`) — `products` itself is included wholesale with no per-product-field filter; the docblock says outright "products pass through verbatim (each carries `url`)" (line 231).

`ShopBrand::toBrandArray()` (`app/Models/Core/Site/ShopBrand.php:123-152`) confirms: `$products = $this->products->map->data->all()` (unranked path) or a closure that copies `$data = is_array($p->data) ? $p->data : []` and only *adds* `popularityRank` (ranked/public path, lines 125-134) — never subtracts/filters keys. Whatever is stored in `ShopProduct.data` reaches the public wire verbatim.

Traced what actually populates `ShopProduct.data`: every writer (`ShopProductSeeder.php:100`, `ShopCatalog::syncLatest` at `ShopCatalog.php:126`) stores the fetcher's raw output object directly as `data`. Checked the fetchers' own shapes:
- `GenericShopScraper::productsFromJsonLd()`/OpenGraph fallback (`GenericShopScraper.php`) — curated field set only (`productId, title, handle, vendor, description, image, images, price, currency, variantId, available, url`), not raw JSON-LD passthrough.
- `ShopifyScraper.php` — likewise maps to a curated set (`productId, title, handle, ...`), not the raw Shopify `products.json` object (which would carry `tags`, `body_html`, full `variants[]`, `options[]`, internal IDs, etc.).

So in current practice every product field that reaches the wire happens to be non-sensitive, but there is still **no enforcement point** — if any fetcher's shape changes (a raw passthrough, a debug field, an internal ID) it ships to unauthenticated visitors with zero allowlist gate, unlike every other platform in `PublicIntegrationConnectionResource::ALLOWLIST` which fails closed on unlisted keys. The original finding's core claim ("no per-field allowlist for products") is accurate and unchanged.
