# Programme Review — consolidated findings (2026-08-17)

Every finding from the Content Pool Convergence whole-programme review gate, in one
executable file. Supersedes the six per-run `CONSOLIDATED.md` files it was built from.

**Reviewed base:** `origin/development` @ `ce890848b` (review branch `review/programme-convergence`,
merged as `7a6d322a3` / `a62628c89`). Review record: spec §30.
**Pipeline:** scan-tier drafts by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`, across six
`audit.sh` runs — five `pre-merge` bundles over targeted scopes plus one `security` bundle over the
new public surface.

## Findings at a glance

| Priority | Count |
|---|---|
| P0 — blockers | 0 |
| P1 — high     | 6 |
| P2 — medium   | 15 |
| P3 — low      | 15 |

## READ THIS BEFORE TICKING ANYTHING — ids were renumbered

The six source runs used **overlapping id spaces**. `#TEST-1` named FOUR different findings
across them, `#CFG-1` four more, `#API-1` and `#API-2` three each. Merging them under their
original ids would let a tick land on an unrelated — sometimes already-closed — finding while the
real box stayed open, and nothing would fail.

So every finding here carries a **new, globally unique `PGR-n` id**, and records its origin as
`**Source:** <run> — was `#<original id>``. When acting on one, match the finding TEXT, not the id.
Never script a tick keyed on id alone.

## Execution policy  (how `execute audit` runs this file)

- **Plan:**       Opus 4.8
- **Implement:**  Sonnet 4.6
- **Review:**     Sonnet 4.6  — a *separate, independent* instance (never the implementer)
- **Combine plan+impl:** YES for S/XS effort · NO for P0/P1 or L/XL (those plan first, then implement)
- **Per-item override:** escalate to Opus for gnarly logic or risky blast radius; a trivial
  mechanical S item may drop to Haiku. Default to the table above unless an item clearly warrants a change.
- **Trigger:** say `execute audit <path to this file>` to run plan → implement → independent review
  per bundle/item. Blockers (P0 · auth · money · DB/migration · L/XL) pause for sign-off.
  Full runbook: `scripts/audit/fix-flow.md`.

**Blocker gate applies to at least these:** `PGR-5` (auth / PII on a public endpoint) and
`PGR-6` (cache + DB write paths, and an owner ruling already rode on it). Plan first, do not bundle.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 6 of 6 complete
- P2 Medium: 3 of 15 complete
- P3 Low: 0 of 15 complete

## Suggested Bundled Sessions

Units below cover the **P2 tier only** — P1 is closed, and P3 is deliberately left unbundled (see the
note at the end of this section). Run them **in the listed order**: bundles 1 and 3 both touch
`PublicEmailSubscriptionController.php`, so running 3 before 1 rebases the sanitizer work onto an
edited file for no reason.

Effort labels are the per-finding ones already recorded below. Per this file's `## Execution policy`,
plan+implement are **combined for all-S units** and kept **separate for units containing an M**.

- **Bundle 1 — PII sanitizer on public write paths:** #PGR-18, #PGR-19, #PGR-20
    - **Why grouped:** One root cause, one remedy — three call sites skip the codebase's existing
      User-Agent / query-param minimizing sanitizer that a neighbouring path already applies. Two files
      (`AnalyticsController`, plus the three public form controllers). Fixing them apart means proving
      the same sanitizer contract three times.
    - **Effort:** S + S + S. Plan+implement combined.
    - **Model:** Plan: Opus 4.8 · Implement: Sonnet 4.6 · Review: Sonnet 4.6.

- **Bundle 2 — Third cache lane on remaining owner-write paths:** #PGR-36
    - **Why grouped:** Single finding, but it is the known residue of #PGR-6 and rides the
      `SiteCacheLanes::bust()` seam that #PGR-6 introduced — it belongs to that work, not to a
      general-purpose bundle. Three call sites (`ManualOverrideController::bumpSites`,
      `ItemMerger::bumpSites`, `SectionItemController::upsert()/destroy()`).
    - **Effort:** M. Plan and implement kept separate.
    - **Watch:** `ProjectionWriter::bumpSite()` fires lane 1 **by design** and is NOT in scope — it is a
      per-item primitive whose batch callers discharge lanes 2+3 once at the request boundary. Do not
      "fix" it. `PoolCacheLaneSeamTest.php` is the guard that must stay green.
    - **Model:** Plan: Opus 4.8 · Implement: Sonnet 4.6 · Review: Sonnet 4.6.

- **Bundle 3 — Small correctness, low blast radius:** #PGR-13, #PGR-16, #PGR-17
    - **Why grouped:** Three unrelated but individually tiny fixes (dashboard reads null favicon/logo;
      subscription lookup ignores the indexed `email_lc`; alias 301 caches for 5 minutes). No shared
      state, no shared file except the subscription controller already touched by bundle 1.
    - **Effort:** S + S + S. Plan+implement combined.
    - **Verify premise first:** #PGR-13 asserts "the writer now populates them" — confirm that writer
      change is still in place before changing the reader, or the fix is a no-op against real data.
    - **Model:** Plan: Opus 4.8 · Implement: Sonnet 4.6 · Review: Sonnet 4.6.

- **Bundle 4 — Missing guards on table-less / driver-shaped surfaces:** #PGR-14, #PGR-15
    - **Why grouped:** Both are **test-only** units adding coverage the services lane already has and the
      menu lane doesn't — a `normalizeRow()` unit test fed PDO_PGSQL-shaped scalars, and a query-surface
      tripwire for the three menu DTO models. No production code changes; symmetric with the existing
      `LegacyServiceQuerySurfaceTest.php`.
    - **Effort:** S + S. Plan+implement combined.
    - **Watch:** `MenuItem`, `MenuCategory`, `MenuItemPlatform` are table-less DTO carriers kept on
      purpose. The unit adds a guard against querying them; it must not delete them or move them into
      `PurgeSoftDeleted::PURGE_HANDLED`.
    - **Model:** Plan: Opus 4.8 · Implement: Sonnet 4.6 · Review: Sonnet 4.6.

- **Bundle 5 — Backfill / prune command batching:** #PGR-8, #PGR-9, #PGR-10
    - **Why grouped:** One pattern in three commands — an unbounded result set held open (`cursor()`, or a
      fully materialized ID list) with per-row work inside. Same remedy shape (chunk, then do the
      expensive work per chunk), so the reviewer checks one contract three times rather than three.
    - **Effort:** M + M + S. Plan and implement kept separate.
    - **Model:** Plan: Opus 4.8 · Implement: Sonnet 4.6 · Review: Sonnet 4.6.

- **Bundle 6 — Postgres-lane concurrency coverage:** #PGR-7, #PGR-12
    - **Why grouped:** Both need a real second committing connection and follow the same established
      idiom (`ProjectionIdentityKeyAtomicityTest.php`, `EffectLedgerConcurrencyTest.php`). Written
      together, the fork/injection harness is set up once.
    - **Effort:** M + M. Plan and implement kept separate.
    - **Watch:** These MUST run in `tests/Postgres/` (`composer test:pg`). The SQLite mirror has no
      independently-committing second connection and will pass vacuously — a green `composer test`
      proves nothing here. Pin the refusal reason, not just the row count.
    - **Model:** Plan: Opus 4.8 · Implement: Sonnet 4.6 · Review: Sonnet 4.6.

**P3 is not bundled, on purpose.** Per `CLAUDE.md` ("Opportunistic fixes — absorb the P3 tail, never
schedule it"), the fifteen P3s below are absorbed when a session already has the file open for real
work, not run as a campaign. Do not build units for them.

## Standalone — do NOT bundle

- **#PGR-11** — `ConvergeSiteSubdomainsCommand` commits the raw subdomain rename before cache and KV
  invalidation.
    - **Why standalone:** It is a locked, destructive DB write against `site.sites` paired with an
      external KV mutation, and getting the reordering wrong misroutes live subdomains. The finding's
      own note says the risk is currently theoretical because production carries no live traffic — so
      the fix has low upside today and a real downside if botched. It earns its own branch and its own
      review, and the blocker gate applies (plan first, wait for sign-off).
    - **Effort:** M.
    - **Model:** Plan: Opus 4.8 · Implement: Sonnet 4.6 · Review: Sonnet 4.6.

---

## P1 — Fix before pilot launch

- [x] **#PGR-1** · P1 — `ProvisionShopPinsCommand`'s three-lane invalidation is unguarded by CI
    - **Resolution (2026-08-18, `audit-fix/programme-review-p1-2026-08-17`):** DONE, premise partly restated. "No CI check enforces this" was **stale** — `ShopPinProvisioningTest.php:230-246` already asserted three lanes. But its lane-1 check was `->exists()`, a row-existence test that still passes with `BuildState::bump()` deleted, and no case asserted `--dry-run` stays silent. Both of the finding's actual asks are now real tests: exact per-site `content_revision` deltas across **two independent sites**, plus a new `--dry-run` fires-none case. `invalidate()` now routes through `SiteCacheLanes::bust()`, and the new `tests/Feature/Architecture/PoolCacheLaneSeamTest.php` fails if this command or the four Content controllers re-roll the lanes by hand. Every lane assertion was proven non-vacuous by deleting its lane and observing the failure.
    - **Source:** migration-commands — was `#TEST-1`
    - **Where:** app/Console/Commands/ProvisionShopPinsCommand.php
    - **Affects:** Shop-page owners after pin backfill; if any invalidation lane is dropped in a refactor, pinned products may not appear on the live/edge sitepage.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a feature test for the non-dry path asserting `BuildState::bump`, `site.sites.updated_at` update, and `CloudflareCachePurgeJob` dispatch occur exactly once per touched site.
        - Add a dry-run test asserting none of those side-effects fire.
    - **Technical:** This command raw-inserts `site.section_items` pins and manually invalidates Redis payload cache, model cache, and Cloudflare edge cache via `invalidate()`. Its own docblock states "No CI check enforces this," which is current, verbatim source — not a stale comment. A structural sweep mirroring the house `JobHygienePolicyTest`/`PolicyCoverageTest` pattern would catch a dropped lane before it reaches a merge.
    - **Plain English:** Imagine re-ordering shelves but forgetting to tell the website and the CDN to refresh. The command's own notes admit no automated check watches for that mistake — so a future edit could quietly break it and nothing would catch it before it ships.
    - **Evidence:**
        ```php
        // Raw-write seam: all three lanes by hand (spec §4). bump() alone is not
        // enough — the payload cache key composes from sites.updated_at, and the
        // CDN outlives the origin write. No CI check enforces this.
        ```

- [x] **#PGR-2** · P1 — `ContentRepairEventItemsCommand` retirement test proves only `removed_at`, not cache/edge invalidation
    - **Resolution (2026-08-18, `audit-fix/programme-review-p1-2026-08-17`):** DONE, but the finding was **materially stale as written** and was restated before any code was touched. `EventItemRepairTest.php:187-236` *already* asserted all three lanes. The finding's quoted evidence — "The test asserted removed_at only, so it passed while proving nothing about the invalidation" — was a verbatim lift of the past-tense code comment at `ContentRepairEventItemsCommand.php:89-90`; the pipeline read a historical narration as current state. Implementing it as written would have rewritten a test that existed. The four **genuine** residual gaps were fixed instead: (1) the lane-1 assertion was `->toBeGreaterThan(0)`, banned by this run's execution prompt — now an exact `$revisionBefore + 1` delta; (2) coverage was single-site — a new multi-tenant case asserts the lanes fire per site; (3) lane 3 was a bare `assertPushed`, which passes even if the code dispatched the site UUID instead of the subdomain — now a discriminating closure (proven: switching the dispatch to `$site->id` makes it fail); (4) `#PGR-4` had no test at all — now covered. That stale sentence has been deleted from the command's comment block, since it is what caused this finding to be adjudicated against already-correct code.
    - **Source:** migration-commands — was `#TEST-2`
    - **Where:** app/Console/Commands/ContentRepairEventItemsCommand.php
    - **Affects:** Repair operators and users whose published sitepages may keep serving retired event items after a `--retire` run; a green test gives false confidence that invalidation happened.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add assertions that a non-dry `--retire` run bumps `BuildState`, touches `site.sites.updated_at`, and dispatches `CloudflareCachePurgeJob` for each affected site.
        - Add a case that fails when any of the three invalidation side-effects is missing.
    - **Technical:** The command raw-writes `content.items.removed_at` and must then invalidate Redis/edge caches by hand because no Eloquent observer fires. The in-code comment records that the specific SQLite masking bug behind the original incident (a double-quoted `"deleted_at"` filter silently compiling to a string literal) has since been removed from the query — but the underlying gap this finding is about is untouched: the retirement `UPDATE` still commits before the site/invalidation work runs, and the comment states plainly that the existing test "asserted `removed_at` only, so it passed while proving nothing about the invalidation." See #MIG-2 for the companion ordering fix this test should exercise.
    - **Plain English:** This is like checking only the "deleted" flag on an event and never confirming the public website or CDN was told to refresh. The test says the job passed while the site could still show the retired event.
    - **Evidence:**
        ```php
        // The test asserted removed_at
        // only, so it passed while proving nothing about the invalidation.
        ```

- [x] **#PGR-3** · P1 — `BackfillPreviousWebsiteContentScanCommand`'s dispatch stagger resets every 200-row chunk instead of spreading across the whole run
    - **Resolution (2026-08-18, `audit-fix/programme-review-p1-2026-08-17`):** DONE. The adjudicated text was right and the scan draft's reasoning was wrong — `floor()` wraps the whole expression, so the ramp was correct *within* a chunk; the arithmetic was left alone. The precise mechanism: `Collection::chunk()` preserves keys and `$rows` came from `->get()`, so keys were already cumulative — **`->values()` was the entire bug**, re-indexing each chunk to `0..199`. The chunk loop was also dead weight (`->get()` had already materialised everything) and is deleted. The ramp is now a whole-run rate driven by **jobs dispatched**, not row index, so a row skipped for a null site no longer punches a hole in the schedule.
        - *Owner decisions:* a fixed **per-row rate, unbounded run, no delay cap** (a fixed total window would collapse spacing as N grows — worse than the bug; a capped delay would reintroduce a tail-flood on the billed lane), at **2s/row**, up from the effective 1.5 (300/200) — rounded up as the conservative direction on a billed path, and integer so the curve is exactly assertable.
        - ⚠️ **A hidden invariant was disturbed, deliberately and with sign-off.** The old max delay was **298s** — one tick under `ScanPreviousWebsiteContentJob::$uniqueFor = 300`, almost certainly not a coincidence: the per-chunk ramp kept every delayed job inside its own `ShouldBeUnique` lock. Under an unbounded per-row rate, jobs past ~150 rows are delayed beyond that lock, which expires before they run — so `WorkplaceObserver`'s own trigger is unblocked and a user editing their site mid-run can cause a second, **duplicate-billing** scan. Accepted by the owner; documented in the command's docblock so the next operator sizes `--limit` against it.
        - *Also added:* `--limit` (query-level, so it bounds memory too) with `->orderBy('site_id')` for determinism — documented as a **cap, not a cursor**, since no "already scanned" filter exists and a second capped run re-picks and re-bills the same leading rows; `--stagger-seconds`; and `->with('site')`, removing an N+1. A **negative `--stagger-seconds` is rejected, not clamped** — `max(0, -5)` is `0` and `$dispatched * 0` is `0` for every job, so clamping would have resolved bad input to the exact hazard it was meant to prevent (the whole fleet dispatched at once onto the billed OCR path). `0` stays legitimate, with that consequence stated in the error text.
        - *Not done, deliberately:* `->get()` was **not** converted to `chunkById()` — `site.workplaces`' PK is a non-sequential TEXT UUID that does not compose with `--limit`, the repo's `chunkById` rule is a *streaming* rule while this is a *counter* bug, and measured scale is 9 rows on dev / 0 users on prod. Promote if the fleet crosses ~10k.
        - *Coverage:* the finding's implicit "no test" premise was **false** — the file existed with 4 tests, none asserting delay. Four added (cumulative ramp across a 201-row population; jobs-dispatched vs rows-scanned; `--limit`; negative-stagger rejection) plus a dry-run projection assertion. Non-vacuity proven by *isolated* probes — reverting the whole file was rejected as proof, because it failed for incidental reasons (a lazy-load exception, missing options) rather than the defect. Reintroducing **only** the re-index made the ramp test report a zero-delay count of **2 instead of 1** — two jobs racing at the same instant, the defect's signature. Removing **only** the negative guard let all 3 jobs reach the billed queue.
    - **Source:** migration-commands — was `#MIG-1`
    - **Where:** app/Console/Commands/BackfillPreviousWebsiteContentScanCommand.php
    - **Affects:** Every existing Workplace with a `previous_website`; the scraping queue and the billed Mistral OCR / MenuAiExtractor spend on a real fleet-wide run.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the per-chunk `$i` with a cumulative position across the whole run (e.g. `$chunkIndex * self::DISPATCH_SPREAD_SECONDS + floor($i / self::BATCH_SIZE * self::DISPATCH_SPREAD_SECONDS)`), so the spread scales with total population rather than repeating every 200 rows.
        - Verify the corrected delay curve in `--dry-run` output before the next real run.
    - **Technical:** `$i` is re-indexed to `0..199` for every 200-row `$batch->values()` chunk (`chunk()` preserves original keys, but `values()` resets them), so `floor($i / 200 * 300)` does correctly ramp from 0 to ~298s — but it ramps identically for *every* chunk. Since `->delay()` only sets `available_at` (no actual sleep between chunks), a fleet of N workplaces produces `ceil(N/200)` overlapping copies of the same 0–298s ramp rather than one smooth 300s spread across the whole population. For a large install base this collapses the intended throttle into simultaneous bursts of size `N/200` at each point along the ramp, hitting the scraping queue and the billed OCR/AI-extraction path in waves instead of a trickle — precisely the failure the command's own docblock says the spread exists to prevent.
    - **Plain English:** The command promises to mail the re-scan requests over five minutes, but the postmark machine resets its page counter every 200 envelopes and restarts the same five-minute stamping pattern each time. Run it on a big enough mailing list and multiple pages hit "send now" together instead of trickling out — hitting the paid scanning service in bursts rather than a steady drip.
    - **Evidence:**
        ```php
        foreach ($batch->values() as $i => $workplace) {
            $site = $workplace->site;
            if ($site === null) {
                continue;
            }
            $delaySeconds = (int) floor($i / self::BATCH_SIZE * self::DISPATCH_SPREAD_SECONDS);
            ScanPreviousWebsiteContentJob::dispatch(
                (string) $site->user_id,
                (string) $workplace->site_id,
                trim((string) $workplace->previous_website),
            )->delay(now()->addSeconds($delaySeconds));
            $dispatched++;
        }
        ```

- [x] **#PGR-4** · P1 — `ContentRepairEventItemsCommand` retires items before resolving invalidation targets; any failure between the two leaves a half-applied destructive update
    - **Resolution (2026-08-18, `audit-fix/programme-review-p1-2026-08-17`):** DONE, premise CONFIRMED exactly as written. The `site.sites` resolution now runs **before** the one-way `content.items.removed_at` update (the shape `PurgeReviewHeadlinePiiCommand.php:72-81` already used), and the three DB mutations — the retirement, `BuildState::bump()` and the `site.sites.updated_at` touch — are wrapped in one `DB::connection('pgsql')->transaction()`. The `CloudflareCachePurgeJob` dispatch moved **outside** the transaction: `config/queue.php` sets `after_commit => false`, so dispatching inside would have re-created the very bug being fixed, and `->afterCommit()` was deliberately NOT used (the job declares no `$afterCommit`, and adding one as a typed property is a fatal — `Queueable` declares it untyped). A dispatch failure is now reported loudly (`report()` + an operator message naming the subdomains + `self::FAILURE`) rather than swallowed, because a re-run will **not** retry the purge — the second run finds nothing orphaned and invalidates nothing, so silence would leave a permanently stale edge. `$orphaned` is deliberately **not** re-read under lock: `SELECT … FOR UPDATE` on `content.items` would contend with the live `ingest:project` writer and would not close the only real race anyway (the projector writes `source_items`, not `items`) — accepted residual, noted in code. Proven by a failure-injection dataset test that drops the table the invalidation half needs: the `site.sites` case proves the **ordering**, the `site.site_build_state` case proves the **rollback**, and both were observed failing against the pre-fix code (`Failed asserting that '…' is null` — the retirement had committed) and passing after.
    - **Source:** migration-commands — was `#MIG-2`
    - **Where:** app/Console/Commands/ContentRepairEventItemsCommand.php
    - **Affects:** `content.items` retired via `--retire` and the public sitepage caches for affected sites.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Resolve the affected `site.sites` rows and subdomains **before** the `content.items` retirement update, as `PurgeReviewHeadlinePiiCommand` already does for the analogous review-PII purge.
        - Wrap the reachable DB mutations in a transaction and dispatch `CloudflareCachePurgeJob`/cache busts after commit, or at minimum `report()` loudly if the invalidation half cannot complete.
    - **Technical:** The specific trigger behind the incident this command's comment documents — a `whereNull('deleted_at')` filter against `site.sites`, a column that table has never had — is already removed from the current query, so that exact 42703 failure can no longer recur. The structural risk it exposed remains, though: `UPDATE content.items SET removed_at ...` still commits immediately, and only afterward does the code resolve `site.sites` and perform the three manual invalidations (`BuildState::bump`, `updated_at` touch, `CloudflareCachePurgeJob::dispatch`). Any other fault in that window — a transient DB disconnect, a Cloudflare dispatch failure, a killed worker — reproduces the same half-applied shape: retired in the DB, still served from cache/edge. Precomputing the affected sites first and moving invalidation after a committed transaction (with loud `report()` on partial failure) closes the whole class of failure, not just the one instance already patched.
    - **Plain English:** This is like moving a batch of files to the archive, then going to update the index cards. The specific card-jam that broke it before is fixed, but the process is still "move first, update the index second" — so a different interruption at the same point would leave the same result: the file room says the files are gone, but the front desk still points customers at the old shelf.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->table('content.items')
            ->whereIn('id', $orphaned->pluck('id')->all())
            ->update(['removed_at' => now(), 'updated_at' => now()]);

        $sites = DB::connection('pgsql')->table('site.sites')
            ->whereIn('user_id', $orphaned->pluck('user_id')->unique()->all())
            ->get(['id', 'subdomain']);
        ```

- [x] **#PGR-5** · P1 — Handle→email login-identifier endpoint discloses a professional's private `primary_email`, not their published contact address
    - **Source:** public-surface-security — was `#SEC-1`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicLoginIdentifierController.php:37-45
    - **Affects:** Every professional's private login email; handles are public subdomains, so this is a systematic email-harvesting vector across the whole user base.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Keep handle-based sign-in working, but stop handing the raw email back to the client. Resolve handle → user server-side and drive the Supabase OTP/password-reset call from the backend instead of returning `primary_email` to the browser for the frontend to hand to `signInWithPassword`/`signInWithOtp`.
        - If the frontend genuinely needs the email string client-side, add a stricter per-resolved-user rate limit (not just per-IP) — the current 40–120ms jitter defeats timing side-channels but not a slow, distributed handle walk.
    - **Technical:** Verified `primary_email` (`User.php:36`) is a distinct column from the intentionally public `public_contact_email` (`User.php:38`) — it's the private Supabase auth/login address, not something a professional has chosen to publish. The controller's own docblock already concedes "Returning the email IS a small enumeration leak (handle → email)" and mitigates only with response-time jitter and route throttling — neither stops an attacker from slowly walking every published handle (all publicly listable by design) to build an email/handle pairing list. No related change appears in recent commit history; this is unaddressed.
    - **Plain English:** Anyone can type in a professional's public page address (their handle) here and get back the private email they use to log in — not the public contact email they chose to share. Because every handle is already public and listable, someone could quietly collect every professional's private login email over time. The fix is to stop handing that email to the visitor's browser at all and instead have the backend deal directly with the sign-in service.
    - **Evidence:**
        ```php
        $email = User::query()
            ->where('handle_lc', mb_strtolower($input))
            ->whereNull('deleted_at')
            ->value('primary_email');

        // ...
        return $this->success([
            'email' => $email ? mb_strtolower((string) $email) : null,
        ]);
        ```

- [x] **#PGR-6** · P1 — Three pool-mutation endpoints reproduce the pre-2026-08-14 stale-origin-cache bug
    - **Resolution (2026-08-18, `audit-fix/programme-review-p1-2026-08-17`):** DONE for the four controller call sites; the `bumpSite()` half is **WONTFIX — premise disproved** (owner ruling, 2026-08-18).
        - *The controllers:* `PoolItemCreateController::pin()`, `ItemController::destroy()` and `ItemLinkController::upsert()/destroy()` were confirmed still two-lane on `293aff38b` and now fire all three. `PoolController::poolChanged()` and `ProvisionShopPinsCommand::invalidate()` were collapsed onto the same seam, so no hand-rolled copy of the contract remains in the pool lane.
        - *No helper was extracted:* the finding asked for one, but `App\Site\Documents\SiteCacheLanes::bust()` (`app/Site/Documents/SiteCacheLanes.php:35`) already existed and was already used by 8 call sites. The work was **adoption, not extraction**.
        - *Why `bumpSite()` is WONTFIX:* the finding's rationale — "the method every connector/manual-write path funnels through" — is false. `grep -rn "bumpSite(" app/` returns exactly **one** caller (`ProjectionWriter.php:422`, `writeManualItem()`); the second hit is a comment in `PruneOrphanedReviewPiiCommand`. The connector seam is `projectStream()` → `invalidateSiteLanes()` (`:258` → `:1688-1694`), which already fires all three lanes (shipped `30240ce19`). `bumpSite()` is a **per-item primitive** whose batch callers (`MenuScanApplier`, `ShopContentWriter::syncProducts`, the backfillers) invoke `writeManualItem()` once per row — a 318-dish menu scan would issue 318 `UPDATE site.sites` and 318 purge dispatches where one of each is correct, and would turn `ProjectionWriterBatchingTest`'s ≤140-query budget red. Its own docblock stated this design deliberately. The owner ruling is satisfied **at the request boundary**, which is where a lane belongs; `bumpSite()` now carries a docblock note recording the ruling and why the split survives it. Behaviour unchanged — the diff on that file is comments only.
        - *Scope found and deferred:* three further owner-initiated lane-1-only paths the finding missed are filed as **#PGR-36** (P2).
        - *Guards:* `tests/Feature/Content/PoolCacheLanesTest.php` extended with hand-add (exact **+2** delta — one bump from `writeManualItem()`, one from `pin()`), item delete, link upsert and link delete; the pre-existing reorder case hardened off `toBeGreaterThan()` to an exact delta. New `tests/Feature/Architecture/PoolCacheLaneSeamTest.php` pins the five files to the seam. `CLAUDE.md` corrected — it wrongly said `bumpSite()` "fires only lanes 1+3"; it fires **lane 1 only**.
    - **Source:** wire-resources — was `#TEST-1`. Independently re-verified in code by the review gate (this was its finding R-10) and **upgraded from a question to a confirmed defect by owner ruling, 2026-08-17.**
    - **Verified, not taken from the draft.** Read directly: `PoolController::poolChanged()` (`:226-233`) fires all three lanes; `PoolItemCreateController::pin()` (`:246-249`) fires lanes 1+3 only; `ProjectionWriter::bumpSite()` (`:1672-1679`) calls `BuildState::bump()` and nothing else. `grep -c "site.sites')"` returns **0** for `PoolItemCreateController`, `ItemController` and `ItemLinkController`. Lane 2 is the load-bearing one — the 60s public-profile payload cache key is composed from `site.sites.updated_at`, so the CDN is correctly purged while the ORIGIN keeps re-serving its own stale payload behind it.
    - **The split the original finding did not make, which changes the fix.** `store()` (hand-add → `writeManualItem()` → `bumpSite()`) is NOT a regression: spec §12.6 explicitly accepted it — *"60s public-profile payload cache — Not busted. A content write does not move `site.sites.updated_at`… TTL-bounded staleness only (default 60s)."* `pin()`, `ItemController::destroy()` and `ItemLinkController::upsert()/destroy()` ARE, because they are owner-initiated mutations inconsistent with a sibling in the same controller family that was deliberately fixed on 2026-08-14, and §17.2 shows the owner-write convention is the opposite (`ManualServiceWriter::invalidate()` DOES touch `site.sites.updated_at`).
    - **OWNER RULING (2026-08-17): 60s TTL-bounded staleness is NOT acceptable for owner-initiated pool mutations. All three lanes are required.** That settles the §12.6-vs-2026-08-14 contradiction in favour of three lanes, so `bumpSite()` is in scope for this fix too, not just the three controllers. CLAUDE.md now records the three-lane contract and names this as a known open defect.
    - Note `1c58f570a` / `49cd4f3c8` (sell-opt-in) landed `store()`/`pin()` on 2026-08-17 **without carrying the lane-2 fix forward**.
    - **Where:** `app/Http/Controllers/Api/Content/PoolItemCreateController.php` (`pin()`), `app/Http/Controllers/Api/Content/ItemController.php` (`destroy()`), `app/Http/Controllers/Api/Content/ItemLinkController.php` (`upsert()`, `destroy()`), `app/Ingest/Projection/ProjectionWriter.php` (`bumpSite()`, used by `writeManualItem()`)
    - **Affects:** Public sitepage visitors — hand-adding a pool item, deleting a library item, or saving/removing an item link bumps the build state and purges the CDN edge, but the Laravel origin keeps re-serving the old public payload from its own cache (keyed on `site.sites.updated_at`) until that cache's TTL expires.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `PoolController::poolChanged()`'s three-lane contract (`BuildState::bump()` + `DB::table('site.sites')->update(['updated_at' => now()])` + conditional `CloudflareCachePurgeJob::dispatch()`) into one shared helper and call it from `PoolItemCreateController::pin()`, `ItemController::destroy()`, and `ItemLinkController::upsert()`/`destroy()`.
        - Also add the `site.sites.updated_at` write to `ProjectionWriter::bumpSite()` (confirmed: it currently only calls `BuildState::bump()`) — this is the method every connector/manual-write path funnels through, including `PoolItemCreateController::store()`'s initial `writeManualItem()` call, so fixing it there closes the gap for the whole projection-write surface, not just the four call sites with a matching `poolChanged()`-style block.
        - Extend `tests/Feature/Content/PoolCacheLanesTest.php` — which already exists and already pins this exact three-lane contract for `PUT /pools/{pool}/order` (added for the 2026-08-14 regression) — with sibling cases for hand-add (`POST /pools/{pool}/items`), item delete (`DELETE /content/items/{item}`), and link upsert/delete (`PUT`/`DELETE /content/items/{item}/links/{platform}`), asserting `site.sites.updated_at` changes on each.
    - **Technical:** `PoolController::poolChanged()`'s docblock documents the incident directly: lane 2 (`site.sites.updated_at`, which the public payload cache key derives from) was missing until 2026-08-14, so a mutation bumped the build state and purged the CDN while the origin kept serving its own stale cached response. Verified via `Grep`/`Read` that neither `ProjectionWriter::bumpSite()` (only calls `BuildState::bump()`) nor `ManualPoolWriter::markRemoved()` (inherited by `ManualServiceWriter`, only touches `content.items.updated_at`) perform the `site.sites.updated_at` write — so `PoolItemCreateController::pin()`, `ItemController::destroy()`, and both `ItemLinkController` mutation actions reproduce the exact two-lane pattern the 2026-08-14 fix replaced. `tests/Feature/Content/PoolCacheLanesTest.php` confirms this by omission: it asserts all three lanes fire for reorder, but no equivalent test exists for the other four write paths. The recent `feat(shop): store-first product add on the pool lane` (1c58f570a) and `feat(shop)!: Sell goes opt-in` (49cd4f3c8) commits landed `PoolItemCreateController::store()`/`pin()` on 2026-08-17 without carrying the lane-2 fix forward.
    - **Plain English:** Think of three copies of a page: the draft, the published version, and a cached copy sitting at the front door for visitors. The August 14 fix made reordering clear all three copies. Adding an item by hand, deleting one, or changing its linked platforms only clears two — the published version keeps showing the old content to visitors until its own timer runs out, even though the front-door cache was correctly cleared.
    - **Evidence:**
        ```php
        // PoolController::poolChanged() — the fixed three-lane contract:
        private function poolChanged(Site $site): void
        {
            BuildState::bump((string) $site->id);
            DB::connection('pgsql')->table('site.sites')->where('id', $site->id)->update(['updated_at' => now()]);
            if ($site->subdomain !== '') {
                CloudflareCachePurgeJob::dispatch($site->subdomain);
            }
        }

        // PoolItemCreateController::pin() — same class of mutation, missing the middle write:
        BuildState::bump((string) $site->id);
        if ($site->subdomain !== '') {
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }

        // ProjectionWriter::bumpSite() — funnel point for writeManualItem() and every connector write:
        private function bumpSite(string $userId): void
        {
            $siteId = DB::table('site.sites')->where('user_id', $userId)->value('id');
            if ($siteId !== null) {
                BuildState::bump((string) $siteId);
            }
        }
        ```


## P2 — Should fix

- [ ] **#PGR-7** · P2 — No test proves `ProjectionWriter::upsertSourceItem()`'s double-click race actually resolves cleanly
    - **Source:** ingest-projection — was `#TEST-1`
    - **Where:** app/Ingest/Projection/ProjectionWriter.php (`upsertSourceItem`), app/Http/Controllers/Api/Content/PoolItemCreateController.php:111
    - **Affects:** The "Add" button on `POST /api/content/pools/{pool}/items`. Two near-simultaneous requests for the same URL (a double-click, or a retried request after a slow response) derive the identical coord (`'manual:'.sha1(strtolower(trim($url)))`) and race on `content.source_items`'s `(source_id, coord)` unique index.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Postgres-lane concurrency test (`tests/Postgres/`, following the fork or deterministic-injection idiom already used in `ProjectionIdentityKeyAtomicityTest.php` and `EffectLedgerConcurrencyTest.php`) that fires two concurrent `writeManualItem()` calls with the same user/coord and asserts: exactly one `content.source_items` row exists for that coord, both calls return the same resolved item id, and neither raises an uncaught `23505`.
        - Keep the DB layer real (Postgres lane, factory-seeded) — the SQLite mirror has no independently-committing second connection and cannot exercise the actual unique-constraint race, per the project's own documented PG-lane convention.
    - **Technical:** `upsertSourceItem()` does a SELECT, and on a miss falls through to `insertOrIgnore` + a re-read — deliberately non-atomic with the SELECT above it, per the method's own comment block. `tests/Postgres/ProjectionIdentityKeyAtomicityTest.php` proves a *different* invariant (a concurrent reader never observes a source item with zero identity keys mid-transaction) but does not drive two callers through `upsertSourceItem()` with the *same* coord to prove the `insertOrIgnore`+re-read fallback actually resolves the loser correctly rather than surfacing the `23505` PoolItemCreateController's own docblock says it used to. This is exactly the kind of regression the project's Postgres-lane concurrency tests exist to catch (see `EffectLedgerConcurrencyTest.php`, `SourceSchedulerConcurrencyTest.php`) and the pattern is not yet applied here.
    - **Plain English:** If someone double-clicks "Add link" on their profile, the system is supposed to treat it as one add, not two. The code has a safety catch for that already, but nothing currently proves the catch actually works under a real double-click — only that a *different*, related race is handled. A regression here would show up as an occasional 500 error on the add-link button.
    - **Evidence:**
        ```php
        // insertOrIgnore + re-read, not insert: the SELECT above and this write
        // are not atomic, and source_items_coord_unique (source_id, coord)
        // turns the loser of that race into a 23505. Unreachable while every
        // coord was minted per-run, but PoolItemCreateController now derives
        // the coord from the URL, so a double-clicked "Add" is two concurrent
        // requests writing the SAME coord — the loser would have surfaced as a
        // 500. Same reasoning as ensureManualSource().
        $id = (string) Str::uuid();
        DB::table('content.source_items')->insertOrIgnore([
        ```
        ```php
        $coord = 'manual:'.sha1(strtolower(trim($data['url'])));
        ```

- [ ] **#PGR-8** · P2 — `BackfillContentItemSlugs` uses `cursor()` on pgsql and an N+1 existence check per item
    - **Source:** migration-commands — was `#MIG-3`
    - **Where:** app/Console/Commands/BackfillContentItemSlugs.php
    - **Affects:** The one-off content slug backfill; command memory, open result-set duration, and runtime against a large `content.items` table.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `cursor()` with `chunkById()` — the pattern this codebase already documents and uses in `IngestProjectCommand` specifically because `pdo_pgsql` has no unbuffered fetch mode.
        - Preload `content.item_slugs` for each chunk in one query and check membership from that array instead of an `exists()` query per item.
    - **Technical:** `IngestProjectCommand.php` states this exact rule verbatim: *"chunkById(id), NOT ->get()/cursor(): pdo_pgsql has no unbuffered fetch mode, so cursor() would still buffer the whole result set in libpq while pinning one open result set for the entire multi-hour run."* `BackfillContentItemSlugs` violates that documented codebase invariant, plus issues a separate `content.item_slugs ... exists()` round trip per row. For a large `content.items` population this is materially slower and holds one open result set for longer than necessary, though as a one-off backfill (not a hot path) the urgency is moderate.
    - **Plain English:** The script asks the warehouse for the entire shelf of boxes before starting, then walks back to the front desk to check one binder page for every individual box — one slow check per box, plus a full-shelf load held open the whole time. Better to bring one pallet at a time and check the binder once per pallet.
    - **Evidence:**
        ```php
        ->cursor()
        ->each(function (object $item) use ($allocator, $dry, &$minted, &$skipped, &$failed) {
            $userId = (string) $item->user_id;
            $itemId = (string) $item->id;
        ```
        ```php
        $hasCurrent = DB::connection('pgsql')->table('content.item_slugs')
            ->where('user_id', $userId)->where('item_id', $itemId)
            ->where('is_current', true)->exists();
        ```

- [ ] **#PGR-9** · P2 — `BackfillMediaPaletteCommand` streams an unbounded media backlog via `cursor()` with inline per-row R2/image work
    - **Source:** migration-commands — was `#MIG-4`
    - **Where:** app/Console/Commands/BackfillMediaPaletteCommand.php
    - **Affects:** Existing gallery images needing palette extraction; R2 read load, temp disk, and image-decode CPU on the operator running the one-off.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Use `chunkById()` rather than `cursor()` on pgsql, matching the pattern this codebase documents in `IngestProjectCommand` (see #MIG-3).
        - For a large backlog, dispatch per-chunk queued jobs with `$backoff` instead of doing the R2 stream + decode inline in the console process, so one slow/failing image doesn't stall the whole sweep.
    - **Technical:** The query uses `->cursor()`, which — per this codebase's own documented understanding — still buffers the result set under `pdo_pgsql`, while each row does a network stream from R2, a temp-file copy, and an image decode inline. The command's own comment already correctly documents that `$timeout` is not read by `Illuminate\Console\Command` and treats `--limit` as the real mitigation, so that half of DeepSeek's original finding is already handled by design and is dropped here — the actionable gap is the `cursor()`/inline-work combination on a backlog with no natural size bound.
    - **Plain English:** The command tries to re-paint every photo in the archive in one sitting, holding the whole shelf's worth of boxes open while it works through them one at a time. The safer design is to process a tray of photos at a time.
    - **Evidence:**
        ```php
        foreach ($query->cursor() as $media) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
            $processed++;

            try {
                $palette = $this->extractForRow($extractor, $disk, (string) $media->path);
        ```

- [ ] **#PGR-10** · P2 — `BorrowedAssetPruner` materializes every doomed asset ID before chunking the deletes
    - **Source:** migration-commands — was `#MIG-5`
    - **Where:** app/Services/Migration/BorrowedAssetPruner.php
    - **Affects:** `content.media_assets` growth and the prune command's memory footprint as borrowed assets accumulate.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `pluck('id')->all()` with a chunked query over the same expired/unreferenced predicate, deleting up to `CHUNK` rows per iteration.
        - Add an optional `--limit` and progress logging so the operator can bound and watch the run.
    - **Technical:** The pruner computes `$doomed` by materializing every candidate UUID into a PHP array before the already-chunked delete loop runs. Since borrowed assets grow by ten per place per Google sync until this cleaner runs, the array size is unbounded even though the delete half is chunked. UUID lists are cheap in absolute terms, so this is a hardening item rather than an urgent one, but it's a straightforward fix to make the whole method streaming.
    - **Plain English:** The tool writes every overdue account number on a giant whiteboard before it starts calling customers. The board gets bigger forever, even though the calling is already done in batches of 500 — better to keep a single page of 500 numbers at a time.
    - **Evidence:**
        ```php
        $doomed = (clone $expired)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('content.item_media')
                ->whereColumn('content.item_media.asset_id', 'content.media_assets.id'))
            ->pluck('id')
            ->all();

        $result['pruned'] = count($doomed);

        if ($dryRun || $doomed === []) {
            return $result;
        }

        foreach (array_chunk($doomed, self::CHUNK) as $slice) {
            DB::connection('pgsql')->table('content.media_assets')->whereIn('id', $slice)->delete();
        }
        ```

- [ ] **#PGR-11** · P2 — `ConvergeSiteSubdomainsCommand` commits the raw subdomain rename before cache and KV invalidation
    - **Source:** migration-commands — was `#MIG-6`
    - **Where:** app/Console/Commands/ConvergeSiteSubdomainsCommand.php
    - **Affects:** `site.sites.subdomain` convergence and the Redis/Cloudflare KV routing state for affected users during the repair.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Resolve all cache keys and the KV alias set **before** the DB update (the command already does this today for logging purposes — `cacheKeysFor()`/`kvPlanFor()` run before the write), then perform the update in a transaction and dispatch invalidation after commit.
        - If a cache/KV step fails, `report()` loudly and leave the row flagged for retry rather than silently reporting "written."
    - **Technical:** `DB::connection('pgsql')->table('site.sites')->update([...])` commits immediately, then `Cache::deleteMultiple($keys)` and the conditional `SyncSubdomainToKvJob::dispatch()` run separately with no transaction wrapping the three. A failure after the update leaves the canonical subdomain moved while stale cache/KV entries persist. This is the same ordering pattern as #MIG-2, and the fix is the same shape. Severity is capped for now by the command's own documented scope: it is explicitly a pre-beta repair tool for drift accumulated before the allocator fix, and the class docblock notes prod currently has zero users (`core.users = 0`), so today's blast radius on prod is effectively nil. On dev, where real accounts exist, a stale-cache window is real but self-healing (bounded by TTL), and this command is run under `--apply` confirmation, not automatically.
    - **Plain English:** The repair changes the store name in the master ledger, then tells the sign-makers. If the sign company doesn't get the message, the ledger says "new name" while customers are still routed by the old sign for a while. Right now there's no live traffic in production to be misrouted, but the same gap would matter once there is.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->table('site.sites')
            ->where('id', $row->site_id)
            ->update(['subdomain' => $new, 'updated_at' => now()]);

        Cache::deleteMultiple($keys);

        if ($kvUsers !== []) {
            SyncSubdomainToKvJob::dispatch((string) $row->user_id);
        }
        ```

- [ ] **#PGR-12** · P2 — Enquiry notification reconciliation's `SKIP LOCKED` concurrency contract has no Postgres-lane test, unlike the analogous claim-vs-prune race
    - **Source:** migration-commands — was `#TEST-3`
    - **Where:** app/Console/Commands/ReconcileEnquiryNotifications.php (transaction with `lock('for update skip locked')`)
    - **Affects:** Enquiry notification recovery when two scheduler ticks or servers overlap; a regression could double-handle or block under concurrency.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `tests/Postgres/` concurrency test asserting two overlapping reconciles take disjoint enquiry slices under `SKIP LOCKED`, following the shape already shipped for the identical pattern in `PreAccountBuildService`'s claim-vs-prune race (`tests/Postgres/ClaimConcurrencyTest.php`) and the ingest source scheduler (`tests/Postgres/SourceSchedulerConcurrencyTest.php`).
        - If a real-Postgres run is impractical for this command specifically, at minimum add a schema-lane assertion that the exact `lock('for update skip locked')` clause is present.
    - **Technical:** The command's own comment states the SQLite lane cannot exercise this guarantee and explicitly cites `PreAccountBuildService`'s claim-vs-prune pattern as the precedent it mirrors — but that precedent now has a shipped Postgres-lane concurrency test (`ClaimConcurrencyTest.php`), while this command's own equivalent does not. Given the repo already has the `tests/Postgres/` infrastructure and two working examples of this exact test shape, closing this gap is a matter of following an established pattern rather than building new test infrastructure.
    - **Plain English:** The system is designed so two janitors cleaning the same list don't grab the same ticket. A sibling system that works the same way already has a rehearsal for this; this one still doesn't, even though the same rehearsal room is available.
    - **Evidence:**
        ```php
        // SKIP LOCKED so an overlapping run (or a second server) takes a
        // different slice rather than blocking — mirrors the claim-vs-prune
        // pattern in PreAccountBuildService. SQLite ignores the lock clause
        // (Feature suite unaffected); the Postgres behavior is the contract.
        ```

- [ ] **#PGR-13** · P2 — Dashboard link cards read `favicon`/`logo` as `null` even though the writer now populates them
    - **Source:** pools-resolver — was `#API-1`
    - **Where:** app/Services/Content/LinkPoolReader.php:66-76 (`cardsInSection()`)
    - **Affects:** The owner's own link-management screen (`LinkPoolReader::cards()`/`cardsForSite()`), which now silently disagrees with what the public sitepage shows for the same item.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Join `content.item_media`/`content.media_assets` for roles `cover` (logo) and `logo` (favicon) in `cardsInSection()`, resolving through `MediaUrlResolver` the same way `PoolResolver::cover()`/`favicon()` do, and return real values instead of hardcoded `null`.
        - Update `LinkPoolReader`'s class docblock, which still says "favicon and logo are always null … that decision is unchanged; see LinkPoolWriter's docblock" — `LinkPoolWriter`'s own docblock was updated the same day to say the opposite ("favicon and logo ARE carried (2026-08-17, reversing Phase 3's 'not carried')"). The two docblocks now contradict each other.
    - **Technical:** `LinkPoolWriter::add()` was changed on 2026-08-17 to write `cover`/`logo`-role `content.item_media` rows for every new or updated link (og:image and site favicon), and `PoolResolver::itemPayloads()` already reads those roles back as `thumbnail`/`favicon` on the public payload. `LinkPoolReader::cardsInSection()` — the dashboard's own read of the identical `custom_links` pool — was not updated to match: it still hardcodes both fields to `null` and cites a stale cross-reference claiming the writer's behavior is "unchanged." Because this is a same-day change to one half of a read/write pair, every link added or edited from today onward will show its image correctly on the public page but blank in the dashboard.
    - **Plain English:** The team just taught the system to save a link's icon and preview image. The public page picks that up correctly, but the dashboard screen where the owner manages their links was never told about the change, so it still shows blank image boxes for every link — even brand-new ones that do have images. It's two mirrors of the same shelf, and only one was dusted.
    - **Evidence:**
        ```php
        // LinkPoolReader::cardsInSection()
        ->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'url' => $row->url,
            'name' => $row->headline_cache,
            'description' => $row->body,
            // See the class docblock — deliberately not carried onto the pool.
            'favicon' => null,
            'logo' => null,
        ])
        ```

- [ ] **#PGR-14** · P2 — `ServiceCollections`/`MenuCollections` PDO_PGSQL scalar normalisation has no unit test that feeds it driver-shaped input
    - **Source:** pools-resolver — was `#TEST-2`
    - **Where:** app/Services/Content/ServiceCollections.php (`normalizeRow()`); app/Services/Content/MenuCollections.php (`normalizeRow()`)
    - **Affects:** Dashboard service/menu category reads on real Postgres — the editable-category gate keys off `is_user_created === false`, and the type coercion that makes that comparison safe is currently exercised only by SQLite, which already returns native types.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a unit test that constructs a raw `\stdClass` row with `'t'`/`'f'` string booleans and numeric-string ints (the literal shape PDO_PGSQL returns) and asserts `normalizeRow()` produces native `bool`/`int`.
        - Mirror the same test for `MenuCollections::normalizeRow()`.
    - **Technical:** Both classes' own docblocks explain, at length, that PDO_PGSQL returns a boolean column as `"t"`/`"f"` and a bigint/integer column as a numeric string, and that the normalisation exists specifically because a caller compares `is_user_created === false`. That normalisation code is correct today, but nothing in the test suite passes it a raw driver-shaped value — SQLite already hands back native PHP types, so the coercion path is silently untested. A future refactor of either `normalizeRow()` could regress the coercion and stay green on `composer test` while misclassifying scraper-owned categories as owner-editable on production Postgres.
    - **Plain English:** The code has a translator that converts the database's "yes/no" and "count" answers into the true/false and whole numbers the app expects. That translator is correct right now, but the test suite never actually hands it the tricky input it was built to handle — so if someone breaks the translator later, all the tests still pass and the mistake only shows up in the real, live database, potentially letting the dashboard treat someone else's menu category as if the owner created it.
    - **Evidence:**
        ```php
        // ServiceCollections::normalizeRow() docblock
        // ... PDO_PGSQL returns a boolean column as the strings "t"/"f",
        // not PHP bool, so a caller (Task 9's wire mapping is written against
        // `is_user_created === false`) would silently misclassify every
        // Fresha-derived category as user-created on real Postgres, invisibly
        // to the SQLite test lane.
        ```

- [ ] **#PGR-15** · P2 — No query-surface guard for the three menu DTO models, asymmetric with the services one
    - **Source:** programme-review (R-4) — was `#R-4`
    - **Where:** app/Models/Core/Site/Menu.php:121 (`categories()`), :127 (`items()`); app/Models/Core/Site/MenuItem.php; app/Models/Core/Site/MenuCategory.php; app/Models/Core/Site/MenuItemPlatform.php; tests/Feature/Architecture/LegacyServiceQuerySurfaceTest.php
    - **Affects:** Any future code that queries or eager-loads the three menu DTO models. A regression is a guaranteed 42P01 on real Postgres that the SQLite lane cannot catch, on a nightly-reachable path.
    - **Effort:** S (~0.5-1h)
    - **What to do:**
        - Extend `LegacyServiceQuerySurfaceTest` (or add a sibling) to cover `MenuItem`, `MenuCategory` and `MenuItemPlatform` with the same two cases it already applies to `Service`/`ServiceCategory`: the string sweep for `::query(`/`::where`/`::find`/`::withTrashed`, and the dynamic-list case asserting they never enter a list a query loop iterates.
        - Keep the positive control the existing test has (assert they ARE accounted for in `PURGE_EXEMPT`), so the negated assertions cannot go vacuous.
        - Decide `Menu::categories()` / `Menu::items()`: either delete the two relation methods, or annotate them as unusable-by-construction. `site.menus` SURVIVES, so the model is live and the relations are reachable.
    - **Technical:** Slice 7 dropped `site.menu_items`, `site.menu_categories`, `site.menu_item_categories` and `site.menu_item_platforms` while deliberately KEEPING `MenuItem`, `MenuCategory` and `MenuItemPlatform` as unsaved DTO carriers for `ManualMenuItems` — the correct decision, and the same shape as `Service`/`ServiceCategory`. One day later the services cutover hit the matching hazard for real (`PurgeSoftDeleted` listed both table-less models in `PURGE_HANDLED`, so the nightly 03:20 command queried a dropped relation; fixed in `d8beab929`) and built `LegacyServiceQuerySurfaceTest` to stop it recurring. That guard names ONLY `Service` and `ServiceCategory`. Verified there is no live query surface on the three menu models today — they appear solely as `new MenuItem` / `new MenuCategory` / `new MenuItemPlatform` in `ManualMenuItems`, exactly as the slice-7 checkpoint claims — so this is a latent trap with no guard rather than a live defect. `Menu` itself is in `PURGE_HANDLED` (correctly; its table survives), which is what keeps the models reachable from a dynamic query loop.
    - **Plain English:** Four menu tables were deleted, but their PHP classes were deliberately kept because the dashboard still uses them as empty shells to shape data. That's fine — until someone writes ordinary-looking code that asks one of those classes to fetch from the database, which no longer exists. The team already hit exactly this with the services classes and built a tripwire to catch it. The tripwire only covers the services ones; the menu ones have none.
    - **Evidence:**
        ```php
        // Menu.php — live model on a SURVIVING table, with relations to DROPPED ones
        public function categories(): HasMany
        {
            return $this->hasMany(MenuCategory::class, 'menu_id')->orderBy('position');
        }
        public function items(): HasMany
        {
            return $this->hasMany(MenuItem::class, 'menu_id');
        }
        ```
        ```php
        // LegacyServiceQuerySurfaceTest — the existing guard, services only
        'Service::query(', 'Service::where', 'Service::find', 'Service::withTrashed',
        'ServiceCategory::query(', 'ServiceCategory::where', ...
        ```

- [ ] **#PGR-16** · P2 — Email subscription lookup ignores the existing indexed `email_lc` column
    - **Source:** public-surface-security — was `#SCHEMA-1`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php:114-118
    - **Affects:** Public newsletter/marketing subscription path (`notifications.email_subscriptions`); slower lookups as the table grows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->whereRaw('lower(email) = ?', [$email])` with `->where('email_lc', $email)` — `$email` is already lower-cased two lines earlier.
        - Do **not** apply this to `PublicCustomerLeadController` or `PublicEnquiryController`'s `Customer::whereRaw('lower(email)...)` calls — verified `site.customers` (baseline migration line 1536) carries no `email_lc` column, so `whereRaw('lower(email)...)` is already the only available approach there; DeepSeek's draft incorrectly extended the recommendation to those two call sites.
    - **Technical:** Verified `notifications.email_subscriptions.email_lc` exists with two matching indexes — `email_subscriptions_unique_pro_list_email_lc` on `(user_id, list_key, email_lc)` and `..._unique_global_list_email_lc` on `(list_key, email_lc)` (baseline migration lines 3047/3051) — and the controller itself writes `$subscription->email_lc = $email;` a few lines below the flagged query, so the normalized column is already populated. The query just isn't using it, so it can't hit either index.
    - **Plain English:** There's already a fast lookup column and index built specifically for finding subscriptions by lowercase email, but this one query ignores it and does the lowercasing itself every time, which the database can't optimize the same way. Pointing it at the existing column makes it fast for free.
    - **Evidence:**
        ```php
        $subscription = EmailSubscription::query()
            ->where('user_id', $site->user_id)
            ->where('list_key', $listKey)
            ->whereRaw('lower(email) = ?', [$email])
            ->first();
        ```
        ```php
        $subscription->email_lc = $email;
        ```

- [ ] **#PGR-17** · P2 — Backend alias 301s cache for 5 minutes, which can briefly misdirect a visitor after a rapid handle reclaim
    - **Source:** public-surface-security — was `#EDGE-1`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicSiteController.php (`show()` and `showByHeader()` alias redirect blocks)
    - **Affects:** Visitors who followed an old subdomain alias shortly before that handle was reclaimed by someone else.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change both alias 301 responses from `public, max-age=300` to `private, max-age=0, must-revalidate` (or `no-store`).
        - Confirm the Worker's own alias-301 path (if any) follows the same contract.
    - **Technical:** The `Cache-Control: public, max-age=300` header is itself the surviving fix from a prior audit pass — the code comment cites it directly ("EDGE-1/CFG-3 (audit): explicit Cache-Control so browsers re-check after the TTL instead of heuristically caching this 301 'forever'"). That earlier fix correctly bounded an *unbounded* caching problem; this finding is a further tightening of an already-mitigated control, not a new gap — a browser that cached the 301 can still serve a stale target for up to 5 minutes if the handle is reclaimed in that window, which the `reclaim_until`/`expires_at` handle lifecycle makes a real, if narrow, scenario.
    - **Plain English:** When an old address is redirected to a new one, browsers are told to remember that redirect for up to 5 minutes. If the old address gets handed to a different professional during that window, a visitor's browser might still send them to the wrong page until it re-checks. Telling browsers to always re-check closes that narrow gap.
    - **Evidence:**
        ```php
        return redirect()->to($url, 301)
            ->header('Cache-Control', 'public, max-age='.(int) config('partna.cache.alias_redirect_max_age', 300));
        ```

- [x] **#PGR-18** · P2 — Raw UTM parameters bypass the analytics sanitizer that already protects referrer data
    - **Source:** public-surface-security — was `#PRIV-1`
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php (`buildEvent`)
    - **Affects:** Every public sitepage visitor whose analytics beacon includes UTM parameters; marketing-campaign tags can embed identifying strings into analytics stores.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Run `utm_source`, `utm_medium`, and `utm_campaign` through the same length-cap/sanitization discipline applied to `referrer`.
        - Add a test asserting a UTM value containing an email-like string is neutralised before reaching the event payload.
    - **Technical:** Verified `buildEvent()` sanitizes `referrer` via `AnalyticsEventSanitizer::referrer($referrer)` but passes `utmSource`/`utmMedium`/`utmCampaign` straight from `$data` with no processing. These are arbitrary third-party-supplied query values that can carry names, emails, or other identifiers appended by a marketer's link — sanitizing the URL but not the extracted campaign fields leaves equivalent PII exposure in a sibling field of the same event.
    - **Plain English:** A marketing link can have a person's name or email typed right into its tracking label. The system already blurs the web address itself, but currently copies that tracking label through untouched — so the same kind of information can sneak in through a side door.
    - **Evidence:**
        ```php
        referrer: AnalyticsEventSanitizer::referrer($referrer),
        utmSource: $data['utm_source'] ?? null,
        utmMedium: $data['utm_medium'] ?? null,
        utmCampaign: $data['utm_campaign'] ?? null,
        ```

- [x] **#PGR-19** · P2 — Three public form paths persist User-Agent without the codebase's own PII-minimizing sanitizer
    - **Source:** public-surface-security — was `#PRIV-2`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php (`subscribe`); app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php (`submit`); app/Http/Controllers/Api/PublicSite/PublicEarlyAccessController.php (`store`)
    - **Affects:** Visitors who submit email subscriptions, enquiries, or early-access signups; their raw browser fingerprint enters consent/submission records.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route all three assignments through `AnalyticsEventSanitizer::userAgent($request->userAgent())`, matching the pattern already applied in `PublicCustomerLeadController::logLead()`/`upsertMarketingSubscription()` (see the `PRIV-2` comment already in that file).
        - `PublicEmailSubscriptionController` is the most urgent of the three — its UA is stored fully raw and uncapped, whereas the other two already cap length at 500 chars (partial mitigation, but not reduced to the canonical `Family/Version` form).
    - **Technical:** `AnalyticsEventSanitizer::userAgent()` already exists and reduces a raw UA string to a coarse `Family/MajorVersion` token (e.g. `Chrome/120`) — verified in `app/Services/Analytics/AnalyticsEventSanitizer.php:69-82`, and already used correctly by `PublicCustomerLeadController`. These three sibling call sites were missed: `PublicEmailSubscriptionController` stores `$request->userAgent()` verbatim with no cap at all; `PublicEnquiryController` and `PublicEarlyAccessController` cap length to 500 chars via `mb_substr` but still store the full raw string, not the sanitized family token.
    - **Plain English:** Three different sign-up forms write down a visitor's full, detailed browser fingerprint, while a nearby form already blurs that same information down to just "Chrome" or "Safari". It's like one office shredding a form while the office next door keeps the identical form in a drawer, because nobody told them about the shredder. Using the same blurring step everywhere closes that gap and also shrinks what has to be found and deleted for a privacy request.
    - **Evidence:**
        ```php
        'user_agent' => $request->userAgent(),
        ```
        ```php
        'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ```
        ```php
        'consent_user_agent' => mb_substr((string) ($request->userAgent() ?? ''), 0, 500) ?: null,
        ```

- [x] **#PGR-20** · P2 — Analytics ingest stores raw User-Agent verbatim on the highest-traffic public write path
    - **Source:** public-surface-security — was `#PRIV-3`
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php (`buildEvent`)
    - **Affects:** Every public sitepage visitor whose analytics beacon is ingested (pageview/click/section/item/action/ping events).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route `userAgent: $request->userAgent()` through `AnalyticsEventSanitizer::userAgent()` before it reaches the `AnalyticsEvent` DTO — the same helper `AnalyticsController::rum()` already uses two methods below in the same class.
    - **Technical:** Verified `buildEvent()` is the documented "single choke point" for request-derived analytics fields and already imports `AnalyticsEventSanitizer` (used for `referrer`, and separately for `rum()`'s UA field a few lines down in the same file) but passes `userAgent: $request->userAgent()` raw and unsanitized into every event type. Note on the accompanying `latitude`/`longitude` fields: these are Cloudflare IP-geolocation coordinates forwarded via `X-Visitor-Lat`/`X-Visitor-Lon` (`DetectsClientInfo.php:174-190`, explicitly documented as "best-effort demographics only, same trust level as `detectCity()`") — city/region-level accuracy, not device GPS. They are not as sensitive as DeepSeek's original "exact street corner" framing implied, but storing a raw coordinate pair is still finer-grained than the `city` string already collected for the same purpose, and expands the analytics-store PII/DSAR export surface for no product benefit.
    - **Plain English:** Every time someone visits a Partna page, the system writes down their exact browser and version in full detail, when a coarser label like "Chrome" would do the same job for site-owner stats. The location data is already coarse (roughly which town, from the visitor's internet connection, not GPS), so that part is less concerning than it first looks — but the detailed browser string is unnecessary and the codebase already has a tool built to blur it that this one call site skips.
    - **Evidence:**
        ```php
        userAgent: $request->userAgent(),
        // ...
        city: $this->detectCity($request),
        // ...
        latitude: $this->detectLatitude($request),
        longitude: $this->detectLongitude($request),
        ```

- [ ] **#PGR-36** · P2 — Three further owner-initiated write paths fire lane 1 only, the same defect class as #PGR-6
    - **Source:** found during the execution of #PGR-6, 2026-08-18 (`audit-fix/programme-review-p1-2026-08-17`) — not from a scan run. Owner ruled it a follow-up rather than folding it into #PGR-6, to keep that unit's diff tight and to avoid dragging `ItemMerger`'s concurrency story into a cache unit.
    - **Where:** `app/Http/Controllers/Api/Content/ManualOverrideController.php:107-112` (`bumpSites()`); `app/Services/Content/ItemMerger.php:368-373` (`bumpSites()`); `app/Http/Controllers/Api/Site/SectionItemController.php:103` (`upsert()`) and `:126` (`destroy()`)
    - **Affects:** Public sitepage visitors. Each is an owner-initiated curation write that bumps the build state and then stops — no `site.sites.updated_at`, no edge purge — so the origin re-serves its stale payload for the 60s TTL *and* the CDN is never purged at all. `ManualOverrideController` is the sharpest of the three: an override changes the rendered headline/body, so the visible text is what goes stale.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Route all four through `App\Site\Documents\SiteCacheLanes::bust()`, the seam #PGR-6 established.
        - Decide `SectionItemController` deliberately: it is the *other* pin path onto `site.section_items` (named as such at `PoolController.php:57`), so it should almost certainly match the pool lane. The site-builder controllers (`SectionController`, `SectionGroupController`, `PageController`) are also lane-1-only and may warrant a different judgement — settle whether the builder lane is in or out before writing code.
        - Add the five files to `POOL_CACHE_LANE_FILES` in `tests/Feature/Architecture/PoolCacheLaneSeamTest.php` (and bump its count assertion) so the guard covers them once they adopt the seam. That test's docblock currently names these three as deliberately excluded — update it.
    - **Technical:** Verified by reading each method on `293aff38b`: all three call `BuildState::bump()` in a loop over the user's sites and nothing else. `SectionItemController::destroy()` uses Eloquent `->delete()` on `SectionItem`, but there is no `SectionItemObserver`, so no observer discharges the other two lanes either. Distinct from `ShopController::bumpSiteCache()` (`:1266-1275`), which fires lanes 1+2 but deliberately not 3 because `IntegrationConnectionCacheRefresher` owns the edge purge on that lane — that one is **not** a defect and should not be swept in.
    - **Plain English:** The same "only cleared two of the three caches" bug that #PGR-6 fixed for adding, deleting and linking items is still present in three more places an owner can edit their page — including the one that changes the actual wording shown on the page. The fix is the same one-line swap onto the shared helper that #PGR-6 introduced.
    - **Evidence:**
        ```php
        // ManualOverrideController::bumpSites() — an override changes what the page SAYS
        private function bumpSites(User $user): void
        {
            foreach (DB::table('site.sites')->where('user_id', $user->id)->pluck('id') as $siteId) {
                BuildState::bump((string) $siteId);
            }
        }
        ```

## P3 — Nice to have

- [ ] **#PGR-21** · P3 — `ProjectionWriter::refreshItemCaches()` hardcodes its batch size while the write path is config-driven
    - **Source:** ingest-projection — was `#CFG-1`
    - **Where:** app/Ingest/Projection/ProjectionWriter.php (`refreshItemCaches`, `writeChunk`)
    - **Affects:** Operators/tests tuning `partna.ingest.projection_write_chunk` to exercise chunk-boundary behaviour — the dashboard-cache refresh batching stays fixed at 500 regardless.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `array_chunk($itemIds, self::BATCH_SIZE)` in `refreshItemCaches()` with `array_chunk($itemIds, $this->writeChunk())`, matching `replaceCollections()`.
        - If cache-refresh batching genuinely needs an independent knob, add a second config key rather than leaving one path hardcoded while its sibling is config-driven.
    - **Technical:** `replaceCollections()` reads `$this->writeChunk()` (`config('partna.ingest.projection_write_chunk', self::BATCH_SIZE)`), but `refreshItemCaches()` still chunks on the bare `self::BATCH_SIZE` constant. `IngestProjectRebuildChunkingTest.php` and `ProjectionWriterBatchingTest.php` already shrink `projection_write_chunk` to exercise chunk-boundary behaviour for the write path; that lever silently does not reach the cache-refresh path, so a test or operator relying on it to probe both boundaries only gets one. Low severity: bind lists stay far under Postgres' parameter limit at either value, and nothing observable breaks — this is a consistency gap, not a correctness bug.
    - **Plain English:** Two parts of the same process package data in batches. One batch size can be tuned with a setting; the other is welded at 500 no matter what the setting says. Turning the dial only changes half the machine.
    - **Evidence:**
        ```php
        private const BATCH_SIZE = 500;
        ...
        foreach (array_chunk($itemIds, self::BATCH_SIZE) as $batch) {
        ```
        ```php
        private function writeChunk(): int
        {
            $chunk = (int) config('partna.ingest.projection_write_chunk', self::BATCH_SIZE);

            return $chunk > 0 ? $chunk : self::BATCH_SIZE;
        }
        ```

- [ ] **#PGR-22** · P3 — `PruneExpiredFeatureFlagOverridesCommand` hard-deletes expired overrides with no dry-run or batching
    - **Source:** migration-commands — was `#MIG-7`
    - **Where:** app/Console/Commands/PruneExpiredFeatureFlagOverridesCommand.php
    - **Affects:** `core.feature_flag_overrides` table and any operational flag overrides that have expired.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `--dry-run` option mirroring the sibling prune commands (`PruneNotifications`, `PruneOldFeedbackSubmissionsCommand`, etc.).
        - Batch the delete with a bounded `limit()` loop so the table cannot force a long-running single statement as it grows.
    - **Technical:** The command runs a single hard `delete()` with no preview and no batch size. Every other prune command in this bundle follows a `--dry-run` + batched-delete convention; this one is the outlier. The table is almost certainly small today (operational flag overrides), so this is low urgency, but bringing it in line with the sibling commands' convention costs little.
    - **Plain English:** This is a "delete expired post-it notes" button with no "show me first" option. It probably only trashes a few notes now, but every other cleanup tool in this codebase lets the operator preview first — this one should too.
    - **Evidence:**
        ```php
        $deleted = FeatureFlagOverride::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
        ```

- [ ] **#PGR-23** · P3 — Pool library list is returned as one unbounded-feeling batch (up to 500 items) with no pagination envelope
    - **Source:** pools-resolver — was `#API-2`
    - **Where:** app/Site/Pools/PoolResolver.php (`resolve()`, `LIBRARY_LIMIT`)
    - **Affects:** The dashboard's pool page and any client reading the `library` array on a site with a large content library.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an explicit `limit`/`cursor` (or `page`/`per_page`) contract for the `library` array plus pagination metadata (`total`, `next_cursor`), rather than always hydrating and returning up to 500 fully-built item payloads.
    - **Technical:** `resolve()` fetches up to `LIBRARY_LIMIT = 500` item ids and fully hydrates every one of them (the same per-item hydration `selection` uses, including facet joins, media resolution, and popularity ranks) on every call — and `PoolResolver`'s own docblock states this is "live, no document cache" by design. There is no pagination envelope, so a client cannot request further pages and must accept the whole batch every time the pool page is read.
    - **Plain English:** The screen that shows an owner's full content library tries to hand over up to 500 fully-detailed items in one response, every time the page loads, with no "load more" mechanism. It works today because 500 is a hard ceiling, but it means every library-page view does more work than it needs to for an owner with a large collection.
    - **Evidence:**
        ```php
        private const LIBRARY_LIMIT = 500;
        // ...
        $libraryIds = DB::connection('pgsql')->table('content.items')
            ->where('user_id', $site->user_id)
            ->whereIn('kind', PoolRegistry::kinds($pool))
            ->whereNull('removed_at')
            ->orderByDesc('last_seen_at')
            ->limit(self::LIBRARY_LIMIT)
            ->pluck('id')
            ->all();
        ```

- [ ] **#PGR-24** · P3 — Candidate scan limit (200) is duplicated between `SectionCandidates` and `SectionTracer` instead of shared
    - **Source:** pools-resolver — was `#CFG-1`
    - **Where:** app/Site/Sections/SectionCandidates.php (`CANDIDATE_SCAN_LIMIT`); app/Services/Content/SectionTracer.php (`CANDIDATE_SCAN_LIMIT`)
    - **Affects:** Whether the "why is this on my page" trace endpoint stays in sync with what the live page actually scans, if the executor's bound ever changes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `SectionTracer::CANDIDATE_SCAN_LIMIT` with a direct reference to `SectionCandidates::CANDIDATE_SCAN_LIMIT`, the same way `SectionTracer::EXECUTED_OPERATORS` already delegates to `DocumentBuilder::EXECUTED_OPERATORS` a few lines below it in the same file.
    - **Technical:** `SectionCandidates` declares `public const CANDIDATE_SCAN_LIMIT = 200` as "the single source of truth"; `SectionTracer` independently declares `private const CANDIDATE_SCAN_LIMIT = 200`. Both values agree today, but `SectionTracer`'s own class docblock is a case study in exactly this failure mode already having bitten this file once: it explains at length how a private, un-synced copy of `EXECUTED_OPERATORS` drifted from the builder's list and made the trace lie about the live page, and it fixed that by delegating to the canonical constant. The scan limit was not given the same treatment.
    - **Plain English:** The number "200" — how many candidate items the system will look through — is written down twice in two different files, when the second file already has a working example, right next to it, of referencing the first file's copy instead of duplicating it. If someone tunes the real limit later and misses the second copy, the "why is this on my page" explainer will quietly start disagreeing with the real page.
    - **Evidence:**
        ```php
        // app/Site/Sections/SectionCandidates.php
        public const CANDIDATE_SCAN_LIMIT = 200;

        // app/Services/Content/SectionTracer.php
        private const CANDIDATE_SCAN_LIMIT = 200;
        ```

- [ ] **#PGR-25** · P3 — `ItemMerger::separate()`'s docblock still describes a `DisjointSet` bug that was fixed on 2026-07-29, and cites a test file that doesn't exist
    - **Source:** pools-resolver — was `#TEST-1`
    - **Where:** app/Services/Content/ItemMerger.php (`separate()` docblock); app/Content/Identity/DisjointSet.php
    - **Affects:** Any engineer reading `ItemMerger::separate()` to understand whether a "these are different" ruling is reliable — the comment currently tells them it isn't, when it now is.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update `ItemMerger::separate()`'s docblock to remove the "KNOWN GAP" note — `DisjointSet::separate()` was rewritten under "FU-1, settled 2026-07-29" to replay unions in canonical sorted order rather than re-rooting an argument, and `tests/Unit/Content/DisjointSetTest.php` now explicitly asserts the split works "regardless of which coord won the union" (`'splits the pair when the second argument is the group root'` and the sibling `'... is the non-root child'` cases).
        - Fix or remove the citation to `tests/Feature/Content/IdentityQueueTest.php`, which does not exist in the repository.
    - **Technical:** DeepSeek's draft (confidence 0.8) took `ItemMerger.php`'s in-repo docblock at face value and reported a live P1 correctness bug ("a `different` ruling currently fails to split about half the time"). That docblock is stale: `app/Content/Identity/DisjointSet.php` was rewritten to record separations as constraints and rebuild the grouping from canonical-order edge replay specifically to remove the union-order dependency the old docblock describes, and `tests/Unit/Content/DisjointSetTest.php` pins the *fixed* behaviour (`'applies the ruling by coordinate value, not by argument position'`), not the broken one. Separately, the "characterisation test" the docblock cites at `tests/Feature/Content/IdentityQueueTest.php` does not exist anywhere in the repo (`Glob` found nothing), so even the citation is wrong. The residual issue is documentation drift, not a live bug — worth a cheap fix so nobody re-diagnoses a phantom problem or avoids using the "different" ruling out of unwarranted caution, but not a correctness finding.
    - **Plain English:** A comment in the code still warns "marking two items as different sometimes silently fails" — but that bug was actually fixed several weeks ago, and the comment was never updated to say so. It also points to a test file that isn't there. Nobody's data is at risk; the risk is a developer reading the outdated warning, believing a working feature is broken, and wasting time chasing a bug that no longer exists.
    - **Evidence:**
        ```php
        // app/Content/Identity/DisjointSet.php
        * THE TIE-BREAK IS A RULING, NOT AN ACCIDENT (FU-1, settled 2026-07-29). When
        * a cut splits a group, an element joined to both sides could go either way.
        * It stays with the LEXICOGRAPHICALLY-SMALLER coordinate.

        // tests/Unit/Content/DisjointSetTest.php
        it('splits the pair when the second argument is the group root', function () {
            // The historical bug: separate() always re-rooted its SECOND argument, a
            // no-op whenever that argument already WAS the union root. ...
            // exactly the shape that used to silently fail to split.
        ```

- [ ] **#PGR-26** · P3 — PoolResolver hardcodes its operational limit and cache TTL instead of using config
    - **Source:** public-surface-security — was `#CFG-1`
    - **Where:** app/Site/Pools/PoolResolver.php
    - **Affects:** Pool page size and popularity-cache retention; tuning requires a code deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `LIBRARY_LIMIT` into `config('partna.pools.library_limit')`, default `500`.
        - Move `POPULARITY_CACHE_TTL_SECONDS` into `config('partna.cache.ttls.site_popularity_ranks')`, default `900`, and update the comment (which currently instructs any future second holder to "copy this value verbatim") to point at the config key instead.
    - **Technical:** Verified both are `private const` on the public pool hot path (`LIBRARY_LIMIT = 500`, `POPULARITY_CACHE_TTL_SECONDS = 900`). The class docblock itself documents a duplicated-config risk it can't otherwise guard against.
    - **Plain English:** Two operational numbers — how many products to load, and how long to trust popularity rankings — are baked into the code today, so changing either requires a developer to edit and redeploy instead of adjusting a setting.
    - **Evidence:**
        ```php
        private const LIBRARY_LIMIT = 500;
        // ...
        private const POPULARITY_CACHE_TTL_SECONDS = 900;
        ```

- [ ] **#PGR-27** · P3 — Public document download presigned URL TTL is hardcoded
    - **Source:** public-surface-security — was `#CFG-2`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php
    - **Affects:** The lifetime of the temporary R2 download link, fixed at 5 minutes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract `now()->addMinutes(5)` to `config('partna.media.download_url_ttl_minutes')`, default `5`.
    - **Technical:** Verified `Storage::disk($mediaDisk)->temporaryUrl()` receives a hardcoded `now()->addMinutes(5)`. Consistent with the rest of `config/partna.php`'s TTL conventions elsewhere in this same audit.
    - **Plain English:** Temporary download links currently always expire after exactly 5 minutes, hardcoded in the program itself. Making it a setting means that number can be adjusted without a code change.
    - **Evidence:**
        ```php
        $presignedUrl = Storage::disk($mediaDisk)->temporaryUrl(
            (string) $document->path,
            now()->addMinutes(5),
        ```

- [ ] **#PGR-28** · P3 — `content.items` library query has no index fully covering its filter + sort shape
    - **Source:** public-surface-security — was `#SCHEMA-2`
    - **Where:** app/Site/Pools/PoolResolver.php:208-214
    - **Affects:** Pool "library" resolution (dashboard + public payload) for professionals with unusually large content libraries.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - If a future user's library grows large enough to matter, add `CREATE INDEX CONCURRENTLY idx_content_items_user_kind_recency ON content.items (user_id, kind, removed_at, last_seen_at DESC);` — not urgent today.
    - **Technical:** Verified an index already exists — `idx_content_items_user_kind` on `(user_id, kind)` (`supabase/migrations/20260727140000_content_schema.sql:69`) — which covers the most selective part of this query's WHERE clause; only the `removed_at IS NULL` filter and `ORDER BY last_seen_at DESC` fall outside it. Per this platform's own scale doctrine, per-user content-library row counts are small (a professional's own items, not a list/analytics/staff sweep), so the marginal benefit of a fully-covering composite index is low at current scale — downgraded from DeepSeek's P1 accordingly. Revisit if a power-user's library size grows materially.
    - **Plain English:** Building someone's content list currently uses a partial shortcut in the database rather than one built exactly for this query. For the number of items an individual professional has today, this makes no noticeable difference — but it's worth a note in case libraries grow much larger later.
    - **Evidence:**
        ```php
        $libraryIds = DB::connection('pgsql')->table('content.items')
            ->where('user_id', $site->user_id)
            ->whereIn('kind', PoolRegistry::kinds($pool))
            ->whereNull('removed_at')
            ->orderByDesc('last_seen_at')
            ->limit(self::LIBRARY_LIMIT)
            ->pluck('id')
            ->all();
        ```

- [ ] **#PGR-29** · P3 — Public document download's subdomain check is a no-op when the caller omits the header
    - **Source:** public-surface-security — was `#SEC-2`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php:25-33
    - **Affects:** Cross-subdomain hotlinking/affinity of document downloads — not confidentiality, since the underlying document is already public.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If the intent is genuinely to bind downloads to their originating site, derive the expected subdomain from the request `Host` header rather than trusting a caller-supplied one, or drop the check entirely if it's only ever meant as a hygiene signal.
    - **Technical:** Confirmed the subdomain check only runs `if ($requestedSubdomain !== '')` — a caller can omit `X-Site-Subdomain` and the check is skipped entirely. However, every prerequisite that actually gates public visibility (`pool === POOL_DOCUMENTS`, `is_active`, `deleted_at === null`, `site->is_published`) already runs unconditionally before this check, and those already fully determine whether the document is meant to be publicly downloadable. Since the document is already public by design once its site is published, omitting the subdomain header does not disclose anything a caller couldn't already obtain by requesting the correct subdomain — this is cross-linking/hotlink hygiene, not a confidentiality boundary, which is why this is downgraded sharply from DeepSeek's original P0.
    - **Plain English:** A download link has an extra check that's supposed to confirm the request came from the right professional's page, but that check is skipped if the caller doesn't send along that detail. In practice this doesn't expose anything private — the document was already meant to be publicly downloadable once its owner's page went live — but it's a loose end worth tidying up.
    - **Evidence:**
        ```php
        // Enforce subdomain isolation when the caller provides an X-Site-Subdomain
        // header (all public-site frontend requests do). Absent header = unenforced
        // (used only by internal/test callers that bypass routing).
        $requestedSubdomain = trim((string) $request->header('X-Site-Subdomain', ''));
        if ($requestedSubdomain !== '') {
            abort_unless(
                strtolower($site->subdomain) === strtolower($requestedSubdomain),
                404
            );
        }
        ```

- [ ] **#PGR-30** · P3 — ShopController hardcodes business limits instead of reading `config/partna.php`
    - **Source:** slug-shop-lane — was `#CFG-1`
    - **Where:** app/Http/Controllers/Api/Platforms/ShopController.php
    - **Affects:** Store-connect cap, picker cache lifetime, and referral suffix maximum — changing any requires a code deploy and risks drift between environments.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `MAX_BRANDS`, `CATALOG_TTL_MINUTES`, and `MAX_REFERRAL_QUERY` into `config/partna.php` (e.g. `shop.max_brands`, `shop.catalog_ttl_minutes`, `shop.max_referral_query`).
        - Replace the class constants with `config('partna.shop.max_brands', 5)` etc.
    - **Technical:** These are operational/product limits, not immutable business logic — the same file already draws its DB-lookup caps (`products_limit`, `menu_items_limit`, `event_ids_limit` in `CloudflarePurgeService`) from `config('partna.cloudflare_purge.*')`, and a `CFG-3` in-code comment on that file explicitly distinguishes a genuinely fixed external limit (Cloudflare's 30-URL API ceiling, left as a literal) from a tunable one. `MAX_BRANDS`/`CATALOG_TTL_MINUTES`/`MAX_REFERRAL_QUERY` are the latter kind and should follow the same config doctrine (`config/partna.php` is the canonical home for feature limits per project convention).
    - **Plain English:** A user can connect up to 5 shops, we cache their product lists for 10 minutes, and we cap promotional links at 500 characters. Those numbers are baked into the code right now, so changing any of them means editing code and shipping a release. Moving them to the settings file lets an engineer adjust them like a dial instead.
    - **Evidence:**
        ```php
        private const MAX_BRANDS = 5;

        private const CATALOG_TTL_MINUTES = 10;

        private const MAX_REFERRAL_QUERY = 500;
        ```

- [ ] **#PGR-31** · P3 — Raw scraper product arrays returned directly from two shop endpoints, bypassing Resource transformation
    - **Source:** slug-shop-lane — was `#API-2`
    - **Where:** app/Http/Controllers/Api/Platforms/ShopController.php (catalog(), brandProducts())
    - **Affects:** Authenticated user dashboard shop picker/catalog; API contract for product list shape.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the products array in a dedicated `ShopProductResource` collection (or equivalent) before returning from both `catalog()` and `brandProducts()`.
        - Decide whether internal scraper keys are intended for the dashboard; strip or `when()` them in the Resource if not.
    - **Technical:** Both actions return `$this->success(['products' => $products])` where `$products` is the raw array produced by `ShopCatalog`/scraper strategies — bypassing the Resource layer that governs every other API response shape in this codebase, and creating a product shape independent of (and able to drift from) `ShopBrandResource`'s own product projection.
    - **Plain English:** Two shop endpoints send the raw output from our store-reading tools straight to the app, instead of running it through the same formatting step every other response uses. The data is fine today, but nothing stops the shape from silently drifting from the rest of the shop API, which would surprise the frontend later.
    - **Evidence:**
        ```php
        // catalog()
        return $this->success(['products' => $products]);

        // brandProducts()
        return $this->success(['products' => $products]);
        ```

- [ ] **#PGR-32** · P3 — ShopController manually resolves API Resources before returning, losing resource envelope semantics
    - **Source:** slug-shop-lane — was `#API-1`
    - **Where:** app/Http/Controllers/Api/Platforms/ShopController.php (brands(), addBrand(), connectStatus(), updateBrand(), setProducts(), addProduct(), removeBrand(), removeProduct())
    - **Affects:** Authenticated user dashboard clients; response shape consistency for shop endpoints.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `(new ShopBrandResource(...))->resolve()` / `ShopBrandResource::collection(...)->resolve()` calls with direct Resource/ResourceCollection returns where the response envelope allows it.
        - Keep transformation inside the Resource layer rather than pre-resolving into a plain array embedded under a custom key.
    - **Technical:** Manually calling `->resolve()` and embedding the array under a hand-picked key discards Laravel's Resource wrapping/collection-metadata machinery; every shop endpoint then hand-rolls its own envelope, making it harder to later attach standard collection metadata without a breaking client change. This is the same root cause as #API-2 — both bypass the framework's Resource contract in favour of a manually-shaped array — and both live in the same controller.
    - **Plain English:** The shop endpoints unwrap the standard response packaging before sending it out. It works today, but means we can't add the usual extras (like pagination info) later without changing every client that reads these endpoints — like taping an envelope shut by hand each time instead of using the standard one.
    - **Evidence:**
        ```php
        return $this->success([
            'brands' => ShopBrandResource::collection(array_values($map))->resolve(),
        ]);

        // ...

        $resolved = (new ShopBrandResource(
            $this->contentReader->brandMap($user)[$id] ?? []
        ))->resolve();

        // ...

        return $this->success($resolved);
        ```

- [ ] **#PGR-33** · P3 — DesignKitRestyleResource bypasses the ApiResource base and emits an uncast id
    - **Source:** wire-resources — was `#API-2`
    - **Where:** app/Http/Resources/Site/DesignKitRestyleResource.php
    - **Affects:** API consumers receiving design-kit restyle payloads; a lone Resource on the older `JsonResource`/no-cast convention instead of the platform's `ApiResource` contract.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `extends JsonResource` to `extends \App\Http\Resources\ApiResource`.
        - Cast `id` to string: `'id' => (string) $this->id`.
    - **Technical:** `ApiResource`'s docblock states the platform-wide contract: every Resource emitting `id` must cast it to string, and every Resource should extend `ApiResource` rather than `JsonResource` directly. `ApiResource` is a zero-behavior abstract wrapper (`abstract class ApiResource extends JsonResource {}`), so this fix has no runtime effect beyond bringing the class into the documented convention.
    - **Plain English:** Every API reply in this codebase is supposed to follow one shared template. This one reply was written against an older, slightly different template. It works fine today, but it's one more place a future developer has to remember doesn't match the rest.
    - **Evidence:**
        ```php
        class DesignKitRestyleResource extends JsonResource
        {
            public function toArray(Request $request): array
            {
                return [
                    'id' => $this->id,
                    'appliedAt' => $this->applied_at->toIso8601String(),
                    'undoneAt' => $this->undone_at?->toIso8601String(),
                ];
            }
        }
        ```

- [ ] **#PGR-34** · P3 — Public profile payload includes the internal `site_id` UUID for unauthenticated visitors
    - **Source:** wire-resources — was `#API-1`
    - **Where:** app/Http/Resources/PublicSite/IndividualProfileResource.php (toArray)
    - **Affects:** Unauthenticated sitepage visitors and any client parsing `GET /api/public/profiles/{handle}`; a database primary key becomes part of the CDN-cached public contract.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `site_id` from the `profile` object, or confirm a specific public consumer needs it and add it to the class's documented "INTENTIONAL EXCLUSIONS" list rather than leaving it unmentioned.
    - **Technical:** `IndividualProfileResource::toArray()` emits `'site_id' => $this->sections['site_id'] ?? null` inside the public `profile` object. The class's own docblock enumerates "INTENTIONAL EXCLUSIONS" (legacy theme fields, gallery fields, PII, commerce) but does not list `site_id` among them, suggesting this is an oversight rather than a deliberate inclusion. The `core.sites` UUID carries no public meaning and isn't used as a lookup key on any public endpoint, so impact is low, but baking a DB identifier into a cached public contract makes it harder to remove later once a client starts depending on it.
    - **Plain English:** This is like a shop printing its internal stockroom bin number on a customer-facing price tag. Visitors don't need it, and once the public page starts showing it, removing it later risks breaking whatever has learned to look for it.
    - **Evidence:**
        ```php
        'profile' => [
            'handle' => $this->handle,
            'displayName' => $this->display_name,
            'accountType' => $this->account_type?->value,
            'site_id' => $this->sections['site_id'] ?? null,
        ```

- [ ] **#PGR-35** · P3 — Two consumers hardcode a `'partna.au'` fallback that disagrees with `config('partna.public_domain')`'s own default chain
    - **Source:** wire-resources — was `#CFG-1`
    - **Where:** app/Http/Resources/PreAccountBuildStatusResource.php; routes/api/publicSite.php:16
    - **Affects:** Pre-account build poll responses and public-site routing when `PARTNA_PUBLIC_DOMAIN` and `SIDEST_PUBLIC_DOMAIN` are both unset — the two consumers silently disagree with what the config layer itself would resolve to.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Drop the `?: 'partna.au'` fallback in both files and use `config('partna.public_domain')` directly — it already resolves through `env('PARTNA_PUBLIC_DOMAIN', env('SIDEST_PUBLIC_DOMAIN', parse_url(env('APP_URL'), PHP_URL_HOST) ?: 'localhost'))`, so it is never null/empty in practice.
        - If a `'partna.au'` floor is genuinely wanted for a misconfigured env, add it as the innermost fallback inside `config/partna.php`'s `public_domain` definition itself, not duplicated at each call site.
    - **Technical:** Verified via `Read` that `config/partna.php`'s `public_domain` key already has a three-tier fallback chain ending in `parse_url($APP_URL) ?: 'localhost'` — it does **not** default to `'partna.au'`. Both `PreAccountBuildStatusResource::toArray()` and `routes/api/publicSite.php:16` independently append `?: 'partna.au'` on top of that, so if both env vars are ever unset, these two call sites would silently diverge from the config layer's actual resolved value (`localhost`-derived) rather than surfacing the misconfiguration. The duplication itself is real (same magic string in two files, matching the finding's own in-code comment cross-reference), and the divergence from the config default is a stricter version of the bug than originally drafted.
    - **Plain English:** The site's default web address is written on two sticky notes instead of the one central operations manual — and worse, both sticky notes say something different from what the manual itself would say if consulted directly. If the real setting is ever missing, visitors could see two different wrong guesses depending on which code path answers.
    - **Evidence:**
        ```php
        // Same fallback as routes/api/publicSite.php — a missing/typo'd
        // PARTNA_PUBLIC_DOMAIN env must not silently break the poll payload.
        $publicDomain = config('partna.public_domain') ?: 'partna.au';
        ```
