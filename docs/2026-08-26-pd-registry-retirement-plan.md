# Plan: PD-registry retirement (hand-written descriptors → catalog-derived)

Date: 2026-08-26. Status: READY — executes automatically after plan 1
(`docs/2026-08-26-menu-item-deep-links-and-cleanup-plan.md`) AND plan 3
(`docs/2026-08-26-routing-fixes-plan.md`) complete, in that order (owner
directive). Execution starts only on the owner's explicit go signal. Same EXECUTION METHOD as that plan's method section (inline,
full permission to go live, push as you go, fix-everything-found via todos,
hard gates with fresh-eyes Sonnet critics, unlimited scrape budget,
checkpoints into this doc).

## The correct end-state (research finding, 2026-08-26)

The registry itself is NOT deleted. Every consumer (route emission, connect
requests, policy, availability meta, refresh cron, resources, payload
validation) needs a PlatformDescriptor at runtime — including for Uber
Eats/DoorDash, which get DERIVED descriptors from the catalog at boot
(`DerivedDescriptorFactory::build()`, registered in
`PlatformRegistryServiceProvider.php:378-384`). Retirement therefore means:

> **Zero hand-written `PD::make()`/`PD::linkOnly()`/`PD::oEmbed()` entries.
> Every descriptor is derived from a catalog definition; bespoke behaviour
> (normalizers, fetch strategies, resources, payloads, refresh wiring) is
> declared ON or ATTACHED FROM the catalog definition, not in a monolithic
> provider.** `PlatformRegistryServiceProvider` shrinks to the singleton
> binding + the derivation pass.

## What the research established (full detail in the inventory below)

`DerivedDescriptorFactory` today derives: key (LegacyPlatformMap), label,
Brand routes/connect (BrandLinkConnect + url input), LinkConnectionResource,
CardPayload, requiresCapability by routing class. It CANNOT yet derive:
**category** (null on every derived descriptor — ActionCandidates falls back
to routing class), bespoke connect strategies/normalizers, payload classes
beyond CardPayload, resource classes beyond LinkConnectionResource, ALL
refresh wiring, displayToggles, complete() predicates, detect(). It skips
shop-provider surfaces and NEVER_UPGRADE=['instagram'], and it already
retro-fits Brand connect onto ~50 hand-written routeless descriptors via
`upgrades()`.

Load-bearing key seams that every deletion must re-verify:
1. `IntegrationsMetaController:72` availability map iterates
   `registry->keys()` — a derived descriptor keeps its key, so keys survive
   IF the derivation covers the slug; verify per batch.
2. `RefreshIntegrationConnectionsCommand:93` `whereNotIn('platform',
   keys())` is an INVERSE filter — losing a key WIDENS the family-fallback
   query. Check each batch doesn't leak rows into it.
3. `PublicIntegrationConnectionResource::ALLOWLIST` and
   `DsarPayloadFilter::DSAR_ALLOWLIST` — slug-keyed consts; a missing key
   FAILS SOFT on public requests (reported exception + empty payload; the
   request succeeds — `PublicAllowlistCoverageTest` is what fails loud;
   corrected by the verification pass). Data tables, not descriptors —
   stay hand-maintained; verify coverage per batch.
4. `Platform` enum (7 values pinned by PlatformEnumSyncTest),
   `core.feature_availability` keys `integration.<slug>`,
   `config('partna.refresh.*')` slug keys, `handWrittenFreeze()` /
   FAMILY_DESCRIPTOR('shop').
5. `site.platform_connections.platform` = LegacyPlatformMap::legacyFor —
   derived slugs match storage by construction; every migrated platform's
   catalog surface MUST carry the right legacyPlatform mapping.

## Phases

### P0 — Foundational factory gaps (unblocks everything)

- **P0.0 THE FREEZE BLOCKER (found by verification pass 2026-08-26 — the
  plan cannot execute without this).** `PlatformRegistry::handWrittenFreeze()`
  = `array_keys(LegacyPlatformMap::toSurfaceMap())` — currently **72
  slugs, including every P1/P2 target** (71 catalog definitions call
  `->legacyPlatform()` even when redundant). `DerivedDescriptorFactory::
  build()` SKIPS every frozen slug, so deleting a hand-written entry does
  NOT get back a derived descriptor — the platform vanishes from routes,
  availability, everything. AND `RegistryCoverageTest` asserts every
  frozen slug is a registry key — it fails on the first deletion.
  Fix in P0: change `build()`'s skip condition to "frozen AND still
  hand-written" (a deleted hand-written entry becomes derivable), and
  rewrite `RegistryCoverageTest` to assert the INVARIANT (every legacy-map
  slug resolves to SOME descriptor — hand-written or derived) rather than
  hand-written existence. Verify with the registry-diff harness that
  flipping the condition alone changes nothing while all hand-written
  entries still exist.
- **P0.1 Derive `category`.** Add a shelf/routing-class → Cat mapping in
  DerivedDescriptorFactory (catalog surfaces already carry Shelf + routing
  class). Verify ActionCandidates output unchanged for currently-derived
  brands, then that it becomes CORRECT (not fallback) for them.
- **P0.2 Payload seam.** Let a catalog definition (or a per-brand
  attachment map in the factory) name a payload class; default stays
  CardPayload.
- **P0.3 Resource seam.** Same for resource classes; default
  LinkConnectionResource.
- **P0.4 Connect-strategy seam.** Same for connect strategies (normalizer
  + frozen 422 copy); default BrandLinkConnect. The normalizer classes
  themselves are kept — only their REGISTRATION moves.
- **P0.5 Delete `ProviderDetector`** — production-dead (only two tests
  reference it); update/retire those tests. Evidence in inventory §1.
- GATE P0: typecheck/tests green; a diff-of-registry harness (dump every
  descriptor's observable fields before/after boot change; byte-identical
  for untouched slugs) — this harness is then reused at every later gate.

### P1 — Group (a): ~28 detect-only cards (biggest single win)

booksy, vagaro, timely, kitomba, phorest, shortcuts, bella-booking, resy,
quandoo, ticketek, oztix, trybooking, resident-advisor, ticketmaster,
bopple*, square-ordering*, boulevard, glossgenius, mangomint, zenoti,
mindbody, ovatu, sevenrooms, tock, tablecheck, hungrypanda*, easi*
(*already deleted by plan 1 Part C — reconcile: whichever plan reaches them
first deletes them; this phase re-verifies).

Their only unique content is category (P0.1 solves) + label casing +
production-dead detect(). Ensure each catalog definition exists with the
right legacyPlatform + display name; delete the hand-written entries;
verify with the registry-diff harness + dashboard tile spot-checks.

P1 preconditions (verification pass): P0.0 must be done (all 27 slugs are
in the frozen set); `square-ordering`'s surface is `notConnectable()` and
`candidates()` filters non-connectable surfaces — plan 1's A1 flips it, so
by the time this plan runs it should be connectable; VERIFY that before
deleting, and check the same connectability question for every other slug
in the batch (a notConnectable catalog surface derives nothing — the
detect-only card entries may need their surfaces marked connectable or
explicitly accepted as intentionally-dropped platforms; decide per slug
with evidence, never silently).

### P2 — Group (b): link-only socials (25)

- The 22 strategy-less linkOnly slugs (whatsapp, substack, patreon, ko-fi,
  buymeacoffee, github, gitlab, codepen, dribbble, behance, gumroad, + the
  socials without bespoke normalizers): payload contract is
  {username,url} vs CardPayload — P0.2 seam carries LinkPayload/
  LinkConnectionResource equivalents so EXISTING connections keep their
  read shape. Verify a live social connection renders identically
  before/after.
- The 14 with bespoke normalizers (x, linkedin, threads, reddit, tiktok,
  facebook, snapchat, discord, telegram, kick, medium, skool, strava,
  twitch): attach via P0.4; the 422 wording must stay byte-identical
  (tests pin it).
- skool's SingleSelection connectStatus + route: migrate or explicitly
  carve out to P5.

### P3 — Group (c): mixcloud, tidal

EmbedPayload + non-refreshable via the seams. Small.

### P4 — Group (e): refreshable content platforms

spotify, soundcloud, youtube, youtube-music, vimeo, bandcamp, apple-music,
apple-podcast, eventbrite, humanitix, google-business. Factory gains
refresh wiring derivation (refreshable, refreshEvery, fetch strategy,
deferredConnect, connectFetch/-Error, displayToggles) as attachments.
Migrate ONE platform first (vimeo — simplest), run its cron refresh live on
dev, then batch the rest one at a time. google-business waits for P5 (also
group f).

### P5 — Group (d)+(f): bespoke controllers, one per unit

fresha; square (booking, TileConnectionResource + SquareController);
opentable/resdiary/nowbookit (SelectionPayload, ServiceMatch, suggestion
route); google-business; apple-music/apple-podcast controller routes;
shop (family descriptor + ShopFetch + complete()); instagram LAST
(NEVER_UPGRADE — decide whether the guard moves to the catalog or the
platform stays the one deliberate carve-out; if carved out, document it as
the single remaining hand-written descriptor and why).

Each: move its strategies/resources/payloads to attachments, delete the
hand-written entry, live-verify connect + refresh + dashboard + sitepage
for a real connection on dev.

### P6 — Teardown + freeze

- `handWrittenFreeze()` list shrinks to whatever P5 deliberately kept
  (target: empty, or instagram only).
- Delete `upgrades()` if nothing hand-written remains to upgrade.
- Provider file reduced to singleton + derivation + attachments; doc sweep
  (this file, convergence-phases doc, catalog README).
- Final regression: every platform in the connect sheet connectable-or-
  correctly-gated on dev; availability map diff empty vs pre-run snapshot;
  public + DSAR resource requests for one connection of each payload class.

## Gates

Every phase gate: typecheck + lint + full test suite green — with THREE
named load-bearing suites explicitly run and green:
`RegistryCoverageTest` (rewritten in P0.0), `RegistryConnectCoverageTest`,
`PublicAllowlistCoverageTest` (+ `PlatformEnumSyncTest` for P5 batches
touching the 7 enum slugs); the registry-diff harness shows ONLY intended
changes; live dev verification named in the phase; fresh-eyes Sonnet
critic on the diff; checkpoint written here. Never advance past a failing
gate.

Additional verified notes (2026-08-26 pass): staff feature-availability
endpoints gate on `registry->has()` — an existing `feature_availability`
row for a vanished slug becomes untoggleable; sweep those rows per batch.
Orphaned `config('partna.refresh.*')` slug keys are silently unused, not
breaking — clean them per batch anyway. LINE-NUMBER CITATIONS in this doc
have drifted (provider derivation loop is now ~:664-710, factory anchors
off by 4–14 lines) — re-grep before using any as an edit anchor.

## Appendix: research inventory (2026-08-26)

Consumers, factory capabilities/gaps, and load-bearing key seams as
reported by the read-only sweep — kept verbatim-in-substance above (§ The
correct end-state, § What the research established). Key file anchors:
provider `app/Providers/PlatformRegistryServiceProvider.php` (hand-written
entries :114-460, derivation :378-424, refresh intervals :614-624);
factory `app/Services/Platforms/Registry/DerivedDescriptorFactory.php`
(descriptorFor :246-291, upgrades :186-207, NEVER_UPGRADE :88);
generic connect `GenericPlatformController` (:73-244), request rules
`ResolvesConnectRules` (:26-68); availability
`IntegrationsMetaController` (:70-75); refresh cron
`RefreshIntegrationConnectionsCommand` (:31-93); allowlists
`PublicIntegrationConnectionResource::ALLOWLIST`,
`DsarPayloadFilter::DSAR_ALLOWLIST`; enum `Registry/Platform.php` (:13-21).

---
# RUN CHECKPOINTS

**P0 + P1 SHIPPED — 2026-08-27 ~09:30 AEST.** P0.0 (freeze fix) and the
four ordering retirements were banked by plan 1. This session: P0.1
category derivation (routing class + shelf → Cat; verified live — the
four retirees regained 'online-ordering'); the registry-diff harness
(`php artisan registry:dump`, stable JSON of every observable descriptor
field); P0.5 CORRECTED — ProviderDetector is NOT production-dead
(GoogleBusinessAutoSync:374 uses detectFor('booking') for the
fresha/square special-cases; deletion deferred to P5's google-business
unit; the critic's zero-references claim was wrong). P1: all 23
remaining detect-only card entries deleted; harness diff shows EXACTLY
23 changes — derived:true + has_detect:false, three labels IMPROVED to
catalog casing (GlossGenius/SevenRooms/TableCheck vs the old ucfirst
hack), zero missing/added slugs, every other field byte-identical.
Safety trace: their PD detectors fed only ProviderDetector, whose only
load-bearing answers are fresha/square (still hand-written); every other
brand classifies via WebsiteLinkHarvester + LegacyPlatformMap.
RegistryCoverageTest's retired list now spans all 27. Golden master
unchanged; registry + golden suites green; full suite green (exit 0).
Hand-written entries remaining: ~40 (P2 socials next, then P3, P4, P5).
