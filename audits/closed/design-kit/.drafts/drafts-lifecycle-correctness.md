- [ ] **LIFE-1** · P1 — `UpdateSiteAction` catches generic `QueryException` and string-compares error codes instead of using `UniqueConstraintViolationException`
    - **Where:** `app/Services/Site/UpdateSiteAction.php` (three catch blocks in `execute()`)
    - **Affects:** Subdomain-change path at scale (200 brands, periodic renames, alias lifecycle). A Postgres upgrade that changes error-code formatting could break the string comparison; a different unique-constraint violation (new index added later) would be silently caught instead of surfaced.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace all three `catch (QueryException $e)` blocks that check `$e->getCode() === '23505'` / `!== '23505'` with `catch (UniqueConstraintViolationException $e)`.
        - Keep the fallback logic (refresh alias timestamps, throw ValidationException) inside the typed catch.
    - **Technical:** The canonical replacement is `UniqueConstraintViolationException` (`#STRIPE-3`, `35c6f31`). `QueryException::getCode()` returns the SQLSTATE as a string, and comparing string `'23505'` is fragile — Postgres major-version upgrades or constraint renames can change the format. `UniqueConstraintViolationException` is a typed subclass in Laravel 10+ that the driver raises specifically for code 23505; catching it by type is version-stable and self-documenting. Three locations in `execute()` use the anti-pattern: the `SiteSubdomainAlias::create()` retry block, the `UserHandleAlias::create()` retry block, and the final `$site->save()` guard.
    - **Plain English:** There are three spots in the subdomain-change workflow that catch a very broad category of database error and then squint at the error message to decide what to do. It's like answering every phone call with "hello, is this about a duplicate subdomain?" instead of having a dedicated line for that specific issue. If the database software changes how it formats error codes in a future update, those string checks would break silently.
    - **Evidence:**
        ```php
        // app/Services/Site/UpdateSiteAction.php — SiteSubdomainAlias create
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        
        // app/Services/Site/UpdateSiteAction.php — UserHandleAlias create  
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        
        // app/Services/Site/UpdateSiteAction.php — final site save
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-2** · P2 — `IndividualProfileController` forgets `handle.resolve` primary key but leaves the `:stale` twin alive on deleted-race recovery
    - **Where:** `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php` (the `$payload === null` branch in `show()`)
    - **Affects:** Public profile read path — rare edge case where a professional is deleted between the resolve-cache write and the payload build. The stale twin survives and the next request hits a stale resolve entry pointing at a dead professional, causing a 404 until the stale TTL expires (~5 min default).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Cache::forget(CacheKeyGenerator::handleResolve($handleLc) . ':stale')` alongside the existing primary-key forget.
        - Or delegate to a helper that busts both (e.g. `Cache::deleteMultiple([$key, $key.':stale'])`).
    - **Technical:** The canonical replacement is `bust :stale twin` (`f5450d8`). The `handle.resolve` cache is written by `CacheLockService::rememberLocked`, which writes both a primary key and a `:stale` SWR twin. The controller's recovery path (when `$payload === null`) only calls `Cache::forget(key)` — the `:stale` twin survives. The stale twin has a 10× multiplier TTL (300 s for a 30 s primary), so the bad resolve entry can persist for up to 5 minutes. At scale (40K daily notifications driving profile visits), this increases the 404 rate on a rare-but-real race condition.
    - **Plain English:** When the system detects that a cached lookup points to a deleted account, it clears the main cache entry but forgets to clear the "backup" copy. It's like deleting a file from your desktop but leaving a copy in the recycle bin — the next request fishes the stale backup out and serves a broken result. The fix is a one-liner: delete both copies at the same time.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
        if ($payload === null) {
            // Resolve cache pointed at a now-deleted row. Forget the resolve
            // entry so the next request rebuilds from scratch.
            Cache::forget(CacheKeyGenerator::handleResolve($handleLc));
            $this->logIfSlow($handleLc, '404-deleted-race', $startedAt);
            return $this->error('Not found.', 404);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-3** · P2 — `SiteCacheService::invalidateSiteImages` busts variant keys with `:stale` but leaves the base `siteImages` key without its `:stale` twin
    - **Where:** `app/Services/Cache/SiteCacheService.php` (`invalidateSiteImages()` method)
    - **Affects:** Image-gallery cache after any media upload/delete. The base `site:{id}:images:active` key's stale twin survives invalidation, so a primary eviction during a traffic spike would serve a potentially stale gallery for up to 10× the primary TTL.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Append `$keys[] = CacheKeyGenerator::siteImages($site->id) . ':stale'` alongside the existing primary-key entry.
        - Or use `...self::bustWithStale(CacheKeyGenerator::siteImages($site->id))` to stay consistent with the rest of the class.
    - **Technical:** The canonical replacement is `bust :stale twin` (`f5450d8`). Every other key in `invalidateSitePayload` and the variant keys in `invalidateSiteImages` itself bust both primary and `:stale`. The base `siteImages` key is the odd one out — only the primary is added to `$keys`. At the scale target (200 brands uploading media concurrently), a primary eviction that falls back to a stale twin would serve old gallery data until the stale TTL expires. The impact is cosmetic (stale images) but violates the SWR symmetry contract the rest of the cache layer enforces.
    - **Plain English:** When someone uploads a new image, the system clears the gallery cache so visitors see the new image. It clears 9 out of 10 copies correctly, but misses one backup copy. If the main cache happens to expire during a traffic spike, the backup surfaces old images. It's a small gap in an otherwise solid invalidation routine.
    - **Evidence:**
        ```php
        // app/Services/Cache/SiteCacheService.php
        public function invalidateSiteImages(Site $site): void
        {
            $keys = [CacheKeyGenerator::siteImages($site->id)];  // ← no :stale appended
        
            foreach (CacheKeyGenerator::siteImagesViewVariants() as [$pool, $mediaType]) {
                $variantKey = CacheKeyGenerator::siteImagesView($site->id, $pool, $mediaType);
                $keys[] = $variantKey;
                $keys[] = $variantKey.':stale';  // ← these get :stale
            }
            Cache::deleteMultiple(array_values(array_unique($keys)));
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-4** · P3 — `Log::warning` calls across request classes and notification jobs lack canonical Nightwatch correlation context
    - **Where:** `app/Http/Requests/Api/User/Site/UpdateSiteRequest.php`, `app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php`, `app/Jobs/Notifications/SendEnquiryConfirmationJob.php`, `app/Jobs/Notifications/SendSubscriptionConfirmationJob.php`
    - **Affects:** Operations visibility at scale. Without `brand_professional_id` and `request_id` in log context, Nightwatch cannot correlate alias-check failures or job failures to specific tenants or originating requests. At 40K daily notifications and 200 brands, this makes debugging transient failures a needle-in-haystack exercise.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `'brand_professional_id'`, `'request_id'`, and an operation name (e.g. `'operation' => 'subdomain_alias_check'`) to every `Log::warning` / `Log::error` call in these files.
        - For jobs, include `'job_id'` and `'attempt'` from the job instance.
    - **Technical:** The canonical replacement is `Log-with-context`. The Stripe payout pipeline established that every structured log must carry `brand_professional_id`, `request_id`, and the operation discriminator so Nightwatch can group and correlate. The `UpdateSiteRequest` alias-check warning and both notification job `failed()` handlers log with only `'error' => $e->getMessage()` — no tenant or request correlation. At the scale target, a single affiliate's misconfigured subdomain could generate dozens of warnings that Nightwatch can't tie back to a specific brand or session.
    - **Plain English:** The system writes helpful notes when something goes wrong — "alias check failed," "job failed" — but doesn't label who it was for or which request triggered it. It's like a customer support log that says "someone had a problem" without a name or ticket number. At 200 brands, that makes finding the right needle in the haystack take minutes instead of seconds.
    - **Evidence:**
        ```php
        // UpdateSiteRequest.php
        Log::warning('Professional alias check failed in UpdateSiteRequest', ['error' => $e->getMessage()]);
        
        // StaffUpdateSiteRequest.php  
        Log::warning('Professional alias check failed in StaffUpdateSiteRequest', ['error' => $e->getMessage()]);
        
        // SendEnquiryConfirmationJob.php
        Log::error('SendEnquiryConfirmationJob failed permanently', [
            'enquiry_id' => $this->enquiryId,
            'error' => $e->getMessage(),
        ]);
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **LIFE-5** · P2 — `design_kits:columns` cache key relies on deploy-script cache-clear for invalidation; no version-keying or in-band bust
    - **Where:** `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php` (`writeDesignKit()` method)
    - **Affects:** Design-kit writes after a migration adds columns to `site.design_kits`. A missed deploy-script cache-clear means new columns are silently filtered out by `array_intersect_key` for up to 1 hour. At 200 brands, a designer attempting to use a newly shipped design var would see it silently not save until the TTL expires or someone manually clears the cache.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Version-key the cache: `'design_kits:columns:v' . config('partna.design_kit_schema_version')` and bump the config version in every migration that adds/drops columns.
        - Or keep the deploy-script clear but add a health-check assertion that verifies the cached column list matches `information_schema` on boot in production, failing loudly if they diverge.
    - **Technical:** The canonical replacement is `version-keyed cache` (`27c1b7a`). The cache `'design_kits:columns'` has a 1-hour TTL and is invalidated only by `artisan cache:clear` in the deploy script — a side-effect decoupled from the migration that actually changes the column set. If the deploy script fails, is skipped, or runs before the migration, the cache serves stale column metadata and legitimate writes to new columns are silently dropped. At the scale target with frequent iterations on the design system, this creates a class of "it works on staging but not production" bugs that are hard to diagnose because the column exists in the DB but the application ignores it.
    - **Plain English:** When a new paint color option is added to the design system, the app keeps a 1-hour sticky note of which colors exist so it doesn't have to check the database every time someone saves. If the sticky note doesn't get thrown away when the new color ships, the app ignores the new color for an hour. Designers would save "cerulean blue" and it would just vanish. The fix is to put a version number on the sticky note so it automatically expires when colors change.
    - **Evidence:**
        ```php
        // UserSiteController.php
        // Column list is deploy-time stable; cache for 1 h so each save doesn't
        // pay an extra metadata round-trip. Busted by `artisan cache:clear`
        // in the deploy script whenever a design_kit migration adds/drops columns.
        $columns = Cache::remember('design_kits:columns', 3600, fn () => DB::connection('pgsql')
            ->table('information_schema.columns')
            ->where('table_schema', 'site')
            ->where('table_name', 'design_kits')
            ->pluck('column_name')
            ->all()
        );
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **LIFE-6** · P3 — `UpdateSiteRequest` and `StaffUpdateSiteRequest` carry ~80 duplicated `design_kit.*` validation rules instead of sharing a trait or parent class
    - **Where:** `app/Http/Requests/Api/User/Site/UpdateSiteRequest.php` and `app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php` (both `rules()` methods)
    - **Affects:** Maintenance velocity — every migration that adds/drops a `site.design_kits` column requires updating two identical rule blocks. A drift test (`DesignKitRequestSyncTest`) catches divergence at CI time, but the duplication itself is toil. At the design-system iteration cadence implied by 15+ migrations in ~2 weeks, this is a recurring tax.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the shared `design_kit.*` rules into a `DesignKitRules` trait or a dedicated FormRequest partial, and `use` it in both classes.
        - Keep the drift test as a safety net; the extraction just makes the "add a column" workflow touch one file instead of two.
    - **Technical:** Both `rules()` methods define an identical allowlist of ~80 `'design_kit.{column_name}'` validation rules (colors, typography, borders, space, icons, effects, sizing, responsive companions, motion, buttons). The Architecture doc mandates Form Request classes and the canonical `#STRIPE-1` pattern pushes shared logic into Policy abilities — the same principle applies to validation allowlists. Duplicated validation rules are a vector for drift (one class gets updated, the other is forgotten) and the only defence is the `DesignKitRequestSyncTest` which runs post-hoc. Extracting shared rules reduces the blast radius of a migration to one file.
    - **Plain English:** There are two copies of a very long checklist — "which design settings are allowed?" — that must stay identical. Every time the design system adds a new setting, both copies need updating. A test catches when they get out of sync, but it's like having two identical menus at a restaurant and needing to update both whenever the chef changes a dish. Moving the checklist to one shared master copy eliminates the double-update toil.
    - **Evidence:**
        ```php
        // UpdateSiteRequest.php — rules() contains ~80 design_kit.* entries:
        'design_kit' => ['sometimes', 'array'],
        'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        // ... ~78 more identical rules ...
        
        // StaffUpdateSiteRequest.php — rules() contains the SAME ~80 entries:
        'design_kit' => ['sometimes', 'array'],
        'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        // ... ~78 more identical rules ...
        ```
    - `[DRAFT, confidence: 0.8]`
