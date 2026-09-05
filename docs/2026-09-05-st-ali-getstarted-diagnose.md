# St Ali test signup — Get Started diagnose (2026-09-05)

Test account: `vintageboutiquedarwin@gmail.com`, handle `stali`, user id
`01a06f34-8fa9-73fb-9385-4319cc98625a`, build id
`01a06f34-819c-733b-85ea-545271b5d4fa`. Signed up 01:35:07 UTC, ran BEFORE
the `feat/setup-accept-lanes` fixes were live. Diagnose only — nothing
fixed. Account deleted after this was recorded.

## Build timeline (core.pre_account_builds / …_events)

- `created_at` 01:35:07, `content_filled_at`/`enriched_at` 01:35:14 (7s —
  Google listing pulled fast), `settled_at` 01:40:05 (**~5 min later**).
- Events: `identity landed` 01:35:11 ("Found your Google listing"),
  `listing started` 01:35:13, `website started` 01:35:13 — **no further
  events after that**. The `website` stage never emitted a `landed` (or
  failed) event at all.
- Platform connections/intents show two real waves: an initial batch
  ~01:35:29–01:35:40, then a second wave at 01:41:46–01:41:56 — **after**
  `settled_at`. Shopify's intent specifically: `first_seen_at` 01:36:08,
  `updated_at` 01:41:46.

This matches issue 1 and 3 exactly: the Get Started platforms step loads
against whatever has landed so far, and real enrichment (Shopify probe,
some social intents) keeps landing for several minutes after the page
first opens — hence "empty, then a refresh 10s later shows platforms" and
"another refresh later surfaced Shopify".

## 1. Platforms step empty on first load

Confirmed real, not a fluke — `settled_at` (01:40:05) is ~5 minutes after
signup, and Shopify/some socials only resolved at 01:41:46, minutes after
that. A fixed 10-second loader won't cover this reliably; the delay varies
per account (Shopify's commerce probe alone can take minutes).

Proposed alternative to a timed loader: poll the setup wire's `progress`/
`passes[].ready` (already on `SetupPayloadWire`) and keep the loader up
until the platforms pass reports ready, with a generous cap (e.g. 15–20s)
after which it shows whatever has landed so far rather than blocking
indefinitely — since some probes (Shopify) can run minutes past first
paint. This also implies the platforms pass should be able to "trickle in"
newly-applied intents after the loader clears, not just after a manual
refresh (see issue 3 — this is the same root cause).

## 2. Duplicate Instagram card, one right one wrong (root cause found)

`site.platform_connections` has two Instagram rows for this user:

| resource_id | source | picture | status |
|---|---|---|---|
| `instagram` | `google-business` | present | **soft-deleted at 01:41:46** |
| `st_ali` | `suggestion` | none | surviving |

The old row was written by the legacy Google-Business auto-sync path,
which keys `resource_id` by the bare platform name (`'instagram'`). The
newer suggestion-accept path keys `resource_id` by the real handle
(`'st_ali'`). Because the two lanes use different identity keys for the
same platform, the existing-connection lookup doesn't recognize the old
row as the same account: it writes a duplicate and (separately) tombstones
the old one — losing the profile picture in the process. Both the "two
cards for one account" (issue 2) and "the surviving card has no picture"
(issue 5) are this same bug, observed at two points in time.

Fix direction (not applied): reconcile connection lookups across both
naming schemes for a surface, or migrate the legacy writer to key by
identifier like the newer lane does.

## 3. Shopify surfaced only after a later refresh

Explained by the timeline above — the commerce probe resolves
asynchronously and can take minutes. Not a bug by itself; the surfacing
mechanism (issue 1) is the actual gap.

## 4. Uber Eats link never appeared

Searched `content.items`/`content.f_link` for any `uber`/`ubereats` URL for
this user — **zero rows**. Also zero `ubereats.*` entries in
`routing.source_intents` (7 intents total: x, facebook, youtube, tiktok,
instagram, opentable, shopify — no Uber Eats). The Google Business
enrichment never produced this link at all — it wasn't scraped, so it
wasn't classified or dropped, it's simply absent upstream. This reads as a
gap in what the Google Business enrichment pulls (or Google's own listing
didn't surface it to us), not a routing/suggestion bug — would need a look
at the Google Business enrichment job's raw fetch to say more definitively.

## 5. Second visit: single Instagram card missing picture, stuck media, a Facebook item

- Missing picture: same root cause as issue 2 (the surviving `st_ali` row
  never had a picture).
- Facebook item present: `content.items` shows ~11 Facebook Reel-sourced
  media rows landed at 01:35:33–34, alongside genuine Instagram post items
  with proper `instagram.com/p/...` URLs. So a Facebook item legitimately
  appearing in the media pool is expected content, not a bug — but worth a
  UI check that each card is clearly platform-marked so it doesn't read as
  a misfiled Instagram item.
- Stuck-loading media: in the full `content.items` dump for this user, a
  batch of `media`-kind rows (10 of them, first_seen_at 01:35:52–01:36:10,
  staggered ~2s apart) carry `url: null` but `has_media: true` — no
  outbound link, only a mirrored image, consistent with individually-mirrored
  Google Business photos rather than Instagram Reels specifically. I could
  not isolate any Instagram-platform video/media row that looks stuck
  pending in what this query returns — this needs a follow-up read of the
  frontend's actual pending/loading state (likely `item.pending` from a
  still-mirroring cover asset) rather than DB state alone, since the DB
  side doesn't show an obvious stuck job.

## 6. No logo despite a prior successful test (confirmed real gap)

`site.logo_candidates` has **zero rows** for this site
(`01a06f34-8fdc-717e-a401-58d134dcd2f8`). This lines up with the build
event timeline: the `website` stage started at 01:35:13 and never emitted
a completion (or failure) event — it's the stage that would scrape the
user's own site for a logo/favicon, and it appears to have silently
stalled or failed without logging that fact. Since Google Business alone
doesn't supply a logo, no candidates ever got generated. Needs the
`website` stage's job/log inspected directly (not done here — diagnose
only) to see whether it errored, timed out, or simply has no site to crawl
for this particular test business.

## Additional issues noticed (not user-reported)

- The `website` build stage has no terminal event (no `landed`/`failed`)
  at all for this build — worth checking whether that stage is expected to
  always emit one; if so, this is itself a bug independent of the logo
  outcome, and would affect anything else that stage is responsible for.
- The legacy-vs-suggestion `resource_id` identity mismatch (root cause of
  issue 2/5) is not Instagram-specific — any surface where the legacy
  Google-Business auto-sync writer and the newer suggestion-applier both
  write connections is exposed to the same duplicate/tombstone bug.

## Account cleanup

`vintageboutiquedarwin@gmail.com` (user id
`01a06f34-8fa9-73fb-9385-4319cc98625a`) purged after this was recorded —
see git history / deletion service run for confirmation.

## Update, later the same day: owner re-tested and fixed

Owner did two fresh manual signups — `tobiasindarwin@gmail.com`
(`stalicoffeeroasters1`, business) and `vintageboutiquedarwin@gmail.com`
(`squeakprobarber`, partna, second account of that name) — and reported the
duplicate Instagram, a nameless Uber Eats card, squeakprobarber's workplace
never appearing, and two extra Booksy links. Re-diagnosed live against these
accounts (not the harness) and shipped four fixes, commit `249073014`
(merged/pushed as `9f056cb98d` on `development`, deployed
`depl-a2abc0b0`):

1. **Duplicate Instagram card (issue 2/5 above, confirmed still live and
   platform-agnostic as predicted)** — root-caused precisely:
   `SetupPayload::suggestionRows()`'s connection-loop skipped a connection
   already claimed by an intent by re-deriving `surface_key|identifier` and
   comparing it against each connection's `surface_key|resource_id` — but
   the legacy Google-Business writer keys its connection by the bare
   platform name (`resource_id='instagram'`) while the newer
   suggestion-applier lane links the intent's own `connection_id` to a
   connection keyed by the real handle (`resource_id='st_ali'`). Both are
   the same account and the FK already says so, but the two string forms
   never match, so the legacy row slipped through as a second card. Fixed
   by tracking covered connections by the ID each intent actually resolved
   to (preferring `connection_id`, falling back to the identifier match),
   not re-derived strings. Verified live post-deploy: 1 Instagram row
   (was 2).
2. **Uber Eats card showing no store name** — a different manifestation of
   a naming bug, not the identity mismatch above: the Uber Eats sync
   writes `payload.name = "UberEats"` (the brand, compact form).
   `ConnectionDisplayName::isBrandLabel()` compared it against the
   catalog's spaced label ("Uber Eats") by exact string equality, missed
   the match, and treated the brand placeholder as a genuine custom name —
   short-circuiting the store-name-from-url fallback that would have read
   "St Ali" off `ubereats.com/au/store/st-ali/...`. Fixed by comparing
   squashed (case- and separator-insensitive) forms. Verified live
   post-deploy: accountName "St Ali" (was "UberEats").
3. **squeakprobarber's workplace pass never showing anything on load** —
   `SetupPassRegistry::READY_STAGES` mapped the listing pass's readiness to
   a stage literally named `'listing'` — a real stage, but the wrong one
   (`GoogleBusinessEnrichJob` enriching an already-connected listing, not
   searching for one). The stage that actually gates "have we finished
   searching for your workplace" is `STAGE_WORKPLACE`
   (`InstagramSourceGenerator`/`BioMentionChainsJob`'s bio-mention search
   feeding `site.workplace_candidates`). The pass reported `ready:true`
   from the very first poll, before that search had run, hiding its
   loading skeleton and letting the payload's overall `busy` flag go false
   early — stopping the dashboard's 3s poll before a match could ever
   reach the screen. Fixed the mapping.
4. **squeakprobarber's two extra Booksy links** — Booksy's catalog surface
   only detected the numeric-id directory path
   (`booksy.com/en-us/{id}_...`); the business's own tenant subdomain
   (`squeakprobarber.booksy.com`) and Booksy's "powered by / learn more"
   badge domain (`booksy.info`) had no detector, so `CustomLinkSeeder`'s
   route-first gate let both through as raw custom links duplicating the
   already-connected Booksy card. Added sibling detectors (mirrors
   `Square.book`'s identical tenant-subdomain fix shipped earlier the same
   day) so both route through the booking policy instead of the links
   pool. The two already-written junk rows on the live squeakprobarber
   test account were also soft-removed directly.

Also shipped the same day: the Get Started dialog's platforms step now
holds behind one loading skeleton until every `platforms.*` pass reports
ready, instead of revealing rows pass-by-pass as they land
(`partna-monorepo` commit `bc1eaa0f`).

Full backend suite: 11,366 passed / 0 failed. `catalog:compile` and
`routing:corpus` regenerated for the two new Booksy detectors.

### Not fixed at the time — flagged for the owner (superseded below)

- **The `website` build stage still has no terminal event** on this fresh
  St Ali retest (`started` at 04:17:37, never `landed`/`failed`), and
  `site.logo_candidates` is still empty — the exact issue flagged above,
  reproduced on a completely fresh account, confirming it is not
  incidental. Traced to an infrastructure gap, not application code: the
  job `ScanPreviousWebsiteContentJob` dispatches onto the `scraping` queue
  (Horizon's `supervisor-long`), and the dev environment's log shows the
  Horizon worker cluster instance receiving repeated "asked the supervisor
  to tear the stack down" hibernation messages a few minutes into the
  build (the environment has `usesHibernation: true`), with zero trace of
  this job class ever running in the following 40+ minutes — while other
  queues' jobs (streaming, ingest) keep running fine. This is the same
  class of problem `media_mirror` was already split onto Laravel Cloud's
  scale-to-zero managed queue to solve (2026-09-04) — `scraping` hasn't
  had that migration. Needs an owner decision (disable hibernation on
  dev, or migrate `scraping` onto a managed queue) — not something to
  silently patch via a code commit.
- **squeakprobarber's live public workplace is "Drysdale Village Pizza"**
  (`(03) 5251 3937`, Drysdale VIC) — confirmed live on the published wire,
  not just a dialog candidate. `site.workplace_candidates` has zero rows
  for this account (the bio-mention auto-search found nothing), so this
  connection did not come from an automated match — it looks like manual
  interaction with the Get Started dialog's own listing-search box during
  testing, not a code defect. Flagged rather than silently
  "fixed"/reverted since it wasn't clear this was unintended.

  **Correction (2026-09-05, later):** this was wrong — the owner confirmed
  squeakprobarber's real workplace is "Members Only Chop Shop", a barbershop
  in Orlando, FL (findable by googling the Instagram handle
  `membersonlychopshop`), and the Drysdale Village Pizza connection was a
  real code bug, not tester interaction. See the next section for the two
  root causes and the fix.

## Update, later still: two more root causes found and fixed

### squeakprobarber's wrong workplace — two compounding bugs, not manual interaction

`site.workplace_candidates` being empty (noted above) wasn't evidence of
manual interaction — it meant the automated one-hop search never even
produced a card to show, because it was silently failing before it got
that far, for two independent reasons that both had to be fixed:

1. **A hardcoded `'AU'` region bias in the bio-mention Google Places
   search.** `BioMentionChainsJob`'s workplace-mention handling (both the
   one-hop attempt and the post-scrape `venueFrom()` fallback) passed
   `'country' => 'AU'` into the candidate array that `FreshaWorkplaceLinker`
   forwards to `GoogleBusinessService::searchText()` as a region bias. A
   mentioned account can be anywhere — St Ali's own bio mentions are
   Australian, but squeakprobarber's `@membersonlychopshop` mention is a
   business in Orlando, FL. With an `AU` bias, Google Places Text Search
   never surfaces the real (US) listing at all, so `candidates()` comes
   back empty and no card is ever written — with nothing in the logs to
   explain why, which is exactly why this read as "the search found
   nothing" rather than "the search was miscalibrated". Fixed by passing
   `'country' => null` in both places: the venue's own country is unknown
   at this stage, so no bias is honest.
2. **`FreshaWorkplaceLinker::namesAgree()` couldn't match a concatenated
   handle against a spaced real name.** Even with the region bias fixed,
   the one-hop attempt builds its search query from
   `ucwords(str_replace(['_','.'], ' ', $handle))` — which only splits on
   underscores/dots. `membersonlychopshop` has neither, so it stays one
   word ("Membersonlychopshop") and never becomes "Members Only Chop
   Shop", failing every one of `namesAgree()`'s exact/substring/token-
   overlap checks against Google's real listing name. Fixed by adding a
   squashed-equality check (lowercase, strip all non-alphanumerics, then
   compare) to `namesAgree()`, so "membersonlychopshop" now correctly
   matches "Members Only Chop Shop".

Both fixed in `app/Jobs/PreAccount/BioMentionChainsJob.php` and
`app/Services/Platforms/FreshaWorkplaceLinker.php`. Verified with 20
targeted tests + Pint + PHPStan, and against the live Google Places API:
searching "Membersonlychopshop" with no region bias now returns exactly
one candidate, "Members Only Chop Shop" (Orlando, FL, place_id
`ChIJF4LX8_t654gRWecFltnWbyg`), which the fixed `namesAgree()` accepts.
squeakprobarber's live `site.workplaces` row and Google Business
`platform_connections` row were corrected using the real
`FreshaWorkplaceLinker::connect()` code path (not hand-written data) —
the wrong Drysdale Village Pizza fields had to be explicitly cleared
first, since `IdentitySync::applyFromGooglePayload()` only fills blank
fields for partna accounts (`google_business_full_sync` capability is
false for `partna`) and won't overwrite an already-populated wrong value
on its own. Live wire post-fix (`scripts/proof/wire.sh squeakprobarber`):
`workplace.name = "Members Only Chop Shop"`, `workplace.phone = "(407)
745-4376"`.

### `ScanPreviousWebsiteContentJob` never closing the `website` build stage

Root cause, once the queue-starvation theory above was checked more
carefully: it wasn't only infrastructure. The job's only terminal
`STAGE_WEBSITE` progress note (`landed`/`skipped`/`failed`) lived inside
the gallery-photo-extraction block at the very end of `handle()`. For a
**partna** account (`workplace_brand_is_site_identity` capability false —
a partna's workplace website is someone else's brand, not their own
identity), the design-evidence block earlier in the method — which is
where logo/favicon/accent extraction actually happens — returned early
without ever reaching that trailing note. So the stage's fate depended on
a feature (gallery photos) that has nothing to do with what the stage is
actually named after, and a huge share of accounts (every partna account)
structurally could never close it.

Fixed per the owner's explicit instruction to fix the stuck stage
"however you can" and to drop previous-website gallery-image extraction
from this job entirely (logo extraction only, going forward):

- Moved the design-evidence block (favicon, accent colour, logo
  candidates, `LogoAutoGrabber::grabIfEmpty()`) to run first in
  `handle()`, and made it write the `STAGE_WEBSITE` terminal note itself —
  `landed` ("Found your logo") when a logo was actually grabbed, `skipped`
  ("No logo found on your website") when none was found, for business
  accounts; `skipped` ("Looked at your website") for partna accounts,
  which never attempt logo extraction at all.
- Removed the gallery-candidate-extraction block and its
  `WebsiteGalleryScanJob::dispatch()` call from this job entirely (the
  job class itself, `WebsiteGalleryCandidateExtractor`, and the backfill
  command that also uses them are untouched — only this one dispatch
  site was removed).
- Removed a stray duplicate `STAGE_WEBSITE STARTED` note inside
  `resolveMenuPageUrl()` that — given the reordering above — would have
  fired *after* the new terminal note for any food-business account whose
  homepage scan found no menu/PDF, silently re-opening the stage and
  undoing the fix for exactly the accounts (cafes/restaurants) most
  likely to need it.

Verified with 2 new targeted tests proving the terminal note now lands
for both outcomes, all 30 pre-existing tests in
`ScanPreviousWebsiteContentJobTest`/`...RetryTest` still passing after
updating their injection-point assumptions, plus Pint/PHPStan clean.

**Live verification caveat:** `BuildProgress::noteForUser()` only writes
progress events while a build is inside its "live window" (unclaimed and
recent) — it cannot retroactively backfill a note onto a build that has
already `settled_at`. St Ali's build settled hours before this fix
landed, so its `pre_account_build_events` row for `website` will remain
`started`/never-landed forever; that's expected and does not indicate the
fix is broken. Separately, and unexpectedly: St Ali's logo *work* had
already succeeded at original signup time regardless of the missing
progress note — `site.site_media` shows two active, `processing_state
= ready` logo rows (`logo_full`, `logo_square`) created seconds after the
website scan started. The missing terminal event was purely a
progress-feed/UI symptom for this particular account, not a sign the
underlying logo grab had failed. Full confidence that the fix behaves
correctly end-to-end (including the terminal note itself landing in real
time) still needs a fresh signup, since St Ali's build is already outside
the live window.

Also checked, since the live public site initially *looked* like it was
showing a plain-text wordmark instead of an image logo: it isn't. The
nav's `<img class="logo-mark">` on `stalicoffeeroasters1.partna.au` is a
real, fully-loaded 600×145 SVG pointing at the extracted `logo_full`
vector variant (`GET .../vector_3162e767cd036f6f.svg` → 200); confirmed
via `naturalWidth`/`naturalHeight`/`complete` on the live DOM, and via the
public API (`profile.brand.logoFull.urlSvg` is populated and resolves).
St Ali's actual uploaded website logo is itself a stylized text wordmark
("ST. ALi Coffee Roasters"), which is why a screenshot reads as plain
text — the extraction, storage, wire serialization, and frontend render
are all working correctly end-to-end for this account. (Along the way,
confirmed the frontend's logo gate at `apps/pages/src/pages/[...path]
.astro:297-305` — which hard-nulls `brand` for `accountType !== 'business'`
by deliberate 2026-08-19 platform ruling — does not affect St Ali, since
its account type is `business`.)
