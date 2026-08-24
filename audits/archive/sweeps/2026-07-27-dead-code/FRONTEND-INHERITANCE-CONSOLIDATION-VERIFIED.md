# Frontend inheritance & consolidation — verified & needed

Distilled from `FULL-CODEBASE-BLOAT-INHERITANCE-AUDIT-PLAN.md` (the "Frontend inheritance
& consolidation opportunities — 2026-07-25" section, Tiers 1 & 2). Every item below was
**re-verified by reading the actual frontend source**, not just re-read from the audit.
Only items that are **both true and worth doing** are kept; what got dropped is listed at
the bottom with the reason.

- **Repo / path base:** all paths are relative to the frontend repo root (`~/Herd/Side Street/partna-frontend-main`, branch `main`).
- **Freshness:** verified against local HEAD `5234954` (2026-07-23). The audit's own re-check was 2026-07-25, so line numbers may drift ±a few lines; every file/function named below was confirmed to exist.
- **Convention corrected throughout:** the audit repeatedly writes `lib/queries/X.ts` where the code is actually in `lib/queries/fetchers/X.ts`. Paths below are the corrected, verified ones.

---

## Do first — small, safe, self-contained (start here)

### A. Collapse the two `unwrap*` envelope helpers into one  *(was Tier 1, item 5)*
- **Files:** `lib/queries/fetchers/sitepage.ts` — `unwrapService` (`:94`), `unwrapServiceCategory` (`:169`).
- **What's duplicated (verified verbatim):** the two functions are character-for-character identical except for the envelope key and the type:
  ```ts
  function unwrapService(raw: { service?: SitepageService } | SitepageService): SitepageService {
    if (raw && typeof raw === "object" && "service" in raw && raw.service) return raw.service
    return raw as SitepageService
  }
  // …unwrapServiceCategory is the same body with "service"→"category".
  ```
- **Fix:** one generic helper in the same file, delete both:
  ```ts
  function unwrapEnvelope<T>(raw: { [k: string]: unknown } | T, key: string): T {
    if (raw && typeof raw === "object" && key in raw && (raw as Record<string, unknown>)[key]) {
      return (raw as Record<string, T>)[key]
    }
    return raw as T
  }
  ```
  Call sites become `unwrapEnvelope<SitepageService>(raw, "service")` / `unwrapEnvelope<SitepageServiceCategory>(raw, "category")`.
- **Effort:** ~5 min. No cross-file coordination.

### B. Factor the two design-kit boolean-toggle hooks  *(was Tier 1, item 2)*
- **Files:**
  - `app/(app)/account/(dashboard)/design/use-individual-night-shift.ts` — an **explicit clone** (its header line 5 literally says `// Cloned from useIndividualLetterCase (use-individual-typography.ts)`).
  - `useIndividualLetterCase` — a function **inside** `app/(app)/account/(dashboard)/design/use-individual-typography.ts` (≈`:203`), *not* a standalone `use-individual-letter-case.ts` (that file does not exist — the audit's own correction is right).
- **What's duplicated (verified):** ~100 lines of identical single-boolean scaffolding — server-value `useRef`, re-sync `useEffect`, save-mutation-seeds-cache, reset-mutation-refetches — over the shared contract `PATCH /site { design_kit: { <key> } }` then seed only that field into the `["site","design-kit"]` cache. Only the column name (`theme_night_shift_auto` vs `typography_uppercase`), the default, and the success message differ.
- **Fix:** a `useSingleBooleanField(key, { defaultValue, successMessage })` factory returning `{ enabled, setEnabled, dirty, saving, save, reset }`; both hooks become one-line factory calls.
- **⚠ Read before implementing:** `git log` shows a prior `useSingleFieldForm()` factory ("for useColours/useThemeMode/useFontFamily/etc.") that **no longer exists in the repo** — it was introduced and then walked back (the explicit clone is the tell). Find out *why* it didn't stick before reintroducing the same shape. This is the one caveat that could turn a clean win into a re-litigated mistake.
- **Effort:** ~15 min once the historical question is answered.
- **Reference for "done right":** the 9 preset-picker sections (`sitepage-preset-sections.tsx`) already do exactly this — thin config wrappers around a shared `KitSelectRow` + `useIndividualKitSelect()`. Bring these two hooks in line with that.

---

## Do next — real, mechanical, low-risk

### C. Chat-engine shared helpers  *(was Tier 1, item 3)*
All under `lib/chat-engine/`.
- **C1 — `hasInputField` helper (clear win):** `Object.prototype.hasOwnProperty.call(input, field)` appears **23 times** across `action-customisation-handlers.ts` (8), `action-settings-handlers.ts` (7), `action-contacts-handlers.ts` (6), `action-business-handlers.ts` (2). **Fix:** add `hasInputField(input, field)` to `action-parsers.ts` (already the home of the shared input-parsing helpers). Zero-risk, purely mechanical.
- **C2 — single-field mutator factory (optional, more judgment):** 7 handlers all follow *validate-one-field → `requestBackend()` PATCH/PUT → success message*: `executeContentSetInstagramAuto` + `executeContentSetSectionPublication` (`action-content-handlers.ts`), `executeDesignSetColors` + `executeDesignSetFont` + `executeDesignSetLetterCase` (`action-design-handlers.ts`), `executeSettingsUpdateUsername` + `executeSettingsSetCharlie` (`action-settings-handlers.ts`). **Fix:** `makeSingleFieldMutator(validator, path, method, bodyBuilder, successMessage)` in `action-gateway-core.ts` turns each ~15-line handler into a one-line call. The 3 boolean ones (`…SetInstagramAuto`, `…SetLetterCase`, `…SetCharlie`) can share a thinner `makeBooleanToggler(...)` specialization.
- **Recommendation:** do **C1** now; treat **C2** as optional — the factory is more abstraction than the 7 short handlers strictly need.
- **Effort:** C1 ~10 min; C2 ~30–45 min.

### D. Dashboard "standalone section" layout factory  *(was Tier 1, item 6)*
- **Files (14 verified):** `products/`, `booking/`, `events/`, `menu/` under `app/(app)/account/(dashboard)/` — each has `layout.tsx` (metadata + `HubChildBreadcrumb` with a per-section icon/label/href) and pages wrapping `<StandaloneSectionShell title="X"><SectionComponent view="Y" /></StandaloneSectionShell>`. `booking/layout.tsx:3` self-documents it: `// …mirrors products/layout.tsx, events/layout.tsx`.
- **Shared pieces already exist:** `components/shells/hub-child-breadcrumb.tsx`, `components/shells/standalone-section-shell.tsx`.
- **Doctrine check (passes):** CLAUDE.md's "copy patterns between page dirs, never cross-import" rule does **not** forbid this — the litmus is *"must this behave identically on another surface? → shared composite."* It does behave identically across all 4; the only variation is config values passed to already-shared components. This is the doctrine's shared-composite path, not a cross-import violation.
- **Fix:** a small config-driven layout factory (icon/label/href/title/view per section) built on the two existing shells; each section's `layout.tsx`/`page.tsx` collapses to a config entry.
- **Do NOT touch:** the `workplace`/`business` pair — it already uses the more sophisticated variant-parameterized shared-body pattern (`_brand-page.tsx` + thin per-account-type wrappers). Leave it.
- **Effort:** ~30–45 min. **Its own PR** with a visual check on all 4 pages afterward — it's routing chrome across 4 features.

---

## Optional — true duplication, but modest payoff (pick up opportunistically)

### E. Query-factory builder  *(was Tier 1, item 4)*
- **Files (verified):** `lib/queries/{account,analytics,customers,dev-insights,integrations,public,sitepage}.ts` — 7 live files (the 8th, `auth.ts`, is dead — see bloat doc). Each repeats `{ all: () => [...] as const, entry: () => queryOptions({...}) }` (confirmed: exactly one `all:` + N `queryOptions` per file).
- **Fix:** a `createQueries()` builder so a cross-cutting change (e.g. a default `gcTime`) is one edit. Template it on the generic `publicFetch<T>()` wrapper.
- **⚠ Two catches:**
  1. `publicFetch<T>()` lives in `lib/queries/fetchers/public.ts:10`, **not** `public.ts` as the audit says.
  2. That `public.ts` + `fetchers/public.ts` pair is flagged **dead** in the bloat doc (item 18 — I confirmed zero real importers). So if you delete it, **lift the `publicFetch`/factory pattern out first** — don't model a new abstraction on a file that's about to be removed.
- **Honest take:** this is a factory-over-already-clean-declarative-code abstraction; the react-query factory shape is idiomatic as-is and low-churn. Real, but lowest value of the Tier-1 set.
- **Effort:** ~30 min.

### F. `useMultiAccountSelection(slug)` hook  *(was Tier 2)*
- **Files (verified — corrected):** `youtube-section.tsx` and `media-sections.tsx` only. **The audit's third file, `link-card-sections.tsx`, does NOT share this** (zero `activeId`, zero `accounts[0]` fallback) — it's a **2-file** pattern, not 3.
- **What's duplicated:** accounts-query + `activeId` state + `find(...) ?? accounts[0]` "active account" derivation.
- **Fix:** `useMultiAccountSelection(slug)` returning `{ accounts, activeId, setActiveId, active }`.
- **Caveat:** platforms differ slightly in account shape, so it's a smaller, less clean-cut win. Do only if you're already in these two files.
- **Effort:** ~15 min.

### G. SSR-safe storage read helper  *(was Tier 2)*
- **Files (verified):** `lib/auth-session.ts`, `lib/auth-accounts.ts` — near-identical `typeof window`/SSR-guard + try/catch around `localStorage`/`sessionStorage` reads. (The audit cited `signup-draft.ts` as a third instance — skip it, it's dead in the bloat doc, so this is genuinely **2 files**.)
- **Fix:** one `safeStorageRead(store, key)` (and optional `safeStorageReadJSON`) helper; both files call it. Note `auth-session.ts` doesn't do the JSON-parse variant, so keep the raw read and the JSON read as two small functions.
- **Effort:** ~10 min.

### H. Single `readString`/`readNumber` home  *(was Tier 2)*
- **Files (verified):** `readString` currently has **three real definitions** — `lib/account/readers.ts` (the canonical one), `lib/data-parse.ts` (its own copy), and a private one in `lib/app-error.ts`. `lib/coerce.ts` already does it right (re-exports from `@/lib/account/readers`).
- **Fix:** make `lib/account/readers.ts` the single source; have `data-parse.ts` (and `app-error.ts` where practical) re-export instead of redefining, so there's one canonical import path. Low-stakes; removes ongoing "which import is correct" confusion.
- **Caveat:** the bloat doc separately flags `data-parse.ts`'s `readInt`/`readBoolean` as dead — coordinate with that cleanup rather than duplicating effort.
- **Effort:** ~15 min.

---

## Suggested order
**A → B → C1 → D**, then E / F / G / H opportunistically. A and B are the smallest, safest, most self-contained. D is the biggest structural win but wants its own PR + visual pass. Everything in the Optional block is real but low-stakes — fold into whatever PR already touches those files.

---

## Deliberately excluded (checked, not kept)

- **Connect-flow "state machine" × 5 files (audit's Tier 1, item 1 — ranked "highest value").** *Excluded: overstated, and its central justification is not in the code.* The cited `aliveRef` (unmount guard) and `pollSeq` (poll generation-counter) appear **zero times** in any of the 5 files, and the "trickiest race logic duplicated 5 ways" it promises to consolidate is **already shared** — the re-scrape contract lives in `use-real-refresh.ts` (used by 4/5) and the only polling flow lives in `use-instagram-connect.ts`. What actually repeats is a trivial `{ modalOpen, pending, error }` (+ optional `value`) useState block across 3 files, and `booking-section` isn't even that (it's a different 2-step url→picker flow). A `useConnectModalState()` hook is harmless but saves ~4 lines/file and delivers none of the race-safety the audit claims. **Do not treat as high-value.**
- **`wrapMutationWithErrorHandling()` (audit's Tier 2).** *Excluded per the audit's own rule:* it says "worth a shared wrapper **if a third caller shows up**," and only 2 callers exist today (`content-media.ts`, `site-workplace.ts`). Revisit when a third appears.

## Also worth reconciling (not an inheritance item, but surfaced here)
The audit's **item 4 holds up `public.ts` as the exemplar to emulate**, while its **bloat item 18 marks that same file pair dead**. Both live in the merged doc and neither points at the other. Whoever picks up E must reconcile these — lift the pattern, then delete the file.
