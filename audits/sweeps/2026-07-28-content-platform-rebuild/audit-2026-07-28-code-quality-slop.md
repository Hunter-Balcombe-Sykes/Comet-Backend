# AI Slop & Low-Value Code Audit — 2026-07-28

**Branch:** development
**Lens:** AI Slop & Low-Value Code: comment noise, premature abstraction, dead code, defensive cruft, copy-paste drift (bundle scan across services, controllers, requests, resources, jobs/observers, policies, wiring, catalog, routing, ingest, content)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/Platforms/ConditionalContext.php, GoogleBusinessApifyScraper.php, WebsiteLinkHarvester.php
- app/Services/Analytics/AnalyticsQueryService.php
- app/Services/Accounts/AccountCapabilities.php
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php, InstagramController.php, ShopController.php
- app/Http/Controllers/Concerns/DetectsClientInfo.php
- app/Http/Controllers/Api/Site/SectionController.php, SectionItemController.php, SectionGroupController.php, PageController.php
- app/Http/Requests/Api/User/Sections/SectionRuleRules.php
- app/Models/Core/Site/SiteMedia.php, IntegrationConnection.php
- app/Policies/SectionPolicy.php, DesignKitRestylePolicy.php (+ sibling policies for pattern-consistency check)
- app/Routing/PlacementPolicy.php, LinkProjector.php, Iri.php, Probes/{Shopify,Squarespace,WooCommerce}StorefrontProbe.php
- app/Ingest/Connectors/{Doordash,Square,UberEats}MenuConnector.php, InstagramConnector.php, EventbriteConnector.php, AppleMusicConnector.php, ApplePodcastsConnector.php
- app/Ingest/Support/YoutubeFeed.php
- (remaining scoped directories reviewed clean — see chunk notes below)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 3 complete
- P3 Low: 0 of 19 complete

---

## P2 — Should fix

- [ ] **#SLOP-1** · P2 — `topSections()` logs the wrong failure key, drifted from the pre-v2 click-query catch blocks
    - **Where:** app/Services/Analytics/AnalyticsQueryService.php:298 (catch block inside `topSections()`, which queries `analytics.section_views` at line 280)
    - **Affects:** On-call debugging of a `section_views` outage — the log stream will point at `link_clicks` instead.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the log key in `topSections()`'s catch block from `'analytics.click_query_failed'` to `'analytics.section_query_failed'`.
        - No other method in the file has this mismatch — `topPages()` (also queries `section_views`) already logs `'analytics.page_query_failed'` correctly, confirming this is an isolated copy-paste leftover, not a pattern to sweep further.
    - **Technical:** `topSections()` queries `analytics.section_views` but its `catch (QueryException $e)` block logs `'analytics.click_query_failed'` — the key used by every *click*-side method in this file (`clickSummary`, `visitsByBucket`'s click path, `topLinks`, `platformClicks`, `topProducts`). `topPages()`, added later and querying the same `section_views` table, correctly logs `'analytics.page_query_failed'`. This is copy-paste drift: `topSections()`'s catch was never updated to match its own table.
    - **Plain English:** Imagine every till in a shop prints a receipt naming which till it came from — except one till that was relabelled during a refit and still prints its old number. If that till breaks, the technician gets sent to the wrong spot. Same here: if the "section views" data table has a problem, the error log blames "link clicks" instead, costing an on-call engineer real time during an incident.
    - **Evidence:**
        ```php
        public function topSections(string|array|null $userScope, Carbon $from, Carbon $to): Collection
        {
            try {
                return $this->scopedTable('analytics.section_views', $userScope)
                    ->whereBetween('occurred_at', [$from, $to])
                    ->selectRaw("section_key, COUNT(*) as views, COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as unique_viewers")
                    ->groupBy('section_key')
                    ->orderByDesc('views')
                    ->limit(12)
                    ->get()
                    // ...
            } catch (QueryException $e) {
                Log::warning('analytics.click_query_failed', ['method' => __METHOD__, 'user_id' => $this->scopeForLog($userScope), 'error' => $e->getMessage()]);

                return collect();
            }
        }
        ```

- [x] **#SLOP-2** · P2 — `firstString` copy-pasted across four connectors, and it has already drifted: two lack the numeric fallback the other two have
    - **Where:** app/Ingest/Connectors/DoordashMenuConnector.php:148-158, SquareMenuConnector.php:154-164, UberEatsMenuConnector.php:138-148, InstagramConnector.php:258-269
    - **Affects:** Square and Uber Eats menu ingestion — any field where the vendor payload returns a number for what the connector expects as a string.
    - **Effort:** S (~1h)
    - **What to do:**
        - Add the `is_numeric($value)` fallback branch to `SquareMenuConnector::firstString` and `UberEatsMenuConnector::firstString` so all four match.
        - Longer-term, only worth a shared helper if a fifth connector needs the same lookup — for now, backfilling the missing branch is the smaller, lower-risk fix.
    - **Technical:** `DoordashMenuConnector::firstString` and `InstagramConnector::firstString` both fall back to `is_numeric($value) ? (string) $value : ...` when no key yields a non-empty string; `SquareMenuConnector::firstString` and `UberEatsMenuConnector::firstString` do not — they were copied before that branch was added and never backfilled. If Square or Uber Eats ever returns a numeric `item_id`/name for a mapped field, the value is silently treated as absent and the record is dropped, with no error surfaced.
    - **Plain English:** Four menu-import helpers all do the same job — "grab the first usable text value from a few possible field names" — but two of them forgot to handle the case where the vendor sends a number instead of text. If that happens, the menu item just vanishes instead of importing, and nobody is told why.
    - **Evidence:**
        ```php
        // DoordashMenuConnector — HAS numeric fallback
        private function firstString(array $item, array $keys): ?string
        {
            foreach ($keys as $key) {
                $value = $item[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
                if (is_numeric($value)) {
                    return (string) $value;
                }
            }
            return null;
        }

        // SquareMenuConnector — NO numeric fallback
        private function firstString(array $item, array $keys): ?string
        {
            foreach ($keys as $key) {
                $value = $item[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
            return null;
        }
        ```

- [ ] **#SLOP-3** · P2 — `ownerMatches` duplicated verbatim between `SectionPolicy` and `DesignKitRestylePolicy`, with the explanatory comment only in one copy
    - **Where:** app/Policies/SectionPolicy.php:58-76 and app/Policies/DesignKitRestylePolicy.php:48-63
    - **Affects:** Authorization correctness for Section and DesignKitRestyle resources — any hardening fix or edge-case correction applied to one policy's ownership check must be manually replicated in the other.
    - **Effort:** S (~1h)
    - **What to do:**
        - Move the shared logic into `BasePolicy` as a `protected function ownsThroughSite(User $actor, Model $resource): bool`, keeping the `getAttributes()`-vs-magic-getter explanatory comment in that single canonical location.
        - Call it from both `SectionPolicy` and `DesignKitRestylePolicy`.
    - **Technical:** Both policies resolve ownership identically: pull the pre-set `site` relation, cross-check the resource's raw `site_id` (via `getAttributes()`, not the Eloquent accessor) against the site's `id`, then compare the site's `user_id` to the actor. `SectionPolicy` carries a comment explaining why `getAttributes()` is used instead of the magic getter (avoids triggering lazy-loading); `DesignKitRestylePolicy`'s copy has no such comment — proof the two have already drifted once. Since this is authorization logic, marked standalone below rather than bundled with the general dedup pass.
    - **Plain English:** Two different rooms use the exact same lock mechanism, but only one has the installation notes taped up explaining a subtlety of how it works. If a locksmith needs to tighten the lock later, they might fix the documented room and forget the other one exists — and this lock happens to be the one that decides who's allowed into someone's site data.
    - **Evidence:**
        ```php
        // SectionPolicy.php:58-76
        private function ownerMatches(User $actor, Model $resource): bool
        {
            $site = $resource->getRelation('site');
            if ($site === null) {
                return false;
            }
            // getAttributes() reads the raw attribute array without triggering
            // Eloquent's magic getter, so a resource whose site_id was never set
            // reads as null rather than lazily loading something.
            $resourceSiteId = $resource->getAttributes()['site_id'] ?? null;
            if ($resourceSiteId === null || (string) $resourceSiteId !== (string) $site->id) {
                return false;
            }
            return (string) $site->user_id === (string) $actor->id;
        }
        ```
        ```php
        // DesignKitRestylePolicy.php:48-63 — identical logic, no comment
        private function ownerMatches(User $actor, Model $resource): bool
        {
            $site = $resource->getRelation('site');
            if ($site === null) {
                return false;
            }
            $resourceSiteId = $resource->getAttributes()['site_id'] ?? null;
            if ($resourceSiteId === null || (string) $resourceSiteId !== (string) $site->id) {
                return false;
            }
            return (string) $site->user_id === (string) $actor->id;
        }
        ```

## P3 — Nice to have

- [ ] **#SLOP-4** · P3 — Comment claims "exact same 7 keys" but the constant now has 21
    - **Where:** app/Services/Platforms/WebsiteLinkHarvester.php:326-328 (comment above the `SOCIAL_PLATFORM[$key]` lookup inside `classify()`)
    - **Affects:** Developers reading the classification path; the stale count understates how large the two hand-maintained constants (`SOCIAL_HOSTS`, `SOCIAL_PLATFORM`) actually are.
    - **Effort:** S (~0.5h)
    - **What to do:** Drop the number — "SOCIAL_PLATFORM is hand-maintained with the exact same keys as SOCIAL_HOSTS" states the invariant without a count that will drift again.
    - **Technical:** The comment was accurate when `SOCIAL_HOSTS`/`SOCIAL_PLATFORM` had 7 entries; the 2026-07-25 social-platform expansion (marked inline as `// Expanded 2026-07-25 — link classification consolidation`) grew both constants to 21 keys each without updating this comment. The sync invariant still holds — just not the number.
    - **Plain English:** A note by a filing cabinet says "exactly 7 folders inside." Fourteen more folders were added last month and the note was never updated. The fix is to stop quoting a number that will only go stale again.
    - **Evidence:**
        ```php
            // No isset() guard needed: SOCIAL_PLATFORM is hand-maintained with the
            // exact same 7 keys as SOCIAL_HOSTS, so the lookup below can never
            // miss for a $key drawn from this loop.
            if (preg_match($pattern, $host) && $this->looksLikeProfile($key, $url)) {
                [$platform, $label] = self::SOCIAL_PLATFORM[$key];
        ```

- [ ] **#SLOP-5** · P3 — Docblock `@return` description pushed ~130 characters right by literal space padding
    - **Where:** app/Ingest/Support/YoutubeFeed.php:20-21
    - **Affects:** Developers reading the docblock; cosmetic only.
    - **Effort:** S (~0.25h)
    - **What to do:** Move the trailing description onto its own normally-indented line, or fold it onto the `@return` line.
    - **Technical:** An editor auto-wrap artifact left the continuation text column-padded rather than left-aligned. Pure formatting noise, not a comment error.
    - **Plain English:** A sticky note's second line is taped six inches to the right of the first for no reason. Still readable, just distracting — move it back.
    - **Evidence:**
        ```php
             * @return list<array{id: string, title: string, url: string, published: ?string, thumbnail: ?string, channel_title: ?string}>|null
             *                                                                                                                                  null when the body does not parse as XML
             */
        ```

- [ ] **#SLOP-6** · P3 — Docblock on `MAX_VALUES_PER_PREDICATE` references an unrelated constant instead of explaining the value it sits on
    - **Where:** app/Http/Requests/Api/User/Sections/SectionRuleRules.php:23-24
    - **Affects:** Developers reading the validation trait — the comment misdirects rather than clarifies.
    - **Effort:** S (~0.5h)
    - **What to do:** Replace the docblock with a one-liner that actually explains the cap (e.g. "Arbitrary safety ceiling per predicate — not tied to the operator count.") or delete it.
    - **Technical:** The docblock "Matches the operator set — see RuleOperator (seven, deliberately)" talks about the seven-operator set, not about why the per-predicate value cap is 20 — the two numbers are unrelated. This reads like a comment copy-pasted from near the `RuleOperator` enum and left in place above a different constant.
    - **Plain English:** A jar is labeled "matches the fork drawer — see spoons (seven, deliberately)" but the jar actually holds 20 cookies. The label talks about something else entirely and tells you nothing about what's in the jar.
    - **Evidence:**
        ```php
        /** Matches the operator set — see RuleOperator (seven, deliberately). */
        private const MAX_VALUES_PER_PREDICATE = 20;
        ```

- [ ] **#SLOP-7** · P3 — Hedging clause tacked onto an otherwise-useful comment
    - **Where:** app/Services/Accounts/AccountCapabilities.php:46-50
    - **Affects:** Maintainers reading `individualCapabilities()`.
    - **Effort:** S (~0.5h)
    - **What to do:** Keep the first two lines (the genuinely non-obvious "NULL sector reads as not-food" contract); drop the "Irrelevant for partna" sentence — a reader can see that from the ternaries on the next few lines.
    - **Technical:** The comment's first half documents a real, non-obvious default (NULL sector ⇒ not-food) that is load-bearing and should stay. The second half ("Irrelevant for partna: none of the four food-derived flags below branch on it for a partna account") is the author thinking out loud after noticing the ternaries below don't consult `$isFood` when `$isBusiness` is false — it restates what `$isBusiness ? $isFood : true` already shows.
    - **Plain English:** A recipe note says "chop the onions" — useful — then adds "by the way, these onions don't even end up in the dish if you're not making the meat version." The second half is the cook explaining their own recipe back to you after the fact.
    - **Evidence:**
        ```php
        // NULL sector reads as not-food (booking-only default) for a business
        // until an industry is picked (Settings) or synced (Google) — see
        // SectorTaxonomy::isFood(). Irrelevant for partna: none of the four
        // food-derived flags below branch on it for a partna account.
        $isFood = SectorTaxonomy::isFood($pro->sector);
        ```

- [ ] **#SLOP-8** · P3 — Hedge comment defers a log-level change to an undefined "once settled"
    - **Where:** app/Services/Platforms/GoogleBusinessApifyScraper.php:85-87 (preceding the `Log::info('google_business.apify.keys', ...)` call)
    - **Affects:** Production log volume — this `Log::info` fires on every Apify run indefinitely, with no plan to demote it.
    - **Effort:** S (~0.5h)
    - **What to do:** Either commit to keeping this as a permanent `info`-level diagnostic (drop the hedge sentence), or demote it to `debug` now — don't leave "once settled" undefined.
    - **Technical:** "Drop to debug once settled" has no ticket, owner, or definition of "settled." Absent one of those, it will never trigger and this diagnostic log will run at `info` level on every Apify scrape forever.
    - **Plain English:** A sticky note on a dashboard says "move this gauge to the back room later," with no date. It stays on the front dashboard forever because "later" never arrives.
    - **Evidence:**
        ```php
        // First-run visibility: which of the fields we care about actually came
        // back, so the mapping can be tuned against real listings without
        // dumping the whole (large) item. Drop to debug once settled.
        Log::info('google_business.apify.keys', [
        ```

- [ ] **#SLOP-9** · P3 — Stale "V2" draft marker on a production trait
    - **Where:** app/Http/Controllers/Concerns/DetectsClientInfo.php:7
    - **Affects:** Maintainers reading the file; no runtime impact.
    - **Effort:** S (~0.25h)
    - **What to do:** Delete the "V2:" prefix — the trait name and its methods already describe what it does.
    - **Technical:** A version tag with no "V1" anywhere in the codebase and no changelog reference raises unanswerable questions (what changed? when?) rather than conveying information.
    - **Plain English:** A sticky note on a published document says "draft 2." Once it's shipped, the note only makes people wonder if there's a newer version somewhere.
    - **Evidence:**
        ```php
        // V2: Detects client country code from CDN headers (Cloudflare, CloudFront, Vercel) and device type from user agent strings.
        trait DetectsClientInfo
        ```

- [ ] **#SLOP-10** · P3 — Multiple comments in `DetectsClientInfo` restate the very next line
    - **Where:** app/Http/Controllers/Concerns/DetectsClientInfo.php:10-11 (docblock restates method name), 21-23 (CDN-name comments restate self-evident header constants), 78 (docblock restates method name, and has a grammar error — "devices type"), 88, 93, 98 (inline comments restate the literal return value directly below each)
    - **Affects:** Maintainers skimming this file; no runtime impact.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the `detectCountryCode` docblock (10-11) and `detectDeviceType` docblock (77-79) — the signatures already say what they do.
        - Delete the `// Cloudflare` / `// AWS CloudFront` / `// Vercel` trailing comments (21-23) — the header constant names (`CF-IPCountry`, `CloudFront-Viewer-Country`, `X-Vercel-IP-Country`) already say the vendor.
        - Delete the `// Bots` / `// Tablet` / `// Mobile` comments (88, 93, 98) — each sits directly above a `return 'bot'/'tablet'/'mobile'` statement.
    - **Technical:** Six separate instances of the same pattern in one file: a comment or docblock that adds zero information beyond what the very next line (a method signature, a header constant, or a return statement) already states. None documents a non-obvious contract, edge case, or magic value — the genuinely useful comment in this file (the `X-Visitor-Country` priority rationale at line 15-18, and the PRIV-9 rounding note at 197-199) is left untouched.
    - **Plain English:** Six sticky notes in the same file each say "this line does what it says" — a label on a can of tomatoes that reads "tomatoes." Harmless individually, but six of them in one short file is exactly the "don't drown files in comments" pattern the house style warns against.
    - **Evidence:**
        ```php
        /**
         * Detect country code from CDN headers (Cloudflare, CloudFront, Vercel).
         */
        protected function detectCountryCode(Request $request): ?string
        ```
        ```php
            ?? $request->header('CF-IPCountry') // Cloudflare
            ?? $request->header('CloudFront-Viewer-Country') // AWS CloudFront
            ?? $request->header('X-Vercel-IP-Country'); // Vercel
        ```
        ```php
        /**
         * Detect devices type from user agent.
         */
        protected function detectDeviceType(?string $ua): ?string
        ```
        ```php
            // Bots
            if (str_contains($u, 'bot') || str_contains($u, 'spider') || str_contains($u, 'crawler')) {
                return 'bot';
            }
        ```

- [ ] **#SLOP-11** · P3 — `ConditionalContext`'s class docblock buries its contract under a 20-line integration guide and per-platform audit
    - **Where:** app/Services/Platforms/ConditionalContext.php:7-43 (class-level comment)
    - **Affects:** Developers reading the class to understand its contract before extending a new fetch strategy.
    - **Effort:** S (~1h)
    - **What to do:**
        - Keep the first two paragraphs (what the class is, the flow, graceful degradation) as the class docblock.
        - Move the "Opting in a further single-GET strategy" step list and the "Ready candidates" / "NOT candidates" per-platform audit into a plan or architecture doc — that content is a point-in-time integration audit, not the class's permanent contract.
    - **Technical:** The docblock mixes two different kinds of information: the class's actual contract (lines 7-23, genuinely load-bearing — explains the optional out-param pattern and graceful degradation) and a step-by-step "how to wire a new caller" guide plus a per-platform candidate/non-candidate rationale (lines 25-43) that will go stale the moment one of those "deferred" strategies is actually wired or the "NOT candidates" list changes. That second half is a plan-document, not a class contract.
    - **Plain English:** A recipe card lists the ingredients, then spends the rest of the page cataloguing which other recipes could reuse the technique and which definitely can't. Useful information, wrong place — it makes the card slower to read every time and will be wrong the day one of those "not yet" recipes gets made.
    - **Evidence:**
        ```php
        // Opting in a further single-GET strategy (no spine change needed):
        //   1. Give the scraper's GET method an optional `?ConditionalContext $cond = null`
        //      last param; merge `$cond?->headers() ?? []` into the request headers; after
        //      the fetch, `if ($cond !== null && $cond->handle($res)) return null;` before
        //      the existing status/null guard.
        // Ready candidates (all route through SafeUrlFetcher::tryFetch), deferred only to
        // bound this plan's blast radius — NOT because the upstream is unsuitable:
        //   • TwitchFetch (single HTML GET)                     — 1 call
        //   • StravaFetch (club page; ignore the optional image probe on a 304)
        // NOT candidates: iTunes (already app-cached, Plan 4), Google Places (billed, raw
        // Http::, 6-day gated), the menu (Apify actor — no HTTP validator; #CACHE-1 stays
        // Bundle C), and any strategy whose payload needs >1 upstream call.
        ```

- [ ] **#SLOP-12** · P3 — Decorative ASCII-art banner comments across the Platforms controllers
    - **Where:** app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php:388, 434; app/Http/Controllers/Api/Platforms/InstagramController.php:366; app/Http/Controllers/Api/Platforms/ShopController.php:924
    - **Affects:** Maintainers reading these files — the dash-padding adds nothing the following code doesn't already signal.
    - **Effort:** S (~0.5h)
    - **What to do:** Replace each `// ── Section Name ──────...` banner with a plain `// Section Name` — keep the substantive content underneath each banner (the PWL-16 register list and the multi-account explanation are genuinely load-bearing and stay).
    - **Technical:** CLAUDE.md explicitly names "decorative banner comments... ASCII art separators" as low-value. These four instances add horizontal-rule padding with zero semantic value beyond the section label itself.
    - **Plain English:** Like hanging a giant "———— KITCHEN ————" banner between rooms that are already clearly a kitchen and a hallway. The label helps; the decorative border around it doesn't.
    - **Evidence:**
        ```php
        // ── Deliberately NOT locked (PWL-16 register) ────────────────────────
        ```
        ```php
        // ── Multi-account support ────────────────────────────────────────────
        ```
        ```php
        // ── internals ────────────────────────────────────────────────
        ```

- [ ] **#SLOP-13** · P3 — Decorative section-banner comments in `SiteMedia`
    - **Where:** app/Models/Core/Site/SiteMedia.php:193-194, 245-246, 259-260
    - **Affects:** Maintainers reading the model; no runtime impact.
    - **Effort:** S (~0.5h)
    - **What to do:** Delete the three ASCII-rule banner pairs (`/* ---...--- */` + `/* Section */`). Method visibility and adjacency already group `booted()`, the relations, and the helpers.
    - **Technical:** Same CLAUDE.md rule as SLOP-12 — `booted()` is self-evidently a lifecycle hook, `site()`/`mediaVariants()` are self-evidently relationships, and the trailing helpers are self-evidently helpers. Nine lines of pure decoration.
    - **Plain English:** A "Kitchen" sign on a kitchen door that already has a window showing the stove. The sign adds a moment of reading, not information.
    - **Evidence:**
        ```php
            /* ------------------------------------------------------------------ */
            /*  Lifecycle hooks */
            /* ------------------------------------------------------------------ */
        ```
        ```php
            /* ------------------------------------------------------------------ */
            /*  Relationships */
            /* ------------------------------------------------------------------ */
        ```

- [ ] **#SLOP-14** · P3 — Decorative ASCII-art banner comments in `PlacementPolicy`
    - **Where:** app/Routing/PlacementPolicy.php:71, 81, 91
    - **Affects:** Developers reading the routing gate logic; no runtime impact.
    - **Effort:** S (~0.5h)
    - **What to do:** Replace each with a single-word comment (`// Gate 1: capabilities`, etc.) with no dash padding.
    - **Technical:** Same pattern and same CLAUDE.md rule as SLOP-12/13 — the label already carries the meaning; the repeated em-dash padding is pure decoration.
    - **Plain English:** An ornate divider between sections of a memo whose headings are already bolded — the divider is scroll-past noise.
    - **Evidence:**
        ```php
        // ── Gate 1: capabilities ────────────────────────────────────────────
        ```
        ```php
        // ── Gate 2: the routing gate matrix ─────────────────────────────────
        ```
        ```php
        // ── Confidence ──────────────────────────────────────────────────────
        ```

- [ ] **#SLOP-15** · P3 — `withConnectionLock`/`withCrossPlatformLock` docblocks carry a caller-by-caller review audit that will go stale the moment a new caller is added
    - **Where:** app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php:298-327 (`withConnectionLock`), :349-378 (`withCrossPlatformLock`)
    - **Affects:** Maintainers reading the locking helpers — the essential contract (default TTL, when to raise it, the lock-ordering invariant) is buried inside a multi-paragraph "checked every caller" audit.
    - **Effort:** S (~1h)
    - **What to do:**
        - Trim each docblock to its load-bearing contract: the default TTL and the one condition that justifies raising it (a closure that runs `FreshaServiceProjector::sync()` / waits on an advisory lock), plus the lock-ordering rule (U1) for `withCrossPlatformLock`.
        - Move the "checked every caller: OnlineOrderingController, FreshaController..., GoogleBusinessController, ..." enumeration into the commit/PR history — that audit is a snapshot that will silently go wrong the moment a new caller is added and nobody updates the list.
    - **Technical:** The lock-ordering invariant (U1) and the TTL-raising rationale are genuinely load-bearing and should stay — this is not a case where the comment restates the code. But the accompanying caller-by-caller enumeration ("Checked every caller of this method... none of the others ever calls FreshaServiceProjector::sync()...") is a review-time audit, not a contract: it will drift the instant a new platform controller is added and calls this method with a slow closure, and nothing forces that enumeration to be re-verified. That's exactly the risk a contract-only docblock avoids.
    - **Plain English:** Keep the actual instructions for using the lock; move the "we checked every current user of this lock and confirmed none of them needs special handling" audit trail out of the source file. That audit is true today, but nothing will update it when a tenth caller is added tomorrow, and a stale audit sitting in the docblock looks exactly as authoritative as a current one.
    - **Evidence:**
        ```php
        * Whole-branch review fix: $ttlSeconds defaults to 10 (unchanged) but can
        * be raised for a caller whose closure holds the lock across FreshaServiceProjector::
        * sync() — the same rationale withCrossPlatformLock's docblock already
        * gives for its own $ttlSeconds param...
        * ...Checked every caller of this method (OnlineOrderingController,
        * FreshaController::connectDeferred()/setServiceVisibility()/forget() [via
        * withCrossPlatformLock, not this method], GoogleBusinessController,
        * CustomLinksController, EventsPlatformController, ShopController,
        * GenericPlatformController, SkoolController, InstagramController,
        * AppleController): none of the others ever calls
        * FreshaServiceProjector::sync() or waits on any advisory lock inside its
        * closure — every one is a fast DB read/write — so they all stay on the
        * default.
        ```

- [ ] **#SLOP-16** · P3 — No-op ternary in `setPlatformAttribute` — both branches return the identical value
    - **Where:** app/Models/Core/Site/IntegrationConnection.php:187-188
    - **Affects:** Maintainers reading the legacy platform-write path — the `isKnownSurface` call implies a decision that never actually happens.
    - **Effort:** S (~0.5h)
    - **What to do:** Replace `?? (LegacyPlatformMap::isKnownSurface($value) ? $value : $value)` with `?? $value`. If unknown surfaces are genuinely meant to be rejected, that needs an explicit throw/report — a separate correctness decision, not a slop cleanup.
    - **Technical:** `$value` is already coerced to `string` on the preceding line (`is_string($value) ? $value : ''`). `LegacyPlatformMap::isKnownSurface($value) ? $value : $value` evaluates to `$value` regardless of the boolean result — both ternary arms are identical, so `isKnownSurface()` is called purely for its return value, which is then discarded. The whole expression collapses to `LegacyPlatformMap::surfaceFor($value) ?? $value`.
    - **Plain English:** The code asks "is this a known platform?" and then does the exact same thing no matter the answer — like a bouncer who checks ID and waves everyone through regardless of what the ID says. The check adds a false sense of validation without actually validating anything.
    - **Evidence:**
        ```php
        $surface = LegacyPlatformMap::surfaceFor($value)
            ?? (LegacyPlatformMap::isKnownSurface($value) ? $value : $value);
        ```

- [ ] **#SLOP-17** · P3 — Identical TLD-regex construction copy-pasted twice in the same file
    - **Where:** app/Ingest/Connectors/EventbriteConnector.php:93 and :189
    - **Affects:** Maintainability only — adding or removing an Eventbrite regional TLD currently requires two identical edits in the same file.
    - **Effort:** S (~0.5h)
    - **What to do:** Extract `implode('|', array_map(fn (string $t) => preg_quote($t, '~'), self::TLDS))` into a private method (e.g. `tldPattern(): string`) and call it from both `pull()` and `normalizeLink()`.
    - **Technical:** Both `pull()` and `normalizeLink()` build the exact same regex fragment from `self::TLDS` independently. A one-line extraction removes the duplication with no behavior change.
    - **Plain English:** The same phone number is written on two different sticky notes in the same file. Consolidating to one note means updating a country domain only has to happen once.
    - **Evidence:**
        ```php
        // pull() — line 93
        $tlds = '(?:'.implode('|', array_map(fn (string $t) => preg_quote($t, '~'), self::TLDS)).')';
        preg_match_all('~https://www\.eventbrite\.'.$tlds.'/e/[a-z0-9-]+~i', $page['body'], $m);

        // normalizeLink() — line 189
        $tlds = '(?:'.implode('|', array_map(fn (string $t) => preg_quote($t, '~'), self::TLDS)).')';
        return preg_replace('~^(https?://)(?:www\.)?eventbrite\.'.$tlds.'(/.*)$~i', '$1www.eventbrite.com$2', $url) ?? $url;
        ```

- [ ] **#SLOP-18** · P3 — Identical `originOf()` helper copy-pasted across three storefront probes
    - **Where:** app/Routing/Probes/ShopifyStorefrontProbe.php:68-75, SquarespaceStorefrontProbe.php:70-77, WooCommerceStorefrontProbe.php:65-72
    - **Affects:** Maintainers of the three storefront probes; no runtime impact today.
    - **Effort:** S (~0.5h)
    - **What to do:** Add an `origin(): ?string` method to `Iri` (a `final readonly class` with no existing method of that name — confirmed no naming conflict) and replace all three private copies with `$iri->origin()`.
    - **Technical:** Three probe classes each define the identical 5-line private method reassembling `$iri->scheme.'://'.$iri->host` after a null check. `Iri` is the natural single home for this — it already owns `scheme` and `host`.
    - **Plain English:** Three different rooms each have an identical sticky note taped to the wall with the same two-ingredient formula. It belongs in one shared drawer, not on three walls that can drift apart.
    - **Evidence:**
        ```php
        private function originOf(Iri $iri): ?string
        {
            if ($iri->scheme === null || $iri->host === null) {
                return null;
            }
            return $iri->scheme.'://'.$iri->host;
        }
        ```

- [ ] **#SLOP-19** · P3 — `findPage` duplicated verbatim across two Site controllers
    - **Where:** app/Http/Controllers/Api/Site/PageController.php:123-132, SectionController.php:157-166
    - **Affects:** Developers touching page-fetch logic; a fix to one copy (e.g. adding a trashed-scope check) is easy to miss in the other.
    - **Effort:** S (~0.5h)
    - **What to do:** Consolidate into one shared private helper (a small `FindsPages` trait, or a call into `PageController`'s method) — at 8 lines with zero drift so far, this is genuinely at the "three similar lines" tolerance line; consolidating removes the future-drift risk cheaply.
    - **Technical:** Both controllers define an identical private `findPage(string $siteId, string $pageId): Page` — query `Page` by `id` + `site_id`, 404 on miss. No comment or behavioral drift yet, unlike the `findSection` case below, but the risk is the same.
    - **Plain English:** The same small "find this page, making sure it belongs to this site" helper lives in two files. It's small enough that duplicating it isn't crazy, but there's nothing today stopping the two copies from silently diverging the next time someone fixes a bug in only one of them.
    - **Evidence:**
        ```php
        // PageController.php:123-132
        private function findPage(string $siteId, string $pageId): Page
        {
            $page = Page::query()->where('id', $pageId)->where('site_id', $siteId)->first();
            if ($page === null) {
                abort(404, 'Page not found.');
            }
            return $page;
        }
        ```

- [ ] **#SLOP-20** · P3 — `findSection` copy-pasted across three controllers, and the comment explaining a security-relevant line has already failed to propagate
    - **Where:** app/Http/Controllers/Api/Site/SectionController.php:141-153 (with comment), SectionItemController.php:133-144 (no comment), SectionGroupController.php:96-107 (no comment)
    - **Affects:** Developers maintaining any of the three Section-family controllers; the two uncommented copies leave a future reader to guess why `setRelation('site', $site)` matters for authorization.
    - **Effort:** S (~1h)
    - **What to do:** Consolidate the three identical `findSection` bodies into one shared private helper (a small trait used by all three controllers), carrying the explanatory comment once.
    - **Technical:** All three controllers implement `findSection` identically — fetch by `id` + `site_id`, 404 on miss, `setRelation('site', $site)` so `SectionPolicy` can resolve ownership without a lazy load. `SectionController`'s copy explains that `setRelation` is "both the N+1 fix and the spoofing guard"; the other two copies lack the comment — proof the duplication has already drifted once (comment-only so far, but the same gap that let SLOP-3 happen).
    - **Plain English:** The same 9-line helper exists in three different files that all manage sections on a sitepage. One copy explains why a particular line matters for security; the other two don't. If someone later changes how sections are authorized, they're likely to update the documented copy and miss the silent ones.
    - **Evidence:**
        ```php
        // SectionController.php:141-153 — the copy WITH the comment
        private function findSection(Site $site, string $sectionId): Section
        {
            $section = Section::query()->where('id', $sectionId)->where('site_id', $site->id)->first();
            if ($section === null) {
                abort(404, 'Section not found.');
            }
            // The policy resolves ownership through this relation and independently
            // re-checks site_id, so preloading it here is both the N+1 fix and the
            // spoofing guard.
            $section->setRelation('site', $site);
            return $section;
        }
        ```
        ```php
        // SectionItemController.php:133-144 — identical logic, no comment
        private function findSection(Site $site, string $sectionId): Section
        {
            $section = Section::query()->where('id', $sectionId)->where('site_id', $site->id)->first();
            if ($section === null) {
                abort(404, 'Section not found.');
            }
            $section->setRelation('site', $site);
            return $section;
        }
        ```

- [ ] **#SLOP-21** · P3 — `@`-suppressed `preg_match` on catalog-authored regex patterns hides malformed patterns instead of surfacing them
    - **Where:** app/Routing/LinkProjector.php:88, 97, 105
    - **Affects:** Developers authoring/maintaining Catalog detector definitions — a typo'd regex in a `reject_patterns`/`subdomain_pattern`/`path_pattern` entry silently fails closed instead of throwing.
    - **Effort:** S (~1h)
    - **What to do:** Remove the `@` suppression on all three `preg_match` calls. These patterns come from `CompiledCatalog`/`Rulepack`, not user input, so a malformed pattern is a catalog-authoring bug that should surface loudly (PHP raises a warning + `preg_match` returns `false`, which the existing `!== 1`/`=== 1` checks already handle safely without the `@`).
    - **Technical:** `score()` runs against `$detector['reject_patterns']`, `$detector['subdomain_pattern']`, and `$detector['path_pattern']` — all compiled from the hand-authored Catalog (confirmed no user input reaches these patterns; the routing chunk's own catalog review found the definitions are static, deliberately-explicit per-brand data). The `@` operator converts a would-be-visible PHP warning into total silence, so a broken regex in a catalog entry just makes that detector never match — indistinguishable from "this platform's rule genuinely doesn't match here."
    - **Plain English:** A bouncer has a handwritten reject list. If a name on it is smudged and unreadable, the bouncer should say something — instead, the `@` symbol tells them to just wave that person through without a word. The list should be checked for legibility, not silently ignored when it's broken.
    - **Evidence:**
        ```php
        foreach ($detector['reject_patterns'] as $reject) {
            if (@preg_match($reject, $iri->path) === 1) {
                return null;
            }
        }
        ```
        ```php
        if ($iri->subdomain === null || @preg_match($detector['subdomain_pattern'], $iri->subdomain, $m) !== 1) {
        ```

- [ ] **#SLOP-22** · P3 — `AppleMusicConnector::pull()` and `ApplePodcastsConnector::pull()` are near-identical — same endpoint, same error/cap logic, different entity/field names
    - **Where:** app/Ingest/Connectors/AppleMusicConnector.php:81-129 and app/Ingest/Connectors/ApplePodcastsConnector.php:72-131
    - **Affects:** Maintenance of the iTunes-Lookup-backed connectors — a fix to the error handling, the resultCount-cap boundary, or the absence-folding reasoning must be applied in both files, and the explanatory comments justifying that logic are themselves duplicated.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the shared fetch/decode/guard pipeline into a private method (e.g. on a small shared trait both connectors use), parameterized by the entity string, the wrapper-discriminator field/value, and a mapper callback.
        - Keep the per-connector class-level docblocks and Note codes — the connector-specific commentary is not duplicated, only the pull-and-guard body is.
    - **Technical:** Both connectors call `itunes.apple.com/lookup` with the same query shape, then run identical guard clauses in the same order (non-200/empty-body → `Unavailable`; bad JSON shape → `Unavailable`; `resultCount === 0` → `Unavailable`; empty mapped items → `Note`) with near-identical inline comments justifying each (both cite the same C5 absence-folding concern). The only real differences are the `entity` param, the wrapper-discriminator key/value (`wrapperType`/`collection` vs `kind`/`podcast-episode`), and the per-item mapper. No third implementer exists beyond these two, and no shared base class currently hosts this logic — confirmed by checking both class declarations directly `implement Connector`.
    - **Plain English:** Apple Music and Apple Podcasts both talk to the same Apple API, just asking for different things (albums vs. episodes). The code that calls the API, checks for errors, and decides whether the result is complete is written twice, almost word-for-word, including the comments explaining the reasoning. A fix to how errors are handled needs to land in two places instead of one.
    - **Evidence:**
        ```php
        // AppleMusicConnector::pull()
        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("lookup returned {$response['status']}", $response['status']);
            return;
        }
        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
            yield new Unavailable('lookup did not decode to the expected resultCount/results shape', $response['status']);
            return;
        }

        // ApplePodcastsConnector::pull() — same structure
        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("lookup returned {$response['status']}", $response['status']);
            return;
        }
        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
            yield new Unavailable('lookup did not decode to the expected resultCount/results shape', $response['status']);
            return;
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Comment/docblock cleanup (text-only, no logic change):** #SLOP-4, #SLOP-5, #SLOP-6, #SLOP-7, #SLOP-8, #SLOP-9, #SLOP-10, #SLOP-11, #SLOP-12, #SLOP-13, #SLOP-14, #SLOP-15
    - **Why grouped:** every item is a comment/docblock trim or deletion with zero behavior change, spread across many small files — safe to batch as one low-risk pass.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Duplicate-helper consolidation (routing probes, connectors, controllers):** #SLOP-2, #SLOP-17, #SLOP-18, #SLOP-19, #SLOP-20, #SLOP-22
    - **Why grouped:** same root-cause pattern (copy-pasted helper logic across sibling files); each fix either backfills a missing branch or extracts one shared implementation.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet. Escalate implement → Opus for #SLOP-22 (the iTunes-lookup extraction touches two live ingest connectors — verify with `composer test` against both `AppleMusicConnectorTest`/`ApplePodcastsConnectorTest` before merging).

- **Bundle 3 — Small isolated correctness-adjacent fixes:** #SLOP-1, #SLOP-16, #SLOP-21
    - **Why grouped:** each is an independent one-to-few-line fix (wrong log key, no-op ternary, silent regex-error suppression) unrelated to the comment-cleanup or dedup bundles — no shared file or subsystem, just similarly small and low-risk.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SLOP-3 — `ownerMatches` duplication between `SectionPolicy` and `DesignKitRestylePolicy`** · touches authorization/Policy code directly; per policy, any change to authorization logic runs alone with its own plan + sign-off even though the finding itself is a code-quality (not security-bypass) issue.
