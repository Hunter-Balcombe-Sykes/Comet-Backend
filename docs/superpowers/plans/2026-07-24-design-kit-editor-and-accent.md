# Design-Kit Editor Fidelity + Accent Auto-Collection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans **INLINE, this session — NOT subagent-driven**. Run **fully autonomously** per the **Autonomous Inline Execution Directive** below, and follow the **Execution Order** section for the actual task sequence (it overrides the phase order the document is written in). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the dashboard design editor faithfully show the design the platform auto-determines for a site (industry presets + scanned brand colour), keep it trustworthy across refreshes and account switches, and widen accent auto-collection so a real brand colour is captured far more often.

**Architecture:** Keep the deliberate **read-time preset model** (`ProfileDesignPresets` stays pure/non-persisted — we do NOT resurrect the deleted factor machine). Fix the editor by making the *dashboard read* apply the same preset overlay the public site already applies, and by making the frontend cache reflect the DB instead of masking it. Separately, replace the single favicon/theme-color accent probe with a priority chain over already-available brand sources (theme-color → logo → favicon → gallery palette), reusing the existing `ImagePaletteExtractor`.

**Tech Stack:** Comet-Backend (Laravel 12, PHP 8.2, Pest, Postgres/Supabase, Horizon jobs, GD, Apify). Partna-Frontend (Next.js 16, React Query / `@tanstack/react-query`, shadcn/Geist, lucide-react). partna-monorepo (`packages/design-system` = `@partnaau/design-system` workspace package; `apps/pages` = Astro on Cloudflare Workers).

## Global Constraints

- **Three repos, three lanes.** Backend tasks: `~/Developer/Comet-Backend` (branch `feat/design-kit-editor-fidelity` off `development`). Frontend tasks: `~/Developer/Partna-Frontend` (work on `main`). Design-system/pages tasks: `~/Developer/partna-monorepo` (work on `main`; run `npm run build:ds` from the repo root after any `packages/design-system` edit — the app consumes the built `dist/`, not `src/`, so an unrebuilt edit is invisible to `apps/pages`). Do NOT edit outside the lane a task names.
- **partna-monorepo tokens-only rule:** every design value in `apps/pages` CSS is `var(--dk-*)` or an allowlisted platform constant — no hardcoded hex/px/rem in architecture CSS. A genuinely fixed (non-theme, non-editable) colour is a **sanctioned exception** declared directly in `design-kit/vars.css` (pattern: `--dk-color-error`) — never a new `defaults.ts`/DB column.
- **Partna-Frontend component rule:** compose `components/ui/*` (shadcn) — no new custom visual components, no new `*.module.css`, lucide-react icons only.
- **Never branch on `account_type`** — gate on `AccountCapabilities` only. (Not expected to arise here; noted for compliance.)
- **Individual-only platform.** Model `App\Models\Core\User\User` (`core.users`, FK `user_id`).
- **No Laravel migration files** in the backend — schema changes are raw SQL under `supabase/migrations/`, applied via `supabase db push` against dev ref `glncumufgaqcmqhzwrxm`.
- **Dashboard design-kit wire format is flat snake_case** under the `design_kit` key (matches DB columns). The public sitepage format is nested camelCase under `designKit` — do NOT converge them; the FE read/write mapping depends on flat snake_case.
- **`ProfileDesignPresets::forUser()` returns flat snake_case** (`color_accent`, `typography_font_family`, …) and is READ-TIME only — no persistence, no jobs, no scans.
- **Design-kit writes are fill-if-empty for auto-sources**; a user's manually-set column always wins.
- **Tests SQLite, prod Postgres** — `designKitVars()` fails closed to `[]` under SQLite (no `site.design_kits` mirror). Feature tests asserting kit values must seed the row through the real write path or a Postgres-backed test, not assume the column exists in SQLite.
- Backend verify gate per task: `composer test` (Pest) + `php artisan pint`. Frontend verify gate: `npm run typecheck && npm run lint`.

---

## Autonomous Inline Execution Directive

This plan runs **inline, one continuous session, fully autonomously**. The executor makes every implementation decision itself and does not pause between tasks.

- **Decide, don't defer.** Every "confirm X first", "read that file and mirror it", "adjust to match the repo's actual pattern", "confirm the exact hook / disk name / dispatch mechanism / queue" note in this plan means **investigate inline and decide** — open the referenced code, pick the option matching surrounding conventions, proceed. Never stop to ask which option, never leave a step half-done pending confirmation, never queue a decision for later. If the code contradicts the plan, the code is ground truth: follow it, note the deviation in the commit message, continue.
- **No popups, no chips, no questions.** Do not use `AskUserQuestion`. Do not spawn background-task chips (`spawn_task`) for anything found mid-run. If you spot worthwhile out-of-scope work (a real bug, dead code, a missing guard) that is concrete and code-level enough to implement + test now, **append it as a new numbered task at the end of Phase 5** using this plan's TDD structure, execute it inline in sequence, and record it under **Execution Order → Discovered during execution**. Vague smells or research questions are not appended — they're mentioned in the final report only.
- **Autonomy ≠ guessing.** systematic-debugging still governs every fix: reproduce/understand before changing. "Decide inline" means *you* resolve the ambiguity from the code, not that you skip resolving it.
- **Per-task verify is non-negotiable.** Each task ends with its verify gate green (`composer test` + `pint`; or `npm run typecheck && npm run lint` [+ `npm run test`]; or `npm run build:ds`) **and a commit**, before the next task. A red gate is stop-and-fix, never defer. Read that gate at the stricter bar in **Verification Discipline** below — multiple cases, fix-what-you-find, real recorded evidence — never a single green example.
- **Deploys, the live migration, and the backfill ARE autonomous — run them in sequence, verified, never deferred** (user-approved 2026-07-24). After Stage A's backend code is committed: push `feat/design-kit-editor-fidelity` → merge `development` → deploy, and **verify the deploy is healthy** (`cloud deployment:list development` + `cloud env:logs partna development --minutes 5`) before doing anything downstream. Then `supabase db push` the Task 15 drop (deploy-then-drop order — the deployed code no longer references the column). Then run `google-business:backfill-claimed-reviews` and confirm it worked. An unhealthy deploy or a failed migration is a **stop-and-fix**, not a proceed. The `development` env is the live DB serving both domains (backend CLAUDE.md), so move deliberately and verify each step — but do move.
- **The one hard safety rail — data integrity, not autonomy.** Before running ANY `->group('postgres')` test, confirm its connection resolves to a dedicated test database, NOT the live dev Supabase ref `glncumufgaqcmqhzwrxm`. The suite uses `RefreshDatabase`, which truncates/re-migrates whatever it connects to — pointing it at the live DB would wipe live serving data. If you cannot confirm a separate test DB, run only the SQLite-backed tests and record the postgres-group tests as unverified in the final report. This rail holds regardless of the deploy approval above.

## Verification Discipline (proof before every tick)

A box is ticked only when the fix is **demonstrated working with real evidence**, never when it "should" work. This plan has historically over-trusted single-example checks — read every task's verify gate at this stricter bar:

- **Multiple cases, not one.** A single green example proves the code runs, not that the bug is fixed. For each task, verify **at least three distinct cases**: (1) the exact failing scenario that motivated it, (2) one or two sibling/edge cases (a different input, a boundary, the "manual value wins" / "different host" counterpart), and (3) the whole relevant test group green — not just the one `--filter`ed test. Where a task ships only one example, add the siblings; they're cheap and they're the entire point.
- **Fix what the checks surface.** If verifying task N turns up a *different* problem — a regression in a neighbouring test, an adjacent bug, a wrong assumption baked into an earlier task — fix it **before** ticking N. Small + in-scope → fix inline. Larger / out-of-scope → append as a new Phase 5 task (per the Autonomous Directive) and do it in sequence. Never tick a box while leaving a thing you just watched break.
- **Real, recorded evidence.** "PASS" means you ran it and saw green — quote the actual output, don't assert it. For visual/behavioural work the evidence is a real load of the surface (a screenshot, a `read_console_messages` clean of errors, a `cloud tinker` row dump), not "this should render now."
- **End-to-end beats unit-only for the pipeline tasks.** `Http::fake` unit tests prove branch logic; they do NOT prove the real pipeline works on real data. Every task touching a live pipeline (accent A*, Instagram R1, Google reviews REV1, custom-link L1, Maps MAP1) also requires the **Stage E broken-oven re-test** (in Execution Order) to pass with real data before its tick.
- **A red or surprising result is stop-and-investigate** (systematic-debugging), never a "close enough" tick.

## Execution Order (inline sequence — overrides document/phase order)

The document is grouped by concern for readability. **Execute in this order instead** — it batches by repo/lane and threads the three deploys (backend, then sitepages, then dashboard) in at the right points. Task numbers are stable (cross-references rely on them); only the run-sequence changes. Deploys, the live migration, and the backfill are all **autonomous** (user-approved 2026-07-24) — run and verify each in place.

**Stage A — Backend code, one branch `feat/design-kit-editor-fidelity` off `development` (`~/Developer/Comet-Backend`):**
1. Task 1 → 2 → 3 → 4 — design-kit resolved read.
2. Task 11 → 12 → 13 → 14 — accent (logo palette scan; shared `AccentQuality` gate; resolver needing both; deferred job needing the resolver).
3. Task 15 → 16 — cleanup. Write the migration FILE + model/config edits + commit; the live `supabase db push` runs in Stage A▸ (deploy-then-drop).
4. Task 17, 18, 21 — independent of each other, any order.

All of Stage A is code + SQLite-backed tests + commits — no live effect yet.

**Stage A▸ — Ship & remediate backend (AUTONOMOUS):**
5. Push `feat/design-kit-editor-fidelity`, open the PR, merge to `development` → deploys to dev-api via Laravel Cloud. ⚠ `development` currently serves BOTH domains — effectively a prod deploy. (The change is purely additive to the API shape, so the still-old FE keeps working; deploying backend first is safe.)
6. **Verify the deploy** — `cloud deployment:list development` shows success; `cloud env:logs partna development --minutes 5` is clean. Unhealthy → stop-and-fix before continuing.
7. **Apply the migration (Task 15 Step 4)** — `supabase db push --dry-run` then `supabase db push` against ref `glncumufgaqcmqhzwrxm`; confirm `previous_website_analysis` is gone. Runs AFTER the code deploy (deploy-then-drop).
8. **Backfill reviews (Task 21 Step 14)** — `cloud command:run partna development "google-business:backfill-claimed-reviews"`; confirm via logs + a `cloud tinker development` check that broken-oven's google-business payload now has a non-empty `reviews` array.

**Stage B — Monorepo, branch `main` (`~/Developer/partna-monorepo`):**
9. Task 19 — logo backdrop; `npm run build:ds`; commit. Fully independent of the backend.
10. Deploy sitepages — `npm run deploy` (apps/pages → Cloudflare Workers, DEFAULT worker only per repo rules). ⚠ **Beyond your literal C1–C4 list** — this pushes the backdrop live to real sitepages. Flagged for your review; say the word and I'll commit-only instead.

**Stage C — Frontend, branch `main` (`~/Developer/Partna-Frontend`):**
11. Task 5 → 6 → 7 → 8 → 9 → 10 — design editor.
12. Task 20 — logo sub-page (no backend dependency).
13. Task 22 — maps key (repoints to the *already-existing* authenticated route — no new-backend dependency).
14. Push `main` → Vercel deploys `app.partna.au`. ⚠ **Beyond your literal C1–C4 list** — flagged for your review; say the word and I'll commit-only instead. Automated FE tests (5, 9, 22) already passed inline against mocks; Tasks 6 & 8 need the Stage-A▸ backend live (it is, by now).

**Stage D — Post-deploy visual verification (AUTONOMOUS = C3):**
15. Run the manual/visual checks from each task's final Step + the whole-plan Verification section against the deployed surfaces (or a local dev server pointed at the deployed `dev-api` for any surface left commit-only): design-editor fidelity + no cross-account bleed, logo visible across all 5 theme modes + day/night shift, maps embed renders instead of "No location data yet", reviews show on broken-oven.

**Stage E — Real end-to-end re-test on the broken-oven test account (AUTONOMOUS):**

broken-oven (IG `brokenovenpizzabar`, previous site `thebrokenovenpizzabar.com.au`) is a disposable full-test account — you're authorised (user, 2026-07-24) to reset and re-run its integrations for a genuine end-to-end test. **Only broken-oven, never any other account.** Before mutating a row, snapshot it (dump the current `IntegrationConnection` / `design_kits` / `site_media` rows to the scratchpad) so it can be restored. Stay within the Apify (200/day IG) and Places budget caps — each re-run below is ~one claim / one fetch. For each live-pipeline fix, reset the relevant broken-oven state, re-run the **real** pipeline, and confirm the *actual outcome* (not a faked one):

16. **Instagram reel (R1):** delete broken-oven's instagram `IntegrationConnection` + its mirrored `site_media`, re-run the real seed against `brokenovenpizzabar`, confirm the latest reel is now captured (non-null `videoUrl`) and `_mediaDiagnostics` shows the video candidate + whether the retry fired. If the reel proves genuinely outside the 12-post window or truly has no mp4 (the out-of-scope case), the diagnostics now say so — report that honestly instead of calling it fixed.
17. **Google reviews (REV1):** confirm the Stage-A▸ backfill populated broken-oven's `reviews`; then additionally blank the payload's `reviews` and re-dispatch `ReEnrichClaimedGoogleBusinessReviewsJob` directly to prove the job path (not only the command) works; confirm the dashboard Reviews section renders real individual reviews.
18. **Custom-link skip (L1):** confirm broken-oven has no `thebrokenovenpizzabar.com.au` custom link; if one exists delete it, re-run the scrape, confirm it is NOT re-added while a genuinely different scraped link still is.
19. **Accent (A1–A4):** null broken-oven's `color_accent`, re-run the scan pipeline, confirm a real qualifying brand accent resolves + applies — and that a manually-set accent would not be overwritten.
20. **Maps (MAP1) + design editor:** load broken-oven in the dashboard — maps embed renders (not "No location data yet"); the design editor shows the resolved preset kit with correct Auto/manual badges; no cross-account bleed after switching accounts.

Record before/after for each as the evidence backing the corresponding task's tick. Restore any snapshot you don't want to leave changed.

**Discovered during execution (appended tasks):** _none yet — the executor appends here as it goes._

---

## Issue Inventory → Task Map (completeness check)

| # | Issue (found during investigation) | Fixed by |
|---|---|---|
| I1 | Dashboard `GET /api/site` is preset-blind — returns raw stored columns only, no `ProfileDesignPresets` overlay, so the auto-determined design never shows in the editor | Task 1, 2 |
| I2 | Auto-determined sector presets are never persisted (read-time only) — "not saved in backend" is literally true for them | Task 1 (resolved by read-time overlay; Option A — no persistence) |
| I3 | FE design-kit react-query cache is `staleTime: Infinity`, persisted to `localStorage` 24h, and the query key is **not namespaced by account** → cross-account bleed (confirmed: `ollies`' `monument-grotesk` bled into `broken-oven`) and never self-corrects | Task 5, 6, 7 |
| I4 | No auto-vs-manual distinction — once presets show, the user can't tell auto from chosen, and "reset to auto" has no mechanism | Task 2 (backend marker), Task 8 (FE badges + reset) |
| I5 | FE hardcodes design-kit defaults ("mirrors DESIGN_KIT_DEFAULTS") instead of importing `@partnaau/design-system` → drift risk | Task 9 |
| I6 | The "Charlie" chat assistant is a second design-kit writer that doesn't reconcile the editor cache | Task 10 |
| I7 | Dashboard preset-blindness / read shape is completely untested | Tasks 1–3, 8 (tests) |
| I8 | Stale, misleading comments in `UserSiteController` ("no var columns exist… keys silently dropped") | Task 4 |
| A1 | `WebsiteAccentExtractor` silently no-ops when theme-color + favicon both fail (monochrome / no theme-color / plain favicon) — no log, no fallback | Task 13, 14 |
| A2 | Logo images (`logo_full`/`logo_square`) are grabbed but never palette-scanned — the best brand-colour source is unused | Task 11 |
| A3 | Gallery/media palettes ARE scanned (`site_media.palette`) but their only consumer (`ImageryPaletteFactor`) was deleted — orphaned data | Task 13 (repurpose as accent source) |
| A4 | Accent capture is single-probe, priority-blind, timing-coupled | Task 12, 13, 14 |
| C1 | `Workplace.previous_website_analysis` column orphaned (writer deleted) | Task 15 |
| C2 | `config/partna.php` `brand_scan` block orphaned (zero references, docblock describes deleted pipeline) | Task 16 |
| C3 | `UserWorkplaceController::destroy()` stale comment about a sweep that no longer happens | Task 16 |
| C4 | `ImagePaletteExtractor` docblock references deleted `ImageryPaletteFactor` | Task 11 |
| L1 | Instagram bio / link-in-bio / website-harvest scrape saves the user's **own previous website** (or a subpage of it) as a custom link — duplicating the very site the Partna page replaces | Task 17 |
| R1 | Instagram scrape captured the latest photo post but NOT the latest reel/video (broken-oven: `videoUrl:null` despite a real reel). **Revised after live testing:** detection and the mirror fetch both work correctly today (verified against the real account); queue/timeout structurally rules out a job-kill theory too. Root cause is a one-off transient mirror failure with no retry and no surviving diagnostic trail | Task 18 |
| LG1 | On the public sitepage a raster logo can be invisible (light logo on a light theme / dark on dark) — no neutral backdrop behind it | Task 19 |
| LG2 | Business/workplace logos are buried on the hub Overview page; user wants a dedicated "Logo" sub-page between Overview and Reviews | Task 20 |
| REV1 | Google reviews never show in the dashboard (aggregate star rating shows fine) — `stripThirdPartyPii()` deletes the `reviews` array at pre-account build time (privacy-correct for an unclaimed listing) and nothing re-fetches once the account is claimed | Task 21 |
| MAP1 | Maps page shows "No location data yet" for every account despite `placeId`/address data being present and correctly exposed — the frontend calls a Maps-key config route that was deliberately removed in a security hardening (`SEC-1`) and never got repointed at the endpoint that replaced it | Task 22 |

> **Phase 5 is open** — Tasks 17–22 are folded in; more can follow with the same structure.

---

## File Structure

**Backend (Comet-Backend):**
- `app/Http/Resources/SiteResource.php` — MODIFY. Add a `withResolvedDesignKit(User)` opt-in that emits the preset-merged effective kit + a manual-keys marker. Default (opt-out) path unchanged.
- `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php` — MODIFY. `show()`/`update()` opt into `withResolvedDesignKit()`; remove stale comments.
- `app/Services/Design/ProfileDesignPresets.php` — READ ONLY (consumed; unchanged).
- `app/Services/WebsiteScan/AccentQuality.php` — CREATE. Shared accent quality gate (extracted from `WebsiteAccentExtractor`).
- `app/Services/WebsiteScan/SiteAccentResolver.php` — CREATE. Priority chain over accent candidates.
- `app/Services/WebsiteScan/WebsiteAccentExtractor.php` — MODIFY. Reuse `AccentQuality`; expose theme-color and favicon candidates separately.
- `app/Services/Design/LogoAutoGrabber.php` / `app/Jobs/ProcessLogoVariantsJob.php` — MODIFY. Palette-scan logos into `site_media.dominant_color`/`palette`.
- `app/Services/Media/ImagePaletteExtractor.php` — MODIFY (docblock only, C4).
- `app/Jobs/Platforms/ResolveSiteAccentJob.php` — CREATE. Runs the resolver after media scans, fill-if-empty.
- `app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php` — MODIFY. Replace inline accent apply with the deferred resolver chain.
- `app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php` — MODIFY (stale comment, C3).
- `config/partna.php` — MODIFY (remove `brand_scan` block, C2).
- `supabase/migrations/<ts>_drop_workplace_previous_website_analysis.sql` — CREATE (C1).
- `app/Services/Platforms/CustomLinkSeeder.php` — MODIFY. Skip auto-seeding a link that matches the user's `previous_website` host (L1).
- `app/Services/Platforms/InstagramScraper.php` — MODIFY. Broaden reel/video detection; return scrape diagnostics (R1).
- `app/Services/Platforms/InstagramConnectionSeeder.php` — MODIFY. Retry a transient `mirrorVideo` failure once; log every drop reason; persist `_mediaDiagnostics` (R1).
- `app/Jobs/Platforms/ReEnrichClaimedGoogleBusinessReviewsJob.php` — CREATE. Re-fetches unstripped Place Details (reviews) once an account is claimed (REV1).
- `app/Services/PreAccount/ClaimSiteService.php` — MODIFY. Dispatch the above job from the post-commit block (REV1).
- `app/Console/Commands/BackfillClaimedGoogleBusinessReviewsCommand.php` — CREATE. Remediates already-claimed accounts, including broken-oven (REV1).
- Tests under `tests/Feature/...` and `tests/Unit/...` as each task specifies.

**Frontend (Partna-Frontend):**
- `app/(app)/account/(dashboard)/design/use-site-design-kit.ts` — MODIFY. Account-namespaced key, finite staleness, consume resolved kit + manual marker, delete stale comment.
- `app/(app)/providers.tsx` + `lib/query/persister.ts` — MODIFY. Exclude the design-kit key from `localStorage` persistence.
- `app/(app)/account/(dashboard)/design/use-individual-typography.ts` and sibling hooks — MODIFY. Import `DESIGN_KIT_DEFAULTS` from `@partnaau/design-system`; add auto/manual awareness + reset-to-auto.
- `lib/chat-engine/action-design-handlers.ts` — MODIFY. Invalidate the design-kit query after a Charlie design write.
- `components/shells/account-nav.tsx` — MODIFY. Insert a "Logo" row into the Workplace/Business hub's `PAGE_SECTIONS` (LG2).
- `app/(app)/account/(dashboard)/workplace/_brand-page.tsx` — MODIFY. Remove the inline logo section (moved to its own page).
- `app/(app)/account/(dashboard)/workplace/logo-page.tsx`, `workplace/logo/page.tsx`, `business/logo/page.tsx` — CREATE (LG2).
- `lib/hooks/use-google-business-connect.ts` — MODIFY. `fetchMapsKey()` calls the authenticated `/config/integrations` instead of the removed public route (MAP1).

**partna-monorepo (design-system + apps/pages):**
- `packages/design-system/src/design-kit/vars.css` — MODIFY. Add the `--dk-color-logo-backdrop` sanctioned constant (LG1).
- `packages/design-system/CLAUDE.md` — MODIFY. Document the new sanctioned exception.
- `apps/pages/src/components/ui/Logo.astro` — MODIFY. Apply the backdrop plate to `.logo-image` only.

---

# PHASE 1 — Preset-aware dashboard read (backend)

## Task 1: `SiteResource` emits the preset-merged effective design kit

**Files:**
- Modify: `Comet-Backend/app/Http/Resources/SiteResource.php`
- Test: `Comet-Backend/tests/Unit/Resources/SiteResourceTest.php`

**Interfaces:**
- Consumes: `App\Services\Design\ProfileDesignPresets::forUser(?User): array` (flat snake_case, sparse); `Site::designKitVars(): array` (flat snake_case, non-null stored columns).
- Produces: `SiteResource::withResolvedDesignKit(User $pro): static`. When opted in, the JSON gains `design_kit` = `array_merge(ProfileDesignPresets::forUser($pro), $manual)` and `design_kit_manual` = `array_keys($manual)` where `$manual = $this->resource->designKitVars()`. When NOT opted in, `design_kit` stays `(object) designKitVars()` and no `design_kit_manual` key is emitted (backward compatible).

- [ ] **Step 1: Write the failing test** — add to `SiteResourceTest.php`:

```php
it('merges sector presets under manual columns when resolved-kit is opted in', function () {
    $user = User::factory()->create(['sector' => 'restaurant']); // → food_drink bucket
    $site = $user->site; // has a design_kits row via trigger; only color_accent set
    DB::connection('pgsql')->table('site.design_kits')
        ->updateOrInsert(['site_id' => $site->id], ['color_accent' => '#105030']);

    $payload = (new SiteResource($site->fresh()))
        ->withResolvedDesignKit($user)
        ->toArray(request());

    // preset value shows through (food_drink → general-sans, weight 300)
    expect($payload['design_kit']->typography_font_family)->toBe('general-sans');
    expect($payload['design_kit']->weight_regular)->toBe('300');
    // manual column wins over preset accent (#e0491f)
    expect($payload['design_kit']->color_accent)->toBe('#105030');
    // only the stored column is reported manual
    expect($payload['design_kit_manual'])->toBe(['color_accent']);
})->group('postgres'); // needs the real site.design_kits table
```

- [ ] **Step 2: Run it, verify it fails**

Run: `./vendor/bin/pest --filter="merges sector presets"`
Expected: FAIL — `withResolvedDesignKit` undefined / `design_kit_manual` missing.

- [ ] **Step 3: Implement in `SiteResource.php`**

Add the property + fluent setter alongside the existing `withRationale`/`withFeatureAvailability` pattern:

```php
/** Owner whose sector presets to overlay; null = emit raw stored kit only. */
private ?User $resolvedDesignKitOwner = null;

/** Opt into emitting the preset-merged effective kit + manual-key marker. Fluent. */
public function withResolvedDesignKit(User $owner): static
{
    $this->resolvedDesignKitOwner = $owner;

    return $this;
}
```

Replace the `design_kit` line (currently `'design_kit' => (object) $this->resource->designKitVars()`) with a resolved-aware block. Build it before the `return array_merge([...])` and splice it in:

```php
$manual = $this->resource->designKitVars(); // flat snake_case, non-null stored columns

$designKit = $this->resolvedDesignKitOwner !== null
    // defaults (filled FE-side) <- presets <- manual (manual wins per column)
    ? array_merge(ProfileDesignPresets::forUser($this->resolvedDesignKitOwner), $manual)
    : $manual;
```

In the returned array, change the `design_kit` entry to `'design_kit' => (object) $designKit,` and, in the trailing conditional merges, add:

```php
$this->resolvedDesignKitOwner !== null
    ? ['design_kit_manual' => array_keys($manual)]
    : [],
```

Add `use App\Services\Design\ProfileDesignPresets;` to the imports.

- [ ] **Step 4: Run the test, verify it passes**

Run: `./vendor/bin/pest --filter="merges sector presets"`
Expected: PASS.

- [ ] **Step 5: Guard the opt-out path stays raw** — add:

```php
it('emits only raw stored columns when resolved-kit is NOT opted in', function () {
    $user = User::factory()->create(['sector' => 'restaurant']);
    DB::connection('pgsql')->table('site.design_kits')
        ->updateOrInsert(['site_id' => $user->site->id], ['color_accent' => '#105030']);

    $payload = (new SiteResource($user->site->fresh()))->toArray(request());

    expect((array) $payload['design_kit'])->toBe(['color_accent' => '#105030']);
    expect($payload)->not->toHaveKey('design_kit_manual');
})->group('postgres');
```

Run: `./vendor/bin/pest --filter="raw stored columns"` → PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Resources/SiteResource.php tests/Unit/Resources/SiteResourceTest.php
git commit -m "feat(design-kit): SiteResource emits preset-merged effective kit + manual marker (I1, I4)"
```

## Task 2: Wire the design editor endpoints to the resolved kit

**Files:**
- Modify: `Comet-Backend/app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php` (`show()` ~:29-38, `update()` return ~:89-93)
- Test: `Comet-Backend/tests/Feature/Api/User/SiteManagement/SiteDesignKitReadTest.php` (create)

**Interfaces:**
- Consumes: `SiteResource::withResolvedDesignKit(User)` from Task 1.
- Produces: `GET /api/site` and `PATCH /api/site` responses whose `site.design_kit` is preset-merged and carry `site.design_kit_manual`.

- [ ] **Step 1: Write the failing feature test** in `SiteDesignKitReadTest.php`:

```php
it('GET /site returns the preset-merged kit for a restaurant with only accent set', function () {
    $user = User::factory()->create(['sector' => 'restaurant']);
    DB::connection('pgsql')->table('site.design_kits')
        ->updateOrInsert(['site_id' => $user->site->id], ['color_accent' => '#105030']);

    $this->actingAsProfessional($user)
        ->getJson('/api/site')
        ->assertOk()
        ->assertJsonPath('site.design_kit.typography_font_family', 'general-sans')
        ->assertJsonPath('site.design_kit.color_accent', '#105030')
        ->assertJsonPath('site.design_kit_manual', ['color_accent']);
})->group('postgres');
```

(Use the repo's existing auth helper — mirror how `SiteFeatureAvailabilitySurfacingTest` acts as a professional.)

- [ ] **Step 2: Run it, verify it fails** — `./vendor/bin/pest --filter="preset-merged kit for a restaurant"` → FAIL (font is absent, no manual key).

- [ ] **Step 3: Implement** — in `show()` change the resource chain to:

```php
return $this->success(['site' => (new SiteResource($site))
    ->withResolvedDesignKit($professional)
    ->withRationale()
    ->withFeatureAvailability($professional)]);
```

Apply the identical `->withResolvedDesignKit($professional)` addition to the `update()` return block (~:91).

- [ ] **Step 4: Run it, verify it passes** — `./vendor/bin/pest --filter="preset-merged kit for a restaurant"` → PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php tests/Feature/Api/User/SiteManagement/SiteDesignKitReadTest.php
git commit -m "feat(design-kit): design editor GET/PATCH /site return the resolved kit (I1, I7)"
```

## Task 3: Reset-to-auto persists as NULL (verify + pin)

**Files:**
- Test: `Comet-Backend/tests/Feature/Api/User/SiteManagement/WriteDesignKitTest.php` (extend)

**Interfaces:**
- Consumes: existing `PATCH /api/site` → `writeDesignKit` (already `nullable`-validated per `DesignKitValidationRules`, already `updateOrInsert`s nulls).

- [ ] **Step 1: Write the test** pinning that a null clears a manual override so the preset re-emerges:

```php
it('PATCH design_kit.<col> = null clears the manual override and the preset re-shows', function () {
    $user = User::factory()->create(['sector' => 'restaurant']);
    // user manually set a font, overriding the preset
    $this->actingAsProfessional($user)->patchJson('/api/site', [
        'design_kit' => ['typography_font_family' => 'geist'],
    ])->assertOk();

    // reset to auto
    $this->actingAsProfessional($user)->patchJson('/api/site', [
        'design_kit' => ['typography_font_family' => null],
    ])->assertOk()
      ->assertJsonPath('site.design_kit.typography_font_family', 'general-sans') // preset
      ->assertJsonPath('site.design_kit_manual', []); // nothing manual now
})->group('postgres');
```

- [ ] **Step 2: Run it** — `./vendor/bin/pest --filter="clears the manual override"`. Expected PASS with Tasks 1–2 in place (no new production code). If it FAILS on the null write, inspect `writeDesignKit` `array_intersect_key` null handling before changing anything.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Api/User/SiteManagement/WriteDesignKitTest.php
git commit -m "test(design-kit): pin reset-to-auto (null) reverts to preset (I4)"
```

## Task 4: Delete stale "no var columns exist" comments

**Files:**
- Modify: `Comet-Backend/app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php` (comment blocks ~:54-58 and ~:96-104)

- [ ] **Step 1: Replace the misleading comment** at the design_kit strip (~:54-58) with the current truth:

```php
// design_kit writes to site.design_kits, not site.sites. Pull it out before
// UpdateSiteAction (which only knows the sites row). writeDesignKit() filters
// keys against the live design_kits columns; unknown keys are dropped, valid
// ones persist.
```

And update the `writeDesignKit` docblock (~:96-104), deleting the "At cleanup-deploy time the table has zero var columns, so this is a no-op" sentences — the table has columns; the method persists.

- [ ] **Step 2: Verify no behavioural change** — `./vendor/bin/pest tests/Feature/Api/User/SiteManagement/WriteDesignKitTest.php` → PASS. Then `php artisan pint app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php
git commit -m "docs(design-kit): drop stale cleanup-phase comments in UserSiteController (I8)"
```

---

# PHASE 2 — Trustworthy editor (frontend)

> Lane: `~/Developer/Partna-Frontend` (branch `main`). These tasks **consume** `design_kit_manual` + the merged shape from Stage-A backend, but the FE **code can be written, tested, and committed before the backend deploys** — until it ships, `manualKeys` harmlessly falls back to `[]` (everything reads as Auto). Only the *manual/visual* verification steps need Stage-A live on `dev-api.partna.au` (Execution Order → Checkpoint C3). Automated FE tests mock the backend and pass immediately. **Do not block or defer this stage on the deploy** — implement + commit, and let the visual checks fall into the checkpoint batch.

## Task 5: Namespace the design-kit query by account (kill cross-account bleed)

**Files:**
- Modify: `Partna-Frontend/app/(app)/account/(dashboard)/design/use-site-design-kit.ts` (query key ~:70-76)
- Test: `Partna-Frontend/app/(app)/account/(dashboard)/design/use-site-design-kit.test.ts` (create if absent)

**Interfaces:**
- Consumes: the active account id from the app's account context (same source `authedJsonRequest`/account switching uses — confirm the exact hook, e.g. `useActiveAccountId()` in `lib/backend-account.ts`, before writing).
- Produces: query key `["site","design-kit", accountId]`.

- [ ] **Step 1: Confirm the account-id source** — open `use-site-design-kit.ts` and `lib/backend-account.ts`; identify the hook exposing the current account/site id used elsewhere in the design page. (Do not assume the name — read it.)

- [ ] **Step 2: Write the failing test** asserting two accounts don't share a cache entry:

```ts
it("uses an account-scoped query key so switching accounts can't bleed", () => {
  expect(siteDesignKitQueryKey("acct_A")).not.toEqual(siteDesignKitQueryKey("acct_B"));
  expect(siteDesignKitQueryKey("acct_A")).toEqual(["site", "design-kit", "acct_A"]);
});
```

- [ ] **Step 3: Implement** — extract `export function siteDesignKitQueryKey(accountId: string) { return ["site","design-kit", accountId] as const; }` and use it in `useQuery({ queryKey: siteDesignKitQueryKey(accountId), ... })`. Thread `accountId` from the confirmed hook; disable the query (`enabled: !!accountId`) until it resolves.

- [ ] **Step 4: Run** `npm run test -- use-site-design-kit` → PASS, then `npm run typecheck`.

- [ ] **Step 5: Commit**

```bash
git add "app/(app)/account/(dashboard)/design/use-site-design-kit.ts" "app/(app)/account/(dashboard)/design/use-site-design-kit.test.ts"
git commit -m "fix(design): account-scope the design-kit query key to stop cross-account cache bleed (I3)"
```

## Task 6: Make the design-kit query reconcile with the DB

**Files:**
- Modify: `Partna-Frontend/app/(app)/account/(dashboard)/design/use-site-design-kit.ts` (`staleTime`/refetch config ~:76; stale comment ~:4-13)

- [ ] **Step 1: Replace `staleTime: Infinity`** with a finite policy so the editor self-corrects:

```ts
staleTime: 30_000,      // was Infinity — allow reconciliation with the DB
refetchOnMount: true,   // re-read on entering the design page
refetchOnWindowFocus: false,
```

- [ ] **Step 2: Delete the now-false justification comment** at `:4-13` (the "GET /site does not include design_kit yet" block). Replace with one line: `// GET /site returns the resolved design_kit (presets merged) + design_kit_manual.`

- [ ] **Step 3: Verify** — `npm run typecheck && npm run lint`. Manual: load the design page for a fresh restaurant account with only accent set → Font shows **General Sans** (preset), not a phantom.

- [ ] **Step 4: Commit**

```bash
git add "app/(app)/account/(dashboard)/design/use-site-design-kit.ts"
git commit -m "fix(design): finite staleTime + refetchOnMount so the editor reflects DB truth (I3)"
```

## Task 7: Stop persisting the design-kit cache to localStorage

**Files:**
- Modify: `Partna-Frontend/app/(app)/providers.tsx` (dehydrate filter ~:33-46) and/or `Partna-Frontend/lib/query/persister.ts`

**Interfaces:**
- Consumes: `siteDesignKitQueryKey` (Task 5) so the exclusion matches by `queryKey[0] === 'site' && queryKey[1] === 'design-kit'`.

- [ ] **Step 1: Extend the persist `dehydrateOptions.shouldDehydrateQuery` filter** (currently excludes only `queryKey[0] === 'auth'`) to also exclude the design-kit key:

```ts
shouldDehydrateQuery: (query) => {
  const k0 = query.queryKey[0];
  if (k0 === "auth") return false;
  if (k0 === "site" && query.queryKey[1] === "design-kit") return false; // never persist across sessions/accounts
  return defaultShouldDehydrateQuery(query);
},
```

- [ ] **Step 2: Verify persistence exclusion** — in the browser, change a control, reload; confirm `localStorage["partna:query-cache"]` no longer contains a `["site","design-kit",…]` entry (the value now comes from a fresh `GET /site`).

- [ ] **Step 3: Verify** `npm run typecheck && npm run lint`.

- [ ] **Step 4: Commit**

```bash
git add "app/(app)/providers.tsx"
git commit -m "fix(design): exclude the design-kit cache from localStorage persistence (I3)"
```

## Task 8: Auto/manual badges + reset-to-auto in the editor

**Files:**
- Modify: `Partna-Frontend/app/(app)/account/(dashboard)/design/use-site-design-kit.ts` (expose `manualKeys`), the control hooks (`use-individual-typography.ts`, `use-individual-kit-select.ts`, `use-individual-colors.ts`, `use-individual-night-shift.ts`), and their section components.

**Interfaces:**
- Consumes: `res.site.design_kit` (merged effective) and `res.site.design_kit_manual: string[]` from Task 1/2.
- Produces: each control exposes `isAuto: boolean` (its column is NOT in `design_kit_manual`) and a `resetToAuto()` that PATCHes `{ design_kit: { <col>: null } }`.

- [ ] **Step 1: Surface `manualKeys`** from `useSiteDesignKit` — return `manualKeys: (res?.site?.design_kit_manual ?? []) as string[]` alongside the kit.

- [ ] **Step 2: In each control hook, derive `isAuto`** — e.g. in `use-individual-typography.ts` for font: `const isAuto = !manualKeys.includes("typography_font_family");`. The displayed value already comes from the merged `design_kit` (preset value when auto), so no separate default lookup is needed here (see Task 9).

- [ ] **Step 3: Add `resetToAuto()`** using the existing save queue: `queuedSitePatch({ design_kit: { typography_font_family: null } })`; on success it refetches (Task 6) and the preset value returns.

- [ ] **Step 4: Render an "Auto" chip** in each section when `isAuto`, with a small "Reset to auto" affordance shown only when `!isAuto`. Follow the shadcn/Geist patterns already in the section components (no new CSS modules).

- [ ] **Step 5: Verify** `npm run typecheck && npm run lint`. Manual: restaurant account shows Font = General Sans with an "Auto" chip; picking Geist drops the chip and shows "Reset to auto"; clicking it returns to General Sans.

- [ ] **Step 6: Commit**

```bash
git add "app/(app)/account/(dashboard)/design/"
git commit -m "feat(design): auto/manual badges + reset-to-auto in the design editor (I4)"
```

## Task 9: Import design-kit defaults from the design system (kill drift)

**Files:**
- Modify: `Partna-Frontend/app/(app)/account/(dashboard)/design/use-individual-typography.ts` and sibling hooks that inline "mirrors DESIGN_KIT_DEFAULTS" constants.

**Interfaces:**
- Consumes: `DESIGN_KIT_DEFAULTS` from `@partnaau/design-system` (the same object partna-pages merges with).

- [ ] **Step 1: Confirm the export** — verify `@partnaau/design-system` exposes `DESIGN_KIT_DEFAULTS` (and font slug default) and that the FE package.json already depends on the workspace package. If the symbol isn't exported, prefer exporting it from the package over re-mirroring.

- [ ] **Step 2: Replace the hand-mirrored default constants** (e.g. `DEFAULT_FONT_SLUG = "geist"`, the `0.75rem`/`normal`/`bleach` literals) with references derived from `DESIGN_KIT_DEFAULTS`. These now only apply for fields the merged `design_kit` doesn't carry (neither preset nor manual).

- [ ] **Step 3: Add a drift guard test** asserting the FE default matches the package:

```ts
import { DESIGN_KIT_DEFAULTS } from "@partnaau/design-system";
it("font default tracks the design system, not a local copy", () => {
  expect(DEFAULT_FONT_SLUG).toBe(DESIGN_KIT_DEFAULTS.typography.fontFamily);
});
```

- [ ] **Step 4: Verify** `npm run typecheck && npm run lint && npm run test -- design`.

- [ ] **Step 5: Commit**

```bash
git add "app/(app)/account/(dashboard)/design/"
git commit -m "refactor(design): source editor defaults from @partnaau/design-system (I5)"
```

## Task 10: Charlie design writes invalidate the editor cache

**Files:**
- Modify: `Partna-Frontend/lib/chat-engine/action-design-handlers.ts` (~:63-80)

**Interfaces:**
- Consumes: `siteDesignKitQueryKey` (Task 5) + the app's `queryClient`.

- [ ] **Step 1: After the Charlie `PATCH /site { design_kit: … }` succeeds**, invalidate the design-kit query so the editor re-reads:

```ts
await queryClient.invalidateQueries({ queryKey: siteDesignKitQueryKey(accountId) });
```

Keep the existing `refreshAccount: true` return.

- [ ] **Step 2: Verify** `npm run typecheck && npm run lint`. Manual: ask Charlie to change the font; the open design editor reflects it without a hard reload.

- [ ] **Step 3: Commit**

```bash
git add "lib/chat-engine/action-design-handlers.ts"
git commit -m "fix(design): Charlie design writes invalidate the editor cache (I6)"
```

---

# PHASE 3 — Accent auto-collection (backend)

> Lane: `~/Developer/Comet-Backend`, same branch as Phase 1. Goal: capture a real brand accent far more often, from more sources, instead of a single favicon/theme-color probe that silently no-ops.

## Task 11: Palette-scan logos + fix the orphaned-consumer docblock

**Files:**
- Modify: `Comet-Backend/app/Jobs/ProcessLogoVariantsJob.php` (or `app/Services/Design/LogoAutoGrabber.php` — whichever owns final logo persistence; confirm which writes the `pool='design'`, `purpose='logo_*'` `site_media` rows)
- Modify: `Comet-Backend/app/Services/Media/ImagePaletteExtractor.php` (docblock C4)
- Test: `Comet-Backend/tests/Feature/WebsiteScan/LogoPaletteScanTest.php` (create)

**Interfaces:**
- Consumes: `ImagePaletteExtractor::fromPath(string): ?array` / `fromGd(\GdImage): ?array` → `{dominant, colors[], saturation, warm}`.
- Produces: logo `site_media` rows carry `dominant_color` + `palette` (same columns gallery images already populate).

- [ ] **Step 1: Confirm the logo persistence point** — trace where `logo_full`/`logo_square` `site_media` rows are written and where their processed bytes/local path are available (mirror how `WebsiteGalleryScanJob` obtains bytes for `ImagePaletteExtractor`).

- [ ] **Step 2: Write the failing test** — process a known logo fixture, assert its `site_media` row gets a non-null `dominant_color`:

```php
it('stores a dominant colour + palette for a scanned logo', function () {
    $media = /* factory: a design-pool logo_full row for a site, with local bytes */;
    (new ProcessLogoVariantsJob($media->id))->handle(app(ImagePaletteExtractor::class), /* deps */);

    $row = DB::connection('pgsql')->table('site.site_media')->where('id', $media->id)->first();
    expect($row->dominant_color)->not->toBeNull();
    expect(json_decode($row->palette, true))->toHaveKey('dominant');
})->group('postgres');
```

- [ ] **Step 3: Implement** — after logo variants are generated, run `ImagePaletteExtractor::fromPath()` on the logo and persist `dominant_color` + `palette` on the row (reuse the exact write shape from the gallery scan path so the columns stay consistent). Best-effort: a null palette must not fail logo processing.

- [ ] **Step 4: Fix C4** — in `ImagePaletteExtractor.php` docblock, replace "feeds `IdentityEvidence::mediaPalette()` → `ImageryPaletteFactor`…" with: "feeds `SiteAccentResolver` (accent fallback) and any future palette consumers; the former factor pipeline was retired."

- [ ] **Step 5: Run** `./vendor/bin/pest --filter="dominant colour"` → PASS; `php artisan pint`.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ProcessLogoVariantsJob.php app/Services/Media/ImagePaletteExtractor.php tests/Feature/WebsiteScan/LogoPaletteScanTest.php
git commit -m "feat(accent): palette-scan logos into site_media; retire orphaned-factor doc (A2, C4)"
```

## Task 12: Extract the shared accent quality gate

**Files:**
- Create: `Comet-Backend/app/Services/WebsiteScan/AccentQuality.php`
- Modify: `Comet-Backend/app/Services/WebsiteScan/WebsiteAccentExtractor.php`
- Test: `Comet-Backend/tests/Unit/WebsiteScan/AccentQualityTest.php` (create)

**Interfaces:**
- Produces: `AccentQuality::qualifies(string $hex): bool` (saturation ≥ 0.3, luminance strictly 0.08–0.92 — the exact current gate) and `AccentQuality::normalizeHex(string): ?string`.

- [ ] **Step 1: Write the failing test**:

```php
it('accepts a saturated mid-luminance hex and rejects near-white/black/grey', function () {
    expect(AccentQuality::qualifies('#105030'))->toBeTrue();
    expect(AccentQuality::qualifies('#ffffff'))->toBeFalse();
    expect(AccentQuality::qualifies('#000000'))->toBeFalse();
    expect(AccentQuality::qualifies('#808080'))->toBeFalse(); // grey: saturation 0
});
```

- [ ] **Step 2: Run** → FAIL (class missing).

- [ ] **Step 3: Implement `AccentQuality`** by moving `qualifies()`/`normalizeHex()` verbatim out of `WebsiteAccentExtractor` (same constants: `MIN_SATURATION=0.3`, `MIN_LUMINANCE=0.08`, `MAX_LUMINANCE=0.92`, and the `$max > 0.0` saturation guard). Then have `WebsiteAccentExtractor` call `AccentQuality::qualifies()`/`normalizeHex()` instead of its private copies.

- [ ] **Step 4: Run** `./vendor/bin/pest --filter=AccentQuality` and the existing `WebsiteAccentExtractor` tests → PASS. `php artisan pint`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WebsiteScan/AccentQuality.php app/Services/WebsiteScan/WebsiteAccentExtractor.php tests/Unit/WebsiteScan/AccentQualityTest.php
git commit -m "refactor(accent): extract shared AccentQuality gate (A4)"
```

## Task 13: `SiteAccentResolver` — priority chain over all brand sources

**Files:**
- Create: `Comet-Backend/app/Services/WebsiteScan/SiteAccentResolver.php`
- Test: `Comet-Backend/tests/Feature/WebsiteScan/SiteAccentResolverTest.php` (create)

**Interfaces:**
- Consumes: `AccentQuality::qualifies()`; `site_media` `dominant_color`/`palette` for the site's logo + gallery rows; theme-color/favicon candidates from the scan.
- Produces: `SiteAccentResolver::resolve(string $siteId, ?string $themeColor, ?string $faviconColor): ?string` — returns the first candidate that `AccentQuality::qualifies()`, in priority order: **theme-color → logo dominant → favicon → gallery/media dominant** → `null`.

- [ ] **Step 1: Write the failing test** covering the fallthrough that broke `broken-oven` (theme-color absent, favicon rejected, but a warm gallery palette exists):

```php
it('falls back to the logo/gallery palette when theme-color and favicon fail', function () {
    $site = /* factory site */;
    // logo row: no qualifying colour; gallery row: warm #ab3516 dominant
    seedMedia($site, purpose: 'logo_full', dominant: null);
    seedMedia($site, pool: 'gallery', dominant: '#ab3516');

    $resolver = app(SiteAccentResolver::class);
    expect($resolver->resolve($site->id, themeColor: null, faviconColor: null))->toBe('#ab3516');
});

it('prefers theme-color over everything, and logo over favicon', function () {
    $site = /* factory site */;
    seedMedia($site, purpose: 'logo_full', dominant: '#123456');
    expect(app(SiteAccentResolver::class)->resolve($site->id, '#7a1fa2', '#999000'))->toBe('#7a1fa2');
    expect(app(SiteAccentResolver::class)->resolve($site->id, null, '#999000'))->toBe('#123456'); // logo > favicon
})->group('postgres');
```

- [ ] **Step 2: Run** → FAIL (class missing).

- [ ] **Step 3: Implement `SiteAccentResolver::resolve()`** — build an ordered candidate list `[themeColor, logoDominant, faviconColor, galleryDominant]` (reading `site_media.dominant_color` for the `pool='design'/purpose LIKE 'logo_%'` row, then the top `pool='gallery'` row), and `return` the first non-null that passes `AccentQuality::qualifies()`, else `null`. Log at `info` when every candidate fails: `Log::info('website_accent.no_candidate', ['site_id' => $siteId]);` (A1 — no longer silent).

- [ ] **Step 4: Run** `./vendor/bin/pest --filter=SiteAccentResolver` → PASS; `php artisan pint`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WebsiteScan/SiteAccentResolver.php tests/Feature/WebsiteScan/SiteAccentResolverTest.php
git commit -m "feat(accent): priority chain resolver (theme-color→logo→favicon→gallery) with no-candidate logging (A1, A3, A4)"
```

## Task 14: `ResolveSiteAccentJob` — run the chain after media scans, fill-if-empty

**Files:**
- Create: `Comet-Backend/app/Jobs/Platforms/ResolveSiteAccentJob.php`
- Modify: `Comet-Backend/app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php` (accent section ~:284-289; logo/gallery dispatch ~:291-316)
- Test: `Comet-Backend/tests/Feature/WebsiteScan/ResolveSiteAccentJobTest.php` (create)

**Interfaces:**
- Consumes: `SiteAccentResolver::resolve()`, `DesignKitAccentApplier::apply(string $siteId, ?string $hex)` (unchanged, fill-if-empty).
- Produces: a queued job that resolves + applies the accent AFTER logo/gallery palettes exist.

- [ ] **Step 1: Write the failing test** — with a warm gallery palette present and `color_accent` null, running the job fills it; a second run does not overwrite a manual accent:

```php
it('fills color_accent from the resolved chain when empty, never overwriting manual', function () {
    $site = /* factory site, sector restaurant */;
    seedMedia($site, pool: 'gallery', dominant: '#ab3516');

    (new ResolveSiteAccentJob($site->id, themeColor: null, faviconColor: null))
        ->handle(app(SiteAccentResolver::class), app(DesignKitAccentApplier::class));
    expect(dkColumn($site, 'color_accent'))->toBe('#ab3516');

    // manual override then re-run: must NOT change
    setDkColumn($site, 'color_accent', '#0000ff');
    (new ResolveSiteAccentJob($site->id, null, null))->handle(/* … */);
    expect(dkColumn($site, 'color_accent'))->toBe('#0000ff');
})->group('postgres');
```

- [ ] **Step 2: Run** → FAIL (job missing).

- [ ] **Step 3: Implement `ResolveSiteAccentJob`** — constructor `(string $siteId, ?string $themeColor, ?string $faviconColor)`; `handle(SiteAccentResolver $resolver, DesignKitAccentApplier $applier)` calls `$applier->apply($this->siteId, $resolver->resolve($this->siteId, $this->themeColor, $this->faviconColor))`. `DesignKitAccentApplier` already no-ops on null and on an existing non-null accent — so the manual-safety is inherited.

- [ ] **Step 4: Rewire `ScanPreviousWebsiteContentJob`** — remove the inline `DesignKitAccentApplier` call at ~:284-289. Capture the `themeColor`/`faviconColor` the extractor already computes, and dispatch `ResolveSiteAccentJob` so it runs AFTER the logo (`ProcessLogoVariantsJob`) and gallery (`WebsiteGalleryScanJob`) palette writes — chain it on their completion (e.g. `Bus::chain([...])` or dispatch with a delay/`->chain()` off the media jobs). Confirm the exact dispatch mechanism those jobs use before wiring; the requirement is strictly ordering (accent resolves last).

- [ ] **Step 5: Run** `./vendor/bin/pest --filter=ResolveSiteAccentJob` and the full `tests/Feature/WebsiteScan/` group → PASS; `php artisan pint`.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/Platforms/ResolveSiteAccentJob.php app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php tests/Feature/WebsiteScan/ResolveSiteAccentJobTest.php
git commit -m "feat(accent): defer accent resolution until after media scans, fill-if-empty (A4)"
```

---

# PHASE 4 — Related cleanup

## Task 15: Drop the orphaned `previous_website_analysis` column

**Files:**
- Create: `Comet-Backend/supabase/migrations/<timestamp>_drop_workplace_previous_website_analysis.sql`
- Modify: `Comet-Backend/app/Models/Core/Site/Workplace.php` (remove the `previous_website_analysis` cast/fillable ~:24,:88) and `app/Services/User/DataExport/DataExportPayloadBuilder.php:277` (drop the defensive exclusion once the column is gone)
- Test: `Comet-Backend/tests/Feature/...DataExport...` (adjust any assertion referencing the column)

- [ ] **Step 1: Confirm zero writers** — grep `previous_website_analysis` across `app/`; the only remaining references should be the model cast, the data-export exclusion, and comments. If any writer exists, STOP and re-scope.

- [ ] **Step 2: Write the migration** (raw SQL, follows `supabase/migrations/CONVENTIONS.md` — single statement, no `CONCURRENTLY` pairing):

```sql
-- Orphaned since the website-style-analysis pipeline (AnalyzePreviousWebsiteJob) was deleted (e66bb911).
ALTER TABLE site.workplaces DROP COLUMN IF EXISTS previous_website_analysis;
```

- [ ] **Step 3: Remove the model cast/fillable + the data-export exclusion.** Run `./vendor/bin/pest` (data-export + workplace suites) → PASS.

- [ ] **Step 4: Apply on dev (autonomous — runs in Stage A▸, AFTER the backend code deploy).** Order matters: deploy the reference-removing code first, THEN drop the column (deploy-then-drop — never drop a column the still-running old code selects). At Stage A▸: `supabase db push --dry-run` then `supabase db push` against ref `glncumufgaqcmqhzwrxm`; confirm the column is gone. Steps 2–3 (write the migration file + model/config edits + SQLite suite) happen inline during Stage A; only the live `db push` waits for the post-deploy point in the sequence.

- [ ] **Step 5: Commit**

```bash
git add supabase/migrations app/Models/Core/Site/Workplace.php app/Services/User/DataExport/DataExportPayloadBuilder.php
git commit -m "chore(cleanup): drop orphaned workplaces.previous_website_analysis (C1)"
```

## Task 16: Remove the orphaned `brand_scan` config + stale workplace comment

**Files:**
- Modify: `Comet-Backend/config/partna.php` (remove the `brand_scan` block ~:1324-1339)
- Modify: `Comet-Backend/app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php` (stale `destroy()` comment ~:160-161)

- [ ] **Step 1: Confirm zero references** — grep `brand_scan`, `PARTNA_BRAND_SCAN`, `BrandScanClient`, `WebsiteStyleAnalyzer` across `app/` + `config/`. Expect none in `app/`. If found, STOP.

- [ ] **Step 2: Delete the `brand_scan` config block** and fix the `UserWorkplaceController::destroy()` comment (it claims `WorkplaceObserver::deleted` sweeps design-preset contributions — that table/concept is gone; state it only clears the workplace row and its media).

- [ ] **Step 3: Verify** `./vendor/bin/pest` (smoke) + `php artisan pint`.

- [ ] **Step 4: Commit**

```bash
git add config/partna.php app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php
git commit -m "chore(cleanup): remove orphaned brand_scan config + stale workplace comment (C2, C3)"
```

---

# PHASE 5 — Additional scope

## Task 17: Don't auto-save the user's previous website as a scraped link

> Lane: `~/Developer/Comet-Backend`, same branch as Phase 1/3.

**Problem:** When we scrape Instagram (bio links), link-in-bio pages, or harvest links off the previous website, we sometimes grab the user's **own previous website** and save it as a custom link (observed on `broken-oven`: a `thebrokenovenpizzabar.com.au` link appeared even though that URL is the account's `previous_website`). The old site is being *replaced* by the Partna page, so it should never re-surface as a link. Skip it — and skip any subpage on the same host — before it's saved.

**Files:**
- Modify: `Comet-Backend/app/Services/Platforms/CustomLinkSeeder.php`
- Test: `Comet-Backend/tests/Feature/Platforms/CustomLinkSeederPreviousWebsiteTest.php` (create)

**Interfaces:**
- Consumes: `LinkCardScraper::normalizeUrl(string): ?string` (already injected as `$this->scraper`); `$user->site?->workplace?->previous_website` (HasOne `User::site` → HasOne `Site::workplace`).
- Produces: `CustomLinkSeeder::seed()` returns `null` (no `IntegrationConnection` created, no `EnrichLinkCardJob` dispatched) when the URL's host equals the previous website's host.

**Why this is the only place needed:** `CustomLinkSeeder::seed()` is the single chokepoint for every auto-scrape link path — `InstagramConnectionSeeder` (bio-link auto-save, `:258`), `LinkInBioScanJob` (`:111`, `:125`), and `WebsiteLinkHarvester`. Manual link-adds go through `CustomLinksController::addLink()`, which does NOT touch this class, so a user can still add their old site by hand if they really want to.

- [ ] **Step 1: Write the failing test** in `CustomLinkSeederPreviousWebsiteTest.php`:

```php
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\CustomLinkSeeder;
use Illuminate\Support\Facades\DB;

it("skips an auto-grabbed link that is the user's previous website (exact, subpage, www)", function () {
    $user = User::factory()->create(); // factory creates the site; if not, create one here
    DB::connection('pgsql')->table('site.workplaces')->updateOrInsert(
        ['site_id' => $user->site->id],
        ['previous_website' => 'https://thebrokenovenpizzabar.com.au/'],
    );

    $seeder = app(CustomLinkSeeder::class);

    expect($seeder->seed($user->fresh(), 'https://thebrokenovenpizzabar.com.au/'))->toBeNull();           // exact
    expect($seeder->seed($user->fresh(), 'https://thebrokenovenpizzabar.com.au/menu'))->toBeNull();       // subpage
    expect($seeder->seed($user->fresh(), 'https://www.thebrokenovenpizzabar.com.au/contact'))->toBeNull();// www variant

    expect(IntegrationConnection::query()
        ->where('user_id', $user->id)->where('platform', 'custom')->count())->toBe(0);
})->group('postgres');

it('still seeds an auto-grabbed link to a different host', function () {
    $user = User::factory()->create();
    DB::connection('pgsql')->table('site.workplaces')->updateOrInsert(
        ['site_id' => $user->site->id],
        ['previous_website' => 'https://thebrokenovenpizzabar.com.au/'],
    );

    expect(app(CustomLinkSeeder::class)->seed($user->fresh(), 'https://www.instagram.com/brokenovenpizzabar'))
        ->not->toBeNull();
})->group('postgres');
```

- [ ] **Step 2: Run it, verify it fails**

Run: `./vendor/bin/pest --filter="previous website"`
Expected: FAIL — the previous-website URLs are currently seeded (count is 3, not 0).

- [ ] **Step 3: Implement the guard** in `CustomLinkSeeder::seed()`, immediately after the `$normalized === null` return (~:43) and before `$rid` is built:

```php
$previousWebsite = $user->site?->workplace?->previous_website;
if ($previousWebsite !== null && $this->matchesPreviousWebsite($normalized, $previousWebsite)) {
    Log::info('platforms.custom_link_seeder.skipped_previous_website', ['user_id' => (string) $user->id]);

    return null;
}
```

Add the helper method to the class:

```php
/**
 * True when $normalizedUrl is the user's previous website or any page on the
 * same host — so a scrape never re-adds the old site we're replacing as a
 * link. Hosts compared lowercased with a leading "www." stripped; an
 * unparseable previous website never matches. Only auto-seeded links reach
 * this class, so manual link-adds are unaffected.
 *
 * NB host-level match: intentional so subpages are caught too. If the previous
 * website is ever a shared-host service (e.g. linktr.ee/<user>), this would
 * also skip other links on that host — acceptable given previous_website is
 * effectively always the user's own domain; revisit only if that assumption breaks.
 */
private function matchesPreviousWebsite(string $normalizedUrl, string $previousWebsite): bool
{
    $prev = $this->scraper->normalizeUrl($previousWebsite);
    if ($prev === null) {
        return false;
    }

    $host = static function (string $url): ?string {
        $h = parse_url($url, PHP_URL_HOST);

        return is_string($h) && $h !== '' ? preg_replace('/^www\./i', '', strtolower($h)) : null;
    };

    $linkHost = $host($normalizedUrl);
    $prevHost = $host($prev);

    return $linkHost !== null && $linkHost === $prevHost;
}
```

`Log` is already imported in this file. No new imports needed.

- [ ] **Step 4: Run it, verify it passes**

Run: `./vendor/bin/pest --filter="previous website"`
Expected: PASS (both examples). If the "different host" example returns null unexpectedly, confirm the `integration.custom` FeatureAvailability gate is open for a fresh factory user (absence of rows = available).

- [ ] **Step 5: `php artisan pint app/Services/Platforms/CustomLinkSeeder.php`**

- [ ] **Step 6: Commit**

```bash
git add app/Services/Platforms/CustomLinkSeeder.php tests/Feature/Platforms/CustomLinkSeederPreviousWebsiteTest.php
git commit -m "feat(links): skip auto-seeding the user's own previous website as a custom link (L1)"
```

---

## Task 18: Instagram reel capture — retry the transient mirror failure, broaden detection, make failures observable

**Problem (revised after live testing — this supersedes the original static-analysis diagnosis):** broken-oven's Instagram scrape mirrored the latest photo but not the latest reel (`videoUrl: null`) despite the account having one. The original investigation proposed two candidates (reel outside the 12-post window; reel present but missing an mp4 field) purely from reading the code. **Both are now ruled out by direct live testing against the real account**, done inline (2 genuine Apify calls, well inside the 200/day cap):

1. `InstagramScraper::fetchProfile('brokenovenpizzabar')` + `latestMedia()`, run live with **today's unmodified code**, found a video cleanly — 4 of the 12 returned posts are `type:'Video'` with real `video_url` fields present. Detection works.
2. The actual mp4 URL from that live video, fetched with the exact HTTP conditions `mirrorVideo()` uses (timeout, headers, no-redirect) — `200`, `video/mp4`, 9.6MB, 4.4s. The mirror fetch works.

Two further structural explanations were checked and also ruled out: the queue is `redis`+Horizon (not inline/sync, so no shared-request timeout risk), and the `scraping` queue's Horizon timeout is **660 seconds** — nowhere near the ~110s+30s worst case. No `failed_jobs` row exists for the connect window either.

**Conclusion:** this was a one-off transient failure — most likely Instagram's short-lived signed CDN URL failing or getting momentarily blocked between being issued and being fetched, which `mirrorVideo()` today accepts as a silent, permanent "no video" with no way to tell it apart from "no reel existed." The forensic trail is gone (no surviving log, no failed_jobs entry — the job completed "successfully" from Laravel's perspective; `mirrorVideo()` just returned null cleanly). **The actual fix for a transient-failure class is a retry**, not a broader detection net — that's the primary change in this task now. The detection-broadening and observability work from the original plan are kept (still real, cheap, and defensively valuable — the broadened detection catches genuine actor-field variance, and the observability is what will show us definitively *which* reason fired if a reel ever fails again even after a retry), but they're no longer positioned as *the* fix.

**Files:**
- Modify: `Comet-Backend/app/Services/Platforms/InstagramScraper.php` (`latestMedia()`, currently lines 184-262)
- Modify: `Comet-Backend/app/Services/Platforms/InstagramConnectionSeeder.php` (`seed()` ~:67-166, `mirrorVideo()` ~:358-429)
- Test: `Comet-Backend/tests/Unit/Platforms/InstagramScraperTest.php` (extend)
- Test: `Comet-Backend/tests/Unit/Platforms/InstagramConnectionSeederMirrorVideoTest.php` (create — no existing test file covers this class)

**Interfaces:**
- Consumes: nothing new.
- Produces: `InstagramScraper::latestMedia(array $profile, ?string $userId): array{photo:?array, video:?array, diagnostics:array{posts:int, videos:int, pickedPhoto:bool, pickedVideo:bool, videoCandidates:list<array{shortCode:?string, hasMp4:bool, type:?string}>}}` — diagnostics key added, capped at 5 `videoCandidates`. `InstagramConnectionSeeder::seed()`'s stored `$selection` gains an internal `_mediaDiagnostics` key (same leading-underscore convention as `_folder`; never added to `InstagramPayload`/`InstagramConnectionResource`, so it can't leak to any wire response — confirmed: `InstagramPayload::fromArray()` only reads keys it explicitly declares, and `InstagramConnectionResource` emits a fixed allowlisted subset).

- [ ] **Step 1: Write the failing broadened-detection test** — add to `InstagramScraperTest.php`:

```php
it('detects a reel via product_type/video_versions when type/videoUrl are absent (actor field variance)', function () {
    $media = (new InstagramScraper)->latestMedia([
        'latestPosts' => [
            [
                // No 'type' key, no 'videoUrl'/'video_url' — only the fields a
                // different actor-version grid node might carry.
                'product_type' => 'clips',
                'display_url' => 'https://scontent.cdninstagram.com/reel-cover.jpg',
                'video_versions' => [['url' => 'https://scontent.cdninstagram.com/reel.mp4']],
                'timestamp' => '2026-07-20T00:00:00.000Z',
                'shortCode' => 'reel1',
            ],
        ],
    ]);

    expect($media['video']['videoUrl'])->toBe('https://scontent.cdninstagram.com/reel.mp4');
});

it('returns diagnostics: total posts, video count, and per-video mp4 presence', function () {
    $media = (new InstagramScraper)->latestMedia([
        'latestPosts' => [
            // A video post the actor gave NO mp4 for — must still count as a
            // video candidate (has_mp4 false), not vanish silently.
            ['type' => 'Video', 'display_url' => 'https://x/cover.jpg', 'timestamp' => '2026-07-20T00:00:00.000Z', 'shortCode' => 'reel1'],
            ['type' => 'Image', 'display_url' => 'https://x/photo.jpg', 'timestamp' => '2026-07-19T00:00:00.000Z', 'shortCode' => 'img1'],
        ],
    ]);

    expect($media['diagnostics'])->toBe([
        'posts' => 2,
        'videos' => 1,
        'pickedPhoto' => true,
        'pickedVideo' => false,
        'videoCandidates' => [
            ['shortCode' => 'reel1', 'hasMp4' => false, 'type' => 'Video'],
        ],
    ]);
});
```

- [ ] **Step 2: Run, verify both fail**

Run: `./vendor/bin/pest --filter="detects a reel via product_type|returns diagnostics"`
Expected: FAIL — first example returns `video: null` (not detected); second has no `diagnostics` key.

- [ ] **Step 3: Implement broadened detection + diagnostics** in `InstagramScraper.php`. Replace the video-branch body (currently lines 235-241) and the trailing `Log::info` diagnostic call (currently lines 253-259) as follows — extract two private helpers and change the return shape:

```php
// Replaces the inline `if (data_get($post, 'type') === 'Video')` branch.
// Broadened 2026-07-24: today's figue-actor grid node reliably carries
// type==='Video' + videoUrl/video_url, but reel-specific variance (a
// clips/igtv product_type, a GraphQL __typename, or an mp4 nested under
// video_versions[0].url instead of a top-level field) has been seen on
// other Meta scrapers and would otherwise silently vanish here.
private function isVideoPost(array $post): bool
{
    if (data_get($post, 'type') === 'Video') {
        return true;
    }
    if (data_get($post, 'is_video') === true) {
        return true;
    }
    $productType = data_get($post, 'product_type') ?? data_get($post, 'productType');
    if (in_array($productType, ['clips', 'igtv', 'feed'], true)) {
        return true;
    }
    $typename = data_get($post, '__typename');

    return in_array($typename, ['GraphVideo', 'XDTGraphVideo'], true);
}

private function videoUrlFromPost(array $post): ?string
{
    $vid = data_get($post, 'videoUrl')
        ?? data_get($post, 'video_url')
        ?? data_get($post, 'video_versions.0.url');

    return is_string($vid) && $vid !== '' ? $vid : null;
}
```

Then in `latestMedia()`'s loop, replace the video branch to call these, and collect diagnostics as you go:

```php
$videoCandidates = [];
// ... inside the foreach ($sorted as $entry) loop, replacing the old branch:
if ($this->isVideoPost($post)) {
    $vid = $this->videoUrlFromPost($post);
    if (count($videoCandidates) < 5) {
        $videoCandidates[] = [
            'shortCode' => data_get($post, 'shortCode'),
            'hasMp4' => $vid !== null,
            'type' => is_string(data_get($post, 'type')) ? data_get($post, 'type') : null,
        ];
    }
    if ($video === null && $vid !== null) {
        $video = ['thumbnailUrl' => $cover, 'videoUrl' => $vid, 'shortCode' => data_get($post, 'shortCode')];
    }
} elseif ($photo === null && $cover !== null) {
    $photo = ['thumbnailUrl' => $cover, 'shortCode' => data_get($post, 'shortCode')];
}
```

Replace the trailing `Log::info('instagram.latest_media', [...])` block and the method's `return` with:

```php
$diagnostics = [
    'posts' => count($posts),
    'videos' => count($videoCandidates),
    'pickedPhoto' => $photo !== null,
    'pickedVideo' => $video !== null,
    'videoCandidates' => $videoCandidates,
];
Log::info('instagram.latest_media', ['user_id' => $userId, ...$diagnostics]);

return ['photo' => $photo, 'video' => $video, 'diagnostics' => $diagnostics];
```

Update the method's return-type docblock (currently `@return array{photo:..., video:...}`) to add `, diagnostics: array{posts:int, videos:int, pickedPhoto:bool, pickedVideo:bool, videoCandidates:list<array{shortCode:?string,hasMp4:bool,type:?string}>}`.

- [ ] **Step 4: Run, verify both pass**

Run: `./vendor/bin/pest --filter="detects a reel via product_type|returns diagnostics"`
Expected: PASS. Then run the FULL existing file to confirm no regression: `./vendor/bin/pest tests/Unit/Platforms/InstagramScraperTest.php` → all PASS (the existing `picks photo cover and video url from the snake_case post shape` test must still pass unchanged — it only exercises the already-working `type:'Video'` + `video_url` path).

- [ ] **Step 5: Write the failing seeder test** — create `InstagramConnectionSeederMirrorVideoTest.php`:

```php
<?php

use App\Services\Platforms\InstagramConnectionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class)->in(__FILE__);

it('logs an observable reason when mirrorVideo drops an oversized reel', function () {
    Log::spy();
    Http::fake([
        'scontent.cdninstagram.com/*' => Http::response(
            str_repeat('x', 1024),
            200,
            ['Content-Type' => 'video/mp4', 'Content-Length' => (string) (60 * 1024 * 1024)], // > 50MB cap
        ),
    ]);

    $seeder = app(InstagramConnectionSeeder::class);
    $method = new ReflectionMethod($seeder, 'mirrorVideo');
    $method->setAccessible(true);
    $result = $method->invoke($seeder, 'https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel.mp4');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'instagram.mirror_video.dropped'
            && $context['reason'] === 'oversize_header'
            && $context['host'] === 'scontent.cdninstagram.com')
        ->once();
});
```

- [ ] **Step 6: Run, verify it fails**

Run: `./vendor/bin/pest --filter="logs an observable reason"`
Expected: FAIL — `mirrorVideo` returns null (correct) but no `instagram.mirror_video.dropped` log was emitted.

- [ ] **Step 7: Make every silent drop observable** — add a private helper to `InstagramConnectionSeeder.php` and call it at each of the five currently-silent return points in `mirrorVideo()` (host disallowed, redirect/non-2xx, content-type mismatch, header-declared oversize, on-disk oversize):

```php
// Every mirrorVideo() drop reason must be observable — a silent null return
// here is indistinguishable from "no reel existed" after the fact, which is
// exactly the ambiguity that made the broken-oven investigation unable to
// confirm root cause. Host only, never the full URL (SEC — avoid logging a
// CDN URL that could carry a signed/expiring token).
private function logVideoMirrorDrop(string $reason, string $url): void
{
    Log::info('instagram.mirror_video.dropped', [
        'reason' => $reason,
        'host' => parse_url($url, PHP_URL_HOST),
    ]);
}
```

Call sites (add immediately before each existing `return null;` in `mirrorVideo()`):
- Host not allowed (currently ~:360-361): `$this->logVideoMirrorDrop('host_not_allowed', $url); return null;`
- Status ≥ 300 (currently ~:386-387): `$this->logVideoMirrorDrop('bad_status_'.$response->status(), $url); return null;`
- Content-Type not video/* (currently ~:390-392): `$this->logVideoMirrorDrop('bad_content_type', $url); return null;`
- Content-Length header oversize (currently ~:398-400): `$this->logVideoMirrorDrop('oversize_header', $url); return null;`
- On-disk filesize oversize (currently ~:404-406): `$this->logVideoMirrorDrop('oversize_actual', $url); return null;`

The two already-`report()`-ed branches (`SafeUrlException` catch, outer `Throwable` catch) are already observable — leave them as-is, don't double-log.

- [ ] **Step 8: Run, verify it passes**

Run: `./vendor/bin/pest --filter="logs an observable reason"`
Expected: PASS.

- [ ] **Step 9: Write the failing retry test — transient failure then success** — the real fix. Add to `InstagramConnectionSeederMirrorVideoTest.php`. First confirm the actual resolved disk name via `MediaDiskResolver`/`mediaDisk()` in `InstagramConnectionSeeder.php` and swap `Storage::fake('r2')` below for whatever that really is if different:

```php
it('retries once on a transient bad status and succeeds on the second attempt', function () {
    \Illuminate\Support\Facades\Storage::fake('r2');
    Http::fake([
        'scontent.cdninstagram.com/*' => Http::sequence()
            ->push('', 403) // transient-looking failure — a momentary CDN block/expired signed URL
            ->push(str_repeat('x', 2048), 200, ['Content-Type' => 'video/mp4', 'Content-Length' => '2048']),
    ]);

    $seeder = app(InstagramConnectionSeeder::class);
    $method = new ReflectionMethod($seeder, 'mirrorVideo');
    $method->setAccessible(true);
    $result = $method->invoke($seeder, 'https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel-retry.mp4');

    expect($result)->not->toBeNull();
    Http::assertSentCount(2);
});

it('does not retry a deterministic failure like the wrong content-type', function () {
    Http::fake([
        'scontent.cdninstagram.com/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $seeder = app(InstagramConnectionSeeder::class);
    $method = new ReflectionMethod($seeder, 'mirrorVideo');
    $method->setAccessible(true);
    $result = $method->invoke($seeder, 'https://scontent.cdninstagram.com/reel.mp4', 'platforms/instagram/test/reel-no-retry.mp4');

    expect($result)->toBeNull();
    // A content-type mismatch is deterministic — retrying wastes a fetch for no
    // reason. Only the two failure classes that plausibly self-resolve (a bad
    // HTTP status, or a connection-level exception) are worth a second attempt.
    Http::assertSentCount(1);
});
```

- [ ] **Step 10: Run, verify both**

Run: `./vendor/bin/pest --filter="retries once on a transient|does not retry a deterministic"`
Expected: FAIL — today's `mirrorVideo()` makes exactly one attempt regardless of outcome, so the first test sees `Http::assertSentCount(2)` fail (only 1 request sent) and the second test already passes coincidentally (also 1 request) — confirm the FIRST one fails before moving on; that's the one this step is proving.

- [ ] **Step 11: Implement the retry** — wrap the existing method body in a bounded retry, retrying only on failure classes that plausibly self-resolve (a non-2xx status, or a connection-level exception — NOT a content-type mismatch, oversize, disallowed host, or `SafeUrlException`, none of which would ever succeed on a bare retry). Rename the current `mirrorVideo` body to `attemptMirrorVideo`, add a `bool &$transient` out-parameter, and add a new thin `mirrorVideo` wrapper. In `InstagramConnectionSeeder.php`:

Add the constant near the existing ones (~:45, alongside `MAX_VIDEO_BYTES`):

```php
    // A reel's CDN URL is short-lived and signed — a bad status or a dropped
    // connection on the first attempt is often a momentary blip, not a real
    // absence of video. One retry, not unbounded: a genuinely oversized or
    // wrong-content-type response would just fail identically again.
    private const VIDEO_MIRROR_MAX_ATTEMPTS = 2;
```

Replace the method signature `private function mirrorVideo(string $url, string $path): ?string` with `private function attemptMirrorVideo(string $url, string $path, bool &$transient): ?string`, then:

1. At the `if ($response->status() >= 300)` branch, change to:
```php
            if ($response->status() >= 300) {
                $transient = true;
                $this->logVideoMirrorDrop('bad_status_'.$response->status(), $url);

                return null;
            }
```
2. Add a new catch clause for connection-level failures, placed BEFORE the existing `catch (Throwable $e)` (PHP matches catch blocks in order — the more specific one must come first):
```php
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $transient = true;
            $this->logVideoMirrorDrop('connection_exception', $url);

            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
```
3. Every OTHER existing `return null;` in the method (host not allowed, `SafeUrlException` catch, content-type mismatch, both oversize checks) stays exactly as-is — `$transient` simply never gets set to `true` on those paths, so the wrapper below won't retry them.

Add the new public-facing wrapper where `mirrorVideo` used to be:

```php
    private function mirrorVideo(string $url, string $path): ?string
    {
        for ($attempt = 1; $attempt <= self::VIDEO_MIRROR_MAX_ATTEMPTS; $attempt++) {
            $transient = false;
            $result = $this->attemptMirrorVideo($url, $path, $transient);

            if ($result !== null || ! $transient) {
                return $result;
            }
            if ($attempt < self::VIDEO_MIRROR_MAX_ATTEMPTS) {
                usleep(300_000); // 300ms — long enough for a momentary CDN blip to clear
            }
        }
        $this->logVideoMirrorDrop('retries_exhausted', $url);

        return null;
    }
```

- [ ] **Step 12: Run, verify both retry tests pass**

Run: `./vendor/bin/pest --filter="retries once on a transient|does not retry a deterministic"`
Expected: PASS.

- [ ] **Step 13: Regression on the earlier drop-observability test** — Step 5's oversized-reel test must still pass unchanged: it's a deterministic drop (`oversize_header`), so `$transient` never gets set and it still returns null on the first attempt with exactly one log call.

Run: `./vendor/bin/pest --filter="logs an observable reason"` → PASS, still `Http::assertSentCount` implicitly 1 (the test doesn't assert this explicitly today — fine to leave as-is, just confirm no regression).

- [ ] **Step 14: Persist diagnostics into the stored connection payload** — in `InstagramConnectionSeeder::seed()`, the line reading `$media = $this->scraper->latestMedia($profile, $userId);` (currently ~:75) already captures the full return. In the `$selection` array being built (currently ~:142-166), add one line alongside the existing `'_folder' => $folder,` entry:

```php
'_mediaDiagnostics' => $media['diagnostics'] ?? null,
```

- [ ] **Step 15: Confirm the new key never reaches a wire response** — add to a relevant existing feature test for the Instagram connect endpoint (find the one asserting the response shape, e.g. a test hitting `POST /api/platforms/instagram/connect` or reading the connection resource) OR add a focused unit assertion:

```php
it('never emits _mediaDiagnostics on the public/dashboard Instagram payload', function () {
    $payload = App\Services\Platforms\Payloads\InstagramPayload::fromArray([
        'username' => 'x', '_mediaDiagnostics' => ['posts' => 1, 'videos' => 0],
    ]);
    // The DTO has no $mediaDiagnostics property — accessing it is a compile-time
    // error, which is itself the guarantee. This test documents that guarantee
    // by asserting the DTO's known, fixed property list stays exactly as before.
    expect(array_keys(get_object_vars($payload)))->not->toContain('mediaDiagnostics');
});
```

Run: `./vendor/bin/pest --filter="never emits _mediaDiagnostics"` → PASS.

- [ ] **Step 16: Full regression + lint**

Run: `./vendor/bin/pest tests/Unit/Platforms/` and `./vendor/bin/pest tests/Feature/Platforms/ --filter=Instagram` (adjust to the repo's actual Instagram feature-test path if different — confirm with `./vendor/bin/pest --list 2>/dev/null | grep -i instagram` first). Expected: all PASS. Then `php artisan pint app/Services/Platforms/InstagramScraper.php app/Services/Platforms/InstagramConnectionSeeder.php`.

- [ ] **Step 17: Commit**

```bash
git add app/Services/Platforms/InstagramScraper.php app/Services/Platforms/InstagramConnectionSeeder.php tests/Unit/Platforms/InstagramScraperTest.php tests/Unit/Platforms/InstagramConnectionSeederMirrorVideoTest.php
git commit -m "fix(instagram): retry a transient video-mirror failure once; broaden reel detection; make every drop observable (R1)"
```

---

## Task 19: Neutral backdrop plate behind the logo on the public sitepage

> Lane: `~/Developer/partna-monorepo` (workspace: `packages/design-system` + `apps/pages`). After editing the package, `npm run build:ds` from the monorepo root before the app sees the change.

**Problem:** the full business logo renders directly on the page background with no backdrop. A light/white logo can vanish on a light `theme_mode` (bleach/dust), and a dark logo can vanish on a dark one (dusk/midnight) — the same asset needs to survive both. Fix: a fixed mid-neutral "plate" behind the logo, identical in every theme mode and in the day/night shift, that gives acceptable contrast to both a near-white and a near-black logo.

**Files:**
- Modify: `partna-monorepo/packages/design-system/src/design-kit/vars.css` (add the token, alongside the existing `--dk-color-error` platform-constant pattern at line 57)
- Modify: `partna-monorepo/packages/design-system/CLAUDE.md` ("Sanctioned var exceptions" list — document the new constant, same section documenting `--dk-color-error`)
- Modify: `partna-monorepo/apps/pages/src/components/ui/Logo.astro` (apply the plate to the `.logo-image` — the raster/uploaded-logo render path only; the wordmark and mask-filled SVG paths correctly use `--ink` and must NOT get a plate, since they already adapt to context by design)
- Test: `partna-monorepo/apps/pages/test/` — confirm the repo's actual vitest test location and pattern first (`ls apps/pages/test/`) before writing; if no existing test touches `Logo.astro` rendering, add a minimal one following whatever pattern the closest existing component test uses.

**Interfaces:**
- Produces: `--dk-color-logo-backdrop` (fixed hex, NOT palette-derived, NOT in `defaults.ts`/`types.ts`/`validate.ts`/any DB column — same "sanctioned exception" class as `--dk-color-error`, confirmed as the correct pattern for a non-editable, non-theme-varying constant).

- [ ] **Step 1: Confirm no existing token already serves this purpose** — `grep -n "backdrop\|plate" packages/design-system/src/design-kit/vars.css packages/design-system/src/design-kit/palettes.ts`. Expected: no hits (new token).

- [ ] **Step 2: Add the token to `vars.css`**, immediately after the `--dk-color-error` block (~line 57), following its exact documented pattern:

```css
  /* --dk-color-logo-backdrop: a SANCTIONED platform constant (not a user knob,
     not palette-derived) — same class as --dk-color-error above. A fixed
     mid-neutral plate behind an uploaded logo so a light OR dark logo stays
     visible regardless of theme_mode or day/night shift. Never overridden by
     the dispatcher or any palette — deliberately identical everywhere. #8a8a8a
     ≈ 54% luminance: gives a near-white logo ~2.3:1 and a near-black logo
     ~4.2:1 contrast against it — acceptable for a decorative backdrop plate,
     not body text. Taste call — revisit in design-loop if it reads wrong
     against a real uploaded logo. */
  --dk-color-logo-backdrop: #8a8a8a;
```

- [ ] **Step 3: Document the exception** in `packages/design-system/CLAUDE.md`, adding one bullet to the existing "Sanctioned var exceptions" list (which already documents `--dk-color-overlay-text`, `--dk-color-panel`, `--dk-color-error`):

```markdown
- **`--dk-color-logo-backdrop`** — a **fixed platform constant**: the plate behind an uploaded logo image, identical in every theme mode and the day/night shift (never dispatcher-varied). No column, no `defaults.ts` entry, not in `types.ts`. Declared directly in `vars.css`.
```

- [ ] **Step 4: Apply the plate in `Logo.astro`** — modify only the `.logo-image` rule (currently ~lines 52-56), which is the raster/uploaded-logo path (`<img class="logo logo-image">`, rendered when `image` is set). Do NOT touch `.logo-mask` (vector logos already self-adapt via `--ink` mask-fill) or `.logo-wordmark` (text, no backdrop needed):

```css
  .logo-image {
    width: auto;
    max-width: calc(var(--logo-height) * 10);
    object-fit: contain;
    /* Neutral plate so a light or dark uploaded logo stays visible regardless
       of theme_mode — same constant in light and dark, see vars.css. */
    background-color: var(--dk-color-logo-backdrop);
    padding: calc(var(--logo-height) * 0.15);
    border-radius: var(--dk-border-radius);
  }
```

- [ ] **Step 5: Rebuild the design-system package**

Run (from monorepo root): `npm run build:ds`
Expected: builds clean, no type errors (the new var is a plain CSS custom property, not a typed `DesignKit` field, so `tsc` has nothing new to check — confirm by running `npm run check:ds` too).

- [ ] **Step 6: Tokens-only audit** (per `apps/pages/CLAUDE.md`) — the new rule is in `Logo.astro`'s own `<style>` block, which is component-scoped CSS (not architecture CSS), so the `grep` audit command (which only scans `src/architectures/*/*.css`) doesn't apply here — confirm by running it anyway and checking it stays empty:

```bash
cd apps/pages
grep -nE '^\s*[a-z-]+\s*:' src/architectures/*/*.css | grep -E '#[0-9a-fA-F]{3,8}' | grep -v 'var(--dk-'
```

Expected: empty (this task added no hex literals to any `architectures/*.css` file — the hex lives solely in `vars.css`, which is the sanctioned location for a platform constant per the "Sanctioned var exceptions" doctrine).

- [ ] **Step 7: Verify visually** — run `npm run dev` (from monorepo root), open a local sitepage preview with a logo set, and confirm the plate renders behind the header logo, unchanged across the 5 theme-mode picker options and the day/night shift toggle. Screenshot before/after for the design-loop record if this repo's convention expects one (`design-loop` skill).

- [ ] **Step 8: Commit**

```bash
git add packages/design-system/src/design-kit/vars.css packages/design-system/CLAUDE.md apps/pages/src/components/ui/Logo.astro
git commit -m "feat(design-kit): fixed mid-neutral backdrop plate behind uploaded logos, same in light/dark (LG1)"
```

**Caveat flagged, not fixed here:** the SQUARE logo's only public render sites today are the favicon cut (`SiteDocument.astro:70`) and the menu-drawer watermark (`MenuDrawer.astro:23`) — neither can carry a CSS backdrop (a favicon is a static image asset; the watermark is deliberately a translucent background decoration, not a foreground mark). If the square logo needs a visible-on-any-background treatment somewhere else (e.g. a future avatar chip), apply the same `--dk-color-logo-backdrop` token there when that render site exists — flag as a fresh task rather than guessing at a UI that isn't built yet.

---

## Task 20: New "Logo" sub-page in the Workplace/Business hub, between Overview and Reviews

> Lane: `~/Developer/Partna-Frontend`.

**Problem:** the business/workplace logo uploads (full + square) currently live buried inside the hub's Overview page, mixed in with contact/address/hours. Give them their own "Logo" page, positioned in the hub's left-hand sub-nav between Overview and Reviews — matching the exact structure Maps/Settings already use in that same hub.

**Grounding:** there is **no top-level "Overview"/"Reviews" nav** (the top-level nav item for this whole area is "Dashboard", per `NAV_ICONS["/account/overview"]` in `account-nav.tsx:54`). The Overview→Reviews adjacency the request refers to is the **Workplace/Business hub's own sub-nav** (`PAGE_SECTIONS["/account/workplace"]` / `PAGE_SECTIONS["/account/business"]`, `account-nav.tsx:135-146`), which already reads `Overview → Reviews → Maps → Settings`. This task inserts `Logo` as the second entry: `Overview → Logo → Reviews → Maps → Settings`. The logo UI itself already exists as a complete, working component (`SitepageLogosSection`) currently mounted on the hub's Overview page (`_brand-page.tsx:14,55`) — this task **moves** it to its own route rather than rebuilding it.

**Files:**
- Modify: `Partna-Frontend/components/shells/account-nav.tsx` (`PAGE_SECTIONS` entries for both `/account/workplace` and `/account/business`, ~lines 135-146)
- Modify: `Partna-Frontend/app/(app)/account/(dashboard)/workplace/_brand-page.tsx` (remove the inline `<SitepageLogosSection>` mount, ~line 55)
- Create: `Partna-Frontend/app/(app)/account/(dashboard)/workplace/logo/page.tsx`
- Create: `Partna-Frontend/app/(app)/account/(dashboard)/business/logo/page.tsx`
- Create: `Partna-Frontend/app/(app)/account/(dashboard)/workplace/logo-page.tsx` (shared body both thin routes render — mirrors how `reviews/page.tsx` renders a shared `GoogleBusinessReviewsPage` from `../google-business-pages`)
- Test: none of the sibling sub-pages (`maps`, `settings`, `reviews`) have dedicated tests — this repo's convention for these hub pages is `npm run typecheck && npm run lint` + manual verification, not unit tests. Match that; do not invent a test file where the pattern doesn't have one.

**Interfaces:**
- Consumes: `SitepageLogosSection({ noun }: { noun: "Business" | "Workplace" })` — unchanged, moved wholesale.
- Produces: routes `/account/workplace/logo` and `/account/business/logo`.

- [ ] **Step 1: Confirm the cross-redirect + variant pattern one more sibling uses** — read `Partna-Frontend/app/(app)/account/(dashboard)/workplace/maps/page.tsx` and its business counterpart to confirm they follow the exact same thin-wrapper shape as `reviews/page.tsx` (already read: a 6-line `"use client"` component calling a shared `<XPage variant="workplace"/>`). If `maps` differs, follow whichever pattern is actually consistent across the existing 3 sub-pages (reviews/maps/settings) — don't invent a fourth shape.

- [ ] **Step 2: Add the "Logo" entry to both `PAGE_SECTIONS` arrays** in `account-nav.tsx`. Current (lines 135-146):

```ts
  "/account/workplace": [
    { href: "/account/workplace", label: "Overview", icon: LayoutGrid },
    { href: "/account/workplace/reviews", label: "Reviews", icon: Star },
    { href: "/account/workplace/maps", label: "Maps", icon: MapPin },
    { href: "/account/workplace/settings", label: "Settings", icon: Settings },
  ],
  "/account/business": [
    { href: "/account/business", label: "Overview", icon: LayoutGrid },
    { href: "/account/business/reviews", label: "Reviews", icon: Star },
    { href: "/account/business/maps", label: "Maps", icon: MapPin },
    { href: "/account/business/settings", label: "Settings", icon: Settings },
  ],
```

Change to (insert `Logo` as the second row in each; import `Spline` from `lucide-react` at the top alongside the existing icon imports — it's already imported in `sitepage-logos-section.tsx` for the same concept, so it's the established icon for "logo" in this codebase):

```ts
  "/account/workplace": [
    { href: "/account/workplace", label: "Overview", icon: LayoutGrid },
    { href: "/account/workplace/logo", label: "Logo", icon: Spline },
    { href: "/account/workplace/reviews", label: "Reviews", icon: Star },
    { href: "/account/workplace/maps", label: "Maps", icon: MapPin },
    { href: "/account/workplace/settings", label: "Settings", icon: Settings },
  ],
  "/account/business": [
    { href: "/account/business", label: "Overview", icon: LayoutGrid },
    { href: "/account/business/logo", label: "Logo", icon: Spline },
    { href: "/account/business/reviews", label: "Reviews", icon: Star },
    { href: "/account/business/maps", label: "Maps", icon: MapPin },
    { href: "/account/business/settings", label: "Settings", icon: Settings },
  ],
```

Add `Spline` to the `lucide-react` import block at the top of the file (alongside `Star`, `Settings`, etc. — insert alphabetically per the existing ordering).

- [ ] **Step 3: Create the shared Logo page body** — `Partna-Frontend/app/(app)/account/(dashboard)/workplace/logo-page.tsx` (co-located with `_brand-page.tsx`, same cross-redirect pattern):

```tsx
// logo-page.tsx — the shared Workplace/Business "Logo" sub-page body. Moved
// out of _brand-page.tsx's Overview so logo uploads get their own left-nav
// row (between Overview and Reviews), same split as Reviews/Maps/Settings.
"use client"

import { useEffect } from "react"
import { useRouter } from "next/navigation"
import { Skeleton } from "@/components/ui/skeleton"
import { PageHeader } from "@/components/shells/page-header"
import { useMaybeAccount } from "@/lib/hooks/use-account"
import { SitepageLogosSection } from "./sitepage-logos-section"

export function LogoPage({ variant }: { variant: "workplace" | "business" }) {
  const account = useMaybeAccount()
  const router = useRouter()
  const accountLoading = account === undefined

  const isBusiness = account?.professionalAccountType === "business"
  const belongsHere = variant === (isBusiness ? "business" : "workplace")

  useEffect(() => {
    if (accountLoading || belongsHere) return
    router.replace(isBusiness ? "/account/business/logo" : "/account/workplace/logo")
  }, [accountLoading, belongsHere, isBusiness, router])

  if (accountLoading || !belongsHere) {
    return (
      <div className="flex flex-col gap-8">
        <div className="flex flex-col gap-1">
          <Skeleton className="h-9 w-52" />
          <Skeleton className="h-4 w-96 max-w-full" />
        </div>
        <Skeleton className="h-40 w-full rounded-lg" />
        <Skeleton className="h-40 w-full rounded-lg" />
      </div>
    )
  }

  const noun = isBusiness ? "Business" : "Workplace"

  return (
    <div className="flex flex-col gap-8">
      <PageHeader
        title="Logo"
        description={`Your ${noun.toLowerCase()} logo and square logo, shown across your site.`}
      />
      <SitepageLogosSection noun={noun} />
    </div>
  )
}
```

- [ ] **Step 4: Create the two thin route files**

`Partna-Frontend/app/(app)/account/(dashboard)/workplace/logo/page.tsx`:

```tsx
// /account/workplace/logo — logo uploads (partna accounts). Business accounts
// cross-redirect to /account/business/logo.
"use client"

import { LogoPage } from "../logo-page"

export default function WorkplaceLogoPage() {
  return <LogoPage variant="workplace" />
}
```

`Partna-Frontend/app/(app)/account/(dashboard)/business/logo/page.tsx`:

```tsx
// /account/business/logo — logo uploads (business accounts). Partna accounts
// cross-redirect to /account/workplace/logo.
"use client"

import { LogoPage } from "../../workplace/logo-page"

export default function BusinessLogoPage() {
  return <LogoPage variant="business" />
}
```

(Confirm at Step 1 whether `business/*` sub-pages actually import their shared body from the `workplace/` sibling directory this way, or whether `business/` has its own colocated copy — mirror whichever the existing `maps`/`reviews`/`settings` trio actually does. The plan assumes the former based on `_brand-page.tsx` and `reviews/page.tsx`'s shown import shapes; adjust the import path in this step to match if Step 1 finds otherwise.)

- [ ] **Step 5: Remove the logo section from the Overview page** — in `_brand-page.tsx`, delete the import (line 14: `import { SitepageLogosSection } from "./sitepage-logos-section"`) and its render call (line 55: `<SitepageLogosSection noun={noun} />`). Update the `subtitle` copy (lines 47-49) to drop "and logos" since it's no longer on this page:

```tsx
  const subtitle = isBusiness
    ? "Your business name, contact details, industry and hours, shown across your site."
    : "Your workplace name, contact details, industry and hours, shown across your site."
```

- [ ] **Step 6: Confirm no route-allowlist gap** — `Partna-Frontend/lib/account-capabilities.ts` gates routes via `allowedRoutePrefixes` (prefix match, `isAccountPathAllowed`/`isPrefixMatch`, read at Step 1 of this task's grounding). Since `/account/workplace/logo` and `/account/business/logo` are children of the already-allowlisted `/account/workplace` / `/account/business` prefixes, `isPrefixMatch` (`pathname.startsWith(\`${prefix}/\`)`) already covers them — confirm by grepping `allowedRoutePrefixes` definitions for `/account/workplace` / `/account/business` and checking they're declared as prefixes (not exact-match-only) before skipping this step for real.

- [ ] **Step 7: Verify**

```bash
npm run typecheck && npm run lint
```

Manual: load `/account/workplace/logo` (or `/business/logo` for a business account) — confirm the left sub-nav shows `Overview, Logo, Reviews, Maps, Settings` in that order with the row highlighting correctly active on Logo; confirm both logo upload tiles work exactly as before (upload/replace/remove); confirm Overview no longer shows the logo cards; confirm the wrong-account-type cross-redirect still works (visit `/account/business/logo` as a partna account → redirects to `/account/workplace/logo`).

- [ ] **Step 8: Commit**

```bash
git add components/shells/account-nav.tsx "app/(app)/account/(dashboard)/workplace/_brand-page.tsx" "app/(app)/account/(dashboard)/workplace/logo-page.tsx" "app/(app)/account/(dashboard)/workplace/logo/page.tsx" "app/(app)/account/(dashboard)/business/logo/page.tsx"
git commit -m "feat(workplace): move logo uploads to their own Logo sub-page between Overview and Reviews (LG2)"
```

---

## Task 21: Re-fetch full Google Business reviews once a claimed account owns the listing

> Lane: `~/Developer/Comet-Backend`, same branch as Phase 1/3.

**Problem:** `GoogleBusinessPayload::stripThirdPartyPii()` (`GoogleBusinessPayload.php:112-127`) deliberately deletes the `reviews` array (real third parties' names/photos/text) whenever a Google Business connection is built through the pre-account/signup flow — `GoogleBusinessSourceGenerator.php:72` calls it directly, and broken-oven went through exactly this path (`built_via: signup`). This is correct privacy behavior for an *unclaimed* listing nobody has proven ownership of. The gap: **nothing re-fetches once the build is actually claimed.** Traced every `claimed_at` write and every `GoogleBusinessEnrichJob::dispatch()` call site — `ClaimSiteService::claim()` (`ClaimSiteService.php:111-113`) only flips the timestamp; it never triggers a fresh, unstripped Place Details fetch. The one-time stripped snapshot from before anyone owned the page stays that way forever, even though the payload DTO's own docblock says authenticated/owned paths are supposed to "keep the full payload." Confirmed empirically: broken-oven's connection payload has `rating: 4.9`, `reviewCount: 44` (never stripped — only `reviews` and `photos[].authors` are), and zero entries under `reviews`.

The backend's own field mask (`GoogleBusinessService.php:117`) already requests `reviews` from Google — this is not a Google API gap, purely a "we deleted it and never got it back" gap.

**Files:**
- Create: `Comet-Backend/app/Jobs/Platforms/ReEnrichClaimedGoogleBusinessReviewsJob.php`
- Modify: `Comet-Backend/app/Services/PreAccount/ClaimSiteService.php` (post-commit block, ~:135-141 — dispatch alongside the existing `SyncSubdomainToKvJob::dispatch(...)`)
- Create: `Comet-Backend/app/Console/Commands/BackfillClaimedGoogleBusinessReviewsCommand.php` (remediates already-claimed accounts — including broken-oven itself — whose payload predates this fix; mirrors the existing `BackfillPreviousWebsiteContentScanCommand` pattern in this codebase for exactly this "gap between an observer-driven fix and accounts that already exist" situation)
- Test: `Comet-Backend/tests/Feature/Platforms/ReEnrichClaimedGoogleBusinessReviewsJobTest.php` (create)

**Interfaces:**
- Consumes: `GoogleBusinessService::fetchPlaceDetails(string $placeId, string $userId, array $priorPhotos = []): ?array` (unchanged); `GoogleBusinessPayload::fromArray(mixed $payload): self` (unchanged, verbatim DTO — no `stripThirdPartyPii()` call here, since this job runs ONLY for claimed/owned accounts).
- Produces: `ReEnrichClaimedGoogleBusinessReviewsJob(string $userId)` — idempotent: no-ops when there's no `google-business` connection, no stored `placeId`, or `reviews` is already present.

- [ ] **Step 1: Write the failing test** — a claimed user's stripped payload gets `reviews` filled in:

```php
<?php

use App\Jobs\Platforms\ReEnrichClaimedGoogleBusinessReviewsJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class)->in(__FILE__);

it('fills in reviews for a claimed user whose payload was stripped at pre-account build time', function () {
    $user = User::factory()->create(['status' => 'active']);
    IntegrationConnection::factory()->create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'place_id' => 'ChIJtestPlaceId',
        // Stripped shape: rating/reviewCount survive stripThirdPartyPii, reviews does not.
        'payload' => ['placeId' => 'ChIJtestPlaceId', 'name' => 'Test Cafe', 'rating' => 4.7, 'reviewCount' => 12],
    ]);

    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'rating' => 4.7,
            'userRatingCount' => 12,
            'reviews' => [
                ['authorAttribution' => ['displayName' => 'A Real Person'], 'rating' => 5, 'text' => ['text' => 'Great!'], 'publishTime' => '2026-07-01T00:00:00Z'],
            ],
        ], 200),
    ]);

    (new ReEnrichClaimedGoogleBusinessReviewsJob((string) $user->id))->handle(app(\App\Services\Platforms\GoogleBusinessService::class));

    $row = $user->integrationConnections()->where('platform', 'google-business')->first();
    expect($row->payload['reviews'] ?? null)->not->toBeNull();
    expect($row->payload['reviews'])->toHaveCount(1);
    // Fields the stripped payload already had (name, placeId) survive the merge.
    expect($row->payload['name'])->toBe('Test Cafe');
});
```

- [ ] **Step 2: Run it, verify it fails**

Run: `./vendor/bin/pest --filter="fills in reviews for a claimed user"`
Expected: FAIL — job class doesn't exist yet.

- [ ] **Step 3: Implement the job**:

```php
<?php

namespace App\Jobs\Platforms;

use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Re-fetches Place Details WITHOUT GoogleBusinessPayload::stripThirdPartyPii()
 * once a pre-account build is claimed — the privacy reason for stripping
 * (an unclaimed listing nobody has proven ownership of) no longer applies to
 * an authenticated, owned account. Idempotent and cheap to over-dispatch: it
 * no-ops immediately if there's nothing to do, so ClaimSiteService can fire
 * it unconditionally on every claim rather than pre-checking connection state
 * itself.
 */
class ReEnrichClaimedGoogleBusinessReviewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $userId) {}

    public function handle(GoogleBusinessService $service): void
    {
        $row = IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', Platform::GoogleBusiness->value)
            ->first();

        if ($row === null || $row->place_id === null) {
            return; // never connected Google Business — nothing to enrich
        }

        $existing = GoogleBusinessPayload::fromArray($row->payload)->toArray();
        if (isset($existing['reviews'])) {
            return; // already has reviews (manual connect, or already re-enriched) — no-op
        }

        try {
            $details = $service->fetchPlaceDetails($row->place_id, $this->userId);
        } catch (PlacesBudgetExhaustedException $e) {
            report($e);

            return;
        }

        if ($details === null || ! isset($details['reviews'])) {
            return; // fetch failed, or Google itself has no reviews for this place — nothing to merge
        }

        $key = CacheKeyGenerator::platformConnectionLock(Platform::GoogleBusiness->value, $this->userId);
        Cache::lock($key, 10)->block(5, function () use ($row): void {
            $fresh = IntegrationConnection::query()->whereKey($row->id)->lockForUpdate()->first();
            if ($fresh === null) {
                return;
            }
            $current = GoogleBusinessPayload::fromArray($fresh->payload)->toArray();
            if (isset($current['reviews'])) {
                return; // lost the race to a concurrent enrich — don't clobber
            }
            // Manual (non-strip) fields the user or a later connect may have set take
            // priority over this re-fetch's snapshot; only newly-available keys (chiefly
            // `reviews`) are meant to land here.
            $fresh->forceFill(['payload' => [...$current, ...$details]])->saveQuietly();
        });
    }
}
```

Add `$this->onQueue(config('partna.queues.scraping', 'scraping'));` in the constructor if this codebase's convention requires an explicit queue assignment for Places-API-calling jobs (confirm against how `GoogleBusinessEnrichJob` itself declares its queue before deciding — match it, don't invent a new one).

- [ ] **Step 4: Run it, verify it passes**

Run: `./vendor/bin/pest --filter="fills in reviews for a claimed user"` → PASS.

- [ ] **Step 5: Write the failing idempotency test** — a connection that already has reviews is left untouched, no Places API call made:

```php
it('is a no-op when reviews are already present', function () {
    $user = User::factory()->create(['status' => 'active']);
    IntegrationConnection::factory()->create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'place_id' => 'ChIJtestPlaceId',
        'payload' => ['placeId' => 'ChIJtestPlaceId', 'reviews' => [['text' => ['text' => 'already here']]]],
    ]);
    Http::fake(); // any request at all fails the test below

    (new ReEnrichClaimedGoogleBusinessReviewsJob((string) $user->id))->handle(app(\App\Services\Platforms\GoogleBusinessService::class));

    Http::assertNothingSent();
});

it('is a no-op when the user never connected Google Business', function () {
    $user = User::factory()->create(['status' => 'active']);
    Http::fake();

    (new ReEnrichClaimedGoogleBusinessReviewsJob((string) $user->id))->handle(app(\App\Services\Platforms\GoogleBusinessService::class));

    Http::assertNothingSent();
});
```

- [ ] **Step 6: Run, verify both fail then pass**

Run: `./vendor/bin/pest --filter="no-op"` → both should already PASS given Step 3's implementation (the early-return guards were written in from the start) — if either fails, the guard ordering in Step 3 needs fixing before continuing.

- [ ] **Step 7: Hook the dispatch into the claim flow** — in `ClaimSiteService.php`, add to the post-commit block (currently ~:135-141, alongside the existing `SyncSubdomainToKvJob::dispatch(...)` line):

```php
        $this->userCache->invalidateUser($result['professional']);
        $this->siteCache->invalidateSite($result['site']);
        SyncSubdomainToKvJob::dispatch((string) $result['professional']->id);
        \App\Jobs\Platforms\ReEnrichClaimedGoogleBusinessReviewsJob::dispatch((string) $result['professional']->id);
```

(Add the proper `use App\Jobs\Platforms\ReEnrichClaimedGoogleBusinessReviewsJob;` import at the top instead of the inline FQCN shown above — written inline here only so this snippet is unambiguous against the surrounding code.)

- [ ] **Step 8: Write a feature test pinning the claim-triggers-dispatch behavior**

```php
it('dispatches the reviews re-enrich job when a build is claimed', function () {
    \Illuminate\Support\Facades\Queue::fake();
    // ... reuse this repo's existing claim-flow test setup (a pre-account build +
    // verified-email claim request) — see tests/Feature/PreAccount/ for the
    // established pattern rather than re-deriving one here.
    // ... perform the claim ...
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Platforms\ReEnrichClaimedGoogleBusinessReviewsJob::class);
});
```

Fill in the claim-flow setup by copying the pattern from an existing passing test in `tests/Feature/PreAccount/` (e.g. whichever test already exercises `ClaimSiteService::claim()` end to end) rather than hand-deriving new setup — the exact fixture shape (build state, verified email, subdomain) must match what `claim()` actually expects, and that test already has it right.

- [ ] **Step 9: Run, verify it passes**

Run: `./vendor/bin/pest --filter="dispatches the reviews re-enrich job"` → PASS.

- [ ] **Step 10: Write the backfill command** — remediates accounts claimed BEFORE this fix shipped (broken-oven included):

```php
<?php

namespace App\Console\Commands;

use App\Jobs\Platforms\ReEnrichClaimedGoogleBusinessReviewsJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Console\Command;

// One-off remediation for accounts claimed before ReEnrichClaimedGoogleBusinessReviewsJob
// existed — their google-business payload was PII-stripped at pre-account build
// time and nothing has re-fetched since. Safe to re-run: the job itself is the
// idempotency guard (no-ops when reviews are already present).
class BackfillClaimedGoogleBusinessReviewsCommand extends Command
{
    protected $signature = 'google-business:backfill-claimed-reviews {--dry-run}';

    protected $description = 'Dispatch the reviews re-enrich job for every claimed account with a Google Business connection and no reviews yet.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $userIds = IntegrationConnection::query()
            ->where('platform', Platform::GoogleBusiness->value)
            ->whereNotNull('place_id')
            ->whereRaw("payload -> 'reviews' IS NULL")
            ->whereIn('user_id', User::query()->where('status', 'active')->select('id'))
            ->pluck('user_id');

        $this->info("Found {$userIds->count()} claimed account(s) with a Google Business connection missing reviews.");

        if ($dryRun) {
            return self::SUCCESS;
        }

        foreach ($userIds as $userId) {
            ReEnrichClaimedGoogleBusinessReviewsJob::dispatch((string) $userId);
        }

        $this->info('Dispatched.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 11: Write a test for the backfill command**

```php
it('dispatches the re-enrich job only for claimed accounts missing reviews', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $needsBackfill = User::factory()->create(['status' => 'active']);
    IntegrationConnection::factory()->create([
        'user_id' => $needsBackfill->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'place_id' => 'p1', 'payload' => ['placeId' => 'p1'],
    ]);

    $alreadyHasReviews = User::factory()->create(['status' => 'active']);
    IntegrationConnection::factory()->create([
        'user_id' => $alreadyHasReviews->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'place_id' => 'p2', 'payload' => ['placeId' => 'p2', 'reviews' => [['text' => ['text' => 'x']]]],
    ]);

    $this->artisan('google-business:backfill-claimed-reviews')->assertSuccessful();

    \Illuminate\Support\Facades\Queue::assertPushed(ReEnrichClaimedGoogleBusinessReviewsJob::class, fn ($job) => $job->userId === (string) $needsBackfill->id);
    \Illuminate\Support\Facades\Queue::assertNotPushed(ReEnrichClaimedGoogleBusinessReviewsJob::class, fn ($job) => $job->userId === (string) $alreadyHasReviews->id);
});
```

- [ ] **Step 12: Run, verify it passes; full regression + lint**

Run: `./vendor/bin/pest --filter="backfill"` then `./vendor/bin/pest tests/Feature/Platforms/` and `php artisan pint app/Jobs/Platforms/ReEnrichClaimedGoogleBusinessReviewsJob.php app/Services/PreAccount/ClaimSiteService.php app/Console/Commands/BackfillClaimedGoogleBusinessReviewsCommand.php`. Expected: all PASS/clean.

- [ ] **Step 13: Commit**

```bash
git add app/Jobs/Platforms/ReEnrichClaimedGoogleBusinessReviewsJob.php app/Services/PreAccount/ClaimSiteService.php app/Console/Commands/BackfillClaimedGoogleBusinessReviewsCommand.php tests/Feature/Platforms/ReEnrichClaimedGoogleBusinessReviewsJobTest.php
git commit -m "feat(google-business): re-fetch unstripped reviews on claim; backfill already-claimed accounts (REV1)"
```

- [ ] **Step 14 (autonomous, post-deploy — runs in Stage A▸ of the Execution Order):** once Stage A has shipped to `development` and the deploy is verified healthy, run `cloud command:run partna development "google-business:backfill-claimed-reviews"` to remediate broken-oven and every other already-claimed account. Then confirm it worked: `cloud env:logs partna development --minutes 5` shows the dispatched jobs, and a `cloud tinker development` check confirms broken-oven's google-business connection payload now has a non-empty `reviews` array. This is the direct real-world fix for the account you noticed the bug on.

---

## Task 22: Point the dashboard's Maps key fetch at the endpoint that still exists

> Lane: `~/Developer/Partna-Frontend`. **Correction to what I said earlier in this conversation:** I initially concluded the fix was to re-register a missing public route. Reading the full `PublicConfigController` docblock changed that — the public route's removal was a *deliberate* security hardening (tagged `SEC-1`), not an accidental gap, so re-adding it would undo that fix. The actual bug is the frontend never got updated when the backend intentionally moved this endpoint.

**Problem:** `GoogleBusinessMapsSection` needs a browser-restricted Google Maps key to render the map embed/street view, fetched via `fetchMapsKey()` → `GET {api}/public/config/integrations` (`Partna-Frontend/lib/hooks/use-google-business-connect.ts:37-49`). That path returns a live 404:
```
$ curl https://dev-api.partna.au/api/public/config/integrations
{"message":"Endpoint not found"}
```
Per `PublicConfigController.php:51-56`'s own docblock: *"public-surface/SEC-1: moved behind `user.api` auth from the former unauthenticated `/public/config/integrations` — the only named consumer is this logged-in dashboard, so there's no product reason to serve it pre-auth."* The route now only exists as the **authenticated** `GET /api/config/integrations` (`routes/api/user.php:52`, named `user.config.integrations`). The frontend hook was never updated to follow.

`fetchMapsKey()`'s `catch` swallows the 404 to `cachedMapsKey = null`, so `GoogleBusinessMapsSection`'s render condition (`saved && mapsKey && (saved.placeId || ...)`) always fails on `mapsKey` alone — even though `saved.placeId` **is** present and correctly exposed (confirmed in `GoogleBusinessConnectionResource::ENRICHMENT_KEYS`, `GoogleBusinessConnectionResource.php:22-31`). The misleading part: "No location data yet" displays even though the location data is right there — it's the map *key* fetch that's broken. This affects every account's Maps page, not just broken-oven's.

Confirmed the fix direction is safe: `fetchMapsKey`/`searchPlaces` (this same hook) have exactly one consumer file, `google-business-details.tsx`, which only renders inside the authenticated `/account/workplace` and `/account/business` dashboard routes — there is no unauthenticated caller (pre-account signup's own Google Business search uses a different, separate mechanism) that would break if this now requires auth.

**Files:**
- Modify: `Partna-Frontend/lib/hooks/use-google-business-connect.ts` (`fetchMapsKey()`, lines 37-49)
- Test: `Partna-Frontend/lib/hooks/use-google-business-connect.test.ts` (create — no existing test file covers this hook; `lib/hooks/use-pre-account-build.test.ts` is this repo's established sibling pattern to follow)

**Interfaces:**
- Consumes: `authedJsonRequest<T = JsonRecord>(path: string, options?: {method?, body?, showToast?}): Promise<T>` from `@/lib/backend-account` (`lib/backend-account.ts:199-218`) — throws `ApiClientError` on non-2xx, returns the raw parsed body (no `.data` unwrap, confirmed against this same helper's existing usage for `GET /site` elsewhere in this plan).

- [ ] **Step 1: Write the failing test**:

```ts
import { describe, expect, it, vi, beforeEach } from "vitest"

const authedJsonRequestMock = vi.fn()
vi.mock("@/lib/backend-account", () => ({
  authedJsonRequest: (...args: unknown[]) => authedJsonRequestMock(...args),
}))

describe("fetchMapsKey", () => {
  beforeEach(() => {
    vi.resetModules()
    authedJsonRequestMock.mockReset()
  })

  it("requests the authenticated /config/integrations endpoint, not the removed public one", async () => {
    authedJsonRequestMock.mockResolvedValueOnce({ googleMapsApiKey: "test-key-123" })
    const { fetchMapsKey } = await import("./use-google-business-connect")

    const key = await fetchMapsKey()

    expect(key).toBe("test-key-123")
    expect(authedJsonRequestMock).toHaveBeenCalledWith("/config/integrations")
  })

  it("returns null when the request fails, without throwing", async () => {
    authedJsonRequestMock.mockRejectedValueOnce(new Error("network error"))
    const { fetchMapsKey } = await import("./use-google-business-connect")

    await expect(fetchMapsKey()).resolves.toBeNull()
  })
})
```

(Adjust the mock/import style to match whichever test runner + module-mocking convention `use-pre-account-build.test.ts` already uses in this repo — read that file first and mirror its exact setup rather than assuming Vitest globals are pre-configured this way.)

- [ ] **Step 2: Run, verify it fails**

Run: `npm run test -- use-google-business-connect`
Expected: FAIL — `fetchMapsKey` still calls raw `fetch` against the dead public path.

- [ ] **Step 3: Implement the fix** — replace the current body of `fetchMapsKey()`:

```ts
export async function fetchMapsKey(): Promise<string | null> {
  if (cachedMapsKey !== undefined) return cachedMapsKey
  try {
    const body = await authedJsonRequest<{ googleMapsApiKey?: string | null }>("/config/integrations")
    cachedMapsKey = body?.googleMapsApiKey ?? null
  } catch {
    cachedMapsKey = null
  }
  return cachedMapsKey
}
```

Add `import { authedJsonRequest } from "@/lib/backend-account"` to the file's imports. Remove the now-unused `getApiBaseUrl` import from this file **only if** nothing else in it still uses it (check `searchPlaces` and any other function in the same file before removing).

- [ ] **Step 4: Run, verify it passes**

Run: `npm run test -- use-google-business-connect` → PASS.

- [ ] **Step 5: Verify manually** — load `/account/workplace/maps` (or `/business/maps`) for an account with a Google Business connection (broken-oven works). Confirm the map embed now renders instead of "No location data yet." Confirm street view still renders when available. Confirm the dashboard's address-autocomplete field (this same key's other real consumer, per `PublicConfigController.php:58-59`) still works — it already used the authenticated route, so this should be unaffected, but verify nothing regressed.

- [ ] **Step 6: Typecheck + lint**

```bash
npm run typecheck && npm run lint
```

- [ ] **Step 7: Commit**

```bash
git add lib/hooks/use-google-business-connect.ts lib/hooks/use-google-business-connect.test.ts
git commit -m "fix(google-business): fetch the maps key from the authenticated endpoint the backend actually kept (MAP1)"
```

---

> **Phase 5 remains open** for the further items you mentioned. Fold each in here as a new task with the same TDD structure — failing test → verify fail → minimal implementation → verify pass → commit.

---

## Verification (whole plan)

- **Every tick obeys Verification Discipline** — multiple cases (not one), fix anything the checks surface, real recorded evidence — and every live-pipeline fix additionally passes the **Stage E broken-oven re-test** with real data before it's called done.
- Backend: `composer test` green; `php artisan pint` clean; dev migration applied; `cloud env:logs partna development --minutes 10` shows `website_accent.no_candidate` only when genuinely no brand colour exists.
- Frontend: `npm run typecheck && npm run lint` green; on `dev-api.partna.au`, a fresh restaurant account (only accent scanned) shows the editor with General Sans (Auto), weight Light (Auto), the scanned accent, "Reset to auto" working, and NO cross-account bleed after switching accounts.
- End-to-end: editor values match the live sitepage for the same account (the two reads now agree).
- Links: a scraped account whose `previous_website` is set never gets that URL (or a subpage of it) auto-saved as a custom link; a genuinely different host still seeds normally (L1).
- Instagram: `./vendor/bin/pest tests/Unit/Platforms/InstagramScraperTest.php tests/Unit/Platforms/InstagramConnectionSeederMirrorVideoTest.php` green; a video-mirror fetch that fails on a transient status/connection error now succeeds on retry instead of permanently dropping the reel, and whatever still fails after the retry leaves a logged reason + a `_mediaDiagnostics` trail on the stored connection instead of an unexplained `videoUrl: null` (R1).
- Logo: `npm run build:ds` clean in partna-monorepo; a light-on-light and a dark-on-dark test logo both stay visible on the live sitepage header, identical across all 5 theme modes and the day/night shift (LG1); `/account/workplace/logo` (and `/business/logo`) renders between Overview and Reviews in the hub sub-nav with both upload tiles working (LG2).
- Reviews: `./vendor/bin/pest tests/Feature/Platforms/ReEnrichClaimedGoogleBusinessReviewsJobTest.php` green; broken-oven's dashboard shows real reviews after the post-deploy backfill command runs (REV1).
- Maps: `npm run typecheck && npm run lint` green in Partna-Frontend; `/account/workplace/maps` renders the pinned map embed for an account with a Google Business connection, instead of "No location data yet" (MAP1).

## Out of scope (explicit)

- Persisting sector presets as stored columns (rejected — Option B; re-introduces the deleted factor machine's staleness).
- True provenance tracking for the scanned accent (it still reports as "manual" because it's a stored column; a `field_sources`-style provenance for design_kit is a future item — flag in Phase 5 if wanted).
- Converging the dashboard (flat snake_case) and public (nested camelCase) wire formats.
- **Recovering an Instagram reel that's genuinely outside the 12-post scrape window, or genuinely has no mp4 anywhere in the actor's grid response** — structurally different from the transient-failure case Task 18's retry now handles (that retry re-fetches the SAME already-known CDN URL, no extra Apify spend). Two billed options exist for these harder cases, gated on what Task 18's telemetry actually shows on the next real occurrence: **(C)** a per-shortcode detail fetch when a video candidate is found with no mp4 (targets the "has video, no mp4" case; costs a second Apify claim per such connect) or **(D)** a wider/second post-window fetch (targets the "reel not in the 12 most recent posts" case; same cost). Don't build either blind — the whole point of Task 18's telemetry is to make this a decision backed by evidence instead of a guess, and it's a real Apify-budget/product call (`config/partna.php:291-294` caps), not a pure code fix.
- Applying the logo-backdrop plate to the square logo's public render sites (favicon, menu-drawer watermark) — neither can take a CSS backdrop today; flagged as a fresh task if a render site that can (e.g. an avatar chip) gets built later.
