# Semantic Correctness Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Semantic Correctness — code that compiles and type-checks but does the wrong thing (real-method-wrong-contract, config/flag misuse, magic-value drift, logic-contradicting-intent, codebase-idiom drift)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/PublicSite/Actions/ActionVocabulary.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Services/Analytics/AnalyticsEvent.php
- app/Services/Analytics/RankedActionsComputer.php
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Requests/Api/PublicSite/Analytics/ActionSeenRequest.php
- app/Http/Requests/Api/PublicSite/Analytics/ActionTapRequest.php
- app/Http/Requests/Concerns/SiteOrderingValidationRules.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Models/Analytics/ActionEvent.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/PurgeRawAnalyticsEvents.php
- config/partna.php
- routes/api.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 2 complete

---

## P3 — Nice to have

- [ ] **#SEM-1** · P3 — Hardcoded `'Reservations'` label bypasses the single-source-of-truth vocabulary used by every other static action in `pool()`
    - **Where:** app/Services/PublicSite/SiteActionsService.php:118
    - **Affects:** Maintainability only — a future label change in `ActionVocabulary::LABELS` silently leaves the reservations action displaying the old label. No user-visible bug today.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the hardcoded `'Reservations'` string with `ActionVocabulary::labelFor('reservations')`.
    - **Technical:** Every other static action in `pool()` resolves its label via `ActionVocabulary::labelFor($actionId)` — confirmed at lines 123, 128, 132, 139, 143, 148, 152, 171 (page/booking/platform actions). The reservations entry at line 118 is the sole outlier, hardcoding the string literally. `ActionVocabulary::LABELS['reservations']` (ActionVocabulary.php:36) is also `'Reservations'` today, so both paths agree — but the vocabulary class exists specifically as the single source of truth (its docblock: "LOCKSTEP with apps/pages test/actions-vocabulary.test.ts"), and this line silently opts out of that contract.
    - **Plain English:** Imagine a restaurant where every menu item's price is looked up from a central price list, except one item has its price handwritten on the chalkboard. Today both say the same thing, but if the price list is ever updated, the chalkboard item stays stale because nobody remembers to update it by hand.
    - **Evidence:**
        ```php
        $out[] = $this->entry('reservations', 'external', 'Reservations', url: $url, createdAt: $reservation->created_at);
        ```

- [ ] **#SEM-2** · P3 — `analytics:compute-popularity --dry-run` always prints "0 rows written; 0 rows deleted" in its final summary, even though per-site output is correct
    - **Where:** app/Console/Commands/ComputeContentPopularityScores.php:194-198, 208, 219, 224-232
    - **Affects:** Operator readability during `--dry-run` invocations only. No production data impact — the counters that guard the real write path (`$rowsWritten`/`$rowsDeleted`) are untouched.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the dry-run branch (line 194-198), before `continue`, increment `$rowsWritten += count($rows)` and `$rowsDeleted += count($deletes, COUNT_RECURSIVE)` (or equivalent) so the aggregate reflects what `reportDryRun()` already printed per site.
    - **Technical:** `handle()` only increments `$rowsWritten` (line 208) and `$rowsDeleted` (line 219) inside the non-dry-run branch, both unreachable when `$dryRun` is true because line 194-198's `if ($dryRun) { $this->reportDryRun(...); continue; }` skips straight past them. The final `sprintf` at lines 224-232 therefore always reports `0` for "would write"/"would delete" during a dry run, while `reportDryRun()` (lines 645-668) correctly prints the real per-site row/delete counts. The aggregate line is cosmetically wrong; nothing downstream consumes it.
    - **Plain English:** Running this command with `--dry-run` prints a detailed, accurate list of what would change for each site — but the one-line total at the very end always says "0 rows" regardless of what was actually listed above it, like a receipt whose itemized lines are correct but whose total always reads $0.
    - **Evidence:**
        ```php
        if ($dryRun) {
            $this->reportDryRun($site, $rows, $deletes);

            continue;
        }

        if ($rows !== []) {
            DB::connection('pgsql')
                ->table('analytics.content_popularity_scores')
                ->upsert(...);
            $rowsWritten += count($rows);
        }

        foreach ($deletes as $contentType => $keys) {
            ...
            $rowsDeleted += count($keys);
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — P3 polish pass:** #SEM-1, #SEM-2
    - **Why grouped:** Both are isolated, S-effort (~0.5–1h), non-interacting cosmetic fixes surfaced by this audit with zero behavioral risk — efficient to batch into one polish session despite touching different subsystems (action pool vs. scoring command).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (no escalation needed — both are one-line/few-line changes).

## Standalone — do NOT bundle

None.
