# Job/Queue Correctness Audit — 2026-07-28

**Branch:** development
**Lens:** Job/Queue Correctness — idempotency, retry safety, `ShouldBeUnique`, missing `$this->fail()`, retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Jobs/Brand/IngestBrandAssetJob.php
- app/Services/Brand/BrandAssetPipeline.php
- app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php
- app/Jobs/Site/BuildSiteDocumentJob.php
- app/Console/Commands/SiteBuildDocumentsCommand.php
- app/Console/Commands/IngestDispatchCommand.php
- app/Console/Commands/IngestBackfillSourcesCommand.php
- app/Ingest/Runtime/RunExecutor.php
- app/Ingest/Runtime/EffectLedger.php
- app/Ingest/Runtime/SourceScheduler.php
- app/Ingest/Message/Deferred.php
- app/Jobs/Ingest/RunSourceJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Gdpr/ExportUserDataJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Jobs/Cache/AggregateCacheMetricsJob.php
- app/Jobs/Moderation/SuspendSiteJob.php
- app/Jobs/Moderation/SuspendUserJob.php
- app/Jobs/Moderation/Concerns/HasActionLogLifecycle.php
- app/Jobs/Streaming/CheckStreamingLiveStatusJob.php
- app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 1 of 1 complete
- P2 Medium: 1 of 3 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [x] **#JOB-1** · P1 — Website-content scan job re-triggers a paid Instagram scrape and duplicate sub-job dispatch on retry
    - **PREMISE CORRECTED (2026-07-29):** the named paid Instagram scrape is NOT re-triggered — `GoogleBusinessAutoSync::seedInstagram()` checks `has()` for an existing connection before `dispatchInstagram()` claims the Apify budget, so attempt 2 spends nothing. The live defect is the re-dispatch of `WebsiteMenuPdfScanJob` (Mistral OCR) and `WebsiteMenuHtmlScanJob` (MenuAiExtractor), both billed and both carrying `$tries = 1` precisely to avoid re-billing. Fixed on that basis; a regression guard now pins the Instagram charge-once behaviour.
    - **Where:** app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php:62-65, :233-236, :252, :265, :316-317, :334-336
    - **Affects:** Any user whose `previous_website` is set/changed. A retry after the first attempt fails (timeout, transient fetch error, exception anywhere after the sub-job dispatch points) re-runs the whole scan, redispatching PDF/HTML/gallery scan jobs a second time and re-invoking `GoogleBusinessAutoSync::seed()`, which is documented in this same file as capable of triggering a real, budget-metered Apify Instagram scrape.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Follow the precedent already established in this codebase for exactly this failure mode — `App\Jobs\Ingest\RunSourceJob` sets `$tries = 1` specifically because "a fetch is not safely re-runnable by the queue... blindly re-running a fetch could double-charge a billed effect." Apply the same reasoning here: set `$tries = 1` so a failed scan is not auto-retried; let the website-change observer that dispatches this job be the natural resource for a fresh scan if needed.
        - If retry-on-transient-failure is still wanted, gate the paid `seed()` call and the sub-job dispatches behind an idempotency marker (e.g., a `last_scan_completed_at`/`scan_state` column on the site) so a second attempt skips work already performed.
    - **Technical:** The job declares `public int $tries = 2` with `public array $backoff = [30]`. `handle()` dispatches `WebsiteMenuPdfScanJob` (up to 5), conditionally `WebsiteMenuHtmlScanJob`, `WebsiteGalleryScanJob`, and `ResolveSiteAccentJob` (twice), and calls `$googleBusinessAutoSync->seed(...)` — all before the function returns. The job does implement `ShouldBeUnique` (`uniqueId()` = `userId:website-content-scan`, `uniqueFor = 300`), but that only blocks a *second, concurrently-dispatched* job with the same key — it does not prevent Horizon from retrying the *same* locked attempt, which re-executes `handle()` from the top and repeats every side effect, including the paid scrape. Given the job performs several sequential HTTP fetches inside a 60s timeout, a timeout-triggered retry is a realistic, known failure mode, not a hypothetical one.
    - **Plain English:** When someone connects their old website, this job reads it and kicks off several follow-up jobs — including, sometimes, a paid lookup of their Instagram account. If that reading job times out or errors partway through, the system automatically tries the whole thing again from scratch — re-triggering all those follow-up jobs and, worst case, paying for the same Instagram lookup twice. The fix is to stop the automatic retry for this particular job, matching how a very similar paid-lookup job elsewhere in the codebase already handles this exact risk.
    - **Evidence:**
        ```php
        public int $tries = 2;

        /** @var list<int> */
        public array $backoff = [30];
        ```
        ```php
            foreach (array_slice($relevantPdfs, 0, self::MAX_PDF_SCANS) as $index => $pdf) {
                WebsiteMenuPdfScanJob::dispatch($this->userId, $pdf['url'])
                    ->delay(now()->addSeconds(30 + $index * 15));
            }
        ```
        ```php
            $findings = array_filter($googleBusinessAutoSync->seed($this->userId, $harvested, null, null), 'is_array');
        ```
        ```php
        ResolveSiteAccentJob::dispatch($this->siteId, $themeColor, $faviconColor);
        ResolveSiteAccentJob::dispatch($this->siteId, $themeColor, $faviconColor)
            ->delay(now()->addSeconds(120));
        ```

## P2 — Should fix

- [ ] **#JOB-2** · P2 — `RunExecutor::drain()` silently discards messages of an unrecognised type
    - **Where:** app/Ingest/Runtime/RunExecutor.php:208-217
    - **Affects:** Any connector whose `pull()` yields a `Message` subclass not covered by the `match` — the message is dropped with only a log line, no anomaly row, no stream failure, no exception.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Push a `Note`/anomaly (`code: 'unknown_message'`) into the stream's output so it surfaces in `ingest.anomalies`/the run detail, matching how every other stream-level problem in this file is recorded.
        - Alternatively, throw so the stream lands in the existing `catch (\Throwable $e)` path in `execute()`, which already reports and records the failure correctly — a connector author introducing a new `Message` subclass without updating this match is a programming error, not a runtime condition worth swallowing.
    - **Technical:** Per Partna's observability doctrine, `Log::warning` alone (no `report()`, no exception) is invisible to Nightwatch, which only alerts on exceptions and auto-detected slow jobs/routes. The `default` arm of the `match` in `drain()` neither throws nor calls `report()`, so a future connector emitting an unrecognised `Message` type loses that data with zero operator-visible signal beyond a log line nobody is watching.
    - **Plain English:** The data-fetching engine understands six kinds of messages a connector can hand it. If a connector ever hands it a seventh, unrecognized kind, the engine quietly throws it away and only whispers about it in a log file nobody reads regularly. The fix makes that a visible incident instead of a silent loss, the same way every other kind of failure in this file already is.
    - **Evidence:**
        ```php
        match (true) {
            $message instanceof Record => $out['records'][] = $message,
            $message instanceof Covered => $out['covered'] = $message,
            $message instanceof Bookmark => $out['bookmark'] = $message,
            $message instanceof Note => $out['notes'][] = $message,
            $message instanceof Deferred => $out['deferred'] = $message,
            $message instanceof Unavailable => $out['unavailable'] = $message,
            default => Log::warning('ingest.unknown_message', ['class' => $message::class]),
        };
        ```

- [ ] **#JOB-3** · P2 — `BuildSiteDocumentJob` is not assigned to a dedicated queue
    - **Where:** app/Jobs/Site/BuildSiteDocumentJob.php:44-47
    - **Affects:** Site-document rebuilds (the `site:build-documents --stale` 5-minute sweeper and `--all` fleet rebuilds) land on the shared default queue instead of a dedicated lane, so a fleet-wide rebuild after a `BUILDER_REVISION` bump competes directly with whatever else runs on `default`, and vice versa.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `$this->onQueue(config('partna.queues.cache_warm', 'cache-warm'));` in the constructor, matching the pattern `WarmPublicSiteCacheJob` already uses for the same class of "pre-generate content for the edge" work, and add a corresponding Horizon supervisor lane entry if `cache-warm` isn't already sized for this job's volume.
    - **Technical:** The job builds the versioned document that ultimately backs the public sitepage cache, but its constructor sets no queue at all, so it inherits the connection's default queue name. CLAUDE.md documents `cache-warm` as the dedicated Horizon lane for exactly this kind of pre-generation work (see `WarmPublicSiteCacheJob`, which explicitly isolates itself "so a burst of cache-warm dispatches after a publish event doesn't compete with user-facing notifications or mail"). `BuildSiteDocumentJob` already carries a `ShouldBeUnique` guard per site+channel, so under `--all` a fleet rebuild can still fan out one job per published site — all landing on `default` — with no isolation from other `default`-queue traffic.
    - **Plain English:** This job rebuilds the polished, ready-to-serve version of a business's public page. Right now it's mixed in with a general-purpose lane instead of the dedicated "cache warming" lane the system already has for this exact kind of work. During a full-fleet rebuild, that means these jobs and unrelated everyday jobs can end up queued behind each other instead of on separate tracks.
    - **Evidence:**
        ```php
        public function __construct(
            public readonly string $siteId,
            public readonly string $channel = 'live',
        ) {}
        ```

- [x] **#JOB-4** · P2 — Ingest run outcome stays `ok` even when every projection for the run failed
    - **Where:** app/Ingest/Runtime/RunExecutor.php:168-186
    - **Affects:** Operators and anyone querying `ingest.runs` directly — a run where every stream lands successfully but every projection throws is recorded with `outcome = 'ok'`, even though the landed data stays unprojected (invisible to product surfaces) until a manual `ingest:project` sweep.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inside the projection `catch` block, add `$worstOutcome = $this->worse($worstOutcome, 'degraded');` — `'degraded'` is already a valid rank in `worse()` (used by `recordStreamFailure()` for budget errors), so this reuses an existing outcome value rather than inventing a new one.
    - **Technical:** `$streamOutcomes[$streamName]` is set to `'ok'` (or `'guard_tripped'`) at line 150, before the projection block runs. The projection `catch (\Throwable $e)` at line 172 calls `report($e)` and inserts an `ingest.anomalies` row, but never touches `$worstOutcome`, so the final `DB::table('ingest.runs')->...->update(['outcome' => $worstOutcome, ...])` at line 191 still writes whatever the landing phase produced. `report($e)` does reach Nightwatch, but the run's own outcome column — the field a support engineer would query first — misrepresents a partial failure as a clean run.
    - **Plain English:** Landing new data and turning it into something the product can actually show are two separate steps. If the first step succeeds but the second silently fails, the system currently still marks the whole run "all good" — hiding the fact that real work still needs to happen before that data is usable. The fix makes the run's status honestly reflect the partial failure.
    - **Evidence:**
        ```php
        $streamOutcomes[$streamName] = $landed['guard_tripped'] ? 'guard_tripped' : 'ok';
        ```
        ```php
            if (($landed['changed'] > 0 || $landed['tombstoned'] > 0)
                && ProjectorRegistry::has((string) $source['source_key'], $streamName)) {
                try {
                    $this->projections->projectStream($source, $streamId, $streamName);
                } catch (\Throwable $e) {
                    report($e);
                    $notes[] = ['code' => 'projection_error', 'message' => $e->getMessage()];
                    DB::table('ingest.anomalies')->insert([
        ```

## P3 — Nice to have

- [ ] **#JOB-5** · P3 — `IngestDispatchCommand` always exits `SUCCESS`, even when every claimed source's dispatch throws
    - **Where:** app/Console/Commands/IngestDispatchCommand.php:38-56
    - **Affects:** Cron/scheduler-level monitoring of the ingest dispatch tick — a tick where every dispatch throws still exits 0. The underlying exceptions themselves ARE already visible to Nightwatch via `report($e)`, so this is an exit-code/scheduler-monitoring gap, not a fully silent failure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `$failures` counter incremented inside the `catch` block and gate the return value on it, mirroring the sibling command `IngestBackfillSourcesCommand`, which already does exactly this.
    - **Technical:** The `foreach` loop's `catch (Throwable $e)` calls `report($e)` and `Log::error(...)` but the method's final line is an unconditional `return self::SUCCESS`. `IngestBackfillSourcesCommand` in the same module tracks a `$failures` counter and returns `$failures === 0 ? self::SUCCESS : self::FAILURE` — this command should match that pattern for consistency and so any scheduler-level failure alerting keyed on exit code actually fires.
    - **Plain English:** This command hands out work orders and occasionally every hand-off can fail. When that happens, the problem does get reported to the error-tracking system, but the command itself still reports back "all good" to whatever is checking whether it ran successfully. A sibling command in the same file already does this correctly — this one should match it.
    - **Evidence:**
        ```php
            } catch (Throwable $e) {
                // A --sync run executes inline, so a bad row can throw here.
                // One failure must not abort the rest of the tick's batch —
                // RunSourceJob's own finally already released this row's claim.
                report($e);
                Log::error('ingest.dispatch.source_failed', [
                    'source_id' => $source['id'],
                    'sync' => $sync,
                    'error' => $e->getMessage(),
                ]);
            }
        ```
        ```php
        return self::SUCCESS;
        ```

- [ ] **#JOB-6** · P3 — `EffectLedger::once()`'s insert `catch` can mask a non-duplicate-key DB error as a silent `'refused'`
    - **Where:** app/Ingest/Runtime/EffectLedger.php:63-81
    - **Affects:** Billed effects (actor runs, AI extraction) — in the narrow case where the `insert()` fails for a reason other than the digest's unique-key race (and the immediate re-query then finds no row), the effect is silently skipped with no log and no exception, rather than surfacing as an error.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Check `$e->getCode()` (or the underlying PDO/PostgreSQL SQLSTATE) for the unique-violation code before treating the failure as "someone else already claimed this digest"; re-throw for anything else so it reaches the caller's own error handling instead of resolving silently to `'refused'`.
    - **Technical:** The `catch (\Throwable)` is designed for the legitimate race where two workers insert the same `digest` simultaneously — one wins, the loser's re-query finds the winner's row and returns its verdict via `verdictFor()`. But the same broad catch also applies to any other insert failure (e.g. a constraint violation unrelated to `digest`). If the immediate re-query in that branch then finds no row (because the insert genuinely never happened for any worker), the method returns `['status' => 'refused', ...]` with no log line and no re-thrown exception — the effect is quietly never performed. Note this window is narrower than "any transient DB error": a connection-level failure would generally also break the re-query, and that would propagate up uncaught since it isn't itself wrapped in a further `try`/`catch`.
    - **Plain English:** This code has a "claim it before you pay for it" system so two workers never accidentally pay twice for the same thing. When two workers really do collide, that's handled correctly. But if the database rejects the very first attempt for some unrelated reason, the code currently treats that the same way as a collision and just walks away — with no error, no log, nothing to look at later. It's a narrow edge case, but it means a billed piece of work could quietly never happen.
    - **Evidence:**
        ```php
        try {
            DB::table('ingest.effects')->insert([
                'digest' => $digest,
                'run_id' => $runId,
                'source_id' => $sourceId,
                'kind' => $kind,
                'cost_tag' => $costTag,
                'cost_units' => $costUnits,
                'claimed_at' => now(),
                'status' => 'claimed',
                'meta' => json_encode([]),
            ]);
        } catch (\Throwable) {
            $row = DB::table('ingest.effects')->where('digest', $digest)->first();

            return $row === null
                ? ['status' => 'refused', 'result' => null, 'cached' => false]
                : $this->verdictFor($row);
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Ingest pipeline failure visibility:** #JOB-2, #JOB-4, #JOB-5, #JOB-6
    - **Why grouped:** All four live in the `app/Ingest/Runtime` + `app/Console/Commands` ingest subsystem and share one root pattern — a failure path that resolves to a silently-misleading success/refused state instead of a visible one. Fixing them together keeps the "what does a failed ingest tick look like" story consistent.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

- **Bundle 2 — Cache-warm queue hygiene:** #JOB-3
    - **Why grouped:** Single small, low-risk queue-assignment fix; no natural pairing with the other findings.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

- **#JOB-1 — ScanPreviousWebsiteContentJob retry re-triggers a paid Instagram scrape** · standalone: P1 finding whose fix directly controls real third-party API spend (a duplicate paid Apify Instagram scrape) and interacts with an existing `ShouldBeUnique` design — needs its own plan and sign-off rather than being bundled with unrelated work.
