# Platforms uniformity — design

**Status:** specced, not started. W1 lands immediately; W2/W4 execute after the
convergence/pool work and the slice-7 drops.

Answers the brief at `docs/superpowers/plans/2026-08-17-platforms-uniformity-brief.md`
(commit `fc0a72436`), including both of its open questions.

**Backend only.** The dashboard half — per-row table, per-row sheet, the card
roster UI, dropping the `/accounts` refetch — is the dashboard lane's, in
`partna-monorepo/apps/dashboard`. This document defines the wire it consumes.

**Dev only.** Production is out of scope, on the same grounds slice 7 gave: prod
is hundreds of commits behind, its schema diverged from the 2026-07-26 baseline,
and it carries no customer data.

---

## 1. Why this exists

The dashboard's Platforms table is moving to **one row per connection**. Every
row must behave the same way: it has a name, it can be disconnected *as that
row*, and if its platform is a content source it feeds a pool.

Two things stop that today.

**A row has no name.** `RoutingConnectionResource` sends `displayName` — the
*platform's* label from the compiled catalog — and `url`. It never sends
`payload.name`, the name of the actual connected thing. So a Google Business row
for "ST. ALi Coffee Roasters" renders from its URL as `maps.google.com`, and
once rows go per-connection a Shopify store would render as its bare numeric ID.

**43 platforms have no routes of their own.** The registry-driven loop in
`routes/api/platforms.php:274` skips every descriptor whose shape is `Bespoke`
(`:276-277`), on the assumption that a bespoke descriptor has a hand-written
route group elsewhere. For 43 slugs that assumption is false — no group exists,
and every operation is forced through a family controller. Booking and
reservations expose only a family-wide `DELETE /platforms/booking`, so a
row-level Disconnect button fires a family-wide nuke.

The underlying cause is that only 11 of the registry's descriptors declare a
route shape at all (1 `LinkOnly`, 9 `MultiAccount`, 1 `SingleSelection`); the
rest inherit the `Bespoke` default. `PlatformRouteShape::Bespoke` means "routed
somewhere else" and nothing asserts that somewhere exists.

---

## 2. The model — three layers

A slug is "a platform" to the dashboard when all three hold:

| Layer | Test | State |
|---|---|---|
| **A · Storage** | own `surface_key`, own brand, own row(s) | ✅ complete — Phase 6 gave all catalog brands their own surfaces |
| **B · Routes** | `POST /platforms/{slug}/connect` · `DELETE /platforms/{slug}` · `GET /platforms/{slug}/selection` (+ `/accounts` when multi) | ❌ **43 slugs have none — this document's subject** |
| **C · Source** | a `Connector` + a `SourceProvisioner::identifierFor` mapping, feeding a pool | 16 connectors; most brands have none — **and that is correct** |

Layer C is a **property, not a gap**. This is the load-bearing reframing: a
brand without a connector is not broken, it is link-only by design.

---

## 3. Owner rulings — settled, do not re-litigate

Carried from the brief (2026-08-17):

1. **Source iff a connector already exists.** A brand with a `Connector`
   (DoorDash, Uber Eats, Square menus, Fresha, the music/video/event connectors,
   Google Business, Instagram) is a source and feeds its pool. Every other brand
   is **link-only**: connectable, disconnectable, listed, routed — and it sources
   nothing. **No new connectors are written under this design.** Menulog, Bopple,
   Easi, HungryPanda, Square Ordering, and every booking / reservation /
   ticketing / creator brand are link-only. Menulog is the named example; the
   rule is general.
2. **Every brand surface is a platform.** No family-only brands. The families
   (`online-ordering`, `booking`, `reservations`, `events`) survive as the
   *paste-any-URL classifier* that routes to a brand. They stop being the only
   way in or out.
3. **The dashboard renders one row per connection.** `/routing/connections`
   already returns that shape; the dashboard stops collapsing by platform.
   Multi-store platforms become multiple rows and multiple sheets.

Added this session, resolving the brief's two open questions:

4. **The family-wide disconnect stays.** `DELETE /platforms/online-ordering`
   (and its booking / reservations siblings) is **kept** as a deliberate "remove
   all of this kind" affordance, alongside the new per-brand `DELETE`. Two
   endpoints, two meanings, both real. The per-brand route is what a row's
   Disconnect button calls; the family route is what a sheet's "clear all"
   calls. Neither is a fallback for the other.
5. **Link-only brands get cards.** The connect sheet offers a browsable roster
   of brand cards, not just a generic paste-a-URL box. Backend consequences in
   §7 — they are smaller than they look, because the roster endpoint already
   exists.
6. **Derived descriptors, keyed by brand.** The route loop learns about the
   unrouted brands by deriving descriptors from the compiled catalog, not by
   iterating the catalog separately and not by hand-writing 86 declarations.
   Rationale and the slug rule in §6.

---

## 4. W1 — `RoutingConnectionResource` emits `name`

**Land now, on `development`.** Independent of everything else here and of
slice 7 — it reads no table on any drop list.

`app/Http/Resources/Routing/RoutingConnectionResource.php`, one key added to
`toArray()`:

```php
'name' => is_string($this->payload['name'] ?? null) ? $this->payload['name'] : null,
```

Nullable by construction, matching the existing `url` key immediately above it,
which uses the same guard for the same reason.

**Why it is safe:** every connect strategy the brief audited writes
`payload.name` — Places, Shopify, Fresha, the reservation providers, and
LinkRouter's cards. Where one doesn't, the field is `null` and the dashboard
keeps its current hostname fallback. No schema, no migration, no behaviour
change for any existing consumer: this is a purely additive response key.

**Test:** extend the existing `RoutingConnectionResource` coverage with three
cases — payload carries a string name (emitted), payload has no `name` key
(null), payload's `name` is a non-string such as an int or array (null, not a
cast). The third case is the one that matters; `payload` is a jsonb column and
nothing constrains its shape.

**Downstream, not ours:** the dashboard can then drop the 6–8 per-platform
`/accounts` requests it fires on Platforms-page mount purely to learn names.

---

## 5. W4 — `nowbookit` / `opentable` / `resdiary`: a naming defect, not a wiring one

The brief flags these three as `shape = MultiAccount` with
`multiAccount() = false`, and recommends reclassifying them to
`SingleSelection`. **That recommendation is wrong and must not be followed.**

Verified 2026-08-17 at `app/Providers/PlatformRegistryServiceProvider.php:628-630`:

```php
$r->get('nowbookit')->routes(PlatformRouteShape::MultiAccount, null, false);
$r->get('resdiary')->routes(PlatformRouteShape::MultiAccount, null, false);
$r->get('opentable')->routes(PlatformRouteShape::MultiAccount, null, false);
```

The middle argument is the connect controller and it is **null** — the file's own
comment at `:614-615` says these are "fully registry-driven (FOUND-24) — null
controller routes". `SingleSelection`'s branch
(`routes/api/platforms.php:288-292`) does `$controller =
$descriptor->connectController()` and wires `selection` + `forget` to it.
Reclassifying would route both endpoints to **null**.

**The current wiring is already correct.** `MultiAccount` with
`multiAccount() = false` emits connect + selection + forget on
`GenericPlatformController` and no `/accounts` routes — exactly what a
single-account, registry-driven reservation provider needs. Only the enum case's
*name* misdescribes it.

**Change:** none to the descriptors. Instead, the durable fix is a constraint on
W2:

> **W2's per-row logic branches on `multiAccount()` — a declared capability —
> never on the route shape.**

Shape names describe how routes are *emitted*; capability flags describe what the
platform *is*. Branching on the former is what made this look like a defect.
Record the constraint in W2's plan and add a one-line comment at
`PlatformRouteShape::MultiAccount` noting that the case name describes the
emission pattern, not an account cardinality.

Optional and low-value: rename the case to something like `GenericDriven`. It
touches every declaration site for no behavioural gain — do it only if W2's
author finds the name actively misleading while working in the loop.

---

## 6. W2 — brand surfaces get the platform route contract

The real piece. Its own branch, its own plan, sequenced after the slice-7 drops.

### 6.1 Approach — derive descriptors from the compiled catalog

At registry boot, every catalog surface that (a) carries a `Detector::url`,
(b) is connectable, and (c) has no hand-written descriptor, gets a **synthesised
`PlatformDescriptor`** carrying a new route shape. `PlatformRegistry::all()`
returns hand-written ∪ derived and remains the single answer to "what can a user
connect."

Rejected alternatives, recorded so they are not revisited:

- **A second loop over `CompiledCatalog::surfaces()` in the route file.** Less
  machinery, but it creates two rosters. `PlatformRegistry::all()` stops being
  the truth the dashboard types its connect roster off, and every future
  consumer has to know which list to ask.
- **86 hand-written descriptors.** Explicit and greppable, and wrong the first
  time someone adds a brand to the catalog without remembering the registry.

Derivation also fixes an existing anomaly for free: `doordash`, `uber_eats` and
`menulog` are catalog surfaces that are *not registry slugs at all*. Under
derivation they become slugs because they are surfaces, not because someone
remembered them.

### 6.2 The slug rule

Registry slugs are brand-shaped (`booksy`, `google-business`); catalog keys are
surface-shaped (`booksy.book`). The mapping is safe because the ratio is 1:1
almost everywhere.

**Verified 2026-08-17:** of 110 files in `app/Catalog/Definitions/`, exactly
**two** declare more than one surface — `Square` (`square.book` +
`square.order`) and `Bandcamp` (`bandcamp.artist` + `bandcamp.store`).

**Rule:** derive one descriptor per brand that has **exactly one** connectable
URL-detected surface, keyed by the brand key. A brand with two or more
connectable surfaces is **not** derived and must carry a hand-written
descriptor.

Both current exceptions are already handled: Bandcamp has a real connector and a
hand-written descriptor; Square's `square.order` is `notConnectable()` and stays
so, because the `*.square.site` misclassification is out of scope (§8).

**Pin the rule with a test.** A third multi-surface brand would otherwise
silently lose its routes — the derivation would skip it and nothing would
notice. The test asserts that every connectable URL-detected surface is reachable
either through a derived descriptor or a hand-written one, with no third
category.

### 6.3 The new route shape

Add `PlatformRouteShape::Brand`. Widening `LinkOnly` was considered and
rejected: `LinkOnly`'s docblock states it is for link-only *socials* on
`GenericPlatformController`, and the derived brands need a connect path that
validates against a specific brand detector, which `LinkOnly` does not.

Emitted routes, per derived slug:

- **`POST /platforms/{slug}/connect`** — accepts `{url}`. Validates the URL
  matches **this brand's** detector, then delegates the write to `LinkRouter`,
  which already writes per-brand and already enforces one-store-per-brand.
- **`GET /platforms/{slug}/selection`** and **`DELETE /platforms/{slug}`** — via
  `GenericPlatformController` with `->defaults('platform', $slug)`, exactly as
  `LinkOnly` does today.
- **`/accounts` + `DELETE /accounts/{id}`** — only where the brand's manifest
  permits more than one store. `GenericPlatformController::removeAccount`
  already keys on `resource_id`; reuse it unchanged.

### 6.4 The detector-match guard

`POST /platforms/menulog/connect` with a DoorDash URL must be **rejected**, not
silently routed to DoorDash. This is the one genuinely new piece of logic in W2
and it is the difference between per-brand routes and a differently-spelled
family classifier.

The guard runs *before* `LinkRouter`: resolve the brand's detector from the
compiled catalog, match the submitted URL, and 422 on a miss with a message that
names the brand the URL actually belongs to when the classifier can tell.

### 6.5 Capability gating

`platform.available` gates per slug off `AccountCapabilities`, keyed by the
surface's routing class:

| Routing class | Capability |
|---|---|
| Ordering | `can_use_online_ordering` |
| Booking | `can_use_booking` |
| Reservations | `can_use_reservations` |
| Social / creator / content | none |

Derived descriptors must carry this mapping, or a link-only booking brand
becomes connectable to an account that cannot use booking. **Run the
`account-capability-audit` skill over the result** — this is exactly the "new
endpoint, forgot the capability gate" case it exists to catch.

Note the mapping is derivable from `routing_class`, which is already on every
surface — no per-brand table needed.

### 6.6 Boot-time constraints

The derivation runs at boot, on every request. Two hard rules, both inherited
from comments already in `routes/api/platforms.php`:

- **Never resolve a lazy strategy factory during derivation.** The existing loop
  is careful to read declared flags (`supportsDeferredConnect()`,
  `multiAccount()`, `hasHighlights()`) rather than call `connectStrategy()`,
  because resolving the factory at boot bakes a real scraper into the descriptor
  before any test can mock it. Derived descriptors declare no strategies, so this
  is a constraint on how they are *built*, not on what they carry.
- **Read only the compiled artefact.** Derivation is affordable because
  `CompiledCatalog` is a static compiled array with a digest, not 110 class
  reflections. It must never fall back to reflecting `Definitions/`.

### 6.7 Connectability flips

46 of the 110 definition files call `notConnectable()`, and they include most of
the brands this design makes connectable: Resy, Tock, Sevenrooms, Tablecheck,
Quandoo, Vagaro, Timely, Phorest, Mindbody, Zenoti, Ovatu, Shortcuts, Ticketek,
Oztix, Trybooking, Ticketmaster, ResidentAdvisor, Patreon, Substack, Mixcloud,
Tidal, WhatsApp.

Each flip is a one-line edit in the definition, then `php artisan
catalog:compile` → `php artisan routing:corpus` → `./vendor/bin/pint` on the
generated artefacts (without the pint run the diff is thousands of
formatting-only lines).

**Some stay false, correctly:** `Partna.php`, `GenericStore.php`,
`DirectBooking.php` and `OrderOnline.php` are pseudo-surfaces, not brands.
`Square`'s `square.order` stays false per §6.2. Produce the explicit keep/flip
list during planning and have it signed off — this is the one part of W2 where a
wrong call is user-visible rather than merely internal.

**`notConnectable()` is currently display metadata only** — read by
`CatalogSurfacesController`, never by routing. W2 makes it honest: after this
work, `isConnectable` and "has connect routes" mean the same thing. That
equivalence is the seam §7 depends on.

### 6.8 Exit criteria

- `DELETE /platforms/doordash` disconnects DoorDash. `DELETE /platforms/booksy`
  disconnects Booksy.
- The dashboard's per-key disconnect is correct for **every** row
  `/routing/connections` returns, with zero special-casing. Apple's `/apple/…`
  prefix remains the one mapped exception.
- `RegistryConnectCoverageTest` and `PlatformControllerConvergenceTest` extended
  to assert every connectable URL-detected brand has all four routes.
- Family controllers unchanged in behaviour: `POST /online-ordering/entries`
  still classifies-then-writes, and now lands on the same row that
  `POST /doordash/connect` would.
- The family-wide `DELETE` endpoints still exist and still remove all of their
  class (ruling 4).

**Size: M–L.** No new tables, no teardown. The work is the derivation, the new
shape, the detector-match guard, the capability mapping, the connectability
flips, and the tests.

---

## 7. The card roster — backend side

Ruling 5 asks for browsable brand cards. The backend cost is close to zero,
because the roster endpoint already exists.

`GET /catalog/surfaces` (`CatalogSurfacesController`, wired at
`routes/api/user.php:72`) already returns, per surface: `key`, `brandKey`,
`displayName`, `routingClass`, `shelf`, `identifierKind`, `isConnectable`,
`maxAccounts`, `canonicalUrlTemplate`, `hasEmbed`, `legacyPlatform` — plus a
`brands` map and an ETag derived from the artefact digest, with
`Cache-Control: private, max-age=300`. Its own docblock describes it as "the
dashboard's read of the compiled catalog — surfaces the picker may show."

So the roster needs **no new endpoint**. It needs `isConnectable` to be honest,
which is §6.7 — work W2 owes regardless. The roster and the router read the same
flag, so "which brands get cards" and "which brands get routes" cannot drift
apart by construction.

**The one genuine gap: there is no icon or logo field.** `Brand.php` and
`Surface.php` carry none. Two options, and this is a **dashboard-lane decision**
rather than a backend one:

- The dashboard keeps its own icon map (what it does today, via the
  `lib/social/platforms.ts` mirror `CatalogSurfacesController` was built to
  replace).
- Add an `icon` slot to `Brand` and serve it from the catalog — small change,
  roughly one line per definition, and it re-centralises what the catalog was
  meant to centralise.

Either way someone must source, normalise and license ~86 brand logos. **That is
the real cost of cards, it is a design/assets job, and it is not on the backend's
critical path.** Cards can ship progressively as assets arrive; the roster
endpoint does not care.

---

## 8. Explicitly not in scope

- **New connectors or new sources.** Ruling 1. Menulog and its kind stay
  link-only.
- **Anything slice 7 touches.** None of W1/W2/W4 read or write the drop-list
  tables. W1 is safe on `development` today; W2 competes with the drops for the
  same head, not the same tables, and is sequenced after them for that reason.
- **The known Phase 6 defect** — `*.square.site` classifying as booking when it
  is Square Online, not Square Appointments. Named in the Phase 6 checkpoint;
  separate work.
- **The dashboard half** — per-row table, per-row sheet, disconnect-by-shape,
  the card grid, dropping the `/accounts` refetch. It needs W1 complete and W2
  correct, and it can ship the table shape before W2 using a shape-mapped
  disconnect as a bridge.
- **Production.** Dev only.

---

## 9. Sequencing

1. **W1** — now, on `development`. One key plus a resource test.
2. **W4** — with W1: a one-line comment on `PlatformRouteShape::MultiAccount`.
   The descriptor change the brief asked for is **cancelled** (§5); what carries
   forward is the branch-on-capability constraint, which is W2's to honour.
3. **W2** — its own branch and its own plan in the house format, after the
   slice-7 drops land and after the pool/convergence work the owner is
   currently running. Rulings 1–6 are its charter.
4. **Cards** — dashboard lane, gated on §6.7's connectability flips and on brand
   assets. Not backend-blocking.

W3 from the brief — interim per-row `DELETE /booking/{resourceId}` and
`DELETE /reservations/{resourceId}` — is **dropped**. It existed only as a hedge
against W2 slipping past the dashboard's per-row table. The dashboard can bridge
with a shape-mapped disconnect instead (§8), so building a throwaway endpoint
pair is not worth it. Reinstate only if the dashboard lane says the bridge is
unworkable.

---

## 10. Risks

**A third multi-surface brand appears.** The §6.2 derivation would skip it
silently and it would lose its routes. Mitigated by the §6.2 test, which is the
reason that test exists rather than a nicety.

**Boot cost.** Derivation runs per request. It reads a compiled array and builds
plain descriptors, so it should be negligible — but measure it during
implementation rather than assuming, because the route file already carries two
loops and this adds a third pass over ~86 entries.

**A connectability flip that shouldn't have happened.** A brand made connectable
whose connect flow does not actually work is worse than one that was never
offered — it is a card the user taps into a dead end. §6.7's explicit keep/flip
list, signed off before implementation, is the control.

**Capability-gate omission.** A derived descriptor missing its routing-class
capability mapping opens a booking brand to an account without booking. The
`account-capability-audit` skill run in §6.5 is the control, and it is not
optional.
