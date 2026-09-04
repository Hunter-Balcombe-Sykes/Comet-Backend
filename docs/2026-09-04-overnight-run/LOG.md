# Overnight run 2026-09-04 — findings log

Format per `docs/2026-09-04-overnight-platform-item-suggestion-run.md` §0.2.
`F<n>` = in-scope finding from this run's own workstreams. `X<n>` = unrelated
issue hit along the way, fixed under the same gate.

## W2–W5 (events roster, social hosts, item-URL grammar, trivial fixes)

Implemented by a 3-agent parallel Workflow (wf_18345203-928) on disjoint
files: `app/Services/Platforms/WebsiteLinkHarvester.php` (events block + 12
social hosts), `app/Services/Platforms/MediaPageReader.php` +
`tests/fixtures/Content/item-url-corpus.php` (10 item-URL platforms),
`app/Site/Pools/ItemLinkRules.php` + `app/Services/Platforms/EventPageReader.php`
(roster + docblock). All cascading test failures the agents caused
themselves were found and fixed in the same pass (pint/phpstan clean,
targeted suites green — see each agent's own summary for detail).

### F1 — `ItemLinkRules::ROSTER` missing 7 of the 9 new item-URL platforms

**What**: the MediaPageReader agent and the ItemLinkRules agent worked on
disjoint files in parallel (by design, to avoid a write collision), but
ItemLinkRules's task was scoped only to the 14 events platforms — it had
no visibility into which music/video platforms MediaPageReader's sibling
task was adding. Result: `ItemUrlCorpusTest`'s own cross-file consistency
check ("keeps the item grammar and the pool rosters in step") caught
`beatport`/`hypeddit` missing from `ROSTER['listen']` immediately; but
`audiomack`/`deezer`/`feature_fm`/`laylo`/`linkfire` (listen) and
`dailymotion`/`rumble` (watch) were ALSO missing and went uncaught only
because the MediaPageReader agent's corpus additions for those 7 were
grammar-only (no concrete example URL in the condensed research spec it
was given), so no corpus row existed to trip the same consistency check.

**Evidence**: `git diff` before fix showed `ROSTER['listen']` /
`ROSTER['watch']` unchanged by either agent for these 7 keys, while
`MediaPageReader::classifyItem()` had real arms for all 9.

**Fix**: added all 9 platforms (`beatport`, `hypeddit`, `audiomack`,
`deezer`, `feature_fm`, `laylo`, `linkfire` → `listen`; `dailymotion`,
`rumble` → `watch`) plus their `HOSTS` entries to
`app/Site/Pools/ItemLinkRules.php`, and added real, live-verified corpus
item+profile rows for the 7 that had none (using the exact real URLs from
the original W1 research pass, not invented) to
`tests/fixtures/Content/item-url-corpus.php`.

**Verified**: `vendor/bin/pint`/`phpstan` clean on both files;
`ItemUrlCorpusTest` + `EventsPoolTest` (21 tests, 78 assertions) green.

### X1 — `composer test`'s migration-safety guard fails before Pest ever runs (pre-existing, unrelated to tonight's work)

**What**: `guard:no-unsafe-migrations` (first step of `composer test`) flags
three already-committed migrations (dated 2026-09-03, from earlier work
this session, unrelated to tonight's platform/item mandate) for unsafe
patterns: `20260903170001_pre_account_builds_settle_sweep_idx.sql`
(`CREATE INDEX` without `CONCURRENTLY`), `20260903210000_rename_below_threshold_block_reason.sql`
(`ADD CONSTRAINT ... CHECK` without `NOT VALID`), and
`20260903220001_source_intents_verifying_state.sql` (both issues, plus
`CONCURRENTLY`-eligible indexes bundled with other DDL in one file). None
of the three are grandfathered (all cutoffs in the guard script predate
2026-09-03). This has been silently blocking the full `composer test` gate
since these files landed — found only because tonight's run actually tried
to run the full suite as its own gate.

**Fix**: a Sonnet agent split/patched all three per
`supabase/migrations/CONVENTIONS.md` §1/§2 (added `CONCURRENTLY`, split the
bundled-DDL file into one-statement files, added `NOT VALID` to both CHECK
constraints — plus a fourth constraint the agent found beyond the original
brief, `platform_connections_verification_state_check`, which had the same
gap). Confirmed all three original migrations were already applied on dev
before touching anything, so the file edits are for a future from-zero
apply only. Pushed two new no-op `VALIDATE CONSTRAINT` follow-up migrations
to dev (`20260904235900`/`...235901` — confirmed no-ops, both constraints
were already `convalidated=true`). Two more files
(`20260904235902`/`...235903`, the extracted `CREATE INDEX CONCURRENTLY`
statements) were created but deliberately NOT pushed — dev already has
both indexes with matching definitions; flagged for a later push if wanted.

**Verified**: `composer guard:no-unsafe-migrations` → "Migration safety lint
passed." (exit 0). A full `composer test` run afterward got past the guard
and ran all 11182 Pest tests (see Gate section below for the one real
failure it surfaced, F4, and its fix).

## Critic dispatch (Probe→Critic→Gate, §0.4)

Two independent critics per fix area, adversarial (told to try to break
the diff, not confirm it): Critic A + Critic C on the 14-platform events
harvester arms; Critic B + Critic D on the 12-platform social-hosts +
10-platform item-URL-grammar changes.

**Critic A** (events arms, pass 1): clean — no findings.

**Critic B** (social-hosts + item-URL grammar, pass 1): clean — no
findings. Independently confirmed `urlBelongsTo()`'s suffix-match logic
correctly covers branded subdomains (`*.ffm.to`, `*.lnk.to`) even with only
the bare host listed in `ItemLinkRules::HOSTS`.

**Critic C** (events arms, adversarial): found 4 issues, all confirmed real
and fixed (see F2, F3 below) — 2 production regex bugs (admitone, megatix)
and 2 documentation-accuracy bugs (`known-link-only.php`'s "eventfinda and
laylo are untouched" claim; `CatalogBackedClassificationTest.php`'s false
claim of an existing test-coverage pin for the new brands).

**Critic D** (social-hosts + item-URL grammar, adversarial): found 1
serious production bug — the `ko_fi`/`ko-fi` key divergence (F1... actually
filed as a standalone fix below, not numbered F1 since F1 was already taken
by the ROSTER gap). Independently re-traced Agent M's MediaPageReader arms
and my own corpus additions for the other 9 item-URL platforms and found
them all correct.

### F2 — `admitone`/`megatix` events regexes didn't match their own real corpus URLs (Critic C)

**What**: `admitone`'s regex only matched `admitone.com`'s shape
(`/events/.../[0-9a-f]{24}`, plural, 24-hex ObjectId); the real
`tickets.admitonelive.com` URLs in `tests/fixtures/Routing/corpus-real.php`
use a completely different shape — singular `/event/<slug>-<numericId>`.
`megatix`'s regex required an `/events/` or `/white-label/` path prefix;
the real corpus also has a bare-root-slug shape
(`megatix.com.au/SnowMachineQueenstownAud`) with neither prefix.

**Fix**: `app/Services/Platforms/WebsiteLinkHarvester.php` — added a new
branch to the admitone arm for `^/event/[a-z0-9-]+-\d+/?$` on
`tickets.admitonelive.com`; added a new branch to the megatix arm for a
bare single-segment slug, denylisting the brand's own known
marketing/account paths (`sell-tickets`, `orders`, `about`, etc.) so it
doesn't swallow them as events.

**Verified**: both fixed against the exact real corpus URLs that exposed
the bug (`vendor/bin/pest tests/Feature/Platforms`, `pint`, `phpstan`
clean).

### F3 — `known-link-only.php`/`CatalogBackedClassificationTest.php` comment inaccuracies (Critic C)

**What**: (a) `known-link-only.php`'s "eventfinda and laylo are untouched"
comment was false for eventfinda — it DID gain a `classify()` event arm
this round, it just happens to keep its row because this sweep's specific
probe URL (a `/venue/` page) doesn't hit the new event shape, same as
admitone/skiddle/tixr. (b) `CatalogBackedClassificationTest.php` claimed
songkick's organiser reclassification was "pinned by
`WebsiteLinkHarvesterTest.php`'s 'both event platforms' dataset" — that
dataset only covers eventbrite/humanitix and was untouched by this round;
none of the 14 new events brands had any direct unit-test pin anywhere.

**Fix**: corrected both comments. For (b), also dispatched a follow-up
Sonnet agent to add a real dataset (`tests/Unit/Platforms/WebsiteLinkHarvesterTest.php`)
pinning `classify()`'s category output for the 14 new brands, using only
real URLs already present in `corpus-real.php` or cited in the harvester's
own code comments (no invented URLs) — result pending, appended below when
it lands.

### Standalone — `ko_fi` vs `ko-fi` key divergence (Critic D)

**What**: `WebsiteLinkHarvester`'s `SOCIAL_HOSTS`/`SOCIAL_PLATFORM` used the
catalog brand_key `ko_fi` (underscore) instead of the catalog's
`legacy_platform` value `ko-fi` (hyphen), which `LegacyPlatformMap::inverse()`
is actually keyed by. Verified via a fresh grep across all 12 new social
brands' catalog Definition files that ko-fi is the ONLY one where brand_key
and legacy_platform diverge. Left unfixed, every real Ko-fi link
auto-discovered via bio scan/GBP sync/website import would fail
`LegacyPlatformMap::surfaceFor('ko_fi')` (returns null), fail
`IntegrationConnection::booted()`'s `isKnownSurface()` check, and throw —
caught and swallowed into a plain-link fallback by `LinkRouter::routeClassified()`,
but silently defeating the entire point of adding it to `SOCIAL_HOSTS`, and
spamming Nightwatch on every occurrence.

**Fix**: changed both map keys and the platform value from `ko_fi` to
`ko-fi` in `WebsiteLinkHarvester.php`; updated the corresponding assertion
in `WebsiteLinkHarvesterTest.php`.

**Verified**: `pint`/`phpstan` clean; `WebsiteLinkHarvesterTest.php` green.

### F4 — `known-link-only.php` needed 4 more rows removed after the item-URL grammar landed (self-caught via full-suite Gate run)

**What**: a full `composer test` run (kicked off once X1 was fixed, to get
a genuine full-suite Gate result) surfaced one real failure:
`CatalogClassificationSweepTest`'s ratchet flagged `feature_fm.release`,
`hypeddit.release`, `laylo.drop` and `linkfire.release` as no longer
belonging in `known-link-only.php` — not a regression, but the CORRECT,
expected effect of Agent M's `MediaPageReader::classifyItem()` item-URL
grammar landing: `classify()` now routes these hosts' probe URLs through
that grammar before falling through to the catalog's routing_class, so
they answer `'content-item'` instead of a generic `'link'`. Confirmed via
direct `classify()` calls (tinker) for these 4 plus the 4 sibling brands
(audiomack, beatport, dailymotion, rumble) that also gained
`MediaPageReader` arms this round but correctly KEEP their row — their
probe URLs are artist/channel pages, not item pages, and the new grammar
only recognises single items.

**Fix**: removed the 4 stale rows from `known-link-only.php` (3 from the
'content' group, 1 — `laylo.drop` — from the 'events' group, since its
catalog `routing_class` is `events` even though the real gap was in
content-item grammar) with an explanatory comment distinguishing them from
the 4 siblings that correctly keep their row.

**Verified**: `CatalogClassificationSweepTest` green (was the only failure
in an 11182-test, 40380-assertion full run — everything else passed).

### X2 — `composer test`/`composer test:ci` silently truncate on any suite run ≥300s (pre-existing, unrelated to tonight's work)

**What**: re-running `composer test` after F4 to get a genuine clean Gate
result failed again — not with a test failure, but with Composer's own
process-timeout: `The process '...artisan test' exceeded the timeout of
300 seconds`, exit 1. Composer scripts default to a 300s process timeout;
this repo's full suite genuinely takes ~1257s (confirmed by an earlier
direct `php artisan test` run outside Composer, which completed normally
and found only the one F4 failure). `composer test:ci` — the script CI's
"Run tests" step actually invokes (`.github/workflows/ci.yml:215`) — runs
the identical `@php artisan test` with no timeout override either, so this
is not a local-only inconvenience: any CI run whose full suite crosses 300s
wall-clock is at risk of a false-red "exceeded the timeout" failure that
looks like a test failure but isn't one. The fix for exactly this class of
problem already exists once in this same file — the `dev` script opens
with `Composer\Config::disableProcessTimeout` for its own long-running
process — but was never applied to `test`/`test:ci`.

**Fix**: added `Composer\Config::disableProcessTimeout` as the first step
of both `test` and `test:ci` in `composer.json`, matching the `dev`
script's established pattern. Left `test:pg`/`test:schema`/`test:authz`
untouched — narrow dedicated lanes, no evidence they approach 300s.

### X3 — `SourceIntentDomainTest` had stale state-domain expectations, failing the required `postgres-tests` CI lane (pre-existing, unrelated to tonight's work)

**What**: pushing tonight's commits surfaced that `postgres-tests` (a
required CI check, `composer test:pg`) had already been red on the
immediately preceding commit (`c7efe16f1`, a docs-only plan-doc commit from
earlier this run) and on every commit before it back to at least
2026-09-03. `Tests\Postgres\SourceIntentDomainTest` asserted
`routing.source_intents_state_check` has exactly 5 values
(`applied, blocked, dismissed, proposed, superseded`) and that only
`dismissed`/`superseded` are written outside `Verdict::intentState()`.
Neither was updated when `20260903220001_source_intents_verifying_state.sql`
(same day, earlier work this session) added a real 6th state, `verifying`,
now actively written by `SuggestionsController`'s verify-queue path and
`SetupBatchApplier`, and read back by `VerifyLinkJob` and
`CheckStuckSourceIntentsCommand`. The same run's CI log also showed the
"No unsafe migration locking patterns" step failing — that one is X1,
already fixed by the commit just before this one.

**Fix**: updated `tests/Postgres/SourceIntentDomainTest.php` — the
domain-size assertion now expects 6 values including `verifying`; the
"written outside Verdict::intentState()" dataset now includes `verifying`
with its real call sites cited (matching the file's own documentation
convention for `dismissed`); the file's top docblock documents `verifying`
alongside `dismissed`/`superseded`.

**Verified**: no local Postgres available to run `composer test:pg`
directly, so verified against live dev Postgres instead (project
`glncumufgaqcmqhzwrxm`) via `execute_sql`:
`source_intents_state_check` = `CHECK (state = ANY (ARRAY['proposed',
'verifying', 'applied', 'blocked', 'dismissed', 'superseded']))`,
`convalidated = true` — matches the fix exactly. `pint`/`phpstan` clean.
CI's own `postgres-tests` run on this push is the final confirmation
(pending at time of writing).

**Verified**: re-ran `composer test` with the fix — completed normally,
no timeout. Full clean Gate result for tonight's entire batch: **11196
passed, 0 failed** (11 deprecated + 1 warning + 2 skipped, all pre-existing
and unrelated — PHP 8.5 `ReflectionMethod::setAccessible()` deprecation
notices in test helpers untouched by tonight's work; not fixed, out of
scope). Duration 1272.6s (~21min) — well past Composer's 300s default,
confirming the fix was load-bearing, not incidental.

## W6 — full connect-path sweep (all 181 catalog surfaces)

Workflow (15 agents) drove every catalog surface's real example URL through
the actual `LinkProjector` (the routing-decision layer, not the looser
harvester), plus 3-6 realistic noise variants per surface (tracking params,
trailing slash, www/no-www, case, mobile subdomain, locale prefix, scheme)
chosen per-brand from that brand's own `Detector` pattern. 181/181 surfaces
covered, 336 tool calls, 0 agent errors.

**Result: only 1 real, actionable finding.** 5 other "failures" were
by-design, confirmed via each surface's own docblock: `direct.book`,
`bandcamp.store`, `generic.store`, `partna.manual_product`,
`google_business.listing` all deliberately register zero `Detector` rules
(reached via a different mechanism — JSON-LD probe, Places API, or manual
entry — never via URL-shape routing). Not bugs; correctly working as
documented.

### F5 — `nowbookit.reserve`'s hand-written probe URL didn't match its own detector's required shape

**Symptom**: W6 found `tests/fixtures/catalog/probe-urls.php`'s
`nowbookit.reserve` example (`?RestaurantId=1234567`) fails to resolve via
`LinkProjector` — and all 5 noise variants built on it failed identically
(same root cause, not 5 separate bugs). `Nowbookit.php`'s detector requires
BOTH `accountid` AND `venueid` query params (case-insensitively spelled);
`corpus-generated.php`'s synthetic fixture already has the correct shape
(`?accountid=100000&venueid=100001`), and `corpus-real.php`'s own comment
already documents this surface as "genuinely requires BOTH... deliberately
NOT covered" by real-world evidence. So the router layer itself was never
wrong — only the hand-written probe fixture was.

Why `CatalogClassificationSweepTest` never caught this: it exercises
`WebsiteLinkHarvester::classify()`, not `LinkProjector` — and the harvester
uses a deliberately looser HOST-ONLY regex (`RESERVATION_HOSTS['NowBookit']
= '~(^|\.)nowbookit\.com$~'`) with no query-param check, by design (the
harvester's job is "does this look like a reservation platform", the
router's job is "is this a specific connectable account" — two different
strictness levels at two different layers). The bad probe URL happened to
still pass the loose check, so it never tripped the sweep test's
invisible/link-only ratchets.

**Fix**: `tests/fixtures/catalog/probe-urls.php` — changed the
`nowbookit.reserve` entry to the real shape
(`https://acmestore.nowbookit.com/?accountid=100000&venueid=100001`),
matching `corpus-generated.php`'s already-correct fixture.

**Verified**: tinker-confirmed both layers now agree —
`LinkProjector::project()` → `matched=1, surface=nowbookit.reserve`;
`WebsiteLinkHarvester::classify()` → `{"platform":"nowbookit","category":"reservations","label":"NowBookit"}`.
Ran `CatalogClassificationSweepTest` + `WebsiteLinkHarvesterTest` +
`RoutingCorpusTest` together: 80 passed, 1 skipped (report-printer, env-gated),
0 failed. `pint --test` clean on the changed file.

## W7 — full item-path sweep (listen/watch/events pools, `PoolItemCreateController`)

Workflow (3 agents, one per pool) pasted real item URLs from
`tests/fixtures/Content/item-url-corpus.php` through the real
`PoolItemCreateController::store()` (direct controller invocation, matching
`PoolLaneTest.php`'s own pattern) against `connect-sweep-01`, covering
base-item creation, account-vs-item refusal, malformed-URL handling (never a
500), and the `MediaParentSuggester` connection point.

**listen pool (15 platforms) and watch pool (6 platforms): 100% clean.** No
bugs — every base item, account-not-item refusal, malformed-URL case, and
parent-suggestion write behaved correctly.

**events pool (23 platforms): 9 findings, all one root cause; only 1 was a real bug.**

### F6 — TryBooking's `/eventlist/` organiser page had no organiser detection, unlike its Eventbrite/Humanitix siblings

**Symptom**: pasting `https://www.trybooking.com/eventlist/constantreader`
(an organiser's whole event listing, not a single event) into the events
pool silently created a bare, dateless `kind='event'` card titled just
`trybooking.com` instead of being refused with the same "that looks like an
organiser page" hint Eventbrite/Humanitix pages get.

**Root cause**: `EventPageReader::organiserPlatformLabel()` — the check
`PoolItemCreateController::store()` runs BEFORE attempting to read a page as
an event — only had arms for Eventbrite (`/o/<id>`) and Humanitix
(`/host/<slug>`). TryBooking had none, so its organiser URL fell through to
`EventPageReader::read()` (which correctly finds no Event JSON-LD on a
listing page and returns null), and from there to the generic
claimed-host card fallback — a DELIBERATE, already-tested behavior
(`EventPagePoolAddTest.php`'s `'falls through to the plain card when a KNOWN
platform page carries no event markup'`, comment: "the old card behaviour,
kept only for claimed hosts: titled by host"). TryBooking's organiser page
was never meant to reach that fallback at all — it was meant to be caught
earlier, the same way Eventbrite/Humanitix's are.

**Fix**: added a TryBooking arm to `organiserPlatformLabel()` matching
`/eventlist/<slug>` (same URL-shape-only style as its Eventbrite/Humanitix
siblings — no existence check, consistent with how those two work too).

**The other 8 findings from the same sweep (humanitix, luma, partiful,
ticketek, oztix, megatix, tickethype malformed-URL cases, all "BUG: 201,
kind='event' for a nonexistent path") are NOT bugs** — re-checked against
`EventPagePoolAddTest.php:109`'s existing, passing, deliberately-worded test
and confirmed this is the intended design: any URL a KNOWN events platform's
host claims, but that doesn't yield real Event JSON-LD (dead link, or a real
page that just isn't structured as an event), gets the same bare
host-titled card as a real page with no markup — the codebase treats "we
recognise this platform but can't confirm a real event" as an acceptable
degraded card, not a refusal, and that decision is already covered by a
passing test with an explicit comment acknowledging it. Overriding that
would be redesigning an intentional, tested UX decision, not fixing a bug —
out of scope for this run. Only TryBooking's narrower, structural
inconsistency (missing organiser detection that its own siblings have) was
a genuine gap.

**Verified**: added a regression test (`EventPagePoolAddTest.php`, "refuses
a TryBooking eventlist organiser page with the connect hint") pinning the
exact fixed behavior. Ran the full `EventPagePoolAddTest` +
`EventsPoolTest` suites together: 26 passed, 0 failed. `pint --test` and
targeted `phpstan analyse` on the changed file both clean.

## W8 — suggestion-pipeline end-to-end sweep (Verdict outcomes, inbox, accept/dismiss, conflict/cap, capability gates)

Created 6 dedicated test users (`suggestion-sweep-01` through `-06`,
partna/business/food-business/non-food-business account shapes) and drove
real `PlacementPolicy`/`SourceReconciler`/`SuggestionsController` calls
against them via direct controller/service invocation (`partna:as` was
tried first but requires a real `auth_user_id`, which these dedicated
sweep accounts deliberately don't carry — switched to the same
direct-invocation pattern W7 used). 6 scenarios, 22 steps total, 8 flagged
as deviating from the scripted "expected" outcome. Investigated every one
individually rather than trusting the sweep-agent's framing at face value:

**5 of the 8 were scenario-design artifacts, not pipeline bugs** — my own
test scripting used `origin='website_import'` to simulate "a link found on
the user's own bio-link page," but `website_import` specifically means a
partna's WORKPLACE website scan (`RoutingCapabilityGate::WORKPLACE_SOURCED_ORIGINS`'s
own docblock), which correctly triggers the foreign-identity gate
(`foreignIdentityDenial()`) for identity-asserting routing classes (social/
content) on a partna account — the origin I should have used for "found on
the user's own page" is `bio_harvest`, not `website_import`. This affected
the async-verify-accept step (suggestion-sweep-01 step 3, github.profile),
the per-surface cap/swap steps (suggestion-sweep-03 steps 2-4, instagram),
and one step where an earlier step in the SAME scenario had already
occupied the one `reservations`-class slot, correctly triggering an
exclusive-class conflict instead of the clean Choose the script expected
(suggestion-sweep-01 step 4) — the actual thing under test there, tombstone
suppression on dismiss, passed perfectly. No code changes; these are
sweep-design notes for any future run using these scripts.

**1 finding (`sibling_branch` mislabeling a genuine cross-brand conflict) was investigated and confirmed as intentional, not a bug.** `SourceReconciler::isSettledWorkplaceSlot()`'s docblock explicitly reasons about TWO distinct cases sharing one block_reason: genuine same-brand multi-branch chains (the originally-measured Fresha incident, six branches becoming five noisy "use this instead?" cards), AND — a separate, explicitly-reasoned bullet — "a link off their employer's site does not get to propose replacing" a self-asserted connection, brand-agnostic by design ("an account says who you are, a booking link says how to reach you"). My suggestion-sweep-02 scenario pasted Calendly (`owner_scope='self'`) then harvested Acuity via `website_import` (`owner_scope='workplace'`) — exactly the second, deliberate case. The resulting inbox-exclusion (`SuggestionsController::index()` drops all `sibling_branch` intents by design) is therefore working as intended, if confusingly named for this second case. Not fixed — reusing one block_reason for two related "don't ask" cases avoids a schema change for a labelling nuance, and I don't have standing to redesign this UX call unilaterally on an autonomous run.

### F7 — `LinkRoutingService::describe()` discarded the specific capability-gate reason for every Note

**Symptom**: a capability-gated paste/preview (e.g. a business account without `can_use_reservations` pasting an OpenTable link) correctly comes back as `verdict=note, blockReason=null` (a Note is never dropped) — but the WIRE `explanation` field always read a generic "We'll keep this as a link on your site.", identical for every Note regardless of WHY. The specific sentence `RoutingCapabilityGate::denialFor()` computes per routing_class (`"reservations are not available for this account"`, distinct text for booking/ordering) never reached the response, the `routing.link_observations` row, or anywhere else — only the short code `block_reason='gate'` survived, indistinguishable from a booking- or ordering-denial without re-deriving which capability failed.

**Root cause**: `LinkRoutingService::describe()`'s `isNote` branch unconditionally replaces `explanation` with one of two hardcoded strings, discarding `$placement->explanation` — which `PlacementPolicy::decide()` DOES populate with the specific reason for essentially every Note case. Unlike the `blockReason`-Reject-only behavior two lines above (which carries an explicit comment justifying it — "the dashboard disables submit whenever blockReason is set... a Note is kept as a link") and the `sibling_branch` case above (also richly justified), this override had no comment explaining why the specific text should be thrown away, which was the deciding signal this one was an oversight, not a deliberate simplification.

**Fix**: `app/Routing/LinkRoutingService.php` — for a Note that isn't the storefront-candidate special case, prefer `$placement->explanation` (falling back to the old generic line only if it's somehow unset). The storefront-candidate branch is untouched.

**Verified**: added `->assertJsonPath('explanation', 'reservations are not available for this account')` to the existing `RoutingEndpointTest.php` test `'gates a reservations link for an account that cannot use reservations'` (which already exercised this exact scenario but never checked `explanation`). Ran `tests/Feature/Routing/` + `tests/Unit/Routing/` in full: 622 passed, 0 failed — confirms no other test was pinning the old generic string for a gated case. `pint --test` and targeted `phpstan analyse` both clean.

## W10 — Opus adversarial re-review of the whole run

Owner asked for a full review of the night's work plus more tests. Two
things came out of it before the review workflow even finished: a system
issue (X4) and the largest finding of the run (F8).

### X4 — 88 orphaned `cloud deploy:list` processes, oldest alive 1d 19h

**What**: `ps` showed 88 live `php .../cloud deploy:list development`
processes, the oldest running 1 day 19 hours 51 minutes, each burning
~1% CPU; load average 13.64 on an otherwise-idle 10-core machine.

**Root cause**: exactly the hazard CLAUDE.md documents for `cloud env:logs`
("no guaranteed exit path — it can wedge on a dead connection and sleep
forever, parentless and socketless"), but on the `deploy:list` subcommand,
which the documented `scripts/env/cloud-logs.sh` wrapper does not cover.
The giant-run skill already says to use `scripts/proof/deploy-wait.sh`
rather than polling `cloud deploy:list` — that rule had no enforcement.

**Fix**: `pkill -f "vendor/bin/cloud deploy:list"`. Verified 0 real
`vendor/bin/cloud` processes remain (the 12 apparent survivors were
Claude.app matching the `cloudflare` plugin path in their command line);
load fell 13.64 → 11.09 and kept dropping. No code change — flagged here
because the guard gap is real and a wrapper for `deploy:list` would close
it if this recurs.

### F8 — 8 harvester platform values resolved to no catalog surface; 6 detect-only brands were bucketed as connectable (HIGH)

**What**: the ko-fi divergence Critic D caught was one instance of a
class, and the class was never swept. A sweep of all 104 platform values
across `SOCIAL_PLATFORM` / `BOOKING_PLATFORM` / `RESERVATION_PLATFORM` /
`ORDERING_PLATFORM` found **8 that resolve to no surface at all** — every
one in `SOCIAL_PLATFORM`: bluesky, cameo, cash_app, deezer, paypal,
tumblr, venmo, vsco.

**Evidence** (tinker, pre-fix — each value pushed through the real
`IntegrationConnection::setPlatformAttribute()`):

```
kick       -> surface_key=kick.channel       isKnownSurface=YES
ko-fi      -> surface_key=ko_fi.page         isKnownSurface=YES
bluesky    -> surface_key=bluesky            isKnownSurface=NO  <-- booted() WILL THROW
deezer     -> surface_key=deezer             isKnownSurface=NO  <-- booted() WILL THROW
cash_app   -> surface_key=cash_app           isKnownSurface=NO  <-- booted() WILL THROW
vsco/tumblr/venmo/paypal/cameo                isKnownSurface=NO  <-- booted() WILL THROW
```

**Failure path** (traced, not assumed): `classify()` → `LinkRouter::routeClassified()`
→ `'social' => seedSocial()` → `resolveSocialLink()` → `IntegrationConnection`
write → `setPlatformAttribute()` resolves the bare brand key to nothing →
`booted()`'s `isKnownSurface()` guard fails → `report(UnregisteredPlatformException)`
→ `throw ValidationException` → caught by `routeClassified()`'s catch-all,
which `report($e)`s a second time and returns `RouteResult::custom()`. Net
effect: the link degrades to the same plain card it would have been anyway,
with **two Nightwatch reports per occurrence**, silently defeating the point
of adding the brand. Identical to the ko-fi failure mode.

**Root cause, two distinct halves** — `is_connectable` turned out to be the
exact discriminator:

| catalog state | brands | verdict |
|---|---|---|
| connectable + `legacyPlatform` declared | buymeacoffee, codepen, gitlab, kick, ko_fi | already correct |
| connectable, NO `legacyPlatform` | **bluesky, deezer** | belong in the map, but the bare key resolves to nothing |
| **`->notConnectable()`** | **cameo, cash_app, paypal, tumblr, venmo, vsco** | should never have been in the map |

The second half is the more serious one, and the codebase predicted it in
writing. The `yelp.listing` test in `WebsiteLinkHarvesterTest.php` says of
exactly this: *"Bucketing it would silently reverse that policy — the single
most likely way to get this change wrong."* `CashApp.php`'s own surface
comment says it is `->notConnectable()` **because** "the legacy harvester
carries no classify() entry, so a manual connect card would 422 its own
URL". The 2026-09-04 wave added the classify() entry without flipping the
connectable flag, landing all six in the inconsistent middle state.
Plan §2's W1b was specified to catch precisely this ("catch any surface
that's detect-only (not connectable) and so correctly excluded") — the
task was written correctly and its result never reached the implementation.

**Fix**: `app/Services/Platforms/WebsiteLinkHarvester.php`
- removed the six `->notConnectable()` brands from **both** `SOCIAL_HOSTS`
  and `SOCIAL_PLATFORM`; they classify as `link` via `classifyFromCatalog()`
  again, which is where they sat before and where `yelp.listing` still sits.
- `bluesky` and `deezer` now name their **surface key** (`bluesky.profile`,
  `deezer.artist`) rather than their brand key. This is what all 45
  `ORDERING_PLATFORM`/`RESERVATION_PLATFORM` entries already do
  (`uber_eats.order`, `thefork.reserve`), and it keeps the fix inside this
  map instead of recompiling the catalog to add one alias.
- `tests/fixtures/catalog/known-link-only.php`: the six came back onto the
  ratchet, honouring that file's own stated rule that it and the harvester
  constants "are the two sides of one ledger".

**Bonus correctness win**: `SOCIAL_HOSTS` is host-only and cannot express a
path requirement. Venmo's catalog detector is path-qualified
(`/u/<handle>`), so bucketing it there also claimed `venmo.com/about` and
every other venmo.com page as a profile. The catalog fall-through is
strictly more precise — the same argument the `paypal` comment in that
block had already made for PayPal, applied to one brand and not its
siblings.

**Deliberately NOT done**: making the six connectable. That is a product
decision (each needs a connect card, and half are payment handles rather
than profiles), not a bug fix, and `CashApp.php` says as much — "Card comes
later with harvester support, if ever needed." Flagged for the owner.

**Verified**: re-ran the same sweep post-fix — **104 → 98 values checked,
unresolvable 8 → 0**. The six removed brands now classify `link/<brand>`;
bluesky → `social/bluesky.profile/Bluesky`, deezer → `social/deezer.artist/Deezer`.
Added two datasets to `tests/Unit/Platforms/WebsiteLinkHarvesterTest.php`
pinning both halves — one asserting every social platform value survives
`IntegrationConnection`'s own guard (the assertion whose absence let this
live), one asserting each detect-only brand still reaches the catalog
fall-through. `tests/Unit/Platforms/WebsiteLinkHarvesterTest.php` 82 passed;
`tests/Feature/Platforms/` **2210 passed, 1 skipped, 0 failed** — including
`CatalogClassificationSweepTest`, whose ratchet independently confirms the
`known-link-only.php` ledger additions. `pint --test` and `phpstan` clean.

**Coverage gap this closed on the way**: before F8, only 2 of the 12 social
brands added that day (kick, ko-fi) appeared anywhere in the harvester's
unit tests. The equivalent gap for the 14 events brands HAD been closed by
F3's follow-up; social was simply left behind.

### F8 follow-up sweep — the same bug class applied to the events arms: CLEAN (no fix needed)

After F8, ran the identical "does every emitted platform value resolve to a
real surface" sweep against all 23 events arms in `classify()`. It reports
**14 unresolvable platform values** (luma, partiful, admitone, bandsintown,
dice, eventfinda, eventim, megatix, moshtix, see_tickets, skiddle, songkick,
ticketweb, tixr) — and every one of them is a **FALSE POSITIVE**, recorded
here so the next sweep doesn't "fix" them:

- `LinkRouter::routeClassified()` routes `'event'` to `EventsSeeder::seedStandalone()`,
  which writes an **events-pool item and nothing else** — its own comment
  says "no connection row" (R7, owner, 2026-08-19). The platform value never
  reaches `IntegrationConnection`, so the guard that F8 tripped is never
  consulted. Two of the 14 (luma, partiful) predate this run by months,
  which is the corroborating signal: a real connection-guard bug there would
  have been throwing continuously since they landed.
- The one events path that DOES create rows, `'event-organiser'` →
  `EventsSeeder::seedAccount()`, opens with its own allowlist
  (`in_array($platform, self::PLATFORMS)`) and only branches for eventbrite
  and humanitix. None of the 14 can reach it.

The bug F8 found is therefore specific to the **social** category, which is
the only one of the four that hands a hand-mapped platform value straight to
a connection write. Booking/reservations/ordering were already clean because
all 45 of their entries name surface keys directly.

Also checked in the same pass: `tickethype` initially appeared to match no
arm at all. That was a bad probe URL on my side (I used `.com.au`; the brand
is Maltese — `tickethype.com.mt`, per its catalog Detector and both
`corpus-real.php` rows). The arm at `WebsiteLinkHarvester.php:1007` is
correct. No finding.

### W8 re-test with the corrected origin — both unexercised mechanics VALIDATED (no bugs)

W8 left two real mechanics unproven because its scenarios used
`origin='website_import'` (which `RoutingCapabilityGate` correctly reads as
"the user's EMPLOYER's site" and rejects for identity-asserting surfaces on
a partna account). Re-ran both with `origin='bio_harvest'` — the right origin
for "a link found on the user's own bio-link page" — against two fresh partna
test users (`bioharvest-a-3aa34afb`, `bioharvest-b-3aa34afb`).

**A — the ASYNC L2-verify accept path (`github.profile`, which IS in
`LinkVerifier::adapters()`). Works end to end:**

| step | result |
|---|---|
| `route()` bio_harvest | `verdict=choose`, intent `state=proposed`, `origin=bio_harvest` |
| `GET /routing/suggestions` | 1 suggestion, `"Is this your GitHub?"`, actions `[accept, dismiss]` |
| `accept` | **202** `{"connectionId":null,"status":"verifying"}` — intent → `verifying`, **no connection yet** (correct: L2 has not confirmed) |
| `VerifyLinkJob` (dispatchSync) | intent → `applied` with `connection_id`, real `github.profile` row created |

**B — the per-surface cap + swap path (`instagram.profile`, max_accounts=1).
Works end to end:**

| step | result |
|---|---|
| paste `instagram.com/cristiano` | `verdict=place`, live connection created |
| harvest `instagram.com/nike` | `verdict=hold`, `block_reason=cap_reached`, `conflicting_connection_id` = the cristiano connection |
| inbox | `"You already have Instagram connected — swap it for this one?"`, actions `[replace, dismiss]`, `conflictingConnectionId` populated |
| `accept` (the swap) | 200 + NEW connection id; old cristiano row `deleted_at` set, new nike row live; intent → `applied` |

Both confirm the pipeline behaves exactly as designed, including the two
things W8 could not reach. **No bugs found** — the W8 "failures" for these
steps were entirely scenario-design artifacts, as triaged at the time.
Recorded so this is not re-run a third time.

### Probe-URL sweep (F5 generalization) — CLEAN

Ran every one of the 108 entries in `tests/fixtures/catalog/probe-urls.php`
through the real `LinkProjector` and compared the resolved surface key to the
fixture's own key. **104 match; the 4 that resolve to NULL
(`direct.book`, `generic.store`, `google_business.listing`,
`partna.manual_product`) each carry ZERO detectors and `is_connectable=false`**
— they are created manually or by API, never by URL detection, so NULL is the
only possible answer and not a defect. F5 was the only real bug in this file.

### `spotify_podcasts.show` — plan §1b's last open question ⚠️ SUPERSEDED, see F9 below

> **This entry's conclusion was WRONG and is kept only for the record.** I
> checked "does a `/show/` URL get an account label at all" (it does) and
> stopped there. The right question was "does it get the label of the *right
> brand*" — and it did not. The W9 completeness critic flagged this three
> rounds running, and it was correct. The corrected finding and its fix are
> in **F9** immediately below.

Plan §1b left this explicitly unresolved: *"`spotify_podcasts.show` status
still unconfirmed — resolve inline during W4 implementation (check if the
existing `spotify` key already handles the `/show/` path, add a branch only
if it doesn't)."* W4 never came back to it; the W9 completeness critic caught
that it was still open. Answered now, empirically:

```
/show/4rOoJ6Egrf8K2IrywzwOMk            item=null              accountLabel='Spotify'
/episode/512ojhOuo1ktJprKbVcKyQ         item=spotify/episode   accountLabel=NULL
/intl-de/show/4rOoJ6Egrf8K2IrywzwOMk    item=null              accountLabel='Spotify'
/track/11dFghVXANMlKmJXsNCbNl           item=spotify/track     accountLabel=NULL
```

The existing `spotify` key already handles it correctly and completely:
- `classifyItem()` (`MediaPageReader.php:229`) matches `(track|album|episode)`
  only, so a `/show/` URL is correctly NOT an item — a show is a podcast
  SERIES, i.e. an account-like entity, not a single thing to pin.
- `accountPlatformLabel()` (`:495`) already includes `show` in its
  `(artist|show|user|playlist)` alternation, so a pasted show URL gets the
  proper connect-hint ("connect Spotify to bring its content in, or paste one
  track's link") rather than the generic "not a track" dead end.
- An episode's parent resolves to its show URL (`:139`), so
  `MediaParentSuggester` can offer the series.
- The locale-prefixed form (`/intl-de/show/`) works on both sides.

**No branch added — adding one would have been wrong.** `spotify_podcasts.show`
carries `legacy_platform=NULL`, but unlike the F8 brands nothing ever emits
`spotify_podcasts` as a platform value (the harvester and MediaPageReader both
emit `spotify`), so it cannot reach the connection guard. Plan §1b's question
is now closed.

### X5 — CI red on every push: npm decommissioning the legacy audit endpoint (pre-existing, unrelated)

**What**: `6d3458f29` (F6) and `7489cb78a` (F7) both went red on commits that
touched only PHP and docs. Two different jobs, one root cause:

- `supply-chain` / `npm audit (repo root)`:
  `400 Bad Request - POST .../security/audits/quick` carrying npm's own notice
  *"This endpoint is being retired. Use the bulk advisory endpoint instead."*
  On the rerun 40 minutes later the same step got `503 Service Unavailable`
  from the same URL — so this is a decommissioning, not a blip.
- `test` / `Security scan (Checkpoint)`: `The process "'npm' 'audit' '--json'"
  exceeded the timeout of 120 seconds` — Checkpoint's `NpmAuditCheck` shelling
  out to the same dying endpoint.

**Not our code**: `git diff f9a88d8f8..6d3458f29` is 3 files, all PHP/docs, and
**no npm file was touched anywhere in this run**. `f9a88d8f8` (F5) passed the
identical job minutes earlier.

**Root cause**: npm 10 — what node 20 ships, and what the runner image gives
the `test` job, which sets up no Node at all — still calls the legacy
`/-/npm/v1/security/audits/quick`. npm 11 uses the supported bulk advisory
endpoint. Confirmed locally on npm 11.6.2: both package roots audit clean and
fast.

**A hypothesis I tested and discarded** rather than shipping: the 400's own
text says *"Invalid package tree, run `npm install`"*, and neither audit step
runs `npm ci` first — so a missing install looked like the obvious cause.
Reproduced the exact CI condition in a scratch dir (package.json +
package-lock.json, no `node_modules`) and `npm audit --audit-level=high`
still returned "found 0 vulnerabilities", exit 0. The missing install is NOT
the cause; the npm version is.

**Fix**: `.github/workflows/ci.yml` — pin `npm install -g npm@11` before the
audit steps in `supply-chain`, and give the `test` job a Setup Node + the same
pin so Checkpoint's shell-out uses a working npm (node 22 still ships npm 10,
so the version pin has to be explicit — setting `node-version` alone would
have left the legacy endpoint in play).

**Explicitly NOT weakened**: `--audit-level=high` is unchanged on both steps,
nothing is `continue-on-error`, and no Checkpoint check was disabled. Both
package roots were verified at 0 vulnerabilities under npm 11 before the pin
landed, so the bump cannot newly-red the build. Pinned to a major rather than
`@latest` so a future npm release cannot change CI behaviour on its own.

Related precedent, deliberately NOT followed: the `checkpoint-suppressions`
job already disables `NpmAuditCheck` outright because it "shell[s] out to
Packagist / the npm registry so an upstream advisory appearing cannot flip it
red on an unrelated commit". Disabling it in `test` too would have been the
easier fix and is arguably redundant with the dedicated `supply-chain` steps —
but it removes a real check, so the version pin was preferred.

### F9 — a Spotify podcast show told the user to connect the wrong platform (W9 finding; corrects my own earlier entry)

**What**: pasting a podcast show URL (`open.spotify.com/show/<id>`) into the
listen pool answers:

> "That looks like a **Spotify** profile, not a single track. Connect
> **Spotify** as a platform to bring its content in automatically, or paste
> one track's link."

But a show is not the music player. The catalog has carried
`spotify_podcasts` as a **distinct brand** since 2026-09-01 — `Brand::make('spotify_podcasts', 'Spotify Podcasts', …)`,
surface `spotify_podcasts.show`, `RoutingClass::Content`, `Shelf::Podcast`,
connectable, with its own detector path-qualified to `/show/<id>` — separate
from `spotify.player`'s artist/user/playlist surface. Connecting "Spotify"
does not bring a show's episodes in, so the one actionable sentence the
refusal gives the person pointed at the wrong platform.

**Root cause**: `MediaPageReader::accountPlatformLabel()` had a single Spotify
arm matching `(artist|show|user|playlist)` and returning the bare string
`'Spotify'` for all four. That arm predates this run; `show` was folded in
with the others when `playlist` joined on 2026-09-03, before
`spotify_podcasts` existed as its own brand.

**How it was found, and my own error**: plan §1b named this as its one
unresolved item — *"check if the existing `spotify` key already handles the
`/show/` path, add a branch only if it doesn't."* W4 never came back to it.
I then closed it myself as "not a gap" (entry above, now marked superseded)
on the strength of `/show/` returning a non-null label. **That was the wrong
test.** The W9 completeness critic flagged it in rounds 1, 2 and 3 and was
right each time: the question was never "is there a label" but "is it the
right brand". Recorded plainly because a critic overruling the person who
dismissed the finding is exactly what the critic stage is for.

**Fix**: `app/Services/Platforms/MediaPageReader.php` — a `/show/` arm
returning `'Spotify Podcasts'`, placed BEFORE the generic arm (which now
matches `(artist|user|playlist)` only, so a show can no longer fall into it).

**Verified**:

```
/show/4rOoJ6Egrf8K2IrywzwOMk           label='Spotify Podcasts'
/intl-de/show/4rOoJ6Egrf8K2IrywzwOMk   label='Spotify Podcasts'
/artist/0OdUWJ0sBjDrqHygGUXeCF         label='Spotify'
/playlist/37i9dQZF1DXcBWIGoYBM5M       label='Spotify'
/user/spotify                          label='Spotify'
/episode/512ojhOuo1ktJprKbVcKyQ        label=NULL   (an item, not an account)
/track/11dFghVXANMlKmJXsNCbNl          label=NULL   (an item, not an account)
```

Added four rows to `MediaItemPoolAddTest`'s grammar matrix (show +
locale-prefixed show, plus playlist and user as the "must still say Spotify"
guards) and updated the two `spotify.show` rows in
`tests/fixtures/Content/item-url-corpus.php`, whose cross-file check caught
the change immediately and correctly — it had pinned the old label.
`MediaItemPoolAddTest` + `ItemUrlCorpusTest` + `MediaScanSeedTest` +
`EventsPoolTest`: **81 passed, 0 failed**. `pint --test` and `phpstan` clean.

### URL-noise robustness sweep of the hand-written arms — CLEAN (135 variants, 0 real divergences)

F2 found two regex bugs in the events block by checking real URL *shapes*.
This checks the complementary axis the plan's W6 required ("URL noise must
never defeat a path match") against the arms **this run added by hand** —
14 events arms and 9 item-URL grammar arms. For each brand's real URL,
generated and re-classified: `?utm_source=…&utm_medium=…`, `?fbclid=…`,
trailing slash, uppercased host, www toggled, and `http://` — asserting the
classification never changes.

**135 variants checked, 1 divergence, and it was the harness's fault, not
the code's**: my variant generator naively prepends `www.` to any host that
lacks it, which for `events.humanitix.com` produces
`www.events.humanitix.com` — a hostname that does not exist. Humanitix's
event arm delegates to `HumanitixScraper::normalizeEventUrl()`, which
deliberately requires the canonical `events.humanitix.com` host and carries
its own `NON_EVENT_SLUGS` denylist; refusing a bogus host is correct. The
brands whose regexes use a `(^|\.)` host prefix (admitone, spotify,
youtube-music…) absorbed the same bogus variant without complaint, which is
why only humanitix surfaced.

Noted as a limitation of the sweep, not of the code: `add_www` is only a
meaningful variant for registrable-domain hosts, not for hosts that are
already a subdomain. Every other dimension applied cleanly to all 23 arms.

---

## Opus review, wave 2 — the generated-column class

Three fixes in one family, all from the W10 adversarial review of this run's
own earlier changes. The family is worth naming because it is invisible on a
green test suite and invisible in code review: `site.platform_connections.
platform` is a **GENERATED** column (`split_part(surface_key,'.',1)` plus the
SPECIAL_TO_LEGACY CASE), so it only ever holds a brand prefix, while
`WebsiteLinkHarvester::classify()` answers a **dotted surface key** for every
catalog-only brand. `where('platform', 'calendly.book')` is not an error — it
is a query that returns nothing, forever, silently.

### F10 — `resolveBookingLink` / `resolveSocialLink` / `applyRemovals` (`bdf6f7087`)

`BuildsAutoSyncFindings::write()` has carried a docblock about exactly this
hazard since Phase 6 and correctly keys on `surface_key`. Its two sibling
lookups in the same trait never got the same fix.

Scope: 23 classify() values are dotted with no legacy slug — the 21 Phase-6
booking brands plus `bluesky.profile` and `deezer.artist`. Proven in a
rolled-back transaction:

```
surface_key=calendly.book    generated platform=calendly   where(platform=surface_key) hits? NO
surface_key=bluesky.profile  generated platform=bluesky    where(platform=surface_key) hits? NO
surface_key=booksy.book      generated platform=booksy     where(platform=surface_key) hits? NO
surface_key=kick.channel     generated platform=kick       where(platform=surface_key) hits? NO
```

booksy and kick are only safe in practice because their *classify() value* is
the legacy slug, not the dotted key.

Consequence, in order of severity: the incumbent connection was **invisible**,
so no Swap conflict was raised and `write()`'s `updateOrCreate` then landed on
that same row and **overwrote the user's live booking URL** with the harvested
one. `wasDisconnected()` missed identically, **resurrecting a connection the
user had removed**.

> This run made it worse before it made it better. F8 (`9ddb303b1`) pointed
> bluesky and deezer at their real surface keys, which moved both from "never
> connects" to "connects, then breaks the single-slot invariant". The review of
> F8 is what found F10.

`applyRemovals` took an **either-match**, not a swap: `remove` is a STORED slug
list (a finding is persisted when raised and applied later), so it now holds
legacy slugs from old findings and dotted keys from new ones — and a slug must
keep matching the generated column, because a multi-surface brand
(`spotify.artist` + `spotify.show`) relies on that breadth.

7 new tests in `AutoSyncSurfaceKeyLookupTest`. **With the fix reverted, 4 fail**
(both conflict cases, both tombstone cases) while the booksy control and the
no-incumbent happy path still pass — which is what says the change is
*equivalent* for legacy slugs rather than merely stricter. Platforms + Routing
+ Unit/Platforms: **3349 passed**.

### F11 — the dead-page title guard (`144050757`)

`MediaPageReader::read()` refuses a title that is the site's own name — a
nonexistent Twitch VOD unfurls as "Twitch". The list named 11 brands by exact
string and had **never learned the nine platforms this run added**.

Probed a well-formed but NONEXISTENT item URL on all 21 platforms
`classifyItem()` can return. Two answered HTTP 200 and minted a pool item onto
the public sitepage:

```
audiomack      "Audiomack - Music platform empowering artists & fans | Audiomack"
youtube-music  "YouTube Music"
```

The Audiomack one is the instructive half: exact-matching slid straight past it
because the site DECORATES its name. The replacement, `isSiteChrome()`, keeps
the global exact-match rule (widened by the nine) and adds a **leading-segment**
rule scoped to the page's **own** platform. Both bounds were measured against
live pages, not guessed:

- **Leading, never trailing.** Beatport's genuine titles end `… | Music &
  Downloads on Beatport`. A suffix rule would have deleted every real Beatport
  track.
- **Own platform, not the global list.** Globally the rule would reject an
  ordinary YouTube video titled "Spotify - Wrapped 2025 Recap".

Re-probed after: all 21 dead URLs return null; real items on
audiomack/beatport/deezer/tidal/soundcloud/youtube/vimeo/dailymotion still read
their true titles. Every og:title in the new tests is the real 2026-09-04
string. Content + Routing: **979 passed**.

### F12 — the sweep for the rest of the family (`2f7f9b14d`)

Rather than assume F10 was the only instance, swept **every** lookup in `app/`
that queries the generated column with a VARIABLE — 10 call sites, three
adversarial lenses each (reachability / consequence / already-correct).

Eight are provably fed a legacy slug (a `Platform` enum case, a literal, a
validated field) and were **deliberately left alone**. Two were not:

- **`LinkInBioImporter:1045`** — the 2026-09-02 fold ("a page of a platform the
  person already has connected is not a link card beside the connection"). It
  never fired for any catalog-only surface, so every re-import published a
  duplicate link card beside the live connection and counted it `noted`.
- **`BuildProgress::platformEntries`** — the signup progress card rendered a
  mark with no handle and no url under it, which is the one thing that method
  exists to prevent.

Both took the either-match. Each has a regression test proven to fail without
the fix **plus a legacy-slug control that passes either way**. Routing +
PreAccount: **798 passed**.

### X5 confirmed green

The npm CI fix (`c0b469768`) passed. Every CI failure in this run traced to
npm's retiring audit endpoint and never to our code — `git log --name-only`
over the whole run shows zero npm files touched.

---

## Opus review, wave 3 — the stale-pin family

Wave 2 was one bug class (a query against a generated column). Wave 3 is a
second, and it is the more dangerous of the two because every instance was
**green**: a test or a list PINNED to a value set that has since moved. Nothing
fails; the gate simply stops asking.

### F14 — four CHECK-domain drifts in the Postgres stand-ins (`3dbbe665b`)

`tests/Postgres/*.php` each hand-write their own scratch DDL, and no gate has
ever compared the VALUE domains: `PostgresLaneDdlDriftTest` checks table and
column NAMES, and its scanner (`PostgresLaneDdlScanner.php:396`) explicitly
skips any line starting `CHECK`.

```
routing.source_intents.state         3 files missing 'verifying'
routing.source_intents.block_reason  the same 3 missing 'not_found', 'sibling_branch'
routing.source_intents band CHECK    absent from all 3
content.item_media.role              9 files missing 'video'
```

Two are more than housekeeping. `sibling_branch` is written by
`SourceReconciler.php:185`, so it was a live `23514` waiting for the next
fixture to reach that branch. And the hand-built `idx_source_intents_live`
predicate was one state short, letting a `verifying` row and a `proposed` row
coexist on one `(user, surface, identifier)` triple — while
`SourceReconciler.php:686-688` states in a comment that the index "guarantees at
most one matching row" immediately before reading `->value('id')` off it. **The
stand-in was contradicting the invariant the file it tests depends on.**

`tests/Pest.php` carried the same index drift — and that is the CHEAP lane, the
one the `verifying` writers actually run against under `LinkVerificationLaneTest`.

The `// Verbatim shape from 20260727120000_routing_schema.sql` comments were true
of the first migration only, and are precisely how three drifts survived. They
now cite each widening migration by file and line.

### F15 — the money ledger's kind sentinel had been disarmed for 11 days (`bbdfab807`)

`ingest.effects` is the charge-once MONEY ledger. `IngestEffectKindDomainTest`
pinned `20260729150002` (`http|actor|api|ai`) while the live domain has been
`20260902010000` (`+vendor`) since 2026-09-02 — the kind all seven
ScrapeCreators connectors write on every eager run.

It passed the entire time, because the hand-written dataset never offered
`'vendor'`, so nothing was ever rejected. That migration documents its own
rollback to the four-value CHECK at `:14-16`; executing it would have left the
suite fully green while every vendor lane raised `23514` in production.

Fixed by repointing the pin AND by adding the source-derived test — scan `app/`
for `->effect('<kind>'` and insert each literal against the applied CHECK. That
second half is the actual repair: nothing previously connected the CHECK to the
code writing into it, which is how a pin sat stale for eleven days.
**Proven red before green** (reverted pin → names the rejected literal).

### F13 — a roster entry that no URL could ever reach (`7fc9b4665`)

`ItemLinkRules::platformForUrl()` matched a host SUFFIX, first-declared-wins.
`music.youtube.com` ends with `.youtube.com`, so every YouTube Music URL
answered `'youtube'` and `youtube-music` — present in `ROSTER['listen']` — was
unreachable. Live on dev today: a `custom_links` item whose URL is a
`music.youtube.com` playlist carries `platform: "youtube"` on the public wire.

Two consequences, the second being the silent-loss shape again: the link is
badged wrong, and `PoolResolver`'s per-platform dedupe (`:2456`) keys on that
value, so on an item already carrying a youtube.com link the YouTube Music one
is **dropped outright**.

Longest-matched-suffix now — order-independent, fixes the class not the pair. A
sweep of every platform pair found this to be the only shadowing case, so
nothing else changes answer. The durable half is `hostShadowing()`, asserted by
a test that checks the invariant that actually matters (the shadowed brand still
wins its own hosts) rather than asserting the list is empty — a brand living on
a subdomain of another brand's domain is a fact about the world, not a defect.

### F16 — a denylist written from imagination (`2a946b02c`)

The megatix bare-root arm separated event slugs from site chrome with a
hand-enumerated denylist that named paths megatix does not serve (`cart`,
`checkout`, `register`) and missed three it does. `/privacy-policy`,
`/terms-conditions` and `/support` each classified as an **event** — and since
this grammar answer is the only server-side gate on the events pool, a legal
page could be added to it.

Enumeration cannot win that: the next `-policy`/`-conditions`/`-centre` variant
is always the unlisted one. Shape carries the weight now — megatix's genuine
root slugs are CamelCase or a single lowercase word (all three confirmed ones:
`SnowMachineQueenstownAud`, `miniraves`, `Restricted`), its chrome is kebab-case
— with the word list kept only for single lowercase words, where shape says
nothing.

Deliberately NOT merged with `HumanitixScraper::NON_EVENT_SLUGS` or
`PastedLinkClassifier::PROFILE_CHROME` despite the family resemblance: those
answer different questions, and the latter holds `events` and `video`, which are
not chrome on a ticketing root. The review recommended sharing them; that
recommendation was checked and rejected, with the reason written into the code.

### Refuted, and worth recording

- **The suggested-task chip** ("add beatport/hypeddit to `ROSTER['listen']`") is
  STALE — both were added earlier in this run (`ItemLinkRules.php:42`).
- **"`probe-urls.php` has no test against `LinkProjector`"** — false. It is
  driven transitively by `CatalogClassificationSweepTest` on every
  `composer test`, with five green assertions.
- **"use `Route::has('platforms.<slug>.connect')`"** (a review recommendation,
  not a finding) — false: only two named routes in the app contain "connect".
  The real predicate is the URI shape `api/platforms/<slug>/connect`, which
  yields 115 slugs.

### Gate

Full `composer test`: **11230 passed, 0 failed** (11 deprecations, 2 skipped).
Postgres lane: **284 passed, 3 skipped**. CI supply-chain green on X6.
