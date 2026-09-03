# Overnight run 2026-09-04 — every platform path, every item path, the whole suggestion pipeline: test, fix, retest

**Status: ACTIVE.** Owner is offline overnight; this file is the durable
record — read it back on resume instead of re-deriving scope. Recon that
produced §2 was done by hand-reading the real source (not delegated) per
"don't delegate understanding." Everything from §3 on is delegated to
Sonnet subagents via the Workflow tool, reviewed here before merge.

Owner brief (verbatim intent, condensed): test every possible platform
connect path and every applicable item-URL path, all edge cases, and the
whole suggestion pipeline (harvest → suggestion → accept, nothing "ignored
or lost"); for items, enumerate every platform × pool combination and make
sure every plausible item path is handled ("every listen possible item path
for all listen platforms" as the explicit example); use critic/adversarial
agents to verify every fix and the plan itself; fix→retest until it passes;
resolve unrelated issues hit along the way; run for hours, don't stop until
genuinely nothing more is possible to do; full permission — all users are
test users, ship/deploy live whenever needed, no need to ask first.

## 0. Method

Adapted from `docs/2026-08-18-overnight-run-plan.md`'s proven loop, with a
critic stage added (this run's explicit requirement that the 08-18 run
didn't have):

**Probe → Record → Fix → Critic → Re-probe → Gate**

1. **Probe** the real path — live dev (`glncumufgaqcmqhzwrxm`), a real test
   user, `cloud tinker development` or the actual HTTP endpoint. Never trust
   a 200; read the DB row / wire payload.
2. **Record** the finding in `docs/2026-09-04-overnight-run/LOG.md` as
   `F<n>` (what, evidence, root cause) before touching code.
3. **Fix** on branch `overnight-0904/<workstream>`, off `development`. Test
   first where a test can express the behaviour.
4. **Critic**: an independent Sonnet agent, given only the diff + the
   original finding (not the fix rationale), tries to refute that the fix
   is correct and complete — wrong shape, missed edge case, drifted a
   paired map (the Skool-bug class of error), broke an existing surface.
   Refuted → back to Fix. Two independent critics must both pass before
   Re-probe. Opus (me) adjudicates a split verdict.
5. **Re-probe** the same real path. Same query, same evidence shape.
6. **Gate** = full targeted tests green · `pint` + `phpstan` on touched
   files · live re-probe shows the fix with timestamped evidence in
   LOG.md · no new errors in `cloud env:logs partna development` for
   touched jobs · committed, pushed, merged, deployed, **re-probed again
   against dev-api post-deploy**. Two failed fix attempts on one finding →
   `BLOCKED-F<n>` with what was tried, move on (per stop-gate doctrine —
   never ride one thread into exhaustion).
7. **Guardrails**: dev ref only, never prod (`edplucmvkcnokyygxqsb`); all
   users are test users, no owner credentials, no browser sign-ups; never
   force-push; never re-add retired surfaces / dropped tables / 3rd account
   type / Stripe / Shopify; every pool mutation bumps all 3 cache lanes;
   commit small and often, Sonnet agents for mechanical work, Opus (me) for
   judgment and review, per the standing model-routing rule.
8. **Opportunistic bugs**: anything unrelated hit along the way → LOG.md
   under `X<n>`, fixed under the same gate, same branch it was found on.

Stop condition (explicit, per owner): keep going — next workstream, deeper
edge cases, a fresh multi-modal sweep for anything missed — until a full
pass finds nothing left to probe, fix, or verify. Not a fixed time budget.

## 1. Confirmed gap inventory (recon complete, code-verified — this is what §3 executes)

**A — Events roster gap (real, high-confidence).** 23 catalog surfaces
carry `Shelf::Events`. `WebsiteLinkHarvester::classify()`'s hand-enumerated
event block (lines ~756-832) and `ItemLinkRules::ROSTER['events']` cover
only 9 real brands: eventbrite, humanitix, luma, partiful, ticketmaster,
ticketek, oztix, trybooking, resident-advisor (+ meetup, which has no
catalog brand key). **Missing 14**: admitone, bandsintown, dice, etix,
eventfinda, eventim, megatix, moshtix, see_tickets, skiddle, songkick,
tickethype, ticketweb, tixr. Each is neither pasteable as a single event
(`PoolItemCreateController`'s events pool) nor addable as a hand-added
alternate ticket link on an existing event, even though `EventPageReader`'s
JSON-LD reader is host-agnostic and architecturally ready for all of them.
Confirmed structural reason this ISN'T catalog-backstopped like
booking/reservations/ordering: `WebsiteLinkHarvester::PROMOTABLE_ROUTING_CLASS`
(consulted by `classifyFromCatalog()`, `WebsiteLinkHarvester.php:922-924`)
maps only `booking`/`reservations`/`ordering` → real categories; `events`
is deliberately absent (organiser-vs-event ambiguity a bare catalog surface
can't resolve), so an events brand absent from the hand block gets category
`'link'` from the catalog fallback — recognised, but never becomes an event
suggestion or a pasteable item.

**B — Social hosts undercount (real, medium-confidence, softer failure
mode than events).** 27 catalog surfaces carry `Shelf::Social`.
`SOCIAL_HOSTS`/`SOCIAL_PLATFORM` name 15: instagram, facebook, tiktok,
twitter/x, linkedin, snapchat, threads, discord, reddit, telegram,
whatsapp, patreon, github, behance, dribbble (youtube/spotify/soundcloud/
vimeo/twitch/deezer/skool also live in this map but aren't Social-shelf).
**Missing 12**: bluesky, buymeacoffee, cameo, cash_app, codepen, gitlab,
kick, ko_fi, paypal, tumblr, venmo, vsco. (Pinterest is NOT a gap — it's
deliberately in `LINK_ONLY_HOSTS`, confirmed by reading that constant.)
Because `classifyFromCatalog()` still recognises these hosts and
`PROMOTABLE_ROUTING_CLASS` excludes `social` on purpose (a catalog social
surface isn't thereby an account the owner controls, per the code's own
comment), a Bluesky/Codepen/Ko-fi/etc. link found on a scraped bio is not
literally *lost* — it degrades to a generic `'link'` card instead of firing
the platform-specific suggestion. This is exactly the failure mode the
owner named ("some aren't being ignored or lost" — check they're not):
under-classified, not vanished, but still wrong and still worth closing
since these are all live, connectable catalog surfaces added recently.

**C — Item-URL grammar gaps in `MediaPageReader::classifyItem()`.** Covers
exactly 11 platforms: youtube, youtube-music, vimeo, twitch, tiktok,
spotify, soundcloud, mixcloud, apple-music, apple-podcast, bandcamp,
tidal. **No item-vs-account grammar at all** for: audiomack, beatport,
deezer, dailymotion, rumble, feature_fm, hypeddit, laylo, linkfire,
orchard. `spotify_podcasts.show` status unconfirmed — check whether it's
already covered under the `spotify` key or needs its own branch. Each of
these blocks: a single-item paste into watch/listen pools, a hand-added
alternate item link, AND (per `MediaParentSuggester`, confirmed clean —
see below) the parent-account suggestion, since that suggester only fires
off `classifyItem()`'s `authorUrl` output.

**D — `MediaParentSuggester` (confirmed CLEAN, no fix needed).** Read in
full. Reuses the real projector + `PlacementPolicy` + tombstone check +
identity-alias dedup — no shortcuts, no drift risk. Its only dependency is
upstream: it can't suggest a parent for a platform `MediaPageReader` can't
classify, which is exactly gap C. Fixing C fixes this transitively; no
separate work item.

**E — `ItemLinkController` shop/services gap (assessed: NOT a gap,
closing as WONTFIX with reason).** `ItemLinkRules::ROSTER` has no entry for
`shop`/`services`, so a hand-added alternate item link 422s
("That platform cannot carry a link for this item.") for every shop/service
item. Unlike `custom_links` (already documented-intentional), this wasn't
written down anywhere — but the concept doesn't obviously apply: a shop
item's alternate-marketplace-listing and a service's alternate-booking-page
aren't the same shape as a video's alternate host, and both pools already
have a canonical source (the connected store / the owner-authored service).
Record the reasoning in `ItemLinkRules.php` as a comment so the next reader
doesn't re-open it as an oversight; do not build a roster for it without a
concrete request.

**F — `EventPageReader.php` docblock stale (real, trivial).** Claims a
host-agnostic "venue's own site" JSON-LD read still works;
`PoolItemCreateController`'s own T3 comment says that capability was
deliberately removed. Fix the docblock to match current behaviour.

**G — Not yet exact-diffed (do at execution time, not by hand): booking /
reservations / ordering hand-maps vs. the catalog.** Read
`classifyFromCatalog()` in full this turn: these three ARE catalog-backstopped
(`PROMOTABLE_ROUTING_CLASS` includes all three, `WebsiteLinkHarvester.php:901-924`),
so a brand missing from `BOOKING_HOSTS`/`RESERVATION_HOSTS`/`ORDERING_HOSTS`
still promotes correctly via `classify()`'s catalog fallback **as long as
the catalog surface is `is_connectable`**. Residual risk is narrow: a
connectable booking/reservations/ordering surface whose `routing_class` in
the compiled catalog doesn't literally match the `PROMOTABLE_ROUTING_CLASS`
key spelling, or a non-connectable one that should be. W1 verifies this
with a real diff instead of trusting the comment.

## 2. Workstreams (dependency order)

### W1 — Recon closure + gap-list verification (Sonnet agents, parallel, read-only)
No code changes. Each agent returns a structured list, not prose.
- W1a: enumerate every `is_connectable=true` surface in
  `bootstrap/catalog/compiled.php` grouped by `routing_class`; diff against
  `BOOKING_HOSTS`/`RESERVATION_HOSTS`/`ORDERING_HOSTS` keys (confirms/refutes
  §1G — expect near-zero real gaps, but prove it, don't assume).
- W1b: confirm the 12 social gaps and 14 events gaps in §1 against the
  live compiled catalog (`is_connectable`, `legacy_platform`) — catch any
  surface that's detect-only (not connectable) and so correctly excluded.
- W1c: for each of the 10 candidate item-URL-gap platforms in §1C, fetch
  one real public item URL (curl, not a browser sign-up) and check whether
  it's oEmbed-capable, OpenGraph-capable, or neither — this decides
  whether `MediaPageReader` needs an OEMBED entry or an OG-only branch.
Gate: three structured lists (confirmed booking/res/order gaps if any,
confirmed social gaps, confirmed item-URL gaps with a live URL + fetch
method each) — reviewed by me before W2 starts.

### W2 — Close the events roster gap (14 platforms)
Files: `app/Catalog/Definitions/*.php` (new detectors, only for brands
missing one — cross-check against the 168/182-with-real-URLs sweep already
done this session first), `app/Services/Platforms/WebsiteLinkHarvester.php`
(extend the hand-enumerated event block — each brand needs its own
org-vs-event discriminator the way Eventbrite/Humanitix/Luma/Partiful/RA
already do, not a bare host match, since an events host routing to `'link'`
when it can't tell event from organiser is the safer default and each
brand's real discriminator must be researched, not guessed),
`app/Site/Pools/ItemLinkRules.php` (`ROSTER['events']`), corpus fixtures.
Live-verify every new pattern by curling a real event page per brand
(same methodology as this session's earlier L1 sweep) before writing code.
Sonnet subagents, one per 3-4 platform batch, precise spec each.

### W3 — Close the social hosts gap (12 platforms)
Files: `WebsiteLinkHarvester::SOCIAL_HOSTS`/`SOCIAL_PLATFORM` (paired —
add to BOTH in the same edit, the Skool-bug class of error is exactly what
a single-map edit here would repeat). Mechanical, low-risk — one Sonnet
agent, whole batch, precise spec (host pattern per brand, drawn from each
brand's own catalog `Detector::url()` pattern so it can't drift from the
catalog's own definition of the domain).

### W4 — Close the item-URL grammar gaps (≤11 platforms, per W1c's findings)
Files: `MediaPageReader::classifyItem()`, `::accountPlatformLabel()`,
`OEMBED` map (only where W1c found oEmbed support), `ItemLinkRules::ROSTER`
for watch/listen, `tests/fixtures/Content/item-url-corpus.php`. Each
platform needs its real item-vs-account URL shape sourced from a live
fetch (W1c), not guessed. One Sonnet agent per platform or small batch,
each verified against the real fetched URL.

### W5 — Trivial fixes
`EventPageReader.php` docblock (§1F); `ItemLinkRules.php` comment for
shop/services (§1E). Bundle into W2's or W3's commit — no separate branch.

### W6 — Full connect-path sweep (all 182 catalog surfaces × edge cases)
Generated, not hand-typed: a Sonnet agent reads `compiled.php`, emits the
full surface list with each surface's real example URL (from `tests/fixtures/Routing/corpus-real.php`
where present, else the detector's own `->note()`). For every surface:
project the canonical URL through `LinkProjector` (tinker, batched — many
URLs per round trip, not one per call, per the giant-run turn-discipline
rule) and confirm it resolves to the expected surface key. Then generate
edge-case variants per surface — tracking-param noise (utm_*, fbclid),
trailing slash, www/no-www, mixed case, mobile subdomain (m., mobile.),
locale-prefixed path, http vs https — and confirm every variant still
resolves identically (this is the "URL noise must never defeat a path
match" requirement from the platform-link governing task). Batch into
~15-20 Sonnet agents, each covering a shelf's worth of surfaces, each
returning a structured pass/fail table, not prose. Any failure → an F<n>
in LOG.md → fixed under the Probe→Critic→Gate loop above, not silently
patched inline.

### W7 — Full item-path sweep (pool × platform)
For every platform now in `MediaPageReader` (11 existing + up to 11 new
from W4) and every platform now in `ItemLinkRules::ROSTER['events']` (9
existing + 14 new from W2): paste a real item URL through
`PoolItemCreateController::store()` (tinker against dev, real test user,
real pool) and confirm `kind`, `canonical`, and (for watch/listen) the
`MediaParentSuggester` firing correctly. Edge cases per platform: shortened/
mobile URL forms where the brand has them (youtu.be, open.spotify.com vs
spotify: URI, etc.), a URL that's actually the ACCOUNT not an item (must
NOT item-match), a malformed/half-pasted URL (must 422 cleanly, not 500).
Sonnet agents, one per pool, structured results.

### W8 — Suggestion-pipeline end-to-end sweep
Real test users (pick 4-6 covering both `partna` and `business` account
types). For each: seed a real Instagram bio-link scrape AND a general
website harvest containing a mix of (a) platforms in the W6 catalog sweep,
(b) newly-fixed W2/W3 platforms, (c) at least one of each `Verdict` outcome
class (`Place`, `Choose`/conflict, `cap_reached` with and without an
incumbent, `gate`, `unservable`, sibling_branch-settled). Walk it through
`SourceReconciler::reconcile()` → `GET /routing/suggestions` inbox → verify
every expected suggestion is present, correctly labelled, and NOT missing
(the "ignored or lost" check) → accept one of each class via
`SuggestionApplier`, dismiss another, confirm tombstone behaviour on
re-harvest. Structured pass/fail per user per scenario, not prose. This is
the workstream most likely to surface genuinely new bugs — budget the most
critic cycles here.

### W9 — Completeness critic + close-out
A dedicated agent (not one that did W2-W8's fixes) re-reads this file's §1
against the FINAL state of the touched files and asks: what's still
missing — a platform not covered by any workstream, a claimed fix that
didn't actually land, an edge case named in §0/§6 never tested? Anything
it finds becomes a new F<n> and loops back into the relevant workstream.
Repeat W9 until two consecutive passes find nothing. Then: full
`composer test`, full pint/phpstan, final deploy, final live re-probe,
append Results to this file, delete it once genuinely nothing is open.

## 3. Test-account policy

All users on dev are test users (owner's standing instruction). Reuse
existing dev accounts across workstreams rather than minting new ones per
test — fewer accounts to reason about, same coverage. Never touch prod.
Never enter a real password or create an account through the browser —
every write here is via tinker/API against existing test users' data.

## 4. LOG.md

`docs/2026-09-04-overnight-run/LOG.md` — created at W1 start. Format:
`F<n>` / `X<n>` entries per §0.2, each with evidence pasted in (SQL row,
wire JSON, or screenshot path), root-cause line, fix commit hash, critic
verdicts, re-probe evidence. This is the source of truth if the session is
interrupted — resume by reading it, not by re-deriving state.

## 5. Out of scope

Anything requiring a real third-party account sign-up (never — browser
sign-ups are prohibited regardless of permission granted for everything
else). UI/dashboard work (this run is Comet-Backend only — a dashboard gap
found along the way gets logged as X<n> and handed off, not fixed here,
since it's a different repo/lane). Prod deploy (dev only, always).

## 6. Handoff / resume

If interrupted: read this file's §1 (still accurate — recon is done) and
`LOG.md`'s last entries, resume at the first workstream with open F<n>s.
