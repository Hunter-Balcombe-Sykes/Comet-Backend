# AI Slop & Low-Value Code Audit — 2026-07-03

**Branch:** development
**Lens:** AI Slop & Low-Value Code: comment noise, premature abstraction, dead code, defensive cruft, copy-paste drift
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Services/User`
- `app/Services/Media`
- `app/Services/Platforms`
- `app/Services/Feedback`
- `app/Services/Diagnostics`
- `app/Mail`
- `app/Http/Controllers/Api/User`
- `app/Http/Resources`
- `app/Jobs`
- `app/Console`
- `app/Notifications`
- `app/Observers`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 3 complete

---

## P2 — Should fix

- [ ] **#SLOP-1** · P2 — Identical event-sort `usort` closures copy-pasted between EventbriteScraper and HumanitixScraper
    - **Where:** app/Services/Platforms/EventbriteScraper.php:107-121, app/Services/Platforms/HumanitixScraper.php:135-149
    - **Affects:** Any future fix to event date ordering — a bug fix applied to one scraper silently leaves the other with the old (possibly wrong) behavior.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `protected function sortByStartDate(array &$events): void` to the shared `PlatformScraper` base class (both scrapers already extend it) containing this exact closure.
        - Add `use Illuminate\Support\Carbon;` to `PlatformScraper.php` (not currently imported there).
        - Replace both inline `usort(...)` blocks with `$this->sortByStartDate($events);`.
    - **Technical:** The two closures are byte-for-byte identical — same empty-string-as-null handling, same `Carbon::parse()` comparison for timezone-aware ordering. This is a pure function of an `$events` array and both call sites already share `PlatformScraper` as a base class, so hoisting it there isn't a premature abstraction (category 2) — it's removing an existing duplication with a genuine second (already-existing) caller, matching CLAUDE.md's "three similar lines > a premature abstraction" in reverse: 15 identical lines duplicated across two files is the real maintenance risk here, not the one-line shared-helper call that replaces them. Note this is distinct from `EventsCatalog::sortEvents()`, which deliberately sorts dateless events *last* rather than *first* (different display context) — do not fold that one into the same helper.
    - **Plain English:** The exact same "how to sort events by date" recipe is written out twice, word for word, in two different files. If someone later finds a mistake in that recipe — say, events with no date sort in the wrong place — and only fixes it in one file, the other file quietly keeps making the same mistake. Writing the recipe once and having both files use it means a fix only ever needs to happen in one place.
    - **Evidence:**
        ```php
        // EventbriteScraper.php — fetchEvents()
        usort($events, function ($a, $b) {
            $aDate = $a['startDate'] ?? '';
            $bDate = $b['startDate'] ?? '';
            if ($aDate === '' && $bDate === '') {
                return 0;
            }
            if ($aDate === '') {
                return -1;
            }
            if ($bDate === '') {
                return 1;
            }

            return Carbon::parse($aDate)->getTimestamp() <=> Carbon::parse($bDate)->getTimestamp();
        });

        // HumanitixScraper.php — fetchEvents() — identical
        usort($events, function ($a, $b) {
            $aDate = $a['startDate'] ?? '';
            $bDate = $b['startDate'] ?? '';
            if ($aDate === '' && $bDate === '') {
                return 0;
            }
            if ($aDate === '') {
                return -1;
            }
            if ($bDate === '') {
                return 1;
            }

            return Carbon::parse($aDate)->getTimestamp() <=> Carbon::parse($bDate)->getTimestamp();
        });
        ```

- [ ] **#SLOP-2** · P2 — `dispatchImageJob` and `dispatchLogoJob` are near-identical copies of the same inline/queue/fallback dispatch pattern
    - **Where:** app/Services/Media/MediaUploadService.php:379-424 (`dispatchImageJob`), :454-502 (`dispatchLogoJob`)
    - **Affects:** Any future fix to the dispatch/fallback contract (e.g. a race condition, a retry-policy change) — patched in one method, the other method silently keeps the old behavior.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract a private `dispatchWithSyncFallback(string $jobClass, array $args, string $label): void` containing the inline-check / `dispatchSync` / `dispatch`-with-fallback logic once, using named-argument array spread (`$jobClass::dispatch(...$args)`, PHP 8.1+) so each job's distinct constructor arguments still bind correctly.
        - Call it from both `dispatchImageJob` (`ProcessImageVariantsJob::class`) and `dispatchLogoJob` (`ProcessLogoVariantsJob::class`), passing each job's own arg array and a log label (`'image'` / `'logo'`).
        - Re-run `tests/Feature/Jobs/ProcessImageVariantsJobTest.php`, `tests/Feature/Jobs/ProcessLogoVariantsJobTest.php`, and `tests/Unit/MediaJobReliabilityTest.php` — all three currently exercise this dispatch path.
    - **Technical:** Both methods implement the identical algorithm — check `queue.default`/environment for inline processing, `dispatchSync`, else `dispatch` with a `dispatchSync` fallback on failure, logging at each branch — differing only in job class, argument list, and log-message wording. `dispatchLogoJob`'s own docblock says it "mirrors dispatchImageJob," confirming the duplication is a known, intentional structural copy rather than an unrelated coincidence — which is exactly the case where a shared helper removes risk instead of adding abstraction: there's no premature generalization here, just two already-existing call sites doing the same thing. `dispatchVideoJob` is correctly NOT merged into this — its contract is "throws on failure, caller rolls back" (per its own docblock), a genuinely different failure-mode contract, not drift.
    - **Plain English:** Two different upload paths (photo vs. logo) use the exact same "try the fast lane, fall back to the slow lane if it fails" routine, written out separately with only the labels changed. If someone finds and fixes a problem with that routine for photos, the logo path keeps the old, unfixed version — nobody would think to check it since it looks like separate code. Writing the routine once and having both paths call it means one fix covers both.
    - **Evidence:**
        ```php
        private function dispatchImageJob(string $imageId, string $originalPath, string $basePath): void
        {
            $queueConnection = (string) config('queue.default', 'sync');
            $processInline = in_array(app()->environment(), ['local', 'testing'], true)
                || $queueConnection === 'sync';

            if ($processInline) {
                try {
                    ProcessImageVariantsJob::dispatchSync(
                        originalPath: $originalPath,
                        imageId: $imageId,
                        basePath: $basePath,
                    );
                } catch (Throwable $e) {
                    Log::error('Inline image variant processing failed.', [
                        'image_id' => $imageId, 'error' => $e->getMessage(),
                    ]);
                }

                return;
            }
        ```
        ```php
        /**
         * Logo dispatch — mirrors dispatchImageJob (best-effort, inline in local/testing,
         * sync fallback on queue failure). Never throws: a dispatch failure leaves the
         * row PENDING and surfaces via the processing_state poll.
         */
        private function dispatchLogoJob(string $mediaId, string $originalPath, string $basePath, string $siteId): void
        {
            $queueConnection = (string) config('queue.default', 'sync');
            $processInline = in_array(app()->environment(), ['local', 'testing'], true)
                || $queueConnection === 'sync';

            if ($processInline) {
                try {
                    ProcessLogoVariantsJob::dispatchSync(
                        originalPath: $originalPath,
                        imageId: $mediaId,
                        basePath: $basePath,
                        siteId: $siteId,
                    );
                } catch (Throwable $e) {
                    Log::error('Inline logo processing failed.', [
                        'image_id' => $mediaId, 'error' => $e->getMessage(),
                    ]);
                }

                return;
            }
        ```

## P3 — Nice to have

- [ ] **#SLOP-3** · P3 — Commented-out code left in `PruneNotifications`
    - **Where:** app/Console/Commands/PruneNotifications.php:24-25
    - **Affects:** Developers reading the prune logic — the dead line misleads about whether policy-update notifications get special retention treatment.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the `// Optional: keep policy updates longer` comment and the commented-out `// $q->where('type', '!=', 'policy_update');` line.
        - If policy-update retention genuinely needs special handling, track it as a real ticket/config flag instead of a dormant code comment.
    - **Technical:** This is commented-out code (category 3) with no ticket, owner, or explanation of why it's disabled rather than deleted — it just sits between the query builder and its execution, leaving a reader unsure whether it was deliberately disabled, forgotten, or a half-finished feature.
    - **Plain English:** Imagine a sticky note on a machine saying "optional: different setting" with the switch taped down. Nobody can tell if it was left there on purpose or by accident. The fix is to remove the note — if the different setting mattered, it would be a real, working switch, not a comment.
    - **Evidence:**
        ```php
        // Optional: keep policy updates longer
        // $q->where('type', '!=', 'policy_update');
        ```

- [ ] **#SLOP-4** · P3 — Restating and decorative section-header comments in `UserCustomerController`
    - **Where:** app/Http/Controllers/Api/User/Customers/UserCustomerController.php:142, :149, :163
    - **Affects:** Developers reading the controller — the comments add scan noise without adding meaning beyond the method name/code they sit above.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the decorative section-header comments `// Archive Soft Delete` (above `destroy()`) and `// Restore (un-archive)` (above `restore()`) — the method names already say this.
        - Remove the inline restatement comment `// soft delete (archive)` on `$customer->delete();` — the `SoftDeletes` behavior is the framework's documented contract, not something this line needs to re-explain.
    - **Technical:** These are comments that restate the very next line/method signature, which CLAUDE.md's Commenting section explicitly calls out to avoid ("Avoid: ... comments that just restate the next line, decorative banners"). A `destroy()` method on a soft-deleting model doesn't need a banner announcing it archives; `$customer->delete()` doesn't need an inline note that it's a soft delete — both are already obvious from the method name and the model's known `SoftDeletes` trait.
    - **Plain English:** These comments are like labelling a door "Door — opens inward" right next to a handle that already makes that obvious. Removing the label doesn't confuse anyone; it just declutters the hallway.
    - **Evidence:**
        ```php
        // Archive Soft Delete
        public function destroy(Request $request, Customer $customer)
        ```
        ```php
        $customer->delete(); // soft delete (archive)
        ```
        ```php
        // Restore (un-archive)
        public function restore(Request $request, Customer $customer): JsonResponse
        ```

- [ ] **#SLOP-5** · P3 — Decorative section-divider comments in Platforms scrapers
    - **Where:** app/Services/Platforms/AppleSearch.php:81, app/Services/Platforms/EventsCatalog.php:217, :302
    - **Affects:** Maintainers reading the files — one-line visual dividers with no navigational value beyond what a blank line already gives.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the `// ── internals ──…` divider in AppleSearch.php.
        - Delete the `// ── Storage ──…` and `// ── Helpers ──…` dividers in EventsCatalog.php.
    - **Technical:** CLAUDE.md's Commenting section explicitly lists "decorative banners" under what to avoid. This exact single-line-divider pattern (`// ── Label ──…`) is used consistently across several files in `app/Services/Platforms` (also seen in `GoogleBusinessAutoSync.php`, `WooCommerceScraper.php`, `ShopifyScraper.php`) rather than being an isolated one-off — so this is best read as an established local convention for this service folder, not stray AI-generated noise. It's still a written-rule violation and adds nothing a blank line before the private-method group doesn't already convey, but it's genuinely low-stakes: fix opportunistically when touching these files rather than as a dedicated pass.
    - **Plain English:** These are like a "Kitchen →" sign taped up in a one-bedroom apartment — the layout already makes the room obvious, so the sign is just visual clutter. It's harmless, but there's no reason to keep adding new signs like it.
    - **Evidence:**
        ```php
        // AppleSearch.php
        // ── internals ────────────────────────────────────────────────
        ```
        ```php
        // EventsCatalog.php
        // ── Storage ───────────────────────────────────────────────────────────
        ```
        ```php
        // EventsCatalog.php
        // ── Helpers ─────────────────────────────────────────────────────────────
        ```

## Suggested Bundled Sessions

- **Bundle 1 — De-duplicate copy-pasted dispatch/sort logic:** #SLOP-1, #SLOP-2
    - **Why grouped:** Same root-cause pattern (identical logic copy-pasted across two call sites, drift risk) even though the files differ (Platforms scrapers vs. Media service) — same fix shape (extract to an existing shared base/private method) and same review checklist (verify behavior unchanged via existing tests).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Comment-noise cleanup:** #SLOP-3, #SLOP-4, #SLOP-5
    - **Why grouped:** All are comment-only deletions (dead code / restating / decorative banners) with zero behavioral change — safe to knock out in one pass across the three files.
    - **Model:** Plan+Implement: Sonnet (combine — trivial S-effort deletions) · Review: Sonnet.

## Standalone — do NOT bundle

None.
