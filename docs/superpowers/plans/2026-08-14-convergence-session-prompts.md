# Convergence — session prompts, in execution order (2026-08-14)

One fresh session per prompt, run **serially** (owner ruling 2026-08-14). Rename each
session as its prompt says. Context passes between sessions through kickoff prompts
and checkpoints, never chat history. Production is out of scope for every session.

**Branching (owner ruling 2026-08-14):** no umbrella branch. Each session starts from the
latest `origin/development`, works on its own short-lived branch (`feat/phase-1-lean-out`,
`feat/slice-4-menus`, …), and merges to `development` when its exit criteria pass — live
verification requires the code deployed, and dev deploys from `development`. Never leave a
session's branch unmerged "for later"; a blocked session raises instead.

**Standing autonomy rule (owner ruling 2026-08-14):** each session implements → tests →
merges to `development` → deploys dev **without waiting for sign-off**, stopping ONLY for:
an owner-level product decision, anything that contradicts a written gate, anything
touching auth/money/prod, or a deletion its plan does not name. Slice 7's gates are
always hard stops.

Order:
1. `phase-1-lean-out` (includes the doc-hygiene pass)
2. `phase-2-identity-keys`
3. `custom-links-pool`
4. `slice-4-menus` (Phase 5's live proof BLOCKED — see 4b)
4b. `menu-actor-driver` (added 2026-08-16 — wires the missing billed-effect
    driver and finishes Phase 5's proof. GATED ON F30: Apify refuses every
    pay-per-event actor account-wide until an owner fixes the payment method)
5. `phase-4-listen`
6. `phase-6-pseudo-platforms`
7. `slice-7-teardown`
8. `phase-8-docs`

---

## 1 — phase-1-lean-out

```
Rename this session to phase-1-lean-out.

You are executing Phase 1 of the Content Pool Convergence programme, plus the doc-hygiene
pass that precedes it. Serial, dev only; production is out of scope and no tool call may
name it.

Read first: docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md §3
(invariants); docs/2026-08-14-convergence-phases.md §1; docs/convergence-log.md (F1–F17);
docs/superpowers/plans/2026-08-14-convergence-session-prompts.md (standing rulings + order).
If the phases/log/scope docs are not yet on development, they live on
origin/feat/platform-pool-convergence — Unit 0 step 1 merges them.

UNIT 0 — doc hygiene (docs only, own commits; commit this prompts file too if untracked):
1. Merge origin/feat/platform-pool-convergence into development (verify docs-only with
   git diff --stat first).
2. Parent spec §4.1: rewrite the status table to reality — 0/0b/1a/1b/2/3a/3b/5a/5b merged
   with checkpoints §12–§19; slice 6 merged, CLOSED and live-verified (checkpoint inline in
   §7, commit e82890b1e); only 4 and 7 not started. Refresh the "unblocked now" line.
3. Slice-7 kickoff: Supabase is PRO (verified 2026-08-14) — keep the pg_dump per-table
   exact-count gate regardless; Gate 2 replaced by the owner override (frontend will be
   rebuilt; designMedia/gallery/siteImages get deleted outright, not dual-served).
4. His RUNBOOK + scope doc, edited IN PLACE (no "correction" appendices): the document
   kind is KEPT (live user-documents feature; no documents pool); the channel KIND retires
   in Phase 4, only its projectors die in Phase 1; the links pool is the CUSTOM LINKS pool —
   the 23 partna.custom_link connections only (not 41), kind `link`, no item_links; the
   product pool's registry key is `shop`, not `sell`; add a W#↔Phase# mapping table to
   docs/convergence-HANDOFF.md.
5. Record the 2026-08-14 owner rulings in the scope doc's decisions section: serial
   execution, one fresh session per slice/phase; the 18 non-custom-link connections take
   the natural mapping (order links → Phase 6 promoted brands, storefronts → shop lane,
   reservations → booking surface); Google aggregates stay on-demand only for the
   remaining 11 sources; the RLS gap on ingest.record_versions_p0–p7 /
   content.storefronts / content.source_stats is ACCEPTED-and-recorded (no access path:
   zero anon/authenticated grants, PostgREST silenced — revisit pre-pilot);
   anseo-studio's Fresha connection is written off (site is 410); slice 7 deletes
   BackfillClaimedGoogleBusinessReviewsCommand.
6. Amend docs/superpowers/plans/2026-08-12-slice-4-menus-KICKOFF-PROMPT.md in place:
   slug migration is collision-free (both tables UNIQUE (user_id, slug); the 9 live
   collisions are all item_type='event', zero menu_item); pool addition is registry-config
   only, template commit 8dd1ff989; slice 2 dual-wrote 11 legacy event slugs → teardown
   deletes them; pool tests must live in tests/Feature/Content/ or
   AuditPipelineIntegrityTest fails the unmapped directory.
7. CLAUDE.md: Supabase plan is Pro (was Free); retire the "prod schema == dev schema"
   claim — prod reconciliation is deferred, separate future work.

UNITS 1–4 — Phase 1 per docs/2026-08-14-convergence-phases.md §1 (TDD, one concern per
commit):
1.1 Constraint migration FIRST and alone: add 'commerce_probe' to the
    routing.source_intents origin CHECK. The constraint is inline/unnamed in
    supabase/migrations/20260727120000_routing_schema.sql — resolve its actual
    auto-generated name on dev before writing the DROP CONSTRAINT. Live insert gate on dev.
1.2 Demote twitch/skool/strava/gumroad/substack to link-only: delete each connector, its
    ConnectorRegistry/ProjectorRegistry entries, SourceProvisioner::identifierFor()
    branch, PlatformRegistry fetch wiring; delete ChannelCardProjector + TwitchVodProjector
    and their tests. content.f_channel stays at 9 — the channel KIND survives to Phase 4.
1.3 Retire the `article` kind from KindRegistry and delete its 1 orphan row. Do NOT
    narrow the DB CHECK (ContentKindDomainParityTest reads two named migration files and
    would go silently stale — F9).
1.4 Delete the vestigial profile_fields seam from the fresha/google_business/instagram
    connector manifests, profileMessages(), StreamSpec targets; retire the dead
    ingest.streams rows. IdentitySync and site.workplaces are NOT legacy — untouched.
Do NOT delete the document kind (F8 — live feature behind it).

EXIT: composer test green; a live commerce_probe intent write succeeds on dev; article
absent from KindRegistry with 0 rows; f_channel still 9; no profile_fields target left in
app/. Then: checkpoint with live SQL pasted into the parent spec; all three cache lanes if
any site-facing write path moved; cloud env:logs partna development --minutes 10 plus a
Nightwatch scan.

Autonomy: implement → test → merge to development → deploy dev without sign-off. Stop ONLY
for an owner-level product decision, a contradiction with a written gate, anything touching
auth/money/prod, or a deletion this plan does not name.
```

---

## 2 — phase-2-identity-keys

```
Rename this session to phase-2-identity-keys.

Execute Phase 2 of the Content Pool Convergence programme: extend
ProjectionWriter::writeIdentityKeys() — it currently emits only PlatformObject and
CanonicalUrl, 2 of 17 KeyClass values — per docs/2026-08-14-convergence-phases.md §2 and
convergence-log F1/F2/F10. Serial, dev only, prod out of scope.

Data reality (F10 — re-verify at entry): isrc/gtin/feed-guid/enclosure have ZERO source
data today; TitleDuration is track-scoped and 0 track items exist. Emit every class where
the projection carries the data, respecting each class's appliesTo()/minLength()/
canonicalise() — but the exit criterion is >2 key classes live on dev via ContentDigest
plus corroborating keys, NOT the presence of Isrc. Do not judge the phase failed for
absent-by-data keys.

TDD: tests/Unit/Ingest/IdentityKeyEmissionTest.php per phases §2.2, including the no-data
paths. This touches app/Ingest/Projection/ProjectionWriter.php, so running
tests/Postgres/ (composer test:pg) is MANDATORY — a green SQLite suite says nothing.

Live gate: ingest:project --dry-run first, then --rebuild. Every content.item_merges row
must be individually inspected and defensible — mergeInto() HARD-DELETES uncurated
merged-away items. Reconcile the content.items count drop exactly. If any merge looks
wrong: HALT.

EXIT: suite + PG lane green; >2 key classes on dev; merges reconciled row-by-row.
Checkpoint with live SQL into the parent spec; edit the slice-4 kickoff in place if this
phase moved any premise it relies on.

Autonomy: implement → test → merge to development → deploy dev without sign-off. Stop ONLY
for owner-level decisions, gate contradictions, auth/money/prod, or any merge you cannot
defend row-by-row.
```

---

## 3 — custom-links-pool

```
Rename this session to custom-links-pool.

Build the custom links pool (owner rulings 2026-08-14, recorded in the scope doc):
registry key `custom_links`, kind `link`, fed by the 23 partna.custom_link connections
ONLY — the other 18 pseudo-platform connections (order links, storefronts, reservations)
do NOT migrate here; they keep their own lanes until Phase 6. Link items do NOT carry
item_links. Serial, dev only, prod out of scope.

Template: commit 8dd1ff989 (the reviews pool — PoolRegistry config plus one test). Pool
tests live in tests/Feature/Content/. Not in LATEST_TAG_POOLS. PoolRegistry pins "a kind
belongs to at most ONE pool" — respect it. Section shape: the settled convention, bare
['op' => 'kind_is'].

Data migration is production code, not a script (invariant #4): app/Services/Migration/,
an artisan command with --dry-run, idempotent — turning the 23 connections' links into
content.items kind='link' via the manual write lane's conventions (coord
manual:{sha1(url)}, NOT a legacy uuid). A kind is not adopted until something reads it:
projector-or-writer, pool entry and read path land together (invariant #2).

EXIT: pool returns the migrated links via the public wire and /content/pools/custom_links;
live count assertion (expect 23-connection coverage, coord coverage not row equality);
wire manifest in docs/wire-changes/; checkpoint with live SQL into the parent spec; all
three cache lanes.

Autonomy: implement → test → merge to development → deploy dev without sign-off. Stop ONLY
for owner-level decisions or gate contradictions.
```

---

## 4 — slice-4-menus

```
Rename this session to slice-4-menus.

Execute docs/superpowers/plans/2026-08-12-slice-4-menus-KICKOFF-PROMPT.md in full. It was
amended 2026-08-14 with the convergence findings — trust the file as written NOW, and
re-derive every figure from dev per its rule zero (including reconciling the 370-vs-318
menu_items history at the entry gate).

Folded in from the convergence plan, this slice also owns Phase 5's live proof: provision
uber_eats/doordash/square via ingest:backfill-sources, dispatch, project; gate =
content.source_items kind='menu_item' > 0 AND the menu pool returning them on the wire;
Apify spend capped at US$18 — re-check remaining credit before spending; exceeding the cap
is a STOP, not a spend.

**BLOCKED, established 2026-08-16 by running it (spec §23.6). This gate was never
achievable and needs its own work first.** The three menu connectors declare a billed
effect of kind `actor`, and `BilledEffectDriverRegistry` (an explicit list in
AppServiceProvider) holds only PlacesDetailsDriver and InstagramActorDriver — its own
comment already noted that the menu connectors "have no driver, which is why they keep
hitting HttpIo's throw". Every run dies in HttpIo:158 before any third-party call, so
nothing can be spent and nothing can land. Writing a menu actor driver against the three
actors config('partna.menu.platforms') names is the prerequisite; it is slice-sized and
unscoped. Sources ARE provisioned on dev (auto_sync=false, so nothing runs them), two of
them now pointing at real stores.

Autonomy: implement → test → merge to development → deploy dev without sign-off, with two
exceptions: (a) the kickoff's blocker-gate items — the 318-slug 301 lane — get a written
plan inside this session before implementation, then proceed; (b) the two deferred product
questions (where site.menus dining modes and menu_platform_links land; standalone-event
permalinks) STOP for the owner with a concrete recommendation. Serial, dev only, prod out
of scope.
```

---

## 4b — menu-actor-driver (added 2026-08-16; rewritten same day once Phase 4 landed)

**GATED ON F30 — do not start this session until the Apify billing fault is
fixed.** Phase 4 proved that Apify refuses EVERY pay-per-event actor
account-wide with HTTP 402, including `apify~instagram-profile-scraper`, the
actor the live Instagram lane uses. It is not budget (US$26.19 of US$29 remains)
and not the token. Until an owner fixes the Apify payment method, a perfect menu
driver still lands nothing — Phase 5's gate would fail one line later than it
does now. Build this AFTER the 402 clears, not before.

```
Rename this session to menu-actor-driver.

Wire the billed-effect driver the three menu connectors need, then finish Phase 5's
live proof, which slice 4 could not (spec §23.6). Serial, dev only, prod out of scope.

FIRST: confirm F30 is cleared. Run one cheap actor and check it is not 402. If it
still refuses, STOP — nothing below can be proven and you would be building blind.

THE PROBLEM, already diagnosed — do not re-derive it. UberEatsMenuConnector,
DoordashMenuConnector and SquareMenuConnector each call
$io->effect('actor', 'menu', ['actor' => <actor id>, 'input' => [...]]).
Nothing in BilledEffectDriverRegistry supports ('actor','menu'), so every run
dies in App\Ingest\Runtime\HttpIo::runBilledEffect() (:158). Verified by running
it twice on dev, 2026-08-16.

COPY PHASE 4'S SHAPE. It solved this exact problem for music and its docblock
names your wall explicitly. Read first, then mirror:
  app/Ingest/Runtime/Effects/MusicActorDriver.php     (85 lines)
  app/Services/Platforms/Actors/MusicActorAdapter.php (the interface)
  app/Services/Platforms/Actors/SpotifyTracksAdapter.php
The split is: the DRIVER owns budget, token and transport; an ADAPTER owns only
the vendor's input shape and dataset field names. You already have three
per-platform mappers to fold in as adapters — UberEatsMenuDriver,
DoorDashMenuDriver and SquareMenuDriver under app/Services/Platforms/. Register
the new driver in AppServiceProvider's BilledEffectDriverRegistry list (:125).

ORDERING IS LOAD-BEARING (both existing drivers say so): every refusal that can
happen must happen BEFORE the budget claim, or a missing token burns a daily slot
doing nothing.

MONEY SEMANTICS — three outcomes, deliberately not interchangeable:
  - EffectNotAttempted  => nothing left the process. The ledger DELETES the claim,
    so the digest is free to retry. Use for: missing token, unconfigured platform,
    cap reached.
  - noAnswer            => a request went out and did not answer. Row STAYS settled;
    the digest is inert until the freshness bucket rolls. Never cached as truth.
  - answered(null|[])   => the vendor positively said "nothing here" (4xx unknown
    store). Settling ok is what stops re-billing a dead store every run.

BUDGET CAP GOTCHA, straight from MusicActorDriver's docblock: ApifyBudget defaults
a MISSING cap to 0, which denies every claim silently. A 'menu' cap already exists
(config partna.limits.apify.actors, PARTNA_MENU_APIFY_DAILY_CAP, default 300) —
confirm the key you claim under matches it exactly, or the driver will never run
and will look like a 402.

CARRY THIS KNOWLEDGE ACROSS from MenuApifyScraper::attemptScrape() (:428), the
legacy lane's raw POST. It is not the structure to copy — MusicActorDriver is —
but it encodes four things menus specifically need and music did not:
  - 4 retries. These actors are WAF-protected and return EMPTY on a large fraction
    of runs for a perfectly valid open store. A one-shot driver looks broken at
    random.
  - run-sync-get-dataset-items returns 201, and ->ok() only accepts 200.
  - 4xx (unknown store, actor not rented) is HARD; 5xx is retryable.
  - The budget is claimed per REAL actor run, not once per driver call.

FOLD IN THIS DEFECT, found while diagnosing. HttpIo's "no driver wired" throw is a
bare RuntimeException, so the ledger stamps the digest `failed` and SETTLES it,
locking that source+input until the freshness bucket rolls — even though no request
ever left the process. That is exactly EffectNotAttempted's contract. Fix it, pin it
with a test.

THE TRAP THAT WILL WASTE YOUR FIRST RUN. Dev holds two SETTLED `failed` rows in
ingest.effects (cost_tag 'menu', claimed 2026-08-15 23:42:15) from slice 4's
attempts. Charge-once-by-digest REFUSES rather than re-attempts — the second run
slice 4 did produced no new rows at all. Clear those two rows (or wait out the
freshness bucket) or your first run silently does nothing and reads as a broken
driver.

DEV STATE, left ready by slice 4 — verify, do not assume:
  - ingest.sources holds uber_eats / doordash / square for showcase-eats
    (user 68a5efcd-2ba6-432b-9a92-61015c4d688e), auto_sync=false. SourceScheduler
    filters auto_sync=true, so run them with RunSourceJob::dispatchSync(<id>).
  - uber_eats and doordash point at REAL stores (Universal Restaurant, Doc Pizza).
    square still holds a placeholder — do not spend on 'some-store'.
  - Their menu streams read health='unavailable', consecutive_failures=2.
  - MenuItemProjector is at version 2 and already emits the `collections` its
    identity keys need. Do not re-add mapping it already has.

SPEND: whatever remains of the US$18 cap after Phase 4. Re-check first-hand
(GET https://api.apify.com/v2/users/me/limits). Exceeding it is a STOP, not a spend.

EXIT: composer test green, plus tests/Postgres/ if you touch ProjectionWriter. Then
Phase 5's gate: content.source_items kind='menu_item' from a CONNECTOR source > 0,
AND the menus pool returning those items on the wire off dev-api.partna.au. Inspect
every content.item_merges row individually — slice 4 produced zero because no dish
on dev was sold on two platforms, so this is where cross-platform identity gets its
first real exercise and where §8.3's hard-delete of uncurated losers first bites.
Checkpoint with live SQL into the parent spec; correct §23.6 and prompt 4 to say the
driver landed.

Autonomy: implement → test → merge to development → deploy dev without sign-off.
Stop ONLY for: F30 still biting, the spend cap, an owner-level product decision, a
gate contradiction, or anything touching auth/prod.
```

---

## 5 — phase-4-listen

```
Rename this session to phase-4-listen.

Execute Phase 4 per docs/2026-08-14-convergence-phases.md §4 and convergence-log F10.
Serial, dev only, prod out of scope.

Work: provision youtube_music (free keyless RSS — the only real track producer; 0 track
items exist today); select and validate Apify actors for Spotify and SoundCloud track
sourcing, preferring ISRC-returning actors; write the connectors and track projectors;
delete SpotifyChannelProjector and SoundcloudChannelProjector; retire the `channel` kind
HERE — KindRegistry narrowing plus deleting the 9 orphan channel/f_channel rows, but do
NOT narrow the DB CHECK (F9). Apify spend stays within whatever remains of the US$18 cap
after slice 4 — re-check before spending; exceeding the cap is a STOP.

EXIT: track rows exist on dev; cross-platform duplicates merge via the Phase 2 identity
keys; channel absent from KindRegistry with 0 rows; suite green. Checkpoint with live SQL
into the parent spec.

Autonomy: implement → test → merge to development → deploy dev without sign-off. Stop ONLY
for owner-level decisions, the spend cap, or gate contradictions.
```

---

## 6 — phase-6-pseudo-platforms

```
Rename this session to phase-6-pseudo-platforms.

Execute Phase 6 per docs/2026-08-14-convergence-phases.md §6 and scope §1.6/§W6. Serial,
dev only, prod out of scope.

Work: the six pseudo-platform categories (custom, online-ordering, shop, reservations,
booking, events-custom) stop being connectable; PlatformCategory remains as grouping
metadata; promote uber_eats/doordash/menulog to real surfaces.

Two things slice 4 left you (2026-08-16, spec §23):
- The ordering platforms ALREADY have a home on the content side:
  `content.collections` kind `order_platform`, ref `order:{platform}`, with a
  `content.storefronts` sidecar carrying provider + store url. 5 exist on dev.
  Promote into that rather than minting a parallel shape.
- **The slug spellings disagree and you own reconciling them.** The legacy
  `site.menu_platform_links.platform` values (which those refs are built from,
  and which `config('partna.menu.platforms')` keys on) are `uber-eats` and
  `doordash` — HYPHEN. The connection surface keys are `uber_eats.order` and
  `doordash.order` — UNDERSCORE. Slice 4 did not unify them because renaming a
  live ref rewrites every collection's natural key.
- The pool wire publishes `name: null` for these cards on purpose: the label is
  the bare slug, and neither "doordash" nor "uber-eats" title-cases correctly.
  A real display-name vocabulary is yours to mint. The 18 non-custom-link
connections land in their ruled homes (order links → the promoted ordering brands,
storefronts → the shop lane, reservations → the booking surface) — verify each
connection's destination actually exists and functions BEFORE retiring its
connectability; a connection with no live home is a STOP-and-raise, not a write-off.
Verify the claim that RoutingCapabilityGate keys on routing_class so gating does not
weaken — run the account-capability-audit skill against anything touched.

EXIT: no new partna.* connections possible; all 41 existing connections migrated or
explicitly dispositioned in writing; suite green; checkpoint with live SQL into the
parent spec.

Autonomy: implement → test → merge to development → deploy dev without sign-off. Stop ONLY
for owner-level decisions, a connection with no home, or gate contradictions.
```

---

## 7 — slice-7-teardown

```
Rename this session to slice-7-teardown.

Execute docs/superpowers/plans/2026-08-12-slice-7-teardown-KICKOFF-PROMPT.md in full. Its
gates were updated 2026-08-14: Supabase is Pro (daily backups exist — the pg_dump
per-table EXACT-count gate stays mandatory anyway), and Gate 2 was replaced by the owner's
frontend-rebuild override — the legacy wire keys (designMedia/gallery/siteImages and the
rest of slice 1a's compatibility surface) are deleted outright, not dual-served.

Everything else in the kickoff stands and is a HARD STOP, not an edit: re-run every
coverage assertion yourself (rule zero — no slice may cite another's checkpoint); the
pg_dump gate before any DROP — if dumped counts don't exactly match live counts per
table, NOTHING is dropped; the standalone-events four-step before the legacy events wire
dies; the five orphaned observers re-homed with EXPLICIT registration (event discovery is
off — an unregistered listener is silently dead); ShopContentWriter re-homed off the
ShopBrand model in the same window; CloudflarePurgeService::purgeHandle()'s three lookups
repointed; delete BackfillClaimedGoogleBusinessReviewsCommand (owner ruling 2026-08-14).

This slice is IRREVERSIBLE and runs alone, last. Dev only — production teardown is
separate future work; the closing deliverable includes the prod-reconciliation write-up
the kickoff names, plus the programme's closing state in the parent spec and
docs/2026-08-05-platforms-as-sources.md saying what is actually true.

Autonomy: within the gates, proceed to merge + deploy dev without sign-off. Every gate is
a hard stop; anything that contradicts one is STOP AND RAISE, never an edit.
```

---

## 8 — phase-8-docs

```
Rename this session to phase-8-docs.

Execute Phase 8 per docs/2026-08-14-convergence-phases.md §8: the documentation truth
pass. Rewrite the backend CLAUDE.md to the post-convergence reality (flag the root
CLAUDE.md for its owner); correct every remaining stale figure in the parent spec; verify
docs/wire-changes/ carries one manifest per shipped contract change (slice 4,
custom-links, and slice 7 are the new ones); docs/2026-08-05-platforms-as-sources.md's
closing line must state what is actually true.

The wire documentation describes the NEW wire on its own terms — never as a diff from
legacy — because it is the input to the frontend rebuild.

Close the programme: final checkpoint in the parent spec, and a short "state of the
world" summary for the owner covering what shipped, what is deferred (prod
reconciliation, LEGAL-2, RLS revisit, Google aggregates cadence), and where every record
lives.

Autonomy: docs only — proceed to merge without sign-off.
```
