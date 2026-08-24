# Semantic Correctness Audit — 2026-08-24

**Branch:** development
**Lens:** Semantic Correctness: code that compiles and type-checks but does the wrong thing (plausible-but-wrong logic, config/flag misuse, magic-value drift, contract mismatches, codebase-idiom drift)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:** all files listed under the task's "Source Files (for Evidence verification)" scope (`app/Services/Platforms/*`, `app/Services/Site/UpdateSiteAction.php`, `app/Services/Analytics/*`, `app/Services/Profile/SectorTaxonomy.php`, `app/Http/Controllers/Api/{Platforms,User,PublicSite,Content,Routing}/*`, `app/Http/Requests/**`, `app/Observers/Core/IntegrationConnectionObserver.php`, `app/Console/Commands/*`, `config/partna.php`, `routes/api.php`, `app/Catalog/**`, `app/Routing/**`, `app/Ingest/Connectors/FreshaConnector.php`, `app/Site/Actions/ActionCandidates.php`, `app/Site/Pools/PoolResolver.php`), cross-checked against `vendor/laravel/framework` (Eloquent `Model.php`, `Events/Dispatcher.php`, `Database/{Connection,PostgresConnection}.php`, `Validation/Concerns/ValidatesAttributes.php`) and `supabase/migrations/20260726000000_baseline_pilot.sql`.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 16 complete
- P3 Low: 0 of 8 complete

---

## P1 — Fix before pilot launch

- [ ] **SEM-1** · P1 — Fresha service sync deletes valid menu items whenever any row fails to map
    - **Where:** app/Ingest/Connectors/FreshaConnector.php:63-75, 234-282
    - **Affects:** Every Fresha-connected professional whose menu contains at least one item the mapper can't recognise — those items are actively deleted from their live services pool, not just skipped.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - When `$unmapped > 0`, do not yield `Covered('services', Coverage::exhaustive())` for this run — yield a non-exhaustive/partial coverage signal (or omit `Covered` for that batch) so `StreamSpec::mayDelete()` does not treat this run as authoritative for the whole menu.
        - Keep the existing `unmapped_rows` `Note` for observability, but move it before any decision that could trigger deletion, and consider surfacing it to staff tooling so a mapper gap gets triaged instead of silently pruning services.
    - **Technical:** `FreshaConnector`'s services stream is declared with `orderField: null` and `deletesOnExhaustive: true` (lines 71-74), and `StreamSpec::mayDelete()` (`app/Ingest/Manifest/StreamSpec.php:48-51`) returns `true` precisely when `deletesOnExhaustive` is set — this is the mechanism the projection layer uses to decide that "absent from this batch" means "was removed on Fresha." `servicesMessages()` builds `$items` by calling `mapServiceItem()` per row and appending only non-null results, incrementing `$unmapped` for every row the mapper couldn't parse (lines 246-258). It then unconditionally yields `Record` for every mapped item followed by `Covered('services', Coverage::exhaustive())` (line 275) — regardless of `$unmapped`. So a menu with N recognisable rows and 1 unmappable row is reported as *exhaustively* covering N items; the projection layer is then licensed to delete every previously-landed service not in that N-item batch, including the unmapped one if it existed as a valid service from a prior run. The file's own comment documents this already happened live: "three of one salon's rows -- 12% of its menu -- vanished with no record and no signal." The `unmapped_rows` `Note` added afterwards only adds visibility into the log stream — it does not stop the deletion.
    - **Plain English:** Imagine a stocktake where a few barcodes are smudged and won't scan. If the stocktake app still tells the warehouse system "I scanned everything, remove anything not on my list," the smudged-but-real products get thrown out even though they never left the shelf. That's what happens here: when Fresha sends a services menu and a few items don't parse cleanly, the connector still tells the system it saw the whole menu, so those unparsed (but real) services get deleted from the professional's page. This has already happened to at least one real salon's menu.
    - **Evidence:**
        ```php
        // Fresha returns the full menu grouped by category, not
        // time — there is no reverse-chron prefix to claim, so
        // this stream is exhaustive-or-nothing, never partial.
        orderField: null,
        // ...but the one call IS the whole menu, so a service the
        // menu no longer lists is gone (see StreamSpec::mayDelete).
        deletesOnExhaustive: true,
        ```
        ```php
        foreach ($items as $item) {
            yield new Record('services', $item['serviceId'], $item);
        }

        // A parsed menu is the whole menu — Fresha does not paginate this
        // call, so what came back is everything there is.
        yield new Covered('services', Coverage::exhaustive());

        if ($unmapped > 0) {
            yield new Note('unmapped_rows', $unmapped.' Fresha row(s) carried no recognisable catalog id and were not landed');
        }
        ```
        ```php
        // StreamSpec::mayDelete()
        return $this->profile->mayDelete() && ($this->orderField !== null || $this->deletesOnExhaustive);
        ```

## P2 — Should fix

- [ ] **SEM-2** · P2 — LinkRouter's reservation/ordering seeders skip the soft-delete tombstone guard, silently resurrecting removed connections
    - **Where:** app/Services/Platforms/LinkRouter.php:434-441 (`seedReservation`), 495-502 (`seedOnlineOrdering`)
    - **Affects:** Any professional who disconnects a reservation or online-ordering platform and whose site/bio still carries the old link — a later Instagram/link-in-bio scan resurrects the removed connection.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Before treating "no live incumbent found" as "never connected," check `IntegrationConnection::onlyTrashed()` for the same user/family the way `BuildsAutoSyncFindings::resolveSocialLink()` already does.
        - If a trashed row is found, route to `unmatched`/`custom(handled: true)` instead of calling `write()`.
    - **Technical:** `IntegrationConnection` uses `SoftDeletes` (`app/Models/Core/Site/IntegrationConnection.php:61`), and disconnecting sets the row's `deleted_at` (`ManagesIntegrationConnection::forgetConnection()`). The trait method `resolveSocialLink()` explicitly implements a "soft-delete tombstone guard" — it checks `IntegrationConnection::onlyTrashed()` before treating an absent live row as "never connected" (`app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php:770-783`), and `InstagramAutoSync`'s class docblock states LinkRouter "reproduces every invariant" the old code carried, explicitly naming "the soft-delete tombstone guard." `LinkRouter::seedReservation()` and `seedOnlineOrdering()` instead query only live rows (`IntegrationConnection::query()->where(...)`, no `onlyTrashed()` check) to look for a same-family incumbent; when none is found (because the only prior row was soft-deleted), they fall through to `$this->write(...)`, which is `updateOrCreate()` scoped to non-trashed rows by Eloquent's default scope — so it creates a fresh row rather than honouring the disconnect.
    - **Plain English:** A business owner removes their OpenTable booking link from their dashboard. Their website still has the old "Reserve a table" button pointing at OpenTable, though. The next time the system re-scans their Instagram bio or website, it sees no *active* OpenTable connection, concludes "they've never connected this," and reconnects it — undoing the removal the owner explicitly asked for.
    - **Evidence:**
        ```php
        // resolveSocialLink() — the pattern that exists elsewhere in this codebase:
        $wasDisconnected = IntegrationConnection::onlyTrashed()
            ->where('user_id', $userId)->where('platform', $platform)
            ->exists();
        if ($wasDisconnected) {
            return ['findings' => [], 'unmatched' => [[...]], 'consumed' => true];
        }
        ```
        ```php
        // LinkRouter::seedReservation — no trashed check:
        $incumbent = IntegrationConnection::query()
            ->where('user_id', (string) $user->id)
            ->where('routing_class', 'reservations')
            ->orderBy('created_at')
            ->get()
            ->first(fn (IntegrationConnection $row) => ! $this->sameUrl(...));
        if ($incumbent !== null) { /* cap-block */ }
        // else falls through to $this->write(...) — resurrects a soft-deleted platform
        ```

- [ ] **SEM-3** · P2 — Google Business enrichment collects a YouTube social link that `seedSocials()` never seeds
    - **Where:** app/Services/Platforms/GoogleBusinessApifyScraper.php:179-186; app/Services/Platforms/GoogleBusinessAutoSync.php:739-750
    - **Affects:** Google Business listings with a YouTube channel URL — every other scraped social (Facebook, TikTok, X, LinkedIn, Instagram) auto-connects, YouTube silently does not.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'youtube' => 'YouTube'` to `$linkOnly`, `'youtube' => 'youtube'` to `$platformOf`, and the matching catalog surface key to `$surfaceOf` in `seedSocials()`.
        - Add a regression test asserting a scraped `socials.youtube` URL produces a seeded YouTube connection.
    - **Technical:** `GoogleBusinessApifyScraper::map()` explicitly places a YouTube URL into the same `socials` array as the other link-only socials (`'youtube' => $this->firstUrl(data_get($place, 'youtubes'))`). `GoogleBusinessAutoSync::seedSocials()` reads that same `socials` array but hardcodes `$linkOnly = ['facebook' => 'Facebook', 'tiktok' => 'TikTok', 'twitter' => 'X', 'linkedin' => 'LinkedIn'];` — YouTube is absent from all three parallel maps (`$linkOnly`, `$platformOf`, `$surfaceOf`), so `$socials['youtube']` is read from the enrichment payload and then simply never iterated.
    - **Plain English:** The scraper collects every social-media card sitting on a business's Google listing — Facebook, TikTok, X, LinkedIn, and a YouTube channel too. The auto-connect step then files all of them onto the dashboard except it skips right over the YouTube card, as if it wasn't in the pile. The business's YouTube channel never gets connected automatically.
    - **Evidence:**
        ```php
        // GoogleBusinessApifyScraper::map()
        $socials = array_filter([
            'instagram' => $this->firstUrl(data_get($place, 'instagrams')),
            'facebook' => $this->firstUrl(data_get($place, 'facebooks')),
            'linkedin' => $this->firstUrl(data_get($place, 'linkedIns')),
            'youtube' => $this->firstUrl(data_get($place, 'youtubes')),
            'tiktok' => $this->firstUrl(data_get($place, 'tiktoks')),
            'twitter' => $this->firstUrl(data_get($place, 'twitters')),
        ], $notNull);
        ```
        ```php
        // GoogleBusinessAutoSync::seedSocials()
        $linkOnly = ['facebook' => 'Facebook', 'tiktok' => 'TikTok', 'twitter' => 'X', 'linkedin' => 'LinkedIn'];
        ```

- [ ] **SEM-4** · P2 — `pageRanksFromActions()` discards ActionScorer's hysteresis-based rank and re-sorts by raw score
    - **Where:** app/Services/Analytics/ContentPopularityReader.php:99-116
    - **Affects:** Public sitepage page ordering when a page's score is close to the one ranked above it — the two anti-thrash mechanisms disagree on the displayed order.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Have `actionScoresForSite()` also select `rank`, and have `pageRanksFromActions()` order by the stored `rank` column instead of re-deriving one from `arsort()`.
        - If score-order is actually what's wanted here, correct the docblock ("in rank order") and rename the method so it doesn't claim to preserve ActionScorer's rank.
    - **Technical:** The method's docblock states it returns "the `page:*` action rows in **rank order**." `ActionScorer::computeForSite()` computes rank via `rankWithHysteresis()` — a candidate only overtakes the one above it when its score beats it by more than 10% (`RANK_SWAP_THRESHOLD`), seeded from the previous rank — specifically to prevent order from thrashing on small score deltas (`app/Services/Analytics/ActionScorer.php:198-234`). `pageRanksFromActions()` calls `actionScoresForSite()`, which selects only `content_key, score` (no `rank`), then does `arsort($pages)` and assigns fresh ranks 1..N purely by score. Whenever two pages' scores are within the 10% hysteresis band, this produces a different order than the one ActionScorer computed and stored, and than what a smart-mode action list on the same site would show for the same pages.
    - **Plain English:** The system has a rule for ordering things on a business's page: "don't reshuffle the order just because one item nudged ahead by a hair — only swap if it's clearly winning." That rule is applied once, correctly, and the result is saved. But the code that decides page order reads the raw scores fresh and re-sorts from scratch, throwing away the "don't reshuffle for small differences" rule. So the page order can jitter in a way the rest of the system was specifically built to avoid.
    - **Evidence:**
        ```php
        /**
         * Page order from the action layer (spec §6): the `page:*` action rows in
         * rank order → page id => 1-based rank.
         */
        public function pageRanksFromActions(?string $siteId): array
        {
            $scores = $this->actionScoresForSite($siteId); // selects content_key, score ONLY
            $pages = [];
            foreach ($scores as $id => $score) {
                if (str_starts_with($id, 'page:')) {
                    $pages[substr($id, 5)] = $score;
                }
            }
            arsort($pages);
            $out = [];
            $rank = 1;
            foreach (array_keys($pages) as $pageId) {
                $out[$pageId] = $rank++;
            }
            return $out;
        }
        ```

- [ ] **SEM-5** · P2 — Five Shop dashboard mutation responses drop `popularityRank` because they call `brandMap()` without its ranks argument
    - **Where:** app/Http/Controllers/Api/Platforms/ShopController.php:469, 560, 674, 960, 1064
    - **Affects:** Dashboard responses returned right after add/update/select-products/add-product actions on a Shop brand — `popularityRank` is momentarily missing until the next `GET`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change all five call sites from `$this->contentReader->brandMap($user)[...]` to `$this->brandMap($user)[...]` (the controller's private wrapper, which already calls `productRanksFor()`), or pass `$this->productRanksFor($user)` explicitly.
    - **Technical:** `ShopContentReader::brandMap(User $user, ?array $productRanks = null)`'s own docblock states: "pass even an empty array to get a null-valued `popularityRank` key on every product... pass null to omit the key entirely." The controller's own private `brandMap()` wrapper and `productRanksFor()`'s docblock make the contract explicit: "Every caller must pass an array (never null) so `popularityRank` stays PRESENT (not omitted) on every dashboard read, matching `brandMap()`'s contract." The `addBrand`, `connectStatus`, `updateBrand`, `setProducts`, and `addProduct` mutation responses all call `$this->contentReader->brandMap($user)` — the reader's method directly, with the default `null` — bypassing the private wrapper and violating the documented contract in five places.
    - **Plain English:** Every brand on a Shop dashboard is supposed to carry a "popularity rank" so the list orders itself sensibly. The page that lists all brands always fetches that rank. But five of the "you just changed something" responses (adding a brand, updating it, picking its products) forget to ask for the rank, so the brand momentarily comes back without one. It looks fine again after the next full page load, but for a moment the save response is missing data every other read of the same brand includes.
    - **Evidence:**
        ```php
        // productRanksFor() docblock — the contract:
        // "... Every caller must pass an array (never null) so popularityRank stays PRESENT
        // (not omitted) on every dashboard read, matching brandMap()'s contract."

        // Five call sites (addBrand, connectStatus, updateBrand, setProducts, addProduct)
        // all violate it, e.g.:
        $resolved = (new ShopBrandResource(
            $this->contentReader->brandMap($user)[$id] ?? []
        ))->resolve();
        ```

- [ ] **SEM-6** · P2 — Public analytics 422-vs-404 branch leaks the existence of unpublished sites
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:398-419; app/Http/Controllers/Concerns/ResolvesSiteFromRequest.php:20-39
    - **Affects:** Public, unauthenticated analytics endpoints — anyone who can guess or obtain a site UUID can distinguish "exists but unpublished" from "doesn't exist" before any origin check runs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collapse every unresolved-site outcome in `resolvePublishedSite()` to a flat 404, regardless of whether `site_id` was supplied.
        - Update the method's own docblock, which already claims this is the intended behaviour ("never 403, no existence leak") but the code doesn't implement it that way.
    - **Technical:** The method's docblock says the 422 branch is reserved for "IDOR when `site_id` was supplied but cross-check failed" and that every other case is 404. `resolveSiteFromData()` (`ResolvesSiteFromRequest.php:20-39`), when a `site_id` is supplied with no `subdomain`, does a bare `Site::query()->whereKey($data['site_id'])->first()` — this returns `null` both for a genuinely nonexistent id *and* would return the Site (not null) for an existing-but-unpublished one, since publish status isn't part of that query. Back in `resolvePublishedSite()`, a `null` resolution with `site_id` present always returns 422 — covering both "cross-check failed" and "no such site." An existing-but-unpublished site instead resolves successfully, then hits the separate `! $site->is_published` branch and returns 404. So a request with a nonexistent `site_id` gets 422, and a request with an existing-but-unpublished `site_id` gets 404 — an attacker can distinguish the two without ever passing the Origin/Referer check that runs afterward.
    - **Plain English:** Imagine phoning a directory line where a real-but-hidden business rings one tone and a made-up phone number plays a different tone. Someone dialing random numbers can tell which ones are real hidden businesses just from the tone, even though neither one should be revealed. The analytics endpoint here does the same thing with website IDs: guessing a real (but not yet published) site's ID gets a different error code than guessing a fake one, quietly confirming the real site exists.
    - **Evidence:**
        ```php
        /**
         * On failure, sets $error to the right JSON response (422 IDOR when site_id
         * was supplied but cross-check failed; otherwise 404 — never 403, no existence leak)
         */
        private function resolvePublishedSite(array $data, ?JsonResponse &$error): ?Site
        {
            $site = $this->resolveSiteFromData($data);
            if (! $site) {
                $status = ! empty($data['site_id']) ? 422 : 404;
                $error = $this->error('Site not found', $status);
                return null;
            }
            if (! $site->is_published) {
                $error = $this->error('Site not found', 404);
                return null;
            }
        ```

- [ ] **SEM-7** · P2 — Pool reorder leaves un-listed pinned items behind with stale sort keys
    - **Where:** app/Http/Controllers/Api/Content/PoolController.php:186-204
    - **Affects:** Any pool reorder where the client's `itemIds` list omits a currently-pinned item — that item's old position can collide with, or land between, the freshly-assigned positions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before inserting the new pins, delete every existing `STATE_PINNED` row for the section (not just the ones matching `whereIn('item_id', $ids)`), or reject the request outright when an existing pinned row is missing from `$ids`.
    - **Technical:** The method's own comment states the contract explicitly: "the FE sent a list it believes is the pool, and half-applying it would scramble the order it shows." The pre-check only validates that every id in `$ids` is owned and in-pool (422 on a foreign/off-pool id) — it never checks that `$ids` is *complete* relative to existing pins. The transaction deletes `SectionItem::where('section_id', $section->id)->whereIn('item_id', $ids)` and re-inserts fresh rows with `sort_key = (float)($index+1)`. A pre-existing pinned row whose `item_id` is absent from `$ids` is left untouched with its old `sort_key`, which can collide with or interleave the new 1..N sequence, producing exactly the scrambled order the comment says this design is meant to prevent.
    - **Plain English:** When someone drags items into a new order and saves it, the save is supposed to replace the whole order. But if the app sends an incomplete list — say it forgot about one pinned item — that forgotten item keeps its old position number instead of being cleared out. Its old number can now land in the middle of the freshly saved order, making the pool display in a sequence nobody asked for.
    - **Evidence:**
        ```php
        // Owner-scoped AND pool-scoped: a foreign or off-pool id is a 422,
        // not a silently skipped row — the FE sent a list it believes is the
        // pool, and half-applying it would scramble the order it shows.
        DB::connection('pgsql')->transaction(function () use ($section, $ids) {
            SectionItem::query()
                ->where('section_id', $section->id)
                ->whereIn('item_id', $ids)   // only touches LISTED rows
                ->delete();
            $rows = [];
            foreach ($ids as $index => $itemId) {
                $rows[] = [..., 'sort_key' => (float) ($index + 1), ...];
            }
            SectionItem::query()->insert($rows);
        });
        ```

- [ ] **SEM-8** · P2 — Manual action-slot contiguity guard silently skips string-typed positions
    - **Where:** app/Http/Requests/Concerns/SiteOrderingValidationRules.php:92-109
    - **Actual affects:** Any non-strict-JSON client (form-encoded, some API tooling) that submits `settings.actions.slots[*].position` as a numeric string in manual mode — an invalid, non-contiguous ordering can be accepted instead of rejected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `is_int($slot['position'] ?? null)` with `filter_var($slot['position'] ?? null, FILTER_VALIDATE_INT) !== false`, casting to `(int)` before pushing onto `$positions`.
        - Add a request-validation test that posts manual slots with string positions (`"1"`, `"2"`, …).
    - **Technical:** Laravel's `integer` rule (`Illuminate\Validation\Concerns\ValidatesAttributes::validateInteger`, non-strict mode) is `filter_var($value, FILTER_VALIDATE_INT) !== false` — it accepts the string `"1"`, and Laravel's validator does not mutate the underlying request data during validation. The custom `manualSlotsContiguousRule()` closure gates entries into `$positions` with `is_int($slot['position'] ?? null)`, which is `false` for the string `"1"`. A client sending string-typed positions therefore passes both the `integer`/`distinct`/`min`/`max` rules on each slot *and* silently drops out of the manual-mode contiguity check, so `[0, 1, "2"]` (contiguous) and `["1", "1"]` (a real gap/duplicate scenario after the string ones are filtered) are equally invisible to `manualSlotsContiguousRule()`.
    - **Plain English:** The form has a rule for manual ordering: positions must run 0, 1, 2, … with no gaps. The rule checks this by looking only at real numbers, not numbers written as text. A client that sends positions as `"1"` instead of `1` — which the form otherwise accepts just fine — slips past the gap-check entirely, so a broken manual order can be saved without the safety check ever looking at it.
    - **Evidence:**
        ```php
        private function manualSlotsContiguousRule(): Closure
        {
            return function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_array($value) || $this->input('settings.actions.mode') !== 'manual') {
                    return;
                }
                $positions = [];
                foreach ($value as $slot) {
                    if (is_array($slot) && is_int($slot['position'] ?? null)) {
                        $positions[] = $slot['position'];
                    }
                }
                ...
        ```
        ```php
        // vendor/laravel/framework/.../ValidatesAttributes.php
        public function validateInteger($attribute, $value, array $parameters = [])
        {
            if (($parameters[0] ?? null) === 'strict') { return is_int($value); }
            return filter_var($value, FILTER_VALIDATE_INT) !== false;
        }
        ```

- [ ] **SEM-9** · P2 — Publish-blocked-on-empty-display-name guard uses `=== true`, so `is_published=1` slips through
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php:111-130; app/Services/Site/UpdateSiteAction.php:90-102
    - **Affects:** Any non-strict-JSON client publishing a site (form-encoded submission, some third-party API tooling) with an empty display name — the intended "cannot publish without a display name" block is bypassed in both the request validator and the action's own duplicate check.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace both `$this->input('is_published') === true` (Request) and `($data['is_published'] ?? null) === true` (Action) with `filter_var($value, FILTER_VALIDATE_BOOLEAN)` or Laravel's `$this->boolean('is_published')`.
        - Add a test posting `is_published=1` (string) with an empty display name and assert publish is still blocked.
    - **Technical:** Both the `is_published` validation rule (`['sometimes', 'boolean']`) and Laravel's `validateBoolean()` (`vendor/laravel/framework/.../ValidatesAttributes.php:494-500`) accept `[true, false, 0, 1, '0', '1']` as valid booleans — but neither the FormRequest's `withValidator()` guard nor `UpdateSiteAction::execute()`'s own completeness check normalize the value before comparing; both use strict `=== true`. A form-encoded or query-string request carrying `is_published=1` (the string `"1"`) is valid per the `boolean` rule, yet fails `"1" === true`, so the display-name-required check is skipped in *both* places that were meant to enforce it.
    - **Plain English:** There's a safety check before a site goes live: "make sure the owner has filled in a public display name first." That check only fires when the "publish" switch is sent as the exact value `true`. But the form itself already treats `1` as a perfectly valid way to say "yes, publish" — so a client that sends `1` instead of `true` walks straight past the safety check, twice over (once in the form validator, once in the code that actually saves the site), and a site with no public name can go live.
    - **Evidence:**
        ```php
        // UpdateSiteRequest::withValidator()
        if ($this->input('is_published') === true) {
            ...
            if (empty($professional->display_name)) {
                $validator->errors()->add('is_published', 'Cannot publish: professional must have a display name.');
            }
        }
        ```
        ```php
        // UpdateSiteAction::execute() — the SAME strict check, independently:
        if (($data['is_published'] ?? null) === true) {
            $canBypass = $allowForcePublish && $forcePublish;
            if (! $canBypass) {
                if (empty($professional->display_name)) { throw ValidationException::withMessages([...]); }
            }
        }
        ```

- [ ] **SEM-10** · P2 — Promoted-settings hoist reads from the merged JSONB, so a stale legacy value can overwrite a fresh typed column
    - **Where:** app/Services/Site/UpdateSiteAction.php:104-158
    - **Affects:** Any PATCH to `settings` on a site whose on-disk `settings` JSONB still carries a pre-promotion value for `show_branding` / `charlie_enabled` / `services_auto_sync_enabled` / `booking_mode` / `manual_booking_url` — an unrelated settings PATCH can silently resurrect that stale value into the now-authoritative typed column.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the hoist condition from `array_key_exists($key, $merged)` to `array_key_exists($key, $incomingSettings)`, and write from `$incomingSettings[$key]` — matching what the in-file comment already claims the code does.
        - Add a regression test: PATCH an unrelated settings key on a site whose stored JSONB still contains a legacy promoted-key value, and assert the typed column is unchanged.
    - **Technical:** `$merged = array_replace_recursive($existing, $incomingSettings)` — `$existing` is decoded straight from the on-disk `site.sites.settings` JSONB, so `$merged` contains every key currently on disk plus the client's overrides, *not just* the keys the client sent this request. The hoist loop then does `if (array_key_exists($key, $merged)) { $data[$key] = $merged[$key]; ...}`, which is true for any promoted key still lingering in the disk JSONB even when the client's PATCH never touched it. This directly contradicts the method's own comment: "We extract from `$merged` (post-PATCH) so only keys the client actually sent are written — columns the request didn't touch keep their existing DB value." In steady state (once a key has been written once post-migration, it's stripped from `$merged` before saving, per the `unset($merged[$key])` two lines later) this self-heals — but any row whose settings JSONB still carries a promoted key from before the column-promotion migration is exposed to a silent stale overwrite on its very next unrelated settings PATCH.
    - **Plain English:** Picture a filing cabinet where five values used to live loose in a folder, and got upgraded to their own labelled drawers. The cleanup code is supposed to only move a value into its drawer when the customer hands over a fresh version of that value today. Instead, it also picks up whatever old, possibly stale copy is still sitting in the folder from before the upgrade — even if the customer didn't mention it — and files that old copy into the drawer, overwriting whatever fresh value is already there.
    - **Evidence:**
        ```php
        $merged = array_replace_recursive($existing, $incomingSettings);
        ...
        // FOUND-16: hoist the promoted keys out of settings JSONB into
        // typed columns. We extract from $merged (post-PATCH) so only keys
        // the client actually sent are written — columns the request didn't
        // touch keep their existing DB value.
        foreach (Site::PROMOTED_SETTINGS_KEYS as $key) {
            if (array_key_exists($key, $merged)) {   // true even when $incomingSettings never had $key
                $data[$key] = $merged[$key];
                unset($merged[$key]);
            }
        }
        ```

- [ ] **SEM-11** · P2 — `IntegrationConnectionObserver::updated()` reads `getOriginal()` after it has already been synced to the new value, so mirrored-media cleanup never fires
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:39-53, 462-495
    - **Affects:** Every Instagram connection whose mirrored R2 folder changes (a re-selection, a re-scrape that lands a new folder) — the old folder is never queued for deletion, so orphaned R2 storage accumulates indefinitely, not just inside `SourceReconciler`'s transaction as the file's own comment assumes.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Capture the pre-update `payload` folder in a pre-save seam (an `updating` observer hook, or a static per-request map keyed by connection id) *before* the underlying `save()` call runs, since `getOriginal()` is unreliable inside any `$afterCommit = true` listener.
        - Use the captured value in `updated()` instead of `$connection->getOriginal('payload')`.
        - Update the class docblock — the "no platform write runs in a DB transaction, so this fires synchronously" reasoning is not what makes `getOriginal()` unsafe here; `syncOriginal()` runs synchronously in the same `save()` call regardless of any transaction, which is the actual root cause.
    - **Technical:** Eloquent's `Model::save()` → `finishSave()` calls `fireModelEvent('saved', false)` and *then*, unconditionally and synchronously (not gated on any transaction), `$this->syncOriginal()` (`vendor/laravel/framework/.../Model.php:1277-1286`) — likewise `performUpdate()` calls `syncChanges()` then `fireModelEvent('updated', false)` before `finishSave()` runs. For an `$afterCommit = true` listener, Laravel's dispatcher does not defer *building* the callback — `Dispatcher::createCallbackForListenerRunningAfterCommits()` captures `$payload = func_get_args()` (i.e. the `$connection` object reference) synchronously at the moment the event fires, and only *registers* `$listener->$method(...$payload)` to run via `TransactionManager::addCallback()` after commit (`vendor/laravel/framework/.../Events/Dispatcher.php:614-625`). Since PHP objects are handles, by the time that deferred callback actually invokes `IntegrationConnectionObserver::updated($connection)` — after commit — `$connection->original` has *already* been overwritten by the synchronous `syncOriginal()` call that happened moments after the event fired, well before commit. `getOriginal('payload')` therefore always equals the current (new) payload, so `$old === $new` is always true and `DeleteMirroredMediaJob::dispatch($old)` never fires — for *every* Instagram payload update, not only ones that happen to run inside `SourceReconciler::reconcile()`'s explicit `DB::transaction()` as the method's docblock assumes.
    - **Plain English:** This code is supposed to notice when a customer's Instagram photo folder changes and clean up the old one so storage doesn't pile up forever. But the way it checks "what was the old folder?" always sees the *new* folder by the time it actually runs — because of a subtle timing quirk in how Laravel schedules this kind of check to run slightly after the save completes. The save has already updated its own record of "what used to be there" before the check gets a chance to look. The upshot: the old-photo-folder cleanup silently never runs, for any Instagram reconnect, and unused storage just keeps accumulating.
    - **Evidence:**
        ```php
        public bool $afterCommit = true;
        ...
        public function updated(IntegrationConnection $connection): void
        {
            if ($connection->platform !== Platform::Instagram->value) { return; }
            try {
                $old = InstagramPayload::fromArray($connection->getOriginal('payload'))->folder;
                $new = InstagramPayload::fromArray($connection->payload)->folder;
                if ($old && $new && $old !== $new) {
                    DeleteMirroredMediaJob::dispatch($old);
                }
        ```
        ```php
        // vendor/laravel/framework/.../Model.php
        protected function finishSave(array $options)
        {
            $this->fireModelEvent('saved', false);
            if ($this->isDirty() && ($options['touch'] ?? true)) { $this->touchOwners(); }
            $this->syncOriginal();   // <-- runs synchronously, unconditionally, right after firing 'saved'
        }
        ```
        ```php
        // vendor/laravel/framework/.../Events/Dispatcher.php
        protected function createCallbackForListenerRunningAfterCommits($listener, $method)
        {
            return function () use ($method, $listener) {
                $payload = func_get_args();  // captured NOW, synchronously, at fire time
                $this->resolveTransactionManager()->addCallback(
                    function () use ($listener, $method, $payload) { $listener->$method(...$payload); }
                );
            };
        }
        ```

- [ ] **SEM-12** · P2 — Menu registry's actual key order (Square first) contradicts both its own top comment and MenuMerger's tie-break documentation (Uber Eats)
    - **Where:** config/partna.php:949-960 (`menu.platforms`); app/Services/Platforms/MenuMerger.php:52-55, 650-651
    - **Affects:** Any site with more than one connected online-ordering menu platform (e.g. Square + Uber Eats) — the actual platform whose price/image/store data wins a display-field tie is the opposite of what the code's own documentation says it should be.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm the intended tie-break order with whoever made the pricing/images decision behind the "Square first" comment.
        - If Uber Eats should still win ties: reorder the array so `uber-eats` is first and remove/replace the "Square first" comment. If Square winning is the current intent: update the FOUND-23 top comment and both `MenuMerger` comments to say so.
    - **Technical:** `MenuMerger::platforms()` is `array_keys(config('partna.menu.platforms'))`, consumed as the literal priority order in `priorityOrder()` (canonical-first, then registry order) and in `pickCheapest()`'s tie-break ("Strictly-less keeps the FIRST platform on a tie (content priority)"). `MenuMerger.php`'s own class-level comment says "in content-priority order (**Uber Eats** wins ties on display fields)," and its price-comparison docblock separately says "First platform wins a tie (content priority = **Uber Eats** over DoorDash)" — two independent statements in the consuming class, both naming Uber Eats. `config/partna.php`'s own top-of-registry comment (FOUND-23) also says "Key ORDER is content/merge priority (**Uber Eats** wins display-field ties)." Yet the actual array literally begins with `'square' => [...]`, preceded by its own inline comment "Square first — top priority for pricing/images over Uber Eats/DoorDash." Three separate documented statements (two files) say Uber Eats should win; the array's real order — the one the code mechanically consumes — makes Square win instead.
    - **Plain English:** Three different comments in two different files all say "when a business has both Square and Uber Eats connected, and their menu data disagrees on a price or picture, Uber Eats' version wins." But the actual list the code reads from puts Square at the very top, and the code treats "first in the list" as "wins ties" — so in practice Square's numbers and pictures win instead, contradicting what every other comment in the codebase says should happen.
    - **Evidence:**
        ```php
        // config/partna.php
        // Key ORDER is content/merge priority (Uber Eats wins display-field ties
        // and is the preferred spine).
        'menu' => [
            'platforms' => [
                // Square first — top priority for pricing/images over Uber Eats/DoorDash.
                'square' => [ ... ],
                'uber-eats' => [ ... ],
                'doordash' => [ ... ],
            ],
        ],
        ```
        ```php
        // MenuMerger.php
        // The platforms we union, in content-priority order (Uber Eats wins ties on
        // display fields). ... registry key order IS the priority order.
        private function platforms(): array { return $this->platforms ??= array_keys(config('partna.menu.platforms')); }
        ...
        // First platform wins a tie (content priority = Uber Eats over DoorDash).
        ```

- [ ] **SEM-13** · P2 — Manual LinkedIn-connect canonicalizes a company/school URL into a personal `/in/` profile URL
    - **Where:** config/partna.php:472-483 (`social_platforms.linkedin`); app/Services/Site/SocialLinkNormalizer.php:61-157
    - **Affects:** Any professional connecting their LinkedIn *company* or *school* page (not a personal profile) through the manual social-connect flow — the stored canonical URL points at `/in/<slug>`, a different, likely nonexistent page.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Preserve the matched path kind (`in`/`company`/`school`/`pub`) through the URL-path recursion and select the matching template segment when rebuilding, instead of always substituting into the single hardcoded `/in/{handle}` template.
        - Add a regression test pasting `linkedin.com/company/acme-inc` and asserting the stored canonical URL still says `/company/acme-inc`.
    - **Technical:** `SocialLinkNormalizer`'s class docblock states "Canonical URLs are always rebuilt from `url_template`, which is https-only." Its algorithm doc confirms: for a URL input, "Try to extract a handle via `url_path_extractor` — if it matches ... recurse into the handle path (gives a clean canonical URL)." `config('partna.social_platforms.linkedin').url_path_extractor` is `#^/(?:in|company|school|pub)/([a-zA-Z0-9-]{3,100})/?$#`, which matches `/company/acme-inc`, extracting `acme-inc` as the handle. That recursion then rebuilds the URL via `str_replace('{handle}', $cleaned, $config['url_template'])`, where `url_template` is hardcoded to `'https://linkedin.com/in/{handle}'` — always the personal-profile shape, regardless of which path kind matched. A pasted `linkedin.com/company/acme-inc` is therefore stored as `linkedin.com/in/acme-inc`, a different (likely nonexistent-as-a-person) page.
    - **Plain English:** LinkedIn has two different kinds of pages people connect: a personal profile and a company page. This code recognises both when someone pastes a link, but then rewrites *every* link — company pages included — into the personal-profile web address shape. So a business owner pasting their company's LinkedIn page ends up with a stored link that points at a personal profile page instead, which is almost certainly the wrong destination.
    - **Evidence:**
        ```php
        'linkedin' => [
            'url_template' => 'https://linkedin.com/in/{handle}',
            // Matches both /in/{handle} (personal) and /company/{handle} (company pages)
            'url_path_extractor' => '#^/(?:in|company)/([a-zA-Z0-9-]{3,100})/?$#',
            ...
        ```
        ```php
        // SocialLinkNormalizer — URL path matches, recurses into handle path:
        return [
            'url' => str_replace('{handle}', $cleaned, $config['url_template']),  // always /in/
            ...
        ```

- [ ] **SEM-14** · P2 — `ConnectionIdentity::matchExisting()`'s FOUND-14 lookup folds case for every surface, contradicting the allowlist its own step 1 enforces two lines above
    - **Where:** app/Routing/ConnectionIdentity.php:112-132
    - **Affects:** Users with a case-sensitive-handle connection (e.g. `discord.server`) that also has a `canonical_key` (FOUND-14 scheme row) — two genuinely distinct case-differing identifiers can incorrectly match as the same connection.
    - **Editorial:** already flagged by the scan tier with strong reasoning; adjudication confirmed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Gate step 2's folding on the same `$foldable = in_array($surfaceKey, self::CASE_INSENSITIVE_HANDLE_SURFACES, true)` computed for step 1.
        - Add a regression test on a non-allowlisted surface (e.g. `discord.server`) with a `canonical_key`-bearing row: two case-differing identifiers must not match.
    - **Technical:** The class docblock for `CASE_INSENSITIVE_HANDLE_SURFACES` is explicit: it is "the only ones `matchExisting()` may fold," specifically because `IdentifierKind::Handle` is a parse-strategy label that also covers case-sensitive opaque codes ("discord.server invite codes — critic catch"). Step 1 correctly gates on `$foldable`. Step 2 ("The FOUND-14 column, which exists for exactly this question") computes `$needle = self::fold($identifier)` and compares against `self::fold((string) $row->canonical_key)` unconditionally, for every surface, with no `$foldable` check at all — directly contradicting the rule step 1 (and the class docblock) just established.
    - **Plain English:** This file has a careful rule, spelled out in a comment: "only fold uppercase/lowercase differences away on platforms where the platform itself doesn't care about case — a Discord invite code IS case-sensitive, so don't fold those." The very next check in the same function ignores that rule completely and folds case for every platform, discord included. Two distinct, case-sensitive Discord invite codes could get treated as the same connection.
    - **Evidence:**
        ```php
        // Step 1 — correctly gated:
        $foldable = in_array($surfaceKey, self::CASE_INSENSITIVE_HANDLE_SURFACES, true);
        $needle1 = $foldable ? self::fold($identifier) : $identifier;
        foreach ($rows as $row) {
            $candidate = $foldable ? self::fold((string) $row->resource_id) : (string) $row->resource_id;
            if ($candidate === $needle1) { return (string) $row->id; }
        }

        // Step 2 — folds unconditionally, no $foldable check:
        $needle = self::fold($identifier);
        foreach ($rows as $row) {
            if ($row->canonical_key !== null && self::fold((string) $row->canonical_key) === $needle) {
                return (string) $row->id;
            }
        }
        ```

- [ ] **SEM-15** · P2 — `IriCanonicalizer::normalizePath()` collapses a path whose sole segment is `amp` (or `index.html`) to the site root
    - **Where:** app/Routing/IriCanonicalizer.php:340-374
    - **Affects:** A pasted profile link whose entire path is a presentation-suffix word (e.g. an Instagram account literally named `amp`) — the link canonicalizes to the platform's homepage and fails to detect as that account.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Only strip the trailing presentation-suffix segment when at least two segments remain (`count($segments) >= 2`) — never pop the last remaining segment down to an empty path.
    - **Technical:** The method's own comment explains the intent for presentation suffixes ("/amp, /index.html... name the same resource... a legitimate segment called 'amp' mid-path... is untouched") but only reasons about the *mid-path* case. For a URL like `instagram.com/amp`, `$segments = ['amp']`; the end-segment check matches `'amp'` against the presentation-suffix list and unconditionally `array_pop()`s it, leaving `$segments = []`, so `$normalized` becomes `'/'` — the site root, not the `amp` identifier. The comment's own reasoning ("a legitimate segment... is untouched") doesn't hold when `amp` is the *only* segment, since it's still popped.
    - **Plain English:** Some URLs end in `/amp` as a hint meaning "the fast-loading mobile version of this page" — the code correctly strips that off when it's tacked onto the end of a real address. But if someone's actual account handle happens to be the three letters "amp" — so the whole address is just `site.com/amp` with nothing else — the code still strips it, leaving nothing, and reads the link as "the homepage" instead of "this specific account."
    - **Evidence:**
        ```php
        // Presentation suffixes that name the same resource: /amp, /index.html.
        // Only ever removed from the END, so a legitimate segment called
        // "amp" mid-path (an artist named AMP) is untouched.
        $last = end($segments);
        if ($last !== false) {
            if (in_array(strtolower($last), ['amp', 'index.html', 'index.htm', 'index.php'], true)) {
                array_pop($segments);   // pops even when $segments had only ONE entry
            }
        }
        $normalized = '/'.implode('/', $segments);
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
        ```

- [ ] **SEM-16** · P2 — Order-platform sidecar fallback collection is computed but never assigned, so sidecar-only dishes never group under their menu
    - **Where:** app/Site/Actions/ActionCandidates.php:183-223
    - **Affects:** Menu/service items that belong only to a provider-bearing sidecar collection (e.g. an item that's only on the Uber Eats menu, not in any owner-authored category) — they render as standalone item actions instead of grouping under that ordering platform's action.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Assign `$home = $fallback` when the loop finishes with `$home === null` but `$fallback !== null`.
        - Add a unit test for an item whose only served collection has a non-null `provider`.
    - **Technical:** The method's docblock states the intended rule explicitly: "provider-bearing collections (order-platform sidecars) are **only a fallback**, and a dish with none floats as an item" — implying a dish with a provider-bearing collection *and no* provider-null collection should group under that fallback, not float. The loop computes `$fallback ??= $cid;` for the first provider-bearing collection seen, but the branch below only checks `if ($home === null)` — `$fallback` is never read again after being set, so a candidate with only sidecar collections always falls into the "float as an item" branch (`itemCandidate()`), never the grouped branch (`$grouped[$home][] = $item`).
    - **Plain English:** Picture a dish that only shows up on a restaurant's Uber Eats menu, not in any of the restaurant's own menu sections. The code's own comment says: in that case, group the dish under the Uber Eats ordering button as a fallback. Instead, the code computes that fallback grouping and then never actually uses it — the dish always ends up floating on its own instead of being grouped under "Order on Uber Eats."
    - **Evidence:**
        ```php
        foreach ((array) ($item['collectionIds'] ?? []) as $cid) {
            $cid = (string) $cid;
            if (! isset($collections[$cid])) { continue; }
            $fallback ??= $cid;                                    // computed...
            if (($collections[$cid]['provider'] ?? null) === null) {
                $home = $cid;
                break;
            }
        }
        if ($home === null) {                                      // ...but never checked here
            if (($c = self::itemCandidate($pool, $page, $item)) !== null) { $out[] = $c; }
        } else {
            $grouped[$home][] = $item;
        }
        ```

- [ ] **SEM-17** · P2 — Dashboard "sources" panel timestamps omit `->utc()`, in a file whose own comment warns about exactly this
    - **Where:** app/Site/Pools/PoolResolver.php:820-837 (`sourcesByItem`); cf. the correct pattern at PoolResolver.php:445-456 (`iso()`)
    - **Affects:** The owner dashboard's per-item "sources" list — `lastSeenAt` / `lastSyncedAt` — whenever the app server's local timezone isn't UTC.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the `$iso` closure in `sourcesByItem` to `Carbon::parse((string) $v)->utc()->toIso8601String()`, matching `PoolResolver::iso()`.
    - **Technical:** `PoolResolver::iso()` — used for the top-level public fields — has an extensive docblock explaining exactly why `->utc()` is load-bearing: "the query builder hands back naive `Y-m-d H:i:s` strings, which a browser's `Date()` reads as LOCAL time — a +10h error on an AEST reader, silently." The `$iso` closure inside `sourcesByItem()`, ~370 lines later in the *same file*, carries an almost identical inline warning immediately above it — "Timestamps go out as ISO-8601 with zone: the query builder hands back naive... strings which a browser's Date() would read as LOCAL time (a +10h badge — **review**)" — and then implements `Carbon::parse((string) $v)->toIso8601String()` *without* `->utc()`, the exact bug the comment describes. The `(... — review)` phrasing reads as an unresolved flag on this exact line.
    - **Plain English:** This file already fixed this exact bug once, with a big comment explaining why — timestamps from the database don't carry a timezone, so without converting them to UTC first, a browser can display a time that's off by several hours. That fix was applied to the site's main "last updated" timestamps. But a second, separate spot in the same file that builds the "where did this item come from" panel has the identical warning written right above it — and then doesn't apply the fix. The comment even flags itself with the word "review," suggesting it was noticed and never followed up on.
    - **Evidence:**
        ```php
        // PoolResolver::iso() — the correct pattern, elsewhere in this file:
        return Carbon::parse((string) $value)->utc()->toIso8601String();
        ```
        ```php
        // sourcesByItem() — same file, missing ->utc(), comment admits the risk:
        // Timestamps go out as ISO-8601 with zone: the query builder
        // hands back naive "Y-m-d H:i:s" strings which a browser's
        // Date() would read as LOCAL time (a +10h badge — review).
        $iso = fn ($v) => $v === null ? null : Carbon::parse((string) $v)->toIso8601String();
        ```

## P3 — Nice to have

- [ ] **SEM-18** · P3 — LinkedIn/Reddit catalog `canonicalUrl` templates can't represent every path kind their own detectors accept
    - **Where:** app/Catalog/Definitions/Linkedin.php:40-44; app/Catalog/Definitions/Reddit.php:39-43
    - **Affects:** Bare-handle-typed LinkedIn/Reddit connects (`BrandLinkConnect::expandHandle()`), and the FOUND-14 dedup-matching rebuild in `ConnectionIdentity` — narrower than it first appears, since the routing/detection lane for a *pasted URL* stores the URL verbatim rather than rebuilding it from this template (per the routing-lane convention documented elsewhere in this codebase).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either scope the detector's `path()` regex to just the kind the single canonical template can represent (`/in/` for LinkedIn, `/user|u/` for Reddit), or make the canonical URL builder path-kind-aware for these two surfaces.
    - **Technical:** Both surfaces use `IdentifierKind::Handle`, so their `canonical_url_template` genuinely is consumed by `BrandLinkConnect::expandHandle()`'s generic `preg_replace('~\{[a-z_]+\}~i', ..., $template)` for bare-handle-typed connects, and by `ConnectionIdentity`'s dedup-rebuild helper for `identifier_kind === 'handle'` rows. LinkedIn's detector path regex accepts `/(?:in|company|school|pub)/<handle>` but `canonicalUrl()` is hardcoded to `/in/{handle}/`; Reddit's accepts `/(?:u|user|r)/<handle>` but `canonicalUrl()` is hardcoded to `/user/{handle}/`. A bare-typed handle has no path-kind signal at all, so defaulting to the personal-profile shape there is plausibly intentional; the residual risk is narrow (dedup-matching only) once the pasted-URL path is accounted for.
    - **Plain English:** LinkedIn and Reddit each let people connect either a personal profile or an organisation/community page. The part of the code that turns a *typed handle* (no web address, just a username) into a full link only knows how to build the personal-profile shape, so a bare company or subreddit handle would come out wrong — though in practice most people paste a full link rather than typing a bare handle, which limits how often this actually bites.
    - **Evidence:**
        ```php
        // Linkedin.php
        ->canonicalUrl('https://www.linkedin.com/in/{handle}/')
        ->detect(Detector::url('linkedin.com')->path('#^/(?:in|company|school|pub)/(?<handle>...)/?$#u')...)
        ```
        ```php
        // Reddit.php
        ->canonicalUrl('https://www.reddit.com/user/{handle}/')
        ->detect(Detector::url('reddit.com')->path('#^/(?:u|user|r)/(?<handle>...)/?$#')...)
        ```

- [ ] **SEM-19** · P3 — Sector classifier's keyword order files a Google-sourced "Fitness trainer" under Gym, not Personal Trainer
    - **Where:** app/Services/Profile/SectorTaxonomy.php:149-230 (`KEYWORD_SECTORS`)
    - **Affects:** Personal trainers synced from Google Business whose category text is "Fitness trainer"/"Fitness coach" — they get the `gym` slug/label instead of `personal-trainer`; the same business synced from Instagram gets the correct slug (both share the same style bucket, so no visual regression).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `'trainer' => 'personal-trainer'` ahead of `'fitness' => 'gym'` in `KEYWORD_SECTORS`, or add explicit `'fitness trainer'`/`'fitness coach'` composite keys before both — mirroring what `INSTAGRAM_CATEGORY_SECTORS` already does.
    - **Technical:** `INSTAGRAM_CATEGORY_SECTORS` maps `'fitness trainer'` and `'fitness coach'` to `personal-trainer` via an exact-match tier that runs before the shared `KEYWORD_SECTORS` fallback — so the Instagram path resolves correctly. `fromGoogleCategory()` calls `classify()` directly against `KEYWORD_SECTORS` with no exact-match tier first. `KEYWORD_SECTORS`'s own docblock states the ordering discipline: "classify() returns the FIRST substring match, so a generic keyword must never precede a more specific colliding one" (worked example: 'barber' before 'bar'). `'fitness' => 'gym'` sits at index 9 (before 'trainer' at index 12); `classify('Fitness trainer', KEYWORD_SECTORS)` therefore matches `'fitness'` first and returns `gym`, violating the map's own stated specific-before-generic rule.
    - **Plain English:** The code has an explicit rule: a specific job title should always beat a vague qualifier — that's why "barber shop" doesn't get filed under the broader word "bar." One spot breaks that rule: a personal trainer whose Google listing says "Fitness trainer" gets filed as "Gym / Studio" because the broad word "fitness" is checked before the specific word "trainer." The same business, synced from Instagram instead, gets the correct "Personal trainer" label — so the label depends on which platform the data came from, even though it doesn't change how the page looks.
    - **Evidence:**
        ```php
        // KEYWORD_SECTORS (consulted directly by fromGoogleCategory()):
        'gym' => 'gym',
        'fitness' => 'gym',       // matches "Fitness trainer" FIRST
        'yoga' => 'yoga-instructor',
        'trainer' => 'personal-trainer',  // never reached for "Fitness trainer"

        // INSTAGRAM_CATEGORY_SECTORS (exact-match tier, consulted first on the IG path only):
        'fitness trainer' => 'personal-trainer',
        'fitness coach' => 'personal-trainer',
        ```

- [ ] **SEM-20** · P3 — YouTube feed retry masks a mid-loop transport failure as the previous non-200 response
    - **Where:** app/Services/Platforms/YoutubeScraper.php:114-152
    - **Affects:** YouTube channel refresh under intermittent feed failures — a few extra retry/sleep cycles beyond what the method's own comment intends, not a functional break (the retry loop still terminates via its existing attempt cap).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Break out of the loop immediately when a retry attempt (not just the first fetch) returns `null`, instead of falling back to the stale prior `$rss` value via `??`.
    - **Technical:** The comment states "Transport-level null deliberately does NOT retry" — true for the *first* fetch (a `null` there fails `is_array($rss)` and breaks immediately). But inside the retry loop, `$rss = $this->fetcher->tryFetch($feedUrl, $headers) ?? $rss;` falls back to the previous iteration's non-200 array when a later attempt returns `null`, so the loop treats that as "still non-200" and retries again rather than stopping — one extra retry cycle (up to ~2s) per masked null, bounded by the existing 4-attempt cap.
    - **Plain English:** The comment says "if the connection dies, don't bother trying again immediately." That's true for the very first attempt. But if a *later* retry hits a dead connection, the code quietly treats it as if it got the same old bad answer as before and tries yet again anyway — a little wasted time, though it eventually gives up either way.
    - **Evidence:**
        ```php
        // Transport-level null deliberately does NOT retry:
        // SafeUrlFetcher's null covers SSRF/DNS/timeout, where an immediate
        // second attempt is noise.
        foreach ([500_000, 1_000_000, 1_500_000, 2_000_000] as $delay) {
            if (! is_array($rss) || in_array($rss['status'], [200, 304], true)) { break; }
            usleep($delay);
            $rss = $this->fetcher->tryFetch($feedUrl, $headers) ?? $rss;  // masks a mid-loop null
        }
        ```

- [ ] **SEM-21** · P3 — Dev-only insights endpoint's item-score query no longer excludes page rows, now that pages live in the `action` family
    - **Where:** app/Http/Controllers/Api/User/Analytics/DevInsightsController.php:91-99, 158-163
    - **Affects:** The `/api/professional/dev-insights` diagnostic endpoint only (explicitly documented as dev/testing) — page entries appear duplicated as phantom `action:page:<id>` rows in the item breakdown, with null titles/zero impressions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an explicit exclusion for `content_key LIKE 'page:%'` (or exclude the whole `action` family) in `itemScores()`'s query, matching what `pageScores()` already assumes about where page rows live.
    - **Technical:** `pageScores()` documents and relies on the fact that since 2026-08-23, page rows live in `content_type = 'action'` with `content_key LIKE 'page:%'`. `itemScores()` filters with `where('content_type', '!=', 'page')` — a value page rows never had even before that migration — so it excludes nothing and every `action`-family row (page rows and actual action candidates alike) passes straight into the "items" breakdown.
    - **Plain English:** This is an internal debugging page for developers, not something regular users see. A recent change moved "page" data to live under a different internal label ("action"). One part of this debug page was updated to know that; a second part, right next to it, still filters using the old label, so page entries now also show up (incorrectly) in the "items" section of the debug view.
    - **Evidence:**
        ```php
        // pageScores(): "Pages are actions since 2026-08-23: their rows live in the 'action' family as page:<id>"
        ->where('content_type', 'action')->where('content_key', 'like', 'page:%')
        ```
        ```php
        // itemScores(): filter never matches a page row (content_type is 'action', not 'page')
        ->where('content_type', '!=', 'page')
        ```

- [ ] **SEM-22** · P3 — Fixture capture tool defaults several binary content types to `txt`, so binary bytes get run through the text redactor
    - **Where:** app/Console/Commands/FixturesCaptureCommand.php:210-224; app/Support/Fixtures/FixtureRedactor.php:15-30
    - **Affects:** `fixtures:capture --from=url` against a source whose response is `image/gif`, `image/x-icon`, `video/mp4`, or a generic `application/octet-stream` — the captured fixture can differ from the fetched bytes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend `extFromContentType()` to map every extension declared in `FixtureRedactor::BINARY_EXTS` (`gif`, `ico`, `mp4`, `bin`), so binary responses correctly bypass the redactor.
        - Add a test iterating `BINARY_EXTS` and asserting `extFromContentType()` produces a matching content-type mapping for each.
    - **Technical:** `FixtureRedactor::BINARY_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'ico', 'pdf', 'bin']` — bodies whose extension is in this list are returned untouched by `apply()`. `FixturesCaptureCommand::extFromContentType()` only maps `json`, `html`, `pdf`, `jpeg`→`jpg`, `png`, `webp`, `xml`, defaulting everything else (including `image/gif`, `video/mp4`, `image/x-icon`, `application/octet-stream`) to `txt` — which is *not* in `BINARY_EXTS`, so `FixtureRedactor::apply()` runs its email/phone regexes over the raw binary body.
    - **Plain English:** This is a developer tool for capturing sample API responses to use in tests. It has a list of file types it knows are "binary, leave them alone" — but the part that figures out what type a downloaded file is doesn't recognise several of those exact types (like GIFs and videos), so it labels them as plain text and runs a find-and-redact-personal-info pass over the raw video/image bytes, which can corrupt the captured file.
    - **Evidence:**
        ```php
        private const BINARY_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'ico', 'pdf', 'bin'];
        ```
        ```php
        return match (true) {
            str_contains($ct, 'json') => 'json',
            str_contains($ct, 'html') => 'html',
            str_contains($ct, 'pdf') => 'pdf',
            str_contains($ct, 'jpeg') => 'jpg',
            str_contains($ct, 'png') => 'png',
            str_contains($ct, 'webp') => 'webp',
            str_contains($ct, 'xml') => 'xml',
            default => 'txt',   // gif, mp4, ico, octet-stream all land here
        };
        ```

- [ ] **SEM-23** · P3 — `SafeUrlFetcher`'s body-size cap is double its documented value
    - **Where:** config/partna.php:1654-1660
    - **Affects:** All outbound user-supplied URL fetches (link previews, menu/shop scrapers) — the actual safety ceiling is looser than what the adjacent comment documents, though still bounded and safe.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set the default to `10 * 1024 * 1024` if 10 MB is the intended spec, or update the comment to say 25 MB if the larger cap is now intentional.
    - **Technical:** The comment directly above the config value says "10 MB is generous for the HTML / JSON those parse," but the default is `25 * 1024 * 1024` — 25 MiB, 2.5× the documented figure.
    - **Plain English:** A safety limit is documented as "cuts off at 10 MB" right next to the line that actually sets it to 25 MB. It's still a real limit that protects against runaway downloads, just not the number the comment says.
    - **Evidence:**
        ```php
        // Hard cap on a fetched terminal response body (bytes)... 10 MB is generous
        // for the HTML / JSON those parse.
        'max_bytes' => (int) env('PARTNA_HTTP_FETCH_MAX_BYTES', 25 * 1024 * 1024),
        ```

- [ ] **SEM-24** · P3 — DAST canary route reads `env()` directly in `routes/api.php`, outside `config/`
    - **Where:** routes/api.php:225-233
    - **Affects:** The DAST scanning pipeline's self-test canary route only — would silently fail to register under `config:cache` if the DAST runner ever caches config before scanning (currently it doesn't appear to, based on the "never present in a real deployed env" framing).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the flag into `config/partna.php` (e.g. `config('partna.dast_canary')`) and read it from there in the route file, per the project's own `env()`-outside-`config/` rule.
    - **Technical:** `env('DAST_CANARY')` is called directly in a route file rather than through `config()`. Per Laravel's standard `config:cache` behaviour, `env()` calls outside `config/*.php` can silently return `null` once config is cached (if the value is only ever set via a `.env` file rather than a real process/CI environment variable). The surrounding comment states this route is "Registered ONLY when DAST_CANARY=1, which nothing sets outside that one script — never present in a real deployed env," which limits the blast radius to the DAST CI job itself, not any real environment.
    - **Plain English:** This is a deliberately-fake vulnerable test route used only by an automated security scanner to prove the scanner actually works, and it's turned on by one environment variable set only by that scanner's own script — never in a real environment. The project has a rule that environment variables should always be read through the central config file instead of directly in code, because reading them directly can silently break under certain deployment optimizations. This one route breaks that rule, though the practical risk is confined to the scanning pipeline itself.
    - **Evidence:**
        ```php
        // Registered ONLY when DAST_CANARY=1, which nothing sets outside that one
        // script — never present in a real deployed env.
        if (env('DAST_CANARY')) {
            Route::get('/__dast_canary', fn (Request $request) => response('<div>'.$request->query('x').'</div>'));
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — UpdateSiteAction/UpdateSiteRequest correctness cluster:** SEM-9, SEM-10
    - **Why grouped:** Both live in the same two files (`UpdateSiteAction.php` + `UpdateSiteRequest.php`), both are strict-comparison/merge-source bugs in the same PATCH-settings code path, and fixing one without the other leaves the same publish/settings flow half-corrected.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

- **Bundle 2 — Ordering-request validation:** SEM-8
    - **Why grouped:** Single-file, single-rule fix in `SiteOrderingValidationRules.php`; small enough to run alone but fits naturally alongside Bundle 1 if picked up in the same session (same request-validation subsystem).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Analytics/pools ranking correctness:** SEM-4, SEM-17
    - **Why grouped:** Both are `app/Site/Pools`/`app/Services/Analytics` timestamp/ranking bugs surfaced on the public + dashboard read paths for the same popularity-scoring subsystem; a reviewer touching one will naturally have the other file open.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Action candidate grouping:** SEM-16
    - **Why grouped:** Standalone by file (`ActionCandidates.php`), but conceptually part of the same "action layer" work as Bundle 3 — fine to run in the same session back-to-back.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Config/comment drift cleanup:** SEM-12, SEM-13, SEM-23, SEM-24
    - **Why grouped:** All are `config/partna.php` (+ one `routes/api.php`) drift/hygiene fixes — same file, low individual risk, naturally reviewed together in one pass over the config file.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet (low complexity).

- **Bundle 6 — Catalog/routing canonicalization:** SEM-15, SEM-18
    - **Why grouped:** Both are catalog-detector/canonicalizer path-handling bugs in `app/Routing` + `app/Catalog/Definitions`, same root-cause shape (a single template can't represent every path a detector accepts).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 7 — Platform auto-sync gaps:** SEM-2, SEM-3
    - **Why grouped:** Both are `app/Services/Platforms` auto-sync omissions (a missing tombstone check, a missing social-key entry) affecting the same "seed connections automatically" subsystem.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 8 — Dashboard/controller polish:** SEM-5, SEM-7, SEM-21
    - **Why grouped:** Three independent, low-risk controller fixes (`ShopController`, `PoolController`, `DevInsightsController`) each touching one query/response — cheap to batch into one review pass.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 9 — Fixture/dev-tooling hygiene:** SEM-22
    - **Why grouped:** Standalone dev-tooling fix (`FixturesCaptureCommand.php`); no shared file with other bundles but low-risk enough to tack onto any other session with spare capacity.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 10 — Sector taxonomy ordering:** SEM-19
    - **Why grouped:** Standalone single-file fix (`SectorTaxonomy.php`), no shared root cause with other bundles.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 11 — YouTube feed retry:** SEM-20
    - **Why grouped:** Standalone single-file fix (`YoutubeScraper.php`).
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **SEM-1 — Fresha exhaustive-coverage data loss** · P1, real user data deletion; needs its own plan covering how partial coverage should be signalled without breaking the legitimate deletion path for services genuinely removed from Fresha.
- **SEM-6 — AnalyticsController 422/404 existence leak** · touches authorization/enumeration-boundary behavior on a public endpoint; run alone with sign-off even though effort is small.
- **SEM-11 — IntegrationConnectionObserver `getOriginal()` afterCommit bug** · the fix requires restructuring how pre-update state is captured across an Eloquent observer boundary (not a local one-line change) and interacts with the `SourceReconciler` transaction-safety invariant documented in the same file; needs its own plan and careful review against `tests/Feature/Routing/SourceReconcilerDeferredSideEffectsTest.php`.
- **SEM-14 — ConnectionIdentity case-fold inconsistency** · touches connection-identity matching/dedup logic that decides which existing user connection a new link folds into; a wrong fix here risks merging two distinct accounts, so it needs isolated review.
