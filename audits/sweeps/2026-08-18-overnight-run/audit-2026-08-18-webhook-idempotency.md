# Inbound callbacks & idempotency semantics Audit — 2026-08-18

**Branch:** audit-fix/instagram-wave-findings-2026-08-18
**Lens:** Inbound callbacks & idempotency semantics — HMAC ordering, idempotency-anchor persistence, silent-200 swallowing, domain mutations in controllers, job/mailable idempotency, out-of-order tolerance, schema-validation status codes, the `IdempotencyKey` client middleware, and `bot.token`-gated internal endpoints, applied to the surviving callback surface post-2026-05-22 standalone strip (this run's scope: ingest replay path + platform route idempotency coverage).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `routes/api/platforms.php`
- `routes/console.php`
- `app/Ingest/Landing/Lander.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#WHK-1** · P1 — Per-record fallback has no per-record try/catch, so one poison record aborts the rest of the run instead of being isolated [cat 5]
    - **Where:** app/Ingest/Landing/Lander.php:301-385 (`landRecordsIndividually()`), propagating uncaught through app/Ingest/Landing/Lander.php:83 (`land()`) and app/Ingest/Runtime/RunExecutor.php:144-151 (`execute()`)
    - **Affects:** Any ingest stream whose chunk-transaction fallback path is triggered by a genuinely malformed record (the class's own docblock names the exact scenario: a scraped caption containing a literal NUL byte, rejected by `jsonb` with `22P05`). When it fires, every later record in that chunk — and every later stream in that source's run — silently fails to land this cycle instead of just the one bad record.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap each `DB::transaction(...)` call inside the `landRecordsIndividually()` loop in its own `try { ... } catch (Throwable $e) { report($e); continue; }`, keeping the catch OUTSIDE the transaction closure (the file's own comment at Lander.php:70-75 already explains why a caught-and-recovered failure must never run inside an open Postgres transaction — 25P02).
        - Consider also wrapping the `$this->lander->land(...)` call in `RunExecutor::execute()` (currently the only unguarded external call in that loop — `drain()` and `projectStream()` both have `try/catch`) so a still-uncaught failure degrades one stream's outcome to `error` instead of aborting the rest of the source's streams for the run.
        - Add a regression test exercising a chunk with one malformed record followed by valid records, asserting the valid records still land — `tests/Feature/Ingest/LanderTest.php` currently has no coverage of the per-record fallback's isolation behavior at all.
    - **Technical:** `land()`'s inline comment states the design intent explicitly: "Falling back to the per-record path isolates the one bad record and lands the rest durably, just slower." But `landRecordsIndividually()`'s `foreach` loop has no per-record `try/catch` around its `DB::transaction()` call — if any single record throws (the NUL-byte/`22P05` case the comment itself names), the exception propagates out of the loop, aborting every subsequent record in that chunk. Because `RunExecutor::execute()` calls `$this->lander->land(...)` without a `try/catch` (unlike its sibling `drain()` call at RunExecutor.php:91-106 and its sibling `projectStream()` call at RunExecutor.php:176-207, both of which explicitly catch, `report()`, and mark a degraded outcome), the exception continues propagating out of the entire `foreach ($manifest->streams as ...)` loop — meaning every remaining stream for that source in that run is also skipped, not just the poisoned chunk. Recovery depends entirely on the next scheduled run picking the source back up via `SourceScheduler`'s backoff; `RunSourceJob` is deliberately `$tries = 1` (queue-level retry is explicitly rejected in its docblock to avoid double-billing effects), so there is no automatic re-attempt within the same dispatch.
    - **Plain English:** The code has a safety net whose whole job is to catch one broken item in a batch and still deliver the rest — like a delivery driver who, if one package has a bad label, sets it aside and keeps delivering everything else on the truck. Right now that safety net doesn't actually catch anything: the first broken item stops the whole truck, and everything behind it — including packages for other addresses entirely — doesn't get delivered this trip. It usually recovers on the next scheduled run, but until then real content updates silently go missing for a cycle with no visible error pointing at the actual cause.
    - **Evidence:**
        ```php
        // The claim, in land():
        // Falling back to the per-record path isolates the one bad
        // record and lands the rest durably, just slower.
        report($e);
        $changed += $this->landRecordsIndividually($streamId, $runId, $spec, $chunk, $redactions);

        // landRecordsIndividually() — no try/catch around the per-record loop:
        foreach ($records as $record) {
            $doc = Redactor::apply($record->doc, $redactions);
            $hash = DocHasher::hash($doc, $spec->volatile);

            DB::transaction(function () use ($streamId, $runId, $record, $doc, $hash, &$changed) {

        // RunExecutor::execute() — land() is called with no try/catch, unlike
        // its sibling drain() and projectStream() calls in the same loop:
        $landed = $this->lander->land(
            streamId: $streamId,
            runId: $runId,
            spec: $spec,
            records: $result['records'],
            covered: $result['covered'],
            redactions: $manifest->redactionsFor($pull->isClaimed),
        );
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Ingest per-record fallback isolation:** #WHK-1
    - **Why grouped:** single file, single root cause (missing per-record error isolation).
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
