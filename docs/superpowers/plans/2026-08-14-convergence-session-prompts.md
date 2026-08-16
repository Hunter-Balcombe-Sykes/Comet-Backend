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
6. `phase-6-pseudo-platforms` (PARTIAL — finished in 6b)
6b. `phase-6-finish` ✅ DONE 2026-08-16 — checkpoint §26. 0 live partna.*
    connections remain; the guard refuses new ones.
7. `slice-7-teardown`
7b. `programme-review` (added 2026-08-16 — whole-programme verification gate;
    phase-8-docs may not start until it reports PASS)
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

## 4b — menu-actor-driver ✅ DONE 2026-08-16 — checkpoint §25

**Landed.** `MenuActorDriver` is wired for `('actor', 'menu')` and merged to
`development` (`310100093`, `42c2306e0`); a DoorDash menu runs end to end and 30
connector-sourced `menu_item` rows serve off `dev-api.partna.au`. Full record in
convergence-design **§25**; **§23.6 is resolved**. Do not re-run this prompt.

Three things worth carrying rather than rediscovering:

- **F30 was already clear** when this ran — the cheap-actor probe answered 201.
  The gate below was correct to exist and correct to lift; see
  `reference_apify_x402_402_was_transient_not_billing`.
- **The prompt's "fold the three `*MenuDriver` mappers in as adapters" rested on
  a false premise.** They are live on the legacy `MenuFetchJob` lane, and their
  mapping was already ported into the connectors by slice 4. The driver ships no
  adapters; §25.1 says why.
- **Still unexercised, and needing an owner call rather than code:**
  cross-platform menu identity and §8.3's hard-delete of uncurated losers. Only
  DoorDash can be scraped on dev — Uber Eats hits a deterministic bot wall
  (`def.uber.com/en/challenge`) and Square still holds the `some-store`
  placeholder. One platform cannot merge with itself (§25.6).

<details>
<summary>Original gating note and prompt, kept for the record</summary>

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

</details>

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

## 6 — phase-6-pseudo-platforms ⚠️ PARTIAL 2026-08-16 — units 1-3 + custom links merged (`2e38cdb25`); finish in 6b

```
NOTE FROM PHASE 4 (2026-08-16, spec §24). spotify and soundcloud are no longer
keyless oEmbed — they are Apify-actor `track` producers at CostClass::Actor, and
their ingest.sources MUST stay auto_sync=false. If you touch those surfaces, do
not "helpfully" re-enable scheduling: SourceScheduler filters on auto_sync, and
that flag is the only thing standing between a surface change and a billed run
on every cadence. The `channel` kind is gone from KindRegistry (12 kinds now),
its 9 rows deleted, and the DB CHECK deliberately left permissive (F9).

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

## 6b — phase-6-finish ✅ DONE 2026-08-16 — merged `0b8c4e7dd`, checkpoint §26

```
Rename this session to phase-6-finish.

Finish convergence Phase 6. Prompt 6 ran on 2026-08-16 and merged roughly a
third of it to `development` as `2e38cdb25` / `5575adf95`; the rest is yours.
Serial, dev only, prod out of scope.

READ FIRST, in this order — they carry the owner rulings and the measured state,
and re-deriving them costs a day:
  1. docs/superpowers/plans/2026-08-16-phase-6-pseudo-platforms-plan.md
     — its STATUS section is the handoff. Four owner rulings taken 2026-08-16,
       the full 41-row disposition table with each destination verified live,
       and a numbered list of what remains with the trap for each.
  2. The parent spec's §22 (custom links pool) and §23 (slice 4) for context.

ALREADY DONE — do not redo:
- The public-allowlist gap is closed and a live defect fixed with it
  (doordash/menulog/uber_eats/shopify were publishing EMPTY payloads and
  reporting MissingPublicAllowlistException on every public request,
  Nightwatch #436). The coverage guard now iterates CATALOG surfaces, not just
  the frozen 78 registry keys.
- Classification and routing are per-brand. The booking XOR and the reservations
  single-slot clear now key on `routing_class`.
- Custom links are `custom_links` POOL items end to end — LinkPoolWriter /
  LinkPoolReader / EnrichPoolLinkJob, CustomLinksController and
  CustomLinkSeeder both on that lane. `partna.custom_link` is closed.

WORK — the remaining five write paths, then the data:
1. online-ordering, booking, reservations, events-custom stop writing partna.*.
   Unbranded/no-home rows go to the links pool with their provider label
   (owner ruling 2A) — LinkPoolWriter is already built for exactly this.
2. The shop marker splits into ONE CONNECTION PER STORE on shopify.store /
   woocommerce.store (owner ruling 4 / option B). Largest piece; overlaps
   slice 7's scheduled rework of site.shop_brands.
3. A migration command for the 41 rows per the disposition table. Idempotent,
   --dry-run, coverage gate derived twice (the house pattern — once in PHP with
   the app's own hash, once in SQL with pgcrypto).
4. THEN enable IntegrationConnection's retirement guard. The const
   RETIRED_SURFACES is already declared and the throw deliberately deferred:
   turning it on before the paths above land only makes every unmigrated caller
   throw. Observed cost of getting this order wrong: 10 red tests.
5. account-capability-audit skill over everything touched; a test pinning that
   RoutingCapabilityGate keys on routing_class; checkpoint with live SQL into
   the parent spec.

TRAPS, each already paid for once:
- SiteActionsService::pool() keys ordering actions on
  connectionsByPlatform['online-ordering']. Move ordering to brand surfaces
  without moving that and the public "Order online" actions vanish silently.
- Keep ordering resource_id stable (`order-<hash>`): action ids are
  `ordering:<resource_id>` and users store preferences against them.
- LegacyPlatformMap and PlatformRegistry are FROZEN at 78 slugs
  (CatalogLegacyMapTest pins the map pair-for-pair to the 20260727110001
  backfill CASE). New brands are CATALOG-ONLY. Writers pass the SURFACE KEY.
- A pool item needs a section, which hangs off the SITE. A siteless fixture
  user silently gets nothing — this is why ~11 test files needed sites.
- Cross-file test helper names collide at load time and fatal the run.
- `platform` is a GENERATED column; never match a surface key against it.

Autonomy: implement → test → merge to development → deploy dev without sign-off.
Stop ONLY for owner-level decisions, a connection with no home, or gate
contradictions. The four rulings in the plan doc are already given — do not
re-ask them.

EXIT: no write path can create a partna.* connection (guarded by a test); all 41
migrated per the disposition table; nine CI jobs green; checkpoint with live SQL
into the parent spec.
```

---

## 7 — slice-7-teardown

```
NOTE FROM PHASE 4 (2026-08-16, spec §24). A PAID connector lane now exists
(`music`, via MusicActorDriver). Two things this teardown inherits: its sources
must stay auto_sync=false, and SourceProvisioner is now self-healing about that
— it turns the flag off when a manifest becomes paid and corrects cost_units, so
do not re-add a manual reconciliation for it. Also: content.source_items.item_id
is SET NULL, not CASCADE — the only such FK among ~30 into content.items — so any
teardown deleting items must delete source_items FIRST or it leaves orphans with
a null item_id. Phase 4 paid for that once; content:retire-channel-kind encodes
the correct order.

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

## 7b — programme-review

```
Rename this session to programme-review.

You are the whole-programme verification gate for the Content Pool Convergence
programme. Run AFTER slice-7-teardown, BEFORE phase-8-docs. Serial, dev only, prod out
of scope. This session writes no feature code: it verifies, audits and files findings.
Fixes follow scripts/audit/fix-flow.md in their own sessions — the only in-place fixes
permitted here are the opportunistic-P3 rule from CLAUDE.md.

1. CHECKPOINT RE-VERIFICATION (rule zero — trust nothing, cite nothing). For every
   checkpoint in the parent spec — §12–§19, slice 6's inline §7 block, and every section
   the later sessions added (§20+, incl. slices 4/4b, custom-links, phases 4/6, slice 7)
   — re-run the checkpoint's live SQL on dev and confirm each claim still holds. An
   assertion that no longer holds reopens its owning slice: STOP and raise; do not patch
   it here and do not tick anything.

2. LEGACY-ZERO SWEEP. Grep app/, routes/, config/, tests/ for any surviving reader of
   every retired store and lane: site.menu_items, site.services, site.shop_products,
   site.content_selection, site.themes, settings.design.*, profile_fields, the four
   retired review wire keys, designMedia/gallery/siteImages, the demoted connectors
   (twitch/skool/strava/gumroad/substack), the article and channel kinds. A green suite
   is not evidence — read the grep hits. Then the inverse (invariant #2): every kind in
   KindRegistry must have a live writer, a pool (or a recorded exemption like document),
   and a wire read path.

3. ALL TEST LANES, LOCALLY — CI is not your test runner, and composer test alone proves
   little here: composer test (serial, on purpose); pest --parallel --processes=4;
   composer test:pg (tests/Postgres/ — ProjectionWriter changed repeatedly this
   programme); composer test:schema (applied-schema lane — pins the architecture
   constraints composer test never sees); the authz lane WITH Postgres up ("31 skipped"
   reads green but tests nothing).

4. AUDIT PIPELINE — never hand-write findings: scripts/audit/audit.sh --bundle pre-merge
   --changed-since <the commit immediately before slice 0 merged; derive it from the
   parent spec §13 baseline>. If that delta exceeds ~100K tokens, split into targeted
   runs per scripts/audit/campaigns.md (pools/resolver, ingest/projection, migration
   commands, wire resources, the 301 slug lane) — narrow runs find more than sweeps.
   Never run two audit.sh at once.

5. CODE + SECURITY REVIEW: /code-review high over the programme's cumulative diff for
   correctness; /security-review over the new public surface (pools wire, custom_links,
   source_stats, content.item_slugs 301 lane, the storefront/order-platform cards).
   Verify the paid-connector guardrails specifically: every CostClass::Actor source is
   auto_sync=false, and nothing re-enables scheduling on a paid surface.

6. LIVE SURFACE: cloud env:logs partna development --minutes 30; Nightwatch scan for
   anything first-seen since the programme's first merge; spot-check the public wire for
   2–3 real handles — pools present and populated, legacy keys absent, migrated menu
   slugs 301-ing, pools.reviews.stats serving.

7. WIRE MANIFESTS vs REALITY: verify every docs/wire-changes/ manifest against actual
   wire output. These are the frontend rebuild's input — a manifest that misdescribes
   the wire is a P1 finding, not a docs nit.

8. KNOWN OPEN ITEMS — confirm each is still recorded, not silently lost: 4b/Phase 5's
   menu-actor proof if still F30-blocked (Apify payment); LEGAL-2; the RLS
   accepted-posture revisit; Google aggregates cadence; prod reconciliation deferred;
   the two slice-4 product questions if still open.

DELIVERABLE: the audit output folder(s) with CONSOLIDATED.md, plus a review record in
the parent spec ending in ONE of: PASS — phase-8-docs may run; or BLOCKED — a named
list of findings each assigned to its owning slice/session. Do not archive, do not tick
other slices' boxes, do not fix what you find (file it).

Autonomy: verification, audits and filed findings without sign-off. Any reopened
checkpoint, any auth/money finding, any P0: STOP for the owner.
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
