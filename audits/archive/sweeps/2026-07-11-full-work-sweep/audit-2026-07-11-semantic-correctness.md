# Semantic Correctness Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Semantic Correctness — code that compiles and type-checks but does the wrong thing (plausible-but-wrong API/config/logic usage)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/User
- app/Services/Site
- app/Services/PublicSite
- app/Services/Cache
- app/Services/Accounts
- app/Services/Auth
- app/Services/FeatureFlags
- app/Services/FeatureAvailability
- app/Services/Segments
- app/Services/EarlyAccess
- app/Support
- app/Contracts
- app/helpers.php
- app/Jobs
- app/Http/Controllers/Api/User
- app/Policies
- app/DTOs

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEM-1** · P1 — `ContentSelectionService::setInstagramAuto` commits the auto-flag and the reserved-slot rebuild as two separate transactions
    - **Where:** app/Services/Site/ContentSelectionService.php:222-241 (flag write), 347-356 (`persist()`)
    - **Affects:** Any user connecting Instagram or toggling Instagram-auto content. A transient DB error inside `persist()` (constraint violation, deadlock, timeout) leaves `content_instagram_auto_enabled = true` already committed while the ig-reel/ig-post slots at positions 1–2 were never written — the sitepage silently renders without the reserved Instagram content, with no error surfaced to the user or caller.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the whole mutation in `setInstagramAuto()` — the `$site->content_instagram_auto_enabled` assignment, `$site->save()`, and the `persist($site, $rows)` call — in a single `DB::connection('pgsql')->transaction(...)`. `persist()`'s own internal `DB::connection('pgsql')->transaction(...)` then nests as a SAVEPOINT under Laravel's transaction-nesting semantics, so `persist()` itself needs no restructuring.
        - Leave `IntegrationConnectionObserver::enableContentInstagramAuto()` (app/Observers/Core/IntegrationConnectionObserver.php:131-147) as-is — it deliberately flips the raw column without reconciling slots, documented as self-healing on the next explicit toggle/edit; that pattern is intentional and out of scope here.
    - **Technical:** Category 4 (logic contradicts intent). `$site->content_instagram_auto_enabled = $enabled; $site->save();` commits immediately at lines 224-225, before the existing `ContentSelection` rows are read and before `$this->persist($site, $rows)` runs its own separate `DB::connection('pgsql')->transaction(...)` at line 349. If `persist()` throws, the flag is durably `true` with no matching ig-* rows — a torn state that only heals on the next call to `setInstagramAuto()` or `replace()`. `resolve()` still serves whatever rows actually exist (no crash), but the positional guarantee the flag is meant to enforce (ig content pinned to slots 1–2) silently doesn't hold, and nothing signals the caller/user that the write partially failed.
    - **Plain English:** Flipping the "auto-fill my Instagram content" switch and actually reserving the two photo slots for it are saved to the database as two separate steps instead of one all-or-nothing step. If the second step trips up — a rare database hiccup — the switch stays "on" but the slots never get set aside, and nobody is told anything went wrong. The fix is to make both steps succeed or fail together, like a single atomic save.
    - **Evidence:**
        ```php
        public function setInstagramAuto(Site $site, bool $enabled): void
        {
            $site->content_instagram_auto_enabled = $enabled;
            $site->save();

            $existing = ContentSelection::query()
                ->where('site_id', $site->id)
                ->orderBy('position')
                ->get();
        ```
        ```php
        private function persist(Site $site, array $rows): void
        {
            DB::connection('pgsql')->transaction(function () use ($site, $rows) {
                ContentSelection::query()->where('site_id', $site->id)->delete();

                foreach ($rows as $row) {
                    ContentSelection::create($row);
                }
            });
        }
        ```

## P3 — Nice to have

- [ ] **#SEM-2** · P3 — `DevInsightsController::CLICK_SECTION_TO_ITEM_TYPE` is a hand-maintained mirror of a private const in the scoring job
    - **Where:** app/Http/Controllers/Api/User/Analytics/DevInsightsController.php:44-56
    - **Affects:** Developers using the `dev-insights` diagnostic endpoint. Currently harmless — verified byte-for-byte identical to the source map — but nothing enforces that identity going forward.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Promote `ComputeContentPopularityScores::CLICK_SECTION_TO_ITEM_TYPE` (app/Console/Commands/ComputeContentPopularityScores.php:105-130) to a shared location both classes reference, or make it `public`/`@internal`.
        - Add a test asserting the two maps are identical so drift fails CI the moment it happens, rather than silently under-reporting clicks on the dev-only endpoint.
    - **Technical:** Category 3 (plausible-but-wrong magic values, drift risk). Confirmed by direct comparison: `DevInsightsController`'s copy (lines 49-56) and `ComputeContentPopularityScores::CLICK_SECTION_TO_ITEM_TYPE` (lines 105-130) match key-for-key and value-for-value today, so there is no active bug. The risk is purely prospective — the const is a "private, not importable" duplication with an explicit "keep in lockstep by hand" comment and no automated guard, so a future edit to one map without the other would silently make the dev-only endpoint under-report `link_clicks` attribution relative to the real scoring job. This is edge-case/dev-tooling-only and currently correct, matching a P3 "harmless-today deviation that will break under a plausible future change," not a live P1/P2 bug.
    - **Plain English:** A settings list for translating "which website section a click came from" into "what kind of item that counts as" is copy-pasted by hand into two different files instead of living in one shared place. Today both copies match perfectly, so nothing is broken. But if someone updates one copy later and forgets the other, the developer diagnostics page would quietly start showing wrong numbers — like two clocks in the same room that currently agree but will drift apart the next time only one gets rewound.
    - **Evidence:**
        ```php
        /**
         * Mirrors ComputeContentPopularityScores::CLICK_SECTION_TO_ITEM_TYPE (a private
         * const there, not importable). Maps a click's section_key → the item_type its
         * clicks score as, so this endpoint can attribute link_clicks to the same item
         * grain the scoring job does. Keep in lockstep by hand.
         */
        private const CLICK_SECTION_TO_ITEM_TYPE = [
            'shop' => 'shop_product', 'shop-products' => 'shop_product', 'shop-tracks' => 'shop_product', 'bandcamp' => 'shop_product',
            'book' => 'service', 'services' => 'service',
            'events' => 'engine_item', 'attend' => 'engine_item',
            'listen' => 'listen_item', 'music' => 'listen_item', 'spotify' => 'listen_item', 'apple-music' => 'listen_item', 'soundcloud' => 'listen_item', 'podcast' => 'listen_item',
            'watch' => 'watch_item', 'youtube' => 'watch_item', 'twitch' => 'watch_item', 'vimeo' => 'watch_item',
            'custom' => 'link_item', 'other' => 'link_item',
        ];
        ```

## Suggested Bundled Sessions

None — the two surviving findings touch unrelated subsystems (content-selection transactions vs. a dev-only analytics const) and neither shares a file, subsystem, or root cause with the other.

## Standalone — do NOT bundle

- **#SEM-1 — Instagram-auto flag/slot transaction gap** · standalone: touches a DB write-path correctness invariant on a user-facing content-selection mutation; run with its own plan + sign-off even though effort is S.
