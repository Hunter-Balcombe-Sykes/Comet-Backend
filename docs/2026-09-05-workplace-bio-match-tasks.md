# Workplace bio-match — running task list

Started 2026-09-05 from the `squeakprobarber` test signup
(`tobiasindarwin@gmail.com`, Instagram `@squeakprobarber`, bio mentions
`@membersonlychopshop`, Orlando mentioned twice in the bio). Add to this as we
go; delete the file when everything in it has shipped.

Status: `OPEN` ready to build · `HELD` needs an owner decision first ·
`DONE` shipped (leave the entry until the whole file goes).

**Nothing in here is being executed yet.** Do not start until the owner says
go.

**Standing rule for every task in this file:** each fix ships with a
regression test built from the REAL data below — the actual handle, bio text
and Places response for `membersonlychopshop` — not an invented fixture.

---

## What happened (evidence)

Traced via Supabase (`glncumufgaqcmqhzwrxm`, dev) + Nightwatch, 2026-09-05.

- `core.pre_account_builds` id `01a06ef2-d281-7360-93e1-11268f2c9f67`,
  user `01a06ef2-e486-73d2-8687-7942b917d78d`.
- `core.pre_account_build_events` for that build shows the `workplace` stage
  ran to completion, not a timeout:
  - `00:23:32` started — "Checking 3 places mentioned in your bio"
  - `00:23:49` skipped — "No workplace found from your bio yet" (17s)
- `site.workplace_candidates` has **zero rows** for this build (and zero
  rows in the whole dev DB) — no candidate was ever written, i.e. the Places
  search itself came up empty, it didn't find-then-drop one.
- No Nightwatch exception fired in that window — this is a silent
  no-match, not a crash.
- Manually searching Google Places for **"members only chop shop"**
  immediately surfaces the correct listing — "Members Only Chop Shop, North
  Magnolia Avenue, Orlando, FL, USA" — confirming the venue is findable and
  the automated pass should have matched it.

## Root causes found reading the code

1. **Region hardcoded to `AU` regardless of the signup's actual country.**
   [`app/Jobs/PreAccount/BioMentionChainsJob.php:252`](../app/Jobs/PreAccount/BioMentionChainsJob.php#L252)
   (the Places-first one-hop) and
   [`app/Jobs/PreAccount/BioMentionChainsJob.php:515`](../app/Jobs/PreAccount/BioMentionChainsJob.php#L515)
   (`venueFrom()`'s default) both pass `'country' => 'AU'` into the venue
   array. That flows through
   [`FreshaWorkplaceLinker.php:279`](../app/Services/Platforms/FreshaWorkplaceLinker.php#L279)
   into `regionCode` on
   [`GoogleBusinessService::searchText`](../app/Services/Platforms/GoogleBusinessService.php#L223),
   which biases the Places Text Search toward Australia. `squeakprobarber`'s
   business is in Orlando, FL, USA — searching with an AU region bias for a
   US venue is exactly the kind of mismatch that can suppress or
   mis-rank the correct result.

2. **Handle-to-venue-name derivation only splits on `_` and `.`.**
   [`app/Jobs/PreAccount/BioMentionChainsJob.php:247`](../app/Jobs/PreAccount/BioMentionChainsJob.php#L247)
   does `ucwords(str_replace(['_', '.'], ' ', $handle))`. The mentioned
   handle `membersonlychopshop` has no underscore or dot separators, so this
   produces the single mashed-together query `"Membersonlychopshop"` instead
   of `"Members Only Chop Shop"` — a text query Google Places is unlikely to
   match, independent of the region bias above. This is very likely the
   proximate cause: the one-hop query sent to Places was never the venue's
   real name.

3. **The bio's own locality signal ("Orlando" appearing twice) is never
   read.** There is a location extractor in `venueFrom()` for street/postcode/
   phone (`BioMentionChainsJob.php:495-518`) but nothing pulls a city/region
   token out of the bio text to build a `locationBias` (lat/lng circle) or
   correct `regionCode` for the search. The `$bias` param is only ever passed
   as `null` from these two call sites — so the search runs unbiased except
   for the wrong hardcoded country.

## Tasks

- [ ] **OPEN** — Derive `regionCode` from the signing-up user's actual
  country/locale instead of hardcoding `'AU'` at both call sites in
  `BioMentionChainsJob`. Confirm what's available at job time (`User` has no
  country field populated pre-onboarding per `core.users` — check the
  Instagram scrape payload / IP-based signal used elsewhere in the pre-account
  pipeline, e.g. `created_ip_hash` geolocation, for a real source of truth).
- [ ] **OPEN** — Fix the handle→name heuristic so a no-separator handle like
  `membersonlychopshop` still produces a sane query. Options to weigh: word-
  segmentation on the concatenated handle, or skip the one-hop guess entirely
  and go straight to scraping the mentioned account's own bio/fullName when
  the handle has no separators (the code already has this as its second
  attempt — worth checking whether trying it first for unsegmented handles is
  simpler than segmenting).
- [ ] **OPEN** — Extract a city/region token from the bio text (same pass that
  already pulls street/postcode/phone in `venueFrom()`) and feed it into
  `proposeCandidates`'s `$bias`/region args so a mentioned city like "Orlando"
  actually narrows the Places search instead of being ignored.
- [ ] **OPEN** — Add a regression test using this exact case: handle
  `membersonlychopshop`, bio containing "Orlando" (x2), expected Places match
  "Members Only Chop Shop, North Magnolia Avenue, Orlando, FL, USA" — assert
  a `site.workplace_candidates` row gets written instead of a skip.
- [ ] **HELD** — Decide whether hardcoded `AU` should become a per-account
  default at all, or whether region should always be inferred/omitted when
  unknown (open question: is `AU` defaulting there intentional for the
  common case, i.e. most current users ARE Australian — needs an owner call
  on whether this is a real regression or a known gap being hit for the
  first time by a US test signup).
