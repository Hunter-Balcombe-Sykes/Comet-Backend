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
