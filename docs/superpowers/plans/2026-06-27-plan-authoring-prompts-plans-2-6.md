# Plan-Authoring + Implementation Prompts — Platform Integrations Redesign (Plans 2–6)

Each plan below has **two** prompts:

- **A · Author + independently review** — reads the spec + prior plans + the *real* current code,
  writes the implementation plan (via `superpowers:writing-plans`), then has a fresh Opus reviewer
  vet the plan document against the codebase. Produces a finalized plan file; does not execute.
- **B · Implement (subagent-driven)** — executes that finalized plan with Sonnet implementation
  subagents, an independent Opus review after each task, a final overall Opus review, then ships
  (push to `development`, + Supabase if the plan added a migration).

**Run them strictly in order**, A then B per plan, and only start a plan after the previous plan's
B step has merged — each plan is written and built against the interfaces the prior one landed:

```
Plan 2A author+review → 2B implement+ship ─┐
                                            ├─ Plan 3A → 3B ─┐
                                                              ├─ 4A → 4B ─┐
                                                                           ├─ 5A → 5B ─┐
                                                                                        └─ 6A → 6B (done)
```

Design spec (read by every prompt): `docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md`
Plan-1 (format + spine interfaces): `docs/superpowers/plans/2026-06-27-platform-integrations-registry-spine.md`

---

## Plan 2 — Migration toolkit + link-only

### A · Author + independently review

```
Author and independently review the implementation plan for PLAN 2 — "Migration toolkit +
link-only" — of the platform-integrations registry redesign. Do NOT execute it; produce a
reviewed, ready-to-run plan document.

PREREQUISITE: Plan 1 (registry spine) must already be merged to development. Run
`git fetch && git pull && git log --oneline -10` and confirm the spine classes exist
(app/Services/Platforms/Registry/PlatformRegistry.php etc.). If not, stop and report.

## Read first
- CLAUDE.md
- docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md
- docs/superpowers/plans/2026-06-27-platform-integrations-registry-spine.md  (format to mirror + spine interfaces)
- The ACTUAL current code below — the plan's code MUST match what is really there now. Read
  every file; do NOT fabricate class names, method signatures, or response shapes.

## Ground-truth files to read before writing
- app/Services/Platforms/Registry/{PlatformRegistry,PlatformDescriptor,PlatformCategory}.php
- app/Services/Platforms/Strategies/Connect/UrlConnect.php and Strategies/Contracts/*
- app/Http/Controllers/Api/Platforms/SingleSelectionPlatformController.php
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
- app/Http/Controllers/Api/Platforms/{Linkedin,X,Threads,Reddit,Tiktok,Facebook,Skool,CustomLinks}Controller.php
- app/Http/Resources/Platforms/{LinkConnectionResource,SkoolConnectionResource}.php
- app/Http/Requests/Platforms/ConnectSocialLinkRequest.php (+ any sibling connect requests)
- app/Services/Platforms/PlatformInput.php
- routes/api/integrations.php  (the $singleSelection loop)
- tests/Feature/Platforms/GoldenMaster/* and tests/Feature/Platforms/LinkPlatformsConnectionTest.php

## Scope of THIS plan (spec §7 link-only archetype, §10 toolkit-first)
- BUILD: a `GenericPlatformController` that resolves its PlatformDescriptor from the route
  {platform} param, uses the ManagesIntegrationConnection trait for CRUD, and shapes responses
  via the descriptor's resource class. BUILD `LinkPayload` (readonly DTO: fromArray/toArray/typed
  props). Extend PlatformDescriptor to carry a live ConnectStrategy (each platform's existing
  normalizer closure wrapped in UrlConnect) so connect() works generically.
- MIGRATE: linkedin, x, threads, reddit, tiktok, facebook (assess skool + custom-links — include
  only if they fit the link-only shape cleanly; otherwise defer to Plan 5 and say so). Point their
  routes at GenericPlatformController; DELETE their per-platform controllers once green.
- DO NOT: rewrite PlatformRefresher/ProviderDetector, touch feed/picker/bespoke platforms, drop
  the CHECK constraint, or change config('partna.social_platforms'). List these as deferred.

## Author the plan (use superpowers:writing-plans)
- Save to docs/superpowers/plans/<YYYY-MM-DD today>-platform-integrations-link-only.md
- Mirror Plan 1 exactly: required header, Global Constraints, File Structure, bite-sized TDD
  tasks (failing test → run-fail → minimal code → run-pass → commit), EXACT paths, COMPLETE code
  in every step, exact commands + expected output. No placeholders, no "similar to Task N".
- Hard rules in every migration task: the API contract is FROZEN — the golden master + existing
  contract tests stay byte-green; the old controller is deleted ONLY after its generic replacement
  is proven green; Pint --dirty before each commit; never create Laravel migrations.
- Run the writing-plans self-review (spec coverage, placeholder scan, type consistency, scope).

## Then review it independently (dispatch a fresh Opus reviewer subagent)
The reviewer reviews the PLAN DOCUMENT against the current codebase and reports PASS or must-fix:
  - Spec coverage: every link-only platform in scope has a migration task.
  - GROUNDED IN REALITY: open the referenced files and confirm every class/method/resource/route
    the plan cites actually exists with the cited signature. Flag anything fabricated or drifted.
  - Contract-freeze: each migration task asserts byte-identical responses via the golden master.
  - Type consistency: GenericPlatformController + LinkPayload + descriptor ConnectStrategy
    signatures are consistent across all tasks.
  - Scope discipline: nothing from Plans 3–6 leaked in; deferred items listed.
  - No placeholders/TODOs; right-sized TDD tasks; frequent commits.
If must-fix findings: fix the plan inline, then re-dispatch the reviewer. Finalize only on PASS.

## Output
Report the finalized plan file path and the reviewer verdict. Do NOT execute the plan.
```

### B · Implement (subagent-driven)

```
Execute PLAN 2 — "Migration toolkit + link-only" — using subagent-driven development. Work
autonomously; stop only on a genuine blocker/ambiguity or a reviewer-flagged decision.

PREREQUISITE: Plan 2 has been authored AND passed its independent plan-review (prompt 2A), and
Plan 1 is merged to development.

## Read first
- CLAUDE.md
- docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md
- The plan file: docs/superpowers/plans/*-platform-integrations-link-only.md (the one 2A authored)

## Pre-flight
1. `git fetch && git pull && git log --oneline -10`.
2. Branch `feat/platform-integrations-link-only` off origin/development. WORK IN THE MAIN CHECKOUT —
   do NOT use a git worktree (Pest feature tests break under .claude/worktrees/ here, and worktrees
   poison composer's optimized classmap).
3. Run `composer test` once to record the BASELINE pass/fail set. `composer dump-autoload -o` if stale.
4. First commit on the branch: the plan file (if not already committed).

## Execution (use superpowers:subagent-driven-development)
For EACH task in the plan, in order:
  a. Dispatch a fresh **Sonnet** implementation subagent scoped to exactly that one task. It
     follows the task's TDD steps verbatim, uses the exact code in the plan, runs the listed
     commands, runs `php artisan pint --dirty`, and commits with the plan's message.
  b. Then dispatch a fresh, INDEPENDENT **Opus** reviewer subagent that reviews ONLY that task's
     diff against the plan + spec: correctness, API contract frozen (golden master still green),
     no scope creep, tests assert real behavior, migrated platforms return byte-identical
     responses, Pint clean, follows existing patterns.
  c. Reviewer PASSES → proceed to the next task automatically (NO human gate). Must-fix → dispatch
     a Sonnet fix subagent, then re-review (Opus). Escalate to me only for a real blocker/ambiguity.

## After all tasks
1. `composer test` — green, NO new failures vs the recorded baseline.
2. Dispatch a final, INDEPENDENT **Opus** review of the ENTIRE branch diff vs origin/development:
   spec adherence, contract provably frozen (golden master + PlatformResourceContractTest green),
   behavior unchanged for unmigrated platforms, code quality, coverage, no leftover placeholders/
   dumps. Fix (Sonnet) → re-review (Opus) until it PASSES.

## Ship (only after the final review PASSES)
1. Supabase: Plan 2 adds NO migration — verify there are no new files under supabase/migrations/;
   if so, this step is a no-op.
2. Git: `git fetch && git rebase origin/development` (resolve conflicts; shared repo — expect 1-3
   cycles), confirm `composer test` still green, then merge/push to `development`.
   ⚠️ Pushing development is push-to-deploy and updates BOTH dev-api.partna.au AND api.partna.au
   (prod sitepages). Announce this before pushing; confirm the golden master is green first. This
   plan is contract-frozen, so the deploy carries no behavior change — state that you confirmed it.
3. After merge: delete the feature branch (local + remote).

## Guardrails
- API contract FROZEN — golden master green at every step; a red is a bug, stop.
- Never create Laravel migrations.
- Do NOT touch config('partna.social_platforms').
- Stay within Plan 2's scope; if a subagent drifts into Plan 3–6 work, stop it.
- Surface (don't silently absorb) any place the plan's code doesn't match current reality.
```

---

## Plan 3 — Embed & feed

### A · Author + independently review

```
Author and independently review the implementation plan for PLAN 3 — "Embed & feed" — of the
platform-integrations registry redesign. Do NOT execute it; produce a reviewed, ready-to-run plan.
This is the heaviest plan; if it exceeds ~12 tasks, split it into 3a (embed) and 3b (feed) and
say so explicitly.

PREREQUISITE: Plan 2 merged to development. `git fetch && git pull && git log --oneline -10`;
confirm GenericPlatformController + LinkPayload exist. If not, stop and report.

## Read first
- CLAUDE.md
- docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md
- docs/superpowers/plans/2026-06-27-platform-integrations-registry-spine.md AND the Plan-2 file
  (the GenericPlatformController + DTO pattern you extend)
- The ACTUAL current code below — match reality, do not fabricate.

## Ground-truth files to read before writing
- app/Services/Platforms/Strategies/Contracts/FetchStrategy.php + Strategies/Fetch/* + Refresh/*
- app/Services/Platforms/{YoutubeScraper,YoutubeApi,VimeoApi,BandcampScraper,OEmbedService,
  DeezerApi,AppleSearch,TwitchScraper,PinterestScraper,GoogleBusinessService}.php
- app/Http/Controllers/Api/Platforms/{Youtube,YoutubeMusic,Vimeo,Bandcamp,Apple,Spotify,
  Soundcloud,Deezer,Twitch,Pinterest,GoogleBusiness}Controller.php
- app/Http/Resources/Platforms/{Youtube,YoutubeMusic,Vimeo,Bandcamp,AppleMusic,ApplePodcast,
  MusicEmbed,Twitch,Pinterest,GoogleBusiness,Tile}ConnectionResource.php
- app/Services/Platforms/PlatformRefresher.php  (READ ONLY — extract the per-platform fetch
  shapes into FetchStrategy implementations; do NOT rewrite the refresher in this plan)
- tests/Feature/Platforms/{ScraperPlatformsConnectionTest,IntegrationsV3ConnectionTest,
  PlatformResourceContractTest}.php and the golden master

## Scope of THIS plan (spec §7 oEmbed + scraped/API-feed archetypes)
- BUILD: `EmbedPayload` (spotify, soundcloud, deezer, mixcloud, tidal) and `FeedPayload`
  (youtube, vimeo, bandcamp, pinterest, twitch, youtube-music, apple-music, apple-podcast,
  google-business). Adapt the existing scrapers/APIs to implement FetchStrategy (wrap, don't
  rewrite, their fetch logic — mirror the exact payload shape PlatformRefresher produces today).
  Attach the fetch strategy to each descriptor.
- MIGRATE: the selection/accounts read paths onto GenericPlatformController + the typed payloads.
  The bespoke picker steps (/recent, /highlights, apple dual music/podcast) STAY as thin
  controllers but consume the DTOs. Delete redundant controller code only after green.
- DO NOT: rewrite PlatformRefresher (Plan 6), touch picker/shop (Plan 4) or bespoke/specials
  (Plan 5), drop the CHECK. List as deferred.

## Author the plan (use superpowers:writing-plans)
- Save to docs/superpowers/plans/<YYYY-MM-DD today>-platform-integrations-embed-feed.md
  (or -embed.md / -feed.md if split).
- Mirror Plan 1's structure and the No-Placeholders / TDD / frozen-contract rules exactly.
- Each FetchStrategy adaptation task: a test asserting the strategy produces the SAME payload
  the current refresher path does for a representative input (mock the scraper), so the eventual
  Plan-6 refresher swap is provably behaviour-preserving.
- Run the writing-plans self-review.

## Then review it independently (fresh Opus reviewer subagent)
PASS / must-fix against the codebase:
  - Spec coverage of every embed + feed platform; correct archetype/DTO assignment for edge
    platforms (mixcloud, tidal, apple music vs podcast).
  - GROUNDED: every scraper/API method + resource + route cited exists with the cited signature.
  - Behaviour-preserving: fetch-strategy tests assert parity with the current refresher payloads.
  - Contract-freeze via golden master on every migrated read path.
  - Type consistency (FetchStrategy signatures, EmbedPayload/FeedPayload) across tasks.
  - Scope discipline (no Plan 4–6 work); deferred list present. No placeholders.
Fix inline + re-review until PASS.

## Output
Report finalized plan file path(s) and reviewer verdict. Do NOT execute.
```

### B · Implement (subagent-driven)

```
Execute PLAN 3 — "Embed & feed" — using subagent-driven development. Work autonomously; stop only
on a genuine blocker/ambiguity or a reviewer-flagged decision. If 3A split the work into 3a/3b,
run this implement step ONCE PER SUB-PLAN (3a fully shipped before 3b), using the matching plan
file and branch suffix.

PREREQUISITE: Plan 3 authored AND passed plan-review (3A); Plan 2 merged to development.

## Read first
- CLAUDE.md
- docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md
- The plan file: docs/superpowers/plans/*-platform-integrations-embed-feed.md (or -embed / -feed)

## Pre-flight
1. `git fetch && git pull && git log --oneline -10`.
2. Branch `feat/platform-integrations-embed-feed` (or -embed / -feed) off origin/development.
   WORK IN THE MAIN CHECKOUT — no git worktree (Pest feature tests break under .claude/worktrees/;
   worktrees poison the composer classmap).
3. `composer test` once → record BASELINE. `composer dump-autoload -o` if stale.
4. First commit: the plan file (if not already committed).

## Execution (use superpowers:subagent-driven-development)
For EACH task, in order:
  a. Fresh **Sonnet** implementation subagent scoped to exactly that task — TDD steps verbatim,
     exact code, run commands, `php artisan pint --dirty`, commit with the plan's message.
  b. Fresh INDEPENDENT **Opus** reviewer of that task's diff vs plan + spec: correctness, contract
     frozen (golden master green), fetch-strategy parity with the current refresher payloads, no
     scope creep, byte-identical migrated responses, Pint clean, existing patterns followed.
  c. PASS → next task automatically (NO human gate). Must-fix → Sonnet fix → Opus re-review.
     Escalate only for a real blocker/ambiguity.

## After all tasks
1. `composer test` — green, NO new failures vs baseline.
2. Final INDEPENDENT **Opus** review of the ENTIRE branch diff vs origin/development: spec
   adherence, contract provably frozen, fetch strategies behaviour-preserving, code quality,
   coverage, no leftover placeholders/dumps. Fix (Sonnet) → re-review (Opus) until PASS.

## Ship (only after the final review PASSES)
1. Supabase: Plan 3 adds NO migration — verify none under supabase/migrations/; no-op if so.
2. Git: `git fetch && git rebase origin/development` (expect 1-3 cycles), confirm `composer test`
   green, then merge/push to `development`.
   ⚠️ Pushing development deploys to BOTH dev-api.partna.au AND api.partna.au (prod sitepages).
   Announce before pushing; confirm golden master green first. Contract-frozen → no behavior change.
3. After merge: delete the feature branch (local + remote).

## Guardrails
- API contract FROZEN — golden master green every step; a red is a bug, stop.
- Never create Laravel migrations. Do NOT touch config('partna.social_platforms').
- Stay within Plan 3's scope (no Plan 4–6 work); stop any subagent that drifts.
- Surface (don't silently absorb) any plan/reality mismatch.
```

---

## Plan 4 — Picker & shop

### A · Author + independently review

```
Author and independently review the implementation plan for PLAN 4 — "Picker & shop" — of the
platform-integrations registry redesign. Do NOT execute it. If it exceeds ~12 tasks, split into
4a (pickers/reservations) and 4b (shop) and say so.

PREREQUISITE: Plan 3 merged to development. `git fetch && git pull && git log --oneline -10`;
confirm EmbedPayload/FeedPayload + adapted fetch strategies exist. If not, stop and report.

## Read first
- CLAUDE.md
- docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md
- The Plan-1, Plan-2, Plan-3 files (the established generic-controller + DTO patterns).
- The ACTUAL current code below — match reality, do not fabricate.

## Ground-truth files to read before writing
- app/Http/Controllers/Api/Platforms/{Fresha,Square,OpenTable,ResDiary,NowBookit,Shop}Controller.php
- app/Http/Resources/Platforms/{FreshaSelection,ShopBrand,OpenTable,ResDiary,NowBookit}ConnectionResource.php
- app/Services/Platforms/{ShopifyScraper,WooCommerceScraper,SquarespaceScraper,GenericShopScraper,
  NowBookitService,OpenTableService,ResDiaryService}.php and any Fresha service classes
- app/Http/Requests/Platforms/* (Fresha connect / service-visibility / shop requests)
- tests/Feature/Platforms/{SquareConnectionTest,OpenTableConnectionTest,PlatformResourceContractTest}.php
  (esp. the fresha + shop contract cases) and the golden master

## Scope of THIS plan (spec §7 picker + multi-brand archetypes)
- BUILD: `SelectionPayload` (Fresha's nested {url, selection{storeName, mode, employee, services,
  hiddenServiceIds}}, plus Square / OpenTable / ResDiary / NowBookit) and `ShopPayload` (the
  multi-brand map keyed by brand id, products passed through verbatim).
- MIGRATE: move FreshaController and ShopController payload ACCESS (the data_get/is_array sites)
  onto the typed DTOs while keeping their bespoke flows intact (Fresha team/services picker +
  service-visibility mutation; Shop brand/product CRUD). Collapse the keyless reservation
  platforms (OpenTable/ResDiary/NowBookit) onto GenericPlatformController + SelectionPayload.
- PRESERVE EXACTLY: Fresha's two-level {url, selection} envelope and storewide/employee modes;
  Shop's verbatim product passthrough and provider defaulting to 'shopify'.
- DO NOT: touch bespoke/specials (Plan 5) or the centralizer rewrite/CHECK drop (Plan 6).

## Author the plan (use superpowers:writing-plans)
- Save to docs/superpowers/plans/<YYYY-MM-DD today>-platform-integrations-picker-shop.md
- Mirror Plan 1's structure + rules. The Fresha + Shop tasks MUST lean on the existing
  PlatformResourceContractTest cases as the parity guard (responses byte-identical).
- Run the writing-plans self-review.

## Then review it independently (fresh Opus reviewer subagent)
PASS / must-fix against the codebase:
  - Spec coverage of all five pickers + shop.
  - GROUNDED: every controller method, resource, scraper/service, request cited exists.
  - Fresha nesting + service-visibility and Shop multi-brand passthrough preserved byte-for-byte
    (golden master + contract tests green).
  - Type consistency (SelectionPayload/ShopPayload) across tasks; scope discipline; no placeholders.
Fix inline + re-review until PASS.

## Output
Report finalized plan file path(s) and reviewer verdict. Do NOT execute.
```

### B · Implement (subagent-driven)

```
Execute PLAN 4 — "Picker & shop" — using subagent-driven development. Work autonomously; stop only
on a genuine blocker/ambiguity or a reviewer-flagged decision. If 4A split into 4a/4b, run this
step once per sub-plan with the matching plan file and branch suffix.

PREREQUISITE: Plan 4 authored AND passed plan-review (4A); Plan 3 merged to development.

## Read first
- CLAUDE.md
- docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md
- The plan file: docs/superpowers/plans/*-platform-integrations-picker-shop.md

## Pre-flight
1. `git fetch && git pull && git log --oneline -10`.
2. Branch `feat/platform-integrations-picker-shop` off origin/development. WORK IN THE MAIN
   CHECKOUT — no git worktree (feature tests break under .claude/worktrees/; classmap poisoning).
3. `composer test` once → record BASELINE. `composer dump-autoload -o` if stale.
4. First commit: the plan file (if not already committed).

## Execution (use superpowers:subagent-driven-development)
For EACH task, in order:
  a. Fresh **Sonnet** implementation subagent scoped to that one task — TDD steps verbatim, exact
     code, run commands, `php artisan pint --dirty`, commit with the plan's message.
  b. Fresh INDEPENDENT **Opus** reviewer of that task's diff vs plan + spec: correctness, contract
     frozen (golden master + the fresha/shop contract cases green), Fresha nesting + Shop product
     passthrough preserved byte-for-byte, no scope creep, Pint clean, existing patterns.
  c. PASS → next task automatically (NO human gate). Must-fix → Sonnet fix → Opus re-review.
     Escalate only for a real blocker/ambiguity.

## After all tasks
1. `composer test` — green, NO new failures vs baseline.
2. Final INDEPENDENT **Opus** review of the ENTIRE branch diff vs origin/development: spec
   adherence, contract provably frozen, the bespoke Fresha/Shop flows intact, code quality,
   coverage, no placeholders. Fix (Sonnet) → re-review (Opus) until PASS.

## Ship (only after the final review PASSES)
1. Supabase: Plan 4 adds NO migration — verify none under supabase/migrations/; no-op if so.
2. Git: `git fetch && git rebase origin/development` (expect 1-3 cycles), `composer test` green,
   then merge/push to `development`.
   ⚠️ Pushing development deploys to BOTH dev-api.partna.au AND api.partna.au (prod sitepages).
   Announce before pushing; confirm golden master green first. Contract-frozen → no behavior change.
3. After merge: delete the feature branch (local + remote).

## Guardrails
- API contract FROZEN — golden master green every step; a red is a bug, stop.
- Never create Laravel migrations. Do NOT touch config('partna.social_platforms').
- Stay within Plan 4's scope (no Plan 5–6 work); stop any subagent that drifts.
- Surface (don't silently absorb) any plan/reality mismatch.
```

---

## Plan 5 — Bespoke & specials

### A · Author + independently review

```
Author and independently review the implementation plan for PLAN 5 — "Bespoke & specials" — of
the platform-integrations registry redesign. Do NOT execute it.

PREREQUISITE: Plan 4 merged to development. `git fetch && git pull && git log --oneline -10`;
confirm SelectionPayload/ShopPayload exist and pickers/shop are migrated. If not, stop and report.

## Read first
- CLAUDE.md
- docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md
- The Plan-1..4 files.
- The ACTUAL current code below — match reality, do not fabricate.

## Ground-truth files to read before writing
- app/Http/Controllers/Api/Platforms/InstagramController.php
- app/Jobs/Platforms/{InstagramConnectJob,GoogleBusinessEnrichJob,MenuFetchJob}.php
- app/Services/Platforms/InstagramApifyBudget.php and app/Observers/IntegrationConnectionObserver.php
- app/Http/Resources/Platforms/InstagramConnectionResource.php
- app/Http/Controllers/Api/Platforms/{Events,Booking,Reservations,OnlineOrdering,Menu,
  GoogleBusiness,CustomLinks}Controller.php
- app/Services/Platforms/{EventsPayload,EventsCatalog,ProviderDetector,GoogleBusinessAutoSync,
  MenuApifyScraper,MenuMerger,MenuSource}.php
- tests/Feature/Platforms/{InstagramAsyncConnectTest,InstagramR2CleanupTest,EventsCatalogTest,
  IntegrationCategoriesTest,MenuTest,GoogleBusinessApifyTest,ReservationProvidersTest}.php
  and the golden master

## Scope of THIS plan (spec §7 specials + bespoke three)
- MIGRATE the genuinely-custom flows fully INTO the registry (descriptor identity + availableFor
  capability gate + typed payloads where applicable), keeping their bespoke controllers:
  Instagram (async Apify job + connect/status polling + R2 _folder cleanup observer — the _folder
  internal field MUST stay out of responses); the EventsController smart-detect facade +
  events-custom; custom links (if not done in Plan 2); GoogleBusiness auto-sync (which seeds OTHER
  platforms' rows — preserve that); the menu fetch; and the smart-detect CATEGORY pseudo-platforms
  (booking / reservations / online-ordering) via ProviderDetector.
- GOAL: after this plan, NO platform reads its payload via untyped data_get — everything is on a
  typed DTO and registered. State this as the plan's exit criterion + add a test asserting it.
- DO NOT: rewrite PlatformRefresher/ProviderDetector internals to registry-iteration yet, or drop
  the CHECK (Plan 6) — though ProviderDetector MAY be migrated to read registry categories here if
  cleaner; if so, keep its public contract identical and note the overlap with Plan 6.

## Author the plan (use superpowers:writing-plans)
- Save to docs/superpowers/plans/<YYYY-MM-DD today>-platform-integrations-bespoke-specials.md
- Mirror Plan 1's structure + rules. Instagram tasks must preserve the async 202→poll contract and
  the _folder-stripping behaviour exactly (lean on InstagramAsyncConnectTest + the golden master).
- Run the writing-plans self-review.

## Then review it independently (fresh Opus reviewer subagent)
PASS / must-fix against the codebase:
  - Spec coverage of Instagram, events facade, custom links, google-business auto-sync, menu, and
    the three category pseudo-platforms.
  - GROUNDED: every controller/job/service/observer/resource cited exists with cited signatures.
  - Instagram async + _folder cleanup + google-business cross-platform seeding preserved.
  - The "no untyped payload access remains" exit test is real and meaningful.
  - Scope discipline; type consistency; no placeholders.
Fix inline + re-review until PASS.

## Output
Report finalized plan file path and reviewer verdict. Do NOT execute.
```

### B · Implement (subagent-driven)

```
Execute PLAN 5 — "Bespoke & specials" — using subagent-driven development. Work autonomously; stop
only on a genuine blocker/ambiguity or a reviewer-flagged decision.

PREREQUISITE: Plan 5 authored AND passed plan-review (5A); Plan 4 merged to development.

## Read first
- CLAUDE.md
- docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md
- The plan file: docs/superpowers/plans/*-platform-integrations-bespoke-specials.md

## Pre-flight
1. `git fetch && git pull && git log --oneline -10`.
2. Branch `feat/platform-integrations-bespoke-specials` off origin/development. WORK IN THE MAIN
   CHECKOUT — no git worktree (feature tests break under .claude/worktrees/; classmap poisoning).
3. `composer test` once → record BASELINE. `composer dump-autoload -o` if stale.
4. First commit: the plan file (if not already committed).

## Execution (use superpowers:subagent-driven-development)
For EACH task, in order:
  a. Fresh **Sonnet** implementation subagent scoped to that one task — TDD steps verbatim, exact
     code, run commands, `php artisan pint --dirty`, commit with the plan's message.
  b. Fresh INDEPENDENT **Opus** reviewer of that task's diff vs plan + spec: correctness, contract
     frozen (golden master + InstagramAsyncConnectTest green), Instagram async/_folder + google-
     business cross-seeding preserved, no scope creep, Pint clean, existing patterns.
  c. PASS → next task automatically (NO human gate). Must-fix → Sonnet fix → Opus re-review.
     Escalate only for a real blocker/ambiguity.

## After all tasks
1. `composer test` — green, NO new failures vs baseline.
2. Final INDEPENDENT **Opus** review of the ENTIRE branch diff vs origin/development: spec
   adherence, contract provably frozen, the "no untyped payload access remains" exit test present
   and passing, code quality, coverage, no placeholders. Fix (Sonnet) → re-review (Opus) until PASS.

## Ship (only after the final review PASSES)
1. Supabase: Plan 5 adds NO migration — verify none under supabase/migrations/; no-op if so.
2. Git: `git fetch && git rebase origin/development` (expect 1-3 cycles), `composer test` green,
   then merge/push to `development`.
   ⚠️ Pushing development deploys to BOTH dev-api.partna.au AND api.partna.au (prod sitepages).
   Announce before pushing; confirm golden master green first. Contract-frozen → no behavior change.
3. After merge: delete the feature branch (local + remote).

## Guardrails
- API contract FROZEN — golden master green every step; a red is a bug, stop.
- Never create Laravel migrations. Do NOT touch config('partna.social_platforms').
- Stay within Plan 5's scope (no Plan 6 work); stop any subagent that drifts.
- Surface (don't silently absorb) any plan/reality mismatch.
```

---

## Plan 6 — Collapse & cutover (FINAL)

### A · Author + independently review

```
Author and independently review the implementation plan for PLAN 6 — "Collapse & cutover" — the
FINAL plan of the platform-integrations registry redesign. Do NOT execute it.

PREREQUISITE: Plan 5 merged to development; every platform is on a typed payload + registered.
`git fetch && git pull && git log --oneline -10`; confirm. If not, stop and report.

## Read first
- CLAUDE.md (esp. "no Laravel migrations", supabase push semantics, the SQLite-vs-Postgres caveat)
- docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md
- The Plan-1..5 files.
- The ACTUAL current code below — match reality, do not fabricate.

## Ground-truth files to read before writing
- app/Services/Platforms/PlatformRefresher.php  (the match() + per-platform payload methods +
  consecutive_failures / status-bucket semantics — these MUST be preserved exactly)
- app/Services/Platforms/ProviderDetector.php
- app/Http/Controllers/Api/Platforms/RefreshController.php
- app/Console/Commands/* (the refresh command — see RefreshPlatformConnectionsCommandTest)
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php (dead-path check)
- app/Rules/PlatformInRegistry.php (Plan 1) + the Form Requests that validate a `platform` value
- The latest CHECK constraint: `grep -rl "platform_connections" supabase/migrations/` then read the
  newest, and `grep -rh "platform IN (" supabase/migrations/ | tail -1`
- app/Services/Platforms/Registry/* and tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php

## Scope of THIS plan (spec §10 step 4 — collapse the centralizers + schema cutover)
- REWRITE PlatformRefresher: replace the match() with `foreach ($registry->refreshable() ...)`
  driving each descriptor's RefreshStrategy/FetchStrategy. Preserve EXACTLY the success path, the
  'ok'/'unavailable'/'error' status buckets, the atomic consecutive_failures increment, and the
  observer-purge behaviour. Guard with RefreshPlatformConnectionsCommandTest + the golden master.
- REWRITE ProviderDetector to read registry categories (if not already done in Plan 5); keep its
  public contract identical.
- WIRE PlatformInRegistry into the Form Requests that validate a platform value; point
  RefreshController at the registry instead of PlatformRefresher::REFRESHABLE.
- SCHEMA CUTOVER: one supabase/migrations/<ts>_drop_platform_connections_check.sql that DROPs the
  platform CHECK constraint (the registry is now the gate). Raw SQL only — never a Laravel migration.
- DELETE the now-dead per-platform controllers and any orphaned trait paths.
- This plan, when executed, is the only one whose ship step pushes a migration to Supabase dev
  (link → db push --dry-run → db push against glncumufgaqcmqhzwrxm) — note that in the plan's ship
  section; the authoring step just writes the .sql file.

## Author the plan (use superpowers:writing-plans)
- Save to docs/superpowers/plans/<YYYY-MM-DD today>-platform-integrations-collapse-cutover.md
- Mirror Plan 1's structure + rules. The refresher rewrite needs parity tests proving identical
  outcomes per status bucket BEFORE the old match() is deleted. The DROP CONSTRAINT task must note
  it is app-validated by PlatformInRegistry and that SQLite never enforced the CHECK (so the
  registry coverage test is the real guard).
- Run the writing-plans self-review.

## Then review it independently (fresh Opus reviewer subagent)
PASS / must-fix against the codebase:
  - The refresher rewrite preserves every status/failure/observer behaviour (parity tests present).
  - The DROP CONSTRAINT is raw SQL in supabase/migrations/, app-validation is wired, coverage test
    guards the gate.
  - No dangling references to deleted controllers; ProviderDetector contract unchanged.
  - GROUNDED in real signatures; scope discipline; type consistency; no placeholders.
Fix inline + re-review until PASS.

## Output
Report finalized plan file path and reviewer verdict. Do NOT execute.
```

### B · Implement (subagent-driven)

```
Execute PLAN 6 — "Collapse & cutover" — the FINAL plan — using subagent-driven development. Work
autonomously; stop only on a genuine blocker/ambiguity or a reviewer-flagged decision.

PREREQUISITE: Plan 6 authored AND passed plan-review (6A); Plan 5 merged to development.

## Read first
- CLAUDE.md (esp. supabase push semantics + "no Laravel migrations")
- docs/superpowers/specs/2026-06-26-platform-integrations-registry-redesign-design.md
- The plan file: docs/superpowers/plans/*-platform-integrations-collapse-cutover.md

## Pre-flight
1. `git fetch && git pull && git log --oneline -10`.
2. Branch `feat/platform-integrations-collapse-cutover` off origin/development. WORK IN THE MAIN
   CHECKOUT — no git worktree (feature tests break under .claude/worktrees/; classmap poisoning).
3. `composer test` once → record BASELINE. `composer dump-autoload -o` if stale.
4. First commit: the plan file (if not already committed).

## Execution (use superpowers:subagent-driven-development)
For EACH task, in order:
  a. Fresh **Sonnet** implementation subagent scoped to that one task — TDD steps verbatim, exact
     code, run commands, `php artisan pint --dirty`, commit with the plan's message.
  b. Fresh INDEPENDENT **Opus** reviewer of that task's diff vs plan + spec: correctness, the
     refresher rewrite preserves status/failure/observer behaviour (parity tests green), the DROP
     CONSTRAINT is raw SQL with app-validation wired, no dangling references to deleted controllers,
     golden master green, Pint clean.
  c. PASS → next task automatically (NO human gate). Must-fix → Sonnet fix → Opus re-review.
     Escalate only for a real blocker/ambiguity.

## After all tasks
1. `composer test` — green, NO new failures vs baseline.
2. Final INDEPENDENT **Opus** review of the ENTIRE branch diff vs origin/development: refresher
   parity proven, ProviderDetector contract unchanged, validation registry-driven, no dead code
   left, golden master + full suite green, no placeholders. Fix (Sonnet) → re-review (Opus) until PASS.

## Ship (only after the final review PASSES)
1. Supabase (THIS plan DOES add a migration): link to the DEV project ref glncumufgaqcmqhzwrxm,
   then `supabase db push --dry-run` (show the output), then `supabase db push`. This applies the
   DROP CONSTRAINT to the dev DB that serves BOTH api domains. Re-link is required if the CLI was
   pointed at another project.
2. Git: `git fetch && git rebase origin/development` (expect 1-3 cycles), confirm `composer test`
   green, then merge/push to `development`.
   ⚠️ Pushing development deploys to BOTH dev-api.partna.au AND api.partna.au (prod sitepages).
   Announce before pushing; confirm the golden master is green and the migration has been applied
   to Supabase dev FIRST (app reads the registry, but the dropped CHECK must already be gone so a
   newly-registered platform value isn't rejected by the old constraint).
3. After merge: delete the feature branch (local + remote).
4. Announce the redesign is COMPLETE: one pattern across all platforms, no match(), no CHECK,
   registry-driven validation, the API contract never moved.

## Guardrails
- API contract FROZEN — golden master green every step; a red is a bug, stop.
- The ONLY new migration allowed is the single DROP CONSTRAINT, as raw SQL in supabase/migrations/.
  No Laravel migrations.
- Do NOT touch config('partna.social_platforms').
- Surface (don't silently absorb) any plan/reality mismatch.
```
