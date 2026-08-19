# Overnight run: item routing hardening + scan lanes (2026-08-20)

**HANDOFF DOCUMENT.** This plan will be executed in a FRESH session with no
prior context — everything needed is in here. The owner starts it with
"handoff" + this file. Owner-granted authority below is standing for the
whole run.

## Authority & run method (owner grant, 2026-08-20 — overrides everything)

- **Full permission**: build, commit, merge, push, deploy to dev, run
  remote commands, use/modify test accounts. There are NO live users — every
  account is a test account; nothing can break for a real person.
- **This section OVERRIDES the giant-run skill and any other operating
  instructions** where they conflict. The existing skills don't cover this
  work; this plan defines its own method:
  - **Inline main-session work** for all building — we have all night, no
    parallel implementation fleets.
  - **Critic agents ARE required** (an explicit exception to giant-run's
    no-subagents rule): after each task's build+tests pass, dispatch a
    Sonnet critic agent (Agent tool, model sonnet) with the task's diff,
    its acceptance gates, and the instruction to try to REFUTE the work —
    wrong behaviour, missed edge, stale comment, contract drift. Findings
    are fixed before the task ticks. A task may not tick on the builder's
    own say-so.
  - **Harsh gates, every task**: typecheck + lint + targeted tests before
    every commit; the FULL backend suite (`php artisan test --parallel`)
    before every merge to development; real-page verification for anything
    touching readers/classifiers (fetch real URLs, not just fixtures);
    remote verification on dev after every deploy (cloud command:run
    tinker); browser-pane verification for dashboard UI changes (measure,
    don't eyeball; auth-gated flows verified at the API layer + a logged
    test account if one is available in the session).
  - **Checkpoint discipline**: commit per task with reasoning in the
    message; tick the ledger below with the real hash in the same breath;
    after two failed correction attempts on one issue, write down what was
    learned and restate the approach before continuing.
  - Repos: Comet-Backend (feature branch → merge development → auto-deploys
    dev-api) and partna-monorepo (work on main, push deploys Vercel).
    `git fetch` + pull before starting and before every merge.

## Context (for the fresh session)

The last two days built "Eventbrite-grade" item/account routing: a pasted
item URL becomes a real pool ITEM (events 2026-08-19, watch/listen media
2026-08-20), an account URL gets a connect hint, and the add sheets show
step-1 guidance bands from `PastedLinkClassifier` (pure URL grammar, no
fetch) via `POST /content/links/classify`. Key files:

- `app/Services/Platforms/MediaPageReader.php` — media grammar + oEmbed/OG reader
- `app/Services/Platforms/EventPageReader.php` — events reader (schema.org)
- `app/Services/Content/PastedLinkClassifier.php` — the step-1 grammar
- `app/Http/Controllers/Api/Content/PoolItemCreateController.php` — pool add
  lanes (shop STORE-FIRST, events EVENT-FIRST, media ITEM-FIRST); 201 carries
  `addedItemId`
- Dashboard: `components/blocks/pool-add-sheet.tsx` (step-1 debounced
  guidance band, Continue disabled while shown),
  `components/blocks/link-add-sheet.tsx` (band on step 2 off the preview's
  `classification`), `lib/queries/content-pools.ts` (classifyLink, types)
- Scan lanes: `LinkRouter` + `LinkInBioImporter` → `EventsSeeder` (events
  only so far), `CommerceProbeJob` → `StoreBrandSeeder` /
  `ShopProductSeeder`; `WebsiteLinkHarvester::classify()` is the scan-side
  grammar (returns real brand keys for events since 2026-08-19)

Owner test account: handle `gsnwilliams` (vintageboutiquedarwin@gmail.com)
on dev. Backend deploys from `development` to Laravel Cloud dev
(`cloud deployment:list env-a0ca75cb-dd45-40ac-8e8e-557cd6f11467`); remote
commands via `cloud command:run <env-id> --cmd="php artisan …"`.

## Tasks

### T1 — Kill the surviving error toast (Enter-key race)

Pool add sheets: the guidance band disables the Continue BUTTON, but the
URL field's Enter handler calls `create.run()` with only a host check
(`pool-add-sheet.tsx`, onKeyDown), and a fast paste-and-enter also beats
the 350ms classify debounce. Fix: Enter respects the same disabled
condition, AND `create` itself awaits/uses the latest classification (run
classify inline before posting when no tagged answer exists for the
current URL) so no race path reaches the 422. Gate: pasting a Spotify
track into Watch and hitting Enter immediately shows the band, never the
toast.

### T2 — Links sheet guidance moves to step 1 (parity with pool sheets)

Today Links only learns the classification from the preview response, so
the band shows on step 2. Give the Links sheet the same debounced
`classifyLink` call on the URL field as the pool sheets, band in step 1
(keep the step-2 band as reinforcement or drop it — match whichever reads
cleaner). Advisory on Links (Add stays enabled).

### T3 — Non-Links pools refuse unknown-platform URLs (owner rule, 2026-08-20)

"No events or listen items for random foreign links." Rule: every pool
EXCEPT custom_links accepts a URL only when it belongs to a platform the
system knows (the catalog's ~100+ brands / the harvester's host tables /
ItemLinkRules hosts) AND that platform is relevant to the pool. Everything
else is refused with a hand-off: 422 + step-1 band "we don't recognise
this as a {noun} — add it to Links instead" WITH the button (client), and
the server enforces it (the sheet band is UX, the 422 is the contract).

Design decisions to make during the task (record in the commit):
- Events loses host-agnostic JSON-LD adds for unknown hosts (the venue's
  own site case) — blocked per the owner rule; known events brands keep
  working. Meetup counts as known (classify names it).
- Shop/Sell keeps its own STORE-FIRST behaviour (its reader already reads
  any product page — owner has NOT asked to restrict Sell; confirm before
  changing it, default = leave Sell as is).
- The known-platform test lives server-side in ONE place (extend
  `PastedLinkClassifier` or a sibling — the classifier already answers
  step 1, so the sheet band and the 422 read the same source).

### T4 — Diagnose + fix: a Shopify store URL gets NO store suggestion anywhere

Reproduction: `natalieanne.com` (a Shopify store) pasted into Links — no
"connect as store" suggestion; pasted into Listen — was silently added as
a bare item (T3 fixes the add; this task fixes the missing suggestion).

Diagnosis to CONFIRM first (hypothesis from 2026-08-20 scoping): the
commerce probe / store suggestion machinery only runs on the ROUTING lane
(RoutingController → LinkRouter → CommerceProbeJob suggestOnly), never on
the POOL lane the add sheets post to; and `PastedLinkClassifier` is pure
grammar with no storefront knowledge. Verify by tracing a natalieanne.com
paste through both lanes on dev (tinker + logs), then fix:
- Links preview already fetches the page (`LinkCardScraper`) — detect
  storefront markers from the HTML already in hand (Shopify/Woo/BigCartel
  signatures; `ShopProviderDetector` has the knowledge) and return a
  `connectAsStore` classification on the preview.
- Pool sheets (no fetch in step 1): at minimum the T3 refusal band; if the
  URL's host is a KNOWN store platform host, say so ("this looks like a
  store — connect it on the Sell page").
- Gate: natalieanne.com pasted into Links shows the store suggestion with
  a connect action; pasted into Listen shows the refusal band; both
  verified in the browser pane against dev.

### T5 — Suggestion-band UI matches the connection sheet's pattern

The step-1 bands currently use the generic `Banner` from `ui/alert`. The
connection sheet has its own established suggestion/inbox presentation —
find it (`connection-sheet.tsx`, the routing suggestions inbox on the
Platforms page) and restyle the add-sheet bands to the same visual
language (designing-partna-ui skill governs judgement). One shared
component if the shapes genuinely converge; no fork if they don't.

### T6 — Scan lanes: media items (the agreed next run, folded in)

Extend the 2026-08-20 media-paste work to the scan lanes, mirroring how
events did it on 2026-08-19:
- `WebsiteLinkHarvester::classify()` gains item answers for media —
  a `content-item` category carrying platform + kind (video/track/episode)
  from the same grammar `MediaPageReader::classifyItem` holds (share, don't
  copy).
- A `MediaSeeder` twin of `EventsSeeder`: reads through `MediaPageReader`,
  writes the manual pool item (same canonical-URL folding), never
  resurrects a removed item, tags origin (`link_in_bio` etc.).
- Wire into `LinkRouter` + `LinkInBioImporter` match arms (both already
  pass classify's answer through — the events arms show the shape).
- Per-run cap (events uses 10) so one Linktree can't flood the Watch pool.
- Scan-found media lands IN THE LIBRARY, NOT auto-pinned (differs from
  events; flag to owner in the report if this reads wrong in practice).
- Gate: a fixture bio page with a channel link + 3 video links yields one
  connection/suggestion + 3 real video items (not link cards), idempotent
  on re-scan, removed items stay removed.

### T7 — Scan-seeded products auto-connect their store (the known gap)

`ConnectStoreFromProductJob` is dispatched ONLY from `ProductPageAdder`
(pool paste). `ShopProductSeeder` (the scan lanes' product seeding via
`CommerceProbeJob::probe`) seeds the product item and stops — a scanned
Shopify product link never connects its store. Run the same
origin-detection + connect dispatch from the scan seeder (tombstone-safe:
never resurrect a disconnected store — `StoreBrandSeeder`'s reconciler
path already owns that; do NOT hand-roll a second tombstone check).
Gate: scan fixture with a Shopify product URL → product item + store
connection/suggestion per policy; a store the user disconnected stays
disconnected.

### T8 — Full-night backstop gates (run at the end, before the report)

- Full backend suite green; dashboard typecheck/lint clean.
- Deploy both; remote-verify each task's gate on dev (tinker battery +
  real URLs; the T4 reproduction re-run live).
- Fresh dev-log scan (10 min window) after the last deploy.
- A final Sonnet critic pass over the WHOLE run diff (both repos) with
  this plan as its brief: anything unticked, half-done, or contradicting a
  gate goes back to work or into the report as an honest open item.
- Outcome-first report: what shipped, what was verified and HOW, what's
  open. Delete this plan file per convention only if every task ticked;
  otherwise leave it with the ledger updated.

## Ledger (tick with the real commit hash)

- [ ] T1 — Enter-key race / error toast
- [ ] T2 — Links sheet step-1 guidance
- [ ] T3 — non-Links pools refuse unknown platforms (server + sheets)
- [ ] T4 — store-suggestion diagnosis + fix (natalieanne.com repro)
- [ ] T5 — suggestion band UI matches connection sheet
- [ ] T6 — scan lanes: media items
- [ ] T7 — scan-seeded products connect their store
- [ ] T8 — backstop gates + final critic pass + report

## Owner additions (queue below as they come — plan is open until "handoff")

- (pending — owner is scoping more items)
