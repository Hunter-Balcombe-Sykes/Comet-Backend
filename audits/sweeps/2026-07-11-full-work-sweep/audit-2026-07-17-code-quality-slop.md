# AI Slop & Low-Value Code Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** AI Slop & Low-Value Code — comment noise, premature abstraction, dead code, defensive cruft, copy-paste drift (house style = `CLAUDE.md` Commenting / Simplicity-first / Do-NOT-over-engineer rules)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Mail/Branding/EmailBrandDefaults.php`
- `app/Services/Platforms/BigCartelScraper.php`
- `app/Services/Platforms/DoorDashMenuDriver.php`
- `app/Services/Platforms/GenericShopScraper.php`
- `app/Services/Platforms/GoogleBusinessAutoSync.php`
- `app/Services/Platforms/IdentitySync.php`
- `app/Services/Platforms/InstagramAutoSync.php`
- `app/Services/Platforms/InstagramScraper.php`
- `app/Services/Platforms/MenuMerger.php`
- `app/Services/Platforms/MenuScanApplier.php`
- `app/Services/Platforms/Normalizers/FacebookNormalizer.php`
- `app/Services/Platforms/Payloads/InstagramPayload.php`
- `app/Services/Platforms/PlatformScraper.php`
- `app/Services/Platforms/Registry/PlatformDescriptor.php`
- `app/Services/Platforms/ShopifyScraper.php`
- `app/Services/Platforms/UberEatsMenuDriver.php`
- `app/Services/Platforms/WebsiteLinkHarvester.php`
- `app/Services/Platforms/WooCommerceScraper.php`
- `app/Services/User/AccountDeletionService.php`
- `app/Services/User/SiteProvisioningService.php`
- `app/Services/User/UserBootstrapService.php`
- `app/Console/Commands/CleanupOrphanedLifestyleConnections.php`
- `app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php`
- `app/Http/Controllers/Api/User/Account/UserSelfController.php`
- `app/Http/Controllers/Api/User/Profile/SectorController.php`
- `app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Jobs/Platforms/InstagramConnectJob.php`
- `app/Jobs/Platforms/MenuFetchJob.php`
- `app/Observers/User/UserObserver.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

- [ ] **SLOP-1** · P2 — `seededFinding`/`conflictFinding`/`write`/`applyFinding` duplicated byte-for-byte between `GoogleBusinessAutoSync` and `InstagramAutoSync`
    - **Where:** `app/Services/Platforms/GoogleBusinessAutoSync.php:627-663, 683-698` and `app/Services/Platforms/InstagramAutoSync.php:219-239, 262-290, 293-308`
    - **Affects:** Maintainers — the connect-modal finding contract and the `IntegrationConnection` write shape live in two places; a schema change (new finding field, new write column) must be made twice or silently diverges.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `seededFinding`, `conflictFinding`, `write`, and the "remove-then-write" core of `applyFinding` into a shared trait (e.g. `Concerns/BuildsAutoSyncFindings`) used by both classes — a trait needs no constructor changes, since both classes already import `IntegrationConnection` directly.
        - Keep `GoogleBusinessAutoSync::applyFinding`'s Instagram-dispatch branch as a thin override that calls the shared remove-step, then either dispatches Instagram or falls through to the shared write.
    - **Technical:** Both classes build an identical finding array (`platform`/`resourceId`/`category`/`label`/`foundUrl`/`outcome`/`apply`) and write identically to `IntegrationConnection::updateOrCreate` with the same 6 fields. This isn't hypothetical drift risk — the adjacent `socialUsername()` method in both files carries a comment documenting that exact failure mode already happening: InstagramAutoSync's docblock says "this was a byte-for-byte copy of that same standalone regex, sharing its blind spot for reserved path segments," fixed by delegating both classes to the shared `FacebookNormalizer` (commits `5b1f4488`, `5d00fac6`, G4-4). The four methods flagged here are the same shape of duplication that bug already came from, just not yet extracted.
    - **Plain English:** Two departments share the same paperwork template but keep separate copies of it. This exact team already got burned once — a shared form (finding someone's Facebook username) drifted between the two copies and produced a real bug, which they fixed by making both departments read from one master copy. These four remaining forms haven't been consolidated yet, so the same kind of drift could happen again.
    - **Evidence:**
        ```php
        // GoogleBusinessAutoSync.php — identical to InstagramAutoSync.php:
        private function seededFinding(string $platform, string $resourceId, string $category, string $label, ?string $foundUrl): array
        {
            return [
                'platform' => $platform,
                'resourceId' => $resourceId,
                'category' => $category,
                'label' => $label,
                'foundUrl' => $foundUrl,
                'outcome' => 'seeded',
                'apply' => null,
            ];
        }

        private function conflictFinding(string $platform, string $resourceId, string $category, string $label, ?string $foundUrl, array $apply): array
        {
            return [
                'platform' => $platform,
                'resourceId' => $resourceId,
                'category' => $category,
                'label' => $label,
                'foundUrl' => $foundUrl,
                'outcome' => 'conflict',
                'apply' => $apply,
            ];
        }
        ```

## P3 — Nice to have

- [ ] **SLOP-2** · P3 — Decorative ASCII-art section-separator comments add noise without structural value
    - **Where:** `app/Services/Platforms/GoogleBusinessAutoSync.php:139, 236, 300, 349, 521, 624, 665` (7 separators), `app/Services/Platforms/ShopifyScraper.php:194`, `app/Services/Platforms/WooCommerceScraper.php:280`
    - **Affects:** Readability — the banners are visual noise; the method names they sit above already convey the grouping.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Delete every `// ── … ──` line in the three files.
    - **Technical:** `CLAUDE.md`'s Commenting section explicitly says to "avoid... decorative banners." `GoogleBusinessAutoSync` uses seven (`reservation`/`booking`/`workplace`/`ordering`/`socials`/`findings`/`helpers`); `ShopifyScraper` and `WooCommerceScraper` each frame a single explanatory comment with a `── internals ──` banner, which is a banner around one line rather than a section of code.
    - **Plain English:** Drawing decorative horizontal lines between paragraphs in a document doesn't help anyone find anything — it just makes the document longer to scroll through. The method names already say what each group of code does.
    - **Evidence:**
        ```php
        // GoogleBusinessAutoSync.php — repeated 7×:
        // ── reservation ──────────────────────────────────────────────
        // ── booking ──────────────────────────────────────────────────
        // ── workplace ─────────────────────────────────────────────────

        // ShopifyScraper.php / WooCommerceScraper.php:
        // ── internals ────────────────────────────────────────────────
        ```

- [ ] **SLOP-3** · P3 — `MAX_IMAGES = 25` duplicated across four scraper classes instead of living once on the shared base
    - **Where:** `app/Services/Platforms/BigCartelScraper.php:16`, `GenericShopScraper.php:25`, `ShopifyScraper.php:71`, `WooCommerceScraper.php:22`
    - **Affects:** Maintainers — changing the gallery cap means touching four files; each carries its own copy of a "mirrors ShopifyScraper::MAX_IMAGES" comment confirming the value is meant to be one cross-provider constant.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `protected const MAX_IMAGES = 25;` to `PlatformScraper` (which already carries the shared `USER_AGENT` constant, so this is a same-pattern addition — no constructor/DI impact since constants aren't instance state).
        - Delete the four private copies; the subclasses already inherit from `PlatformScraper` so `self::MAX_IMAGES` resolves unchanged.
    - **Technical:** All four classes extend `PlatformScraper`. The constant is the same value with the same purpose (capping a stored product row's image gallery) in every file, and each copy's comment already says it "mirrors" the others — the duplication is acknowledged, not accidental.
    - **Plain English:** Four rooms each have their own thermostat set to exactly 72°, with a sticky note on each saying "keep at 72° like the other rooms." A single house-wide thermostat avoids someone having to visit every room to change the temperature.
    - **Evidence:**
        ```php
        // BigCartelScraper.php / GenericShopScraper.php / ShopifyScraper.php / WooCommerceScraper.php:
        private const MAX_IMAGES = 25;
        ```

- [ ] **SLOP-4** · P3 — `json()` fetch helper duplicated with near-identical bodies in three scrapers
    - **Where:** `app/Services/Platforms/ShopifyScraper.php:198-207`, `WooCommerceScraper.php:285-294` (byte-identical to each other); `BigCartelScraper.php:99-107` (adds an `Accept` header, skips the `is_array` check, returns `mixed`)
    - **Affects:** Maintainers — the same fetch-decode-validate sequence lives in three places.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a stateless helper to `PlatformScraper` that takes the fetcher explicitly as a parameter rather than storing it: `protected function decodeJson(?array $fetchResult): ?array { ... }`, called as `$this->decodeJson($this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]))`. This keeps the base class's documented "pure functions... fetching stays in each subclass, which keeps their constructor signatures (and DI) untouched" contract intact (`PlatformScraper.php:8-13`) — do **not** move the `SafeUrlFetcher` call itself into the base class, since that would force every subclass constructor to route through a base constructor it currently doesn't have.
        - Standardize BigCartelScraper's variant on the same `?array` validation the other two already do (it currently returns unvalidated `mixed`).
    - **Technical:** `ShopifyScraper::json()` and `WooCommerceScraper::json()` are identical; `BigCartelScraper::json()` differs only in an extra `Accept` header and omitting the `is_array` guard (its caller compensates with its own `is_array` check, so this isn't a live bug — just an inconsistency). `PlatformScraper`'s own class docblock explains why fetching wasn't already centralized: keeping DI untouched. A helper that accepts the fetch result (rather than owning the fetcher) satisfies that constraint while still removing the duplicated decode/validate logic.
    - **Plain English:** Three rooms have copy-pasted the same recipe card, one with slightly different handwriting. The recipe should live in a shared binder — but the binder can't own the room's own ingredients (that's a deliberate design choice noted in this file), so only the shared "how to read this recipe" step moves, not the fetching itself.
    - **Evidence:**
        ```php
        // ShopifyScraper.php — identical to WooCommerceScraper.php:
        private function json(string $url): ?array
        {
            $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
            if ($res === null || $res['status'] !== 200) {
                return null;
            }
            $data = json_decode($res['body'], true);

            return is_array($data) ? $data : null;
        }

        // BigCartelScraper.php — differs only in extra header + no is_array validation:
        private function json(string $url): mixed
        {
            $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json']);
            if ($res === null || $res['status'] !== 200) {
                return null;
            }

            return json_decode($res['body'], true);
        }
        ```

- [ ] **SLOP-5** · P3 — Dead private methods `hasStoreKey` and `count` left behind after eager-load refactor
    - **Where:** `app/Services/Platforms/GoogleBusinessAutoSync.php:492-499` (`hasStoreKey`), `:677-680` (`count`)
    - **Affects:** Maintainers — readers may assume these are still on a live call path, since a comment a few lines above references them by name as if they still ran per-iteration.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Delete `hasStoreKey()` (lines 492-499) and its docblock.
        - Delete `count()` (lines 677-680).
    - **Technical:** `seedOrdering()` was refactored to eager-load existing ordering connections into `$existingStoreKeys`/`$existingCount` in-memory maps, replacing per-iteration queries. Verified zero callers repo-wide: `grep -rn "hasStoreKey"` across the whole repo returns only the method's own definition and a comment mentioning it by name (`app/Services/Platforms/GoogleBusinessAutoSync.php:384`, plus two references inside archived audit markdown files under `audits/`, not live code); `grep -rn "->count(\$userId"` and a scan of the class for `$this->count(` return no matches anywhere in `app/` or `tests/`.
    - **Plain English:** A kitchen was renovated to use a central pantry instead of running to the store for every ingredient. The old shopping list pad and the spare car keys for those store runs are still sitting on the counter — they don't do anything anymore and just confuse the next cook into thinking they're still needed.
    - **Evidence:**
        ```php
        // Defined but never called anywhere in the class or repo:
        private function hasStoreKey(string $userId, string $storeKey): bool
        {
            return IntegrationConnection::query()
                ->where('user_id', $userId)
                ->where('platform', Platform::OnlineOrdering->value)
                ->get()
                ->contains(fn (IntegrationConnection $row) => $this->storeKey(CardPayload::fromArray($row->payload)->url()) === $storeKey);
        }

        private function count(string $userId, string $platform): int
        {
            return IntegrationConnection::query()->where('user_id', $userId)->where('platform', $platform)->count();
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Platform scraper hygiene:** #SLOP-2, #SLOP-3, #SLOP-4
    - **Why grouped:** all three touch `app/Services/Platforms/PlatformScraper.php` plus the same set of subclasses (`ShopifyScraper`, `WooCommerceScraper`, `BigCartelScraper`, `GenericShopScraper`) — one small mechanical-cleanup PR.
    - **Model:** Plan Opus · Implement Sonnet · Review Sonnet (all S-effort, low complexity).

- **Bundle 2 — GoogleBusinessAutoSync consolidation:** #SLOP-1, #SLOP-5
    - **Why grouped:** both touch `GoogleBusinessAutoSync.php`; the trait extraction in #SLOP-1 is a natural place to also drop the dead `hasStoreKey`/`count` helpers from #SLOP-5 in the same pass.
    - **Model:** Plan Opus · Implement Sonnet · Review Sonnet.

## Standalone — do NOT bundle

None.
