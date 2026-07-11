# Tail-cleanup — plan + report

Branch `tobias/tail-cleanup` off `development`. ONE PR, no merge. Code-only (no migrations).
Real vendor via `composer install` (done, exit 0). Verify each fix against current code (codebase moved overnight — Josh merged).

## Fix 1 — WS-F4 location-map suppression (`DisplaySettingsFilter.php`)
The `location` toggle nulls `address/lat/lng/addressParts` but NOT `placeId`/`streetView`, so the
dashboard map (keys off `placeId`) + street-view still render when "Location & map" is OFF.
- **Payload keys (verified):** `GoogleBusinessPayload` has `placeId`; `GoogleBusinessService:173` sets
  top-level `streetView = {panoId,lat,lng}` (panoId is nested — strip `streetView`). Both are emitted by
  `GoogleBusinessConnectionResource` ENRICHMENT_KEYS. No top-level `panoId`.
- **Change:** add `placeId`, `streetView` to `SUPPRESSIONS['google-business']['location']`.
- **Interaction guard:** `disabledKeys()` feeds TWO persist paths. `GoogleBusinessFetch:55` merges
  `[...$payload, ...gated $details]` → stored `placeId` survives (safe). `connect()` writes storage BEFORE
  `suppress()` echo → storage keeps `placeId` (safe). Serve paths (`suppress()`) drop it → map gone. GOOD.
  Only Fix 4's new persist-gate must preserve `placeId` (see Fix 4).

## Fix 2 — WS-B1 `fetchMany()` honest-UA 403 fallback (`SafeUrlFetcher.php`)
`fetch()` retries a 403 once with `FALLBACK_USER_AGENT`; `fetchMany()` (refresh fleets) does not.
- **Change:** extract the redirect-following pool loop into `fetchManyFollowingRedirects()`; `fetchMany()`
  runs it once with the browser UA, collects URLs whose terminal status is 403 (only if browser UA), reruns
  just those once with `FALLBACK_USER_AGENT`, replaces on `<400`. Mirrors `fetch()`'s one-shot semantics.

## Fix 3 — `fetchBrand()` transport-failure guard (`WooCommerceScraper.php`, `ShopifyScraper.php`)
`$home = $this->fetcher->tryFetch(...)` returns `?array`; `$home['status'] === 200` on `null` → E_WARNING
→ Laravel `HandleExceptions` throws ErrorException → 500. Both Woo:72 + Shopify:44 have it; Squarespace safe.
- **Change:** guard `$home !== null && $home['status'] === 200`.

## Fix 4 — WS-B2 `GoogleBusinessEnrichJob` refresh-gating (`GoogleBusinessEnrichJob.php`)
Enrich re-persists `payloadOf(connection)` without the display gate `GoogleBusinessFetch` applies.
- **Change:** private `gateDisabled($payload, $connection)` = `Arr::except($payload,
  array_diff(disabledKeys('google-business', $connection->display_settings), ['placeId']))`. `placeId`
  excluded because it is the refresh identity key (`GoogleBusinessFetch` reads `payload.placeId`) and there
  is no base-payload spread to restore it here. Apply in `handle()` persist + `mark()`.

## Fix 5 — OV-I staff-settings validation (`StaffUpdateSiteRequest.php`)
Staff request accepts `settings` as a generic array; missing the ordering-key rules `UpdateSiteRequest`
enforces (esp. `manual_actions` custom `{label,url}` URL must be http(s)).
- **Root-cause change (matches `DesignKitValidationRules` precedent):** extract the ordering rules +
  `manualActionEntryRule`/`distinctActionRefsRule` closures into new
  `Concerns\SiteOrderingValidationRules::orderingRules()`. `UpdateSiteRequest` + `StaffUpdateSiteRequest`
  both `use` it and spread `...$this->orderingRules()` — can't drift again. Drop the now-unused
  `SitepageId` import from `UpdateSiteRequest`.

## Tests (Pest, one per fix)
1. `DisplaySettingsTest` — location OFF drops `placeId`+`streetView`(+address/lat/lng) from dashboard selection.
2. `SafeUrlFetcherTest` — `fetchMany` retries a 403 with honest UA + returns pass; keeps 403 when retry blocked.
3. NEW `Feature/Platforms/ScraperTransportFailureTest` (Feature-bound so the warning throws) — Woo+Shopify
   `fetchBrand` return clean on null homepage.
4. `GoogleBusinessApifyTest` — enrich with `location:false` strips address/lat/lng but KEEPS `placeId`.
5. `StaffUpdateSiteValidationTest` — staff rejects bad ordering payload + `javascript:` custom-action URL.

## Verify
`vendor/bin/pest` green (real vendor) + `php artisan pint --dirty`. Then commit, push, `gh pr create --base development`. No merge.

---
## RESULT

All 5 fixes landed (none were already-done). Code + one Pest test per fix.

- **Fix 1** `DisplaySettingsFilter` — `location` set now strips `placeId` + `streetView` (panoId is nested
  in streetView; no top-level panoId). Verified persist paths keep `placeId` (refresh identity).
- **Fix 2** `SafeUrlFetcher::fetchMany` — extracted `fetchManyFollowingRedirects()`; added the one-shot
  honest-UA 403 fallback matching `fetch()`.
- **Fix 3** `WooCommerceScraper` + `ShopifyScraper` `fetchBrand` null-guarded (`$home !== null && …`).
  Squarespace was already safe. Test is Feature-bound so the pre-fix warning-to-500 actually throws.
- **Fix 4** `GoogleBusinessEnrichJob` — `gateDisabled()` applies `disabledKeys` (minus `placeId`) to
  `handle()` + `mark()` persist paths.
- **Fix 5** extracted `Concerns\SiteOrderingValidationRules`; both `UpdateSiteRequest` (refactored, no
  behaviour change — drift/sync tests green) and `StaffUpdateSiteRequest` (the fix) now share it.

**Verification:** `composer test` → 3651 passed, 0 failed (127 pre-existing skips). `pint --dirty` clean.

**Concerns:** none blocking. Fix 4 gating `mark()` (not just the success path) is a deliberate superset —
consistent with WS-B2's "storage never holds data we won't serve"; no-op when no toggles are set (all
existing tests unaffected). The `IndividualProfileResource` `skeletonId` dual-key note in CLAUDE.md is
unrelated to this batch.
</content>
</invoke>
