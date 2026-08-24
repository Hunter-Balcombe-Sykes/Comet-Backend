# FULL CODEBASE BLOAT, INHERITANCE AND MORE — AUDIT PLAN

All bloat/dead-code/fixes/inheritance planning docs produced across this session, gathered into one file so it's all in one place. Nothing has been deleted, edited, or implemented anywhere — every section below is reference/planning only, exactly as originally written. Content is unchanged from the 5 source files; this wrapper just merges them.

**2026-07-25 update:** every item below now has a `Paths:` line added directly underneath it — Haiku agents re-verified each file/class/hook path against the live repos today and noted whether "ready to delete" items are already sitting archived/excluded from the build, or still live in what actually ships. Nothing else was changed; these are pure additions for tomorrow's review. Two real discrepancies turned up and are called out inline where they occur: (1) Frontend inheritance item 2 names a file, `use-individual-letter-case.ts`, that doesn't actually exist on its own — `useIndividualLetterCase` is one of several hooks living inside `use-individual-typography.ts`; (2) the Backend Analytics Request files live at `app/Http/Requests/Api/PublicSite/Analytics/...`, not the shorter `app/Http/Requests/Analytics/...` implied in a couple of spots.

## Contents
1. Partna-Frontend — Bloat & fixes (2026-07-24)
2. Partna-Frontend — Inheritance & consolidation opportunities (2026-07-25)
3. Comet-Backend — Bloat & fixes (2026-07-25)
4. Comet-Backend — Inheritance & consolidation opportunities (2026-07-25)
5. partna-monorepo — Bloat & fixes (2026-07-25)

---

> **Source:** `Partna-Frontend/docs/audit/2026-07-24-bloat-and-fixes.md`

# Frontend bloat & fixes — started 2026-07-24, deep-audited 2026-07-24

Running list of dead code to delete and bugs to fix. Nothing in this file has been deleted yet — this is the reference doc; implementation happens separately. A deep second pass on 2026-07-24 went back through every "maybe"/"hold"/"needs checking" from the first pass and resolved each one with direct evidence (git history, live production DB queries, backend code, exposure checks) instead of leaving it hedged. Where that changed a verdict, both the original hedge and the resolution are kept so the reasoning stays visible.

---

## READY TO DELETE — every item below has a definitive verdict, no remaining "maybe"

### 1. Dead theme-N reserved routes + hideChrome branches
*Verified 2026-07-24*
- `lib/routes.ts` — remove from `RESERVED_PATHS` (starts line 20): line 34 `"theme-1"`, line 35 `"theme-2"`, line 36 `"theme-3"`, line 57 `"smart-tools"`, line 58 `"unique-themes"`, line 60 `"payment-and-billing"`.
- `app/(app)/root-layout-client.tsx` — remove lines 85-87 (the three `pathname?.startsWith('/theme-1'/'/theme-2'/'/theme-3')` conditions) from the `hideChrome` expression (lines 82-96). Leave the rest of `hideChrome` untouched.
- Why safe: leftover from the V4 deletion of `lib/public-theme/` (May 22). No `app/theme-*`, `app/smart-tools`, or `app/payment-and-billing` route exists anywhere in the repo.
- Effort: ~10 min.
- **Paths (verified 2026-07-25):** `lib/routes.ts` [LIVE], `app/(app)/root-layout-client.tsx` [LIVE] — both confirmed, part of the active build.

### 2. Transitional Fresha read-only table — NOW CONFIRMED SAFE (was "hold, backend-gated")
*Verified 2026-07-24 · resolved 2026-07-24 via live production DB query*
- `app/(app)/account/(dashboard)/integrations/booking-fresha-table.tsx` (257 lines) + `booking-fresha-table.test.tsx`.
- `app/(app)/account/(dashboard)/integrations/booking-dashboard-section.tsx` — the `FreshaServicesTable` import (line 34) and the `unresynced` gate + render branch inside `ServicesView` (lines ~154-174).
- **Original hedge:** "needs confirmation the Fresha projector has back-filled `site.services` for every pre-fold connection."
- **What resolved it:** Read `Comet-Backend`'s `FreshaServiceProjector.php` — the backfill isn't a one-time batch job, it happens per-account "until their next refresh/reconnect/selection save," so there's no global "is it done" state to wait for. Instead queried the live `Partna Development` Supabase project (`glncumufgaqcmqhzwrxm` — per `Comet-Backend/CLAUDE.md`, this IS the live DB right now, prod is stopped) directly:
  ```sql
  SELECT count(*) FILTER (WHERE platform='fresha') AS total,
         count(*) FILTER (WHERE platform='fresha' AND payload->'raw'->'services' IS NULL) AS legacy_no_raw,
         count(*) FILTER (WHERE platform='fresha' AND jsonb_array_length(coalesce(payload->'selection'->'services','[]'::jsonb))>0 AND payload->'raw'->'services' IS NULL) AS legacy_with_actual_services
  FROM site.platform_connections WHERE deleted_at IS NULL;
  -- result: total=4, legacy_no_raw=1, legacy_with_actual_services=0
  ```
  4 total Fresha connections exist; 1 hasn't gone through the projector yet, but that one has **zero services** in its selection blob. The frontend's gate is `provider === "fresha" && !hasProjectedRows && legacyHasServices` — since `legacyHasServices` requires a non-empty services list, this branch cannot currently render for anyone.
- **Verdict: safe to delete now.** New Fresha connections always get `raw` populated at connect time, so nothing recreates this condition going forward either.
- Effort: ~15 min.
- **Paths (verified 2026-07-25):** `app/(app)/account/(dashboard)/integrations/booking-fresha-table.tsx` [LIVE], `booking-fresha-table.test.tsx` [LIVE], `booking-dashboard-section.tsx` [LIVE] — all confirmed, part of the active build.

### 3. AppleConnection dead `view="all"` branch
*Verified 2026-07-24*
- `app/(app)/account/(dashboard)/integrations/media-sections.tsx` — `AppleConnection` component (line 224, not exported) has `view === "all"` branches at lines 478, 541, 618.
- Confirmed zero callers pass `view="all"`: all 9 call sites (lines 821-861) use only `"overview"`, `"accounts"`, or `"releases"`.
- Effort: ~20 min — needs a proper read of the 3 branch sites to know exactly what collapses, not a blind line-delete.
- **Paths (verified 2026-07-25):** `app/(app)/account/(dashboard)/integrations/media-sections.tsx` [LIVE] — confirmed, part of the active build.

### 4. Dead link-management cluster in `lib/sitepage/api.ts` — EXPANDED from 2 exports to 15
*Verified 2026-07-24 · expanded + fully re-verified 2026-07-24*
- **Corrected path** — the original audit said `lib/social/api.ts`; the real file is `lib/sitepage/api.ts`.
- **Original scope (2 exports):** `createSocialLink` (line 237), `updateSocialLink` (line 239), plus the "Back-compat aliases... Delete once all call sites have been updated" comment above them (lines 234-235).
- **Expanded scope, all independently grep-verified with zero external callers each** (including checking for coincidental same-named locals — two of these tripped that trap and were confirmed false alarms, see below):
  - `deleteSitepageImage` (68), `reorderSitepageImages` (76), `saveDisplayName` (90) — false-alarm check: a local `const saveDisplayName` in `account-settings-section.tsx` and a comment in `use-account-mutation.ts` are unrelated, not imports.
  - `fetchLinkBlocks` (184) — false-alarm check: `action-customisation-handlers.ts` defines and calls its **own separate** `fetchLinkBlocks`; confirmed unrelated to this one.
  - `createPlatformLink` (200), `updatePlatformLink` (218), `deleteLinkBlock` (242), `setLinkActive` (249), `createCustomLink` (262), `updateCustomLink` (276) — this directly overturns the original audit's claim that callers "migrated to" these two; they have zero callers either.
  - `updateGalleryImageMeta` (365), `pickVariantUrl` (378), `pickVideoSrc` (391).
- **The file is NOT wholesale dead** — `fetchSitepageImages`, `uploadSitepageImage`, `fetchSections`, `updateSectionSettings`, `updateSectionPublication`, `fetchDocument`, `uploadDocument`, `updateDocumentMeta`, `toggleDocumentEnabled`, `deleteDocument` (+ 2 types) all have real callers and stay.
- **Verdict: delete all 15 dead exports listed above** (roughly 58% of this file's public surface) — the entire "link block" management API is dead, superseded by something else (likely the newer `lib/social/platforms.ts` — see the DEAD findings below, though that file's own unused exports are a *different*, separately-dead set, not proof of what replaced this one; if picking this up, confirm what the live `/account/custom-links` page actually calls before assuming).
- **Independently re-confirmed a second time** by the full knip sweep (item 14 below) — a separate verification pass over this exact file reached the identical 15-name list with no daylight between the two, including a matching correction: `createSocialLink`/`updateSocialLink` are wrappers around `createPlatformLink`/`updatePlatformLink`, and both layers are dead together (only `createSocialLink`/`updateSocialLink` called `createPlatformLink`/`updatePlatformLink` at all — once those two go, nothing else in the repo calls the platform-agnostic versions either).
- Effort: ~30 min.
- **Paths (verified 2026-07-25):** `lib/sitepage/api.ts` [LIVE] — confirmed, part of the active build.

### 5. Unused imports (two files)
*Verified 2026-07-24*
- `app/(app)/account/(dashboard)/integrations/products-section.tsx` — line 18 (`Card, CardDescription, CardFooter, CardHeader, CardTitle`) and line 19 (`CardGrid`). Zero JSX usage.
- `app/(app)/account/(dashboard)/integrations/platform-settings-body.tsx` — line 31 (`import type { CategoryStatus } from "./types"`).
- Effort: ~5 min.
- **Paths (verified 2026-07-25):** `app/(app)/account/(dashboard)/integrations/products-section.tsx` [LIVE], `platform-settings-body.tsx` [LIVE] — both confirmed, part of the active build.

### 6. Misplaced test file
*Verified 2026-07-24*
- `lib/settings/settings-utils.test.ts` (58 lines) tests `readString`/`readNumber` from `@/lib/coerce`, which re-exports them from `lib/account/readers.ts`. `lib/settings/settings-utils.ts` doesn't exist as a file.
- Action: move to `lib/account/readers.test.ts`.
- Effort: ~5 min.
- **Paths (verified 2026-07-25):** `lib/settings/settings-utils.test.ts` [LIVE, confirmed exists]; `lib/settings/settings-utils.ts` — CONFIRMED NOT FOUND, matching this item's own claim that it doesn't exist.

### 7. Three integrations sub-pages provably unreachable
*Verified 2026-07-24*
- `app/(app)/account/(dashboard)/integrations/[platform]/maps/page.tsx`, `.../photos/page.tsx`, `.../reviews/page.tsx` — literal `return null` stubs. Zero platforms in `platform-registry.ts`'s `PLATFORM_ENTRIES` list `"maps"`/`"photos"`/`"reviews"` in `subPages` anymore, so the layout guard redirects away before any of these render, for every platform.
- Effort: ~5 min.
- **Paths (verified 2026-07-25):** all 3 (`.../maps/page.tsx`, `.../photos/page.tsx`, `.../reviews/page.tsx`) confirmed at their full stated paths under `app/(app)/account/(dashboard)/integrations/[platform]/` [LIVE].

### 8. Five legacy-bookmark redirect stubs — NOW CONFIRMED SAFE (was "hold 2-4 weeks")
*Verified 2026-07-24 · resolved 2026-07-24 via exposure check*
- `.../integrations/[platform]/services/page.tsx` → `/account/booking`
- `.../integrations/[platform]/products/[id]/page.tsx` → `/account/products`
- `.../integrations/[platform]/brands/[id]/page.tsx` → `/account/products/stores`
- `.../integrations/[platform]/menu-items/[id]/page.tsx` → `/account/menu`
- `.../integrations/[platform]/link/[id]/page.tsx` → `/account/integrations/online-ordering`
- **Original hedge:** "only days old, deleting now trades a redirect for a 404 on bookmarks — wait 2-4 weeks."
- **What resolved it:** Checked whether these URLs were ever exposed anywhere OTHER than someone manually copying the address bar mid-session: grepped all of `Comet-Backend`'s email/notification templates for links to any of these paths — zero hits, zero hardcoded `/account/integrations/...` deep links anywhere in the backend. Then checked the actual blast radius: `site.sites` has **32 total rows** — the whole platform is pre-launch ("Launching publicly soon" per the marketing footer; waitlist-only, no public sign-up yet) with a tiny, known user base. These are also obscure nested settings sub-paths, not memorable top-level URLs.
- **Verdict: safe to delete now.** Not zero-risk in the absolute sense (a user could theoretically have hand-bookmarked one), but the actual exposure is as close to zero as this kind of thing gets — no automated system ever linked to them, and the entire eligible user pool is 32 accounts pre-launch.
- Effort: ~10 min.
- **Paths (verified 2026-07-25):** all 5 stubs confirmed at their full stated paths under `app/(app)/account/(dashboard)/integrations/[platform]/` [LIVE].

### 9. Entire "About" editing feature orphaned — NOW FULLY CONFIRMED (was "check intent first")
*Verified 2026-07-24 · resolved 2026-07-24 via git archaeology*
- `lib/about/api.ts` — zero importers.
- `lib/about/use-about.ts` (`useBio`, `useAbout`) — zero importers.
- **`lib/about/types.ts` still stays as a file** (used by `lib/account-types.ts` + `lib/account/map-snapshot-to-account.ts` for reading backend data) — **but 9 of its exports are also confirmed dead** and can be removed from it: `ABOUT_MAX_CREDENTIALS`, `ABOUT_MAX_EXPERIENCE`, `toBackendAbout`, `EMPTY_ABOUT`, `newCredentialDraft`, `newExperienceDraft`, `countWords`, `BIO_MIN_WORDS`, `BIO_MAX_CHARS` — all of them only ever consumed by the two now-dead files above, confirmed via independent re-check. Keep `Credential`/`Experience`/`AboutPayload` types + `normaliseAboutPayload` — those are live.
- **Original hedge:** "worth checking whether that's intentional or an accidental disconnect — ask whoever removed the Bio/About UI."
- **What resolved it:** `git log` traced it precisely. Commit `aeb3e60c` (May 25, "dissolve features/about") moved the API/hooks to `lib/about/` and co-located the editing components (`credentials-list`, `experience-list`) under the Content route. Commit `b666fd47` (Jul 2, "V0 — exact-Vercel flat shell") then explicitly deleted the whole Content route tree — commit message literally says **"Content + Links route trees DELETED"** — taking `content/about/page.tsx` (186 lines), both editor-modal components, and both list components with it (1,115 lines total), while `lib/about/api.ts`/`use-about.ts` were simply left behind. Confirmed zero UI reference to Bio/Credentials/Experience anywhere in the current codebase, 3 weeks after the deletion, including in the Content hub that was later rebuilt (for Menu/Products/Events/Booking/Custom-links only) — it was never brought back.
- **Verdict: confirmed intentional-cleanup-that-missed-the-backing-files. Safe to delete `api.ts` + `use-about.ts` entirely, and prune the 9 dead exports from `types.ts`.**
- Effort: ~15 min.
- **Paths (verified 2026-07-25):** `lib/about/api.ts` [LIVE], `lib/about/use-about.ts` [LIVE], `lib/about/types.ts` [LIVE, file stays] — all confirmed.

### 10. Orphaned docs code-block component
*Verified 2026-07-24*
- `app/(marketing)/docs/_components/doc-code-block.tsx` — zero importers anywhere, including every docs page.
- Effort: ~5 min.
- **Paths (verified 2026-07-25):** `app/(marketing)/docs/_components/doc-code-block.tsx` [LIVE] — confirmed.

### 11. Unused signup-draft persistence utility — NOW FULLY CONFIRMED (was "flag possible duplicate")
*Verified 2026-07-24 · resolved 2026-07-24*
- `lib/signup-draft.ts` — a well-built sessionStorage draft-recovery utility (key `partna:signup-draft:v3`, schema-versioned, 24h TTL). Zero real importers.
- **Original hedge:** "the pre-account signup form has its own hardcoded draft key — check whether it duplicated this logic before deleting."
- **What resolved it:** Read `pre-account-signup-form.tsx` in full — it does have its own separate, working draft implementation (`loadDraft`/`saveDraft`/`clearDraft`, lines 40-71, key `commet:signup-draft`), but that's for the *current* 4-step Pre-Account flow. `lib/signup-draft.ts`'s elaborate 11-step enum (`options`/`methods`/`email`/`verify`/`google-business`/`industry`/`integrations`/`brands`/`about`/`username`/`complete`) matches a completely different, OLDER flow. `sign-up/page.tsx`'s own header comment confirms it: **"The old account-first wizard... has been deleted: this flow shipped live 2026-07-21... has been serving every real signup since."** The component `lib/signup-draft.ts` was built for no longer exists at all.
- **Verdict: confirmed dead, no duplicate-logic concern — the two utilities serve two different flows, one of which (the one this file served) was fully retired 3 days ago.**
- Effort: ~10 min.
- **Paths (verified 2026-07-25):** `lib/signup-draft.ts` [LIVE]; `pre-account-signup-form.tsx` → confirmed at `app/(app)/account/(auth)/sign-up/pre-account/pre-account-signup-form.tsx` [LIVE].

### 12. Unused session hooks pair — NOW FULLY CONFIRMED (was "caution, not confirmed")
*Verified 2026-07-24 · resolved 2026-07-24*
- `lib/hooks/use-session.ts` (`useSession()`) + `lib/hooks/use-token-changed-effect.ts` (only ever imported by `use-session.ts`). Zero real importers elsewhere.
- **Original hedge:** "touches auth/session/token — cross-check against `lib/auth-session.ts` before deleting."
- **What resolved it:** `use-session.ts` was born in commit `df673a57` ("phase 3b — fetchSession + useSession() opt-in read hook") — an early-generation hook. `lib/hooks/use-account.ts` (the file that's actually used everywhere — `useAccount`/`useMaybeAccount`) has its own header comment: **"Phase 9 cleanup migrates all `useCurrentAccount` call sites onto these two hooks and deletes the legacy adapter."** `use-session.ts` is a sibling from that same earlier generation that fell out of use as things consolidated onto `useAccount`/`useMaybeAccount`, but wasn't itself targeted for removal. The underlying `TOKEN_CHANGED_EVENT` mechanism it listens to is still very much alive (used directly by `auth-context.tsx` + `auth-session.ts`) — deleting this pair doesn't touch that.
- **Verdict: confirmed pre-Phase-9 leftover, safe to delete.**
- Effort: ~15 min.
- **Paths (verified 2026-07-25):** `lib/hooks/use-session.ts` [LIVE], `lib/hooks/use-token-changed-effect.ts` [LIVE] — both confirmed.

### 13. Orphaned "About" editing UI (dupe cross-reference)
See item 9 — folded in there since it's the same feature/cluster (`lib/about/types.ts`'s 9 dead exports).

### 14. Confirmed-dead exports across the codebase (full knip sweep, all resolved)
*Found 2026-07-24 via `npx knip`, individually re-verified 2026-07-24 by three separate passes over all 86 flagged files (grep-checked for real usage, internal usage, and coincidental same-name traps)*

Every export below has **zero usage anywhere** — not imported externally, not called internally, not a same-name coincidence. Grouped by file:

- `lib/supabase-mfa.ts` — `hasVerifiedFactor` (110)
- `components/ui/popover.tsx` — `PopoverAnchor` (83), `PopoverDescription` (85), `PopoverHeader` (86), `PopoverTitle` (87) *(core `Popover`/`PopoverTrigger`/`PopoverContent` stay — file not prunable)*
- `components/ui/command.tsx` — `CommandShortcut` (193), `CommandSeparator` (194) *(core `Command*` exports stay)*
- `lib/auth/signup-utils.ts` — `toE164Phone` (11): a re-export of `lib/phone.ts`'s real function; every actual caller imports directly from `lib/phone.ts` instead. Safe to drop the re-export line only — `lib/phone.ts`'s own function is very much alive.
- `lib/integrations/platform-registry.ts` — `PLATFORM_GROUPS` (34)
- `lib/coerce.ts` — `readStringOrNull` (9)
- `lib/early-access/places.ts` — `googleMapsPlaceUrl` (79)
- `app/(dev)/dev/kitchensink/mock-data.tsx` — `CalendarClock` (127) *(dev-only tooling, never ships to prod anyway — lowest priority)*
- `components/ui/breadcrumb.tsx` — `BreadcrumbEllipsis` (121) *(core exports stay)*
- `components/ui/input-otp.tsx` — `InputOTPSeparator` (90) *(core exports stay)*
- `components/ui/dropdown-menu.tsx` — `DropdownMenuPortal` (266), `DropdownMenuGroup` (269), `DropdownMenuCheckboxItem` (272), `DropdownMenuShortcut` (276), `DropdownMenuSub` (277), `DropdownMenuSubTrigger` (278), `DropdownMenuSubContent` (279) *(core `DropdownMenu`/`DropdownMenuItem` etc. stay — 7 real importers)*
- `components/ui/kbd.tsx` — `KbdGroup` (26) *(`Kbd` itself stays — 3 real importers)*
- `lib/early-access/platforms.ts` — `EMPTY_PLATFORM_ENTRY`, `toInviteOtherLinks`
- `components/ui/sidebar.tsx` — **all 12** of its flagged exports are dead: `SidebarGroupAction`, `SidebarGroupLabel`, `SidebarInput`, `SidebarMenuAction`, `SidebarMenuBadge`, `SidebarMenuSkeleton`, `SidebarMenuSub`, `SidebarMenuSubButton`, `SidebarMenuSubItem`, `SidebarRail`, `SidebarSeparator`, `SidebarTrigger` *(other `Sidebar`/`SidebarProvider`/`useSidebar` exports stay — file not prunable, just these 12 sub-parts)*
- `components/ui/alert-dialog.tsx` — `AlertDialogAction`, `AlertDialogMedia`, `AlertDialogTrigger` *(`AlertDialogOverlay`/`AlertDialogPortal` are used internally, not dead — don't touch those two)*
- `components/ui/table.tsx` — `TableFooter`, `TableCaption`
- `lib/about/types.ts` — see item 9 above (9 exports, same cluster)
- `components/platform-icon/index.tsx` — **all 8** flagged exports dead: `MISSING_BRAND_ICONS`, `isWordmarkUnavailable`, `getPlatformVisualScale`, `getPlatformWordmark`, `getPlatformMaskLogo`, `getPlatformIcon`, `CustomLinkIcon`, `ServiceLinkIcon` *(real importers only use `getPlatformIconUrl`/`GLYPH_MAP`/`GlyphKey` — unaffected)*
- `lib/social/platforms.ts` — `LINK_CATEGORIES`, `SOCIAL_PLATFORMS`, `getPlatformsByCategory`, `getPlatformInputLabel`, `LINK_CATEGORY_LABELS` *(`PlatformKey`/`getPlatform`/`PLATFORMS` stay — heavily used elsewhere)*
- `lib/sitepage/types.ts` — `SITEPAGE_GALLERY_MAX_IMAGES`, `SITEPAGE_CONTENT_MAX_IMAGES` (the file's own comment admits the cap is "informational," never actually enforced/read anywhere)
- `lib/data-parse.ts` — `readInt`, `readBoolean` *(trap resolved: a completely different, heavily-used `readBoolean` lives in `lib/account/readers.ts` — that one is untouched and correct; this file's own copy is the dead one)*
- `lib/motion.ts` — `spring` *(the file's own comment claims it powers a spring-physics pill-slide animation, but that animation doesn't exist in the current `choice-picker.tsx` — the comment is stale)*
- `lib/chat-engine/action-parsers.ts` — `readNumber`, `readStringArray`, `readMode`
- `components/ui/avatar.tsx` — `AvatarBadge` (114)
- `components/ui/media-overlay.tsx` — `MediaOverlayLink` (59) — its only "usage" is a stale plan doc referencing a component file that no longer exists
- `components/ui/context-menu.tsx` — **all 11** flagged: `ContextMenuCheckboxItem`, `ContextMenuRadioItem`, `ContextMenuLabel`, `ContextMenuSeparator`, `ContextMenuShortcut`, `ContextMenuGroup`, `ContextMenuPortal`, `ContextMenuSub`, `ContextMenuSubContent`, `ContextMenuSubTrigger`, `ContextMenuRadioGroup` *(core `ContextMenu`/`ContextMenuTrigger`/`ContextMenuContent`/`ContextMenuItem` stay — used in `app/(dev)/dev/ui-showcase/page.tsx`)*
- `lib/account-capabilities.ts` — `hasSharedSubheaderForPath` (93), `isSectionAllowedForPath` (96) — **flagging for a sanity glance before deleting**: these read like they should gate tab visibility (`isSectionAllowedForPath` in particular), which made the verifying agent double back and check twice; it confirmed zero callers anywhere including internally, but given the semantic weight, worth one more look at call sites before removing rather than a blind delete.
- `components/providers/step-up-provider.tsx` — `useMfaStepUp` (64) — the real MFA step-up trigger is the separate `lib/mfa-step-up.ts` bridge (`openStepUpAndAwait`), not this context hook.
- `components/shells/auth-page-shell.tsx` — `AuthHandlePreview` (266), `AuthTitleBreak` (287), `AuthSuccessState` (295) — checked against all 10 real consumers of this module, none import these three.
- `components/ui/field.tsx` — `FieldLegend`, `FieldSeparator`, `FieldSet`, `FieldContent`, `FieldTitle` *(`Field`/`FieldLabel`/`FieldDescription`/`FieldError` stay — those are the ones actually used)*
- `components/ui/dialog.tsx` — `DialogTrigger` (177) — every real usage in the repo uses a plain button instead, by design (comments confirm this deliberately)
- `lib/account-section-cache.ts` — `invalidateSectionData` (27), `invalidateSectionDataMany` (32), `loadSectionData` (48) — half this module's public surface; only `getSectionData`/`setSectionData`/`invalidateSectionDataByPrefix` are actually imported (by `contacts/page.tsx`)
- `app/(app)/account/(dashboard)/integrations/tickets-section.tsx` — `eventsSelectionQuery` (760) — `TicketsEventsSection` reimplements the identical query inline instead of importing this
- `app/(app)/account/(dashboard)/integrations/menu-content-manager.tsx` — `MenuItemDeleteConfirm` (643) — bonus corroboration: `docs/superpowers/plans/2026-07-21-dashboard-rebuild-plan.md:284` already lists this by name under "Dead code," independently of this sweep
- `components/ui/input-group.tsx` — `InputGroupTextarea` (165)
- `components/ui/item.tsx` — `ItemSeparator` (196), `ItemHeader` (199), `ItemFooter` (200) — `notifications/page.tsx` (the one plausible consumer) uses `Item`/`ItemActions`/`ItemContent`/`ItemDescription`/`ItemMedia`/`ItemTitle` only; a plan doc confirms `ItemSeparator` there was replaced by `ListCard`
- `lib/staff/api.ts` — `fetchSegment` (126, no segment-detail page exists), `sendInvites` (249, the file's own comment says it's superseded by `approveEarlyAccess`/`approveEarlyAccessBulk`, which `staff/early-access/page.tsx` actually imports)
- `lib/date-format.ts` — `formatShortDate` (4)
- `components/ui/chart.tsx` — `ChartLegend` (370), `ChartLegendContent` (371) *(`ChartStyle`/`ChartContainer` etc. stay)*

**Not included above (confirmed used, don't touch):** every export the sweep tagged "OVER-EXPORTED-ONLY" — code that's actively called *within its own file* but never imported elsewhere (e.g. `brandInfoNavLabel`/`brandPagePath` in `build-capabilities.ts`, `LEGACY_PASSTHROUGH_SEGMENTS` in `platform-registry.ts`, `COUNTRIES` in `geo/countries.ts`, and ~50 more across both chunks). These are a fundamentally different, much lower-priority finding — at most the `export` keyword could be dropped to make them module-private; the code itself is live and must not be deleted. Not itemized here to keep this file focused on things that can actually be deleted.

**Paths (verified 2026-07-25):** all 38 files above confirmed to exist at their stated paths [LIVE] — none archived or build-excluded. No corrections needed.

### 15. `public/` static asset bloat — NEW, found in the expanded sweep
*Found + verified 2026-07-24*
- **`public/themes/Theme 1 Preview.png` + `Theme 2 Preview.png`** (2.2MB each, 4.4MB total, dated March 24) — zero code references anywhere. Directly tied to the same already-dead theme-picker feature as item 1 above.
- **`public/Brand Kit/`** (old, non-namespaced location) — confirmed near-duplicate of `public/branding/Brand Kit/`: `diff -rq` shows the only differences are a stray `.DS_Store` and 2 newer PNGs that exist only in the `branding/` copy. The V4 lean-down commit's own message says "Brand Kit assets reorganised to `public/branding/Brand Kit/`" — the old top-level copy was simply never cleaned up after the move. Delete `public/Brand Kit/`, keep `public/branding/Brand Kit/`.
- **`public/fonts/` — the large majority is a graveyard of pre-2026-07-12 font experiments.** `roster-fonts.css`'s own comment says the current roster was "locked 2026-07-12" and needs exactly 7 flat files (`geist.woff2`, `inter.woff2`, `general-sans.woff2`, `monument-grotesk.woff2`, `forma-djr.woff2`, `helvetica-neue.woff2`, `helvetica-now.woff2`). Confirmed zero code references to any of the following (14MB total in `public/fonts/`, most of it this list):
  - Subdirectories: `NB Architeck/` (60K), `Neue-Haas-Grotesk/` (1.6M), `Saans/` (4.9M), `Theme Font Previews/` (352K), `Forma DJR Banner/` (700K), `Forma DJR Micro/` (556K), `Forma DJR Deck/` (1.4M), `Helvetica Neue/` (2.1M, the multi-weight version — the roster only needs the flat `helvetica-neue.woff2`).
  - Flat files: `GTStandard-MMedium.woff2`, `GTStandard-MRegular.woff2`, `GTStandard-MSemibold.woff2`, `NNRektoratWeb-Heavy.woff2`, `Nb-Arch.woff2`, `OPTIOnyx.woff2`, `Swiss Bold.woff2`, `Swiss Regular .woff2` (note the stray space in that last filename).
  - `Forma DJR Display/` (740K) + `Forma DJR Text/` (592K) are referenced, but **only** by item 16 below's dead `/launch` route — see that item before deleting these two.
- Effort: ~15 min for the confirmed-orphan set; recheck item 16 first for the two conditional ones.
- **Paths (verified 2026-07-25):** every subdirectory and flat file listed above confirmed present under `public/` and `public/fonts/` [LIVE] — these are static assets, so "LIVE" here means they ship as-is in `public/`, not that they go through tsconfig; none are archived elsewhere.

### 16. Dead `/launch` route — NEW, found in the expanded sweep
*Found + verified 2026-07-24*
- `app/(app)/launch/layout.tsx` + `app/(app)/launch/forma-djr.css` — a layout with **no `page.tsx` and no child routes at all**. Next.js has nothing to render here; visiting `/launch` 404s already. Zero links to `/launch` anywhere in the codebase.
- The layout's own comment says it "wraps the launch/waitlist routes" (plural) — whatever page(s) it used to wrap are gone; only the empty shell remains.
- Deleting this also frees up `public/fonts/Forma DJR Display/` + `Forma DJR Text/` (item 15) — this route's CSS is their only consumer.
- Effort: ~10 min.
- **Paths (verified 2026-07-25):** `app/(app)/launch/layout.tsx` [LIVE], `app/(app)/launch/forma-djr.css` [LIVE] — both confirmed.

---

## Full-file inventory pass (2026-07-24) — every file in the repo read, not just checked for imports

The items above came from targeted checks. This second pass dispatched 13 agents to read **all 663 non-test source files** in `app/`, `lib/`, and `components/` in full — not just grep for usage, but judge each file's actual content against current doctrine (removed brand/commerce/Shopify/3-account-type vocabulary, the single-architecture rule, capability-gating). Items 17+ below are new; where a finding independently re-confirms something already listed above, it's noted but not re-numbered.

### 17. Two more whole dead files from the old signup wizard
*Found + verified 2026-07-24*
- `lib/auth/signup-utils.ts` — zero importers anywhere except its own test file. Built for the same deleted account-first signup wizard as `lib/signup-draft.ts` (item 11) — its header comment describes the exact same retired step flow. `lib/signup-draft.ts`'s `SignupDraftStep` type has an almost-identical step-value union, confirming this is a second leftover from the same retirement, not independently in the codebase's memory a first time.
- Effort: ~5 min.
- **Paths (verified 2026-07-25):** `lib/auth/signup-utils.ts` [LIVE] — confirmed.

### 18. Dead public-sitepage query layer — pre-Astro-migration leftover
*Found + verified 2026-07-24*
- `lib/queries/public.ts` (whole file, 7 query entries: `profile`, `siteBySlug`, `bookingConfig`, `bookingServices`, `joinByHandle`, `configIntegrations`, `configSocialPlatforms`) + `lib/queries/fetchers/public.ts` (whole file, the 7 backing fetch functions) — zero consumers anywhere except their own test files.
- Why: public sitepage rendering moved entirely to the separate Astro app (`partna-monorepo/apps/pages`) on Cloudflare Workers. This was the old in-Next.js-dashboard path for the same data, orphaned since. Confirmed `app/` has no public-profile route at all, and the `app/api/public/*` route handlers that do exist are an unrelated proxy mechanism that doesn't import either file.
- Two specific tells: `configIntegrations` is duplicated by a hand-rolled `fetch()` in `use-google-business-connect.ts` (what's actually used); `configSocialPlatforms` is superseded by the static `lib/social/platforms.ts` mirror ("Frontend mirror of the backend platform registry").
- Effort: ~10 min. Largest single dead surface found in this pass.
- **Paths (verified 2026-07-25):** `lib/queries/public.ts` [LIVE], `lib/queries/fetchers/public.ts` [LIVE] — both confirmed.

### 19. Dead auth-probe query layer — cascade from item 12
*Found + verified 2026-07-24*
- `lib/queries/auth.ts` (whole file — `authQueries.session()`) + `lib/queries/fetchers/auth.ts` (whole file — `fetchSession`/`AuthSession`) — zero consumers anywhere except their own tests and `lib/hooks/use-session.ts`, which item 12 already confirmed dead. Nobody had traced one layer further down until this pass — these two are dead by the same cause, just not independently noticed before.
- Effort: ~5 min.
- **Paths (verified 2026-07-25):** `lib/queries/auth.ts` [LIVE], `lib/queries/fetchers/auth.ts` [LIVE] — both confirmed.

### 20. Dead "optimistic updates" feature — built, tested, never wired in
*Found + verified 2026-07-24*
- `lib/hooks/optimistic/account.ts` (whole file — `optimisticAccountUpdate()`) — exported and unit-tested, but zero production call sites. `lib/hooks/use-account-mutation.ts:67-97`'s `optimistic` onMutate/onError/onSettled branch (the only consumer of this function's return shape) is never populated by any real mutation — dead machinery in an otherwise heavily-used file (11+ call sites), not a whole-file issue for that one.
- Added whole-cloth in commit `f9e4ab09` ("phase 5 — immer-based optimistic helpers," 2026-05-20) and never adopted in the ~2 months since.
- Effort: ~10 min for the dead file; leave `use-account-mutation.ts` itself alone except removing the unused branch.
- **Paths (verified 2026-07-25):** `lib/hooks/optimistic/account.ts` [LIVE]; `lib/hooks/use-account-mutation.ts` [LIVE, file stays].

### 21. Dead partial query-factory slices (live files, some entries never adopted)
*Found + verified 2026-07-24*
- `lib/queries/account.ts` — `notificationEmailPreferences()` entry dead (zero consumers besides its own test). `lib/queries/fetchers/account.ts` — matching `fetchAccountNotificationEmailPreferences` dead. Rest of both files (`me()`, `notifications()`, `fetchAccount`, `fetchAccountNotifications`) stays, heavily used.
- `lib/queries/customers.ts` — `customer(id)`, `enquiries()`, `emailSubscribers()` entries dead; only `list()` is used. `lib/queries/fetchers/customers.ts` — matching `fetchCustomer`, the `Enquiry`/`EnquiryStatus`/`EnquiriesResponse` types + `fetchEnquiries`, and `fetchEmailSubscribers` all dead; only `fetchCustomers` stays. **Smoking gun on the enquiries slice:** commit `aa51e366` built an Enquiries inbox UI reusing `customerQueries.enquiries()`, then commit `ad7fbf8c` ("remove the Enquiries inbox from the section for now") ripped the UI back out **the same day** (2026-06-04) — nothing has reconnected it in the 7 weeks since.
- `lib/queries/sitepage.ts` + `lib/queries/fetchers/sitepage.ts` — only the `services()` entry + the Services CRUD functions (`create/update/delete/resync/restore*Service`, `moveServiceToCategory`, `reorderSitepageServiceLayout`, category CRUD) are alive (via the booking tables). Dead: `links()`, `sections()`, `service(id)` singular, `serviceCategories()`/`serviceCategory(id)` read-singular, `gallery()`, `images()` 0-arg, `site()`, and their matching fetchers. Superseded by `lib/sitepage/api.ts`'s own differently-signed versions (confirmed: `menu-content-manager.tsx` imports `fetchSitepageImages` from `lib/sitepage/api`, not this file) plus ad hoc `authedJsonRequest("/site")` calls scattered across 6 other files. The file's own comment documents an intended Phase 6 migration that only landed for Services.
- Effort: ~20 min total across the 6 files; leave the live entries in each alone.
- **Paths (verified 2026-07-25):** all 6 files (`lib/queries/{account,customers,sitepage}.ts` + matching `lib/queries/fetchers/{account,customers,sitepage}.ts`) confirmed [LIVE].

### 22. Two dev-only demo files outside the allowed component trees
*Found + verified 2026-07-24*
- `components/examples/c-chart-13.tsx` + `components/examples/c-chart-19.tsx` — `components/examples/` isn't in the Frontend CLAUDE.md's list of allowed non-`ui/` component trees (`shells`, `brand`, `controls`, `platform-icon`, `feedback`, `dialogs`, `fields`, `toasts`, `observability`, `providers`). Both are decorative hardcoded-data demo cards, sole consumer is the dev-only (`notFound()`-gated in production) `ui-showcase` page. `components/ui/row-pie-chart.tsx`'s own comment says it already extracted the real pattern from `c-chart-19.tsx` — this file is the leftover raw material, already superseded.
- Effort: ~5 min.
- **Paths (verified 2026-07-25):** `components/examples/c-chart-13.tsx` [LIVE], `c-chart-19.tsx` [LIVE] — both confirmed.

### 23. Dead shared-abstraction file — built to generalize a pattern nobody adopted
*Found + verified 2026-07-24*
- `components/shells/entity-detail-shell.tsx` (whole file — `EntityDetailHeader`, `EntityDetailLayout`, `DetailsStrip`) — built explicitly, per its own header comment, to generalize the header+details-block duplicated across `integration-detail-shell.tsx` and `feature-detail-shell.tsx`. Neither of those two ever actually imports it — the duplication it was meant to collapse still exists, uncollapsed, in both real call sites. Only consumer is the dev-only `ui-showcase` page + its own test.
- Effort: ~5 min.
- **Paths (verified 2026-07-25):** `components/shells/entity-detail-shell.tsx` [LIVE] — confirmed.

### 24. 12 whole `components/ui/` files with zero production usage
*Found + verified 2026-07-24*
Every one of these compiles and works — they're just reachable only through the dev-only `ui-showcase` gallery, never from real app code:
- **With a confirmed active replacement already in use:** `relative-time.tsx` (real code calls `lib/relative-time.ts`'s `relativeTime()` directly instead), `scroll-row.tsx` (superseded by `scrollable-row.tsx`, "promoted to `components/ui/` 2026-07-23" per its own header), `section-card.tsx` (superseded by the still-live `IntegrationSection` in `integrations-ui.tsx` — the intended migration away from it never happened), `entity-card.tsx` (the real connected-integrations UI hand-built its own different layout instead, per a 2026-07-24 comment).
- **Preemptively built, never adopted:** `split-button.tsx`, `button-group.tsx` (the latter's only other importer is the former, itself dead).
- **Stock shadcn primitives, never adopted, no replacement pattern found elsewhere:** `carousel.tsx`, `context-menu.tsx` (this upgrades the earlier knip finding — even its "core" `ContextMenu`/`ContextMenuTrigger`/`ContextMenuContent`/`ContextMenuItem` exports, previously assumed live, turn out to have zero production callers too, only the dev showcase), `loading-dots.tsx`, `tabs.tsx`, `toggle.tsx`, `toggle-group.tsx` (only other importer is `toggle.tsx`, itself dead).
- Effort: ~30 min for all 12.
- **Paths (verified 2026-07-25):** all 12 files confirmed under `components/ui/` [LIVE] — none archived or excluded.

### 25. Two dead local scripts tied to already-retired features
*Found + verified 2026-07-24 (found directly, not via a sub-agent)*
- `scripts/create-theme-skin.mjs` (wired to `package.json`'s `"new-theme"` script) — scaffolds a brand-new `app/<theme-name>/` directory with its own `.module.css` file. This directly contradicts current doctrine ("exactly ONE architecture," "no new `*.module.css`") and would actively produce doctrine-violating output if anyone ran it today. Leftover from the pre-"staple" multi-theme-picker era.
- `scripts/sql/seed_layrite_commerce_analytics.sql` (444 lines) — seeds fake analytics data for a `professional_type = 'brand'` account against `core.brand_partner_links`, a table the project's own CLAUDE.md says was removed ("The old 3-account-type system, BrandPartnerLink... removed — never reintroduce"). Zero references anywhere else in the repo.
- Effort: ~5 min for both (these aren't wired into any CI/build step — pure standalone scripts).
- **Paths (verified 2026-07-25):** `scripts/create-theme-skin.mjs` [LIVE], `scripts/sql/seed_layrite_commerce_analytics.sql` [LIVE] — both confirmed.

### 26. More dead `RESERVED_PATHS` entries beyond item 1
*Found + verified 2026-07-24*
- `lib/routes.ts` — beyond the 6 already listed in item 1, these entries also have no matching directory anywhere under `app/`: `test-api` (32), `test-me` (33), `site-page` (38 — distinct from the still-real `sitepage` on line 31), `customisation` (54), `customer-creation` (55), `learn-more` (56), `troubleshooting` (59), `coming-soon` (64), `help` (50). They sit directly interleaved with the item-1 entries in the same two clusters, suggesting the same original cleanup pass just didn't catch all of them.
- **Not flagged** (deliberately kept despite no matching directory): `admin`, `home` — generic defensive reservations that legitimately outlive a page (blocking handle-squatting on common words).
- Effort: fold into item 1's cleanup, +5 min.
- **Paths (verified 2026-07-25):** `lib/routes.ts` [LIVE] — same file as item 1, confirmed.

### 27. Small dead exports found across otherwise-healthy files
*Found + verified 2026-07-24 — each is a single unused export in a file that's mostly alive; don't touch the rest of these files*
- `app/(app)/account/(dashboard)/contacts/utils.ts:6` — `ContactsSection` type, zero usage anywhere.
- `app/(app)/account/(dashboard)/features/[feature]/feature-registry.ts` — the `danger` field on `FeatureMeta` (populated for all 3 features, consumed nowhere). Its own sibling file's comment explains why: "the Settings sub-route died... the danger-styled reversible disable card was deleted outright" — the type/data were never pruned after that removal.
- `app/(app)/account/(dashboard)/integrations/types.ts:160-168` — `HighlightItem` and `HighlightsConfig`, zero usage; the sections these were written for now use their own local shapes.
- `lib/social/platforms.ts` — one more dead export beyond the 5 already tracked in item 14: `PlatformHandleLocation` (type).
- `components/shells/account-shell.tsx:31` — `AppFooterAppearanceMode` type alias, zero usage anywhere including internally (the file's own props type uses the real `AppearanceMode` directly).
- `components/settings/form-dialog.tsx:122-148` — the entire `surface === "drawer"` branch (~25 lines) has zero production call sites; only the dev showcase page exercises it. The default `surface="modal"` path (30+ real consumers) is fine and stays.
- `lib/queries/stale-times.ts:22` — `StaleTimeTier` type, zero usage (the `STALE_TIME`/`POLL_INTERVAL`/`REAL_TIME_POLL` constants in the same file are fine and heavily used).
- Effort: ~15 min total.
- **Paths (verified 2026-07-25):** all 7 files confirmed [LIVE] at their stated paths.

---

## DO NOT DELETE — confirmed false positives, checked so the sweep doesn't waste time rediscovering them

### Unused npm dependencies flagged by knip
- `tw-animate-css` — imported via `@import "tw-animate-css"` in `app/(app)/globals.css`. CSS `@import` is invisible to knip's JS analysis.
- `shadcn`, `tailwindcss` (devDependencies) — CLI/build-tool only (`npx shadcn add`, the PostCSS pipeline), never imported in JS/TS.
- **Do not remove from `package.json`** — removing `tailwindcss` would break the entire build for zero benefit.
- **Paths (verified 2026-07-25):** `@import "tw-animate-css"` confirmed at `app/(app)/globals.css:1`.

### "OVER-EXPORTED-ONLY" exports (from the knip sweep)
~88 exports across all three fully-processed chunks (all 86 flagged files) are used internally within their own file but never imported elsewhere (e.g. `CHARLIE_CONFIRM_NOTE`, `removeAccount`, `brandInfoNavLabel`/`brandPagePath`, `LEGACY_PASSTHROUGH_SEGMENTS`, `COUNTRIES`, `SUBDIVISIONS`, `MFA_KEYS`, `useCarousel`, `PLATFORM_DETAILS`, and many more). **This is not dead code** — at most the `export` keyword is unnecessary. Do not delete any of these; not worth itemizing individually here since there's nothing to remove.

### `lib/env.ts` — `env` export (AMBIGUOUS, needs a decision not a deletion)
The `env` binding itself is read nowhere, not even internally — but `app/(app)/layout.tsx:11` does a bare `import "@/lib/env"` for its side effect (`envSchema.parse()` validates required env vars at load time and throws if missing). Deleting the export is fine; deleting the whole file would silently remove startup env validation. Needs a decision (drop `export`, keep the statement) rather than a mechanical delete.
- **Paths (verified 2026-07-25):** `lib/env.ts` confirmed; the `import "@/lib/env"` side-effect line confirmed at `app/(app)/layout.tsx:11`.

---

## Fixes (bugs to correct)

### 1. Charlie font-tool schema still advertises 8 fonts the handler rejects
*Verified 2026-07-24 — half-fixed already*
- **Where:** `lib/chat-engine/registry.ts:437` (the `design_set_font` tool's `description` string) vs. the real `FONT_SLUGS` allowlist in `lib/chat-engine/action-design-handlers.ts:34-42`.
- **Bug:** `registry.ts:437` still reads: `"Set the sitepage font by slug. Valid slugs: geist, origin, melodrama, parceh, humane, reglo, oswald, ostrich-sans, young-serif."` — 8 of those 9 slugs don't exist. Real roster: `geist, inter, general-sans, monument-grotesk, forma-djr, helvetica-neue, helvetica-now`.
- **Already fixed today:** commit `e82b1daf` corrected the *fallback error message* at `action-design-handlers.ts:67`.
- **Still to do:** update the `description` string in `registry.ts:437`. Ideally derive it from `FONT_SLUGS` so the two can't drift apart again.
- Effort: ~30 min.
- **Paths (verified 2026-07-25):** `lib/chat-engine/registry.ts` [LIVE], `lib/chat-engine/action-design-handlers.ts` [LIVE] — both confirmed.

### 2. Charlie's notification-preference tool will 422 on two of its four categories
*Found 2026-07-24, full-file inventory pass*
- **Where:** `lib/chat-engine/registry.ts:271-286` (the tool's `enum`/description) + `lib/chat-engine/action-settings-handlers.ts:30-35,267-269` (`NOTIFICATION_CATEGORIES`).
- **Bug:** both hardcode `["subscriptions", "analytics_weekly", "analytics_milestones", "profile_tasks"]`. But `settings-notifications-section.tsx:22-38` documents that this exact pair was already found broken and fixed in the real dashboard: `"subscriptions"` maps to no real backend category (the toggle 422'd on every save; the Billing/Subscriptions group was removed from the dashboard entirely), and the real milestones category is called `"achievement"`, not `"analytics_milestones"`.
- **Impact:** if a user asks Charlie to toggle "subscription notifications," the tool call 422s. If they ask about milestone notifications, Charlie can never emit the correct category name (`"achievement"` isn't in its `enum`).
- **Root cause:** `action-settings-handlers.ts`'s own comment says it "mirrors the non-mandatory rows in settings-notifications-section.tsx" — that mirror broke when the dashboard got its fix and the chat-engine side didn't.
- Effort: ~20 min.
- **Paths (verified 2026-07-25):** `lib/chat-engine/registry.ts` [LIVE], `lib/chat-engine/action-settings-handlers.ts` [LIVE]; `settings-notifications-section.tsx` → confirmed at `app/(app)/account/(dashboard)/settings/settings-notifications-section.tsx` [LIVE].

### 3. Charlie's `business_update_info` tool sends the wrong HTTP verb
*Found 2026-07-24, full-file inventory pass — confidence medium-high (can't inspect the Laravel route table directly, but every real frontend caller agrees)*
- **Where:** `lib/chat-engine/action-business-handlers.ts:55` — the `business_update_info` tool sends **POST** to `/site/workplace`.
- **Bug:** the one real, live upsert wrapper for that same endpoint — `lib/site-workplace.ts:79-85`'s `upsertWorkplace()`, used by the actual Business/Workplace Info page — sends **PUT**. The signup wizard (`setup-steps.tsx:641`) also explicitly uses `PUT` against the same path. `action-business-handlers.ts` looks like it was written against a since-changed contract and never updated (several other files' comments still say "POST /site/workplace" too, consistent with old-but-uncorrected shared knowledge).
- **Impact:** the tool is likely non-functional (would 404/405 against the real route) — worth confirming against the actual Laravel route before fixing, but every piece of frontend evidence points the same way.
- Effort: ~15 min to confirm + fix.
- **Paths (verified 2026-07-25):** `lib/chat-engine/action-business-handlers.ts` [LIVE], `lib/site-workplace.ts` [LIVE]; `setup-steps.tsx` → confirmed at `app/(app)/account/(auth)/sign-up/pre-account/setup-steps.tsx` [LIVE].

### 4. Pricing page shows factually wrong tier comparisons to real prospects
*Found 2026-07-24, full-file inventory pass — high confidence, customer-facing*
- **Where:** `app/(marketing)/pricing/_pricing-data.ts`.
- **Bugs, cross-checked against `lib/integrations/platform-registry.ts` and `docs/reference/account-types/page.tsx`:**
  - Line 71: lists **"Multi-page site"** as a Business-tier highlight — flatly contradicts the "exactly ONE architecture, single continuous page" doctrine (confirmed independently in the docs pages themselves). Reads like leftover copy from before the `staple` architecture was locked in.
  - Lines 121-122: marks **"Reservations (OpenTable, ResDiary)"** and **"Menu & online ordering"** as available on both tiers. `platform-registry.ts`'s `HIDDEN_SLUGS.partna` list and its own code comment ("Standard (partna) hides the hospitality category cards") confirm these are Business-exclusive — only `"Storewide booking"` is correctly marked so.
  - Line 124: marks **"Music (Spotify, Bandcamp, Apple Music)"** as available on both tiers. `platform-registry.ts`'s `HIDDEN_GROUPS.business` explicitly hides Listen/Community/Shop/Other for Business accounts — this integration group isn't available to them at all.
- **Impact:** a prospect comparing plans is told the wrong things are included on each tier — this is public, customer-facing, revenue-adjacent copy.
- Effort: ~20 min — the data model only has `both`/`businessOnly` helpers, may need a third state to express "Professional-only" correctly.
- **Paths (verified 2026-07-25):** `app/(marketing)/pricing/_pricing-data.ts` [LIVE], `lib/integrations/platform-registry.ts` [LIVE], `app/(marketing)/docs/reference/account-types/page.tsx` [LIVE] — all confirmed.

### 5. Docs pages drifted stale after three recent refactors — same root pattern as the font bug
*Found 2026-07-24, full-file inventory pass*
Several `/docs/*` pages describe integrations/design/fonts as they were before three separate 2026-07 refactors, none of which touched the docs when they shipped:
- **`docs/reference/fonts/page.tsx:46-59`** — lists Origin/Young Serif/Melodrama/Parceh/Humane/Oswald/Ostrich Sans/Reglo as "the roster." Every one of those (except Geist) is explicitly retired per `use-individual-typography.ts`'s own `RETIRED_FONT_MIGRATION` map ("removed in the 7-font simplification, 2026-07-12"). The doc never mentions any of the 7 fonts that are actually live. **This is the same underlying stale-roster problem as Fix #1 above, just surfacing in public docs instead of the AI tool** — worth fixing both from the same source of truth.
- **`docs/integrations/socials/page.tsx:23-47`** + **`docs/reference/platforms/page.tsx:69-107`** — list 7 socials (Instagram/Facebook/TikTok/X/LinkedIn/Threads/Reddit); the real list (`lib/integrations/socials-data.ts`) has 12 — the same 7 plus Snapchat/Discord/Telegram/Kick/Medium, added 2026-07-23 (one day before this audit).
- **`docs/integrations/page.tsx:54`** + **`docs/reference/platforms/page.tsx:227-229`** — still list Google Business as a connectable Integrations item; it was absorbed into Workplace/Business entirely on 2026-07-23 and is no longer a registry entry.
- **`docs/reference/account-types/page.tsx:55-90`** — lists "Menu" as an Integrations group shown/hidden by account type; Menu moved out of the integrations picker entirely on 2026-07-15 (it's now a standalone page). The table also never mentions that Business accounts lose the Listen/Community/Shop/Other groups (same fact as Fix #4's pricing-page gap).
- **`docs/integrations/booking/page.tsx:23-28`** — says booking "works with Fresha and Square" only; the real list (`lib/social/platforms.ts`) has 5: Fresha, Booksy, Timely, Calendly, Square. The marketing `features/bookings/page.tsx` correctly describes all 5.
- Effort: ~30 min for the batch — all fixable by reading off the same current data sources cited above.
- **Paths (verified 2026-07-25):** all 6 docs pages confirmed under `app/(marketing)/docs/...` [LIVE]; `lib/integrations/socials-data.ts` [LIVE]; `use-individual-typography.ts` → confirmed at `app/(app)/account/(dashboard)/design/use-individual-typography.ts` [LIVE]; `lib/social/platforms.ts` [LIVE].

### 6. Settings page repeats the same stale Google Business claim as the pricing/docs pages
*Found 2026-07-24, full-file inventory pass (originally surfaced by the settings/workplace agent, grouped here since it's the same root cause as Fix #5)*
- **Where:** `app/(app)/account/(dashboard)/settings/account-type-cards.tsx:44` (context 39-49).
- **Bug:** the "Business Partna" plan tile lists "Google Business profile sync" as a business-exclusive feature. Since the 2026-07-23 merge, Google Business is identically available to both account types via Workplace/Business — confirmed no capability flag gates it either way. The file's own header comment already calls the whole bullet list "placeholder copy," so this is known-soft, but this specific claim is concretely wrong today.
- Effort: ~10 min.
- **Paths (verified 2026-07-25):** `app/(app)/account/(dashboard)/settings/account-type-cards.tsx` [LIVE] — confirmed.

---

## Needs a human/product decision — not a mechanical delete or fix

### 1. `lib/shop.ts` and the products/e-commerce integration surface — possible doctrine conflict
*Found 2026-07-24, full-file inventory pass*
- `lib/shop.ts` (Shopify/WooCommerce/Squarespace/BigCartel provider labels + canonical product-URL builder) is live and actively used by 8+ files (`store-connect-wizard.tsx`, `products-table.tsx`, `stores-table.tsx`, `products-section.tsx`, `shop-brand-modals.tsx`, `store-settings-section.tsx`, `category-sections.tsx`, `shop-product-card.tsx`), plus a `shop` field in `lib/account-types.ts`'s `CustomisationSections`, a `cover_shopify` design-media slot, and a `cdn.shopify.com` entry in the image optimizer allowlist.
- **The tension:** the repo hub's own doctrine says *"No commerce, brand affiliation, or Shopify... Stripe, Shopify: removed — never reintroduce."* Yet this is a substantial, currently-live, actively-imported feature surface built specifically around Shopify (among other providers).
- **What we could confirm:** `platform-registry.ts` treats this as a legitimate current "E-commerce" integration category (`slug: "products"`) available to both account types, and the Shopify piece specifically appears to be "show products from a store you separately own" — a read-only catalog display, never touching checkout/payments/Stripe. That reading is consistent with the doctrine's likely intent (no *Partna-run* commerce), but neither this pass nor a direct spot-check earlier in this audit could fully rule out that it's a leftover the pivot cleanup simply missed.
- **Not resolved here on purpose** — this is a product/architecture call, not a code-cleanup one. Flagging so it doesn't get silently assumed either way.
- **Paths (verified 2026-07-25):** `lib/shop.ts` [LIVE], all 8 consumer files confirmed under `app/(app)/account/(dashboard)/integrations/` [LIVE], `lib/account-types.ts` [LIVE].

### 2. `app/(app)/account/template.tsx` — undocumented file with a real behavioral side effect
*Found 2026-07-24, full-file inventory pass*
- The only `template.tsx` anywhere in `app/`. Does nothing but `return <>{children}</>` — but unlike a `layout.tsx`, a Next.js `template.tsx` forces a full remount of everything beneath it on **every** navigation within `/account/*`, resetting scroll position and component state each time.
- Unlike every other file in this codebase, it carries no comment explaining why it exists — a real outlier given how consistently this repo documents non-obvious behavior.
- **Two equally plausible readings:** vestigial scaffolding nobody deleted, or a deliberate-but-undocumented remount-forcing shim (some dashboards use this pattern intentionally to guarantee clean state between pages). Can't distinguish from static reading alone — worth a quick runtime check (does removing it change anything observable?) before deciding either way.
- **Paths (verified 2026-07-25):** `app/(app)/account/template.tsx` [LIVE] — confirmed.

---

## Stale comments & naming (documentation-only — no functional impact, batched to keep this file scannable)
*Found 2026-07-24, full-file inventory pass. None of these need code-behavior changes — only comments, names, or minor consolidation. Low priority; fix opportunistically when touching these files for other reasons.*

- **`media/` directory naming collision risk:** `media/layout.tsx`, `media/page.tsx`, `media/settings/page.tsx` still have function names `ContentLayout`/`ContentPage`/`ContentSettingsPage` (plus `lib/content-media.ts`'s whole "content client" framing) from before `/account/content` was repurposed into the real Content Hub (`ContentHubPage`, a different page). One letter apart, genuinely confusing to a future reader — not a bug (Next.js routes by folder, not export name), but worth a rename pass.
- **Integrations directory stale comments (6+ files):** `google-business-details.tsx`, `shop-product-card.tsx`, `stores-table.tsx`, `page.tsx`, `socials-section.tsx`, `menu-section.tsx`, `tickets-section.tsx` all have comments referencing pages/components that were deleted or renamed during the Products/Menu/Tickets/Google-Business "standalone page" migrations — e.g. citing a `SocialRow` component that's now inlined, or a "brand detail page" that no longer exists. All comment-only, zero functional impact.
- **Deleted-wizard file references:** `pre-account-signup-form.tsx`'s header cites a feature flag (`NEXT_PUBLIC_PRE_ACCOUNT_SIGNUP_ENABLED`) that no longer exists in code; `verify-email-handler.tsx` cites a `verify-step.tsx` file that was deleted with the old wizard; `lib/forward-redirect.ts` + its consumers `app/(app)/login/page.tsx` + `app/(app)/create-account/page.tsx` all cite `brand_partner`/`shopify_setup_token` as example forwarded params — leftover vocabulary from the removed brand/partner system.
- **`lib/account/build-capabilities.ts`** — comment says the nav labels are "Business Info"/"Workplace Info"; the actual `brandInfoNavLabel()` function returns just "Business"/"Workplace." Comment-only mismatch.
- **Duplicated-but-in-sync data:** `features/page.tsx` and `feature-registry.ts` independently hardcode the same 3 features' label/description text with nothing enforcing they stay in sync (currently identical, but a future edit to one won't propagate). `features-menu.tsx`/`industries-menu.tsx` are near-identical dropdown components (~40 lines of byte-identical hook logic) that both self-acknowledge mirroring each other — a candidate for extracting one generic `ProductMenu` component, not urgent.
- **Triplicated/quadruplicated tiny helpers:** `readString`/`readNumber` exist independently in `lib/account/readers.ts` (canonical), `lib/coerce.ts` (a passthrough re-export), and `lib/data-parse.ts` (a from-scratch reimplementation) — all three have live, disjoint callers. `normalizeUrl` exists in `lib/coerce.ts` plus separately-defined byte-identical copies in `lib/auth-session.ts`, `lib/auth-context.tsx`, and an inlined copy in `lib/auth-accounts.ts` — none of the three import the one in `coerce.ts`. No bug, just worth consolidating someday.
- **Misc small naming/cosmetic items:** `product-mockups.tsx`'s `LiveWatchers` export is unused externally (called only internally); `sign-up/page.tsx`'s default export is misnamed `CreateAccountPage`; `claim/[subdomain]/page.tsx` duplicates `auth-and-processing-step.tsx`'s OTP-claim logic (self-documented as intentional — cold cross-device arrival vs. mid-session state); `lib/queries/stale-times.ts`'s `StaleTimeTier` type and `lib/use-section-states.ts` (a "use-*" file that no longer exports a hook, only a constant) are both trivial/cosmetic; the staff dashboard has 12 stray blank-with-whitespace lines across 8 files (copy-paste artifact, zero behavioral effect).

---

## Confirmed clean areas (full-file inventory pass, 2026-07-24)
Worth recording what was checked and found to have **nothing wrong** — these areas are not silently unaudited, they were read file-by-file and came back clean: the entire staff dashboard (33 files), design/analytics/overview (40 files), marketing shared components + docs infra (21 files), and settings/workplace/business/branding/features came back 49/52 clean. Re-running a check on these specifically is low-yield.

## Suggested order
Batch the pure mechanical whole-file deletions together — items 1, 5, 6, 7, 9, 10, 11, 12, 17, 18, 19, 20, 22, 23, 24, 25 have no dependencies on each other and no remaining uncertainty (biggest single win: item 18, then 24). Items 2 and 8 are fully cleared but touch live user-facing render paths — a normal review pass, not blind deletion. Item 3, item 4, item 21, and item 27 need a closer read (collapsing branches or trimming specific exports out of otherwise-live files) rather than whole-file deletes. Item 26 folds into item 1. The two "needs a decision" items (`lib/shop.ts`, `account/template.tsx`) should be resolved *before* touching anything in their blast radius, not mechanically. Fixes 1-6 are all small and either user-facing or customer-facing (especially Fix 4, the pricing page) — worth prioritizing over the bloat cleanup, not after it. The "stale comments & naming" section is opportunistic, lowest priority, fix only when already touching those files.

## Separate, out-of-scope finding surfaced during this audit
While checking the Fresha data in Supabase, the tooling flagged a **live security issue unrelated to frontend bloat**: 3 tables in the `site` schema have Row Level Security disabled and are fully exposed to the anon/authenticated Supabase roles — `site.design_kits_font_backup_20260709`, `site.design_kit_contributions_font_backup_20260709`, `site.item_slugs`. This is a Comet-Backend/Supabase concern, not a Partna-Frontend one, so it's flagged here rather than added to this file's scope — raised directly in chat, not left buried.

## How this file was built
**Pass 1** (items 1-8 originally): manual verification (Read/Grep against source, cross-referencing nav configs and the account-capabilities route allowlist). **Pass 2** (items 9-16 originally, labeled "leads"): `npx knip@latest --reporter json` plus spot-checks. **Pass 3:** every hedge from passes 1-2 individually resolved with direct evidence — git archaeology for intent, a live production Supabase query, a backend+exposure check, exhaustive re-verification of exports, and a complete three-way re-audit of all 86 knip-flagged files distinguishing genuinely dead code from merely-over-exported code from knip false positives — plus a fresh sweep of `public/` assets and routes outside knip's scope. **Pass 4** (2026-07-24, items 17+ and both new Fixes/Decisions/Naming sections): a full-file inventory — 13 parallel agents read all 663 non-test source files in `app/`, `lib/`, and `components/` in full (not grep-sampled), judging each against current doctrine rather than just checking import graphs. This is what surfaced the whole dead query-factory layer (item 18-21), the dev-only `components/ui/` files with zero production usage beyond knip's per-export view (item 24), and — most importantly — real functional bugs that no dead-code tool would ever find (Fixes 2-6: two broken Charlie tool calls and factually wrong customer-facing pricing/docs copy). `scripts/` and root config files (`next.config.ts`, `proxy.ts`) were checked directly, not via an agent. **Pass 5** (2026-07-25): 3 Haiku agents re-verified every path above against the live repo and labeled every "ready to delete" item LIVE or ARCHIVED — all came back LIVE, nothing frontend-side is already dormant.

---

> **Source:** `Partna-Frontend/docs/audit/2026-07-25-inheritance-opportunities.md`

# Frontend inheritance & consolidation opportunities — 2026-07-25

Different lens from the bloat-and-fixes doc: this is about **live, correct code** duplicated across 2+ files, where a shared hook/component/factory would mean editing one place instead of several. Nothing here is dead or wrong — it's a maintainability investment.

**Method:** 7 Haiku agents swept broadly (integrations, design-kit dashboard, chat-engine, queries/hooks, components, dashboard misc pages, lib root) for candidates. Every candidate below was re-verified by reading the actual files, cross-checking against this repo's own "copy patterns between page dirs, never cross-import" doctrine where relevant, and checking git history where a prior consolidation attempt was referenced. Nothing has been implemented — this is the reviewed, planned list.

---

## Tier 1 — High confidence, verified, clear win

### 1. Connect-flow state machine — 5 files, same generation-counter discipline
`connect-modals.tsx` (`SingleInputConnectModal`), `media-sections.tsx` (`AppleConnection`), `booking-section.tsx`, `youtube-section.tsx`, `link-card-sections.tsx` each independently re-implement the identical `value/pending/polling/error` state quartet plus an `aliveRef` (unmount guard) + `pollSeq` (generation counter to distinguish a stale poll from a reopened one) discipline — confirmed near-word-for-word across all 5 by the sweep, and this exact pattern is what several individual files' own comments already describe copying from each other (e.g. the `0002ecbb` Apple Music/Podcasts commit explicitly modeled its polling on `SingleInputConnectModal`'s "existing shape"). A `useConnectFlow()` hook returning `{ pending, polling, error, aliveRef, pollSeq, reset }` would mean the close/reopen race-guard logic — the trickiest part — is fixed once, not five times.
- **Paths (verified 2026-07-25):** all 5 files confirmed under `app/(app)/account/(dashboard)/integrations/` [LIVE].

### 2. Design-kit boolean-toggle hooks — explicit "cloned from" comment
`use-individual-letter-case.ts` and `use-individual-night-shift.ts` — `use-individual-night-shift.ts`'s own header says **"Cloned from useIndividualLetterCase"**. ~100 lines of identical save/reset/sync scaffolding (server-value ref, re-sync effect, save-mutation-seeds-cache, reset-mutation-refetches) with only the field name and default differing. A `useSingleBooleanField(key, defaultValue, successMessage)` factory replaces both.

- **Historical note, worth knowing before implementing:** `git log` on this codebase shows a "V4 lean-down" commit (months ago) that explicitly introduced a `useSingleFieldForm()` factory "for useColours/useThemeMode/useFontFamily/etc." — but that function doesn't exist anywhere in the codebase today (confirmed via repo-wide grep). It was either renamed beyond recognition or the team walked back the abstraction and returned to copy-paste-per-hook — the night-shift hook being an explicit clone (rather than a factory call) is the tell. Worth asking whoever was around for that if there was a reason it didn't stick, before reintroducing the same shape.
- **Paths (verified 2026-07-25):** `use-individual-night-shift.ts` confirmed at `app/(app)/account/(dashboard)/design/use-individual-night-shift.ts` [LIVE]. **CORRECTION:** `use-individual-letter-case.ts` does NOT exist as its own file — `useIndividualLetterCase` is one of several hooks exported from `app/(app)/account/(dashboard)/design/use-individual-typography.ts` (confirmed via that file's own header comment "useIndividualLetterCase → typography_uppercase (boolean)" and the exported function at line 254, alongside `useIndividualFont`). Doesn't change the consolidation case — the duplicated scaffolding is real either way — but whoever picks this up should know the "clone source" is a function inside `use-individual-typography.ts`, not a same-shaped sibling file.

### 3. Chat-engine handler patterns — 3 concrete, mechanical extractions
Read all 12 files in `lib/chat-engine/` to verify:
- **Field-presence checks** — `Object.prototype.hasOwnProperty.call(input, fieldName)` appears 20+ times across `action-contacts-handlers.ts`, `action-customisation-handlers.ts`, `action-settings-handlers.ts`, `action-business-handlers.ts`. A `hasInputField(input, fieldName)` helper in `action-parsers.ts` (which already holds the other shared input-parsing helpers) is a natural, low-risk home.
- **Single-field PATCH/PUT mutation shape** — `executeContentSetInstagramAuto`, `executeContentSetSectionPublication`, `executeDesignSetColors`, `executeDesignSetFont`, `executeDesignSetLetterCase`, `executeSettingsUpdateUsername`, `executeSettingsSetCharlie` (7 handlers) all follow validate-one-field → `requestBackend()` PATCH/PUT → return success message. A factory `makeSingleFieldMutator(validator, path, method, bodyBuilder, successMessage)` in `action-gateway-core.ts` would turn each of these 7 handlers into a one-line factory call instead of a ~15-line function.
- **Boolean-toggle subset** — 3 of the 7 above (`executeContentSetInstagramAuto`, `executeDesignSetLetterCase`, `executeSettingsSetCharlie`) are specifically boolean toggles; a `makeBooleanToggler(toolName, path, method, bodyBuilder)` specialization reads clearer than the generic factory for just these three.
- **Paths (verified 2026-07-25):** all 7 files (`action-contacts-handlers.ts`, `action-customisation-handlers.ts`, `action-settings-handlers.ts`, `action-business-handlers.ts`, `action-parsers.ts`, `action-gateway-core.ts`, `action-design-handlers.ts`) confirmed under `lib/chat-engine/` [LIVE].

### 4. Query factory pattern — 8 files
`lib/queries/{account,analytics,auth,customers,dev-insights,integrations,public,sitepage}.ts` (note: `auth.ts` is already flagged dead in the bloat doc — the pattern still holds for the other 7) all repeat the same `{ all: () => [...], entry: () => queryOptions({...}) }` factory shape. `public.ts`'s `publicFetch<T>()` wrapper is already exactly this kind of generalization done well for its 7 endpoints — worth using as the template for a `createQueries()` builder covering the rest, so a cross-cutting change (e.g. adding a default `gcTime`) is one edit instead of 20+.
- **Paths (verified 2026-07-25):** all 8 files confirmed under `lib/queries/` [LIVE].

### 5. `sitepage.ts` — two identical functions in the same file
`unwrapService()` (lines ~94-97) and `unwrapServiceCategory()` (lines ~169-174) are character-for-character identical except for the envelope key name and type. This is the easiest possible case — no cross-file coordination needed, just collapse to one `unwrapEnvelope<T>(raw, key: string): T` in the same file.
- **Paths (verified 2026-07-25) — CORRECTION:** both functions were located at `lib/queries/fetchers/sitepage.ts:94` and `:169` respectively (`unwrapService`, `unwrapServiceCategory`) — i.e. the fetchers file, not `lib/queries/sitepage.ts` itself as this item's heading implies. Same collapse-to-one-helper plan applies, just in the fetchers file.

### 6. Dashboard-misc "standalone section" wrapper — 19 files, config-only duplication
`products/`, `booking/`, `events/`, `menu/` each have a `layout.tsx` (metadata + `HubChildBreadcrumb` with different icon/label/href), an index `page.tsx`, a `settings/page.tsx`, and sub-pages — all wrapping `<StandaloneSectionShell title="X"><SectionComponent view="Y" /></StandaloneSectionShell>` with only the title/view differing. Verified `booking/layout.tsx` directly: its own comment says **"mirrors products/layout.tsx, events/layout.tsx"** — an explicit, self-aware copy.
- **Doctrine check (this is the one I'd have reflexively excluded, so I checked it properly):** this repo's CLAUDE.md says "copy patterns between page dirs — never cross-import another page's local files," which sounds like it forbids exactly this. But the litmus test the same doctrine gives is *"must this behave identically on another surface? → shared composite; else page-local, copy pattern."* This wrapper **does** behave identically across all 4 — the only variation is which config values get passed to already-shared components (`HubChildBreadcrumb`, `StandaloneSectionShell`, both already living in `components/shells/`). A factory extending that same shared layer isn't "cross-importing another page's file" — it's the doctrine's own shared-composite path, just not yet applied here. This is a legitimate exception to reach for, not a violation of the rule.
- **What NOT to touch:** the `workplace`/`business` pair uses a different, already-correct pattern (variant-parameterized shared body components like `_brand-page.tsx`, thin per-account-type route wrappers) — leave that alone, it's the more sophisticated version of the same idea and doesn't need this treatment.
- **Paths (verified 2026-07-25):** all 12 `products/booking/events/menu` layout/page/settings files confirmed under `app/(app)/account/(dashboard)/` [LIVE]; `HubChildBreadcrumb` → `components/shells/hub-child-breadcrumb.tsx`; `StandaloneSectionShell` → `components/shells/standalone-section-shell.tsx`; `workplace/`, `business/`, and `_brand-page.tsx` all confirmed under the same `app/(app)/account/(dashboard)/` tree.

---

## Tier 2 — Real, medium confidence

- **Multi-account selection** (`youtube-section.tsx`, `media-sections.tsx`, `link-card-sections.tsx`) — accounts-query + `activeId` state + `find(...) ?? accounts[0]` derivation repeated 3x. A `useMultiAccountSelection(slug)` hook is plausible, though platforms differ slightly in account shape so it's a smaller, less clean-cut win than Tier 1 items.
- **Safe localStorage/sessionStorage read** (`auth-session.ts`, `auth-accounts.ts`) — near-identical SSR-guard + try/catch + optional-JSON-parse pattern. (The sweep also cited `signup-draft.ts` as a third instance — skip that one, it's already flagged dead in the bloat doc, so this is really a 2-file case, weaker than first reported.)
- **Mutation error-handling wrapper** (`content-media.ts`, `site-workplace.ts`) — same `setSaving(true) → try → catch+translate+toast → finally setSaving(false)` shape, worth a shared `wrapMutationWithErrorHandling()` if a third caller shows up.
- **Data coercion import paths** (`data-parse.ts`, `coerce.ts`, `app-error.ts`) — `readString` effectively has 2-3 different "canonical" homes depending which file you ask; not urgent, but consolidating to one real source (already `lib/account/readers.ts` per the bloat doc's own findings) and having the others purely re-export would remove ongoing confusion about which import path is correct.
- **Paths (verified 2026-07-25):** all files above confirmed under `lib/` (root) or `app/(app)/account/(dashboard)/integrations/` [LIVE] as applicable.

---

## Looked at, correctly NOT a candidate (verified, not just assumed)

- **`use-google-business-connect.ts` vs `use-instagram-connect.ts`** — both explicitly comment that they deliberately mirror each other's *shape* for consistency, but two independent sweeps (this one and the earlier bloat sweep) confirm the internals genuinely differ: Google Business does Apify-driven auto-scrape polling with conflict detection; Instagram does a manual-attempt-limited poll plus an `unmatched`-links feature and a background-resume probe Google Business doesn't have. A generic wrapper covering both polling styles would be a leaky abstraction. Correct as two files.
- **`content-media.ts` vs `design-media.ts`** — surface-similar (both are "media pool" clients) but one manages two interdependent entities (library + selection) and the other manages a single image slot with polling; a shared factory would need more configuration options than the ~150-200 lines it would save. Correct as two files; revisit only if a third media client appears.
- **Components in `components/shells/`, `settings/`, `fields/`, `dialogs/`, `controls/`, `feedback/`, `toasts/`, `providers/`** — swept in full (40 files), came back with zero strong candidates. This area is already well-factored (shared composites correctly centralized, dialogs/forms correctly delegate to existing helpers). Nothing to do here.
- **The 9 design-kit preset-picker sections** (`sitepage-preset-sections.tsx`) — already properly generic; every one is a thin config wrapper around the shared `KitSelectRow` component + `useIndividualKitSelect()` hook. This is what "done right" looks like — cited here as the working example the other design-kit hooks (item 2 above) should be brought in line with.
- **Paths (verified 2026-07-25):** `use-google-business-connect.ts` → `lib/hooks/use-google-business-connect.ts`; `use-instagram-connect.ts` → `lib/hooks/use-instagram-connect.ts`; `content-media.ts` → `lib/content-media.ts`; `design-media.ts` → `lib/design-media.ts`; `sitepage-preset-sections.tsx` → `app/(app)/account/(dashboard)/design/sitepage-preset-sections.tsx`; the `KitSelectRow` component → `app/(app)/account/(dashboard)/design/sitepage-kit-select-card.tsx`; `useIndividualKitSelect()` → `app/(app)/account/(dashboard)/design/use-individual-kit-select.ts`. All confirmed.

---

## Suggested order
Items 2 and 5 are the smallest, safest, do-first wins (one explicit clone, one within-file duplicate). Item 1 (connect-flow) is the highest-value one — it's also the trickiest race-condition logic in the codebase, currently duplicated 5 ways, so consolidating it means fixing a subtle bug class in one place instead of hoping all 5 stay correct forever. Item 6 (dashboard-misc) is the biggest file-count win but touches routing chrome across 4 features — worth its own PR with a visual check on all 4 pages after. Item 4 (query factories) and item 3 (chat-engine) are both mechanical and low-risk once someone commits to the shape. Tier 2 items are genuine but lower-stakes — pick up opportunistically.

---

> **Source:** `Comet-Backend/docs/2026-07-25-backend-bloat-and-fixes.md`

# Backend bloat & fixes — 2026-07-25

Full-file inventory sweep of `app/`, `routes/`, `config/`, `database/factories/`, `resources/views/emails/`. NOT generated by `scripts/audit/audit.sh` (none of its 21 lenses cover "internal dead code" — the closest, `cross-repo-dead-code`, is cross-repo only) — this is a manual sweep, same methodology as the sibling Partna-Frontend audit: 15 parallel agents read every file in full and judged it against real usage (route tables, container bindings, dispatch call sites), not just grep. Nothing has been deleted or fixed — this is the reference doc.

Overall this codebase is unusually healthy — most of the ~1000 files audited came back completely clean. The issues below are real, but sparse relative to the size of the sweep.

---

## Fixes — real bugs, not just cleanup

### 1. Live crash: `StaffFeatureFlagOverrideController::store()` queries a column that doesn't exist
**File:** `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php:58-61`
`store()` re-fetches via `->where('user_id', $scope->userId)->whereNull('brand_id')`. The `core.feature_flag_overrides` table's `brand_id` column was replaced by `user_id` in the baseline migration (`supabase/migrations/20260526000000_baseline_standalone_user.sql`) — confirmed via the model's `$fillable` and the sibling `FeatureFlagService`, neither of which reference `brand_id` at all. **This will throw a Postgres "column does not exist" error on every `store()` call.** Highest-priority item in this whole sweep.
- **Paths (verified 2026-07-25):** `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php` [LIVE] — confirmed.

### 2. Two Staff controllers missing authorization entirely
- `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php` — all 4 methods (`index`/`store`/`update`/`destroy`) have zero `authorizeForUser` calls, unlike every sibling admin controller in the directory.
- `app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php` — uses inline `abort_if($request->attributes->get('partna_staff') === null, 401, ...)` in all 3 methods instead of `authorizeForUser` (the exact inline-check pattern the doctrine forbids — this only checks "is staff," not a policy ability).

Not currently exploitable (route middleware still requires the `staff` role), but a real, consistent doctrine deviation from how every other staff controller in the codebase does this.
- **Paths (verified 2026-07-25):** both files confirmed [LIVE] at their stated paths.

### 3. Four more individual methods missing authorization (files otherwise correct)
- `StaffSite/StaffAccountDeletionController.php::show()` — its siblings `initiate()`/`cancel()` both authorize; `show()` doesn't.
- `StaffSite/StaffEmailSubscriberController.php::index()` — exposes subscriber PII (email/name); its sibling `export()` authorizes, `index()` doesn't. Also carries stale "brand"/pre-pivot terminology in its docblock (see Stale comments below).
- `StaffSite/StaffNotificationController.php::markReadForProfessional()` + `::dismissForProfessional()` — both skip `authorizeForUser` while `store()`/`index()` in the same file explicitly add it "matching the other staff write controllers" per their own comments.
- **Paths (verified 2026-07-25):** all 3 files confirmed under `app/Http/Controllers/Api/Staff/StaffSite/` [LIVE].

### 4. Feature-parity gap: staff can't multi-category a service, users can
**Files:** `app/Http/Requests/Api/Staff/UserSite/Services/{StaffStoreServiceRequest,StaffUpdateServiceRequest}.php`
A migration 3 days before this audit (`20260721180000_service_multi_category.sql`) dropped `site.services.category_id` for a multi-category pivot table. The parallel **professional-facing** Requests were updated (`category_ids` array + a dedicated re-assignment endpoint). The **staff-facing** ones were not — no `category_ids` field, no staff equivalent route. Staff can only ever assign one category via the legacy field; professionals can assign several.
- **Paths (verified 2026-07-25):** both files confirmed at their stated paths [LIVE].

### 5. `typography.weight` design-kit field is silently dropped on validation
**File:** `partna-monorepo/packages/design-system/src/design-kit/validate.ts:103-112`
`types.ts` declares `typography.weight?: 'light'|'regular'|'bold'` and `apps/pages`'s CSS emitter actively reads it — but the Zod validation schema has no `weight` field. Zod silently strips unrecognized keys by default, so any inbound JSON setting this field is dropped by `validateDesignKit`. Cross-repo note: this is in the monorepo, listed here because it's a Fix, not filler.
- **Paths (verified 2026-07-25):** cross-repo — see the matching, separately-verified entry in the partna-monorepo section below.

### 6. Charlie's notification-preference tool bug — backend confirms the fix (cross-repo)
The sibling Partna-Frontend audit found Charlie's `settings_set_notification_preference` tool hardcodes stale categories. This sweep confirmed the ground truth directly from `config('partna.notifications.mailables')` (`config/partna.php:1678-1694`): the real 9 categories are `feature_announcement, incident, inbox, policy_update, profile_tasks, achievement, platform_connection, content_scrape, analytics_weekly`. `achievement` and `analytics_weekly` are real and live; `subscriptions` and `analytics_milestones` don't exist as categories at all (confirmed: `subscriptions` survives only in a stale comment referencing the removed commerce/brand system). No backend change needed — the frontend fix should map to `achievement`/`analytics_weekly`.
- **Paths (verified 2026-07-25):** `config/partna.php` confirmed (2176 lines total, notification mailables section present).

---

## Ready to delete — confirmed dead

- `app/Services/Platforms/Strategies/Refresh/OnDemandRefresh.php` — self-documented dead elsewhere in the codebase ("dead code (no constructor references anywhere)"); zero references anywhere including tests.
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD] — sits in the normal live `app/Services/` tree, not archived or excluded.
- `app/Services/Platforms/Strategies/Fetch/NoFetch.php` — only reached by its own unit test; every link-only platform registers without a `->fetch()` call at all, so this is never constructed in production.
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD].
- `app/Services/Http/ParsedUrl.php` — built for a URL-resolver spec that ended up implemented a different way; zero production callers.
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD].
- `app/Http/Controllers/Api/Platforms/AppleController.php` — 6 dead public methods (`musicSelection`, `musicAccounts`, `removeMusicAccount`, `podcastSelection`, `podcastAccounts`, `removePodcastAccount`). The routes were migrated to `GenericPlatformController` (the route file's own comments document this: "music reads → generic"); the old methods were never removed.
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD] — file itself stays, only the 6 methods go.
- `app/Http/Requests/BaseFormRequest.php::allowedRedirectRule()` — Stripe-era leftover (docblock references "Stripe Checkout, Connect Express, SetupIntent"), zero call sites anywhere. **Independently found by 3 separate audit agents** — high confidence.
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD] — file stays, only this method goes.
- `app/Http/Resources/UserPublicResource.php` — zero references outside its own isolated unit test; no controller ever returns this shape.
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD].
- `app/Http/Resources/Platforms/EventbriteConnectionResource.php` + `HumanitixConnectionResource.php` — both still *registered* in `PlatformRegistryServiceProvider`, but `EventbriteController`/`HumanitixController` bypass the registry's resource mechanism entirely and hand-build response arrays instead. The registration is inert.
  - **Paths (verified 2026-07-25):** both confirmed [LIVE-IN-BUILD].
- `app/Support/Money.php` — has a full unit-test suite but zero application call sites; `ServiceResource.php` returns raw unformatted cents, confirming nothing adopted this helper.
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD].
- `feature()` global helper (`app/helpers.php`) — documented as "the pattern to use" for tenant-aware flag checks, has its own test, but zero production callers (everything goes through `FeatureFlagService`/`FeatureGate` directly instead).
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD] — file stays, only this helper goes.
- `config/partna.php:1727-1750` — the `'features' => ['smart_booking', 'square_sync', 'fresha_sync']` block. Confirmed dead three ways: no route applies `FeatureGate` with these flags; `Site::BOOKING_MODES` has no `'smart'` value anymore (nothing left to gate); Fresha/Square now run through the unconditional platform registry. One test literally comments "(feature dropped)."
  - **Paths (verified 2026-07-25):** `config/partna.php` confirmed [LIVE-IN-BUILD] — same file checked directly for Fix 6 above; file stays, only this block goes.
- `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php::visibility()` — self-documented: *"NB: this method is not currently wired to a route... the gate is here so the Authorization Doctrine holds if it is ever routed."* The live visibility toggle is `SiteVisibilityController::update`.
  - **Paths (verified 2026-07-25):** `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php` confirmed [LIVE-IN-BUILD] (cross-confirmed separately via the inheritance doc's item 10 below) — file stays, only this method goes.
- Two dead DB columns, both self-documented in their Model's own docblock as unused: `site.site_media.product_gid` ("Legacy Shopify product-link column... not referenced by any current application code") and `core.email_subscriptions.qr_slug` ("not currently written or read by any app code").
  - **Paths:** N/A — these are database columns, not file paths; not in scope for the path-verification pass.

---

## Needs a decision — not a mechanical delete

### CSAM auto-action moderation subsystem appears fully dormant
`ModerationDecisionService::decideAsSystem()`, `ModerationActionDispatcher`'s `csam_auto_suspend` branch, and two config kill-switches (`partna.moderation.enabled`, `partna.moderation.auto_actions_enabled`) are fully built and unit-tested, but **nothing in the codebase ever creates a `csam_match` case** — no detection entry point exists anywhere. Could be intentional scaffolding ahead of an upcoming CSAM-scanning integration, or a real gap. Given the subject matter, this needs a conversation with whoever owns Trust & Safety, not an assumption either way.
- **Paths (verified 2026-07-25):** `ModerationDecisionService` → `app/Services/Moderation/ModerationDecisionService.php`; `ModerationActionDispatcher` → `app/Services/Moderation/ModerationActionDispatcher.php`. Both confirmed.

### `FeatureGate` middleware — registered, zero routes use it
Alias `feature` exists in `bootstrap/app.php`, has its own test, but `grep`ing every route file for `feature:` returns zero hits. Its own docblock frames it as launch-gate infra (`->middleware('feature:smart_booking')`) — may be intentionally idle between launches rather than dead.
- **Paths (verified 2026-07-25):** `bootstrap/app.php` confirmed.

### Two controllers may be architecturally unreachable
`PublicMarketingPreferenceController` and `PublicSiteController::show()` are registered only under a domain-based route group (`{subdomain}.partna.au`), but production traffic to that domain is served entirely by the Cloudflare Worker/Astro app — the Laravel backend lives on separate `api.partna.au` hostnames. Every other public write endpoint explicitly works around this with header-based tenant resolution instead. `PublicMarketingPreferenceController` has no test coverage at all (weak signal it's dead); `PublicSiteController::show()` has a dedicated, actively-maintained test (weaker signal — might be deliberately kept, or might be test debt). Worth a conversation with whoever owns the Cloudflare/DNS routing before touching either.
- **Paths (verified 2026-07-25):** `PublicMarketingPreferenceController` → `app/Http/Controllers/Api/PublicSite/PublicMarketingPreferenceController.php`; `PublicSiteController` → `app/Http/Controllers/Api/PublicSite/PublicSiteController.php`. Both confirmed.

### Three intentional YAGNI seam interfaces (not orphans)
`app/Services/Platforms/Strategies/Contracts/{ApiKeyConnect,OAuthConnect,WebhookRefresh}.php` — all three explicitly documented as empty-on-purpose extension points for future platform types ("Intentionally EMPTY... do not add a concrete implementation here"). `OAuthConnect` is even pinned by a test asserting zero implementations exist. Not bloat — flagged only so nobody mistakes them for orphans.
- **Paths (verified 2026-07-25):** all 3 confirmed under `app/Services/Platforms/Strategies/Contracts/`.

---

## Consolidation candidates (duplicated logic, not dead code)

- `socialUsername()` — byte-identical 24-line private method duplicated between `GoogleBusinessAutoSync.php` and `InstagramAutoSync.php`. Both classes already extracted their *other* shared logic into `Concerns/BuildsAutoSyncFindings` — this one method was missed.
  - **Paths (verified 2026-07-25):** `GoogleBusinessAutoSync.php` → `app/Services/Platforms/GoogleBusinessAutoSync.php`; `InstagramAutoSync.php` → `app/Services/Platforms/InstagramAutoSync.php`; `Concerns/BuildsAutoSyncFindings` → `app/Services/Platforms/Concerns/BuildsAutoSyncFindings.php`.
- `absolutize()` (URL-resolving helper) — 4 near-identical copies across `WebsiteScan/{FaviconFetcher,PdfLinkDetector,WebsiteGalleryCandidateExtractor,WebsiteLogoCandidateExtractor}.php`. One copy was patched 2026-07-23 for protocol-relative URLs; the other 3 weren't — a real inconsistency, not just duplication.
  - **Paths (verified 2026-07-25):** all 4 confirmed under `app/Services/WebsiteScan/`. See also the fuller, corrected version of this finding in the Comet-Backend inheritance doc's item 1 below (2 algorithm families, not 1).
- `reorderLayout()` — ~165 lines of category/service validation + sort-order math + junction-table diffing, copy-pasted verbatim between `Api/Staff/UserSiteManagement/StaffServiceManagementController.php` and `Api/User/SiteManagement/UserServiceController.php`, instead of living in one shared Service (the pattern every other complex write in these files uses). `UserUploadController::reorder()` has a third occurrence of the same "two-pass sort_order park" trick, worth folding into the same consolidation.
  - **Paths (verified 2026-07-25):** `StaffServiceManagementController.php` → `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php`; `UserServiceController.php` → `app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php`; `UserUploadController.php` → `app/Http/Controllers/Api/User/Uploads/UserUploadController.php`.
- `AiSpendBudget`/`ApifyBudget`/`PlacesBudget` — near-identical atomic daily-cap-with-rollback logic, three times. Each docblock says this is deliberate ("kept as its own class... not because the mechanism differs") — judgment call, not a bug.
  - **Paths (verified 2026-07-25):** all 3 confirmed under `app/Services/Cache/`.
- Several near-duplicate Form Request pairs (low-priority, Laravel's one-Request-per-endpoint idiom is a reasonable justification for most): `FreshaEmployeeServicesRequest`/`SaveFreshaSelectionRequest` (identical employeeId rule), the reorder-ids family (`ReorderServiceCategoryRequest`/`ReorderServiceRequest`/`ReorderGalleryImageRequest`/`ReorderBlocksRequest` — last one also missing `required`+`distinct` the other three have), `SetContentGooglePhotosRequest`/`SetContentInstagramAutoRequest` (identical toggle body), `StaffForceDestroyRequest`/`StaffInitiateDeletionRequest` (identical rules, drifted wording).
  - **Paths (verified 2026-07-25):** `FreshaEmployeeServicesRequest.php`/`SaveFreshaSelectionRequest.php` → `app/Http/Requests/Platforms/`; the reorder family → `app/Http/Requests/Api/User/Services/` (Category/Service), `app/Http/Requests/Api/User/ImageGallery/` (Gallery Image), `app/Http/Requests/Api/User/Site/` (Blocks); `SetContentGooglePhotosRequest.php`/`SetContentInstagramAutoRequest.php` → `app/Http/Requests/Api/User/Content/`; `StaffForceDestroyRequest.php`/`StaffInitiateDeletionRequest.php` → `app/Http/Requests/Api/Staff/`. All confirmed.
- 3 controllers with business logic that should be in a Service instead: `StaffNotificationEmailPolicyController`, `NotificationEmailPreferenceController` (both implement the same 4-tier notification-precedence cascade via raw SQL, independently).
  - **Paths (verified 2026-07-25):** `StaffNotificationEmailPolicyController.php` → `app/Http/Controllers/Api/Staff/StaffSite/`; `NotificationEmailPreferenceController.php` → `app/Http/Controllers/Api/User/Notifications/`. Both confirmed.
- `GalleryImageResource`/`SiteMediaResource`/`ContentLibraryUploadResource` shape the same `SiteMedia` model three overlapping-but-inconsistent ways; `LinkBlockResource`/`SectionBlockResource` do similarly for `Block` — each pair/trio is wired to a genuinely distinct controller, so not dead, just worth a look.
  - **Paths (verified 2026-07-25):** `GalleryImageResource.php`, `SiteMediaResource.php`, `LinkBlockResource.php`, `SectionBlockResource.php` → `app/Http/Resources/`; `ContentLibraryUploadResource.php` → `app/Http/Resources/Content/`. All confirmed.

---

## Dead validation (harmless, but pointless)

3 analytics Form Requests (`Analytics/{ItemSeenRequest,ActionSeenRequest,ActionTapRequest}.php`) validate `utm_source`/`utm_medium`/`utm_campaign` as nullable strings, but `PostgresEventWriter` never persists them — the underlying tables genuinely have no `utm_*` columns. Likely copy-paste from a sibling Request that does use them. Optional/nullable, so nothing breaks — just validation for data that's silently dropped.
- **Paths (verified 2026-07-25) — CORRECTION:** the 3 files live at `app/Http/Requests/Api/PublicSite/Analytics/{ItemSeenRequest,ActionSeenRequest,ActionTapRequest}.php` — the fuller path, not the bare `app/Http/Requests/Analytics/...` this item implies. `PostgresEventWriter` → `app/Services/Analytics/Writers/PostgresEventWriter.php`. All confirmed. (Same correction applies to item 9 in the Comet-Backend inheritance doc below.)

---

## Stale comments & pre-pivot terminology fossils (no functional impact — batched, fix opportunistically)

All confirmed via grep to have zero remaining functional relevance — these are documentation-only, referencing removed features/entities/repos. Grouped by what they reference:

**References the removed 3-account-type / "Brand" / "affiliate" / "partner" system** (current model: 2 types, `partna`/`business`, gated via `AccountCapabilities`):
- `app/Jobs/Notifications/SendEnquiryNotificationJob.php:19-20` — "applies to all 3 account types (brand, partner, individual)"
- `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:44-49` — vestigial `CAPABILITY_GATE_MAP` explicitly tied to the removed system (permanently empty array, self-documented as intentional)
- `app/Http/Requests/Api/Staff/Notifications/StaffStoreNotificationRequest.php:47-49` — cites "commissions"/"payouts" as reserved categories that don't exist
- `app/Http/Requests/Api/User/Services/UpdateServiceRequest.php:8` — "affiliate UX" wording
- `app/Http/Requests/Api/User/Site/UpsertWorkplaceRequest.php:61` + `UpsertSectionBlockRequest.php:55-59` — "Brand Info editor" / config path drift
- `app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php:16-18` — "a brand's marketing-list subscribers... leaking to brands"
- `app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php:18` — class docblock says "Account-type restrictions apply," directly contradicted by the method beneath it (which correctly has none)
- `app/Services/Site/RenameSubdomainAction.php:126` — cites `HydrogenAffiliateController`, which doesn't exist (Partna-Hydrogen is archived)
- `app/Services/Site/UpdateSiteAction.php:69` — cites `commerce.affiliate_product_selections`, a table that doesn't exist
- `config/partna.php:912-913` — `contact_subject_defaults` comment says "the affiliate's settings... Affiliates can extend but not remove"
- `config/partna.php:1656-1659` — cites `FanOutBrandStatusNotificationJob`, which doesn't exist
- `config/partna.php:1756-1759` — GDPR section docblock describes "Shopify GDPR webhook handlers" and `RedactShopJob`, neither of which exist (the config keys themselves are correctly used by the real standalone-user GDPR system)
- `config/services.php:75` (+ same phrase in `app/Services/Cloudflare/CloudflareKvService.php:10`) — "brands vs affiliate redirects," current model is `{type:"individual"}`/`{type:"alias"}`

**References archived Partna-Hydrogen or removed "themes" architecture:**
- `app/Models/Analytics/SectionView.php:13` — "Partna-Hydrogen's IntersectionObserver"
- `app/Observers/Core/SiteMediaObserver.php:76` — "Hydrogen affiliate path for partners"

**References Shopify (the removed Partna-owned integration, not the current "link your own external store" feature):**
- `config/nightwatch.php:25` — "Shopify tokens" in a PII-redaction comment

**Pre-rename "Sidest" → "Partna" fossils:**
- `app/Services/Notifications/NotificationPublisher.php:23` — cites `config/sidest.php`, which doesn't exist (real source is `config('partna.notifications.mailables')`)

**Stale "test-mode, no auth" docblocks** (all 3 controllers are fully authenticated/persistent in reality — this was prototype-phase framing never updated after promotion to production):
- `app/Http/Controllers/Api/Platforms/AppleController.php:21`, `FreshaController.php:27-36`, `SkoolController.php:15`

**Other one-off stale comments/docblocks (low priority):**
- `app/Services/Diagnostics/EnvCheckService.php:12` — wrongly claims Fresha/Square "integrations dropped" (both are live)
- `app/Services/FeatureFlags/FeatureFlagService.php:18-22` — numbering gap in a "resolution order" list (references a removed tier)
- `app/Services/Analytics/TrackableBlockTypes.php:8-9` — overclaims a consumer (`AnalyticsQueryService::topSections`) that no longer reads it
- `app/Http/Requests/Api/User/Feedback/SubmitFeedbackRequest.php:10-12` — cites a superseded migration (the field now has a DB CHECK the comment says it doesn't)
- `app/Models/Core/MediaVariant.php:14` — docblock says `core.media_variants`, actual table is `site.media_variants`
- `config/queue.php:43,74-75` — cites `RebuildProfessionalHourlyAggregatesJob`/`RebuildBrandHourlyAggregatesJob`, neither exists
- `app/Services/Media/MediaUploadService.php:249-268` — a docblock is physically glued above the wrong method (refactor artifact)
- `app/Services/Media/UnprocessableImageException.php` — lives outside the `Exceptions/` subfolder its 6 siblings use (cosmetic)
- `app/Mail/HandleAliasExpiringMail.php` — renders from `resources/views/mail/` instead of the `emails/` convention every sibling uses (organizational, not a bug)
- **Paths (verified 2026-07-25):** every file cited across all of the above groups confirmed present at its stated path — no corrections needed for this section.

---

## Suggested order
Fix items 1-4 first (the live crash and the missing-auth gaps are the highest-stakes items in this whole sweep, all localized to the Staff FeatureFlag/notification controllers). Fix 5 (design-kit validation) and item 6 (cross-repo notification categories) are quick, separate. The "Ready to delete" list is a safe mechanical batch. The three "Needs a decision" items should go to their respective owners before anyone touches them — especially the CSAM one. Stale comments are opportunistic — fix when touching the file for something else, not worth a dedicated pass.

---

> **Source:** `Comet-Backend/docs/2026-07-25-inheritance-opportunities.md`

# Backend inheritance & consolidation opportunities — 2026-07-25

Different lens from the bloat sweep: this is about **live, correct code** that's duplicated across 2+ files, where a shared base class/trait/service would mean editing one place instead of several. Nothing here is dead or wrong — it's a maintainability investment.

**Method:** 7 Haiku agents swept the areas requested (design kit, integrations, scraping, PublicSite, menu, ecommerce, URLs/links) for candidates. Every candidate below was then independently re-verified by reading the actual files — a few reported as "near-identical" turned out to have real, non-obvious differences worth knowing before merging them (flagged explicitly where that happened). Nothing has been implemented — this is the reviewed, planned list for whoever picks it up.

---

## Tier 1 — High confidence, verified, clear win

### 1. URL `absolutize()` — consolidate 4, keep 1 separate (verified, correction to the raw sweep)
**The sweep found 6 near-identical copies; on inspection there are actually two different algorithms, not one:**

- **"Slap onto domain root" family (4 files, genuinely mergeable):** `app/Services/WebsiteScan/FaviconFetcher.php`, `PdfLinkDetector.php`, `WebsiteGalleryCandidateExtractor.php` (all three lack a protocol-relative fix and currently mangle `//cdn.example.com/x`-style URLs — a live bug per the 2026-07-23 fix note in the fourth file), and `WebsiteLogoCandidateExtractor.php` (has the fix). `app/Services/Http/MetadataParser.php::absolutize()` is a public method with the same shape *and* the fix already applied — it can serve as the canonical implementation the other three call into, fixing their bug in the same change.
- **"Directory-aware" algorithm (stays separate):** `app/Services/Platforms/WebsiteLinkHarvester.php::absolutize()` correctly resolves genuinely-relative hrefs against the base URL's directory path (`dirname($parts['path'])`) and explicitly rejects non-http schemes (`mailto:`, `tel:`, `data:`). The other family just anchors any non-absolute path to the domain root — fine for favicons/logos/gallery images (which are almost never deeply relative), wrong for arbitrary page links. **Do not merge this one in** — it solves a genuinely different problem correctly.

**Action:** make the 3 buggy WebsiteScan extractors call `MetadataParser::absolutize()` (or extract its logic to a tiny shared `UrlAbsolutizer` service) instead of their own copies — fixes a real bug and removes ~60 lines of duplication in the same change. Leave `WebsiteLinkHarvester` alone.
- **Paths (verified 2026-07-25):** all 4 WebsiteScan files confirmed under `app/Services/WebsiteScan/`; `MetadataParser.php` → `app/Services/Http/MetadataParser.php`; `WebsiteLinkHarvester.php` → `app/Services/Platforms/WebsiteLinkHarvester.php`. All confirmed.

### 2. Reservation-provider Connect classes + Resources (6 files → could become ~2)
`Strategies/Connect/{NowBookitConnect,OpenTableConnect,ResDiaryConnect}.php` share an identical resolve-URL → extract-identifier → return-ConnectResult shape, differing only in field names (`accountId+venueId` vs `rid` vs `microsite`) and which service they call. Their matching `Http/Resources/Platforms/{NowBookit,OpenTable,ResDiary}ConnectionResource.php` are the same story on the response side. A single field-name-configurable class/resource pair would replace 6 files with 2.
- **Paths (verified 2026-07-25):** all 3 Connect classes confirmed under `app/Services/Platforms/Strategies/Connect/`; all 3 Resources confirmed under `app/Http/Resources/Platforms/`.

### 3. `BandcampConnectionResource` should extend `TileConnectionResource` like its siblings (inconsistency, not just duplication)
`YoutubeConnectionResource` and `AppleMusicConnectionResource` both correctly extend `TileConnectionResource` (the shared base for "flat fields + latest + highlights" shape) and only implement `flatFields()`. `BandcampConnectionResource` has the identical output shape but extends `ApiResource` directly and hand-builds the whole `toArray()` — it's not that no shared base exists, it's that this one file doesn't use the one that already does.
- **Paths (verified 2026-07-25):** all 4 (`YoutubeConnectionResource.php`, `AppleMusicConnectionResource.php`, `BandcampConnectionResource.php`, `TileConnectionResource.php`) confirmed under `app/Http/Resources/Platforms/`.

### 4. Shop scrapers — extract a `ShopScraper` intermediate base (5 files)
`ShopifyScraper`, `WooCommerceScraper`, `BigCartelScraper`, `GenericShopScraper` (+ `SquarespaceScraper`, structurally the same family) all define an identical `MAX_IMAGES = 25` constant and 3 of them have a byte-for-byte identical private `json()` fetch-and-decode helper. An intermediate `ShopScraper extends PlatformScraper` holding both would mean the safety threshold and the null-guard logic are edited once, not 3-5 times.
- **Paths (verified 2026-07-25):** all 6 scrapers (including `PlatformScraper.php`) confirmed under `app/Services/Platforms/`.

### 5. Menu helper trio — pull into the existing `NormalizesMenuData` trait (4-2-2 files)
Three genuinely byte-identical helpers scattered outside the trait that's supposed to hold exactly this kind of thing:
- `cleanString()` — identical in `NormalizesMenuData.php`, `MenuAiExtractor.php`, `MenuScanApplier.php`, `MenuContentController.php` (4 copies; the trait already has one, the other 3 should just use it).
- `normalizeName()`/`norm()` — identical in `MenuMerger.php` and `MenuContentController.php`, and a comment in the latter admits it must stay "IDENTICAL to MenuFetchJob::normalizeName so a suppressed dish matches at rebuild time" — i.e. the duplication is already known to be load-bearing and fragile.
- `nextPosition()` — identical in `MenuScanApplier.php` and `MenuContentController.php`.
- **Paths (verified 2026-07-25):** `NormalizesMenuData.php`, `MenuAiExtractor.php`, `MenuScanApplier.php`, `MenuMerger.php` → confirmed under `app/Services/Platforms/`; `MenuContentController.php` → `app/Http/Controllers/Api/Platforms/MenuContentController.php`; `MenuFetchJob.php` → `app/Jobs/Platforms/MenuFetchJob.php`. All confirmed.

### 6. Shop Request URL normalization (2 files)
`AddShopBrandRequest.php` and `AddShopProductRequest.php` have an identical `prepareForValidation()` block normalizing the `url` field via `PlatformInput::urlish()`. A tiny `NormalizesUrlField` trait (or a method on a shared shop-request base) replaces both.
- **Paths (verified 2026-07-25):** both Requests confirmed under `app/Http/Requests/Platforms/`; `PlatformInput.php` confirmed under `app/Services/Platforms/`.

### 7. Shop seeder tombstone-check + lock-and-upsert pattern (2 files)
`ShopBrandSeeder.php` and `ShopProductSeeder.php` share an identical "check if the connection was soft-deleted, bail if so" block, and a near-identical `Cache::lock() → updateOrCreate IntegrationConnection with a MARKER → mutate the model → refresh cache` sequence (both docblocks already say "same convention as EventsSeeder"). A `PlatformSeederBase` with `checkTombstone()` and `acquireConnectionLock()` helpers would make this one place instead of two, and would be ready for the next seeder that needs the same shape.
- **Paths (verified 2026-07-25):** all 3 (`ShopBrandSeeder.php`, `ShopProductSeeder.php`, `EventsSeeder.php`) confirmed under `app/Services/Platforms/`.

### 8. Public form spam-detection (honeypot + timing check) — 4 controllers
`PublicCustomerLeadController`, `PublicEnquiryController`, `PublicEmailSubscriptionController`, `PublicEarlyAccessController` all independently implement the identical honeypot check (`$data['website']` non-empty → fake success) and timing check (`form_started_at_ms` delta vs. `config('partna.form_timing.min_ms'/'max_ms')`), down to the same log event names (`honeypot_hit`, `too_fast`). A `GuardsAgainstFormSpam` trait with `assertHoneypot()`/`assertFormTiming()` centralizes both the logic and the timing-window consistency across all 4 public entry points.
- **Paths (verified 2026-07-25):** all 4 confirmed under `app/Http/Controllers/Api/PublicSite/`.

### 9. Analytics Request base class — 8 files
`Analytics/{ActionSeenRequest,ActionTapRequest,ClickRequest,ItemSeenRequest,PageviewRequest,PingRequest,SectionDwellRequest,SectionSeenRequest}.php` — verified directly (`ActionSeenRequest` read in full): every one uses the `ResolvesPublicSiteSubdomain` trait, the identical `prepareForValidation()` one-liner, and the identical `site_id`/`subdomain`/`session_id`/`visitor_id`/`referrer`/`utm_*` rule block. Only the 1-2 event-specific fields differ per class. A `BaseAnalyticsRequest` holding the shared rules as a method subclasses call into `array_merge()` with their own fields would cut ~30 duplicated lines × 8.
- Note: `ActionTapRequest`'s own comment explicitly says it's "kept as its own class... so the two beacons' validation can diverge independently" — this is about NOT sharing the *class itself* with `ActionSeenRequest ` specifically, not an objection to extracting the common *rules* into a base both can still subclass independently. Doesn't block this.
- **Paths (verified 2026-07-25) — CORRECTION:** all 8 files live at `app/Http/Requests/Api/PublicSite/Analytics/...`, not the bare `app/Http/Requests/Analytics/...` this item's heading implies (same correction as the Dead Validation section in the bloat doc above). `ResolvesPublicSiteSubdomain` trait → `app/Http/Requests/Concerns/ResolvesPublicSiteSubdomain.php`. All confirmed.

### 10. Design-kit "write + invalidate" transaction pattern (2 call sites today, a 3rd likely coming)
`UserSiteController::writeDesignKit()` and `WebsiteScan/DesignKitAccentApplier::apply()` both do transaction → row lock → `updateOrInsert()` → cache invalidation against the same `design_kits` table. A `WriteDesignKitAction` service handling the transaction/lock/upsert/invalidation choreography would let each caller keep its own pre-processing (column-filtering for the controller, fill-if-empty guard for the auto-accent applier) while sharing the risky part (the transactional write itself).
- **Paths (verified 2026-07-25):** `UserSiteController.php` → `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php`; `DesignKitAccentApplier.php` → `app/Services/WebsiteScan/DesignKitAccentApplier.php`. Both confirmed.

---

## Tier 2 — Real, medium confidence (worth doing, less urgent)

- **Highlights `apply()` pattern** — `VimeoHighlights.php` and `YoutubeMusicHighlights.php` share an identical collection-based apply() shape (`keyBy → map → filter → take(MAX) → values`); `YoutubeHighlights`/`BandcampHighlights` already share a `RefreshesLatestTile` trait for an equivalent-but-differently-shaped result. Worth a trait for the Vimeo/YoutubeMusic pair specifically — don't force all 4 into one shape, they're two genuinely different result contracts.
- **Apple Music/Podcast Fetch** — `AppleMusicFetch`/`ApplePodcastFetch` share the same "call scraper → extract latest → merge payload → update flat fields" shape, differing only in method names and which flat fields update. A parameterized base is plausible but touches fewer files (2) than the items above.
- **Event scraper `parseEvent`/`parseEventNode`** — `EventbriteScraper`/`HumanitixScraper` share ~40 lines of JSON-LD field-mapping, and `HumanitixScraper`'s own comment says it "mirrors EventbriteScraper::parseEvent." A shared `PlatformScraper::parseEventNode()` would centralize the event-shape contract these two already agree to keep in lockstep.
- **Menu `resolveMenu()`** — `MenuScanApplier`/`MenuContentController` share a near-identical find-or-create-Menu pattern (only difference: one passes a `$source` param). A small `MenuResolver` service handles both.
- **Customer upsert (lookup + restore + conditional update)** — `PublicCustomerLeadController`, `PublicEnquiryController`, `PublicEmailSubscriptionController` each independently implement the same email-normalize → find-with-trashed → restore-if-trashed → conditional-name-update → create-if-missing sequence, differing only in the `source` value written. A `PublicCustomerUpsertService::upsertByEmail(userId, email, fullName, source)` would serve all three.
- **Lead-submission logging** — `PublicCustomerLeadController`/`PublicEnquiryController` both build near-identical `LeadSubmission` rows (ip_hash, user_agent, referrer via the same `AnalyticsEventSanitizer` calls). A small `LogsLeadSubmissions` trait saves ~20 lines × 2.
- **Menu category/item Request pairs** — `Create/UpdateMenuCategoryRequest` share an identical `name` rule; `Create/UpdateMenuItemRequest` share most fields, differing mainly in `required` vs `sometimes`. A shared base or trait for the common rule set, with Create/Update layering their own required-ness, is a reasonable but lower-stakes cleanup.
- **Paths (verified 2026-07-25):** Highlights files → `app/Services/Platforms/Strategies/Highlights/`; `RefreshesLatestTile` → `app/Services/Platforms/Concerns/RefreshesLatestTile.php`; Fetch classes → `app/Services/Platforms/Strategies/Fetch/`; scrapers/MenuScanApplier/MenuContentController → same paths as items 4-5 above; Public controllers → `app/Http/Controllers/Api/PublicSite/`; `AnalyticsEventSanitizer.php` → `app/Services/Analytics/`; Menu Request pairs → `app/Http/Requests/Platforms/`. All confirmed.

---

## Looked at, correctly NOT a candidate (verified, not just assumed)

- **Shop constants scattered across 3 files** (`MAX_BRANDS`, `CATALOG_TTL_MINUTES`, the `MARKER` array, `INDIVIDUAL_BRAND_ID`) — each docblock already says "keep in lockstep" and the duplication is a handful of named constants, not logic. Centralizing into `config/partna.php` is possible but adds config-typo risk for very little payoff versus the current self-documented convention. Low priority, not "don't do," just not worth the churn right now.
- **`PublicMenuController`'s `numberOrNull`/`textOrNull`** vs. `MenuController`'s versions — the code's own comment says this duplication is *intentional*: "Mirrors MenuController — kept local, duplication over a shared dependency," specifically to avoid `PublicMenuController` (public-facing, security-sensitive) depending on the authenticated dashboard controller. Correct as-is.
- **`FeatureGate` middleware / YAGNI seam interfaces** (`ApiKeyConnect`, `OAuthConnect`, `WebhookRefresh`) — these aren't duplication candidates at all, they're deliberately-empty extension points already covered in the bloat-and-fixes doc's "needs a decision" section.
- **Paths (verified 2026-07-25):** `PublicMenuController.php` → `app/Http/Controllers/Api/PublicSite/`; `MenuController.php` → `app/Http/Controllers/Api/Platforms/`. Both confirmed.

---

## Suggested order
Items 1, 3, and 9 are the best win-per-effort (a real bug fix bundled in, a one-line inconsistency fix, and the biggest single file-count reduction, respectively). Items 2, 4, 5, 6, 7, 8, 10 are all genuine, similar-effort wins — batch them as a dedicated "consolidation" PR separate from any bloat-deletion PR, since these touch live behavior and deserve their own test-pass rather than riding along with deletions.

---

> **Source:** `partna-monorepo/docs/2026-07-25-monorepo-bloat-and-fixes.md`

# Monorepo bloat & fixes — 2026-07-25

Full-file inventory sweep of `packages/design-system/` and `apps/pages/` (234 files total). Same methodology as the sibling Partna-Frontend and Comet-Backend audits — every file read in full, judged against real usage (import graphs, route reachability, build config), not just grepped. Nothing deleted or fixed — reference doc only.

---

## Ready to delete — confirmed dead

### Whole directories/files
- **`packages/design-system/_archive/`** (116 files, 548K) — present since the monorepo's very first commit, explicitly excluded from `tsconfig.json` (`"exclude": [..., "_archive"]`), zero live references. Contains subdirectories literally named `brand/` and `theme-1/` — matching already-confirmed-removed features (the old brand account type, the old theme picker). Bigger than the entire live `src/` (20 files). Ready to delete outright.
  - **Paths (verified 2026-07-25):** confirmed at `packages/design-system/_archive/` — **[ALREADY ARCHIVED/EXCLUDED]**, explicitly excluded via `tsconfig.json`. Not part of anything currently built.
- **`apps/pages/supabase/.temp/`** (8 files) — pure Supabase CLI link-state cache from a one-off `supabase link` run in the wrong repo. `apps/pages` has no Supabase dependency at all and zero code references anything in this folder; per doctrine, Supabase belongs to Comet-Backend, not this Astro app. Tracked since the initial monorepo commit, untouched since. Also leaks low-sensitivity infra metadata (dev project ref, pooler hostname) that has no reason being committed.
  - **Paths (verified 2026-07-25):** confirmed at `apps/pages/supabase/.temp/` — **[ALREADY ARCHIVED/EXCLUDED]**, dot-prefixed CLI runtime state, not part of anything currently built.
- **`packages/design-system/src/index.ts`** — the package's `exports` map in `package.json` has no `"."` entry at all (only `./design-kit`, `./engines`, `./renderers`, `./design-assets`), so this file is unreachable by any consumer via any import path. Vestigial placeholder (`export {};`).
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD] — sits in the compiled `src/` tree; unreachable via the package's public exports map, but not excluded from the build itself.
- **`packages/design-system/src/renderers/pdf.ts`** (247 lines) — zero consumers anywhere; `apps/pages` only ever links out to a PDF download URL, never renders inline. Its own header says it "replaces the legacy theme-1 `<PdfDocument>` React component" — built ahead of an inline-preview feature that was never wired up.
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD] — actually exported via `package.json`'s `./renderers` entry.
- **`packages/design-system/src/brand/fonts/`** (49 `.woff2` files across 5 font families) — zero references from any `.ts`/`.tsx`/`package.json`/`tsconfig.json`. The real, wired font files for all 7 live fonts sit in the parallel `src/design-assets/fonts/` directory instead. 3 of the 5 families here (`nb-architekt`, `neue-haas-grotesk`, `swiss-721`) are confirmed-retired fonts — the same font-graveyard pattern the sibling Partna-Frontend audit found in its own `public/fonts/`.
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD] — notably, still actively copied by `scripts/copy-assets.sh` during the build, so it's not just inert source, it's shipped output too.
- **`packages/design-system/scripts/check-css-isolation.sh`** — globs `src/theme-*/styles/global.css`; no such directories exist and structurally can't recur (architectures now live in `apps/pages/src/architectures/`, not this package). Still runs in CI on every push, always trivially no-ops.
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD] — actively runs in `npm run check` (pre-commit verification), not archived.
- **`packages/design-system/scripts/extract-platform-icons.mjs`** — a one-time migration script (its own header: "run once from the repo root") that reads from `_archive/icons` and writes to files that already exist. Not wired into any `package.json` script or CI. Will hard-error the moment `_archive/` (above) is deleted.
  - **Paths (verified 2026-07-25):** confirmed [LIVE-IN-BUILD] — sits in the normal `scripts/` tree, not archived (even though its own input folder is).

### Individual dead exports (files otherwise live, don't touch the rest)
- `packages/design-system/src/design-assets/icons.ts` — `getIconUrl`, `getIconUrlForFont`, `listDesignIcons` (zero consumers; `apps/pages` globs raw SVGs at build time instead) — though `listDesignIcons` is explicitly named in Partna-Frontend's own CLAUDE.md as "the anticipated" future export, so this may be intentionally-ahead-of-adoption rather than dead.
- `packages/design-system/src/design-assets/registry.ts` — the entire "Platforms" section (`getPlatformColor`, `getPlatformColorContrast`, `getPlatformCategory`, `getPlatformWordmarkUrl`, `listPlatforms`, `PlatformCategory`/`PlatformEntry` types, ~100 lines) plus `getFontFamily`/`listFonts` — zero consumers; `apps/pages` globs platform icons directly and `Partna-Frontend` hand-inlines its own font catalog rather than importing this.
- `packages/design-system/src/engines/page-taxonomy.ts` — `CANONICAL_PAGE_ORDER` (pure alias, unused), `BUSINESS_ONLY_PAGES`/`isBusinessOnlyPage` (unused here — may be enforced backend-side, not verified), `SECTION_KEY_TO_PAGE` (its own comment frames it as documentation-mirroring a backend PHP map, not code meant to execute).
- `packages/design-system/src/engines/platform-sections.ts` — `OtherSources` type + `buildOtherItems` — explicitly a "back-compat wrapper for the V1 architectures," which no longer exist under the single-"staple"-architecture doctrine; the real caller builds Pinterest items directly instead.
- `apps/pages/src/content/platforms/fetch-platforms.ts` — 11 dead singular selector functions (`selectShopify`, `selectAppleMusic`, `selectApplePodcast`, `selectEventbrite`, `selectBandcamp`, `selectHumanitix`, `selectSpotify`, `selectSoundcloud`, `selectVimeo`, `selectYoutubeMusic`, `selectTwitch`) — only their `*All` plural siblings are ever called. Comment on line 174 confirms these are a legacy shim for the removed "Bento" architecture.
- `apps/pages/src/content/platforms/fetch-shopify-selection.ts` — `variantLinks`, `formatProductPrice` — zero callers.
- `apps/pages/src/analytics/behaviors.ts` — `initDeepLinkJump`, `initMenuNav`, `initSharedPageBehaviors` — the current architecture (`staple.client.ts`) explicitly skips these in its own comment ("ONE's multi-panel model... structurally doesn't apply to Staple's per-page routing").
- **Paths (verified 2026-07-25):** all 7 files confirmed [LIVE-IN-BUILD] at their stated paths — none archived; these are dead exports inside otherwise-live, currently-shipping files.

---

## Dead computation — data computed on every page render, never displayed

**Files:** `apps/pages/src/content/types.ts` + `resolve-site-content.ts`
Four whole data blocks are computed on **every single sitepage render**, but grepping every page/component in the app shows none of it is ever read:
- `pinterest` (section media items)
- `gallery` (the flat, non-curated gallery list — every real gallery renderer uses `curatedGallery` instead)
- `covers` (youtube/appleMusic/applePodcast/eventbrite cover images)
- `profile.document` + `.newsletter` — notably, `document` is half-wired: `analytics/tracker.ts` has a ready-made `data-track-download` hook waiting for it and `pages/api/document/[id].ts` is a working proxy endpoint, but no page renders the download link that would use either.
- `WorkplaceSurface.latitude`/`.longitude`/`.phone`/`.website` — only `.name`/`.description`/`.city` are ever consumed.

This is repeated, wasted computation on every request, not just static dead code — worth prioritizing over pure cleanup items above.
- **Paths (verified 2026-07-25):** `apps/pages/src/content/types.ts`, `apps/pages/src/content/resolve-site-content.ts`, `apps/pages/src/analytics/tracker.ts`, `apps/pages/src/pages/api/document/[id].ts` — all confirmed.

---

## Needs a decision — not a mechanical delete

**8 components reachable only via the dev-gated `/blocks` and `/primitives` proof pages, zero use on any live sitepage:** `blocks/Hero.astro`, `blocks/LinkList.astro`, `blocks/SiteFooter.astro`, `ui/Badge.astro`, `ui/Divider.astro`, `ui/Link.astro`, `ui/Row.astro`, `ui/Select.astro`, `ui/Textarea.astro`. Since `apps/pages/CLAUDE.md` explicitly designates those two pages as intentional "proof surfaces," this may be components built ahead of real-page adoption rather than dead code — a fact for whoever decides what to prune, not an assertion either way.
- **Paths (verified 2026-07-25):** all 9 confirmed under `apps/pages/src/components/{blocks,ui}/`.

---

## Fixes (also listed in the Comet-Backend doc since it's the higher-severity item)

**`packages/design-system/src/design-kit/validate.ts:103-112`** — the `typography` Zod schema is missing a `weight` field that `types.ts` declares and `apps/pages/src/lib/emit-kit-css.ts` actively reads. Zod silently strips unrecognized keys, so any inbound JSON setting `typography.weight` is dropped by `validateDesignKit`. Real functional gap, not just stale docs.

**`packages/design-system/src/design-kit/types.ts:135-137`** — `motion`'s docstring describes a `glassShineDuration` field that doesn't exist anywhere (not in the interface, `defaults.ts`, `validate.ts`, or the CSS emitter) — leftover from the "glass surface system" removal that wasn't updated to past tense like every other glass-related comment in the codebase.

**`packages/design-system/src/design-kit/validate.ts:67-78`** — `responsiveSpaceSchema` (used for `spaceDesktop`) validates 7 fields, but the type only declares 1 (`regular?`). Looks like an un-narrowed copy-paste of the base `space` schema. Lower severity — over-permissive, not currently blocking anything.

**`packages/design-system/scripts/check-no-framework.sh:17`** — the "no React" CI guard's regex only catches `react-router|@remix-run|astro:|next/`, never bare `from 'react'`. Currently moot (no file imports React), but a real coverage gap in the guard itself.

- **Paths (verified 2026-07-25):** `validate.ts`, `types.ts` → confirmed under `packages/design-system/src/design-kit/`; `emit-kit-css.ts` → `apps/pages/src/lib/emit-kit-css.ts`; `check-no-framework.sh` → `packages/design-system/scripts/check-no-framework.sh`. All confirmed.

---

## Consolidation candidates
- `packages/design-system/src/design-assets/icons.ts`'s `SEMANTIC_ICON_NAMES` is exported specifically so consumers don't hand-copy it — but `apps/pages/src/lib/icons.ts` hand-maintains its own duplicate list anyway (its own comment even flags this as a manual "LOCKSTEP" requirement).
- A magic constant (`1.3125rem`, an ALD-measured header height) is independently re-derived in 5 files with no shared token: `ui/Logo.astro` (likely canonical), `blocks/MenuDrawer.astro`, `blocks/PageBand.astro`, `blocks/ItemDetail.astro`, `blocks/FilterDrawer.astro`. Each file's own comments concede they must stay pixel-identical — a real candidate for one shared `--dk-layout-*` token.
- `blocks/ItemDetail.astro:236` hardcodes `line-height: 1.4` where a `--dk-typography-line-height` token already exists and is used for the identical property elsewhere (`ui/Textarea.astro`).
- **Paths (verified 2026-07-25):** `icons.ts` (design-system + `apps/pages/src/lib/icons.ts`) and all 5 astro files confirmed under `apps/pages/src/components/{ui,blocks}/`.

---

## Stale comments (documentation-only, batched)
All reference retired concepts with zero functional impact:
- **"one"/"Bento"/"theme" terminology from before the 2026-07-15 rename to the single "staple" architecture:** `apps/pages/src/lib/fetch-profile.ts:32-34` (directly contradicts its own type declaration one line below), `lib/site-render.ts` (pervasive, 6+ occurrences), `env.d.ts:28`, `middleware.ts:28-29,145`, `content/platforms/social-links.ts:6`, `content/platforms/fetch-platforms.ts:174`.
- `blocks/ContactForm.astro:110` — one hardcoded hex color (`--dk-color-error: #ff0000`), but explicitly documented as a deliberate, owner-approved exception (dated 2026-07-18) — not an oversight.
- `blocks/SiteFooter.astro:90-91` — two hardcoded rem values with no measurement-justification comment, unlike the codebase's usual convention for such constants.
- **Paths (verified 2026-07-25):** all 6 files confirmed under `apps/pages/src/`.

---

## Suggested order
The dead-computation finding (4 unread data blocks computed every render) is worth fixing before the pure-cleanup items — it's ongoing waste, not just static bloat. `_archive/` and `supabase/.temp/` are the two biggest, safest, zero-ambiguity deletions. The design-kit validation gaps (`typography.weight`, `glassShineDuration`) should go with the matching Comet-Backend fixes since they're the same cross-cutting design-kit contract. Everything else is opportunistic.

**Note added 2026-07-25:** of everything in this doc's "Ready to delete" list, only `_archive/` and `supabase/.temp/` are actually dormant — already excluded from the build. Every other delete candidate here (the individual-export items, `src/index.ts`, `renderers/pdf.ts`, `brand/fonts/`, both scripts) is confirmed still LIVE-IN-BUILD — meaning removing them actually shrinks what ships today, not just tidies up something already inert. Worth knowing when prioritizing: the two archived items are the zero-risk freebies, but most of the real payoff here is in the live ones.
