# Inbound Callbacks & Idempotency Semantics Audit — 2026-07-28

**Branch:** development
**Lens:** Inbound callbacks & idempotency semantics — HMAC ordering, idempotency-anchor persistence, silent-200-on-failure, domain mutations in controllers, job/mailable idempotency, out-of-order tolerance, schema-validation status codes, client-supplied `IdempotencyKey` middleware, bot-token-gated internal endpoints, and the ingest billed-effect ledger's replay/idempotency semantics.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
- app/Http/Controllers/Api/Webhooks/ResendWebhookController.php
- app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php
- app/Http/Controllers/Api/Internal/EnvCheckController.php
- app/Http/Controllers/Api/Internal/CspReportController.php
- app/Http/Middleware/Auth/VerifySupabaseHookSignature.php
- app/Http/Middleware/Auth/VerifyResendWebhookSignature.php
- app/Services/Webhooks/StandardWebhookVerifier.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/VerifyBotToken.php
- app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php
- app/Services/User/AccountDeletionService.php
- app/Ingest/Runtime/EffectLedger.php
- app/Ingest/Runtime/SourceScheduler.php
- app/Ingest/Landing/Lander.php
- routes/api.php
- routes/api/user.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 4 of 4 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **#WHK-1** · P2 — `SourceScheduler::releaseStranded` overwrites a legitimately re-claimed source (TOCTOU)
    - **Category:** 6 (out-of-order / stale-replay tolerance), generalized from webhook idempotency to the ingest scheduler's own claim/release protocol.
    - **Where:** app/Ingest/Runtime/SourceScheduler.php:192-204
    - **Affects:** Any `ingest.sources` row that is (a) flagged stranded by the 7200s cutoff, then (b) legitimately released and re-claimed by `claimDue()` in the narrow window between `releaseStranded`'s SELECT and its per-row UPDATE. The fresh claim's `in_flight_since` gets nulled out from under the new run.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->where('in_flight_since', '<', $cutoff)` (re-checked, not just read once) to the release UPDATE's WHERE clause, so it only clears a row that still holds the stale timestamp it was selected on.
        - This mirrors the conditional-UPDATE claim pattern `claimDue()` already uses (`whereNull('in_flight_since')`) for exactly the same race.
    - **Technical:** `releaseStranded()` reads candidate IDs via `pluck('id')->all()` filtered on `in_flight_since < $cutoff`, then iterates with an unconditional `UPDATE ... SET in_flight_since = null`. If a source is released by `SourceScheduler::release()` and re-claimed by `claimDue()` between the SELECT and this UPDATE, `in_flight_since` now holds a fresh timestamp from the legitimate new claim, and the unconditional UPDATE destroys it. `claimDue()`'s own claim UPDATE conditions on `whereNull('in_flight_since')` for this exact reason; the release path is missing the same guard.
    - **Plain English:** A parking attendant walks the lot writing down plates of cars parked too long, then walks back and tows every car on the list — without checking whether the owner returned and legitimately re-parked in the meantime. The fix is to re-check the parking slip's timestamp immediately before towing, not to trust the list from a few seconds ago.
    - **Evidence:**
        ```php
        $stranded = DB::table('ingest.sources')
            ->whereNotNull('in_flight_since')
            ->where('in_flight_since', '<', $cutoff)
            ->pluck('id')
            ->all();

        foreach ($stranded as $id) {
            DB::table('ingest.sources')->where('id', $id)->update([
                'in_flight_since' => null,
                'in_flight_run_id' => null,
                'next_attempt_at' => now(),
                'updated_at' => now(),
            ]);
        ```

- [x] **#WHK-2** · P2 — `Lander::foldAbsence` counter bumps are not atomic; a crash mid-batch shortens a key's tombstone runway
    <!-- premise MOSTLY resolved by aa1b5782 (per-key UPDATE loop gone). The audit's stated failure mode (re-running the same absence-fold) has NO live path: RunSourceJob sets tries=1. Unit B fixed only the surviving residual — foldAbsence's write phase was not transactional, so a crash mid-fold could advance chunk 1 and not chunk 2, or set guard_tripped_at with no anomaly row. -->
    - **Category:** 4/6 (processing must be idempotent end-to-end; a partial-execution retry must not silently mutate outcomes).
    - **Where:** app/Ingest/Landing/Lander.php:165-178
    - **Affects:** Any stream where absence-folding partially commits before a process crash or DB timeout mid-loop — affected keys skip one "chance" and tombstone after 2 real absences instead of the documented 3.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the per-key `absent_runs` increments for one run in a single batch statement (a `CASE`-driven bulk UPDATE, or `DB::transaction`) so they advance atomically together.
        - Alternatively, make the increment idempotent against retries by keying it off `run_id` — only bump `absent_runs` if the last run that touched the key differs from the current run id.
    - **Technical:** `foldAbsence` computes `$dominatedAbsent` in memory, then updates each row's `absent_runs` individually. A crash after N of M updates leaves the batch half-advanced; re-running the same absence-fold (same run, same input) increments the already-bumped keys a second time, converging on `TOMBSTONE_RUNS` (3) after only 2 genuinely distinct absent runs. This is the general idempotent-reprocessing property the lens requires of every retryable side effect, applied here to the ingest deletion-guard's own bookkeeping.
    - **Plain English:** The system needs three consecutive "not seen" misses in a row before deciding a record is really gone. If the server hiccups halfway through updating a batch of missing records' miss-counters, the ones already updated lose one of their three "lives" — on the next run they only need two more misses to be deleted instead of three. Bundling the counter bumps into one all-or-nothing step (or tying them to which run touched them last) fixes it.
    - **Evidence:**
        ```php
        $tombstoned = 0;
        foreach ($dominatedAbsent as $row) {
            $runs = (int) $row->absent_runs + 1;
            $update = ['absent_runs' => $runs, 'absent_since' => DB::raw('COALESCE(absent_since, now())')];

            if ($runs >= self::TOMBSTONE_RUNS) {
                $update['tombstoned_at'] = now();
                $tombstoned++;
            }

            DB::table('ingest.record_state')
                ->where('stream_id', $streamId)
                ->where('key', $row->key)
                ->update($update);
        }
        ```

- [x] **#WHK-3** · P2 — `EffectLedger::once` catches `\Throwable`, masking non-duplicate INSERT failures as silent refusals
    - **Category:** 2/4 (idempotency-anchor claim must distinguish "already claimed" from "claim attempt itself failed"; the ledger governs money — see recent commit note below).
    - **Where:** app/Ingest/Runtime/EffectLedger.php:63-81
    - **Affects:** Any billed ingest effect (Apify actor call, Places fetch) whose initial claim INSERT fails for a transient or structural reason other than a unique-digest violation — the effect is silently refused with no retry and no operator signal, rather than propagating so the caller can retry or escalate.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Catch `\Illuminate\Database\QueryException` specifically and inspect the SQLSTATE (`$e->getCode()` / driver error info) for PostgreSQL's unique-violation code `23505`.
        - Re-throw any other failure (connection timeout `08006`, deadlock `40P01`, not-null violation `23502`, etc.) so it propagates to the job's normal retry/backoff path instead of resolving to `'refused'`.
    - **Technical:** `once()` wraps the claim INSERT in `catch (\Throwable)` and assumes any throwable means "another worker already claimed this digest," then does a lookup SELECT that returns null (no row exists) and reports `'refused'`. But a connection drop, deadlock, or schema mismatch also throws here, and produces the identical `'refused'` verdict with no row ever inserted — the effect silently vanishes with no retry and no record that anything went wrong. `EffectLedger` is the charge-once guard for billed connector fetches (commit `694906b7`, "billed-effect replays carry their data, and recurring fetches re-bill by window" — landed on this same file), so a transient-failure-turned-permanent-silent-refusal here means a paid vendor call that never actually ran is treated the same as one that's mid-flight elsewhere, with nobody able to tell the difference from outside `ingest:effects --resolve`.
    - **Plain English:** The billing guard has a net that's too wide. It's built to catch the one case where two workers try to pay for the same thing at the same time — one should step aside. But the net also catches "the database was briefly unreachable" and treats that identically, silently skipping work that should simply be retried. A finer net that only catches "someone else already claimed this" and lets real errors surface would fix it.
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

- [x] **#WHK-4** · P2 — `EffectLedger` abandoned-effect state is invisible to Nightwatch (breadcrumb-only `Log::warning`)
    - **Category:** 9-equivalent (observability gap on a stuck idempotency anchor — same failure shape the lens calls out for bot-protection fail-open logging).
    - **Where:** app/Ingest/Runtime/EffectLedger.php:141-148
    - **Affects:** Operators — a billed effect abandoned by a dead worker (stale claim past the 900s window) permanently blocks retries of that digest until someone manually runs `ingest:effects --resolve`, and nothing pages anyone to do it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Insert a row into `ingest.anomalies` for the abandoned effect, matching the exact pattern `SourceScheduler::releaseStranded` and `Lander::foldAbsence`'s guard-trip already use for the same "silent-but-real" operational signal.
        - Keep the `Log::warning` as a breadcrumb; the anomaly row (or a thrown/`report()`-ed exception) is what actually reaches Nightwatch/an operator.
    - **Technical:** Per the architecture doctrine, Nightwatch alerts on exceptions and slow jobs/routes only — never on log queries — so `Log::warning('ingest.effect.abandoned', ...)` is invisible until someone tails logs. `SourceScheduler::releaseStranded` and `Lander::foldAbsence` both already write to `ingest.anomalies` for their structurally identical "something silently went wrong and needs a human" cases; `EffectLedger` should follow the same established convention rather than being the one silent exception in the ingest fleet.
    - **Plain English:** When a paid data-fetch job dies mid-flight, the system correctly freezes that billing slot to avoid double-charging. But the only notification is a sticky note in a log file nobody watches. The fix is to raise the same kind of alert the rest of this system already uses elsewhere for "something's stuck and needs a person" — a dashboard row, not just a log line.
    - **Evidence:**
        ```php
        $claimedAt = strtotime((string) $row->claimed_at);
        if ($claimedAt !== false && (time() - $claimedAt) > self::ABANDON_AFTER_SECONDS) {
            // Long-dead claim: mark it so it stops blocking silently and is
            // visible to whoever reconciles spend, but STILL refuse — the
            // vendor may well have charged us for it.
            DB::table('ingest.effects')->where('digest', $row->digest)->update(['status' => 'abandoned']);
            Log::warning('ingest.effect.abandoned', ['digest' => $row->digest, 'kind' => $row->kind]);

            return ['status' => 'abandoned', 'result' => null, 'cached' => true];
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Ingest scheduler/lander race hardening:** #WHK-1, #WHK-2
    - **Why grouped:** Same root-cause pattern (a read-then-write cycle without a re-checked atomic guard) in the two ingest lifecycle files that run every dispatcher tick; fixing them together keeps the "conditional UPDATE as the only race resolution" convention consistent across the pipeline.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#WHK-3 — EffectLedger catch-all `\Throwable` masks failures** · standalone: touches the billed-effect ledger's charge-once correctness (money-adjacent — vendor spend accounting), per doctrine run with its own plan + sign-off even though effort is small.
- **#WHK-4 — EffectLedger abandoned-effect Nightwatch gap** · standalone: same billed-effect ledger file/subsystem as #WHK-3; keep its review isolated from the scheduler/lander bundle so a billing-adjacent change gets its own sign-off.
