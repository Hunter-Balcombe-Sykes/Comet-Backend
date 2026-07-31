# Semantic Correctness Audit — 2026-07-29

**Branch:** development
**Lens:** Semantic Correctness — code that compiles and type-checks but does the wrong thing (bundle scan across `app/Catalog`, `app/Console`, `app/Content`, `app/Http/Controllers`, `app/Ingest`, `app/Jobs`, `app/Observers`, `app/Providers`, `app/Routing`, `app/Services`, `config`, `routes`)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Controllers/Api/Platforms/ShopController.php`
- `app/Services/Platforms/Strategies/Fetch/ShopFetch.php`
- `app/Http/Controllers/Api/Platforms/InstagramController.php`
- `app/Http/Controllers/Concerns/DetectsClientInfo.php`
- `app/Http/Controllers/Api/Content/ContentKindController.php`
- `app/Http/Controllers/Api/Routing/SuggestionsController.php`
- `app/Observers/Core/IntegrationConnectionObserver.php`
- `app/Console/Commands/CatalogSyncCommand.php`
- `app/Console/Commands/RoutingCorpusCommand.php`
- `app/Providers/AppServiceProvider.php` + `config/partna.php`
- `app/Routing/IriCanonicalizer.php`
- `app/Ingest/Landing/DocHasher.php`
- `app/Content/Identity/Resolver.php`
- `app/Content/Identity/KeyClass.php`
- (verified-and-dropped: `app/Catalog/Definitions/Youtube.php`, `YoutubeMusic.php`, `Shopify.php`, `BigCartel.php`; `app/Routing/SourceReconciler.php`; `app/Ingest/Runtime/HttpIo.php`; `app/Ingest/SourceProvisioner.php`; `app/Services/Profile/FieldBindingResolver.php`)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 1 of 1 complete
- P2 Medium: 3 of 5 complete
- P3 Low: 0 of 7 complete

---

## P1 — Fix before pilot launch

- [x] **#SEM-1** · P1 — Shop product-picker "manual" guard is inert: the scheduled sync it claims to block only reads a different, global column
    - **Where:** app/Http/Controllers/Api/Platforms/ShopController.php:699-703 (setProducts), cf. docblock at :426-432; app/Services/Platforms/Strategies/Fetch/ShopFetch.php:12-20, 30-41
    - **Affects:** Any user with a connected Shop brand who hand-picks products via the picker (`PUT /api/platforms/shop/brands/{id}/selection`) while the site's global auto-latest toggle is on. Auto-latest defaults ON (`Site::DEFAULT` via `(bool) ($site->shop_auto_latest ?? true)`), so this is the out-of-the-box state for every new connection.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Either make `ShopFetch::fetch()` skip a brand whose `selection_mode === 'manual'` (restoring the guard's documented intent), or delete the guard and its comment from `setProducts()` and update the docblock in `updateBrand()`/`ShopFetch` to state plainly that only the global toggle protects a manual pick.
        - Add a feature test: connect a brand, `PUT .../selection` with a hand-picked set, leave `shop_auto_latest` at its default `true`, run `ShopFetch::fetch()`, assert the picked products either survive (if the fix restores the per-brand override) or that the dashboard is told up front the pick is temporary (if the fix is documentation-only).
    - **Technical:** Category (4) — a guard that is structurally present but semantically inert. `setProducts()` writes `selection_mode = 'manual'` with the comment "leaving latest mode on would silently overwrite it on the next scheduled sync," implying that write prevents the overwrite. But `ShopFetch::fetch()` (the scheduled-refresh strategy) explicitly states and implements the opposite: "the per-brand `selection_mode` column is dormant as of 2026-07-08; the one site-level toggle decides for every store," and its loop re-syncs every non-individual brand to latest products whenever `site.shop_auto_latest` is true, with **no read of `selection_mode` anywhere in the method**. `updateBrand()`'s own docblock (lines 426-432) independently confirms the same 2026-07-08 dormancy. So the write in `setProducts()` changes a column nothing consults; the actual protection the comment promises does not exist. Any user who curates products while auto-latest is on (the default) will have that curation silently replaced on the next scheduled cycle.
    - **Plain English:** The product picker has a safety label that says "hands off — I picked these myself," and the code that writes that label genuinely believes it stops the overnight auto-restocker from touching this shelf. But the overnight process was rewired weeks ago to check a completely different, building-wide switch instead of reading each shelf's label. So the label gets written, looks reassuring, and does nothing — a customer's hand-picked shop items quietly get swapped back to "whatever's newest" the next time the scheduled refresh runs, unless they separately found and disabled the site-wide auto-latest setting.
    - **Evidence:**
        ```php
        // ShopController.php:699-703 — writes the guard, believing it protects the pick
        // A hand-picked selection is a manual choice — leaving latest mode
        // on would silently overwrite it on the next scheduled sync.
        if (($brand->selection_mode ?? 'manual') === 'latest') {
            $brand->update(['selection_mode' => 'manual']);
        }
        ```
        ```php
        // ShopFetch.php:12-20 — the class docblock: selection_mode is dormant
        // Scheduled shop refresh: re-syncs every non-individual store's selection to
        // the store's newest products WHEN the user's GLOBAL auto-latest is on
        // (site.sites.shop_auto_latest — the per-brand selection_mode column is dormant
        // as of 2026-07-08; the one site-level toggle decides for every store). When
        // auto-latest is off, nothing is synced.
        ```
        ```php
        // ShopFetch.php:40-41 — the sync loop, which never reads selection_mode
        // Auto-latest ON → every non-individual store tracks its newest
        // products (the per-brand selection_mode is ignored under the global).
        ```

---

## P2 — Should fix

- [ ] **#SEM-2** · P2 — `RoutingCorpusCommand --check` compares only case count, not content
    - **Where:** app/Console/Commands/RoutingCorpusCommand.php:78-90
    - **Affects:** CI/developer confidence in the routing-corpus round-trip check — a detector change that swaps or corrupts two cases while keeping the same total count passes silently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the count comparison with a full content comparison (`$committed === $cases` after normalizing key order), or store and compare a content hash of the generated corpus.
    - **Technical:** Category (4) — logic that contradicts intent. `--check` is meant to "verify the committed corpus still round-trips," but the only check performed is `count($committed) !== count($cases)`. Two detectors swapping their generated URLs, or one case's identifier drifting, leaves the count unchanged and the command exits `SUCCESS` even though the committed fixture no longer reflects what the current catalog definitions produce.
    - **Plain English:** A stocktake that only counts boxes, never opens them, will happily report "all good" even if two boxes got their labels swapped. The `--check` flag is supposed to catch drift in the generated test corpus, but it currently only checks that the number of test cases hasn't changed — not that they're still the *same* cases.
    - **Evidence:**
        ```php
        if ($this->option('check')) {
            if (! is_file($path)) {
                $this->error('No generated corpus committed.');
                return self::FAILURE;
            }
            $committed = require $path;
            if (count($committed) !== count($cases)) {
                $this->error(sprintf('Corpus is stale: committed %d cases, definitions now produce %d. Run `php artisan routing:corpus`.', count($committed), count($cases)));
                return self::FAILURE;
            }
        }
        ```

- [ ] **#SEM-3** · P2 — `CatalogSyncCommand` never clears a brand's `successor_key` once removed from the definitions
    - **Where:** app/Console/Commands/CatalogSyncCommand.php:44-62
    - **Affects:** `catalog.brands` rows for any brand whose `successor_key` is removed from the compiled catalog definitions — the stale successor reference persists indefinitely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'successor_key'` to the upsert's `$update` column list (line 57) so a null in the current definition actually overwrites a stale value.
        - The unconditional second-pass loop (lines 58-62) then becomes redundant for the non-null case and can be removed, or kept as a no-op belt-and-braces pass.
    - **Technical:** Category (4) — logic that contradicts intent. The brand upsert builds each row with `'successor_key' => null` (line 51, since the FK can't be satisfied until every brand row exists), then upserts with an `$update` list that excludes `successor_key` entirely (line 57). A second loop only fixes the column when the *current* definition's `successor_key` is non-null (lines 58-62). When a brand's successor relationship is removed from the catalog definitions (goes from non-null to null), neither pass touches the column: the upsert doesn't write it, and the guarded second pass explicitly skips null. The row keeps whatever `successor_key` it had from the previous sync.
    - **Plain English:** When the master brand list says "this brand has been folded into that one," the sync correctly writes that link. But if a later update to the master list says "actually, that link is gone," the sync never erases it — it only ever fills the link in, never clears it out. The database keeps pointing to a merger that no longer exists in the source of truth.
    - **Evidence:**
        ```php
        // Line 51 — every upsert row nulls successor_key up front
        'successor_key' => null,
        // Line 57 — successor_key is NOT in the $update column list
        self::upsertBatched('catalog.brands', $brandRows, ['key'], ['display_name', 'homepage', 'lifecycle', 'last_synced_digest', 'tombstoned_at', 'updated_at']);
        // Lines 58-62 — second pass only fires for non-null values
        foreach (CompiledCatalog::brands() as $brand) {
            if ($brand['successor_key'] !== null) {
                DB::table('catalog.brands')->where('key', $brand['key'])->update(['successor_key' => $brand['successor_key']]);
            }
        }
        ```

- [x] **#SEM-4** · P2 — `IriCanonicalizer` tenant-label extraction mishandles a `www.`-prefixed suffix-override host
    - **Where:** app/Routing/IriCanonicalizer.php:143-159 (specifically 155-156)
    - **Affects:** Smart-detect placement for suffix-override platforms (Shopify `myshopify.com`, Big Cartel `bigcartel.com`) when the pasted/harvested URL carries a `www.` sub-label ahead of the tenant, e.g. `www.acme.myshopify.com`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Strip a leading `www.` segment before taking the remaining label as the tenant, e.g. split on `.` and drop a leading `www` element, rather than only checking equality against the bare string `'www'`.
        - Add a case to the routing corpus (`routing:corpus`, see #SEM-2) for `www.acme.myshopify.com` asserting `tenantLabel === 'acme'`.
    - **Technical:** Category (4) — the same "strip a bare `www`" guard exists twice in this method (once for suffix-override hosts at line 156, once for plain-registrable hosts at line 174) and both use `$label === 'www'`, an exact-string match. For a suffix-override host like `www.acme.myshopify.com`, `$label` computed at line 155 is `'www.acme'` — not equal to `'www'` — so the guard doesn't fire and `tenantLabel` becomes the compound string `'www.acme'`. Since `subdomain = $tenantLabel` (line 171) and every Shopify/Big Cartel detector's subdomain pattern is anchored `^[a-z0-9][a-z0-9-]{1,60}$` (no `.` in the character class), a subdomain value of `'www.acme'` cannot match any detector — the link goes undetected rather than being placed under the wrong tenant.
    - **Plain English:** A storefront URL like `www.acme.myshopify.com` should be recognised as the "acme" store. The code that pulls the store name out of the URL only knows how to remove a bare "www" — it doesn't know how to remove "www." when it's stuck in front of the real name. So it hands "www.acme" downstream instead of "acme," and the recognition rule (which expects a clean name with no dots) simply fails to match. The link is treated as unrecognised instead of being connected.
    - **Evidence:**
        ```php
        $label = substr($host, 0, -1 * (strlen($suffix) + 1));
        $tenantLabel = $label === 'www' ? null : ($label ?: null);
        ```

- [x] **#SEM-5** · P2 — Content-identity poisoned-key guard compares raw values while the merge index compares canonicalised values, so same-source duplicates that differ only by canonicalisation slip past the guard
    - **Where:** app/Content/Identity/Resolver.php:96-110 (poisonedKeys, signature at line 100) vs :117-142 (keyIndex, signature at line 133); app/Content/Identity/KeyClass.php:106-114 (canonicalise)
    - **Affects:** Cross-source identity merging for any key class where canonicalisation is non-trivial (ISRC/GTIN14: strip punctuation + uppercase; CanonicalUrl/EnclosureUrl: lowercase). Two items from the *same* source sharing a key value that differs only in case/punctuation will be merged instead of being excluded as unreliable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `poisonedKeys()`, canonicalise the value before building the signature (mirror what `keyIndex()` already does): `$canonical = $key->class->canonicalise($key->value); $signature = $key->class->value.'|'.$canonical;`.
        - Add a regression test: two `SourceItem`s from the same `sourceId` with `ISRC-1234` and `isrc1234` on the same key class must both trip the poison guard.
    - **Technical:** Category (4) — a guard that is present but semantically inert for a subset of inputs. `poisonedKeys()` (line 100) builds its signature from the **raw** `$key->value`. `keyIndex()` (line 126, 133) canonicalises the value first via `$key->class->canonicalise($key->value)` before building its signature, and then checks `isset($poisoned[$signature])` (line 134) against the raw-keyed poison map. `KeyClass::canonicalise()` genuinely transforms values for several classes (strip non-alphanumeric + uppercase for Isrc/Gtin14; lowercase for CanonicalUrl/EnclosureUrl). Whenever two same-source items carry a key value differing only by a canonicalisation-equivalent transformation, `poisonedKeys()` sees two distinct raw strings and never marks the signature poisoned, while `keyIndex()` computes the identical canonical signature for both and merges them — silently defeating the poison guard for exactly the inputs it exists to catch.
    - **Plain English:** When the same account lists the same identifier twice on two different items, the system is supposed to say "this identifier clearly isn't unique here, don't use it to merge things" — that's the poison check. But the poison check looks at the identifier exactly as written, while the actual merging logic looks at a cleaned-up version (same capitalisation, punctuation stripped). If the two copies are written slightly differently — say "ISRC-1234" versus "isrc1234" — the poison check sees two different strings and shrugs, but the merge logic cleans both down to the same thing and merges the items anyway, exactly the mistake the poison check was built to prevent.
    - **Evidence:**
        ```php
        // Resolver.php:100 — poisonedKeys uses the RAW value
        $signature = $key->class->value.'|'.$key->value;
        ```
        ```php
        // Resolver.php:126, 133-134 — keyIndex uses the CANONICALISED value
        $canonical = $key->class->canonicalise($key->value);
        // ...
        $signature = $key->class->value.'|'.$canonical;
        if (isset($poisoned[$signature])) {
            continue;
        }
        ```
        ```php
        // KeyClass.php:106-114 — canonicalise genuinely transforms these classes
        return match ($this) {
            self::Isrc, self::Gtin14 => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? $value),
            self::CanonicalUrl, self::EnclosureUrl => strtolower($value),
        ```

- [x] **#SEM-6** · P2 — `detectDeviceType`'s bot check is a small subset of the sibling `isBotUserAgent`, so most bot traffic is labelled `desktop`/`mobile` instead of `bot`
    - **Where:** app/Http/Controllers/Concerns/DetectsClientInfo.php:88-91 (detectDeviceType) vs :50-66 (isBotUserAgent)
    - **Affects:** Analytics data quality for every request that runs through this trait — page-view/beacon device-type dimensions, and any downstream report or filter that trusts `detectDeviceType()` to flag automated traffic.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the three-substring check in `detectDeviceType` with a call to `$this->isBotUserAgent($ua)`, reusing the full signal list.
        - Add a test that feeds every `isBotUserAgent` signal through `detectDeviceType` and asserts `'bot'`.
    - **Technical:** Category (4) — logic that contradicts intent. Both methods live in the same trait and both classify bot traffic, but `detectDeviceType` only checks `str_contains($u, 'bot'|'spider'|'crawler')`, while `isBotUserAgent` additionally covers ~17 more signals (`headlesschrome`, `puppeteer`, `playwright`, `selenium`, `phantomjs`, `facebookexternalhit`, `twitterbot`, `linkedinbot`, `yandexbot`, `baiduspider`, `slurp`, `curl/`, `wget/`, `python-requests`, `python-urllib`, `libwww-perl`, plus named SEO crawlers). None of those substrings overlap with `'bot'`/`'spider'`/`'crawler'`, so a request from Puppeteer, curl, or Facebook's link-preview crawler returns `true` from `isBotUserAgent()` but `'desktop'` (or occasionally `'mobile'`) from `detectDeviceType()` — the two methods disagree on the same input.
    - **Plain English:** Two checkpoints guard the same door, both supposedly looking for known bots. One has a long, well-maintained watchlist; the other only checks for three obvious words. Most of what the first checkpoint would catch — headless browsers, command-line scripts, link-preview crawlers — walks straight past the second one and gets labelled an ordinary desktop or mobile visitor. Any report built on "device type" quietly mixes bot traffic in with real people.
    - **Evidence:**
        ```php
        // isBotUserAgent — the full signal list (lines 50-66)
        $signals = [
            'bot', 'spider', 'crawler',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'rogerbot',
            'facebookexternalhit', 'twitterbot', 'linkedinbot',
            'yandexbot', 'baiduspider', 'slurp',
            'python-requests', 'python-urllib',
            'curl/', 'wget/',
            'libwww-perl',
            'headlesschrome', 'phantomjs', 'puppeteer',
            'playwright', 'selenium',
        ];
        ```
        ```php
        // detectDeviceType — only three signals (lines 88-91)
        if (str_contains($u, 'bot') || str_contains($u, 'spider') || str_contains($u, 'crawler')) {
            return 'bot';
        }
        ```

---

## P3 — Nice to have

- [ ] **#SEM-7** · P3 — `DocHasher`'s wildcard volatility segment is an unimplemented stub
    - **Where:** app/Ingest/Landing/DocHasher.php:56-59
    - **Affects:** No connector today declares a volatile path containing `*` (confirmed by repo-wide search) — purely latent risk for a future connector.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Implement the wildcard branch: for a `*` head segment, apply the remaining path segments to every element of the list, matching the docblock's stated intent (which, note, is already achieved implicitly for non-wildcard list paths via the `array_is_list` branch a few lines below — confirm a wildcard segment is still a needed, distinct syntax before implementing).
        - Add a unit test once implemented.
    - **Technical:** Category (4) — dead/unimplemented branch. `applyVolatility()`'s `$head === '*'` case returns `$doc` completely unmodified instead of recursing into list elements. No current connector's manifest declares such a path (grep confirms), so this has zero live effect; it would only matter if a future volatile-path declaration used an explicit `*` segment.
    - **Plain English:** There's a switch on the machine labelled for handling repeating list items specially, but the wiring behind it was never connected — flipping it does nothing. Nothing uses that switch today, so no harm is being done, but it would silently fail to protect against a rotating CDN parameter if someone starts using it.
    - **Evidence:**
        ```php
        // A wildcard segment applies the rule to every element of a list.
        if ($head === '*') {
            return $doc;
        }
        ```

- [ ] **#SEM-8** · P3 — Subdomain-availability throttle limit is documented as config-driven but the config key doesn't exist
    - **Where:** app/Providers/AppServiceProvider.php:488; config/partna.php:996-1046
    - **Affects:** Operability only — the 30/min limit for `GET /api/site/subdomain-availability` can't be tuned per environment without a code change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'subdomain_availability_authed_per_minute' => (int) env('PARTNA_THROTTLE_SUBDOMAIN_AVAILABILITY_AUTHED_PER_MINUTE', 30)` to the `throttle` array in `config/partna.php`, matching every sibling limiter in that array (`signup_availability_per_minute`, `login_identifier_per_minute`, etc.).
    - **Technical:** Category (2) — config-key drift. The rate limiter reads `config('partna.throttle.subdomain_availability_authed_per_minute', 30)`, but this key is genuinely absent from `config/partna.php`'s `throttle` array (confirmed by reading the full array) — every other limiter in that same array follows the `'key' => (int) env('PARTNA_THROTTLE_...', default)` pattern. The `config()` call always falls through to its inline default of `30`, functioning as a hardcoded literal despite looking config-driven.
    - **Plain English:** Every other speed limit in the settings file can be changed from outside the code, by an operator, without redeploying. This one particular limit looks like it works the same way, but the settings file never actually got the matching entry added — so today it's stuck at 30 requests/minute no matter what an operator tries to configure.
    - **Evidence:**
        ```php
        return Limit::perMinute(config('partna.throttle.subdomain_availability_authed_per_minute', 30))
            ->by('subdomain-availability:'.$key)
            ->response(fn () => response()->json(['message' => 'Too many requests. Please try again later.'], 429));
        ```

- [ ] **#SEM-9** · P3 — `IntegrationConnectionObserver::updated()` deserialises the full Instagram payload on every write, not only on payload changes
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:497-517
    - **Affects:** No live bug today — the inner `$old !== $new` check already no-ops correctly. Becomes a real bug if `InstagramPayload::fromArray()` ever gains a side effect (cache write, external call, stricter validation).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$connection->wasChanged('payload')` to the top-level gate alongside the existing `platform !== Instagram` check, so a `last_visited_at`-only write short-circuits before deserialisation.
    - **Technical:** Category (4) — a guard that could be tighter without changing today's behaviour. The only gate is `$connection->platform !== Platform::Instagram->value`; every other update to an Instagram connection row (e.g. a `last_visited_at` bump) still runs `InstagramPayload::fromArray()` twice and compares folders. The comparison correctly finds `$old === $new` and does nothing today, but the deserialisation itself runs needlessly on every write, and any future change to `InstagramPayload::fromArray()` that adds a side effect would fire on writes that never touched the payload.
    - **Plain English:** Every time an Instagram connection's "last viewed" timestamp gets touched — which happens just from routine bookkeeping — the code re-opens and re-reads the entire Instagram data bundle to check whether a cleanup should run, even though nothing in that bundle changed. Today it correctly notices nothing changed and stops, so there's no visible problem. But it's doing unnecessary work on every touch, and if someone later adds a step to that "re-open the bundle" process, it would now run on updates that have nothing to do with it.
    - **Evidence:**
        ```php
        public function updated(IntegrationConnection $connection): void
        {
            if ($connection->platform !== Platform::Instagram->value) {
                return;
            }

            try {
                $old = InstagramPayload::fromArray($connection->getOriginal('payload'))->folder;
                $new = InstagramPayload::fromArray($connection->payload)->folder;
                if ($old && $new && $old !== $new) {
                    DeleteMirroredMediaJob::dispatch($old);
                }
            } catch (\Throwable $e) {
        ```

- [ ] **#SEM-10** · P3 — `InstagramController::connect()` dispatches `InstagramConnectJob` without `->afterCommit()`, unlike every other deferred-connect dispatch
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php:124
    - **Affects:** No current bug — the row-write here uses `Cache::lock()` (a Redis lock), not `DB::transaction()`, so `updateOrCreate()` auto-commits before the dispatch runs. Would become a real race if a future change wraps this flow in an explicit DB transaction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->afterCommit()` to the `InstagramConnectJob::dispatch(...)` call, matching `DefersBespokeConnect::connectDeferred()` and `ShopController::addBrand()`.
    - **Technical:** Category (1) — a real method (`dispatch()`) called in a way that's legal but diverges from the project's own established contract for this exact pattern. `DefersBespokeConnect.php:97` and `ShopController.php:364` both append `->afterCommit()` to their deferred-connect job dispatches specifically so the job can't run before its prerequisite row is committed and visible. `InstagramController::connect()` is the one such dispatch that omits it. Verified today's flow uses `Cache::lock(...)->block(...)` around the `updateOrCreate()`, not a DB transaction, so the row is already committed by the time `dispatch()` runs — no live bug. The gap is real, though, relative to the codebase's own stated pattern.
    - **Technical (fix cost/benefit):** effort is trivial and removes a latent trap for the next person who touches this method.
    - **Plain English:** Three near-identical "connect" flows all have a rule: don't start the background job until the database write is fully saved. Two of them follow the rule explicitly; this one doesn't, because right now nothing wraps its write in a way that could delay the save. If someone later adds that wrapping (for good reasons elsewhere), this one flow would start the job before the row it depends on actually exists.
    - **Evidence:**
        ```php
        InstagramConnectJob::dispatch($user->id, $username, $connection->id, notifyOnConnect: true);
        ```

- [ ] **#SEM-11** · P3 — `ContentKindController`'s `Cache-Control: private` contradicts its own docblock's "cached at the edge" claim
    - **Where:** app/Http/Controllers/Api/Content/ContentKindController.php:22-23 (docblock), :33 (header)
    - **Affects:** CDN/edge cache efficiency for `GET /api/content/kinds` — every request re-hits the origin instead of being served from a shared cache, contrary to the class's stated design.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `private` to `public` in the `Cache-Control` header, since the endpoint carries no tenant data and is identical for every account (confirmed: no `ResolveCurrentUser`/per-user branching in this controller).
        - Or, if `private` is intentional (e.g. a deliberate choice to avoid CDN caching for this endpoint), update the docblock to remove the "cached at the edge" claim.
    - **Technical:** Category (2)/(4) — the docblock states "it is the same answer for every account and is cached at the edge," but `Cache-Control: private` instructs every shared/CDN cache not to store the response at all, directly undermining that stated design. The registry is a compile-time, tenant-agnostic value (`KindRegistry::all()`), so there is no correctness reason for `private` here.
    - **Plain English:** The code's own comment says "this answer never changes per-user, so we let the network cache it for speed." The actual instruction sent to browsers and CDNs says the opposite: "don't share this in any cache." It's like a sign reading "free samples, please share" taped to a locked box — one or the other needs to change.
    - **Evidence:**
        ```php
        * The registry is a compile-time declaration with no tenant data in it, so it
        * is the same answer for every account and is cached at the edge.
        */
        class ContentKindController extends ApiController
        {
            ...
            return $this->success(['kinds' => ContentKindResource::collection(KindRegistry::all())])
                ->header('Cache-Control', 'private, max-age='.self::CACHE_SECONDS);
        ```

- [ ] **#SEM-12** · P3 — `SuggestionsController` scopes ownership with an inline `where('user_id', ...)` instead of the project's `authorizeForUser` + Policy pattern
    - **Where:** app/Http/Controllers/Api/Routing/SuggestionsController.php:74-97 (accept/dismiss), :132-139 (findIntent)
    - **Affects:** Architectural consistency of the authorization surface for the suggestions inbox. No live bypass — the inline `where('user_id', $userId)` correctly scopes every read to the caller.
    - **Effort:** S (~0.5–1h) to document an exemption; M (~2–4h) to retrofit a model + Policy
    - **What to do:**
        - `routing.source_intents` is queried via `DB::table()`, not an Eloquent model, so there is no natural Policy target today. Either: (a) add a justified `POLICY_EXEMPT` entry in `tests/Feature/Security/PolicyCoverageTest.php` documenting that this raw-table read is intentionally out of the Policy system, or (b) promote `source_intents` to a model with a corresponding Policy and switch `accept()`/`dismiss()` to `authorizeForUser()`.
    - **Technical:** Category (5) — codebase-idiom drift. The doctrine ("Authorization through Policies, never inline... Always `$this->authorizeForUser($user, 'verb', $resource)`") is followed by every other controller in this scope; `SuggestionsController::findIntent()` instead scopes via `->where('user_id', $userId)` directly against a `DB::table()` query, since `routing.source_intents` has no Eloquent model and thus no Policy target. Functionally correct (no cross-tenant leak — 404 is returned for a non-owned/nonexistent intent, matching doctrine's 403-vs-404 rule), but it sits outside the sweep `PolicyCoverageTest` checks, so a future refactor of this table won't get the same CI safety net every other tenant-owned resource has.
    - **Plain English:** Every other door in this part of the building is wired into the central security system that logs and checks every entry against a master list. This one door instead has a guard who manually checks IDs against a sticky note — it works today, nobody gets through who shouldn't, but it's invisible to the building-wide security audit, so nobody would notice if it quietly stopped working correctly later.
    - **Evidence:**
        ```php
        // accept() — no authorizeForUser() call; ownership enforced only via findIntent()
        $intent = $this->findIntent($user->id, $intentId);
        if ($intent === null) {
            return $this->error('That suggestion is no longer available.', 404);
        }
        ```
        ```php
        // findIntent() — inline user_id scoping on a raw DB::table() query
        private function findIntent(string $userId, string $intentId): ?object
        {
            return DB::table('routing.source_intents')
                ->where('id', $intentId)
                ->where('user_id', $userId)
                ->whereIn('state', ['proposed', 'blocked'])
                ->first();
        }
        ```

- [ ] **#SEM-13** · P3 — `ShopController::updateBrand()` still accepts and persists `selectionMode`/`linkMode` to columns its own docblock calls dormant
    - **Where:** app/Http/Controllers/Api/Platforms/ShopController.php:426-432 (docblock), :449-454 (writes)
    - **Affects:** No user-visible behaviour — the docblock states "the dashboard no longer sends them." Confuses future readers into thinking these per-brand columns still gate something.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `selectionMode`/`linkMode` write branches from `updateBrand()` (or keep accepting the keys for back-compat but stop persisting them), now that #SEM-1 is also being addressed for the same dormant column.
    - **Technical:** Category (2) — config/column write with no consulted effect. Per the same 2026-07-08 dormancy this class documents (and #SEM-1 confirms via `ShopFetch`), `selection_mode`/`link_mode` on `site.shop_brands` are read nowhere in the public render path or scheduled refresh; only the site-level `shop_link_mode`/`shop_auto_latest` columns matter. `updateBrand()` still writes both per-brand columns when present in the request, per its own docblock purely for backward compatibility with old dashboard payloads.
    - **Plain English:** This form field still exists and still gets saved, even though the docblock right above it says nobody reads it anymore and the current dashboard doesn't even send it. It's harmless today, but it's exactly the kind of leftover wiring that makes the next engineer waste time wondering whether it matters.
    - **Evidence:**
        ```php
        if (array_key_exists('selectionMode', $validated)) {
            $updates['selection_mode'] = $validated['selectionMode'];
        }
        if (array_key_exists('linkMode', $validated)) {
            $updates['link_mode'] = $validated['linkMode'];
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Shop dormant `selection_mode` cleanup:** #SEM-1, #SEM-13
    - **Why grouped:** same file (`ShopController.php`), same root cause — the 2026-07-08 selection_mode/link_mode dormancy.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet. Escalate implement → Opus for #SEM-1 — the fix touches `ShopFetch`'s scheduled-sync semantics and needs careful reasoning about the existing lock/concurrency comments in this file before deciding whether to restore a per-brand override or simply correct the documentation.

- **Bundle 2 — Instagram connection lifecycle hardening:** #SEM-9, #SEM-10
    - **Why grouped:** both are Instagram-connection future-proofing fixes (missing `wasChanged` gate; missing `->afterCommit()`) with zero live impact today, same subsystem.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Catalog & config sync hygiene:** #SEM-2, #SEM-3, #SEM-8
    - **Why grouped:** all three are small, independent correctness gaps in sync/config tooling (console commands + service-provider config wiring) — no shared file, but same "tooling integrity" theme and all trivial, non-interacting fixes.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Content subsystem cleanup:** #SEM-5, #SEM-11
    - **Why grouped:** both live under `app/Content`/`app/Http/Controllers/Api/Content`, both are small self-contained correctness fixes.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Small independent hardening fixes:** #SEM-4, #SEM-6, #SEM-7
    - **Why grouped:** no shared root cause or subsystem; batched purely because each is a small (S-effort), single-file, non-interacting fix suited to one short session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEM-12 — SuggestionsController inline auth pattern** · touches authorization (inline ownership check vs. the project's Policy doctrine) — per doctrine, any authorization-pattern finding runs alone with its own plan + sign-off, even though the underlying risk is low (no confirmed bypass).
