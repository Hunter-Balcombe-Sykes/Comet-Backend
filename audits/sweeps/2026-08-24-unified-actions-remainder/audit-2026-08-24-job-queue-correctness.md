# Job/Queue Correctness Audit — 2026-08-24

**Branch:** development
**Lens:** Job/Queue Correctness — idempotency, retry safety, ShouldBeUnique, missing `$this->fail()`, retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Jobs/Platforms/CommerceProbeJob.php
- app/Jobs/Platforms/ShopBrandConnectJob.php
- app/Jobs/Platforms/ShopInitialFillJob.php
- app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/FixturesCaptureCommand.php
- app/Console/Commands/FixturesVerifyCommand.php
- app/Console/Commands/ResetTestUserCommand.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#JOB-1** · P1 — ShopInitialFillJob's fill/auto-select failures are logged only, with no `report()` call, silently regressing a bug that already shipped once
    - **Where:** app/Jobs/Platforms/ShopInitialFillJob.php:74-92
    - **Affects:** Newly connected Shop stores in the scan-suggested lane; a transient `ShopCatalog::syncLatest()` failure leaves the product library empty (and the first-connect auto-select with nothing to pick) until the 6-hourly scheduled `ShopFetch`, with zero alerting anywhere.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` alongside the existing `Log::warning(...)` in both catch blocks so a systemic failure (not just an isolated transient one) reaches Nightwatch.
        - Keep the best-effort, non-retrying design (`$tries`/backoff here are meant to cover a whole different failure surface — the job's own dispatch/lookup path, not this fetch — since the docblock explicitly states the fetch gate/circuit machinery owns retries for the fetch itself).
    - **Technical:** `handle()` wraps `ShopCatalog::syncLatest()` and `ShopAutoSelector::selectInitial()` in independent `catch (Throwable $e)` blocks that call only `Log::warning(...)` — no `report($e)`. Per the project's observability contract, Nightwatch alerts on exceptions reaching the exception handler (via `report()` or an uncaught throw); a bare `Log::warning` is invisible to it. This job's own docblock states the initial fill exists specifically because a prior version of this exact gap ("a scan-suggested store... never passes through ShopBrandConnectJob... the catalogue stayed empty") already reached production once. Swallowing the retry-relevant exception without `report()` means a recurrence of that same failure mode — e.g. a regression in `ShopCatalog::syncLatest()` itself — would silently reproduce the empty-shop bug with no alert anywhere, only a `Log::warning` line nobody is paged on.
    - **Plain English:** A new online shop is connected, and a helper is supposed to load the products onto the shelves right away. If that loading trips up, the helper writes a note in a private notebook and walks away — no manager is told. The shop looks connected but has nothing to sell, and because nobody gets an alert, this exact problem could recur indefinitely without anyone noticing.
    - **Evidence:**
        ```php
        try {
            $catalog->syncLatest($store, (string) $store->userId);
        } catch (Throwable $e) {
            Log::warning('shop.initial_fill_job.fill_failed', [
                'collection_id' => $this->collectionId,
                'user_id' => $store->userId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $selector->selectInitial($this->collectionId);
        } catch (Throwable $e) {
            Log::warning('shop.initial_fill_job.auto_select_failed', [
                'collection_id' => $this->collectionId,
                'user_id' => $store->userId,
                'error' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **#JOB-2** · P1 — ShopBrandConnectJob's fill/auto-select catches have the same log-only pattern as JOB-1, same root cause
    - **Where:** app/Jobs/Platforms/ShopBrandConnectJob.php:216-241
    - **Affects:** Every store connect through the primary `addBrand` lane; a failed initial catalogue fill or auto-select is invisible to Nightwatch, reverting the exact "fresh connect ends with zero products" regression the surrounding comment says this code was written to fix.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` in both catch blocks, matching the fix for JOB-1 (same job family, same fix).
    - **Technical:** Identical root cause to JOB-1: `catch (Throwable $e) { Log::warning(...); }` with no `report()` around both `ShopCatalog::syncLatest()` and `ShopAutoSelector::selectInitial()`. The surrounding comment states plainly this fill exists because "a fresh connect ended with ZERO products" was a live regression already caught once in production (2026-08-17). Per the tiering rule that identical root causes get identical tiers, this carries the same P1 as JOB-1, not the lower tier DeepSeek assigned it.
    - **Plain English:** Same problem as the sibling job above, in the other place a new shop can be connected: the loading step can silently fail with only a private note, no alert, and the shop is left empty until a scheduled refresh catches it hours later.
    - **Evidence:**
        ```php
        try {
            app(ShopCatalog::class)->syncLatest(
                $shop->storeByCollection($this->collectionId) ?? $store,
                (string) $store->userId,
            );
        } catch (Throwable $e) {
            Log::warning('shop.brand_connect_job.initial_fill_failed', [
                'collection_id' => $this->collectionId,
                'user_id' => $store->userId,
                'error' => $e->getMessage(),
            ]);
        }
        ```
        ```php
        try {
            app(ShopAutoSelector::class)->selectInitial($this->collectionId);
        } catch (Throwable $e) {
            Log::warning('shop.brand_connect_job.auto_select_failed', [
                'collection_id' => $this->collectionId,
                'user_id' => $store->userId,
                'error' => $e->getMessage(),
            ]);
        }
        ```

## P2 — Should fix

- [ ] **#JOB-3** · P2 — ApproveEarlyAccessBuildJob reports build/scrape failures but never calls `$this->fail()`, so Horizon's failed-jobs dashboard never reflects the failure
    - **Where:** app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php:107-127, 163-182
    - **Affects:** Staff early-access approvals; a transient DB/build error or a real scrape failure (Apify outage, `SourceGenerationException`) leaves the signup uninvited with a correctly-updated `build_state`/log entry and a Nightwatch report, but Horizon shows the job as processed — staff have no dashboard signal that the approval silently didn't complete.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the bare `return;` in each catch with `$this->fail($e); return;`, after the existing state writes and `report()`/`Log::warning` calls.
        - Keep `$tries = 0` / `$maxExceptions = 1` as-is — `$this->fail()` marks the job terminally failed without triggering any further retry, which is exactly the "never re-bill Apify" guarantee these properties already document; it only adds Horizon-dashboard visibility on top of the Nightwatch alerting that already fires via `report($e)`.
    - **Technical:** Every catch in `handle()` already calls `report($e)` (confirmed in source, unlike JOB-1/JOB-2 above), so Nightwatch — which hooks the exception handler's `report()` path — is not actually blind here, correcting DeepSeek's draft framing ("invisible to Nightwatch"). The real gap is narrower: because `handle()` returns normally after logging instead of calling `$this->fail($e)`, Horizon's `failed_jobs` table and dashboard never record the failure, so an on-call engineer scanning Horizon for stuck approvals will see nothing. Given `$tries = 0` and `$maxExceptions = 1` were deliberately chosen so a real exception never retries and never re-bills the scraper (per the class docblock), calling `$this->fail($e)` instead of a plain `return` achieves the identical no-retry outcome while surfacing the failure where Horizon-based operational tooling actually looks.
    - **Plain English:** When approving someone for early access fails partway through — a hiccup pulling their Instagram content, say — the system writes it in a log and quietly stops, as if nothing went wrong. The background-job dashboard staff would normally check for stuck work shows this as done, not failed, so nobody knows to go re-approve that person unless they happen to read the raw logs.
    - **Evidence:**
        ```php
        public int $tries = 0;

        public int $maxExceptions = 1;
        ```
        ```php
        try {
            $result = $builds->requestBuild(
                accountType: $signup->type,
                sourceType: $signup->source_type,
                rawSourceRef: $signup->source_ref,
                sourceName: null,
                ipHash: null,
                staff: $approvingStaff,
                publish: true,
                expiresDays: null,
                contactEmail: $signup->email_lc,
                builtVia: PreAccountBuild::VIA_EARLY_ACCESS,
            );
        } catch (Throwable $e) {
            Log::warning('early_access.approve.build_failed', [
                'signup_id' => $signup->id, 'error' => $e->getMessage(),
            ]);
            report($e);

            return;
        }
        ```
        ```php
            } catch (SourceGenerationException $e) {
                $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode])->save();
                report($e);
                Log::warning('early_access.approve.scrape_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode]);

                return;
            } catch (Throwable $e) {
                $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();
                report($e);

                return;
            }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Shop connect-fill Nightwatch visibility:** #JOB-1, #JOB-2
    - **Why grouped:** Identical root cause (log-only `catch (Throwable $e)` with no `report()`) across the two Shop connect jobs; identical one-line fix in each of four catch blocks.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Early-access approval failure visibility:** #JOB-3
    - **Why grouped:** Single file, single fix pattern (`$this->fail($e)` after existing `report()`/state writes); no dependency on Bundle 1.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
