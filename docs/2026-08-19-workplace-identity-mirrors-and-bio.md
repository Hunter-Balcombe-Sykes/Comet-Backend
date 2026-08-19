# Workplace-identity mirrors + About Me paragraph (2026-08-19)

Cross-repo: `Comet-Backend` (bulk) + `partna-monorepo/apps/pages` (plumb only)
+ one label change in `partna-monorepo/apps/dashboard`.

## Goal

One rule, applied to every workplace-vs-person field:

- **business** — the workplace IS the account. Each workplace field mirrors
  onto the matching user column; there is ONE of each thing.
- **partna** — the workplace is *where they work*. Workplace fields and user
  fields are independent; there are TWO of each thing.

Today only `name` and the contact pair behave this way, the contact pair
mirrors for BOTH account types (wrong for partna), and `bio` does not exist.

## Decisions (owner, 2026-08-19 — all settled, do not re-litigate)

1. No new workplace columns. `workplaces.phone` / `contact_email` already ARE
   the workplace's public contact.
2. Gate every mirror on the EXISTING `workplace_brand_is_site_identity`
   capability (`= $isBusiness`), not on `account_type` and not on three new
   flags that would all equal `$isBusiness`.
3. New `core.users.bio`, text, `max:1000`, BOTH account types.
4. `workplaces.description` mirrors → `users.bio` for business (mirror, not
   redirect: writes still land on the workplace row, sync paths untouched).
5. Address mirrors → `users.location_*` for business.
6. Country: mirror `workplaces.country` → `users.location_country`. Leave
   `country_code` alone — it also drives phone formatting.
7. Settings Address stays EDITABLE for business; last writer wins.
8. `display_name`: keep Google's seed (`GoogleBusinessController::
   maybeAdoptGoogleName`), DROP the manual mirror in `UserWorkplaceController`.
   Display name is user-owned after the initial seed.
9. Business `display_name` cap 15 → 255, same as partna.
10. Astro: PLUMB ONLY. Types + parsers, no mounts, no render.
11. Backfill: null `users.public_contact_*` for partna; copy workplace
    description + address → user columns for business.
12. `users.sector` stops being set from the workplace's Google listing for
    partna. Same capability gate. Business keeps the Google fold; for partna
    the ONLY automated industry source becomes their own Instagram business
    category, with a manual pick still outranking it.

### Accepted consequences

- Existing partna sites lose their `ContactAlternatives` line AND their
  auto-generated Privacy/Terms contact until each person fills in their own
  details. Owner chose this over keeping mirrored values.

## Phase 1 — schema

1. `supabase/migrations/<ts>_users_bio.sql` — `ALTER TABLE core.users ADD
   COLUMN bio text` + `COMMENT ON COLUMN`.
2. `supabase/migrations/<ts>_identity_mirror_backfill.sql`:
   - partna: `UPDATE core.users SET public_contact_number = NULL,
     public_contact_email = NULL WHERE account_type = 'partna'`
   - business: copy `site.workplaces.description` → `users.bio`, and
     `address_line1/city/state/postcode/country` → `location_*`.
3. `tests/Pest.php` — SQLite DDL mirror of `core.users` (~line 523) gains
   `bio`. Tests break loudly if this is missed.

## Phase 2 — the mirror itself

`app/Observers/Core/WorkplaceObserver.php`

4. Rename `mirrorContactFields()` → `mirrorIdentityFields()`; widen it to
   phone, contact_email, description, address_line1, city, state, postcode,
   country. Gate the whole method on `workplace_brand_is_site_identity`.
5. **FIX WHILE HERE, NOT OPTIONAL** — the current mirror fires on
   `wasRecentlyCreated` and then assigns unconditionally, including nulls. A
   workplace row created for an unrelated reason (`setPreviousWebsite` does
   `Workplace::updateOrCreate`, `ScanPreviousWebsiteContentJob` does
   `firstOrNew`) therefore WIPES the user's contact fields. Widening the
   mirror from 2 columns to 8 turns that from a bug into a data-loss event.
   New rule: on create, mirror only NON-NULL fields; on update, mirror exactly
   the fields `wasChanged()` reports (nulls included, so clearing still
   clears).

`app/Services/Platforms/IdentitySync.php`

6. `mirrorPublicContactNumber()` (~line 353) — same capability gate, else
   Google sync re-couples the pair for partna.

`app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php`

7. Delete the `display_name = $attributes['name']` mirror (~line 158-163) per
   decision 8. `GoogleBusinessController::maybeAdoptGoogleName` stays.

`app/Services/Platforms/IdentitySync.php` (second change)

7b. `applySector()` (~line 322) — gate the GOOGLE branch on
    `workplace_brand_is_site_identity`. Today the workplace's Google category
    folds onto `users.sector` for BOTH types with no gate (`$overwrite` only
    decides how hard it writes), so a partna's industry is set by where they
    work. Gate the writer only — do NOT touch `SectorProvenance`'s ladder or
    ranks. Business unaffected.

    Result for partna: `InstagramIdentitySync` becomes the sole automated
    source (Instagram is the person's own account, correctly scoped already),
    manual still outranks it. NOTE the existing limit, left as-is: Instagram
    is rank 1 with `SELF_REFRESH = false`, so it fills a BLANK sector and can
    never correct its own earlier value. That guard exists because the
    PARTNA_INSTAGRAM_ACTOR rollback flips between two actors returning
    different keys — do not "fix" it to make the auto-sync feel livelier
    without re-reading SectorProvenance's docblock first.

    Food guard is already business-only (`isFoodDemotion($isBusiness, …)`), so
    this cannot change partna capability behaviour — sector gates nothing for
    partna. Only visible partna effect is design presets, unstyled until an
    industry exists.

`app/Services/Accounts/AccountCapabilities.php` — no new flag; confirm
`workplace_brand_is_site_identity` reads correctly at every new call site.

## Phase 3 — user-level bio

8. `app/Models/Core/User/User.php` — `@property` + `$fillable`.
9. `app/Http/Requests/Api/User/UpdateUserRequest.php` — `bio` rule
   (`nullable`, `string`, `max:1000`); and `displayNameMax()` returns
   `max:255` unconditionally (decision 9).
10. `app/Http/Requests/Api/Staff/UserSite/StaffUpdateUserRequest.php` — same.
11. `app/Http/Resources/UserDashboardResource.php` — expose `bio` so the
    dashboard work has something to bind to.
12. `app/Services/User/DataExport/DataExportPayloadBuilder.php` — DSAR
    coverage for the new column.

## Phase 4 — public wire

13. `app/Services/PublicSite/SitepageDataResolverService.php`
    - new `getBio()` alongside `getPublicContact()` (~line 766)
    - `getWorkplace()` (~line 805) gains `contact_email`
14. `app/Services/PublicSite/IndividualProfilePayloadBuilder.php`
    - `buildBio()`; `buildWorkplace()` (~line 203) remaps `contactEmail`
    - policies call (~line 156) uses the PERSON's email for partna
15. `app/Http/Resources/PublicSite/IndividualProfileResource.php` — `bio` key
    (and delete the stale "bio was removed" comment at ~line 88).
16. **VERIFY FIRST:** two public read paths exist — this PHP resource AND the
    SQL `public_site_payload` function (`20260817000000_*.sql:211` builds its
    own `professional` object). Confirm which one actually feeds Astro before
    editing; update whichever is live.

## Phase 5 — Astro (plumb only)

`partna-monorepo/apps/pages`

17. `src/content/wire.ts` — `ProfileWire.bio: string | null`.
18. `src/content/types.ts` — bio on the profile surface;
    `WorkplaceSurface.contactEmail: string | null`.
19. `src/content/resolve-site-content.ts` — parse both (~line 305 / ~line 368).

NO mounts. No `TextCard`, no Email action on `WorkplaceCard`. Owner owns the
render.

## Phase 6 — dashboard (one line)

20. `components/settings/account-settings.tsx:127` — `displayLabel` becomes
    `"Display name"` unconditionally. Check `displayNameLimit` (~line 90) too,
    since it mirrors the backend cap changed in step 9.

## Verification

- `php artisan test` (SQLite DDL mirror will catch a missed column)
- `npm run typecheck` in `apps/pages` and `apps/dashboard`
- Tinker: a business workplace save moves description + address + contact onto
  the user row; a partna workplace save moves NOTHING
- Tinker: a Google Business connect sets `users.sector` for a business and
  leaves a partna's untouched; an Instagram connect still sets a blank
  partna sector
- Tinker: `Workplace::updateOrCreate` with only `previous_website` on a fresh
  row leaves the user's fields untouched (the step-5 fix)

## Out of scope

- Rendering anything on the sitepage (owner's).
- Any dashboard work beyond the one label + limit.
- The `display_name` / `workplaces.name` divergence for business — owner
  ruled it intentional.
- `location_country` being unread by the Settings card (it reads
  `country_code`). Mirror writes it; surfacing it is dashboard work.
