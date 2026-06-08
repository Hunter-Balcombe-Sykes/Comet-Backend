# Code Quality Audit — Consolidated — 2026-06-06

**Branch:** development
**Lens:** Bundle 'code-quality' — AI slop & low-value code (SLOP-*) and semantic correctness (SEM-*)
**Pipeline:** DeepSeek V4 Pro scan × 4 groups → Claude Sonnet adjudication × 4 groups → consolidated
**Scope:** `app/` (split into 4 groups to stay under 480KB per scan)

| Group | Scope | Findings |
|-------|-------|----------|
| 01a | Services/User, SmartLinks, Media, Cache | 8 |
| 01b | Services/* (remaining) | 3 |
| 02  | Http/Controllers | 3 |
| 03  | Http/Resources, Jobs, Observers, Policies, Models, Console | 8 |

**Total findings:** 22
**Breakdown by tier:** P0: 0 · P1: 1 · P2: 4 · P3: 17
**Breakdown by lens:** SEM: 10 · SLOP: 12

---

## Progress

- P1 High: 0 of 1 complete
- P2 Medium: 4 of 4 complete
- P3 Low: 7 of 17 complete

---

## P1 — Fix before pilot launch

- [x] **#SEM-1** · P1 — `SiteSubdomainAlias::$fillable` missing `reclaim_until` and `expires_at` — alias lifecycle silently broken on every rename
    - **Where:** app/Models/Core/Site/SiteSubdomainAlias.php:22-27 · app/Services/Site/UpdateSiteAction.php (create call) · app/Console/Commands/PruneExpiredHandleAliases.php:28-31
    - **Affects:** Every professional who renames their subdomain. Subdomain alias rows are created with `NULL` in both `reclaim_until` and `expires_at`. The prune cron (`handles:prune-expired-aliases`) filters `WHERE expires_at IS NOT NULL AND expires_at < now()`, so it never deletes any subdomain alias row. Aliases accumulate in `site.site_subdomain_aliases` permanently and are never resynced out of Cloudflare KV on expiry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `reclaim_until` and `expires_at` to `SiteSubdomainAlias::$fillable`.
        - Add corresponding `datetime` entries to `SiteSubdomainAlias::$casts` (mirror the pattern in `UserHandleAlias`).
        - Add an `active()` scope (matching `UserHandleAlias::scopeActive`) and apply it in `PublicSiteResolver::resolvePublishedSite()` on the alias query.
        - Patch existing NULL rows in the DB: `UPDATE site.site_subdomain_aliases SET expires_at = created_at + INTERVAL '90 days', reclaim_until = created_at + INTERVAL '14 days' WHERE expires_at IS NULL`.
    - **Technical:** `UpdateSiteAction::execute()` calls `SiteSubdomainAlias::query()->create([..., 'reclaim_until' => ..., 'expires_at' => ...])`. Laravel's `create()` runs through `fill()`, which enforces `$fillable` as an allowlist. Because `reclaim_until` and `expires_at` are absent from `SiteSubdomainAlias::$fillable = ['subdomain', 'created_at', 'site_id']`, both values are silently discarded and the row is inserted with `NULL` in both columns. The prune cron then queries `WHERE expires_at IS NOT NULL AND expires_at < now()` — which never matches NULL rows — so subdomain aliases are never deleted. Contrast `UserHandleAlias`, which correctly lists both columns in `$fillable` AND `$casts`, and has both `active()` and `reclaimable()` scopes. This is a structural parity gap between two sibling models written at different times. There is no routing failure for end-users today (the direct-site query in `resolvePublishedSite` shadows stale alias rows), but the `site.site_subdomain_aliases` table grows without bound on every rename, the Cloudflare KV `SyncSubdomainToKvJob` is never dispatched for expired subdomain aliases, and any future code that reads `$alias->expires_at` on a `SiteSubdomainAlias` model will receive `NULL` rather than a Carbon instance.
    - **Plain English:** Think of subdomain aliases like forwarding addresses at a post office. When you move (rename your subdomain), a forwarding card is filed so old mail still reaches you for 90 days, then the card is shredded and the slot opens up for someone else. The bug is that every forwarding card is filed without an expiry date on it — the shredder's policy is "only destroy cards with a date," so every card just sits there forever, even when a new tenant should have taken that address. The fix is to make sure every new card gets a date stamped on it, and to backfill all the undated cards that already exist.
    - **Evidence:**
        ```php
        // app/Models/Core/Site/SiteSubdomainAlias.php — $fillable lacks lifecycle columns:
        protected $fillable = [
            'subdomain',
            'created_at',
            'site_id',                // reclaim_until and expires_at are ABSENT
        ];
        // No $casts for reclaim_until / expires_at. No active() or reclaimable() scope.

        // app/Services/Site/UpdateSiteAction.php — create() silently discards both fields:
        SiteSubdomainAlias::query()->create([
            'site_id'       => $site->id,
            'subdomain'     => $site->subdomain,
            'reclaim_until' => now()->addDays($reclaimDays),   // silently dropped
            'expires_at'    => now()->addDays($redirectDays),  // silently dropped
            'created_at'    => now(),
        ]);

        // app/Console/Commands/PruneExpiredHandleAliases.php — skips NULL rows:
        $expiredSubdomainIds = $pgsql->table('site.site_subdomain_aliases')
            ->whereNotNull('expires_at')     // NULL rows never match → never pruned
            ->where('expires_at', '<', now())
            ->pluck('id');

        // Contrast: UserHandleAlias correctly declares both columns:
        protected $fillable = [
            'user_id', 'handle', 'reclaim_until', 'expires_at',
            'notified_t3_at', 'notified_t1_at', 'created_at', 'updated_at',
        ];
        protected $casts = [
            'reclaim_until' => 'datetime',
            'expires_at'    => 'datetime',
            // ...
        ];
        public function scopeActive($query) { ... }
        public function scopeReclaimable($query) { ... }
        ```

---

## P2 — Should fix

- [x] **#SEM-2** · P2 — `MediaDiskResolver` returns stale config value instead of probed runtime env var
    - **Where:** app/Services/Media/MediaDiskResolver.php:39-42
    - **Affects:** Any deployment where `PARTNA_MEDIA_DISK` is added or changed as a runtime env injection without a config-cache rebuild — all image and video uploads silently route to the wrong disk (the `'media'` sentinel) instead of the intended R2 bucket.
    - **Effort:** S (~5 min)
    - **What to do:**
        - Change `return $configured;` to `return trim($explicit);` on the early-return branch.
        - This makes the `$_ENV` probe the authoritative source when the env var is present in the process — which is precisely the scenario the probe was introduced to handle.
    - **Technical:** The class docblock states: *"Laravel Cloud caches config at deploy time but injects platform env vars into the process environment at runtime, so env()/config() won't reflect them."* This is exactly why the `$_ENV`/`$_SERVER` probes exist. Yet the early-return branch (`if (is_string($explicit)) { return $configured; }`) returns `config('partna.media_disk')` — the deploy-time cached value — rather than the probed `$explicit` value. When `PARTNA_MEDIA_DISK` is injected at runtime on a deployment where config was cached without it, `$configured` is `'media'` (the sentinel) while `$explicit` is the real disk name. The method returns `'media'`, and all uploads silently go to whichever disk Laravel resolves as `'media'`. The fix is one word: return `$explicit` instead of `$configured`.
    - **Plain English:** The code detects the correct disk name from the server's live environment, then throws it away and uses a potentially-stale setting from the app's config file instead. One word changes this.
    - **Evidence:**
        ```php
        // MediaDiskResolver.php — class docblock explicitly states config() won't reflect runtime-injected vars...
        // "Laravel Cloud caches config at deploy time but injects platform env vars into the process
        //  environment at runtime, so env()/config() won't reflect them."

        // ...yet the early-return ignores $explicit and returns the (potentially stale) config value:
        $explicit = $_ENV['PARTNA_MEDIA_DISK'] ?? $_SERVER['PARTNA_MEDIA_DISK']
            ?? $_ENV['SIDEST_MEDIA_DISK'] ?? $_SERVER['SIDEST_MEDIA_DISK'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return $configured;  // ← should be: return trim($explicit);
        }
        ```

- [x] **#SEM-3** · P2 — GDPR email-resolution methods in data-export and deletion paths have diverged in event filter and sort order
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php (`resolveLookupEmail`, ~line 62) vs app/Services/User/AccountDeletionService.php (`resolvePurgeEmail`, ~line 300)
    - **Affects:** GDPR data-subject access requests (DSARs) triggered during the 30-day deletion grace period — the data-export path may fail to surface the user's original email for waitlist rows and global email subscriptions, producing an incomplete Article 15 export.
    - **Effort:** S (~1h)
    - **What to do:**
        - In `DataExportPayloadBuilder::resolveLookupEmail`, add `UserDeletionAuditEntry::EVENT_CONFIRMED` to the `whereIn` filter and replace the three hardcoded strings with class constants.
        - Change `orderBy('created_at')` (ASC) to `orderByDesc('created_at')` to match `resolvePurgeEmail`'s "latest snapshot wins" semantics.
        - Extract a shared private-static method (or a small `ResolvesDeletedEmail` concern) used by both classes so this logic cannot drift again.
    - **Technical:** `resolveLookupEmail` queries `whereIn('event', ['requested', 'admin_initiated'])` with hardcoded strings and ascending sort. `resolvePurgeEmail` queries `whereIn('event', [EVENT_REQUESTED, EVENT_CONFIRMED, EVENT_ADMIN_INITIATED])` using class constants with descending sort. The data-export path currently finds the correct row (masking the bug), but if `EVENT_CONFIRMED` is ever written without a preceding `EVENT_REQUESTED` (admin-initiated paths), the ASC query finds nothing. Using hardcoded strings means a constant rename silently breaks the filter with no type-check. GDPR compliance requires parity.
    - **Plain English:** Two functions that are supposed to answer the same question — "what was this user's real email before we scrambled it?" — now ask the database in slightly different ways. Today they both happen to return the right answer. But if the order ever changes, or the scrambling process changes slightly, the data-export version silently returns nothing, producing an incomplete GDPR export — a legal exposure, not just a code smell.
    - **Evidence:**
        ```php
        // DataExportPayloadBuilder.php — hardcoded strings, missing EVENT_CONFIRMED, ASC order
        $snapshot = DB::connection('pgsql')
            ->table('audit.user_deletion_audit')
            ->where('user_id', $professional->id)
            ->whereIn('event', ['requested', 'admin_initiated'])
            ->orderBy('created_at')
            ->value('professional_email_snapshot');

        // AccountDeletionService.php — class constants, includes EVENT_CONFIRMED, DESC order
        $snapshot = DB::connection('pgsql')
            ->table('audit.user_deletion_audit')
            ->where('user_id', $professional->id)
            ->whereIn('event', [
                UserDeletionAuditEntry::EVENT_REQUESTED,
                UserDeletionAuditEntry::EVENT_CONFIRMED,
                UserDeletionAuditEntry::EVENT_ADMIN_INITIATED,
            ])
            ->orderByDesc('created_at')
            ->value('professional_email_snapshot');
        ```

- [x] **#SEM-4** · P2 — Eventbrite upcoming-event filter uses lexicographic string comparison on ISO 8601 timestamps with timezone offsets
    - **Where:** app/Services/Platforms/EventbriteScraper.php:96-104 (approx)
    - **Affects:** Eventbrite smart links in public profiles. Events in negative-UTC-offset timezones (US, Canada, Western Europe) can be incorrectly filtered out as "past" and omitted from the upcoming-events list while they are still in the future, because the local-time hour digits compare as smaller than the UTC server-time digits.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$now = now()->toIso8601String()` with `$now = now()` (a Carbon instance).
        - In the `array_filter` callback, wrap the event date in `Carbon::parse()` and compare with `->gte($now)`: `fn ($e) => empty($e['endDate'] ?? $e['startDate'] ?? null) || Carbon::parse($e['endDate'] ?? $e['startDate'])->gte($now)`.
        - Apply the same Carbon-based comparison to the `usort` call, replacing `strcmp(...)` with a Carbon diff comparison so sort order is correct across timezone offsets.
    - **Technical:** `$now = now()->toIso8601String()` produces a UTC offset string such as `"2026-06-20T16:00:00+00:00"`. Eventbrite's JSON-LD `startDate`/`endDate` fields carry the event's local timezone offset, e.g. `"2026-06-20T09:00:00-07:00"` for a 9am LA event (= 16:00 UTC). The filter `($e['endDate'] ?? $e['startDate']) >= $now` performs a plain PHP string comparison. `"09:00:00-07:00"` compares as `"09..." < "16..."` — the LA event is incorrectly treated as already ended and excluded from the upcoming list, even though it hasn't started. The `usort` has the same flaw, potentially misordering events.
    - **Plain English:** The code checks whether an event is upcoming by comparing the time as text — like alphabetically sorting words. A Los Angeles event starting at 9am looks like "09:00" in text, while the server's current UTC time might read "16:00" — so the code reads "09 comes before 16" and wrongly concludes the event is in the past. The fix is to convert both times to a common reference (UTC) before comparing.
    - **Evidence:**
        ```php
        usort($events, fn ($a, $b) => strcmp((string) ($a['startDate'] ?? ''), (string) ($b['startDate'] ?? '')));
        $now = now()->toIso8601String();
        $upcoming = array_values(array_filter(
            $events,
            fn ($e) => empty($e['endDate'] ?? $e['startDate'] ?? null) || ($e['endDate'] ?? $e['startDate']) >= $now,
        ));
        ```

- [x] **#SEM-5** · P2 — `guardApifyBudget` acquires the per-user cooldown before checking the global daily cap
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php (`guardApifyBudget`, lines 154–168)
    - **Affects:** Any user who attempts an Instagram connect when `APIFY_DAILY_CAP` (200/day) has already been reached. Their request is rejected for capacity reasons — but their personal 600-second cooldown is already set, so they cannot retry even after the daily cap resets (midnight) without waiting out the full cooldown window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Reorder the two checks: read the daily counter (`Cache::get($dayKey, 0)`) and return the capacity error first, before calling `Cache::add($cooldownKey, ...)`.
        - Only acquire the per-user cooldown and increment the daily counter when the request will actually proceed to the Apify scraper.
    - **Technical:** `Cache::add($cooldownKey, 1, self::APIFY_COOLDOWN_SECONDS)` atomically writes a 600-second lock before the daily cap is read. If `$count >= APIFY_DAILY_CAP`, the method returns a 429, but the cooldown entry has already been committed. The user is now gated by two independent back-off timers despite their request never touching the paid Apify API. The daily-cap check is a single `Cache::get` (cheap, no side-effects) and should run first.
    - **Plain English:** Visitors to an at-capacity theme park ride are handed a "come back in 10 minutes" ticket before anyone checks whether the ride is still open for the day. If the ride already hit its daily capacity, the visitor gets turned away — but their ticket still prevents them from getting back in line until it expires. Check capacity first; only issue the timer ticket to people the ride will actually let on.
    - **Evidence:**
        ```php
        $cooldownKey = "platforms:instagram:cooldown:{$user->id}";
        if (! Cache::add($cooldownKey, 1, self::APIFY_COOLDOWN_SECONDS)) {
            return $this->error('You refreshed Instagram recently — please wait a few minutes.', 429);
        }

        $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::APIFY_DAILY_CAP) {
            return $this->error('Instagram is busy right now — please try again later.', 429);
        }
        Cache::put($dayKey, $count + 1, now()->addDay());

        return null;
        ```

---

## P3 — Nice to have

### Semantic (SEM)

- [ ] **#SEM-6** · P3 — `UserBootstrapService::bootstrap` wraps Eloquent writes in an unpinned `DB::transaction()`, breaking rollback in feature tests
    - **Where:** app/Services/User/UserBootstrapService.php (bootstrap method, `DB::transaction(...)`)
    - **Affects:** Feature test isolation for the bootstrap path — if a test triggers a mid-transaction error, `User` and `Site` rows written on the `pgsql` connection are not rolled back by the transaction wrapper (which runs on the SQLite default in test environments).
    - **Effort:** S (~5 min)
    - **What to do:**
        - Change `DB::transaction(function () use (...)` to `DB::connection('pgsql')->transaction(function () use (...)`.
    - **Technical:** `AccountDeletionService::request()` carries an explicit comment documenting exactly this issue: *"Pin the transaction to 'pgsql' explicitly so it shares the connection with the Eloquent writes inside (User extends BaseModel which forces pgsql). Using bare DB::transaction() would target the default connection, which is 'sqlite' in feature tests — making the wrapper a no-op and breaking rollback."* `UserBootstrapService::bootstrap` does not apply the same fix, despite creating both a `User` and a `Site` inside the transaction body.
    - **Plain English:** The code that creates a new user's account wraps its database work in a safety net — if anything goes wrong halfway through, the safety net is supposed to undo everything. In tests, the safety net is attached to the wrong rope: the code writes to one database but the safety net is tied around a different one, so failed-halfway tests leave phantom records behind that can cause other tests to fail unexpectedly. The fix is documented in a sister function in the same codebase.
    - **Evidence:**
        ```php
        // UserBootstrapService.php — bare DB::transaction(), runs on SQLite default in tests
        return DB::transaction(function () use ($uid, $data, $existing) {
            // ... User::save() and Site::create() run on pgsql via BaseModel ...
        });

        // AccountDeletionService.php — same scenario, explicitly fixed with a comment explaining why
        DB::connection('pgsql')->transaction(function () use ($professional, $tokenHash, $rawToken, $request) {
            // Pin to 'pgsql' explicitly so it shares the connection
            // with the Eloquent writes inside (User extends BaseModel which forces
            // pgsql). Using bare DB::transaction() would target the default
            // connection, which is 'sqlite' in feature tests — making the wrapper
            // a no-op and breaking rollback.
        });
        ```

- [x] **#SEM-7** · P3 — Dead assigned variable and stale developer note in `HealthController::checkCache`
    - **Where:** app/Http/Controllers/Api/HealthController.php:101 (`checkCache` method)
    - **Affects:** No runtime impact. The variable is assigned and immediately abandoned; the comment is a leftover thinking-out-loud note that can confuse future readers.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Delete the line `$store = config('cache.default'); // or config('cache.default') depending on your config`.
        - The `Cache::put`, `Cache::get`, and `Cache::forget` calls on the following lines already use the default store via the facade.
    - **Technical:** `$store` is assigned from `config('cache.default')` on line 101 and is never referenced again in `checkCache()`. The comment alongside it is a stale developer note from when explicit store targeting was being considered. The assignment is pure dead code.
    - **Evidence:**
        ```php
        $store = config('cache.default'); // or config('cache.default') depending on your config
        $key = 'health:cache:'.bin2hex(random_bytes(8));
        $value = bin2hex(random_bytes(8));

        Cache::put($key, $value, now()->addSeconds(10));
        $read = Cache::get($key);
        Cache::forget($key);
        ```

- [x] **#SEM-8** · P3 — `UserSectionBlockController::upsert` uses `count()` for new-block `sort_order`; every other path uses `max() + 1`
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php (new-block branch of `upsert`, lines 171–175) vs. `syncAllowedSections` (line 358/376) in the same file
    - **Affects:** `sort_order` assignment when a section block is first created. Produces correct value today because section blocks are never hard-deleted, but would assign a colliding `sort_order` if any row were ever removed outside the normal soft-delete flow (manual DB correction, future admin operation, or a test fixture teardown).
    - **Effort:** S (~0.5h)
    - **What to do:**
        - In the `! $block->exists` branch of `upsert()`, replace the `Block::query()->...->count()` approach with the `max('sort_order') ?? -1` then `(int) $maxSort + 1` pattern already used in `syncAllowedSections` (same file) and in `StaffSectionManagementController::upsert`.
    - **Technical:** `count()` equals `max(sort_order) + 1` only when all `sort_order` values are contiguous integers starting at 0 — an invariant that holds today because the app never hard-deletes section blocks. If a row were ever hard-deleted, `count()` would compute a value that collides with an existing live row, violating the partial unique index on `(site_id, block_group, sort_order) WHERE block_group = 'sections'`.
    - **Evidence:**
        ```php
        // upsert() — new-block branch
        $existingCount = Block::query()
            ->where('site_id', $site->id)
            ->where('block_group', 'sections')
            ->count();
        $block->sort_order = (int) $existingCount;

        // syncAllowedSections() — same file, safer pattern
        $maxSortOrder = $allBlocks->max('sort_order') ?? -1;
        // ...
        $block->sort_order = ++$maxSortOrder;
        ```

- [x] **#SEM-9** · P3 — `EnquiryResource` derives `is_read` from status alone; a null status silently produces `is_read: true`
    - **Where:** app/Http/Resources/EnquiryResource.php — `is_read` line inside `toArray()`
    - **Affects:** Dashboard inbox — an enquiry whose `status` is null renders `is_read: true`, suppressing the unread badge.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an explicit null guard: `'is_read' => $this->status !== null ? ($this->status->value !== 'new') : ($this->read_at !== null),`
    - **Technical:** `$this->status?->value !== 'new'` evaluates `null !== 'new'` — which is `true` — whenever `status` is null. The docblock explicitly states "read_at is retained for backwards compatibility with existing consumers that relied on the timestamp form," implying the two signals should agree. Under production DB defaults (`status` NOT NULL with `'new'` default) this is masked, but SQLite test rows created without the column default, a bulk-import, or a future migration gap can produce null-status rows.
    - **Evidence:**
        ```php
        'is_read' => $this->status?->value !== 'new',
        ```

- [x] **#SEM-10** · P3 — `SendEnquiryNotificationJob` stamp is outside the lock, making the at-most-once comment incorrect
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php — `lockForUpdate` block and the `email_sent_at` stamp at line 105
    - **Affects:** Professional inbox — when a job is killed near the 30s timeout (before the stamp), the enquiry row never receives `email_sent_at`, so the retry sees `null` and sends a duplicate notification email to the professional.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$enquiry->forceFill(['email_sent_at' => now()])->saveQuietly()` inside the `DB::transaction` block, before `return $e` — exactly as `SendEnquiryConfirmationJob` does for `confirmation_sent_at`.
    - **Technical:** The `lockForUpdate` transaction releases the row lock when the closure returns — before the mail is sent and before the stamp. The comment at the lock site explicitly claims "Lock the enquiry row so two concurrent workers… can't both see `email_sent_at = null` and both deliver the email" — but since the stamp happens outside the transaction, this is false. A worker killed after the transaction ends but before the stamp leaves `email_sent_at = null`; the retry then sends a duplicate. The sister job `SendEnquiryConfirmationJob` stamps `confirmation_sent_at` correctly — inside the `lockForUpdate` transaction — giving genuine at-most-once semantics.
    - **Plain English:** The lock on the database row is like a "being processed" sign placed on a shelf. The sign is taken down as soon as you start processing — before you log the work. A second worker walks up, sees no sign, and processes the same item again. The fix: log the work while the sign is still up.
    - **Evidence:**
        ```php
        // SendEnquiryNotificationJob — stamp is OUTSIDE the transaction
        $enquiry = DB::transaction(function () {
            $e = Enquiry::query()->lockForUpdate()->find($this->enquiryId);
            if ($e === null) { return null; }
            if ($e->email_sent_at !== null) { return false; }
            return $e; // <-- lock released here without stamping
        });
        // ... many lines later, after mail is sent ...
        $enquiry->forceFill(['email_sent_at' => now()])->saveQuietly(); // <-- stamp is too late

        // SendEnquiryConfirmationJob — correct at-most-once pattern
        $e->forceFill(['confirmation_sent_at' => now()])->saveQuietly(); // <-- stamped INSIDE lock
        return $e;
        ```

---

### Slop (SLOP)

- [x] **#SLOP-1** · P3 — `str()` helper duplicated identically across five extractor classes
    - **Where:** app/Services/SmartLinks/Extractors/ITunesExtractor.php, OEmbedExtractor.php, ShopifyExtractor.php, SpotifyExtractor.php, StructuredDataExtractor.php
    - **Affects:** Anyone maintaining or extending the extractor namespace — must remember to copy the same helper on every new extractor; a fix to one copy silently leaves four others stale.
    - **Effort:** S (~45 min, bundle with SLOP-2 and SLOP-3)
    - **What to do:**
        - Create `app/Services/SmartLinks/Extractors/Concerns/TrimsStrings.php` as a PHP trait exporting `str(mixed $v): ?string`.
        - Add the trait to all five extractor classes. Remove the five private copies.
    - **Technical:** Five identical `private function str(mixed $v): ?string` implementations exist across the extractor namespace. No extractor overrides the logic. SLOP-2 (`hostBrand`) and SLOP-3 (`subTypeFromType`) share the same root cause and should be fixed in the same commit.
    - **Evidence:**
        ```php
        // ITunesExtractor.php (verbatim; identical copies in four other extractors)
        private function str(mixed $v): ?string
        {
            if (! is_string($v)) {
                return null;
            }
            $t = trim($v);

            return $t === '' ? null : $t;
        }
        ```

- [x] **#SLOP-2** · P3 — `hostBrand()` duplicated across `ShopifyExtractor` and `StructuredDataExtractor`
    - **Where:** app/Services/SmartLinks/Extractors/ShopifyExtractor.php:242-247, StructuredDataExtractor.php:214-219
    - **Affects:** Maintenance — changing the fallback-brand-name logic requires editing two files; silent divergence already exists (one uses an intermediate variable, one inlines it).
    - **Effort:** S (~15 min, bundle with SLOP-1 and SLOP-3)
    - **What to do:**
        - Move `hostBrand(string $host): string` into the `Concerns\TrimsStrings` trait (see SLOP-1).
    - **Evidence:**
        ```php
        // ShopifyExtractor.php:242-247
        private function hostBrand(string $host): string
        {
            $host = preg_replace('/^www\./', '', $host);
            return ucfirst(explode('.', $host)[0] ?? $host);
        }

        // StructuredDataExtractor.php:214-219
        private function hostBrand(string $host): string
        {
            $host = preg_replace('/^www\./', '', $host);
            $label = explode('.', $host)[0] ?? $host;
            return ucfirst($label);
        }
        ```

- [x] **#SLOP-3** · P3 — `subTypeFromType()` duplicated across `OEmbedExtractor` and `SpotifyExtractor`
    - **Where:** app/Services/SmartLinks/Extractors/OEmbedExtractor.php:110-114, SpotifyExtractor.php:115-119
    - **Affects:** Maintenance — same one-liner in two files; any change requires editing both.
    - **Effort:** S (~15 min, bundle with SLOP-1 and SLOP-2)
    - **What to do:**
        - Move `subTypeFromType(string $type): ?string` into the shared trait (see SLOP-1).
    - **Evidence:**
        ```php
        // OEmbedExtractor.php:110-114 (identical in SpotifyExtractor.php:115-119)
        private function subTypeFromType(string $type): ?string
        {
            $parts = explode('.', $type);
            return end($parts) ?: null;
        }
        ```

- [ ] **#SLOP-4** · P3 — Decorative banner comments in `ImageVariantService`
    - **Where:** app/Services/Media/ImageVariantService.php (two banner blocks separating public and private methods)
    - **Affects:** Readability — violates CLAUDE.md's explicit "Avoid: decorative banners" rule.
    - **Effort:** S (~5 min)
    - **What to do:**
        - Delete both `/* ---- / /* Public API */ / /* ---- */` and `/* ---- / /* Internal helpers */ / /* ---- */` blocks.
    - **Evidence:**
        ```php
        /* ------------------------------------------------------------------ */
        /*  Public API */
        /* ------------------------------------------------------------------ */

        /* ------------------------------------------------------------------ */
        /*  Internal helpers */
        /* ------------------------------------------------------------------ */
        ```

- [ ] **#SLOP-5** · P3 — Section-separator comments restate the next code block in `SiteCacheService`
    - **Where:** app/Services/Cache/SiteCacheService.php, inside `resolveImageVariantUrlsInSite`
    - **Affects:** Readability — four `// ---` separator lines say *what*, not *why*.
    - **Effort:** S (~5 min)
    - **What to do:**
        - Delete the four `// ---` separator lines. If the method feels hard to follow without labels, extract each block into a named private method instead.
    - **Evidence:**
        ```php
        // --- Collect all media IDs (images + videos) in one pass ---
        // --- Images: resolve variant URLs ---
        // --- Videos: resolve variant/stream/poster URLs ---
        // --- Document: resolve preview_url from storage path to full CDN URL ---
        ```

- [ ] **#SLOP-6** · P3 — File-path comments duplicate the namespace declaration in 7 Analytics service files
    - **Where:** app/Services/Analytics/AnalyticsDedupGuard.php:2, AnalyticsEvent.php:2, Ingestors/QueuedIngestor.php:2, Ingestors/SyncIngestor.php:3, Writers/PostgresEventWriter.php:3, Contracts/AnalyticsEventWriter.php:2, Contracts/AnalyticsIngestor.php:2
    - **Affects:** Developers reading these files — the comments add zero information not already encoded in the `namespace` declaration and file location.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the file-path comment line from each of the 7 files. In `SyncIngestor.php` and `PostgresEventWriter.php`, also remove the orphaned blank lines the comment left between `<?php` and `namespace`.
    - **Evidence:**
        ```php
        // AnalyticsDedupGuard.php
        <?php
        // app/Services/Analytics/AnalyticsDedupGuard.php
        namespace App\Services\Analytics;
        ```

- [ ] **#SLOP-7** · P3 — V2 comment in `CustomerResource` restates the field list immediately below it
    - **Where:** app/Http/Resources/CustomerResource.php:7
    - **Affects:** Readability — the comment enumerates verbatim fields that appear on the next 12 lines.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the `// V2: API resource for customer records — transforms email, phone, name, source, notes, and marketing opt-in status.` comment.
    - **Evidence:**
        ```php
        // V2: API resource for customer records — transforms email, phone, name, source, notes, and marketing opt-in status.
        class CustomerResource extends ApiResource
        ```

- [ ] **#SLOP-8** · P3 — Three near-identical 4–5 line comments re-explaining the same `stdClass` coercion
    - **Where:** app/Http/Resources/PublicSite/IndividualProfileResource.php — the `$designKit`, `$publicConfig`, and `$siteImages` blocks in `toArray()`
    - **Affects:** Developer attention — the same `[]` vs `{}` serialisation lesson is read three times in thirty lines.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Keep the first comment block (on `$designKit`) in full — it is purposeful.
        - Replace the second and third comment blocks with a single-line reference: `// Same empty-object coercion as $designKit above.`
    - **Evidence:**
        ```php
        // First block (keep as-is):
        // Empty designKit must serialize as `{}` (object), not `[]` (array). ...
        $designKitOut = $designKit === [] ? new stdClass : $designKit;

        // Second and third blocks (condense to one line each):
        // Same story for publicConfig — always an object, even if every field is absent. ...
        $publicConfigOut = $publicConfig === [] ? new stdClass : $publicConfig;

        // siteImages is a purpose-keyed map ...; an empty map must serialize as `{}` ...
        $siteImagesOut = $siteImages === [] ? new stdClass : $siteImages;
        ```

- [ ] **#SLOP-9** · P3 — Dead `$backoff` property on a job that declares `$tries = 1`
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:26–28
    - **Affects:** No runtime impact — dead config that a reader must mentally discard.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete `public int $backoff = 0;` and the comment above it.
    - **Technical:** The job declares `public int $tries = 1`. Laravel's queue worker only consults `$backoff` when scheduling a retry — with `$tries = 1` the job is never retried, so `$backoff` is read exactly zero times. The inline comment admits this ("tries=1 means no retry, so backoff is moot") but claims it is "required for hygiene" — which is false.
    - **Evidence:**
        ```php
        public int $tries = 1;

        // No backoff — tries=1 means no retry, so backoff is moot, but required for hygiene.
        public int $backoff = 0;
        ```

- [ ] **#SLOP-10** · P3 — Tutorial-style "Typical usage:" example inside a production class docblock
    - **Where:** app/Http/Resources/Moderation/CaseDetailResource.php:15–17
    - **Affects:** Readability — usage examples belong in tests or developer docs, not in the class file.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the `Typical usage:` block (the label line and the example code line).
        - Keep the two preceding paragraphs — they document real contract behaviour (`whenLoaded()` semantics and PII strip).
    - **Evidence:**
        ```php
        /**
         * Uses whenLoaded() throughout so callers control which relations are hydrated; ...
         * Composes CaseSignalResource which enforces the PII strip on signals.
         *
         * Typical usage:
         *   new CaseDetailResource($case->load(['signals', 'evidence', 'decisions']))
         */
        ```

- [ ] **#SLOP-11** · P3 — Seven moderation jobs share ~25 identical lines of ActionLogEntry lifecycle boilerplate
    - **Where:** app/Jobs/Moderation/SuspendSiteJob.php, SuspendUserJob.php, QuarantineMediaJob.php, PurgeModerationCacheJob.php, NotifyOnCallStaffJob.php, NotifyReportedUserJob.php, NotifyReporterJob.php
    - **Affects:** Future maintainers — a change to the `ActionLogEntry` lifecycle must be replicated identically across seven files with real drift risk.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract a `HasActionLogLifecycle` trait providing: `$tries`, `$backoff`, the `$actionLogId`/`$caseId` constructor body with PHP 8.4 workaround, the standard `failed()` body, and helper methods `markDispatched()` and `markCompleted()`.
        - The two queue values (`moderation_high`, `notifications`) diverge across jobs — keep `$this->queue = ...` in each constructor after the trait default is applied.
    - **Evidence:**
        ```php
        // SuspendSiteJob.php (identical pattern in all seven jobs; only queue and enforcement action differ)
        public int $tries = 3;
        public array $backoff = [10, 30, 60];

        public function failed(Throwable $e): void
        {
            report($e);
            ActionLogEntry::query()->where('id', $this->actionLogId)->update([
                'status'    => 'failed',
                'failed_at' => now(),
            ]);
            Log::error('Moderation enforcement job permanently failed', [...]);
        }
        ```

- [ ] **#SLOP-12** · P3 — Image and video variant jobs duplicate ~60 lines of lock, guard, and cleanup logic
    - **Where:** app/Jobs/ProcessImageVariantsJob.php and app/Jobs/ProcessVideoVariantsJob.php
    - **Affects:** Future maintainers — a fix to the in-flight lock strategy, the terminal-state guard, or the `markFailed`/`cleanupR2Artifacts` contract must be applied identically to both files.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Extract a `HandlesMediaVariantProcessing` trait containing: Redis `SET NX` lock acquire/release, terminal-state guard (`READY`/`FAILED`), `withTrashed()->find()` + `trashed()` early-return, `processing_state` transition, original-file existence check, `tempnam()` + temp-file cleanup-in-finally, `failed()`, `markFailed()`, and `cleanupR2Artifacts()`.
        - Keep the differences explicit: queue connection (`redis` vs `redis_video`), timeout/tries, lock key prefix, and the service-specific `processVariants()` call.
    - **Evidence:**
        ```php
        // ProcessImageVariantsJob.php (identical structure in ProcessVideoVariantsJob, different key prefix)
        $lockKey = "image:processing-lock:{$this->imageId}";
        $acquired = Redis::set($lockKey, '1', 'EX', $this->timeout + 60, 'NX');
        if (! $acquired) {
            Log::info('ProcessImageVariantsJob: another worker is processing this image, skipping.');
            return;
        }
        try {
            $this->runHandle($service);
        } finally {
            Redis::del($lockKey);
        }
        ```

---

## Suggested Bundled Sessions

### Bundle A — SmartLinks extractor consolidation (SLOP-1 + SLOP-2 + SLOP-3)
One trait, three helpers extracted, ~45 min total. Low risk — pure internal refactor with no public API surface change.

### Bundle B — GDPR parity + subdomain alias lifecycle (SEM-1 + SEM-3)
Both are data-correctness gaps touching the user lifecycle domain. SEM-1 requires a DB backfill — run on dev first and verify via SQL before prod. ~2h total.

### Bundle C — Notification delivery correctness (SEM-10)
Standalone fix: move the `email_sent_at` stamp inside the `lockForUpdate` transaction. ~0.5h. Low blast radius — one job file, matches pattern already used in the sibling job.

### Bundle D — Media disk resolver one-liner (SEM-2)
One-word fix. Trivially safe — change `$configured` to `trim($explicit)`. ~5 min.

### Bundle E — Apify + Eventbrite scraper fixes (SEM-4 + SEM-5)
Both are in the Platforms layer. SEM-5 is a reorder (no logic change); SEM-4 swaps string comparison for Carbon. ~1.5h total.

### Bundle F — Comment/slop cleanup pass (SLOP-4 + SLOP-5 + SLOP-6 + SLOP-7 + SLOP-8 + SLOP-9 + SLOP-10)
Pure comment deletions/trims across 12 files. No logic change. ~1h, good for a focused cleanup commit.

### Standalone — do NOT bundle

- **SLOP-11** (7 moderation jobs trait extraction) — M effort, touches 7 job files. Run as a focused session with full test suite pass before merging.
- **SLOP-12** (image/video variant jobs trait extraction) — L effort, touches critical media processing paths. Run in isolation with manual smoke-test of image and video upload flows.
- **SEM-6** (UserBootstrapService transaction pin) — Low risk but touches the user bootstrap path; verify feature tests pass end-to-end before merging.
