# Signup testing repairs

> Running list of issues found while manually testing the signup flow live. Items are
> added as they're found (one by one), diagnosed, then fixed in order. Delete when shipped.

**Source:** live test signup 2026-07-22, `joshhunter@huntercorpinvestments.com.au`, handle
`supernormal` (Supernormal restaurant, Melbourne — business account, built from a Google
Business Profile match). Full timeline of that signup is in chat history for this date;
not duplicated here.

---

## 1. Business address: duplicate "display address" line vs. structured fields

**Status:** root cause confirmed.

On Account → Business settings, the Address card shows a single-line "display address"
field ABOVE the separate structured fields (street address, suburb/state/postcode, etc).
Owner direction: there should be exactly one source of truth — the structured fields —
and no separately stored/edited display-address line. If a single-line preview is wanted
anywhere, it should be computed from the structured fields, not stored/edited
independently.

**Findings:** genuine two-independent-sources-of-truth design, not a mislabeled
computed-preview pattern — confirmed end to end:
- Frontend: `Partna-Frontend/app/(app)/account/(dashboard)/workplace/workplace-info-section.tsx`'s
  `AddressCard` (lines 566-731) renders SIX real, independently-editable fields —
  `address` ("Display address"), `address_line1` ("Street address"), `city`
  ("City / suburb"), `state`, `postcode`, `country` — all wired via react-hook-form's
  `register`, saved verbatim with zero cross-derivation (lines 605-627). Also
  independently writable via the AI chat assistant's `business_update_info` tool
  (`lib/chat-engine/action-business-handlers.ts`), a third write path.
- Backend: `site.workplaces` has all six as plain independent nullable `text` columns
  since its first migration (`20260701150000_create_workplaces.sql`). Validation
  (`UpsertWorkplaceRequest`), the controller (`UserWorkplaceController::upsert()`), the
  model (`Workplace.php`, no accessor/mutator), the resource, and the public sitepage
  payload builders (`SitepageDataResolverService`, `IndividualProfilePayloadBuilder`) all
  read/write both independently — zero derivation anywhere in the stack.
- Built deliberately: commit `4f0a1331` ("split the details form into Contact / Address /
  Description cards") explicitly describes "display line + structured parts" as the
  intended design.
- **The concrete drift trigger:** `IdentitySync::applyFromGooglePayload()` (called from
  `IntegrationConnectionObserver::saved()` on every Google Business connect/re-sync) reads
  `address` from Google's `formattedAddress` and writes ONLY that — it never reads the
  `addressParts` (suburb/state/postcode/country) that's sitting in the exact same sync
  payload at that moment. So the instant a business connects Google Business (the primary
  onboarding path, and exactly how the test account was built), the display line gets
  auto-filled and the structured fields stay blank underneath it — guaranteed, not an
  edge case.

**Fix:** not started. Owner direction is to drop the separate `address` field entirely and
make the structured fields the only source of truth (computing any single-line display
elsewhere from those, never storing/editing it independently).

**Options (2026-07-23) — need owner decision before executing:**
- **A. Full removal:** drop the `address` column/field everywhere — FE form, validation,
  model, resource, public sitepage payload builders. Any place that wants a single-line
  string computes it on the fly from the structured fields at read time (e.g.
  `implode(', ', array_filter([address_line1, city, state, postcode, country]))`), never
  stored. Cleanest, but requires finding and updating every consumer, and I haven't
  checked the THIRD repo (`partna-monorepo`, the Astro pages app that renders the actual
  public sitepage) for whether its template reads `address` directly — that's outside
  Partna-Frontend/Comet-Backend, not yet checked.
- **B. Keep column, stop it being independently editable:** remove the "Display address"
  input from the FE form and stop accepting `address` as user input, but keep the DB
  column and have the backend auto-COMPUTE it from the structured fields whenever they
  change (a model observer/mutator), so any existing consumer reading `address` (possibly
  including the pages template) keeps working unchanged. Lower risk, doesn't require
  auditing every consumer up front, and still fully satisfies "one editable source of
  truth" — the stored `address` becomes a pure mirror, never independently written.
- Either way, `IdentitySync::applyFromGooglePayload()` needs to start reading
  `addressParts` (suburb/state/postcode/country) from the Google sync payload and writing
  the structured fields, not just `address` — otherwise the drift keeps happening on every
  future Google Business connect regardless of which option is chosen.

**Decision (2026-07-23): Option A — full removal.** Drop `address` everywhere (FE form,
validation, model, resource, public sitepage payload builders, and the pages repo's
`resolve-site-content.ts:670-671`) — no computed-mirror column kept around.

**Resolved (2026-07-23): existing drifted data** — owner direction: existing accounts are
all test data, don't bother backfilling. No migration attempted to parse `address` back
into parts before dropping the column.

**Fix shipped (2026-07-23).** Full removal across all three repos:
- **Comet-Backend:** migration `20260723120000_drop_workplaces_address.sql` (`DROP COLUMN
  address` on `site.workplaces`, applied to dev Supabase); `Workplace` model, request
  validation, `UserWorkplaceController` (upsert field loop + manual-provenance stamps, now
  covering all 5 structured fields instead of just the flat one), `WorkplaceResource`,
  `StaffWorkplaceController`, `SitepageDataResolverService`, `IndividualProfilePayloadBuilder`,
  `DataExportPayloadBuilder` (GDPR export column list) all stop reading/writing `address`.
  `WorkplaceVisibility`'s name-OR-address live-gate predicate now checks `address_line1`
  instead (same semantics, structured column). `WorkplaceObserver`'s cache-affecting-column
  list updated. `IdentitySync::applyFromGooglePayload()` rewritten to read Google's
  `addressParts` (lines/suburb/state/postcode/country — already present in the same sync
  payload, previously unused) and write the 5 structured columns directly, never a flat
  string — this also fixes the root drift trigger this item started from. All touched tests
  updated to the structured shape; full suite green (4776 passed).
- **Partna-Frontend:** `AddressCard`'s "Display address" input removed entirely (form type,
  register calls, submit payload, `isGoogleSynced` check all updated); `Workplace`/
  `WorkplaceUpsertInput` types in `lib/site-workplace.ts` drop `address`. Both AI chat tools
  that could set a workplace location (`business_update_info`, `settings_set_workplace`)
  updated to the structured-field shape instead of the flat string, in both the handler body
  and the registry's tool schema. Live-verified: reloaded `/account/workplace` on the ollies
  test account, confirmed only the structured fields render (no Display address field), typed
  into Street address, saved, hard-reloaded from the server, confirmed the value round-tripped
  correctly through the new `address_line1` column with zero console errors.
- **partna-monorepo:** `resolve-site-content.ts:670` (the `address` key read) and
  `WorkplaceSurface.address` in `types.ts` removed — confirmed dead-on-arrival downstream
  (only `addressLine1`/`city`/etc. are actually consumed, by `composeLocaleLine()`). The
  separate Google-Business-connection-surface `address` (`head-builder.ts`, `locale-line.ts`'s
  fallback branch, `resolve-site-content.ts`'s `googlePlace` block) is a genuinely different
  data source and was deliberately left untouched. Typecheck + full test suite green (65/65).

---

## 2. Website accent/logo scan crashes — DivisionByZeroError

**Status:** root cause known, fix not started.

Every pre-account/claimed site that has a previous website discovered (via Google Business
`website` field or similar) gets scanned for branding — accent colour + logo candidates.
On the test account this crashed immediately and left the design kit fully blank
(`color_accent`, `theme_mode`, etc. all null) and no logo pulled in at all.

- Job: `App\Jobs\Platforms\ScanPreviousWebsiteContentJob`
- Crash: `DivisionByZeroError` in `app/Services/WebsiteScan/WebsiteAccentExtractor.php:93`
  (`qualifies()`'s saturation calc), called via `:70` → `:27` → `ScanPreviousWebsiteContentJob.php:169`.
- The crash happens BEFORE the job reaches its logo-candidate step later in the same
  handler — one bug explains both the missing accent colour and the missing logo.
- Reproduced twice, identically, on the test account (initial attempt + Laravel's
  automatic retry ~30s later per the job's own `tries=2` config) — deterministic, not a
  flake.

**Exact mechanism (2026-07-23, fully nailed down):** in `WebsiteAccentExtractor::qualifies()`:
```php
sscanf($hex, '#%02x%02x%02x', $r, $g, $b);
$max = max($r, $g, $b) / 255;
...
$saturation = $max === 0.0 ? 0.0 : ($max - $min) / $max;
```
When the sampled pixel is pure black (`$r = $g = $b = 0`, a genuinely common colour in
real favicons/logos), `max($r, $g, $b)` is `0`. PHP's `/` operator returns an **integer**
(not a float) when both operands are integers and evenly divisible — and `0 / 255` is
exactly divisible — so `$max` ends up as the **integer** `0`, not the float `0.0`. The
guard `$max === 0.0` uses strict comparison (type-checked), and `0 === 0.0` is `false` in
PHP (int !== float) — so the guard fails to catch this case and falls through to
`($max - $min) / $max`, dividing by (integer) zero. A pure-black pixel anywhere in the
sampled favicon/theme-color triggers this reliably, which is exactly why it crashed
instantly and identically both times.

**Fix (not applied yet):** replace the strict `===` guard with a value comparison that
doesn't care about int vs. float — e.g. `$saturation = $max > 0.0 ? ($max - $min) / $max
: 0.0;` (also reads more honestly as "if there's any lightness, compute saturation").
Small, isolated, single-line change; no other logic in the file is affected.

**Fix shipped (commit `afa230c2`).** Exactly the one-line change above —
`WebsiteAccentExtractor.php`'s saturation guard reads `$max > 0.0 ? ($max - $min) / $max :
0.0` — no other logic touched. Backend full suite green.

**Test gap closed (critic-agent pass caught this):** the shipped test suite's two "close to
black" cases (`#0a0a0a`, `solidColorPng(0,100,200)`) both have a non-zero channel, so
neither actually exercises the int/float guard gap this bug was — reverting the fix
wouldn't have failed either. Added two real regression tests using genuinely pure black
(`#000000` theme-color and a `solidColorPng(0,0,0)` favicon) on both entry paths that
reach `qualifies()`; both pass post-fix and would have thrown `DivisionByZeroError`
pre-fix.

---

## 3. Instagram auto-connect crashes for zero-media accounts

**Status:** root cause known, fix not started.

When Google Business enrichment (or a website scan) discovers a candidate Instagram
handle and the auto-connect job resolves it, an account with 0 posts/0 videos on
Instagram crashes the whole connect instead of saving a connection with no media. On the
test account this left the Instagram platform connection row present but empty/inactive
— no handle or profile data actually saved (appears as "Instagram: null" in the UI).

- Job: `App\Jobs\Platforms\InstagramConnectJob`
- Crash: `League\Flysystem\UnableToDeleteFile` in
  `app/Services/Platforms/InstagramConnectionSeeder.php:125`, called via
  `InstagramConnectJob.php:127`.
- That line deletes "stale" placeholder media files left over from a previous connect
  run, on the assumption (per the code's own comment) that deleting an absent key is a
  safe no-op. For a FIRST-EVER connect with zero media to mirror, the file never existed
  in the first place, and this storage backend throws instead of no-op'ing.
- Reproduced twice, identically (initial attempt + automatic retry) — deterministic.

**Deeper look (2026-07-23):** the comment on that delete call says "Storage::delete on an
absent key is a safe no-op" — true of S3's own DeleteObject API in general (deleting a
non-existent key normally succeeds with no error), so this isn't a case of the developer's
assumption being wrong in principle, more likely a specific storage-backend/permissions
quirk surfacing as `UnableToDeleteFile` for this particular key/run (the exact underlying
S3/R2 reason wasn't captured in the log pull — only the top-level Flysystem message — so
the precise "why S3 didn't no-op here" isn't 100% pinned down). Regardless of the exact
underlying reason, this is a best-effort cleanup step (reclaiming stale files from a
previous connect run) — it should never be able to abort a connection that otherwise
fully succeeded. Every other I/O operation in this same file (`mirrorOne()`,
`mirrorVideo()`) already wraps its work in try/catch + `report($e)` + safe fallback,
exactly this pattern — this delete call is the one spot that doesn't.

**Fix (not applied yet):** wrap the `Storage::disk('media')->delete($stale)` call in a
try/catch, `report()` the exception for observability (so a real underlying storage
problem still surfaces in Nightwatch), and continue — matching the file's own established
convention rather than introducing a new one. Small, isolated change.

**Fix shipped (commit `afa230c2`).** The delete call in `InstagramConnectionSeeder.php`
is now wrapped in try/catch + `report($e)`, matching the file's own established
convention. Backend full suite green.

**Known test gap (critic-agent pass caught this, not closed):** no test forces
`Storage::disk('media')->delete()` to genuinely throw — every seeder test uses
`Storage::fake('media')`, whose local-adapter delete on an absent key already no-ops
cleanly (can't reproduce the original storage-backend quirk). Reliably forcing a real
throw here needs either a fragile full-surface Storage facade mock (this file's `seed()`
also calls `put()`/`exists()` on the same disk via `mirrorOne()`/`mirrorVideo()`, so a
partial mock risks an unrelated "no matching handler" failure) or a real integration
against a non-fake disk. Judged not worth the fragility for a LOW-severity, already-fixed
gap — flagging honestly rather than skipping silently.

---

## 4. Website-scanned menu invisible on the dashboard (/account/menu + Categories)

**Status:** root cause confirmed.

A menu built from `WebsiteMenuPdfScanJob` (previous-website PDF scan — see item 2's
neighbourhood) is fully present and correctly linked in the database (15 items, 3
categories, all linked, confirmed on the test account) and renders correctly on the LIVE
public page (`GET /api/public/profiles/{handle}/menu`, `PublicMenuController::show()`),
but shows as empty on the authenticated dashboard (`GET /api/platforms/menu`,
`/account/menu` + Categories tab).

Cause: `MenuPayloadComposer::hasOwnerContent()`
(`app/Services/Platforms/MenuPayloadComposer.php:64-80`) and
`MenuContentController::EDITABLE_SOURCES`
(`app/Http/Controllers/Api/Platforms/MenuContentController.php:48`) both only recognise
`source_platform` values `'scan'` (dashboard photo/PDF upload) and `'manual'` (hand-added
dish) as "owner content" that keeps a menu alive without a live Uber Eats/DoorDash link.
`website-scan` (the newer, automatic previous-website-scan source introduced alongside
pre-account sites) is missing from both allowlists, so `dashboardPayload()`'s orphan
guard (`MenuPayloadComposer.php:36-38`) nulls out the whole menu for any account with no
online-ordering connection — exactly this test account's situation. The public controller
has no such gate (just `whereNotNull('last_fetched_at')`), which is why it renders fine
there.

Proof this is a known-but-incomplete rollout, not a fresh mystery:
`app/Services/Platforms/MenuScanApplier.php:198` already includes `'website-scan'` in its
own version of this same allowlist (`['scan', 'manual', 'website-scan']`) — the other two
call sites were just never updated to match when the source type was added.

Also answers the "where did these come from" question: NOT a Google Business photo/image
scan — `content_source: website-scan`, `menu_categories.source_platform: website-scan`,
sourced from a PDF wine list found on the old website
(`https://supernormal.net.au/s/Supernormal-Wine-List-s3p2.pdf`), which is also why the
items have no pictures (`image_url`/`images` both null on all 15 rows) — a wine list PDF
has no photos to extract.

**Fix shipped (commit `afa230c2`).** `'website-scan'` added to both allowlists
(`MenuPayloadComposer::hasOwnerContent()`, `MenuContentController::EDITABLE_SOURCES`), plus
the two cosmetic same-family fixes flagged in the consolidation sweep below
(`MenuFetchJob::previousCategoryOrder()` and `::remainingContentSource()` now also exclude/
recognise `website-scan`). Backend full suite green.

**Owner note (2026-07-23):** before doing the allowlist fix above, confirm there is
genuinely only ONE storage mechanism for menu items — website-scanned items must land as
real rows in the same `site.menu_items`/`site.menu_categories` tables via the same
relational shape as scan/manual items, never a second parallel store or item type.

**Consolidation sweep done (2026-07-23):** grepped every `source_platform` reference in
the backend. Confirmed: only ONE storage mechanism exists — website-scan items are plain
rows in the normal `menu_items`/`menu_categories` tables, same schema/relations as
scan/manual, just tagged differently. The important safety question — does a real
Uber Eats/DoorDash scrape ever WIPE website-scanned content on a rebuild? — is answered
NO: `MenuFetchJob::rebuildableCategoryIds()` (`app/Jobs/Platforms/MenuFetchJob.php:607-613`)
already correctly excludes `'website-scan'` alongside `'scan'`/`'manual'` from what a
scraper rebuild is allowed to delete — this one was done right from the start. Two much
smaller, cosmetic-only inconsistencies turned up in the same sweep, same family as the
main bug (a spot that lists `'scan'`/`'manual'` and just never got `'website-scan'` added
when it was introduced), neither a visibility or data-loss risk:
- `MenuFetchJob::previousCategoryOrder()` (line 264-275) only excludes `'scan'` when
  building the cross-run category-ordering baseline — cosmetic (could very rarely affect
  sort order if a website-scan category shares a name with a freshly-scraped one), not a
  safety issue.
- `MenuFetchJob::remainingContentSource()` (line 789-797) only checks for `'scan'` when
  labelling what content_source remains after a rebuild clears scraped content — a menu
  left with ONLY website-scan categories would get mislabelled `'manual'`. Cosmetic label
  only, not a data issue.

Worth fixing alongside the main allowlist fix since they're the same root pattern, but
low priority — flagging so they don't get missed, not treating as urgent.

---

## 5. Website menu-PDF scan only ever grabs the FIRST PDF on the page

**Status:** root cause confirmed. Distinct from item 4 (that's about hiding already-scanned
content; this is the scan itself being incomplete).

`ScanPreviousWebsiteContentJob.php:109` finds every PDF link on the previous website's
homepage (or its `/menu` page, one bounded extra hop, only tried when the homepage has
neither HTML menu text nor a PDF): `$pdfs = $pdfLinks->find($html, $baseUrl);` — an array
of every PDF link found. But line 136 only ever dispatches a scan for the first one:

```php
if ($pdfs !== []) {
    WebsiteMenuPdfScanJob::dispatch($this->userId, $pdfs[0])->delay(now()->addSeconds(30));
}
```

Any other PDFs on the page are silently dropped — never scanned, never logged as skipped.
On the test account this is confirmed to be exactly why only a wine list (15 drink items,
sake/yuzushu/umeshu) came in: whatever real food menu PDF the site may also have was
never touched, because it wasn't first in the array. Not yet checked whether
supernormal.net.au's real site actually has a separate food-menu PDF (easy to check live
if wanted) — but the code confirms this would silently happen regardless.

**Owner direction:** not about picking the "right" document — a drinks/wine list counts as
a real menu document and should be scanned same as food. The issue is purely the
artificial cap at one. Every PDF found on the page should get scanned, not just the
first.

**Relevant nuance found (2026-07-23):** `PdfLinkDetector::find()`
(`app/Services/WebsiteScan/PdfLinkDetector.php`) is completely naive — it returns EVERY
`<a href>` on the page whose path ends in `.pdf`, with no filtering by link text or
context (no "menu"/"wine"/"food" keyword check at all). So "scan every PDF found" as
written would also OCR-scan any unrelated PDF a site happens to link (a Terms &
Conditions PDF, a press kit, a careers doc, etc.) — each one costs a real, metered Mistral
OCR call (per `WebsiteMenuPdfScanJob`'s own comment about not blocking on "a slow Mistral
OCR call"). **Decision (2026-07-23): keyword prefilter.** Only dispatch a scan for PDFs
whose link text/href matches a menu-relevant keyword (menu/wine/drink/food/beverage/
dessert etc.) — still "every real menu doc," just not literally every PDF on the domain.

**Fix shipped (commit `afa230c2`).** `$pdfs[0]` cap removed — `ScanPreviousWebsiteContentJob`
now keyword-prefilters every PDF found (`MENU_KEYWORDS`), then dispatches one
`WebsiteMenuPdfScanJob` per relevant PDF, staggered and bounded by `MAX_PDF_SCANS = 5` as a
safety cap, not a "pick one" limit. Backend full suite green.

---

## 6. On-page HTML menu-text extraction missed a real, populated food menu

**Status:** fully investigated and root-caused, with direct live verification (fetched
both `supernormal.net.au/` and `/menu` independently — a real browser session plus a raw
`curl` capture, cross-checked against each other).

**Root cause, confirmed:** `MenuTextExtractor` (`app/Services/WebsiteScan/MenuTextExtractor.php`)
has exactly ONE strategy and nothing else — it looks for a schema.org `Menu`-typed
JSON-LD block (`hasMenuSection`/`hasMenuItem` shape) and returns `[]` immediately if none
exists. This is a deliberate, tested contract, not an oversight — its own test suite
(`tests/Unit/WebsiteScan/MenuTextExtractorTest.php:83-85`) explicitly locks in "returns
`[]` when there is no Menu JSON-LD at all" as correct behaviour. The problem: that
structured-data shape is rare in the real world — the large majority of real restaurant
sites (Squarespace, Wix, Webflow, bespoke templates) render their menu as plain templated
HTML, not `Menu`-typed JSON-LD, and this extractor cannot see that content at all no
matter how clean or complete it is.

**Confirmed against the real site directly:**
- Homepage (`supernormal.net.au/`): zero `.pdf` links, zero `Menu`-typed JSON-LD (only
  generic `WebSite`/`LocalBusiness`/`Restaurant` boilerplate). So both `$items` and `$pdfs`
  come back empty on the homepage — the job's `/menu`-fallback gate
  (`ScanPreviousWebsiteContentJob.php:117`) correctly evaluates true.
- `findMenuPageLink()` correctly identifies `/menu` from the homepage nav (simple,
  correctly-working "path contains 'menu', same host" match) and the job fetches it for
  real.
- `/menu` (confirmed via both a live browser session AND a raw HTTP capture, so this is
  what the job's own plain HTTP fetch actually receives, not a JS-rendered illusion): has
  **two real PDFs in document order** (wine list first, a cocktail card second — explains
  why `$pdfs[0]` = the wine list, matching item 5 exactly) **and** a genuine, complete food
  menu (~30 dishes with prices) rendered in Squarespace's own consistent native markup
  (`.menu-item` / `.menu-item-title` / `.menu-item-description` / `.menu-section-title`) —
  present in the raw pre-JS HTML, not client-injected. Zero `Menu`-typed JSON-LD anywhere
  on this page either. `MenuTextExtractor` never looks at any of that markup, so it
  returns `[]` here too, regardless of how much real content is on the page.
- Bonus finding: the site's own `Restaurant` JSON-LD already carries schema.org's standard
  `"menu": "https://supernormal.net.au/menu"` pointer field — a free, authoritative signal
  neither `MenuTextExtractor` nor `findMenuPageLink()` currently reads.

**So this was NOT the "PDF found on homepage skips the /menu hop" scenario I'd guessed at**
— the homepage had no PDF at all, so nothing was skipped; the job genuinely fetched and
inspected `/menu`'s real content and the extractor simply can't parse the format it's in.

**Separate, real, but NOT what caused this incident** (confirmed, not conflated): the job
does have an intentional, tested short-circuit — if the homepage alone already yields a
PDF, the `/menu` hop is skipped entirely, even if a separate richer HTML menu might live
one hop away (`ScanPreviousWebsiteContentJob.php:117`; explicitly tested at
`tests/Feature/Platforms/ScanPreviousWebsiteContentJobTest.php:182-194`). This is a
legitimate latent risk for a *different* hypothetical business (PDF on the homepage +
richer HTML menu one hop away) and worth hardening later, but it did not affect
Supernormal and shouldn't be fixed as if it were the cause here.

**Fix — redesign researched in full (2026-07-23), clear recommendation, not yet applied:**

Considered two approaches head to head — a hand-rolled CMS-selector library (Squarespace,
Wix, WordPress, Weebly, Webflow, GoDaddy) vs. reusing the AI menu-structuring call already
built for PDFs. Tested against REAL live sites on each platform (not documentation
guesses) — verdict is decisive:

- **Selector library: confirmed dead-end as a general strategy.** Wix sites are fully
  client-rendered — a real Wix site's plain-HTTP-fetched HTML has under 600 characters of
  actual text, nothing to select against, ever (this pipeline deliberately never runs a
  headless browser). GoDaddy sites sit behind a WAF that 403'd a real fetch attempt.
  WordPress fragments into 5+ actively-used, mutually-incompatible menu-plugin
  conventions. Webflow has real content but no platform-wide class convention, only
  per-template author choices. Squarespace is the ONE platform confirmed stable enough to
  hand-roll selectors against — which is exactly the one this incident happened to hit.
  A selector library would need constant expansion and still hit hard walls (Wix, likely
  GoDaddy) no amount of selector work fixes.
- **Reusing `MenuAiExtractor::structure()` (the AI call already used for PDF OCR text) on
  plain HTML-extracted visible text: confirmed to hold up cleanly.** That method is
  already a pure text-in/items-out call with ZERO PDF-specific assumptions, and already
  has two independent callers feeding it text from different origins (PDF OCR, and
  Google-photo OCR) — feeding it HTML-extracted text is the same shape of call, no change
  needed to `structure()` itself. Cost is likely FAVORABLE, not just comparable — this
  path needs no OCR step at all (one fewer paid API call than the PDF path), and real
  menu text runs well under the extractor's existing size cap.

**Recommended build (in order):**
1. Read the schema.org `hasMenu`/`menu` JSON-LD pointer (confirmed unused today, zero
   cost, clean fit with existing `ParsedMetadata`/`MetadataParser` code) as an authoritative
   locator signal for the menu page, ranked ahead of the current path-substring guess in
   `findMenuPageLink()`.
2. New `VisibleTextExtractor`-style service (genuinely new — nothing today walks the DOM
   for bulk visible text, every existing extractor reads one specific field) that
   preserves block-level line breaks (needed so item-name/price/description boundaries
   survive) and truncates at the existing OCR-text size cap.
3. A cheap pre-filter (e.g. minimum count of price-like tokens, mirroring an existing
   pattern already used for the Google-photo menu scan) before spending an AI call on a
   page that turns out not to actually be a menu.
4. A new dispatched job (parallel to the existing PDF-scan job — must be its own job, not
   inline, since the AI call's own timeout is LONGER than this job's overall timeout,
   exactly why the PDF path is already a separate job) calling the SAME
   `MenuAiExtractor::structure()` unchanged, then the same `MenuScanApplier::apply(...,
   source: 'website-scan')` unchanged.
5. A small, precise, zero-cost Squarespace-specific fast-path (try its known stable
   classes first, free when it hits) as a first check before spending on the AI call —
   not as the start of a general library, just a cheap win on the one platform that
   deserves it.

**Worth fixing alongside this (found during the research, real gap independent of this
item):** there is currently NO spend/rate cap anywhere on the AI menu-structuring calls
(Mistral OCR + the DeepSeek structuring call) — confirmed by checking every rate limiter
defined in the app. Adding a third automatic trigger point (this new HTML path) makes
that pre-existing gap more worth closing at the same time, not just for this feature.

Separately (lower priority, not this incident's cause): reconsider whether skipping the
`/menu` hop just because the homepage yielded ANY PDF is too aggressive — maybe only skip
once HTML items were actually found, not merely because a PDF was found somewhere. (Not
addressed this run — flagged for later, low priority, not this incident's cause.)

**Fix shipped (commit `afa230c2`), full recommended build order.** The schema.org
`hasMenu`/`menu` JSON-LD pointer is now read and ranked ahead of the path-substring guess
in `findPageLink()` (generalized from `findMenuPageLink()` — see item 8, same helper now
serves menu/about/contact one-hops). New `VisibleTextExtractor` (block-level DOM walk,
60000-char cap) + a price-line density pre-filter (`MIN_DENSE_TEXT_CHARS`/`MIN_PRICE_LINES`)
gate a new `WebsiteMenuHtmlScanJob` (its own dispatched job, same rationale as the PDF
job) that reuses `MenuAiExtractor::structure()` unchanged. A zero-cost Squarespace-specific
fast-path (`SquarespaceMenuExtractor`) runs first and skips the AI call entirely when it
hits. The AI-spend gap flagged alongside this item is also closed: new `AiSpendBudget`
class (mirrors `ApifyBudget`'s atomic per-actor + global cap pattern) gates both
`MenuAiExtractor::ocr()` and `::structure()`. Backend full suite green.

---

## 7. LinkedIn and X (Twitter) not picked up from Google Business, unlike Instagram

**Status:** fully investigated and confirmed — this is a data-source limitation (the
Apify actor crawls the business's own website, not the Google listing page), not a bug in
our code. Owner manually confirmed Supernormal's real Google Business listing shows
LinkedIn, Instagram, and X links. Instagram was picked up (then crashed — item 3). LinkedIn
and X were never picked up at all — no platform connection row for either, not even a
failed/stub one like Instagram got.

What's confirmed from code (not a missing-code-path bug like items 4/5):
- `'linkedin'` and `'x'` (twitter) ARE registered, connectable platforms
  (`PlatformRegistryServiceProvider.php:120-121`, link-only socials alongside
  tiktok/facebook/threads/reddit).
- `GoogleBusinessApifyScraper::map()` (`app/Services/Platforms/GoogleBusinessApifyScraper.php:173-181`)
  DOES correctly attempt to map `linkedin` (from raw Apify field `linkedIns`) and `twitter`
  (from raw field `twitters`) into the enrichment's `socials` array, same as
  instagram/facebook/youtube/tiktok/pinterest.
- `GoogleBusinessAutoSync::seedSocials()` (`app/Services/Platforms/GoogleBusinessAutoSync.php:568-613`)
  DOES correctly attempt to seed a real connection for `linkedin`/`twitter` (mapped to
  platform `x`) if present in that `socials` array, same code path as facebook/tiktok.
- No exception was logged for a LinkedIn/X write attempt on the test account (only the two
  known crashes — items 2/3) — so the seeding code most likely never ran for these two
  because `socials['linkedin']`/`socials['twitter']` were simply absent after mapping, not
  because the write itself failed.

Working theory — **now confirmed live (2026-07-23):** the Apify actor input's own code
comment (`GoogleBusinessApifyScraper.php:132-136`) says social links come from a
"company-contacts add-on" that **crawls the business's own website** for social profile
URLs — not from whatever's directly entered on the Google Business Profile listing page
itself. Fetched `https://supernormal.net.au/` directly and scanned every `<a>` on the page
for linkedin/twitter/x.com/instagram/facebook links: the ONLY social link anywhere on
their real homepage is `https://www.instagram.com/supernormal_180` — no LinkedIn, no X,
nothing else. This fully confirms the theory: Apify's website-crawl-based discovery
correctly found the one social link that's actually on the business's own site
(Instagram) and correctly found nothing else, because there IS nothing else there. The
LinkedIn/X links the owner saw are on the separate Google Business Profile listing page,
added there independently (Google lets an owner enter these directly in their Business
Profile dashboard, with no requirement they also appear on the business's own website).

**Verdict: this is a genuine data-source/vendor limitation, not a code bug.** Our mapping
and seeding code are both already correct and complete for LinkedIn/X (see above) — there
is simply no LinkedIn/X data in the raw input this Apify actor call produces for this
business, because that actor looks at the wrong place for it.

The debug log `google_business.apify.keys` (`GoogleBusinessApifyScraper.php:88-105`) is a
red herring here — it only checks presence for `menu`/reservation/booking/`instagrams`/
`facebooks`, never checks `linkedIns`/`twitters`/`youtubes`/`tiktoks`/`pinterests` at all
(the log's own comment says it was meant to be dropped to debug "once settled" and was
just never updated) — so its absence from that log proves nothing either way about the
raw data.

**Fix:** not started. Since the current Apify actor genuinely can't see this data, the fix
is necessarily "get it from somewhere else" — see the owner note below for the options to
weigh (different/additional scraper, Places API directly, etc.), not a code patch to the
existing mapping/seeding logic (which is already correct).

**Owner note (2026-07-23):** getting a business's real profiles (Instagram, LinkedIn, X,
etc.) straight from Google Business is important enough that it shouldn't be solely
reliant on crawling the business's OLD WEBSITE for links. When we get to this item,
evaluate whether we need a different scraper, an additional/second scraper run alongside
the current one, the Places API directly, or some other source — whatever actually gets
this data reliably from Google's own listing, not just from what happens to be
discoverable on the business's own site.

**Alternatives researched (2026-07-23) — real vendor/API research, not guesses, sources
cited in full below:**
- **Places API (New):** confirmed dead end — checked the full official field list plus
  2.5 years of release notes; no social-link field has ever existed on this API. Nothing
  to add to our existing field mask.
- **Google Business Profile API** (the owner-management API, separate from Places API):
  real capability — an owner genuinely can manage social links via this API (Google's own
  help docs confirm 7 platforms incl. LinkedIn/X). **But it requires the business to grant
  OAuth access to a listing verified 60+ days, plus a manual Google app-review to get
  access at all.** This is structurally incompatible with our cold-build flow (place-ID
  only, no owner relationship at signup) — it would only fit a FUTURE, separate "connect
  your Google Business listing" feature built after claim, not a fix for today's signup
  path.
- **Alternative Apify actors / different input on the current one:** checked
  exhaustively — every social-discovery flag on the actor already in use, and a
  competing vendor's (Outscraper) equivalent actor, are BOTH website-crawl-only. No actor
  found anywhere that sources socials from the Google listing page itself. This looks like
  an industry-wide gap, not something specific to our config.
- **SerpApi Knowledge Graph API:** the one option that's neither confirmed-dead nor
  confirmed-working — it has a `profiles` field that's verified to populate for major
  entities, but NOT verified for a small local business, and costs meaningfully more per
  call than current Apify spend. Would need one real test call against a known test
  business to actually settle whether it helps — not yet done, deliberately not
  fabricated as a result.

**Decision (2026-07-23): test SerpApi.** Spend one Knowledge Graph API credit (free trial
available) against the known test business's exact name/place ID and read the raw
response — settles whether it actually returns social links for a small local business
before deciding anything further.

**Unblocked (2026-07-23) — owner signed up and provided a key.** Ran the real test call:
`GET https://serpapi.com/search.json?engine=google_maps&q=Supernormal+Melbourne+restaurant&type=search`
against the real Supernormal business (5282 reviews — not an obscure entity).

**Result: confirmed empty, as suspected — SerpApi does NOT solve this.** Grepped the
entire raw response (not just expected fields) for `instagram`/`linkedin`/`twitter`/
`x.com`/`facebook`/`social`/`tiktok` — zero occurrences anywhere. Google's own Knowledge
Panel simply doesn't carry social links for this business via this endpoint, matching
the working theory (the LinkedIn/X links the owner sees are entered directly in the
Business Profile *owner* dashboard, not surfaced on the public-facing panel this scrapes).
**Item 7 verdict unchanged and now empirically confirmed, not just theorized:** genuine
data-source limitation; mapping/seeding code was already correct and complete; the only
path that could actually deliver this data is the Business Profile API + owner OAuth
consent option already researched (a future post-claim "Connect your Google Business"
feature, not a signup-time fix).

**Bonus finding from the same test call, unprompted but worth recording:** the same
response carries a genuine `menu` field — a direct link (`supernormal.net.au/menus`) PLUS
**20 real Google-sourced menu photos** — plus a rich `extensions` block (12 categories:
highlights, offerings, dining_options, accessibility, atmosphere, crowd, planning,
payments, parking, etc.), popular-times graph data, full star-rating distribution, and
price-range data. None of this is currently captured by anything in this pipeline. Full
evaluation (replace-Apify feasibility + complete extras inventory) requested separately by
the owner — tracked outside this plan doc since it's a new capability question, not a
repair to something broken.

Full source list (Google's own docs + Apify/SerpApi/Outscraper primary pages) available on
request — omitted here to keep the plan doc readable; ask if you want them re-surfaced.

---

## 8. New capability: previous-website scraper is leaving real, extractable data on the table

**Status:** fix shipped (commit `196dfd28`) — all 3 candidates built, decisions below all
executed. See the "Fix shipped" section at the end of this item for what actually landed.

Full audit of `ScanPreviousWebsiteContentJob` and everything it wires in, done
specifically to find what MORE it could reasonably capture. Precise mechanism now
confirmed for something noted loosely in item 1/6: the class that pre-fills
`Workplace.description` (and `category`/`previous_website`) before this job even runs is
`GoogleBusinessAutoSync::seedWorkplace()` (NOT `IdentitySync` — that class explicitly
never touches `description`/`contact_email`), gated on the `google_business_full_sync`
capability (business accounts only). Its single `save()` call is also what
`WorkplaceObserver` detects as a `previous_website` change to dispatch this whole scan
job — so for any Google-Business-built account, `description` is guaranteed already
populated before `AboutTextExtractor` gets a chance to write anything. A plain `partna`
account manually pasting an old URL doesn't hit this short-circuit.

**Contact email — recommended design, small/isolated:**
1. `mailto:` links on the homepage (near-zero cost, most reliable signal) — needs a NEW
   small extractor (same DOMXPath shape as `PdfLinkDetector`); the existing link harvester
   explicitly discards `mailto:`/`tel:` today.
2. JSON-LD `email`/`contactPoint.email` — reuse the existing `MetadataParser` the same way
   `AboutTextExtractor` already does.
3. One-hop `/contact` page — generalize `findMenuPageLink()`'s keyword (already exists for
   `/menu`), only tried if 1-2 found nothing on the homepage.
4. Explicitly recommended AGAINST: footer text-pattern deobfuscation ("email [at]
   domain") — poor cost/benefit, and JS-obfuscated variants are architecturally
   unreachable anyway (this job does one plain fetch, no headless render, by design).

**About/description — two separable pieces:**
- Easy: a same-host `/about` one-hop reusing the EXISTING, unchanged `AboutTextExtractor`
  against that page too (some sites' JSON-LD/meta-description lives there, not the
  homepage). Small/isolated.
- Harder, needs real design + validation before shipping: actual "About"/"Our Story"
  heading → following-paragraph prose extraction — every current extractor in this
  pipeline is a narrow structured-data lookup (JSON-LD/meta/href), none do heuristic
  prose-boundary detection, and real sites vary a lot in how heading/paragraph markup
  nests. Buildable (the codebase already has the exact XPath case-fold idiom needed,
  reused from `WebsiteLogoCandidateExtractor`), but needs validation against real site
  fixtures first.
- **Decision (2026-07-23): prefer the business's own words.** Website-sourced description
  becomes the DEFAULT shown description when available, demoting Google's editorial
  summary — not just a fallback. This means the current unconditional fill-if-empty
  precedence (Google always wins the race today) needs to change alongside building the
  richer prose extractor, not just the extractor itself — likely: run the website
  About-extraction attempt regardless of whether Google already filled `description`, and
  have it OVERWRITE (not skip) when it finds real prose, tagging `field_sources`
  accordingly. Given the heuristic nature of the extraction (see above), this raises the
  bar on validating the extractor against real sites before shipping, since a bad
  extraction is now user-facing by default.

**Survey of other signals — full table in the research, headline results:**
- **Gallery/cover photos**: the standout candidate — real existing field (`SiteMedia`
  pool), high prevalence on real sites, medium reliability, genuinely higher-quality than
  Google's own often-sparse photos. Needs a NEW extractor (harvest `<img>` candidates,
  size/heuristic-filtered like the existing logo extractor) PLUS new plumbing to actually
  download+store binary media (nothing in this pipeline does that today — every current
  extractor only pulls URLs/text). Medium effort.
- Price/cuisine tags, testimonials, press/awards: **no field exists yet** for any of
  these — out of scope until a schema/section decision is made separately, not just an
  extraction gap.
- Opening-hours override, second "contact" phone: fields exist or partially exist, but
  low value/reliability vs. what Google already provides — not worth building.
- Other social/booking links: already fully covered by the existing link harvester —
  no gap found.

**Architecture note that applies to ALL new one-hop fetches (contact, about, etc.):** the
job has a hard 60s timeout and doesn't use the existing `SafeUrlFetcher::fetchMany()`
concurrent-pooled-fetch capability — it does sequential fetches today (homepage, one
optional menu hop, favicon). Stacking MORE one-hop fetches serially (contact, about) risks
timing out before reaching accent/logo extraction at the end. Recommendation: fetch
multiple one-hop candidates concurrently via the already-existing `fetchMany()` rather
than adding more sequential fetches.

**Suggested build order (not a decision, just a sequencing read):** contact-email
mailto:+JSON-LD → `/about` one-hop reusing the existing extractor → `/contact` one-hop
(paired with the about fetch via `fetchMany()`) → gallery photos (medium effort, new
plumbing) → prose heuristic (only once the precedence question above is settled).

**Fix shipped (commit `196dfd28`).** All 3 candidates built, decisions above executed as
written:
- **Contact email:** new `ContactEmailExtractor` — mailto: links (filtered against
  noreply@/no-reply@/donotreply@/etc.) first, then JSON-LD `email`/`contactPoint.email`.
  Fills `Workplace.contact_email` fill-if-empty via a new `WorkplaceContentApplier::
  applyContactEmail()`.
- **About/description:** the plain existing `AboutTextExtractor` (JSON-LD/meta,
  fill-if-empty) is unchanged and still runs first. New `AboutProseExtractor` (heading
  "About"/"Our Story" → following-paragraph heuristic, 3-paragraph/1000-char cap) runs
  alongside it. Precedence decision executed exactly as specified: new
  `WorkplaceContentApplier::applyProseDescription()` overwrites the current description
  whenever prose is found, UNLESS the current value's `field_sources` says `'manual'` —
  the business's own words now win over Google's editorial summary and over this
  extractor's own plain fill, matching the decided default.
- **One-hop /about + /contact:** `findMenuPageLink()` generalized to `findPageLink(...,
  $keyword)` or, shared by menu/about/contact. Both hops fetched CONCURRENTLY via
  `SafeUrlFetcher::fetchMany()` exactly per the architecture note above, only for whichever
  of prose/email came up empty on the homepage.
- **Gallery photos:** new `WebsiteGalleryCandidateExtractor` (harvests `<img>` candidates
  outside header/nav/footer, filtered by noise-pattern + declared-size heuristics) + new
  `GalleryAutoGrabber` (download/validate/upload plumbing — the "nothing in this pipeline
  does that today" gap this item flagged; mirrors `LogoAutoGrabber`'s fetch/validate
  pattern) + a new dispatched `WebsiteGalleryScanJob` (own job, same 60s-timeout rationale
  as the PDF/HTML menu jobs). Fills an EMPTY gallery pool only — never tops up one with
  existing photos, same "fills empty slots, never touches populated ones" contract as the
  logo grabber.

Live-verified against the real Supernormal site researched earlier: homepage alone has
neither an email nor about-prose (both correctly return null — nothing fabricated); the
one-hop `/contact` fallback correctly found `info@supernormal.net.au`; no `/about` page
exists on the real site (confirmed via the link harvester, so prose correctly stayed
null rather than guessing). Full test coverage added (extractors, applier, both jobs);
backend full suite green (4838 passed).

---

## 9. Existing, currently-live gap: a scraped contact email never reaches the public site

**Status:** confirmed real bug, found as a byproduct of the item 8 research — distinct
from "should we build new contact-email scraping," this affects something that ALREADY
runs today.

`Workplace.contact_email` and the public sitepage's actual contact-email field
(`User.public_contact_email`, read by `SitepageDataResolverService::getPublicContact()`)
are two different columns. The ONLY code path that copies one into the other
(`UserWorkplaceController::mirrorContactFields()`) fires only on a manual dashboard save
— never automatically. `InstagramIdentitySync::applyContactFields()` ALREADY fills
`Workplace.contact_email` from Instagram's `businessEmail` field today, for real accounts,
right now — and that scraped email sits invisibly in the dashboard editor (it does render
there) until the account owner happens to open and re-save the Brand Info form. It never
reaches the live public page on its own.

**Fix (not applied yet):** likely a one-line addition mirroring the existing fill-if-empty
pattern (`mirrorContactFields()`'s own `isBlank()` check) so any automatic writer of
`Workplace.contact_email` also fills `User.public_contact_email` when the latter is empty,
not just the manual-save path. Small, isolated, same shape as the item 4 allowlist fix.

**Correction (2026-07-23), before shipping:** re-checked the ORIGINAL `mirrorContactFields()`
(via git archaeology back to the commit that first added it, `adb938bf`) — it was NEVER
fill-if-empty / never had an `isBlank()` guard. It has always been unconditional
overwrite-on-change (`if ($user->public_contact_number !== $phone) { ...set... }`), on the
manual-dashboard-save path only, since the method's creation. The "isBlank() fill-if-empty"
description above was this plan doc's own mischaracterization, not the real prior behavior.

**Fix shipped (commit `afa230c2`).** Moved `mirrorContactFields()` from
`UserWorkplaceController` (manual-save-only) into `WorkplaceObserver::saved()`
(`wasRecentlyCreated || wasChanged(['phone', 'contact_email'])`) — a byte-for-byte
faithful port of the existing overwrite-on-change logic, now firing for EVERY writer
(scan, sync, manual) instead of only the dashboard save path, which is exactly what this
item asked for. Backend full suite green.

**Genuine open product question (both critic-agent passes independently flagged this —
not a bug I introduced, but real, and now reachable through more paths than before):**
`User.public_contact_email`/`public_contact_number` are ALSO independently editable via
`PATCH /me` (`UpdateUserRequest.php:37`) — a second, genuinely separate write path from
the Workplace Contact card. The shipped mirror is unconditional overwrite-on-change, so
the sequence "set a distinct public email via `/me` → later edit the workplace phone (not
even the email)" silently reverts the independently-set email back to whatever
`Workplace.contact_email` currently holds, because the mirror re-syncs BOTH fields
whenever EITHER changes. This exact precedence has been live (manual-save-only) for
months with no reported complaint, so it may be the intended "Workplace Contact card is
always authoritative" design — but I deliberately did NOT unilaterally "fix" it this run,
because the two plausible fixes have real, different trade-offs that deserve an actual
product decision, not a guess:
- **Strict fill-if-empty** (only mirror once, never touch an already-set User field again)
  would ITSELF regress today's behavior for the common case — a user who edits their
  Contact card's email expecting it to keep showing publicly (the normal mental model for
  anyone who's never touched the separate `/me` endpoint) would stop seeing that edit
  propagate after the first sync.
- **Real provenance-aware precedence** (only protect a value the user set independently
  via `/me`, keep syncing otherwise) is the more correct fix but needs a new
  provenance/source column on `User` for `public_contact_email`/`public_contact_number` —
  meaningfully bigger scope than this item asked for.

**Owner decision needed:** which precedence is actually wanted — Workplace-always-wins
(current, unchanged), strict fill-once, or build real provenance tracking? Until decided,
current (pre-existing) behavior is unchanged; the missing-mirror bug this item was
actually about is fixed either way.

---

## 10. Post-signup dashboard redirect shows a 2FA setup prompt — should not

**Status:** root cause traced to a concrete, well-evidenced mechanism; one open question
remains (why the backend would return the value that triggers it). The originally
dispatched background agent for this item never completed overnight (ran many hours,
far past the 5-13 minutes the other three research threads took) — the investigation
below was done directly instead, not by that agent; its result should be treated as
superseding whatever that agent might still return later, not merged with it.

**What's confirmed:** this is NOT a deliberate MFA nudge feature and not the on-demand
step-up modal system (`MfaStepUpProvider`/`useMfaStepUp` has zero callers anywhere in the
app — dead code, ruled out). The exact UI copy "Set up two-factor authentication" traces
to exactly one component that could plausibly appear unprompted:
`app/(app)/account/(dashboard)/staff/_staff-mfa-gate.tsx`'s `EnrollFlow`, which renders
whenever a session isn't `aal2` and has zero verified MFA factors — but that component
only ever mounts inside `staff/layout.tsx`, i.e. only under `/account/staff/*` routes.

The connecting piece: `overview/page.tsx` (where signup/claim redirects to) has its own
client-side redirect —
```tsx
const isStaff = currentAccount.accountType === "staff"
useEffect(() => { if (isStaff) router.replace("/account/staff/overview") }, [isStaff, router])
```
— and `accountType` here is populated from `lib/account/map-snapshot-to-account.ts:178`
(`accountType: snapshot?.role ?? baseAccount.accountType`), i.e. directly from a `role`
field on the account snapshot response. If that field ever comes back `"staff"` for an
ordinary business/individual account, the Overview page immediately bounces the user to
`/account/staff/overview`, which is wrapped in `StaffMfaGate` — and since a brand-new
account has zero MFA factors enrolled, that gate shows exactly "Set up two-factor
authentication" instead of the real dashboard. This matches every detail of what was
reported: redirected toward the dashboard, but a 2FA setup card shows instead.

**Confirmed NOT a genuine staff account:** queried `core.partna_staff` directly for the
test account (joined on `auth_user_id`) — zero rows. This account has no staff
relationship in the database at all. So if the `role` snapshot field really did come back
`"staff"`, that's a real backend bug (or a transient/race-condition value right after
claim), not a correctly-classified account being gated as designed.

**Open question, not yet resolved:** exactly why/when the backend's account-snapshot
`role` field would return `"staff"` for a definitely-non-staff, freshly-claimed account —
whether it's a genuine miscomputation, or a transient race right after claim (e.g. a
stale cached response, a JWT claim not yet refreshed) that would self-correct on a
subsequent load. Needs either a live reproduction (claim a fresh test account and watch
the network response for the snapshot/`role` field in the moment right after redirect) or
tracing the backend endpoint that serves this snapshot field for the claim/redirect
moment specifically — not yet done.

**Further investigation (2026-07-23), root cause still not fully pinned down:** re-traced
both sides end to end looking specifically for a staleness/caching bug, since that's the
most plausible "worked on reload" shape a transient race would take:
- Frontend: `/me` and `/staff/me` both fetch with `cache: 'no-store'` plus a `_ts`
  cache-busting query param (`lib/account/me-request.ts`, `lib/account/snapshot-fetcher.ts`)
  — no HTTP/browser caching layer in play. The `/staff/me` fallback path (triggered on a
  403 from `/me`) is a dead end for this bug specifically: that route's own middleware
  chain includes `require.aal2`, so it would 401/403 on the SAME aal2 gate regardless of
  real staff status for a fresh, MFA-less claim — it can't be the mechanism that produces
  a false `role: 'staff'`.
- Backend: `UserSelfController::show()` deliberately does NOT trust a cached `is_staff`
  value — it re-queries `PartnaStaff::where('auth_user_id', ...)` FRESH on every request
  and attaches it via `setRelation()` right before building the resource, specifically so
  staff promotion/demotion (and, by the same logic, correct non-staff classification)
  reflects immediately rather than riding a stale cache window. `UserCacheService`'s
  hydrated-model cache (60s) is separately guarded against auth_user_id drift (explicit
  mismatch check + self-healing cache-bust). No caching bug found on either side.
- New finding: the dashboard layout (`app/(app)/account/(dashboard)/layout.tsx`) has its
  OWN independent-looking path-allowlist redirect ("Path not allowed → redirect to
  capabilities.defaultRoute") that runs on every protected-route render, not just once at
  Overview. Live-confirmed it correctly bounces a genuine non-staff account (ollies test
  account) straight back to the real dashboard on a direct navigation attempt to
  `/account/staff/overview`. But it is NOT actually independent verification: it's driven
  by the exact same `role` field from the exact same single `/me` snapshot as the Overview
  page's own redirect, so a wrongly-classified account would sail through both checks
  identically — there is no genuinely separate signal anywhere in the stack that could
  catch a bad `role` value before it does damage.

**Fix shipped (2026-07-23) — defense-in-depth, not a root-cause fix:** since every guard
in the stack traces back to one unverified upstream read, and the exact backend trigger
couldn't be confirmed without live-reproducing a fresh claim (blocked — requires creating
a new account, prohibited even under this run's blanket autonomy), shipped the safe half
of the two options the earlier pass proposed: a frontend escape hatch. `StaffMfaGate`
(`app/(app)/account/(dashboard)/staff/_staff-mfa-gate.tsx`) now renders a "Not staff?
Return to your dashboard" link in its shared `GateShell` wrapper — covers the loading
state, EnrollFlow, and ChallengeFlow alike, since it's rendered once in the wrapper all
three share. Clicking it calls `router.replace('/account/overview')`. This doesn't fix
the (still-unconfirmed) cause of a wrong `role` value, but it does directly fix the
reported user harm: nobody wrongly routed here is ever stuck behind a blocking 2FA wall
again, regardless of what causes the misroute. Tested (4 new tests: aal2 bypass unaffected,
escape hatch present + navigates correctly from all three gate states) and typecheck/lint/
full suite green.

**Still open — needs live reproduction to fully close:** the exact backend trigger for a
false `role: 'staff'`/`is_staff: true'` on a freshly-claimed, definitely-non-staff account
remains unconfirmed. If this recurs, the highest-value next step is watching the raw `/me`
network response in the moment right after a fresh claim (not after the fact) — everything
reachable by reading code has now been checked twice without finding the mechanism.

---

## 11. Instagram auto-connect returns zero media for real, content-rich accounts

**Status:** root cause found, high confidence, fix not started.

**Symptom:** a genuinely public, content-rich Instagram business account (this run's test:
`@supernormal_180` — 2,314 posts, 83.4K followers, confirmed live and public) auto-connects
successfully (`last_refresh_status: 'ok'`) but the stored connection payload has
`images: []`, `videoUrl: null`, `videoPoster: null`, and even `profilePicUrl: null` —
nothing at all to show. The dashboard's `PUT /api/content/instagram-auto` toggle
(`ContentController::setInstagramAuto`) is a pure display switch over this already-scraped
payload (`selection->setInstagramAuto($site, enabled)`) — it does not re-scrape, so toggling
it on/off can never retroactively populate media the original scrape never fetched.

**Root cause:** `InstagramScraper::fetchProfile()` (`app/Services/Platforms/InstagramScraper.php:34-39`)
calls the Apify actor `figue~instagram-profile-scraper` (`config('partna.instagram.actor')`)
with only `{'profiles': [...], 'resultsLimit' => 24}`. Per the actor's own published input
schema, it accepts exactly two parameters: `profiles` and `includeRecentPosts` (boolean) —
documented as **"off by default (faster, profile data only); enable to also return recent
posts."** The code never sends `includeRecentPosts: true`, so the actor is being called in
its explicit profile-only mode on every single connect, for every account — it is working
exactly as configured, just not configured the way `InstagramConnectionSeeder`/`latestMedia()`
assume. `resultsLimit` is not a documented input for this actor at all and is very likely
silently ignored.

Confirmed directly from the diagnostic log already built into the code for exactly this
suspicion (`InstagramScraper::latestMedia()`'s `Log::info('instagram.latest_media', ...)`):
for this connect, `posts: 0, videos: 0, picked_photo: false, picked_video: false` — despite
`postsCount: 2314` in the very same profile response. Profile-level stats survive because
they ARE part of the "profile data only" response; only the posts array itself is being
suppressed by the actor's own default.

**This is not specific to this test account.** Every Instagram auto-connect on the platform
is structurally getting zero post media today, silently — a real account with thousands of
posts and a genuinely empty account (item 3, above) currently produce an identical empty
payload, because the one parameter that would ever return posts is never sent. Item 3's fix
(the `UnableToDeleteFile` crash) only stopped this from crashing; it never touched why the
media is empty.

**Verification pass (2026-07-23, second check):** fetched the actor's actual input-schema
page (not just its store listing). Confirmed: the schema defines EXACTLY two inputs —
`profiles` (required) and `includeRecentPosts` (boolean, default `false`). **`resultsLimit`
does not exist in the schema at all** — our `'resultsLimit' => 24` has been silently ignored
on every call, upgraded from "very likely" to confirmed. Post cap when enabled: 12 per
profile. Also verified `ContentController::setInstagramAuto` → `ContentSelectionService::
setInstagramAuto()` (`app/Services/Site/ContentSelectionService.php:222`) really is
display-only: it flips `content_instagram_auto_enabled` + reconciles content-selection
slots inside a transaction — no scrape anywhere in the path. Both original conclusions hold.

**Fix (verified, not applied yet):**
1. Add `'includeRecentPosts' => true` to the Apify request body in
   `InstagramScraper::fetchProfile()` (~line 38).
2. DELETE the `'resultsLimit' => self::RESULTS_LIMIT` request key and the `RESULTS_LIMIT`
   constant (`InstagramScraper.php:18`) — confirmed nonexistent in the actor's schema, so it
   is dead weight that misleads readers. Rewrite the constant's comment trail: the real pool
   is ≤12 posts, so "8 covers + healthy manual-picker pool" expectations downstream should be
   re-read against a 12-post ceiling (check `latestMedia()` consumers for any assumption of
   more).
3. Re-test against a live reconnect (this same `supernormal_180` connection is a ready-made
   regression case) and confirm `latestMedia()`'s diagnostic log shows `posts > 0` and
   `picked_photo`/`picked_video` flip true.
4. Nice-to-have, not blocking: add the same style of diagnostic logging `latestMedia()` has
   to the `profilePicUrl()` path — right now a null profile pic is silently indistinguishable
   between "Apify didn't return one" and "the mirror fetch failed for another reason," which
   will start to matter once posts are actually flowing.

---

## 12. Website-scanned wide/full logo never captured — SVG rejected before it reaches the logo pipeline

**Status:** root cause found, high confidence, fix not started. Not a crash — the square
logo slot still gets filled from a fallback candidate, so the account isn't visibly broken,
just missing the wide logo.

**Symptom:** `LogoAutoGrabber` (triggered from `ScanPreviousWebsiteContentJob`) found the
business's homepage header logo — supernormal.net.au renders its logo as an inline `<svg>`
in the page header, exactly the "premier source" `LogoAutoGrabber`'s own docblock describes
(`app/Services/Design/LogoAutoGrabber.php:20-29`, "the RENDERED header logo (img currentSrc
/ inline `<svg>`) is the premier source"). The grab attempt logged `Failed to store
singleton original... Rejected: MIME type 'image/svg+xml' is not an accepted image format.`
A PNG candidate (from the fallback ranks — favicon/apple-touch/manifest family; the log line
doesn't record which kind) succeeded a second later and filled the SQUARE slot, but the
FULL (wide) slot — for which the inline SVG was very likely the only sufficiently wide
candidate available on this site — was never filled.

**Root cause, traced through the full call chain:**
`LogoAutoGrabber::tryInlineSvg()` (line 300) → `upload()` (line 507) →
`MediaUploadService::uploadSingleton()` (line 182) → `ImageVariantService::storeOriginal()`
(line 299) → `assertImageMime()` (lines 507-515), whose allowlist is
`ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp']` (`ImageVariantService.php:26`)
— no `image/svg+xml`, unconditionally, for every caller.

The intended SVG pathway does exist downstream: `MediaUploadService::dispatchSingletonProcessing()`
(lines 506-522) explicitly routes design-pool logos through a background-removal +
vectorization job (`ProcessLogoVariantsJob` via `dispatchLogoJob()`) whenever
`partna.logo_removal.enabled` is true — exactly the "processor rasterizes it and stores the
sanitized source as the vector" behavior `LogoAutoGrabber`'s own docblock promises. But
`dispatchSingletonProcessing()` only runs AFTER `storeOriginal()` has already succeeded
(`uploadSingleton()` line 194, gated behind the try/catch at lines 181-191) —
`assertImageMime()`'s blanket rejection fires first and unconditionally, so **no SVG can
ever reach the logo-aware branch that's supposed to handle it.** The two halves of this
feature — the grabber that fetches/ranks inline-SVG logos, and the storage layer meant to
accept and rasterize them — are out of sync. This isn't a deliberate tradeoff; it's a real
gap between documented intent and actual behavior. Confirmed `partna.logo_removal.enabled`
is a real, currently-checked flag (`config/partna.php:1184`) and that it must be on for this
account (otherwise `tryInlineSvg()` would have short-circuited with
`rejected:svg-pipeline-disabled` before ever calling `upload()` at all) — so this is blocked
at the storage layer specifically, not by the feature flag.

**Verification pass (2026-07-23, second check) — `ProcessLogoVariantsJob` now read end to
end.** The job is INPUT-AGNOSTIC about format: it reads the stored original's raw bytes and
ships them to the self-hosted logo-processor container via `LogoProcessorClient::process(
imageBytes, filename, mime: original_mime ?? 'image/png')` — no server-side decode of the
original before shipping. The container returns a background-removed transparent PNG (WebP
variants are generated from THAT, never from the original) plus an optional VTracer SVG,
which is stored as a `MediaVariant(variant_key='vector', artifact_type='svg')`. So the
job/pipeline side needs NO structural change for SVG input — the storage layer ahead of it
is the only blocker, with one remaining unknown pushed one level down:

- **Remaining unknown (container-side):** whether the logo-processor container itself
  accepts `image/svg+xml` input bytes (i.e. rasterizes the SVG before rembg). The client
  just forwards bytes + MIME. Verify with one dev-environment call sending a small SVG to
  the processor (or by reading the logo-processor service's accepted-format handling)
  BEFORE building the storage-layer change — if the container rejects SVG, the fix must
  also add a server-side rasterization step, which is a materially bigger job.

**Fix (refined, not applied yet):**
1. `MediaUploadService::uploadSingleton()` gets an SVG-aware branch for logo purposes when
   `partna.logo_removal.enabled` is true. Note BOTH gates in the current storage path block
   SVG, not just one: `assertImageMime()`'s allowlist (`ALLOWED_IMAGE_MIMES`, line 26) AND
   `safeExtension()`'s `ALLOWED_EXTENSIONS` (line 29, no 'svg' — an SVG original would be
   stored misnamed as `.jpg` even if the MIME gate passed). Either a purpose-aware bypass
   that stores the SVG original directly (correct `.svg` key + `original_mime`) and hands
   off to `dispatchLogoJob()`, or purpose-parameterized allowlists — bypass preferred:
   `ImageVariantService` stays raster-only, matching its name and every other caller.
2. Keep `LogoAutoGrabber::svgIsSafe()`'s sanitization as the ingest gate (already runs
   before upload — scripts/foreignObject/external refs rejected). The stored SVG is served
   only via `<img>` per the job's own docblock, so script-in-SVG is inert downstream too.
3. Add a regression test in the same spirit as the `WebsiteAccentExtractor`/
   `InstagramConnectionSeeder` fixes elsewhere in this doc: a wide inline-SVG candidate should
   end up stored (as SVG or its rasterized form, whichever the real fix produces), not
   silently dropped.
4. This specific `supernormal` test account would need a fresh logo re-scan to actually pick
   up the full logo once fixed — `grabIfEmpty()`'s own occupancy check currently skips a slot
   that's already filled (the square one is), so this won't self-heal retroactively without
   either a manual re-grab path or a brand-new signup.

**If not pursued purely as a bug fix, this is a real system improvement regardless:** any
business whose logo happens to be an inline SVG — a common, modern pattern — can never get
an auto-populated wide/full logo today, only businesses with raster (PNG/JPG) header logos
benefit from that slot. Worth prioritizing independent of how often SVG headers turn out to
show up across future signups.

---

## 13. New: signup should collect a password so the user can log in with one

**Status:** captured per owner request; not yet researched or scoped. Do not implement
without doing the auth-model check below first.

**Ask:** the signup flow currently never asks the user to set a password. Add a step that
collects one, placed immediately after the existing email OTP verification step.

**Open questions to resolve before writing an implementation plan (not yet checked this
pass):**
- What is the actual current auth model? **Partially resolved (2026-07-23 verification
  pass):** the signup flow's own auth component states it in its header comment —
  `Partna-Frontend/app/(app)/account/(auth)/sign-up/pre-account/auth-and-processing-step.tsx`
  line 1: "AuthAndProcessingStep — passwordless email-OTP auth". So the app side is
  confirmed OTP-only today; this IS an auth-model addition. Still to confirm against the
  Supabase project dashboard: that the Email provider allows password sign-in (OTP and
  password both live under the same Email provider in Supabase, so
  `supabase.auth.updateUser({password})` on the post-OTP session is the expected mechanism
  — high confidence, but verify in the dashboard before building).
- **Where it lands (scoped from the real step machine):** the signup flow is a 3-step
  union — `type Step = "options" | "identity" | "auth"` in
  `pre-account-signup-form.tsx:25` — with OTP inside `AuthAndProcessingStep`. The password
  step slots in as a new step after OTP verification succeeds, INSIDE the auth step's
  post-verify sequence (the session exists at that point, which `updateUser` needs). The
  claim flow (`app/(app)/claim/[subdomain]/page.tsx`) reuses the same auth-step pattern for
  approved/staff-built accounts — the password step must land in BOTH paths or invited
  users never get one.
- If passwords are to be introduced: which exact step of the `Partna-Frontend` signup flow the
  new field slots into, validation rules, and how it's actually set — Supabase (not our own
  `core.users`) owns credentials, so this is very likely a
  `supabase.auth.updateUser({password})`-style call made right after OTP verify, while the
  user still holds the valid session the OTP step produced.
- Whether already-claimed accounts (including every test account from this run) need a
  retrofit "set a password" path too, or only new signups going forward.

---

## 14. New: post-password skippable onboarding steps for `partna` accounts

**Status:** planned, not yet built. Scope: **`partna` account type only, for now** (item 15
covers the `business` equivalent).

**Order:** Instagram (existing) → Email OTP (existing) → Password (item 13) → the following,
each individually skippable.

**Confirmed first — is there a separate sector/industry list?** No. Exactly one canonical
list exists: `SectorTaxonomy::SECTORS` (`app/Services/Profile/SectorTaxonomy.php`), ~70 slugs
across 10 groups (Food & Drink, Beauty & Personal Care, Health & Fitness, Professional
Services, Retail & Shopping, Home & Trade Services, Hospitality & Events, Automotive,
Creative & Entertainment, Education & Coaching, + "Other"). It's already the shared target
for manual picking AND auto-classification — `fromGoogleCategory()` / `fromInstagramCategory()`
both map raw category text onto this same slug space. The only other "industry" field in the
codebase is `EarlyAccessSignup.workplace_or_industry`, a free-text string on the unrelated
early-access waitlist form — not a taxonomy.

**Step A — Sector.** Only asked if `core.users.sector` is still null at this point (i.e.
neither Instagram's `businessCategoryName` nor anything else has classified one yet via
`SectorTaxonomy::fromInstagramCategory()`). Reuses the existing `SectorTaxonomy::all()`
picker payload — no new taxonomy work needed.

**Step B — Connect a store (Shop).** One URL input, with an optional discount code in the
SAME step — both are already part of the existing `POST /api/platforms/shop/brands` call
(`AddShopBrandRequest`: `url`, `discountCode`). That call already auto-detects the provider
(Shopify/WooCommerce/Squarespace/Big Cartel/generic), auto-fetches name/logo/products, and
warms a product-picker cache in the same request — so "select any [products] to feature"
slots in immediately after, against real fetched data, not a placeholder. Confirmed the
Shop feature carries no account-type gate anywhere in `AccountCapabilities`. **Referral is deliberately NOT part of this step** — it isn't part of the existing connect
call either. Verified exact shape (2026-07-23 second check): the input field is
`referralUrl` on `UpdateShopBrandRequest` only (user pastes a referral LINK; its query
suffix is what gets stored, as the `referral_query` column; empty/null clears it). It stays
a dashboard follow-up rather than an onboarding field. Store cap: `MAX_BRANDS = 5`
(`ShopController.php:65`) — the onboarding step only ever adds the first, so the cap can't
bite here.

**Step C — Workplace (Google Business / name),** for sector types that would have one.
**Backend already exists (2026-07-23 second check):** `GET/PUT/DELETE /api/site/workplace`
(`UserWorkplaceController` in `app/Http/Controllers/Api/User/SiteManagement/`) — so this
step is frontend-only plumbing onto a live endpoint, plus whatever the Google-Business-
lookup half needs (the pre-account identity step already has a Places search UI for the
business flow that can be reused for the lookup UX). **Open question, still not resolved:**
which sectors count as "would have a workplace" vs. mobile/individual/home-based ones
(musician, content-creator, writer, personal-trainer, mobile hairdresser, etc.) has no
existing flag to key off — `SectorTaxonomy` doesn't mark this distinction today. Needs an
explicit per-sector (or per-group) yes/no before this step can be built; flagging rather
than guessing a list.

**Step D — up to 2 sector-based integration suggestions**, pre-filled from Instagram's
`unmatched` list where a matching link is already sitting there (see item 16 below for what
`unmatched` actually contains for a partna account), otherwise a normal connect prompt.

**Correction applied: "Booking" is ONE unified input, not Fresha vs. Square as separate
picks.** The registry already smart-detects a pasted booking URL's real provider
(`PlatformRegistryServiceProvider`: `$r->get('fresha')->detect(new HostMatch('~(^|\.)fresha\.com$~'))`,
same for Square) — so this step just asks for "your booking link," and if it resolves to
Fresha specifically, the existing Fresha connect flow's own staff-selection step ("select
which user you are" — confirmed via `FreshaController`'s staff list +
`FreshaSelectionResource`) runs exactly as it does today. Nothing new needed there, just
route into it.

Full mapping, corrected per feedback (Education & Coaching → Booking + YouTube; no sector
gets Fresha/Square as two separate suggestions):

| Group | Sectors | #1 | #2 |
|---|---|---|---|
| Food & Drink | restaurant, cafe, bakery, bar, food-truck, caterer, personal-chef | Booking | Online Ordering |
| Beauty & Personal Care | hair-salon, barber, nail-technician, esthetician, spa, brows-lashes | Booking | — |
| Beauty & Personal Care | makeup-artist, tattoo-artist | Booking | Pinterest |
| Health & Fitness | personal-trainer, yoga-instructor, gym | Booking | Strava |
| Health & Fitness | nutritionist, physiotherapist, chiropractor, therapist, dentist | Booking | — |
| Professional Services | accountant, lawyer, financial-advisor, consultant, real-estate-agent, insurance-broker, mortgage-broker, marketing-agency, it-services, virtual-assistant | LinkedIn | Booking |
| Retail & Shopping | clothing-boutique, jewellery, florist, gift-shop, homewares, artisan-maker | *(covered by Step B)* | Pinterest |
| Home & Trade Services | plumber, electrician, builder, painter, cleaner, landscaper, handyman, removalist, pest-control | Booking | — |
| Hospitality & Events | event-venue, event-planner, wedding-planner | Eventbrite | Humanitix |
| Hospitality & Events | accommodation, bartender | Booking | Custom Link |
| Automotive | mechanic, car-detailer, auto-electrician, tyre-service | Booking | — |
| Creative & Entertainment | musician, DJ | Spotify | SoundCloud |
| Creative & Entertainment | photographer, videographer | Vimeo | Pinterest |
| Creative & Entertainment | graphic-designer, artist | Pinterest | Custom Link |
| Creative & Entertainment | content-creator | YouTube | TikTok |
| Creative & Entertainment | writer | X | Custom Link |
| Education & Coaching | tutor, music-teacher, driving-instructor, dance-instructor, life-coach, course-creator | Booking | YouTube |
| Other | "Something else" | *(skip Step D entirely — no sensible default)* | |

Several groups (Beauty's clinical sectors, Health's clinical sectors, Home & Trade,
Automotive) carry only one real suggestion — no forced second pick where the platform
registry has nothing that genuinely fits.

---

## 15. New: analogous post-password onboarding for `business` accounts

**Status:** planned, not yet built.

**Order:** Google Business (existing, already mandatory earlier in a business signup) →
Email OTP → Password → the following, each skippable:

**Step A — Instagram**, only if not already connected by this point.

**Step B — up to N sector-based skippable connects, sourced from Google Business auto-sync,
not Instagram's `unmatched`.**

**CORRECTED (2026-07-23 second check — the first version of this section misread the
gating).** `GoogleBusinessAutoSync::seed()` read line by line; the real structure is:

- `seedBooking()` is NOT unconditional — it's gated on `can_use_booking`, which is
  `$isBusiness ? !$isFood : true` (`AccountCapabilities.php:68`). So: a NON-food business
  gets booking seeded; a FOOD business gets no booking at all (books via reservations
  instead — the 2026-07-15 sector-gating contract); partna always books.
- Everything else sits behind an early return on `google_business_full_sync`
  (= `$isBusiness`, `AccountCapabilities.php:60`) — partna accounts stop after booking.
  Within the business-only block: `seedReservation()` (gated `can_use_reservations`, food),
  `seedOrdering()` (gated `can_use_online_ordering` = business AND food),
  **`seedWorkplace()` (unconditional within the block — missed entirely in the first
  version of this item)**, and `seedSocials()` (unconditional within the block).

Net per business sub-type: **food business** → reservations + online-ordering + workplace +
socials auto-seeded (no booking); **non-food business** → booking + workplace + socials
(no reservations/ordering). This matches the requested examples exactly (hairdresser →
booking; restaurant → reservations + online-ordering) — meaning for business accounts the
onboarding step is purely a GAP-FILLER for what the Google listing/website didn't carry,
never a first ask. The step should read current `platform_connections` per suggested
platform and skip anything already live.

**Workplace redundancy for business — now CONFIRMED, not "likely":** `seedWorkplace()` runs
on every full-sync (business) Google Business connect, fed from the GB payload. This
session's own supernormal timeline shows the resulting `site.workplaces` row created at
signup. Item 14's Step C (workplace ask) is therefore partna-only; business accounts must
NOT get a workplace step.

**Still open:** whether item 14's Steps B (Store/Shop) and A (sector-if-missing) also apply
to business accounts — sector is near-guaranteed present for business (Google category →
`IdentitySync` fold at connect time), so Step A would almost never fire, but Step B (store)
is a genuine open product question for this account type.

---

## 16. Bio-link-in-bio unrolling: real, confirmed gap — `linkin.bio` not recognized

**Status:** root cause found, directly evidenced by this session's own test account. Small,
isolated fix.

**Verified the general mechanism works as designed:** `LinkInBioScanJob` correctly fetches a
detected link-in-bio page, filters out the page's own site-chrome links (same-host filter,
so a Linktree's own pricing/blog/help-centre links don't get seeded as the user's custom
links), classifies every remaining outbound link through the EXACT SAME
`InstagramAutoSync::handleClassifiedLink()` gating a direct bio link gets (so a bio-page-hop
link is never treated more permissively than a direct one), and falls anything unclassified
through to `CustomLinkSeeder` so nothing vanishes. This part is sound — confirmed by reading
it end to end, not assumed.

**The actual gap:** `LinkInBioDetector::HOSTS` (`app/Services/Platforms/LinkInBioDetector.php:13`)
recognizes exactly 4 aggregators — `linktr.ee`, `msha.ke` (Milkshake), `beacons.ai`,
`stan.store`. **`linkin.bio` (Later's link-in-bio product) is not on this list.** This
session's own test account's Instagram bio link IS a linkin.bio page
(`https://linkin.bio/supernormal_180`) — because it's unrecognized, `LinkInBioScanJob` never
fired for it. The link was instead added as a single inert custom link ("My Link in Bio
Page"), exactly as observed in this session's retest (`site.platform_connections`, platform
`custom`, `resource_id: link-946b70dbf882493b`). Whatever real links actually live on that
page (the restaurant's own website, reservations, etc.) were never surfaced or auto-synced.

**Fix (not applied yet):** add `linkin.bio` to `LinkInBioDetector::HOSTS`. Also worth a quick
pass for other common aggregators not currently covered — `lnk.bio`, `bio.link`,
`campsite.bio` — before assuming the list is complete; how common each actually is among
Partna's real signups hasn't been independently checked.

**Separate, related gap — a store can never unroll from a bio link at all, today:**
`WebsiteLinkHarvester::classify()` recognizes exactly 4 categories — `social`, `booking`,
`reservations`, `online-ordering`. **There is no `shop` category.** So even once a
link-in-bio page IS correctly unrolled, a link to the business's own Shopify/WooCommerce/etc.
store on it can never auto-populate a `ShopBrand` + its products the way item 14's Step B does
when the user pastes the identical URL directly into the Shop connect flow — today it falls
through to, at best, a plain inert custom link. If "otherwise connects integration or adds
custom links or products" is meant to cover stores found via bio-scanning too, that's new
work (classify → route into the Shop connect pipeline), not a fix to something already
broken — flagging as a distinct decision from the `linkin.bio` host-list fix, not bundled
into it.

**→ That expansion is now requested and fully scoped as item 17 below.** This item's own
remaining scope is just the host-list fix (17 depends on it only in the sense that a page
that never gets unrolled has no links to classify).

---

## 17. New: store / product / event / event-organiser detection for bio links + link-in-bio unrolling

**Status:** scoped for execution; not started. Owner request: when the bio scanner (direct
Instagram bio links) or the link-in-bio unroller (Linktree-class pages) finds a store, a
product page, an event, an event organiser, or a booking link, it should ADD the right thing
(brand / individual product / standalone event / organiser account / booking connection)
instead of a plain custom link — best-effort, "we won't catch them all but as many as we can."

**Where it plugs in (verified, no new pipeline needed):** all three scan paths already
funnel through the same two chokepoints —
`InstagramAutoSync::handleClassifiedLink()` (classified links) and the
`CustomLinkSeeder` fallback (unclassified links) — from: (a) direct bio links
(`InstagramAutoSync::seed()`), (b) link-in-bio unrolls (`LinkInBioScanJob`), and (c) the
previous-website link harvest (`CustomLinkSeeder`'s own docblock names all three callers).
Extending the classifier + the fallback therefore upgrades ALL THREE sources at once —
including the website scan, for free.

**Booking already works** (`WebsiteLinkHarvester::classify()` → category `booking`,
fresha/square hosts → auto-connect via `handleClassifiedLink`, XOR-locked). Nothing to add
for booking beyond what exists; the request's booking half is already live.

### Tier 1 — cheap, URL-pattern-only new categories (no fetch)

Extend `WebsiteLinkHarvester::classify()` with three new categories, reusing the platform
scrapers' OWN normalize methods for validation so patterns can never drift from what the
connect flow accepts (same discipline as the existing reservation-provider delegation):

- `event-organiser` — `EventbriteScraper::normalizeOrgUrl()` (matches
  `eventbrite.<tld>/o/<slug>`) and `HumanitixScraper::normalizeOrgUrl()`
  (`events.humanitix.com/host/<handle>` + handle forms). Platform = `eventbrite`/`humanitix`.
- `event` — `EventbriteScraper::normalizeEventUrl()` (`/e/<slug>`) and
  `HumanitixScraper::normalizeEventUrl()` (`events.humanitix.com/<slug>`). Note Humanitix
  org-vs-event share a host — org check must run FIRST (`/host/` is the discriminator).
- `shop` (host-decisive stores only) — `*.myshopify.com`, `*.bigcartel.com`. NOT generic
  hosts (that's Tier 2's probe). **Known ambiguity, decided:** `square.site`/`squareup.com`
  stays classified `booking` (current behaviour) even though Square Online stores share the
  host — a host pattern can't disambiguate, and silently flipping existing booking behaviour
  would be a regression.

### Tier 2 — probe-based store/product detection for UNCLASSIFIED links

A bare URL (the business's own storefront, a product page) can't be classified by host — it
needs fetching. The existing machinery already discriminates perfectly:
`GenericShopScraper::readProductPage()` returns product data, OR
`OUTCOME_STORE_PAGE` + `storeUrl` when the URL is a storefront homepage, OR nothing;
`ShopProviderDetector::detectDetailed()` then resolves a storefront's provider (its probe
chain is up to ~5 HTTP round-trips per URL — the reason this tier CANNOT run inline in
`InstagramConnectJob`, whose 150s timeout is mostly consumed by Apify's 110s scrape).

New bounded async job **`ProbeCommerceLinksJob`** (scraping queue, timeout ~90s, ≤6 URLs
per dispatch, `ShouldBeUnique` on user+URL-set hash):
1. Per URL: `readProductPage()` first (one fetch, three-way discriminator).
2. Product found → seed as individual product (existing `individual` bucket semantics,
   `MAX_INDIVIDUAL_PRODUCTS = 20`, dedup by productId — all already enforced by the flow
   being extracted in the seeder work below).
3. `OUTCOME_STORE_PAGE` (or Tier-1 `shop` classification) → `ShopProviderDetector` →
   seed as `ShopBrand` (`MAX_BRANDS = 5`, updateOrCreate by brand_id — idempotent).
4. Nothing → `CustomLinkSeeder` fallback, exactly today's behaviour. The job OWNS the
   fallback: callers stop calling `CustomLinkSeeder` directly and dispatch this job with
   their unclassified/unmatched URLs instead — "nothing vanishes" is preserved, upgraded.

Callers rewired: `InstagramConnectionSeeder::autoSaveUnmatchedLinks()` and
`LinkInBioScanJob`'s two fallback sites.

### The real work — service-level seeders extracted from controller flows

The four "add" flows live inside HTTP controllers today (lock-guarded, capped,
contract-frozen). Job-context seeding needs service extractions the controllers then call —
same pattern as `CustomLinkSeeder` / `InstagramConnectionSeeder`:

- `ShopBrandSeeder` ← `ShopController::addBrand()` (detection is passed in pre-resolved;
  brand profile fetch; connection lock; cap; catalog-cache warm).
- `ShopProductSeeder` ← `ShopController::addProduct()` (individual bucket, transactional
  product-set rebuild).
- `EventsAccountSeeder` + `StandaloneEventSeeder` ← `EventsPlatformController::addAccount()`
  / `addStandaloneEvent()` (per-platform normalize + fetch via the right scraper;
  `MAX_STANDALONE_EVENTS = 10`; **organiser-account cap: none found in the controller —
  verify at implementation and add one if genuinely absent**, an unbounded auto-add from a
  scanned page would be new exposure the manual flow never had).
- Controllers refactored to delegate; every existing 422 code/message stays byte-identical
  (frozen API contract).

### Gating (all verified against the existing capability model)

- `can_autosync_scraped_connections` (DISC-7, = claimed accounts only) must gate every new
  auto-add path — same fail-closed consent rule the social/booking seeds follow.
- Shop + events carry NO account-type capability gate today (verified) — both types seed.
- Tombstone semantics: a soft-deleted `shop`/`eventbrite`/`humanitix` connection means the
  user disconnected it — route to custom-link fallback instead of resurrecting (same rule
  `handleClassifiedLink` applies to socials/booking).
- `user->isPendingDeletion()` short-circuit (CustomLinkSeeder already models this).

### Honest limits (stated up front, not discovered later)

- **JS-rendered link-in-bio pages yield nothing** — `SafeUrlFetcher` is a plain HTTP fetch;
  aggregators that render links client-side (some Beacons/Stan themes) produce an empty
  anchor list. Accepted as-is for this pass.
- **Affiliate/promo links in creator bios will connect OTHER brands' stores** as the user's
  own ShopBrand if auto-add is chosen (see question Q4) — caps + easy removal bound the
  damage, but the failure mode is real and common for content creators.
- Probe budget means a page with >6 unclassified links only probes the first 6 (order =
  page order); the rest go straight to custom-link fallback. Logged, not silent.

---

## Execution plan (items 11-17) — phases, dependencies, gates

Standing gates for every phase: backend `composer test` full suite before push; FE
`npm run typecheck && npm run lint`; deploy = push `development` (backend) / `main`
(frontend); post-deploy live smoke against the dev environment for anything that writes
(per the SQLite-vs-Postgres drift rule); plan-doc checkbox + task-list update per phase.

**Phase A — small, independent, ship first (backend only):**
- [ ] A1 (item 11): `InstagramScraper::fetchProfile()` — add `includeRecentPosts: true`,
      delete `resultsLimit`/`RESULTS_LIMIT`, adjust comments. Targeted pest over the
      scraper's fixture path. Deploy → live verify: reconnect `supernormal_180` (dashboard
      reconnect or staff re-run) → `instagram.latest_media` log shows `posts > 0`,
      `picked_photo`/`picked_video` true, payload media populated.
- [ ] A2 (item 16): `LinkInBioDetector::HOSTS` += `linkin.bio` (+ any further hosts per Q6).
      Unit test. Deploys with A1. Live verify: same reconnect also fires `LinkInBioScanJob`
      for the linkin.bio URL (log line + resulting connections/custom links).

**Phase B — SVG logo path (item 12, backend + one infra verify):**
- [ ] B0 GATE: verify the logo-processor container accepts `image/svg+xml` input (one dev
      call or read its source). If NO → STOP, re-plan (server-side rasterization needed).
- [ ] B1: SVG-aware singleton branch in `MediaUploadService::uploadSingleton()` (store
      original as `.svg` + correct `original_mime`, dispatch `dispatchLogoJob()` directly).
- [ ] B2: regression tests (SVG logo purpose stores + dispatches; non-logo SVG still
      rejected; raster path untouched). Full suite. Deploy.
- [ ] B3: live verify — re-run the supernormal website scan (or fresh test signup by owner)
      → `logo_full` slot fills from the inline SVG; check `site_media` + variants.

**Phase C — commerce/event link intelligence (item 17, backend, largest chunk):**
- [ ] C1: Tier-1 classifier categories + unit tests (org-before-event ordering pinned).
- [ ] C2: seeder extractions (`ShopBrandSeeder`, `ShopProductSeeder`, `EventsAccountSeeder`,
      `StandaloneEventSeeder`) + controller delegation, feature tests proving frozen
      contracts (existing controller tests must pass unchanged).
- [ ] C3: `handleClassifiedLink()` routing for `shop`/`event`/`event-organiser` (+ DISC-7,
      tombstones, caps) + tests.
- [ ] C4: `ProbeCommerceLinksJob` + caller rewiring (seeder/LinkInBioScanJob fallbacks) +
      tests (probe-hit product, probe-hit store, miss→custom-link, budget cap, uniqueness).
- [ ] C5: full suite, deploy, live verify against `supernormal_180`'s linkin.bio page
      (depends on A2 having landed) — expect its real outbound links to classify/probe into
      typed adds; record exactly what it produced in this doc.

**Phase D — password at signup (item 13, FE + one dashboard check):**
- [ ] D0 GATE (owner): confirm Supabase Email provider allows password sign-in.
- [ ] D1: password step component (new post-OTP-verify state inside the auth step) in
      `pre-account-signup-form.tsx` flow; same component wired into
      `claim/[subdomain]/page.tsx`'s auth sequence. `supabase.auth.updateUser({password})`.
      Validation per Supabase project password policy (read it in D0, mirror client-side).
- [ ] D2: skip/retrofit semantics per Q2/Q3 answers. Tests (step renders post-OTP, updates,
      error paths, skip if allowed). typecheck+lint, deploy, live verify on a fresh test
      signup (owner does the signup; verification via logs + sign-in-with-password check).

**Phase E — partna onboarding steps (item 14, FE + one small backend endpoint):**
- [ ] E1 (backend): `GET /api/onboarding/suggestions` — server-computed: reads sector,
      live `platform_connections`, Instagram `unmatched`; returns ordered, deduped,
      not-yet-connected suggestions per the item 14 table (table lives server-side as the
      single source of truth) + prefill URLs where `unmatched` carries one. Feature tests.
- [ ] E2 (FE): step-machine extension after claim, before dashboard redirect: Sector
      (only when null; `GET /profile/sector-options` + `PUT /profile/sector`) → Store
      (`POST /platforms/shop/brands` + product select `PUT .../selection`) → Workplace
      (partna-only, sectors per Q1; `PUT /site/workplace`) → Suggestions (E1-driven, ≤2,
      each connect via its existing endpoint; booking = one URL input, provider
      smart-detected). Every step skippable; skip-state persisted so a refresh doesn't re-ask.
- [ ] E3: gates + deploy + owner live retest of a partna signup end to end.

**Phase F — business onboarding (item 15, FE, depends on E1):**
- [ ] F1: Instagram-if-missing step post-password; then suggestions step reusing E1
      (server already knows account type + what GB auto-sync seeded — no business-specific
      endpoint work beyond the mapping covering business sub-cases: food → reservations/
      ordering gaps, non-food → booking gap).
- [ ] F2: gates + deploy + owner live retest of a business signup.

Dependency graph: A → C5's verify (A2) and D-F are independent of each other except
E1 ← F1. B independent. Recommended order: A, B0-B3, C, D, E, F — backend risk earliest,
frontend flows once their backend surfaces exist.
