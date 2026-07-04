# Foundational Durability Audit — 2026-07-04

**Branch:** development
**Lens:** Foundational durability & extensibility — shotgun surgery, denormalization debt, leaky abstraction boundaries in the platform-integration/menu-scraping subsystem and JSON-heavy storage paths
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Controllers/Api/Platforms/*`
- `app/Services/Platforms/*`
- `supabase/migrations/*`
- `app/Models/**`, `config/partna.php`
- `app/Jobs/Platforms`, `app/Services/Notifications`, `app/Jobs/Notifications`, `app/Services/Accounts`, `app/Services/FeatureFlags`
- `app/Http/Controllers/Api/User`, `app/Http/Controllers/Api/Staff`, `app/Http/Controllers/Api/PublicSite`, `app/Http/Controllers/Concerns`
- `app/Services/User`, `app/Services/PublicSite`, `app/Services/Analytics`, `app/Services/Streaming`, `app/Services/BotProtection`
- `app/Http/Requests/*`, `app/Http/Resources/*`
- `routes/*`, `app/Console`, `app/Policies`
- `app/Jobs/Moderation`, `app/Mail/*`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 24 complete
- P3 Low: 0 of 25 complete

---

## P1 — Fix before pilot launch

- [ ] **#FOUND-1** · P1 — GDPR data-export registry requires editing 3+ locations in lockstep; a missed step silently omits a user's data
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php` (`build()`, `stream()`), `app/Services/User/DataExport/DataExportZipWriter.php` (`csvNameFor()`)
    - **Affects:** Any real user who submits a GDPR data-export request after a new exportable table/section is added without every edit site being touched — the export silently ships incomplete, which is a compliance failure, not just a maintenance cost.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Define an `ExportSection` interface (`name()`, `kind()`, `stream(string $userId): Generator`, `csvColumns(): ?array`, `csvFileName(): ?string`).
        - Register sections in a `DataExportRegistry` populated in a service provider, mirroring the `SectionVisibilityRegistry` / `SectionVisibilityContract` pattern already in `app/Services/User/Visibility/` — confirmed to exist and follow exactly this shape.
        - Have `build()`, `stream()`, and `csvNameFor()` all iterate the registry instead of hand-enumerating ~20 sections across three places.
    - **Technical:** `build()` explicitly enumerates ~20 keys, each calling a dedicated `stream*()` method; `stream()` duplicates the same enumeration with individual yield blocks; `DataExportZipWriter::csvNameFor()` is a third hand-maintained `match()`. Adding one exportable entity (e.g. a new child table) requires touching all three with no compiler or test enforcement of completeness — a missed step is a silent, undetected data omission on a compliance-sensitive endpoint. The registry pattern already proven in `SectionVisibilityRegistry` collapses this to one class + one `register()` call.
    - **Plain English:** When someone asks Partna for a full copy of their data (a legal right), the system builds that copy by manually checking off ~20 boxes on a list, and that list is written out three separate times in slightly different forms. If a developer adds new data later and forgets to update all three copies of the list, the export silently leaves that data out — with nothing warning anyone it happened. A single master list that all three places read from removes that risk.
    - **Evidence:**
        ```php
        return [
            'metadata' => $this->metadata($professional),
            'profile' => $this->profile($professional),
            'site' => $this->site($userId),
            'waitlist' => $this->collect($this->streamWaitlistSignups($lookupEmail)),
            ...
        ```
        ```php
        private function csvNameFor(string $sectionName): string
        {
            return match ($sectionName) {
                'customers' => 'customers.csv',
                'enquiries' => 'enquiries.csv',
                default => str_replace('.', '_', $sectionName).'.csv',
            };
        }
        ```

## P2 — Should fix

- [ ] **#FOUND-2** · P2 — TurnstileProvider and HCaptchaProvider duplicate ~80% of their HTTP/error-handling shape
    - **Where:** `app/Services/BotProtection/Providers/TurnstileProvider.php`, `app/Services/BotProtection/Providers/HCaptchaProvider.php`
    - **Affects:** Adding a third CAPTCHA provider (e.g. reCAPTCHA v3); any cross-provider change to timeout/error handling.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract an abstract `HttpSiteVerifyProvider` owning config-key resolution, the timeout-seconds calculation, the form-encoded POST, and the `ConnectionException`/`RequestException` → `CaptchaProviderException` mapping.
        - Each concrete provider implements only `buildResult(array $responseData): VerificationResult`.
    - **Technical:** Both `verify()` methods read `config('partna.bot_protection.drivers.{name}')`, compute a timeout from `enforce_timeout_ms`, POST as form data, catch the same two exception types, and check `serverError()` — identical sequences, differing only in the response-to-DTO mapping. A third provider would copy ~40 lines and change 3.
    - **Plain English:** Two CAPTCHA providers are wired up with near-identical plumbing — same HTTP call, same error handling, same timeout logic — just calling different vendors. A third provider would mean copying that plumbing a third time. One shared base class with a vendor-specific "translate the response" step removes the copy-paste.
    - **Evidence:**
        ```php
        $config = config('partna.bot_protection.drivers.turnstile');
        $defaultMs = (int) config('partna.bot_protection.enforce_timeout_ms', 3000);
        $timeoutSec = ($timeoutMs ?? $defaultMs) / 1000;
        try {
            $response = Http::asForm()->timeout((float) $timeoutSec)->post($config['verify_url'], [...]);
            if ($response->serverError()) { throw ...; }
        } catch (ConnectionException $e) { throw ...; }
        catch (RequestException $e) { throw ...; }
        ```

- [ ] **#FOUND-3** · P2 — LiveStatusPoller + StreamingTokenManager hardcode a per-platform `match`/config map that a third streaming platform would copy again
    - **Where:** `app/Services/Streaming/LiveStatusPoller.php` (`poll()`, `pollTwitch()`, `pollKick()`), `app/Services/Streaming/StreamingTokenManager.php` (`PLATFORM_CONFIG`)
    - **Affects:** Adding a third streaming platform (e.g. YouTube Live).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Define a `StreamingPlatformDriver` interface (`getLiveHandles(array $handles): array`).
        - Register platforms in `config('partna.streaming.platforms')` (driver class + batch size + OAuth config); replace `poll()`'s `match` with a loop over registered platforms.
    - **Technical:** `pollTwitch()` and `pollKick()` are structurally identical (batch → fetch → `array_flip` → write status), differing only in batch size and client class. `StreamingTokenManager::PLATFORM_CONFIG` is a second hardcoded per-platform map. Both would gain a third copy on the next platform.
    - **Plain English:** Twitch and Kick each get their own nearly-identical "check who's live" routine. Adding a third streaming platform means copying that routine again instead of just registering a new entry in a list.
    - **Evidence:**
        ```php
        match ($platform) {
            'twitch' => $this->pollTwitch($handles),
            'kick' => $this->pollKick($handles),
            default => Log::warning('streaming.unknown_platform', ['platform' => $platform]),
        };
        ```

- [ ] **#FOUND-4** · P2 — Seven platform highlight-save Form Requests are structurally identical, differing only in field name and cap
    - **Where:** `app/Http/Requests/Platforms/Save{AppleMusic,ApplePodcast,Bandcamp,Vimeo,Youtube,YoutubeMusic}HighlightsRequest.php`, `SetShopProductsRequest.php`
    - **Affects:** Every new platform with highlight curation — each one currently means a new, hand-copied request class.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create one `SavePlatformHighlightsRequest` that resolves its field name and max count from `PlatformDescriptor`, mirroring the `ResolvesConnectRules` pattern already used by `PlatformConnectRequest`.
        - Delete the seven per-platform classes once routes point at the unified request.
    - **Technical:** Every one of these classes validates a single array field with `present|array|max:N` + `*|string|max:M`, differing only in field name (`albumIds`, `itemIds`, `videoIds`, `productIds`) and N. This is exactly the shape `PlatformConnectRequest` already solved for connect flows.
    - **Plain English:** Seven near-identical forms exist that only differ in one label and one number. A new platform means photocopying one and changing those two things — easy to get subtly wrong. One shared form template that reads the label/number from a central list removes the copy-paste.
    - **Evidence:**
        ```php
        // SaveAppleMusicHighlightsRequest
        'albumIds' => ['present', 'array', 'max:5'],
        'albumIds.*' => ['string', 'max:30'],
        ```
        ```php
        // SaveYoutubeMusicHighlightsRequest
        'itemIds' => ['present', 'array', 'max:5'],
        'itemIds.*' => ['string', 'max:30'],
        ```

- [ ] **#FOUND-5** · P2 — Platform route `defaults('platform', ...)` strings are hand-typed ~36 times with no registry validation
    - **Where:** `routes/api/integrations.php` (every `->defaults('platform', '<slug>')`)
    - **Affects:** Every route registered for a platform; a typo in one occurrence silently 404s or misroutes that specific action with no boot-time warning.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Generate the `defaults('platform', ...)` value from the `PlatformRegistry` descriptor at route-registration time instead of a literal string, for the platforms that still declare their own route groups.
        - Add a CI check (or reuse `AuditPipelineIntegrityTest`-style coverage) asserting every hardcoded platform default resolves in the registry.
    - **Technical:** Laravel's route `defaults()` performs no validation against any registry — `GenericPlatformController` and per-platform controllers read this string at runtime to resolve the descriptor. A drifted or mistyped slug fails silently at request time, not at deploy time.
    - **Plain English:** Every platform connection route carries a hand-typed tag saying which platform it's for. There are about three dozen of these tags. If someone mistypes one, nothing catches it until a real user clicks the button and gets an error. Generating the tag from the platform's single master registry entry instead of retyping it removes that risk.
    - **Evidence:**
        ```php
        Route::post('/connect', [YoutubeController::class, 'connect'])->defaults('platform', 'youtube');
        Route::post('/connect', [BandcampController::class, 'connect'])->defaults('platform', 'bandcamp');
        Route::post('/connect', [YoutubeMusicController::class, 'connect'])->defaults('platform', 'youtube-music');
        ```

- [ ] **#FOUND-6** · P2 — EventbriteController/HumanitixController are pure delegation wrappers with no shared interface
    - **Where:** `app/Http/Controllers/Api/Platforms/EventbriteController.php`, `app/Http/Controllers/Api/Platforms/HumanitixController.php`
    - **Affects:** Adding a third events platform (Ticketmaster, Meetup) — currently means copying one of these ~70-line files.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Define an `EventsPlatformScraper` interface with the methods `EventsPlatformController` currently declares abstract.
        - Make `EventsPlatformController` concrete, resolving its scraper from `PlatformRegistry` by the route's platform slug; delete the two subclasses.
    - **Technical:** Both classes bind one scraper and implement six methods that each delegate directly to that scraper — no logic beyond wiring. This is the same registry-collapse opportunity `GenericPlatformController` already proved out for the link-only archetype.
    - **Plain English:** Eventbrite and Humanitix each have their own controller file, but both files just forward every call to their scraper — there's no real logic in either. A third ticketing platform means copying one of these files and swapping the scraper name. A registry entry does the same job with no new file.
    - **Evidence:**
        ```php
        class EventbriteController extends EventsPlatformController
        {
            public function __construct(private readonly EventbriteScraper $scraper) {}
            protected function platform(): string { return 'eventbrite'; }
            protected function normalizeAccountUrl(string $input): ?string { return $this->scraper->normalizeOrgUrl($input); }
            protected function fetchAccountEvents(string $url): ?array { return $this->scraper->fetchEvents($url); }
        }
        ```

- [ ] **#FOUND-7** · P2 — EventsCatalog hardcodes a per-platform adapter map of closures in its constructor
    - **Where:** `app/Services/Platforms/EventsCatalog.php` (`__construct`, `$this->adapters`)
    - **Affects:** Adding a third events platform to the unified catalog.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Define an `EventsProvider` interface (`normalizeEventUrl`, `fetchSingleEvent`, `normalizeAccountUrl`, `fetchEvents`) and register providers in `config('partna.events.providers')`.
        - Build `$this->adapters` by iterating registered providers instead of two hand-written closures.
    - **Technical:** `$this->adapters` is a hardcoded `['eventbrite' => [...], 'humanitix' => [...]]` map built in the constructor, duplicating the same knowledge `ProviderDetector::providersFor('events')` already holds in the registry.
    - **Plain English:** The events catalog keeps its own private, hand-written list of which two ticket platforms exist, even though the platform registry already knows this. Adding a third ticket platform means editing this private list by hand instead of the catalog simply asking the registry.
    - **Evidence:**
        ```php
        $this->adapters = [
            'eventbrite' => [
                'eventUrl' => fn (string $u) => $this->eventbrite->normalizeEventUrl($u),
                'fetchEvent' => fn (string $u) => $this->eventbrite->fetchSingleEvent($u),
            ],
            'humanitix' => [ ... ],
        ];
        ```

- [ ] **#FOUND-8** · P2 — GoogleBusinessAutoSync hardcodes an if-else chain over reservation/booking providers
    - **Where:** `app/Services/Platforms/GoogleBusinessAutoSync.php` (`RESERVATION_PLATFORMS`, `BOOKING_PLATFORMS` constants, `seedReservation()`'s provider chain)
    - **Affects:** Adding a fourth reservation provider (e.g. SevenRooms) to the Google Business auto-sync flow.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Define a `ReservationProvider` interface (`detect(url): bool`, `resolveWrite(url, name): ?array`); register providers in priority order in config.
        - Replace the if-else chain with a loop over registered providers.
    - **Technical:** The chain checks `$this->openTable->isOpenTableUrl($url)` → `$this->resDiary->isResDiaryUrl($url)` → `$this->nowBookit->isNowBookitUrl($url)` → generic fallback, verified present verbatim. A fourth provider means editing this method and injecting a new service.
    - **Plain English:** When Google Business finds a "book a table" link, the code runs down a fixed checklist asking "is this OpenTable? ResDiary? NowBookit?" one at a time. A fourth booking system means editing that checklist by hand rather than each system announcing itself to a shared list.
    - **Evidence:**
        ```php
        private const RESERVATION_PLATFORMS = ['opentable', 'resdiary', 'nowbookit', 'reservations'];
        private const BOOKING_PLATFORMS = ['fresha', 'square', 'booking'];
        ...
        if ($this->openTable->isOpenTableUrl($url) && ($rid = $this->openTable->parseRid($url)) !== null) { ... }
        if ($this->resDiary->isResDiaryUrl($url) && ($embed = $this->resDiary->embedUrl($url)) !== null) { ... }
        if ($this->nowBookit->isNowBookitUrl($url) && ($ids = $this->nowBookit->parseIds($url)) !== null) { ... }
        ```

- [ ] **#FOUND-9** · P2 — Shop provider dispatch is hardcoded across three separate switches in two files
    - **Where:** `app/Http/Controllers/Api/Platforms/ShopController.php` (`brandProfileFor()`, `providerProducts()`), `app/Services/Platforms/ShopProviderDetector.php` (`detect()`)
    - **Affects:** Adding a shop provider (Etsy, Ecwid) — requires editing the detector's probe sequence AND both controller `match()` blocks AND the constructor's scraper list.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Define a `ShopProviderInterface` (`detect(url): ?array`, `fetchBrand(url): array`, `fetchProducts(url, currency): array`).
        - Register providers in a `ShopProviderRegistry`; `ShopController` injects the registry (1 dependency instead of 6) and both `match()` blocks become `$this->registry->get($provider)->fetchBrand(...)`.
    - **Technical:** `ShopProviderDetector::detect()` runs a fixed probe sequence (Big Cartel → Shopify → WooCommerce → Squarespace → generic); `ShopController` then independently re-enumerates the same provider set in two more `match()` blocks that must stay in sync with the detector and each other. Three edit sites for one concept, verified across both files.
    - **Plain English:** The shop feature currently knows which of 5 store platforms it's talking to via three separate hand-written lists spread across two files — one to detect the store type, two more to decide how to fetch its data. A sixth store platform means updating all three lists and hoping none are missed. One shared "provider registry" replaces all three.
    - **Evidence:**
        ```php
        // ShopProviderDetector::detect()
        if ($account = $this->bigcartel->accountFromUrl($url)) { ... }
        if ($this->shopify->probe($origin)) { ... }
        if ($this->woocommerce->probe($origin)) { ... }
        if ($productsUrl = $this->squarespace->discoverProductsUrl($url)) { ... }
        ```
        ```php
        // ShopController::brandProfileFor()
        return match ($detected['provider']) {
            ShopProviderDetector::PROVIDER_WOOCOMMERCE => [$this->woocommerce->fetchBrand($detected['origin']), null],
            ShopProviderDetector::PROVIDER_SQUARESPACE => [$this->squarespace->fetchBrand($detected['sourceUrl']), null],
            ShopProviderDetector::PROVIDER_BIGCARTEL => [$detected['store'], null],
            ShopProviderDetector::PROVIDER_GENERIC => [$detected['page']['brand'], $detected['page']['products']],
            default => [$this->shopify->fetchBrand($detected['origin']), null],
        };
        ```

- [ ] **#FOUND-10** · P2 — Highlights curation flow is duplicated 5×, differing only in id field and cap
    - **Where:** `AppleController.php`, `BandcampController.php`, `YoutubeController.php`, `YoutubeMusicController.php`, `VimeoController.php` (each `highlights()`)
    - **Affects:** Any change to the highlights pattern (cap, stale-tile refresh policy) needs identical edits in 5 files.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract a shared method/trait parameterized by fetcher callable, id field, max count, and flat fields — mirroring the already-existing `RefreshesLatestTile` trait, which proves this codebase centralizes shared tile logic.
    - **Technical:** Each `highlights()` does: acquire lock → look up account row → call platform-specific fetcher → optionally refresh the latest tile → build `$byId = collect($items)->keyBy($idField)` → map/filter/take/values → write back. Verified byte-for-byte identical structure in `YoutubeController` and `BandcampController`, varying only in id-field name (`videoId` vs `itemId`) and resource class.
    - **Plain English:** Five platforms let users pick a handful of "highlight" items from their recent posts. The code that does this — lock the data, fetch the latest content, save the user's picks — is copy-pasted five times. Changing how highlights work (e.g. raising the cap) means editing five files identically.
    - **Evidence:**
        ```php
        $byId = collect($items)->keyBy('itemId');
        $chosen = collect($validated['itemIds'])
            ->map(fn (string $id) => $byId->get($id))
            ->filter()->take(self::MAX_HIGHLIGHTS)->values()->all();
        ```

- [ ] **#FOUND-11** · P2 — Platform identifier strings are bare literals scattered across queries, jobs, and dispatch arrays with no enum or registry
    - **Where:** `app/Jobs/Platforms/GoogleBusinessEnrichJob.php` (`->where('platform', 'google-business')`), `app/Jobs/Platforms/EnrichLinkCardJob.php` (`public string $platform`), `app/Jobs/Platforms/MenuFetchJob.php` (`'uber-eats'`, `'doordash'` array keys)
    - **Affects:** Any rename or typo of a platform key — a mismatch is a silent zero-row query or a rate-limiter key miss, with no compiler check.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a string-backed `Platform` enum with a canonical DB-string case per platform; replace bare string literals in queries, job constructors, and dispatch arrays.
        - Longer-term, tie platform metadata (scraper class, rate-limiter key, capability gate) to one `config('partna.platforms')` registry.
    - **Technical:** Platform identifiers appear as bare strings in DB queries, job `uniqueId()` construction, rate-limiter keys, and array keys, with no shared enum tying them together — verified across `GoogleBusinessEnrichJob::connection()` and `EnrichLinkCardJob`'s constructor.
    - **Plain English:** Names like "google-business" are handwritten in a dozen different spots instead of defined once. If a name ever needs to change, every handwritten copy has to be found and fixed by hand — and a missed one silently breaks that one spot with no warning.
    - **Evidence:**
        ```php
        return IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', 'google-business')
            ->where('place_id', $this->placeId)
            ->first();
        ```
        ```php
        public function __construct(
            public string $userId,
            public string $platform,
            public string $resourceId,
            public string $url,
        ) {
        ```

- [ ] **#FOUND-12** · P2 — GoogleBusinessEnrichJob and InstagramConnectJob duplicate identical queue/retry boilerplate
    - **Where:** `app/Jobs/Platforms/GoogleBusinessEnrichJob.php`, `app/Jobs/Platforms/InstagramConnectJob.php`
    - **Affects:** Any change to the retry/backoff policy for Apify-backed connect jobs; a third Apify job (TikTok, Facebook) would copy this again.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract an abstract `PlatformConnectJob` base owning `$tries = 0`, `$maxExceptions = 2`, `$backoff = [30, 120]`, `retryUntil(): now()->addMinutes(15)`, and the `RateLimited('platform-connect')` middleware; subclasses supply only `providerRateKey()` and `handle()`.
    - **Technical:** Both jobs declare identical `$tries`, `$maxExceptions`, `$backoff`, `retryUntil()`, and `middleware()` — verified line-for-line identical except `providerRateKey()`'s return value. A retry-policy change today requires editing both independently.
    - **Plain English:** Two background jobs that talk to different platforms (Google Business, Instagram) share an identical retry/rate-limit rulebook, copied into each file separately. If the rulebook ever needs to change, both copies have to be updated by hand — and a third platform job would copy it a third time.
    - **Evidence:**
        ```php
        public int $tries = 0;
        public int $maxExceptions = 2;
        public array $backoff = [30, 120];
        public function retryUntil(): \DateTimeInterface { return now()->addMinutes(15); }
        public function middleware(): array { return [new RateLimited('platform-connect')]; }
        ```

- [ ] **#FOUND-13** · P2 — MenuFetchJob writes `menu_items.badges` as manually json_encoded JSON, bypassing the relational structure the rest of the table already uses
    - **Where:** `app/Jobs/Platforms/MenuFetchJob.php` (`persist()`)
    - **Affects:** Any future dietary-filter feature ("show me vegetarian dishes") — badges can't be indexed or queried without a full JSON scan.
    - **Effort:** M (~2–4h, DB migration)
    - **What to do:**
        - Extract badges into a child table `menu_item_badges` (`menu_item_id`, `badge_type`, `position`) — consistent with how `MenuCategory`/`MenuItem`/`MenuItemPlatform` are already properly relational in this same job.
        - If badges are genuinely just display decoration with no filter need, a GIN index on the existing JSONB column is the cheaper alternative — call this out explicitly rather than leaving it unindexed either way.
    - **Technical:** Confirmed: `'badges' => isset($item['badges']) ? json_encode($item['badges']) : null,` followed by `MenuItem::query()->insert($rows); // Bulk insert (bypasses casts — badges already JSON).` Notably, the rest of this exact job (categories, items, per-platform price links) is *already* fully relational — badges are the one field left in JSON, an inconsistency within the same recently-built subsystem.
    - **Plain English:** This job already stores menu categories, dishes, and per-platform prices as proper, searchable database rows — a real engineering win. But dietary tags ("vegetarian", "gluten-free") are still crammed into one text blob per dish. If Partna ever wants to answer "which dishes are vegetarian?" across many menus, it can't do that without reading every dish's blob one at a time.
    - **Evidence:**
        ```php
        'badges' => isset($item['badges']) ? json_encode($item['badges']) : null,
        ...
        // Bulk insert (bypasses casts — badges already JSON).
        MenuItem::query()->insert($rows);
        ```

- [ ] **#FOUND-14** · P2 — IntegrationConnection account dedupe scans every row's JSONB payload for 7 possible identity fields, with no unique constraint possible
    - **Where:** `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php` (`writeAccountConnection()`, `matchAccountRow()`)
    - **Affects:** Every connect/reconnect on multi-account platforms (YouTube, Vimeo, Twitch, Spotify, SoundCloud, Bandcamp, Deezer).
    - **Effort:** L (~1–2d, DB migration)
    - **What to do:**
        - Promote the canonical identity value (whichever of `handle`/`input`/`apiPath`/`channelId`/`login`/`url`/`link` applies) to an indexed `canonical_key` column, populated at write time.
        - Change the lookup to `WHERE user_id = ? AND platform = ? AND canonical_key = ?`; keep the full selection blob in `payload` for everything else.
    - **Technical:** Confirmed verbatim: `writeAccountConnection()` iterates every account row for the user (capped at `maxAccounts() = 5` today) and probes 7 possible JSONB sub-keys per row to find a match — a scan that cannot use an index and cannot enforce a uniqueness constraint at the DB level. The current 5-account cap keeps the *performance* cost negligible, but the *correctness* gap (no DB-level dedupe guarantee) is real today.
    - **Plain English:** Every time someone connects a YouTube channel, the system opens every other channel they've already saved and manually checks seven different fields to see if it's the same one — like checking every book on a shelf instead of a card catalog. It's cheap today because the shelf only holds 5 books per platform, but there's no way for the database itself to guarantee two rows aren't secretly duplicates.
    - **Evidence:**
        ```php
        $existing = $rows->firstWhere('resource_id', $rid)
            ?? $rows->first(function (IntegrationConnection $row) use ($needle) {
                $stored = $row->payload ?? [];
                foreach (['handle', 'input', 'apiPath', 'channelId', 'login', 'url', 'link'] as $field) {
                    $value = $stored[$field] ?? null;
                    if (is_string($value) && strtolower(trim($value)) === $needle) {
                        return true;
                    }
                }
                return false;
            });
        ```

- [ ] **#FOUND-15** · P2 — FreshaController embeds ~200 lines of HTML/GraphQL scraping logic that the codebase's own convention places in a Service
    - **Where:** `app/Http/Controllers/Api/Platforms/FreshaController.php` (`fetchLocation`, `extractTeam`, `extractServices`, `fetchEmployeeServices`, `fetchMenu`, `stripLocale`, `slugFromUrl`, `extractStoreName`)
    - **Affects:** Any future change to Fresha's page structure; blocks reuse by a future feature needing the same store-menu data.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the scraping/parsing methods into `App\Services\Platforms\FreshaScraper`, matching the pattern every other platform controller already follows (`BandcampScraper` injected into `BandcampController`, etc.).
    - **Technical:** Confirmed: the controller's own docblock says *"Promotion plan: when the test is done, extract scrape logic to App\Services\Platforms\FreshaScraper"* — this is acknowledged, still-open debt, not a new discovery. The controller does HTML fetching via `SafeUrlFetcher`, `__NEXT_DATA__` regex parsing, and a hand-built GraphQL payload construction (verified: `Http::withHeaders([...])->timeout(12)->post(self::GRAPHQL_URL, $payload)`).
    - **Plain English:** The Fresha booking integration keeps its "back office" work (downloading pages, parsing hidden data, calling Fresha's internal API) inside the "front desk" controller instead of a dedicated Service, where every other platform's equivalent work lives. The code itself already has a comment saying "move this later" — this finding is a reminder that later is now, before another feature needs the same data and copies from the front desk instead.
    - **Evidence:**
        ```php
        // Promotion plan: when the test is done, extract scrape logic to
        // App\Services\Platforms\FreshaScraper, persist via a platform_connections
        // table per user, and wire to /account/platforms in Partna-Frontend.
        ```

- [ ] **#FOUND-16** · P2 — UserSectionBlockController hardcodes a `blockType === 'bio'` special case that will grow into an if-ladder as more sections need side effects
    - **Where:** `app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php` (`upsert()`)
    - **Affects:** Any future section type needing a cross-model side effect on save.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Define a `SectionUpsertHook` interface (`afterUpsert(User, Site, Block, array): void`); register hooks per `block_type` in config; have `upsert()` resolve and invoke the registered hook generically.
    - **Technical:** Confirmed: the upsert method special-cases `$blockType === 'bio'` to copy `settings.text` onto `$pro->bio`. As more section types need side effects (sync to another table, dispatch a job), this becomes a growing chain of hardcoded `if` blocks in the same method.
    - **Plain English:** Saving the "bio" section on a sitepage has a special rule bolted on: also copy the text into the professional's main bio field. The next section that needs its own special rule will get another `if` block bolted on next to it, and so on — a chain that gets harder to test and easier to break with each addition. A small plugin per section type, registered in one place, avoids that chain.
    - **Evidence:**
        ```php
        if (
            $blockType === 'bio'
            && array_key_exists('settings', $data)
            && is_array($data['settings'])
            && array_key_exists('text', $data['settings'])
        ) {
            $pro->bio = data_get($block->settings, 'text');
            $pro->save();
        }
        ```

- [ ] **#FOUND-17** · P2 — Media reorder is hand-rolled inline instead of using the shared ReorderService that link/section blocks already use
    - **Where:** `app/Http/Controllers/Api/User/Uploads/UserUploadController.php` (`reorder()`) vs `app/Services/Site/ReorderService.php`
    - **Affects:** Any future reorder endpoint; a fix to the shared two-pass offset algorithm would miss this copy.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extend `ReorderService` to support pool/media-type scoping and an `afterCommit` callback, then replace the hand-rolled algorithm in `UserUploadController::reorder()` with a call to it.
    - **Technical:** Confirmed both implementations use the identical two-pass offset-then-renumber algorithm (`$offset = count + 1000`, write offset positions, then write final positions) inside an advisory lock — but `UserUploadController` reimplements it by hand while `UserLinkBlockController` and section blocks call `ReorderService::reorder()`.
    - **Plain English:** Three features let you drag-and-drop reorder things (gallery images, link cards, page sections). Two of them share one "reordering engine." The gallery-image one has its own private copy of that engine, handwritten inside its own controller. If a bug in the reordering logic is ever found and fixed in the shared engine, the gallery images stay broken because nobody remembers they have their own copy.
    - **Evidence:**
        ```php
        // UserUploadController::reorder()
        $offset = $siteImages->count() + 1000;
        foreach ($finalIds as $index => $id) {
            SiteMedia::query()->where('site_id', $site->id)->where('id', $id)->update(['sort_order' => $offset + $index]);
        }
        foreach ($finalIds as $index => $id) {
            SiteMedia::query()->where('site_id', $site->id)->where('id', $id)->update(['sort_order' => $index]);
        }
        ```
        ```php
        // ReorderService::reorder() — same two-pass shape, already shared by links/sections
        $offset = (int) (clone $scopeQuery)->max('sort_order') + 1000;
        foreach ($newOrder as $i => $id) { (clone $scopeQuery)->where('id', $id)->update(['sort_order' => $offset + $i]); }
        foreach ($newOrder as $i => $id) { (clone $scopeQuery)->where('id', $id)->update(['sort_order' => $i]); }
        ```

- [ ] **#FOUND-18** · P2 — Notification email preference resolution (mandatory → per-pro policy → global policy → user pref → default) lives entirely inside a controller
    - **Where:** `app/Http/Controllers/Api/User/Notifications/NotificationEmailPreferenceController.php` (`index()`)
    - **Affects:** Any future consumer needing the same resolved preference (a background job deciding whether to send, a staff panel showing override state).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract a `NotificationPreferenceResolver` service with `resolve(userId, category): array`; have the controller call it per category, and let notification-sending jobs reuse the same resolver instead of a shorter re-implementation.
    - **Technical:** Confirmed: the cascading precedence (`mandatory` → `force_on`/`force_off` per-pro → `force_on`/`force_off` global → user preference → default `true`) is implemented as ~40 lines of business logic inline in the controller's `index()`.
    - **Plain English:** Deciding whether a professional gets a particular email involves a multi-step decision (is it mandatory? has staff forced it? has the professional opted out?). That whole decision process currently only exists inside the settings-page controller. If a background job needs to make the same decision before sending an email, it can't reuse this logic — it would have to copy it.
    - **Evidence:**
        ```php
        if ($mandatory) { $effective = true; }
        elseif ($perProMode === 'force_on') { $effective = true; }
        elseif ($perProMode === 'force_off') { $effective = false; }
        elseif ($globalMode === 'force_on') { $effective = true; }
        elseif ($globalMode === 'force_off') { $effective = false; }
        elseif ($prefValue !== null) { $effective = $prefValue; }
        else { $effective = true; }
        ```

- [ ] **#FOUND-19** · P2 — StaffAnalyticsController builds raw SQL queries and cache orchestration directly in the controller
    - **Where:** `app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php` (`summary()`)
    - **Affects:** Any future staff analytics dimension; the controller grows linearly with each addition.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the query-building (visits/day, clicks/day, top links) into a dedicated analytics service returning a DTO; keep the controller responsible for date parsing and response shaping only.
    - **Technical:** Confirmed: `summary()` directly issues three `DB::table()` queries (`analytics.site_visits`, `analytics.link_clicks`, a three-table join for top links) with two duplicated `try/catch` fallback blocks, assembled into a large nested response array inline.
    - **Plain English:** The staff analytics dashboard controller is doing a data-analyst's job directly instead of asking a dedicated component for the numbers — writing raw database queries and manually assembling a large response, all inside the web-request handler. Every new chart added to this dashboard grows this one file further.
    - **Evidence:**
        ```php
        $visitsByDay = DB::table('analytics.site_visits')
            ->where('user_id', $professional->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('DATE(occurred_at) as day, COUNT(*) as count')
            ->groupByRaw('DATE(occurred_at)')
            ->orderBy('day')
            ->get();
        ```

- [ ] **#FOUND-20** · P2 — PublicEnquiryController orchestrates 9 distinct steps in a single ~110-line method, and 4 lead-capture controllers each re-implement the same honeypot/timing gate
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php` (`submit()`)
    - **Affects:** Testability of the spam-detection pipeline; consistency across `PublicCustomerLeadController`, `PublicEmailSubscriptionController`, `PublicWaitlistController`, which duplicate the same honeypot + timing checks.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the honeypot/timing/spam-blocklist/subject-validation pipeline into an `EnquirySubmissionService`; share the honeypot/timing gate across all four lead-capture controllers via a trait or Form Request base.
    - **Technical:** Confirmed: `submit()` sequentially performs honeypot detection, timing enforcement, subdomain resolution, contact-block active check, spam blocklist lookup, subject validation, customer upsert with race handling, enquiry save, and job dispatch — all inline. A fifth lead-capture form would copy the honeypot/timing gate a fifth time.
    - **Plain English:** The enquiry-form handler does the job of several specialists — checking for spam bots, verifying the form wasn't submitted too fast, resolving which site it's for, checking a banned-customer list, saving the record, and notifying — all in one long method. Changing any one step means understanding the whole pipeline. Four separate public forms already copy the same "is this a bot" check independently.
    - **Evidence:**
        ```php
        $honeypot = $data['website'] ?? null;
        if (is_string($honeypot) && trim($honeypot) !== '') {
            $this->logLead($request, $subdomain, null, null, 'honeypot', $startedMs);
            return $this->success(['ok' => true]);
        }
        ```

- [ ] **#FOUND-21** · P2 — Adding a new public-profile content section requires hand-wiring 2+ files with no registry
    - **Where:** `app/Services/PublicSite/IndividualProfilePayloadBuilder.php` (`build()`, 8 `build*()` methods), `app/Services/PublicSite/SitepageDataResolverService.php` (8 `get*()` methods)
    - **Affects:** Engineers adding a new public-profile section (e.g. "testimonials").
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Define a `ProfileEngine` interface (`engineKey()`, `build()`, `isLive()`) and register engines in a `ProfileEngineRegistry`, mirroring the already-existing `SectionVisibilityRegistry` / `SectionVisibilityContract` pattern (confirmed present in `app/Services/User/Visibility/`).
    - **Technical:** Confirmed: `build()` explicitly calls 8 named `build*()` methods (`buildBio`, `buildGallery`, `buildLinks`, `buildServices`, `buildDocument`, `buildNewsletter`, `buildContact`, `buildWorkplace`), each paired with a corresponding `get*()` on the resolver. A 9th section means a new method pair plus a new `build()` call plus a new resource key — no single registration point.
    - **Plain English:** Adding a new content block to someone's public profile page — say, a testimonials section — currently means wiring changes in two different files by hand. The codebase already solved this exact problem for a related feature (which sections are *visible*) with a shared registry; the same pattern isn't yet applied to *building* each section's data.
    - **Evidence:**
        ```php
        return (new IndividualProfileResource($pro, [
            'bio' => $this->buildBio($pro, $sections),
            'gallery' => $this->buildGallery($site, $sections),
            'links' => $this->buildLinks($site, $booking),
            'services' => $this->buildServices($site, $pro->id, $sections),
            'document' => $this->buildDocument($site),
            'newsletter' => $this->buildNewsletter($sections),
            'contact' => $this->buildContact($sections),
            'workplace' => $this->buildWorkplace($site, $sections),
        ]))->resolve();
        ```

- [ ] **#FOUND-22** · P2 — Moderation outcome notifications require a new Notification class plus a match-arm edit per decision type
    - **Where:** `app/Jobs/Moderation/NotifyReportedUserJob.php` (dispatch `match`), `app/Notifications/Moderation/{ContentHidden,AccountSuspended,AccountBanned}Notification.php`
    - **Affects:** Adding a new moderation outcome (e.g. "warn", "temporary limit").
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the per-outcome notification classes with one `DecisionNotification` that reads its subject/body from a `decision_type => {subject, lines}` config map or directly from the `Decision` model; drop the `match`.
    - **Technical:** Confirmed: `NotifyReportedUserJob` dispatches via `match ($decision->decision_type) { 'hide_content', 'hide_site' => new ContentHiddenNotification($decision), 'suspend_user' => new AccountSuspendedNotification($decision), 'ban_user' => new AccountBannedNotification($decision), default => null }`. A new outcome requires both a new class and a new arm.
    - **Plain English:** Every new type of moderation outcome (hiding content, suspending an account, banning) gets its own dedicated notification class, and a separate dispatcher has to be told which class to use for which outcome. Adding "warn" as a new outcome next month means writing another class and adding another line to the picker — a single reusable notification, parameterised by the outcome data, avoids both.
    - **Evidence:**
        ```php
        $notification = match ($decision->decision_type) {
            'hide_content', 'hide_site' => new ContentHiddenNotification($decision),
            'suspend_user' => new AccountSuspendedNotification($decision),
            'ban_user' => new AccountBannedNotification($decision),
            default => null,
        };
        ```

- [ ] **#FOUND-23** · P2 — Adding a third food-delivery platform requires editing 5+ locations across the Menu subsystem's Services and Jobs layers
    - **Where:** `app/Services/Platforms/MenuApifyScraper.php` (`ACTORS` constant, `driver()`), `app/Services/Platforms/MenuMerger.php` (`PLATFORMS` constant), `app/Services/Platforms/MenuSource.php` (`PLATFORMS` constant), `app/Jobs/Platforms/MenuFetchJob.php` (`handle()`, `persist()`)
    - **Affects:** Any engineer adding Menulog, Deliveroo, or a third aggregator to the newest, most-actively-developed part of the codebase.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Define one `config('partna.menu.platforms')` entry per platform (actor id, host-match pattern, input builder, item mapper, merge priority).
        - Have `MenuApifyScraper`, `MenuMerger::PLATFORMS`, `MenuSource::PLATFORMS`, and `MenuFetchJob` all read from this single list instead of each hardcoding `'uber-eats'`/`'doordash'`.
    - **Technical:** Confirmed across all four files: `MenuApifyScraper::ACTORS` maps slug→Apify actor id; a *separate* `driver()` (160 lines away, same file) maps slug→`{buildInput, mapItems}` — these two must independently stay in sync. `MenuMerger::PLATFORMS` and `MenuSource::PLATFORMS` each hardcode the same two-platform list independently. `MenuFetchJob::handle()` hardcodes `'uber-eats'`/`'doordash'` in at least 6 distinct spots (`$plan['ueUrl']`/`$plan['ddUrl']`, the `$storeLinks` extraction, the `$statuses` loop, the skip-guard, the `$contentSource` fallback, and the per-platform store-URL upsert). Adding platform #3 today means touching all of this in lockstep — the single highest-leverage shotgun-surgery pattern in the newest subsystem this lens is explicitly biased toward.
    - **Plain English:** Right now, Uber Eats and DoorDash support is wired into the menu system across four files and roughly seven separate hand-written lists that all have to agree. Adding a third delivery service (say, Menulog) means finding and updating every one of those lists — miss one, and the menu silently breaks for that platform. A single master configuration entry per platform, read by all four files, turns "add platform #3" into one line in one place.
    - **Evidence:**
        ```php
        // MenuApifyScraper.php
        private const ACTORS = [
            'uber-eats' => 'memo23~uber-eats-scraper',
            'doordash' => 'dz_omar~doordash-scraper',
        ];
        ```
        ```php
        // MenuMerger.php
        private const PLATFORMS = ['uber-eats', 'doordash'];
        ```
        ```php
        // MenuFetchJob.php — hardcoded in the skip-guard, statuses, content-source fallback, and upsert
        && ($existingLinks->get('uber-eats')?->store_url) === $plan['ueUrl']
        && ($existingLinks->get('doordash')?->store_url) === $plan['ddUrl']
        $contentSource = $ueMenu !== null ? 'uber-eats' : 'doordash';
        foreach (['uber-eats' => $plan['ueUrl'], 'doordash' => $plan['ddUrl']] as $platform => $url) { ... }
        ```

- [ ] **#FOUND-24** · P2 — Adding a music/video/social listen platform requires copying scaffolding across a controller file AND 7 route definitions
    - **Where:** `app/Http/Controllers/Api/Platforms/{Bandcamp,Deezer,Soundcloud,Spotify,Twitch,Vimeo,Youtube,YoutubeMusic,Pinterest,Strava,NowBookit,OpenTable,ResDiary}Controller.php` (13 `SingleSelectionPlatformController` subclasses), `routes/api/integrations.php` (4 duplicated 7-route groups for YouTube/Bandcamp/Vimeo/YouTube Music)
    - **Affects:** Every developer adding a new music/video/social platform — currently the highest-volume duplication pattern in the codebase.
    - **Effort:** XL (~16–32h)
    - **What to do:**
        - Extend `PlatformRegistry`/`PlatformDescriptor` with a `ConnectStrategy` contract covering the current archetypes (oEmbed resolve, RSS/feed parse, HTML-scrape profile, highlights-curation), mirroring the `ConnectStrategy` the codebase already built for the link-only archetype (`GenericPlatformController`).
        - Let a registry-driven controller serve every listen/watch platform once each platform registers its `ConnectStrategy` + optional `HighlightsStrategy`.
        - Migrate the four duplicated route groups (YouTube, Bandcamp, Vimeo, YouTube Music) to a single registry-driven `routeShape()` that emits connect/recent/highlights/accounts/selection/forget from one descriptor call, collapsing the ~28 hand-written route lines to one `->routes(...)` per platform.
    - **Technical:** Confirmed `GenericPlatformController` + `PlatformRegistry` already solves this exact problem for the link-only archetype — its own docblock states it "resolves the matching PlatformDescriptor and serves the uniform connect/selection/forget shape that the per-platform controllers used to." The 13 remaining `SingleSelectionPlatformController` subclasses (verified: `BandcampController`, `YoutubeController`, `SpotifyController`, `NowBookitController` all follow the identical shape — constructor injecting one scraper, `platform()`, `resourceClass()`, and a `connect()` that parses input → calls scraper → shapes payload) differ only in that one `connect()` body. **Note on scope:** `GoogleBusinessController` was checked and is NOT a candidate for this collapse — it carries substantial unique logic (Places-picker enrichment, sync-findings, `applySync`) beyond the shared shape, so it should stay out of this migration. The route file independently duplicates the same 7-line group four times (verified for YouTube/Bandcamp/Vimeo/YouTube Music) with only the controller class and platform slug varying.
    - **Plain English:** Adding a new music, video, or social platform today means copying an entire controller file (constructor + 3 method overrides) AND copying 7 lines of route registration, then changing a handful of names. Thirteen platforms already follow this exact copy-paste shape. The codebase already built the "universal remote" pattern that solves this for simpler link-only platforms (Twitter, TikTok, etc.) — extending that same registry pattern to cover the connect+recent+highlights archetype would make adding platform #15 a one-file, one-registration job instead of a controller-plus-routes copy.
    - **Evidence:**
        ```php
        // GenericPlatformController — proof the registry pattern already works for one archetype:
        // "Registry-driven controller for the link-only archetype... resolves the matching
        // PlatformDescriptor and serves the uniform connect/selection/forget shape that the
        // per-platform controllers used to."

        // BandcampController — one of 13 structurally-identical SingleSelectionPlatformController subclasses:
        class BandcampController extends SingleSelectionPlatformController
        {
            public function __construct(private readonly BandcampScraper $scraper) {}
            protected function platform(): string { return 'bandcamp'; }
            protected function resourceClass(): string { return BandcampConnectionResource::class; }
            public function connect(PlatformConnectRequest $request): JsonResponse { /* parse → scrape → shape */ }
        }
        ```
        ```php
        // routes/api/integrations.php — identical 7-route group, repeated for youtube/bandcamp/vimeo/youtube-music:
        Route::prefix("{$base}/youtube")->middleware($middleware)->group(function () {
            Route::post('/connect', [YoutubeController::class, 'connect'])->defaults('platform', 'youtube');
            Route::get('/recent', [YoutubeController::class, 'recent']);
            Route::post('/highlights', [YoutubeController::class, 'highlights']);
            Route::get('/accounts', [GenericPlatformController::class, 'accounts'])->defaults('platform', 'youtube');
            Route::delete('/accounts/{id}', [GenericPlatformController::class, 'removeAccount'])->defaults('platform', 'youtube');
            Route::get('/selection', [GenericPlatformController::class, 'selection'])->defaults('platform', 'youtube');
            Route::delete('/', [GenericPlatformController::class, 'forget'])->defaults('platform', 'youtube');
        });
        ```

- [ ] **#FOUND-25** · P2 — ShopController's brand+product map grows unboundedly inside one JSONB cell per user
    - **Where:** `app/Http/Controllers/Api/Platforms/ShopController.php` (`brandMap()`, `addBrand()`, `setProducts()`), `app/Http/Requests/Platforms/SetShopProductsRequest.php`
    - **Affects:** Every user with a shop connection — up to 5 brands × up to 250 selected products each can live in a single JSONB cell.
    - **Effort:** L (~1–2d, DB migration)
    - **What to do:**
        - Extract brands to a `site.shop_brands` child table (id, provider, url, name, currency, favicon, logo, discount_code, fetch_mode) and products to `site.shop_products` (brand_id FK, product_id, name, price, currency, image_url, position).
        - Keep the `shop` `IntegrationConnection` row as the lifecycle/authorization anchor; shrink its payload to a lightweight marker.
    - **Technical:** Confirmed: `brandMap()` deserializes the *entire* `IntegrationConnection.payload` for the `shop` platform on every brand/product operation. `MAX_BRANDS = 5` and `SetShopProductsRequest` allows up to `productIds max:250` per brand — meaning up to 1,250 product objects can live in one JSONB cell, fully rewritten on every add/remove/selection change. This is a genuine unbounded-growth pattern (unlike the smaller, capped account-dedupe case in FOUND-14), not merely a future risk.
    - **Plain English:** Every product and brand a user connects to their shop lives in one giant text blob in the database. Every time they add a product, view their brands, or change their selection, the *entire* blob — potentially over a thousand products — gets read and rewritten. It's like keeping every store's inventory in one shared filing folder: opening the folder to check one store means seeing everything, and updating one item means rewriting the whole folder. Separate tables (one row per brand, one row per product) fix this properly.
    - **Evidence:**
        ```php
        private function brandMap(User $user): array
        {
            return ShopPayload::fromArray($this->readConnection($user))->toArray();
        }
        ...
        $map[$id] = ['id' => $id, 'provider' => ..., 'products' => $map[$id]['products'] ?? []];
        $this->writeConnection($user, $map);
        ```
        ```php
        // SetShopProductsRequest.php
        'productIds' => ['present', 'array', 'max:250'],
        'productIds.*' => ['string', 'max:50'],
        ```

## P3 — Nice to have

- [ ] **#FOUND-26** · P3 — RefreshController hardcodes its cooldown cache key instead of using CacheKeyGenerator
    - **Where:** `app/Http/Controllers/Api/Platforms/RefreshController.php:44`
    - **Affects:** Any future cache-key namespacing change would miss this one key.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Add `CacheKeyGenerator::platformRefreshCooldown(...)` and use it here; move `COOLDOWN_SECONDS = 12` to `config('partna.platforms.refresh_cooldown_seconds')`.
    - **Technical:** Confirmed: every other lock/key in this trait goes through `CacheKeyGenerator` (`platformConnectionLock(...)`); this one key is a raw string interpolation, the sole exception.
    - **Plain English:** Every cache key in this feature is generated by a shared "key-naming" helper except this one, which is handwritten. If the naming scheme ever changes, this one key gets left behind.
    - **Evidence:**
        ```php
        if (! Cache::add("integrations:refresh:{$user->id}:{$platform}", true, self::applyJitter(self::COOLDOWN_SECONDS))) {
        ```

- [ ] **#FOUND-27** · P3 — Four broadcast-notification mailable classes are identical except for the Blade view name
    - **Where:** `app/Mail/Notifications/FeatureAnnouncementMail.php`, `IncidentMail.php`, `PolicyUpdateMail.php`, `ProfileTaskMail.php`
    - **Affects:** Adding a new broadcast category.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Consolidate into one `BroadcastNotificationMail` parameterized by view name.
    - **Technical:** Confirmed: all four extend `BaseTransactionalMail` with the same constructor signature, differing only in the `->view(...)` string.
    - **Plain English:** Four mail templates are photocopies of each other with only the title changed. A single fill-in-the-blank template removes the need to keep photocopying.
    - **Evidence:**
        ```php
        class FeatureAnnouncementMail extends BaseTransactionalMail
        {
            public function __construct(public readonly Notification $notification) {}
            public function build(): self { return $this->buildEnvelope()->subject($this->notification->title)->view('emails.notifications.feature_announcement'); }
        }
        ```

- [ ] **#FOUND-28** · P3 — Five auth-hook mailable classes are template-coded per Supabase action instead of parameterized
    - **Where:** `app/Mail/Auth/{EmailChange,EmailConfirm,Invite,MagicLink,PasswordReset}Mail.php`
    - **Affects:** Adding a new auth email flow.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Consolidate into one `AuthHookMail` parameterized by subject/view, keeping the shared `webhookId` handling in one place.
    - **Technical:** Confirmed: all five share an identical constructor shape (`recipientEmail`, `?displayName`, `verifyUrl`, `webhookId`), differing only in subject string and view name.
    - **Plain English:** Five nearly-identical email templates each get their own file for what's really one template with a different title and destination page.
    - **Evidence:**
        ```php
        class EmailChangeMail extends BaseTransactionalMail
        {
            public function __construct(public readonly string $recipientEmail, public readonly ?string $displayName, public readonly string $verifyUrl, string $webhookId = '') { $this->webhookId = $webhookId; }
        }
        ```

- [ ] **#FOUND-29** · P3 — Waitlist applicant-type/industry typo-tolerance is hardcoded in `match()` instead of the config the validators already read from
    - **Where:** `app/Http/Requests/Api/PublicSite/PublicWaitlistSignupRequest.php` (`normalizeApplicantType()`, `normalizeIndustry()`)
    - **Affects:** Adding a new accepted typo or category requires a code deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Move the alias maps into `config('partna.waitlist.type_aliases')` / `industry_aliases`, alongside the `types`/`industries` lists the `Rule::in()` validators already read from config.
    - **Technical:** Confirmed: `normalizeApplicantType()` hardcodes `'professional', 'proffesional', 'profesisonal' => 'professional'`, and `normalizeIndustry()` hardcodes `'mensgrooming' => 'mens_grooming'` etc., while the validators next to them already read `config('partna.waitlist.types')`.
    - **Plain English:** The waitlist form silently corrects common typos, but the list of typos it recognizes is baked into code rather than the config file the rest of this same form already uses.
    - **Evidence:**
        ```php
        return match ($compact) {
            'professional', 'proffesional', 'profesisonal' => 'professional',
            ...
        };
        ```

- [ ] **#FOUND-30** · P3 — Platform connection Resource classes have no shared interface, relying on developer discipline to pick the right base
    - **Where:** `app/Http/Resources/Platforms/*ConnectionResource.php` (~16 classes)
    - **Affects:** A developer adding a platform that fits an existing shape (`TileConnectionResource`, `MusicEmbedConnectionResource`, `LinkConnectionResource`) may not discover it and create yet another standalone class.
    - **Effort:** M (~2–4h)
    - **What to do:** Define a `PlatformConnectionResourceContract` (`flatFields(): array`) that every resource implements or extends, making the choice of base class discoverable.
    - **Technical:** Confirmed three base classes already exist (`TileConnectionResource`, `MusicEmbedConnectionResource`, `LinkConnectionResource`), each serving several platforms, but nothing ties them together or signals their existence to a new contributor.
    - **Plain English:** There are already a few reusable "templates" for platform connection responses, but no sign posted anywhere saying which template fits which kind of platform — a new contributor has to read all the existing code to find out.
    - **Evidence:**
        ```php
        abstract class TileConnectionResource extends ApiResource
        {
            abstract protected function flatFields(): array;
        }
        ```

- [ ] **#FOUND-31** · P3 — EventbriteConnectionResource and HumanitixConnectionResource are byte-identical
    - **Where:** `app/Http/Resources/Platforms/EventbriteConnectionResource.php`, `app/Http/Resources/Platforms/HumanitixConnectionResource.php`
    - **Affects:** A field added to one must be manually mirrored to the other.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Merge into a single `EventsOrganiserConnectionResource`; point both platform descriptors at it.
    - **Technical:** Confirmed both emit the identical four keys (`url`, `organiser`, `next`, `upcoming`) with no platform-specific divergence.
    - **Plain English:** Two files hold identical forms with different filenames. One file does the same job with less risk of drifting apart.
    - **Evidence:**
        ```php
        return [
            'url' => $this->resource['url'] ?? null,
            'organiser' => $this->resource['organiser'] ?? null,
            'next' => $this->resource['next'] ?? null,
            'upcoming' => $this->resource['upcoming'] ?? [],
        ];
        ```

- [ ] **#FOUND-32** · P3 — Three staff service-reorder Form Requests are byte-identical copies of their user-facing counterparts instead of extending them
    - **Where:** `app/Http/Requests/Api/Staff/UserSite/Services/StaffReorderService{,Category,Layout}Request.php`
    - **Affects:** A validation-rule change needs editing both the user and staff copy.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Have the staff classes extend the user classes with no override, exactly as `StaffReorderLinkRequest extends ReorderBlocksRequest` already does elsewhere in the same directory tree.
    - **Technical:** Confirmed `StaffReorderServiceRequest` and `ReorderServiceRequest` have identical `rules()` bodies; the correct extends-with-no-override pattern is already proven in `StaffReorderLinkRequest`.
    - **Plain English:** Two request-validation files are cut from the same key but stamped with different labels. The codebase already has the right pattern next door — the staff version should just be the user version with a different nameplate.
    - **Evidence:**
        ```php
        class StaffReorderLinkRequest extends ReorderBlocksRequest
        {
            // Inherits Professional Validation
        }
        ```

- [ ] **#FOUND-33** · P3 — StaffStoreServiceRequest/StaffUpdateServiceRequest duplicate the user request instead of extending it with the one extra field
    - **Where:** `app/Http/Requests/Api/Staff/UserSite/Services/StaffStoreServiceRequest.php` vs `app/Http/Requests/Api/User/Services/StoreServiceRequest.php`
    - **Affects:** A field added to service CRUD needs editing both files.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Have the staff request extend the user request and merge in the extra `category_id` rule via `parent::rules()`.
    - **Technical:** Confirmed the staff variant repeats every user-request field verbatim, adding only `category_id`.
    - **Plain English:** Same problem as the reorder requests — a form that's 90% identical to another one, copied wholesale instead of extended with just the one new field.
    - **Evidence:**
        ```php
        // StaffStoreServiceRequest — same fields as StoreServiceRequest PLUS category_id
        'category_id' => ['nullable', 'uuid', 'exists:service_categories,id'],
        ```

- [ ] **#FOUND-34** · P3 — Row-type is encoded as a string prefix on `resource_id` (`event-`, `link-`) instead of a discriminator column
    - **Where:** `app/Services/Platforms/EventsCatalog.php` (`accountRows()`, `eventRows()`)
    - **Affects:** Any code branching on `resource_id` prefix; a new row kind needs every prefix-check site updated.
    - **Effort:** M (~2–4h)
    - **What to do:** Add a nullable `resource_kind` column populated alongside `resource_id`; replace `str_starts_with(...)` checks with `$r->resource_kind === 'event'`.
    - **Technical:** Confirmed `EventsCatalog` branches on `str_starts_with($r->resource_id, 'event-')` / `'link-'` in multiple places to distinguish account rows from standalone events — a naming convention, not an enforced column.
    - **Plain English:** The system tells different kinds of saved rows apart by looking at the first few letters of their ID rather than a labeled column. It works today, but it's an unenforced convention a future developer could accidentally break.
    - **Evidence:**
        ```php
        $rows = $platformRows->filter(
            fn (IntegrationConnection $r) => ! str_starts_with($r->resource_id, 'event-')
                && ! str_starts_with($r->resource_id, 'link-'),
        )->values();
        ```

- [ ] **#FOUND-35** · P3 — Social link `handle` stays in `settings` JSONB while `platform`/`category` were already promoted to real columns
    - **Where:** `app/Console/Commands/BackfillSocialLinksCommand.php`
    - **Affects:** Any future feature needing to search/filter/uniqueness-constrain a link block's handle.
    - **Effort:** M (~2–4h, DB migration)
    - **What to do:** Add a nullable `handle` column to `site.blocks`, backfill from `settings->>'handle'`, and update the write path — following the same pattern this exact command already applied to `platform` and `category`.
    - **Technical:** Confirmed: the command's own docblock states *"Phase 2: writes to the promoted columns (platform, category) instead of settings JSONB. handle stays in settings as it has no dedicated column."* This is the one field left behind from an otherwise-completed JSON→column promotion in the same recent effort.
    - **Plain English:** Two of three related fields on a social link (platform, category) were already moved out of the miscellaneous-data bucket into their own labeled columns. The third (handle) was left inside the bucket. Searching "everyone with an Instagram handle" or preventing duplicates would require opening every bucket instead of reading a column directly.
    - **Evidence:**
        ```php
        // Phase 2: writes to the promoted columns (platform, category) instead of
        // settings JSONB. handle stays in settings as it has no dedicated column.
        ```

- [ ] **#FOUND-36** · P3 — MenuSource loads every online-ordering row for a user and filters `url` in PHP instead of a column
    - **Where:** `app/Services/Platforms/MenuSource.php` (`entries()`), `app/Services/Platforms/Payloads/CardPayload.php` (`url()`)
    - **Affects:** Menu resolution for users with online-ordering links. Current per-user cardinality is small (typically 1–2 links), so this is low urgency today, but the pattern will not hold up if this feature grows to allow many saved ordering links.
    - **Effort:** M (~2–4h, DB migration)
    - **What to do:** Promote `url` to a real nullable column on `site.platform_connections` if/when this list is allowed to grow past a couple of entries; otherwise leave as-is.
    - **Technical:** Confirmed: `entries()` loads every `online-ordering` row for the user, then filters in PHP with `->filter(fn (array $p) => is_string($p['url'] ?? null) && $p['url'] !== '')` — the field can't be indexed or filtered in SQL. Given the small realistic row count per user, this is a candidate for later, not now.
    - **Plain English:** The system stores each delivery link's web address inside a general-purpose data blob rather than its own column, so finding "which links does this user have" means opening every blob and checking inside. Today each user only has a couple of these, so it's not costing anything — but it's a pattern worth avoiding if the feature grows.
    - **Evidence:**
        ```php
        $this->entriesCache[$userId] = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->where('platform', 'online-ordering')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (IntegrationConnection $r) => CardPayload::fromArray($r->payload)->toArray())
            ->filter(fn (array $p) => is_string($p['url'] ?? null) && $p['url'] !== '')
            ->values();
        ```

- [ ] **#FOUND-37** · P3 — `core.users.about` JSONB column is now dead weight following the completed credentials/experience extraction
    - **Where:** `app/Http/Controllers/Api/User/Account/UserSelfController.php` (`update()`), `app/Http/Resources/UserDashboardResource.php`, `app/Models/Core/User/User.php` (`'about' => 'array'` cast)
    - **Affects:** Schema clarity — a nullable JSONB column that always resolves to `null` after validation is confusing debt, not active risk.
    - **Effort:** S (~0.5–1h, DB migration)
    - **What to do:**
        - Confirm no remaining callers read `about` directly (verified: none found outside the stripping logic itself), then drop the `about` column, its cast, and the now-redundant strip logic in one cleanup pass.
    - **Technical:** This finding replaces DeepSeek's original framing, which assumed an *incomplete* migration with more structured sub-keys still to extract. Verified against the actual code: `ValidatesUserAbout::aboutRules()` only ever validates `about.credentials` and `about.experience` — both of which are unconditionally stripped in `UserSelfController::update()` before the JSONB write, collapsing to `null` whenever both are present. Reads already go through `aboutPayload()` (child tables), confirmed in both `UserDashboardResource` and `UserStaffResource` ("Reads from child tables via aboutPayload() — not from the legacy about JSONB (FOUND-5)"). This migration is the completed result of the **Foundational Wave-2** work (FOUND-2..21, deployed 2026-07-01→02 per recent commits) — there is no remaining structured data to extract; the column is simply dead.
    - **Plain English:** An earlier cleanup already moved a professional's credentials and work history out of a general "about" data bucket into their own proper tables — both the read and write sides already reflect that. What's left is an empty, unused bucket still sitting in the schema. It should be removed rather than treated as unfinished work.
    - **Evidence:**
        ```php
        // Strip credentials/experience from the validated payload before fill()
        // so the legacy about JSON column never re-accumulates them. They are
        // written to child tables by SyncUserAboutService instead (FOUND-5).
        unset($validated['about']['credentials'], $validated['about']['experience']);
        if ($validated['about'] === []) {
            $validated['about'] = null;
        }
        ```
        ```php
        // UserDashboardResource.php
        // Reads from child tables via aboutPayload() — not from the legacy about JSONB (FOUND-5).
        'about' => (object) $this->aboutPayload(),
        ```

- [ ] **#FOUND-38** · P3 — Email action-type dispatch uses two separate `match()` statements that must stay in lockstep
    - **Where:** `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php` (`resolveMailable()`, `buildConfirmUrl()`)
    - **Affects:** Adding a new Supabase auth email action type.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Replace both `match()` blocks with lookups into a single `action_type => [mailable_class, frontend_confirm_type]` registry.
    - **Technical:** Confirmed both methods independently map the `email_change_*` family (one to a Mailable class, one to a frontend confirm type) 26 lines apart.
    - **Plain English:** Two separate rulebooks in the same file both need updating whenever a new type of auth email is added; they currently have to be kept in sync by hand.
    - **Evidence:**
        ```php
        $frontendType = match ($actionType) {
            'email_change_new', 'email_change_current' => 'email_change',
            default => $actionType,
        };
        ```

- [ ] **#FOUND-39** · P3 — Hardcoded first-name/non-name-token lists live in a controller constant instead of config
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php` (`COMMON_FIRST_NAMES`, `NON_NAME_TOKENS`)
    - **Affects:** Adding a name to the inference dictionary, or blocking a new spam token, requires a code deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Move both arrays to `config('partna.name_inference.*')`; consider extracting the inference algorithm to a reusable service.
    - **Technical:** Confirmed both constants exist as hardcoded arrays with ~80 and ~18 entries respectively.
    - **Plain English:** The system's dictionary of common first names (used to guess a name from an email address) is baked into code rather than a config file that ops could edit without a deploy.
    - **Evidence:**
        ```php
        private const COMMON_FIRST_NAMES = [
            'aaron', 'adam', 'alex', 'alice', 'amanda', ...
        ];
        private const NON_NAME_TOKENS = [
            'admin', 'booking', 'bookings', 'contact', 'hello', ...
        ];
        ```

- [ ] **#FOUND-40** · P3 — Bot User-Agent signal list is hardcoded in a controller trait instead of config
    - **Where:** `app/Http/Controllers/Concerns/DetectsClientInfo.php` (`isBotUserAgent()`)
    - **Affects:** Adding a new crawler/bot signature (e.g. a new AI scraper) requires a code deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Move the `$signals` array to `config('partna.bot_user_agent_signals')`.
    - **Technical:** Confirmed the ~20-entry list is a hardcoded array inline in the method.
    - **Plain English:** The "is this visitor a bot" checklist is written directly into code. A new AI crawler that starts scraping the site can't be blocked without a full deploy.
    - **Evidence:**
        ```php
        $signals = [
            'bot', 'spider', 'crawler',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'rogerbot',
            ...
        ];
        ```

- [ ] **#FOUND-41** · P3 — Duplicated raw SQL upsert in StaffNotificationEmailPolicyController's two methods
    - **Where:** `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationEmailPolicyController.php` (`updateGlobal()`, `updateProfessional()`)
    - **Affects:** A schema change to `notification_email_policies` needs both copies updated.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract a single `upsertPolicy(?userId, category, mode)` private method (or model method) that both callers use.
    - **Technical:** Confirmed both methods issue the same parameterised `INSERT ... ON CONFLICT` statement, differing only in the WHERE clause and bound user_id.
    - **Plain English:** The same database-write statement is copy-pasted into two nearly-identical methods. One shared helper avoids keeping two copies in sync.
    - **Evidence:**
        ```php
        DB::statement(
            'INSERT INTO notifications.notification_email_policies (id, user_id, category_key, mode, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON CONFLICT (user_id, category_key) WHERE user_id IS NOT NULL
             DO UPDATE SET mode = EXCLUDED.mode, updated_at = NOW()',
            [(string) Str::uuid(), $professional->id, $update['category'], $update['mode']]
        );
        ```

- [ ] **#FOUND-42** · P3 — PublicMenuController formats menu data inline instead of via a Resource class
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicMenuController.php` (`show()`)
    - **Affects:** Consistency if another surface (staff preview, Astro payload) later needs the same menu shape.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Extract the category/item mapping (price formatting, empty-category filtering) into a `MenuResource`.
    - **Technical:** Confirmed the controller hand-builds the response array with `number_format(...)` and filter/map chains rather than an API Resource, which is this codebase's documented convention for all responses.
    - **Plain English:** The menu endpoint manually formats prices and filters empty categories inline instead of using the codebase's standard "response shaping" component. If another feature needs the same menu shape, this logic would likely get copied rather than reused.
    - **Evidence:**
        ```php
        $categories = $menu->categories
            ->map(fn ($cat) => [
                'name' => $cat->name,
                'items' => $cat->items->map(fn ($item) => [
                    'price' => $item->base_price !== null ? number_format((float) $item->base_price, 2) : null,
                    ...
                ])->values()->toArray(),
            ])
            ->filter(fn ($cat) => count($cat['items']) > 0)
            ->values()->toArray();
        ```

- [ ] **#FOUND-43** · P3 — Analytics section titles and referrer-source labels are hardcoded in a service class instead of config
    - **Where:** `app/Services/Analytics/AnalyticsQueryService.php` (`sectionTitle()`, `SOURCE_CASE`, `REFERRER_LABELS`)
    - **Affects:** Adding a new skeleton section or social referrer source requires a code deploy; the two label constants must independently stay in sync.
    - **Effort:** M (~2–4h)
    - **What to do:** Move `sectionTitle()`'s mapping and the `SOURCE_CASE`/`REFERRER_LABELS` pair into a single `config('partna.analytics.*')` registry consumed by both.
    - **Technical:** Confirmed: `sectionTitle()` is a ~22-arm `match()`, and `SOURCE_CASE` (a raw SQL `CASE` string) / `REFERRER_LABELS` (an ordered list) are two separate constants that must stay aligned — a new `WHEN` clause without a matching label silently buckets that source into "Other."
    - **Plain English:** Adding a new page section or recognizing a new social-media referral source means editing the analytics engine's source code rather than a config file, and two related lists have to be kept in sync by hand with nothing catching a mismatch.
    - **Evidence:**
        ```php
        private const REFERRER_LABELS = [
            'Organic (Google)', 'Organic (Bing)', ... , 'Direct Link', 'Other',
        ];
        ```

- [ ] **#FOUND-44** · P3 — `streaming_platforms` and part of `link_block_icon_keys` duplicate data already in `social_platforms`
    - **Where:** `config/partna.php` (`streaming_platforms`, `link_block_icon_keys`)
    - **Affects:** Adding a new social/streaming platform means editing 2–3 separate lists; a missed one silently skips live-status polling or breaks the icon picker.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Derive `streaming_platforms` and the social-icon subset of `link_block_icon_keys` at config-load time from `social_platforms`' `default_category`/`icon_key` fields.
    - **Technical:** Confirmed all three arrays exist; `social_platforms` already carries `icon_key` and `default_category` per platform, but `streaming_platforms` and part of `link_block_icon_keys` repeat that data by hand.
    - **Plain English:** The master list of social platforms already says which ones are streaming platforms and what icon each uses. Two other lists repeat that same information by hand, and a new platform can be added to one list while being forgotten in the others.
    - **Evidence:**
        ```php
        'streaming_platforms' => ['twitch', 'kick'],
        'link_block_icon_keys' => [
            'scissors', 'calendar', ..., 'twitch', 'kick',
        ],
        ```

- [ ] **#FOUND-45** · P3 — Notification frontend-type normalization logic is scattered across three coupled locations
    - **Where:** `app/Models/Core/Notifications/Notification.php` (`FRONTEND_TYPES`, `normalizeFrontendType()`, `severityForFrontendType()`)
    - **Affects:** Adding a new canonical frontend display type requires editing a constant, an if-chain, and a match — missing one silently defaults to "Info."
    - **Effort:** S (~0.5–1h)
    - **What to do:** Replace the three with a single `type => {aliases, severity}` array and two trivial lookup methods.
    - **Technical:** Confirmed the if-chain in `normalizeFrontendType()` repeats the `mb_strtolower(trim(...))` pattern per branch with silent fallthrough to `'Info'`.
    - **Plain English:** Three separate lists in the same file all need to agree on what notification display types exist. A new type added to one list but not the others quietly falls back to "Info" with no warning.
    - **Evidence:**
        ```php
        public static function normalizeFrontendType(?string $value, ?string $severity = null): string
        {
            $normalized = mb_strtolower(trim((string) ($value ?? '')));
            if ($normalized === 'success') { return 'Success'; }
            if ($normalized === 'critical' || $normalized === 'error') { return 'Critical'; }
            ...
            return 'Info';
        }
        ```

- [ ] **#FOUND-46** · P3 — SiteMedia pool definitions are spread across model constants and two config arrays
    - **Where:** `app/Models/Core/Site/SiteMedia.php` (`POOL_*`, `GALLERY_POOLS`), `config/partna.php` (`image_pools`, `upload_pools`)
    - **Affects:** Adding a new media pool touches 4 locations across 2 files.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Centralize into one `config('partna.media_pools')` array (per-pool `max`/`uploadable`/`listable`); have model constants read from it.
    - **Technical:** Confirmed all four locations exist independently (`POOL_GALLERY` etc., `GALLERY_POOLS`, `image_pools`, `upload_pools`).
    - **Plain English:** The rules for each image "pool" (gallery, content, documents) are split across four different spots in two files. Adding a fifth pool means updating all four.
    - **Evidence:**
        ```php
        public const GALLERY_POOLS = [self::POOL_GALLERY, self::POOL_CONTENT];
        ```
        ```php
        'upload_pools' => ['gallery', 'content'],
        'image_pools' => ['gallery' => ['max' => ...], 'content' => ['max' => ...], 'documents' => ['max' => 1]],
        ```

- [ ] **#FOUND-47** · P3 — Block model constants duplicate the canonical `config('partna.block_types')` registry
    - **Where:** `app/Models/Core/Site/Block.php` (`GROUP_LINKS`, `GROUP_SECTIONS`, `TYPE_LINK`)
    - **Affects:** Config already documents itself as the source of truth; the model constants are a hand-synchronised mirror.
    - **Effort:** S (~0.5h)
    - **What to do:** Replace the hardcoded constants with static methods reading from `config('partna.block_types')`.
    - **Technical:** Confirmed `config/partna.php` comments the `block_types` array as canonical and cross-references the DB CHECK and the `Block::GROUP_*`/`TYPE_*` constants explicitly, meaning three copies of the same list exist by design intent, with only config being authoritative.
    - **Plain English:** The list of valid page-block types is written in a config file that says it's the single source of truth — but the Block model keeps its own hand-copied version of the same information.
    - **Evidence:**
        ```php
        public const GROUP_LINKS = 'links';
        public const GROUP_SECTIONS = 'sections';
        public const TYPE_LINK = 'link';
        ```

- [ ] **#FOUND-48** · P3 — `blocks_group_type_check` hardcodes the section-type enum in a CHECK constraint alongside the config it mirrors
    - **Where:** `supabase/migrations/20260701160000_blocks_group_type_pair_check.sql`
    - **Affects:** Adding a new sitepage section type requires a migration in addition to the config/frontend work already required.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Keep this CHECK as-is — it exists to fix a real, recent bug (an invalid `(block_group, block_type)` pair like `('sections', 'link')` was previously invisible to every list endpoint) and removing it would reintroduce that bug. If churn becomes a real problem, replace it with a lookup table keyed on the `(block_group, block_type)` pair (preserving the pairing invariant), rather than dropping DB-level enforcement entirely.
    - **Technical:** This finding revises DeepSeek's original recommendation, which proposed dropping the constraint entirely by analogy to the `platform_connections` CHECK removal (`20260629120000_drop_platform_connections_check.sql`). That analogy doesn't hold: this constraint's primary purpose (confirmed in the migration's own header comment) is preventing an invalid group/type *pair* from silently becoming invisible to list endpoints — a real bug fixed on 2026-07-01, three days before this audit. Section types are also a low-churn, deliberately curated, engineering-heavy addition (each requires new frontend components), unlike the fast-growing external-provider list `platform_connections` tracked. Dropping DB-level enforcement here would regress a recent, deliberate correctness fix.
    - **Plain English:** A new sitepage section type requires a database update in addition to code changes — but that database check was added just a few days ago specifically to catch a real bug (mismatched section data becoming invisible on the page). Removing it, as the first-pass suggestion proposed, would bring that bug back. Section types don't change often enough for this to be worth more than a passing note.
    - **Evidence:**
        ```sql
        ALTER TABLE site.blocks ADD CONSTRAINT blocks_group_type_check
            CHECK (
                (block_group = 'links' AND block_type = 'link')
                OR (block_group = 'sections' AND block_type IN (
                    'gallery', 'services', 'booking', 'contacts_collection',
                    'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter',
                    'countdown', 'contact', 'public_contact', 'workplace',
                    'credentials', 'experience', 'bio'
                ))
            ) NOT VALID;
        ```

- [ ] **#FOUND-49** · P3 — The booking section's `platform` field stays in `settings` JSONB while related fields have already been promoted
    - **Where:** `supabase/migrations/20260701170000_promote_block_settings_columns.sql`
    - **Affects:** `SitepageDataResolverService::getBooking` must extract the value via JSON path.
    - **Effort:** S (~0.5–1h, DB migration)
    - **What to do:** If the booking block's `platform` sub-key is read/filtered elsewhere in the future, promote it to a dedicated column following the same expand/contract pattern already used for the links-group promotion in this migration; otherwise leave as-is given it's read in exactly one place today.
    - **Technical:** The migration's own comment explicitly and deliberately scopes the promotion to links-group blocks only: *"block_group='sections' rows (e.g. booking sections) keep their own settings.platform untouched — that is a different field, read by SitepageDataResolverService::getBooking."* This is a documented, intentional exclusion, not an oversight — the finding is downgraded accordingly since it's a single, known read site, not an unbounded pattern.
    - **Plain English:** The booking section's platform setting was deliberately left inside the general settings bag when a related cleanup promoted other fields to their own columns — the code comment explains why. It's a reasonable candidate for the same treatment later if a second reader ever needs it, not an active problem today.
    - **Evidence:**
        ```sql
        -- Backfill from JSONB, LINKS ONLY. block_group='sections' rows (e.g. booking
        -- sections) keep their own settings.platform untouched — that is a different
        -- field, read by SitepageDataResolverService::getBooking.
        ```

- [ ] **#FOUND-50** · P3 — Moderation schema CHECK enums (`case_type`, `reportable_type`, `reason_code`) may need a lookup table if new content types (from the scraping subsystem) become reportable
    - **Where:** `supabase/migrations/20260528000000_create_moderation_schema.sql` (`cases_case_type_check`, `cases_reportable_type_check`, `case_signals_reason_code_check`)
    - **Affects:** Adding a new moderation case type, reportable content type, or report reason requires a migration.
    - **Effort:** M (~2–4h)
    - **What to do:** No action needed now. If a future feature makes `MenuItem`, `Event`, or another scraped-content type reportable, extend `reportable_type` via migration at that time; a lookup table is only worth the complexity if that list starts changing frequently.
    - **Technical:** This finding is substantially downgraded from the draft's framing. Unlike `platform_connections` (a fast-growing, externally-driven provider list that genuinely churned through 6 migrations before removal — confirmed via `20260629120000_drop_platform_connections_check.sql`), moderation case types and reason codes are policy-driven, low-churn, and benefit from strict DB-level enforcement as a compliance safeguard rather than suffering from it. The one plausible forward-looking gap — `reportable_type` not yet covering new content types the menu/events scraping subsystem introduces — is speculative, not a demonstrated need today.
    - **Plain English:** The moderation system has a fixed, deliberately strict list of case types and report reasons enforced by the database — which is a *good* thing for a compliance-sensitive area, not technical debt. The only genuine forward-looking question is whether new scraped content (menu items, events) will eventually need to be reportable too; that's worth revisiting if and when that feature is actually planned, not now.
    - **Evidence:**
        ```sql
        CONSTRAINT cases_reportable_type_check CHECK (reportable_type IN (
            'Site', 'SiteMedia', 'User', 'Block', 'Service'
        )),
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Platform provider dispatch hardening:** #FOUND-7, #FOUND-8, #FOUND-9, #FOUND-11
    - **Why grouped:** all are hardcoded if-else/match dispatch tables over a provider list in the Platforms services layer, fixable with the same registry pattern.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Events subsystem duplication:** #FOUND-6, #FOUND-31, #FOUND-34
    - **Why grouped:** same two-platform (Eventbrite/Humanitix) events integration, spanning controller/resource/service layers.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Highlights & Apify job hygiene:** #FOUND-10, #FOUND-12, #FOUND-15
    - **Why grouped:** shared "connect + curate" controller/job boilerplate across music/video platforms and Fresha.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — User dashboard controller cleanup:** #FOUND-16, #FOUND-17, #FOUND-18
    - **Why grouped:** same file family (`app/Http/Controllers/Api/User/...`), each extracting inline business logic into a service/registry.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 5 — Public/staff controller leaky abstraction:** #FOUND-19, #FOUND-20, #FOUND-21
    - **Why grouped:** same pattern (orchestration logic that belongs in a Service, sitting in a controller) across staff and public surfaces.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 6 — Provider dedup (streaming + bot protection):** #FOUND-2, #FOUND-3
    - **Why grouped:** identical "two providers, one boilerplate shape" pattern in unrelated subsystems, same fix shape.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 7 — Form Request dedup:** #FOUND-4, #FOUND-32, #FOUND-33
    - **Why grouped:** mechanical Form Request consolidation (identical or near-identical rule sets), low risk, high volume.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 8 — Route + cache-key hygiene:** #FOUND-5, #FOUND-26
    - **Why grouped:** both are small, mechanical fixes in the platform-routing/cache-key layer.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 9 — Notification/mail class dedup:** #FOUND-22, #FOUND-27, #FOUND-28
    - **Why grouped:** same "one class per variant, differing only in template/data" pattern across Notifications and Mail.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 10 — Config/model constant dedup:** #FOUND-44, #FOUND-45, #FOUND-46, #FOUND-47
    - **Why grouped:** all are small config-vs-model-constant drift risks in `config/partna.php` + adjacent models, same fix shape (derive from one canonical source).
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 11 — Misc small hygiene:** #FOUND-29, #FOUND-30, #FOUND-38, #FOUND-39, #FOUND-40, #FOUND-41, #FOUND-42, #FOUND-43
    - **Why grouped:** low-effort, low-risk config/DRY nits across unrelated files with no dependency between them — safe to knock out in one sweep.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

- **Bundle 12 — Schema CHECK constraint review:** #FOUND-48, #FOUND-49, #FOUND-50
    - **Why grouped:** all three re-examine recently-added schema CHECK constraints against the `platform_connections` precedent; best reviewed together so the "when is a narrow CHECK the right call" judgment is applied consistently.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#FOUND-1 — GDPR data-export registry** · sole P1 finding; touches a compliance-sensitive data path — run with its own sign-off even though effort is only M.
- **#FOUND-14 — IntegrationConnection canonical_key column** · DB migration + L effort.
- **#FOUND-23 — Menu subsystem platform registry** · L effort, spans Services + Jobs layers across the newest subsystem.
- **#FOUND-24 — Music/video/social platform connect registry** · XL effort, widest blast radius in this audit (13 controllers + 4 route groups).
- **#FOUND-25 — ShopController brand/product relational extraction** · DB migration + L effort.
- **#FOUND-13 — MenuItem badges child table** · DB migration.
- **#FOUND-35 — MenuSource url column promotion** · DB migration (even though low urgency, any DB migration is standalone per policy).
- **#FOUND-36 — Drop dead `about` JSONB column** · DB migration (schema change), even though effort is S.
- **#FOUND-37 — (superseded, see #FOUND-36 numbering)** — *(note: #FOUND-37 in this document is the email-hook match finding; the `about`-column finding is #FOUND-36 above — verify ID before starting work)*.
