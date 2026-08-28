# Link Classifier Seam Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the boundary between `WebsiteLinkHarvester`'s hand-maintained host
constants and the catalog-driven projector explicit and self-enforcing, and close
the recurring booking/reservations/ordering half of the gap with data rather than
a hand list.

**Architecture:** Option B from the decision doc, plus one bounded promotion.
Three commits: (1) a ratchet test + fixture that fails when a new catalog surface
lands as detect-only without a recorded decision; (2) one authoritative policy
docblock replacing four scattered half-explanations; (3) `classifyFromCatalog()`
returns the real category for `routing_class ∈ {booking, reservations, ordering}`
**and** `is_connectable === true`, and keeps answering `'link'` for everything
else. `social`, `content`, `events` and `shop` are untouched by design — the
decision doc's §A.2 records why.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4. No migration, no `compiled.php`
change, no wire change.

**Spec:** `docs/superpowers/specs/2026-08-28-link-classifier-seam-design.md`

## Global Constraints

- **Do NOT reintroduce the pseudo-platform link lane.** No `partna.*_link`
  surface, no `custom`/`booking`/`reservations`/`online-ordering`/`events-custom`
  category controller. (CLAUDE.md **Do NOT**; `LegacyPlatformMap::RETIRED`,
  `app/Catalog/LegacyPlatformMap.php:41-56`.)
- **Do not touch the `shop` routing class.** `classifyFromCatalog()`'s existing
  shop guard (`app/Services/Platforms/WebsiteLinkHarvester.php:743-753`) keeps
  the commerce probe running; changing it trades a product card for a plain link.
- **Do not touch `harvest()` / `harvestHtml()`** on this branch. Its gap is real
  (spec §0.3) and is recorded as a follow-up in Task 4, not smuggled in here.
- **Do not run the full suite** (~20 min). Every step below names one file.
- `pint --test` is the gate, not `pint` (`composer pint:test`). `--filter` +
  pint is broken in this repo — run whole test files.
- Branch: `audit-fix/link-classifier-seam-2026-08-28`, worktree
  `.worktrees/link-classifier-seam`, based on `origin/development`.
  It needs its own `composer install` and a copied `.env` before any test runs
  (never `cp -R vendor`, never a symlinked `vendor`).

---

## File Structure

| File | Responsibility |
|---|---|
| `tests/fixtures/catalog/known-link-only.php` | **Create.** The recorded detect-only decision, one row per surface with a reason. Sibling of `known-invisible.php`. |
| `tests/Feature/Platforms/CatalogClassificationSweepTest.php` | **Modify.** Add one `it()` — the two-way ratchet on the `link-only` bucket. |
| `app/Services/Platforms/WebsiteLinkHarvester.php` | **Modify.** One policy docblock above `SOCIAL_HOSTS`; one `PROMOTABLE_ROUTING_CLASS` const; ~6 lines in `classifyFromCatalog()`. |
| `tests/Feature/Platforms/CatalogBackedClassificationTest.php` | **Modify.** Add the promotion's own cases beside the existing `'link'` pins. |

---

### Task 1: Pin the detect-only boundary with a two-way ratchet

The sweep already derives its cases from the compiled catalog and already
ratchets the `invisible` bucket both ways (`known-invisible.php`). This task
gives the `link-only` bucket the same treatment, so a new catalog brand that
lands detect-only fails a test **with instructions**, instead of being found
live three weeks later.

**Files:**
- Create: `tests/fixtures/catalog/known-link-only.php`
- Modify: `tests/Feature/Platforms/CatalogClassificationSweepTest.php`
- Test: `tests/Feature/Platforms/CatalogClassificationSweepTest.php`

**Interfaces:**
- Consumes: `Tests\Support\Catalog\SweepProbeUrl::for()` and `::bucket()`
  (`tests/Support/Catalog/SweepProbeUrl.php:24,38`), and the file-local helpers
  `sweepSurfaces()`, `sweepHandWritten()` already defined at the top of the test.
- Produces: `sweepKnownLinkOnly(): list<string>` — a file-local helper mirroring
  `sweepKnownInvisible()` (`:29-32`).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Platforms/CatalogClassificationSweepTest.php`, after
the `it('classifies every surface, or the surface is a pinned known gap')` block:

```php
function sweepKnownLinkOnly(): array
{
    return require dirname(__DIR__, 2).'/fixtures/catalog/known-link-only.php';
}

// The seam guard (spec 2026-08-28-link-classifier-seam-design §B.3). classify()
// answers 'link' for a catalog surface the hand tables never learned: recognised,
// costs no probe, never auto-connected. That is a POLICY, not an accident — but
// until this test it was invisible at add-time, so a wave of 37 new definitions
// could land 34 detect-only brands and nobody knew until a live find.
//
// Two-way, exactly like known-invisible.php: a NEW detect-only surface fails
// (record the decision or add the harvester row), and a stale row fails
// (it was promoted — delete the row).
it('records every detect-only surface as a decision, not an accident', function () {
    $classifier = app(WebsiteLinkHarvester::class);
    $known = array_flip(sweepKnownLinkOnly());
    $newlyLinkOnly = [];
    $noLongerLinkOnly = [];

    foreach (sweepSurfaces() as $key => $surface) {
        $url = SweepProbeUrl::for($surface, sweepHandWritten());
        if ($url === null) {
            continue; // reported by the probe-URL test above
        }
        $isLinkOnly = SweepProbeUrl::bucket($classifier->classify($url)) === 'link-only';
        if ($isLinkOnly && ! isset($known[$key])) {
            $newlyLinkOnly[] = "{$key}  ({$url})";
        }
        if (! $isLinkOnly && isset($known[$key])) {
            $noLongerLinkOnly[] = $key;
        }
    }
    sort($newlyLinkOnly);
    sort($noLongerLinkOnly);

    expect($newlyLinkOnly)->toBeEmpty(
        "These surfaces classify as a generic link card. Either give the brand a row in\n"
        ."WebsiteLinkHarvester's host constants, or record the detect-only decision (with a\n"
        ."reason) in tests/fixtures/catalog/known-link-only.php:\n - "
        .implode("\n - ", $newlyLinkOnly),
    );
    expect($noLongerLinkOnly)->toBeEmpty(
        "These known-link-only.php rows now classify into a real category — remove them:\n - "
        .implode("\n - ", $noLongerLinkOnly),
    );
});
```

- [ ] **Step 2: Run it to confirm it fails for the right reason**

```bash
./vendor/bin/pest tests/Feature/Platforms/CatalogClassificationSweepTest.php
```

Expected: FAIL — `require` of a non-existent
`tests/fixtures/catalog/known-link-only.php`. (Not an assertion failure yet;
the fixture does not exist.)

- [ ] **Step 3: Create the fixture, seeded from the measured 49**

Create `tests/fixtures/catalog/known-link-only.php`:

```php
<?php

// Ratchet baseline for CatalogClassificationSweepTest: surfaces that
// WebsiteLinkHarvester::classify() answers 'link' for — recognised by the
// compiled catalog, never promoted into a connectable category, and costing no
// commerce probe. Same contract as LINK_ONLY_HOSTS.
//
// This list is a RECORD OF DECISIONS, not a backlog. A row here means "the
// catalog detects this brand and we deliberately render it as a link card".
// Adding a brand to WebsiteLinkHarvester's host constants means DELETING its row.
//
// Why each group stays: docs/superpowers/specs/2026-08-28-link-classifier-seam-design.md §A.2.
return [
    // routing_class 'social' (24) — a catalog social surface is not thereby an
    // account the owner controls. Four are payment handles and four are
    // third-party review listings; promoting them would connect a page the user
    // does not own. Pinterest is additionally protected by LINK_ONLY_HOSTS.
    'bark.company',
    'bluesky.profile',
    'buymeacoffee.page',
    'cameo.profile',
    'cash_app.profile',
    'codepen.profile',
    'fiverr.profile',
    'flickr.photos',
    'gitlab.profile',
    'houzz.pro',
    'kick.channel',
    'ko_fi.page',
    'medium.profile',
    'paypal.me',
    'pinterest.profile',
    'productreview.listing',
    'substack.publication',
    'tripadvisor.listing',
    'trustpilot.listing',
    'tumblr.profile',
    'upwork.profile',
    'venmo.profile',
    'vsco.profile',
    'yelp.listing',

    // routing_class 'content' (13) — classify() has no 'content' category and
    // LinkRouter has neither a gate arm nor a seeder for one. Inventing that
    // lane is product work, not a classifier change.
    'apple_music.artist',
    'apple_podcasts.show',
    'audiomack.artist',
    'bandcamp.artist',
    'bandcamp.store',
    'beatport.artist',
    'circle.community',
    'dailymotion.channel',
    'kajabi.courses',
    'mixcloud.player',
    'rumble.channel',
    'strava.club',
    'tidal.player',

    // routing_class 'events' (7) — the catalog cannot distinguish a single
    // event from an organiser page (no *.event surface exists), and
    // LinkRouter::seedEvent branches on exactly that distinction. The split
    // lives in each scraper's pure normalizer, not in a surface.
    'bandsintown.artist',
    'dice.events',
    'eventfinda.tickets',
    'megatix.tickets',
    'moshtix.tickets',
    'skiddle.tickets',
    'songkick.artist',

    // routing_class 'booking' but is_connectable=false — the catalog says these
    // are detect-only surfaces. Promoting them would hand seedBooking() a
    // surface the catalog refuses to connect.
    'microsoft_bookings.book',
    'wix_bookings.book',

    // routing_class 'shop' (1) — Gumroad's storefront root is a link card by
    // ruling (task #17, 2026-08-18); deeper paths keep the product probe.
    'gumroad.store',

    // Promoted by Task 3 of this plan; rows deleted there, listed here only so
    // the intermediate commit is green:
    //   easi.order, shortcuts.book
    'easi.order',
    'shortcuts.book',
];
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Platforms/CatalogClassificationSweepTest.php
```

Expected: PASS, 5 tests. If `newlyLinkOnly` is non-empty, `origin/development`
has moved and gained brands since this plan was measured — add the new keys with
a one-line reason rather than editing the test.

- [ ] **Step 5: Prove the ratchet actually bites (temporary, do not commit)**

Delete the `'ko_fi.page',` line, re-run. Expected: FAIL naming `ko_fi.page` with
the "record the detect-only decision" message. Restore the line, re-run, PASS.

- [ ] **Step 6: Commit**

```bash
git add tests/fixtures/catalog/known-link-only.php tests/Feature/Platforms/CatalogClassificationSweepTest.php
git commit -m "test(catalog): ratchet the detect-only classifier bucket both ways

A catalog surface that classify() answers 'link' for is a decision, not an
accident — but it was invisible at add-time, so wave 2 shipped 37 definitions
with 3 harvester rows and the gap surfaced as live finds. known-link-only.php
is the sibling of known-invisible.php: a new detect-only brand now fails the
sweep with instructions, and a promoted one fails until its row is deleted.

Spec: docs/superpowers/specs/2026-08-28-link-classifier-seam-design.md"
```

---

### Task 2: One authoritative policy docblock above the constants

Today the policy is spread across four half-explanations
(`WebsiteLinkHarvester.php:534-546`, `:284-306`, `:707-731`, and
`CatalogBackedClassificationTest.php:6-20`), and each has drifted: the
`classifyFromCatalog()` docblock still argues from `LinkProjector::FLOOR = 35`
when the floor has been 25 since the N1 fix (`app/Routing/LinkProjector.php:44`).

**Files:**
- Modify: `app/Services/Platforms/WebsiteLinkHarvester.php:50` (immediately
  above `private const SOCIAL_HOSTS`)
- Modify: `app/Services/Platforms/WebsiteLinkHarvester.php:707-731`
  (`classifyFromCatalog()`'s stale FLOOR paragraph)
- Test: none — comment-only. Guarded by `composer pint:test`.

**Interfaces:** none.

- [ ] **Step 1: Insert the policy block above `SOCIAL_HOSTS`**

Insert immediately before `/** Host-pattern → socials key. First match per key wins (homepage order). */`
(currently `WebsiteLinkHarvester.php:50`):

```php
    /*
     * ── THE FOUR HOST TABLES ARE A DELIBERATE, PERMANENT SPLIT ──────────────
     *
     * SOCIAL_HOSTS / RESERVATION_HOSTS / ORDERING_HOSTS / BOOKING_HOSTS are
     * hand-maintained and answer BEFORE the compiled catalog. That is policy,
     * not debt. Read this before "fixing" it a fifth time.
     *
     * WHY THEY ARE NOT COLLAPSED INTO THE CATALOG. `routing_class` is a
     * PLACEMENT vocabulary (7 values); classify() returns a CATEGORY (9). They
     * do not line up, and three of the gaps are structural:
     *
     *   • routing_class 'content' (22 surfaces) has no category, no
     *     gateAllows() arm and no LinkRouter seeder. Seven of those 22 —
     *     youtube, youtube_music, spotify, soundcloud, twitch, vimeo, deezer —
     *     are 'social' HERE and are among the platform's most-connected brands.
     *     A collapse silently stops connecting all seven.
     *   • 'event' vs 'event-organiser' is a PATH distinction. No *.event
     *     surface exists; the catalog carries only organiser/ticketing pages.
     *     LinkRouter::seedEvent() branches on exactly that string.
     *   • LINK_ONLY_HOSTS is 4/5 absent from the catalog entirely (amazon, ltk,
     *     poshmark, shopmy). Its probe-starvation protection has no catalog
     *     expression at all.
     *
     * And no catalog field reproduces the boundary: 20 of the 49 detect-only
     * surfaces are is_connectable=true, while 12 connectable-today surfaces are
     * is_connectable=false.
     *
     * WHAT THE CATALOG DOES OWN. classifyFromCatalog() backstops these tables
     * and PROMOTES a surface to its real category for exactly
     * routing_class ∈ {booking, reservations, ordering} AND is_connectable —
     * the three classes whose vocabulary is 1:1 with a category that has a real
     * gate arm and a real seeder. A new brand in one of those classes therefore
     * needs NO row here. Everything else answers 'link'.
     *
     * WHAT TO DO WHEN YOU ADD A BRAND. Nothing, if it is booking/reservations/
     * ordering — the promotion covers it. Otherwise
     * CatalogClassificationSweepTest will fail and tell you to either add a row
     * here or record the detect-only decision in
     * tests/fixtures/catalog/known-link-only.php.
     *
     * Full reasoning, with the measured numbers:
     * docs/superpowers/specs/2026-08-28-link-classifier-seam-design.md
     */
```

- [ ] **Step 2: Correct the stale FLOOR paragraph in `classifyFromCatalog()`**

In the docblock at `WebsiteLinkHarvester.php:707-731`, replace the sentence
beginning *"They are host-only by construction and have no confidence floor…"*
through *"…and regresses nothing."* with:

```
     * They are host-only by construction and answer correctly for the 178
     * catalog hosts they cover. (Historical note: this method's original
     * argument for running SECOND was that LinkProjector::FLOOR was 35, above
     * what a bare host-only detector scores. FLOOR has been 25 since the N1
     * fix — LinkProjector.php:26-44 — so identification is no longer the
     * reason. The ordering survives because these tables carry CATEGORY
     * decisions the catalog does not: see the policy block above SOCIAL_HOSTS.)
```

- [ ] **Step 3: Run pint and the classifier tests**

```bash
composer pint:test
./vendor/bin/pest tests/Unit/Platforms/WebsiteLinkHarvesterTest.php
```

Expected: pint clean on the touched file; 24 tests pass (comment-only change).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Platforms/WebsiteLinkHarvester.php
git commit -m "docs(platforms): state the host-table split as policy, once

The reasoning was spread across four half-explanations and one had gone stale:
classifyFromCatalog() still argued from LinkProjector::FLOOR = 35, which has
been 25 since the N1 fix. One block above SOCIAL_HOSTS now carries the whole
decision — what the catalog owns, what the tables own, and what to do when you
add a brand.

Spec: docs/superpowers/specs/2026-08-28-link-classifier-seam-design.md"
```

---

### Task 3: Promote booking / reservations / ordering from the catalog

The recurring bug is entirely in these three classes: `7ca811fec` (four booking
brands), `fd55e662d` (one ordering brand), `e7c376ab0` (T27a booking/ordering),
`76e2264ca` (ordering). None is social, content or events. This closes them with
data.

Measured effect on today's catalog: **two surfaces**, `easi.order` and
`shortcuts.book`. `microsoft_bookings.book` and `wix_bookings.book` are the right
class but `is_connectable: false`, so they correctly stay `'link'` — which is the
point of the second condition. Brand #38 works with no code change.

**Files:**
- Modify: `app/Services/Platforms/WebsiteLinkHarvester.php` (new const beside
  `LINK_ONLY_PLATFORM`; `classifyFromCatalog()` at `:735-762`)
- Modify: `tests/fixtures/catalog/known-link-only.php` (delete two rows)
- Test: `tests/Feature/Platforms/CatalogBackedClassificationTest.php`

**Interfaces:**
- Consumes: `LegacyPlatformMap::routingClassFor(string $surfaceKey): ?string`
  (`app/Catalog/LegacyPlatformMap.php:101-104`); the compiled surface array's
  `is_connectable` bool and `display_name` string.
- Produces: no new public signature. `classify()`'s return shape is unchanged —
  `array{platform:string, category:string, label:string}`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Platforms/CatalogBackedClassificationTest.php`:

```php
// Seam promotion (spec 2026-08-28-link-classifier-seam-design §4). The three
// routing classes whose vocabulary is 1:1 with a category that has a real
// gateAllows() arm and a real seeder get their real category from the catalog,
// so a new booking/ordering brand needs no host-table row. is_connectable is the
// second condition: a surface the catalog refuses to connect must never reach
// seedBooking().
it('promotes a connectable booking/reservations/ordering surface to its real category', function (string $url, string $platform, string $category, string $label) {
    expect(catalogClassifier()->classify($url))
        ->toBe(['platform' => $platform, 'category' => $category, 'label' => $label]);
})->with([
    'easi ordering' => ['https://easi.com.au/order/acme', 'easi', 'online-ordering', 'EASI'],
    'shortcuts booking' => ['https://acme.shortcuts.com.au/', 'shortcuts', 'booking', 'Shortcuts'],
]);

it('holds a non-connectable surface at link even when its routing class is promotable', function (string $url, string $platform, string $label) {
    expect(catalogClassifier()->classify($url))
        ->toBe(['platform' => $platform, 'category' => 'link', 'label' => $label]);
})->with([
    'microsoft bookings' => ['https://outlook.office365.com/owa/calendar/bookings@contoso.com/bookings/', 'microsoft_bookings', 'Microsoft Bookings'],
    'wix bookings' => ['https://bookings.wixapps.net/bookings/v1/acme', 'wix_bookings', 'Wix Bookings'],
]);

it('leaves social, content, events and shop classes at link', function (string $url, string $platform, string $label) {
    expect(catalogClassifier()->classify($url))
        ->toBe(['platform' => $platform, 'category' => 'link', 'label' => $label]);
})->with([
    'social class' => ['https://ko-fi.com/acme', 'ko-fi', 'Ko-fi'],
    'content class' => ['https://www.mixcloud.com/someone/', 'mixcloud', 'Mixcloud'],
    'events class' => ['https://www.songkick.com/artists/1234567', 'songkick', 'Songkick'],
]);
```

- [ ] **Step 2: Run it to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Platforms/CatalogBackedClassificationTest.php
```

Expected: the first dataset FAILS — both cases come back `'category' => 'link'`.
The second and third datasets PASS already (they are the regression pins).

- [ ] **Step 3: Add the promotion const**

In `app/Services/Platforms/WebsiteLinkHarvester.php`, immediately after
`private const LINK_ONLY_PLATFORM = [...];` (ends `:330`):

```php
    /**
     * Catalog routing_class => classify() category, for the classes where the
     * two vocabularies are 1:1 AND LinkRouter has both a gateAllows() arm and a
     * real seeder. Deliberately three entries:
     *
     *   • 'social' is excluded — a catalog social surface is not thereby an
     *     account the owner controls (paypal.me, trustpilot.listing).
     *   • 'content' has no category, no gate arm and no seeder at all.
     *   • 'events' cannot express event vs event-organiser (no *.event surface).
     *   • 'shop' must keep its commerce probe (see the guard below).
     *
     * See the policy block above SOCIAL_HOSTS.
     */
    private const PROMOTABLE_ROUTING_CLASS = [
        'booking' => 'booking',
        'reservations' => 'reservations',
        'ordering' => 'online-ordering',
    ];
```

- [ ] **Step 4: Use it in `classifyFromCatalog()`**

In `classifyFromCatalog()`, replace the shop guard's condition line and the
return block (currently `:743-761`) with:

```php
            $routingClass = LegacyPlatformMap::routingClassFor($projection->surfaceKey);

            if ($routingClass === 'shop') {
                // A shop-class surface we do NOT sync as a store (Gumroad,
                // stan.store — no product feed) is a link card at its
                // storefront root: "tameimpala.gumroad.com" is the shop, and
                // nothing else can carry it (task #17, 2026-08-18). A deeper
                // path (a product page) keeps the probe, as documented above.
                $isProvider = in_array($projection->surfaceKey, ShopConnections::surfaces(), true);
                $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
                if ($isProvider || $path !== '') {
                    return null;
                }
            }

            // is_connectable is the second condition, not a nicety: promoting a
            // surface the catalog refuses to connect would hand seedBooking() /
            // seedOnlineOrdering() a write they must never make. It holds
            // microsoft_bookings.book and wix_bookings.book at 'link' today.
            $category = ($surface['is_connectable'] ?? false) === true
                ? (self::PROMOTABLE_ROUTING_CLASS[$routingClass] ?? 'link')
                : 'link';

            return [
                'platform' => LegacyPlatformMap::legacyFor($projection->surfaceKey),
                'category' => $category,
                'label' => (string) $surface['display_name'],
            ];
```

- [ ] **Step 5: Delete the two promoted rows from the ratchet fixture**

In `tests/fixtures/catalog/known-link-only.php`, delete the trailing block:

```php
    // Promoted by Task 3 of this plan; rows deleted there, listed here only so
    // the intermediate commit is green:
    //   easi.order, shortcuts.book
    'easi.order',
    'shortcuts.book',
```

- [ ] **Step 6: Run the four affected test files**

```bash
./vendor/bin/pest tests/Feature/Platforms/CatalogBackedClassificationTest.php
./vendor/bin/pest tests/Feature/Platforms/CatalogClassificationSweepTest.php
./vendor/bin/pest tests/Unit/Platforms/WebsiteLinkHarvesterTest.php
./vendor/bin/pest tests/Feature/Platforms/LinkRouterGateMatrixTest.php
```

Expected: all PASS. In particular `CatalogBackedClassificationTest`'s existing
12-case pin *"keeps every hand-table classification the catalog would have
lost"* (`:71-86`) must still pass unchanged — the constants still answer first,
so nothing it covers is touched.

- [ ] **Step 7: Verify the two promoted surfaces reach a real seeder, not a dead arm**

```bash
./vendor/bin/pest tests/Feature/Platforms/Registry/BrandCoverageTest.php
./vendor/bin/pest tests/Feature/Platforms/CatalogClassificationSweepTest.php
```

Then confirm by reading, not by assuming:
- `LinkRouter::seedBooking()` (`app/Services/Platforms/LinkRouter.php:279-296`)
  writes a generic provider card for any platform that is not Fresha or Square —
  `shortcuts` needs no registry descriptor.
- `LinkRouter::seedOnlineOrdering()` (`:491-493`) resolves
  `LegacyPlatformMap::surfaceFor('easi')` → `easi.order`, so the store row keys
  on a real surface.

Record what you read in the commit body. If either turns out to need a
descriptor, STOP and report — that is a blocker-gate item, not a fix-in-place.

- [ ] **Step 8: Run pint**

```bash
composer pint:test
```

- [ ] **Step 9: Commit**

```bash
git add app/Services/Platforms/WebsiteLinkHarvester.php tests/fixtures/catalog/known-link-only.php tests/Feature/Platforms/CatalogBackedClassificationTest.php
git commit -m "feat(platforms): the catalog names booking/reservations/ordering brands

classifyFromCatalog() answered a flat 'link' for every catalog-only brand, so a
booking or ordering brand the hand tables never learned rendered as a generic
card until someone added a host row — nine commits since 2026-08-01 are that
one bug. Those three routing classes map 1:1 onto a category with a real
gateAllows() arm and a real seeder, so the catalog can name them directly.

Gated on is_connectable as well as routing_class: microsoft_bookings.book and
wix_bookings.book are the right class but not connectable, and promoting them
would have handed seedBooking() a write the catalog refuses.

social / content / events / shop keep answering 'link' — the vocabularies do
not line up there and collapsing them costs seven of the most-connected brands.
Reasoning and measurements:
docs/superpowers/specs/2026-08-28-link-classifier-seam-design.md

Effect on today's catalog: easi.order, shortcuts.book. Every future brand in
these classes works with no code change."
```

---

### Task 4: Record the two gaps this branch deliberately leaves open

Not code. The decision doc names two live gaps that are out of scope here; they
must not evaporate when the plan file is deleted on ship.

**Files:**
- Modify: `docs/superpowers/specs/2026-08-28-link-classifier-seam-design.md`

- [ ] **Step 1: Append a "Follow-ups" section to the spec**

```markdown
## 5. Follow-ups this branch deliberately did not take

1. **`harvest()` has no catalog fallback** (`WebsiteLinkHarvester.php:449-514`).
   A catalog-only booking/ordering/reservation brand on a scraped homepage is
   absent from the GB-shaped payload entirely — not mis-categorised, missing.
   Fixing it means a second refactor with its own review; the promotion in Task
   3 does not reach this lane. Sized **M**.
2. **`content` is a routing class with no product lane** (22 surfaces). Until
   `classify()`, `LinkRouter::gateAllows()` and `LinkRouter`'s `match` all learn
   it, every content brand is a link card and seven content-class surfaces are
   'social' only because a hand table says so. Sized **L**, and it is a product
   decision before it is a code one.
```

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-08-28-link-classifier-seam-design.md
git commit -m "docs(spec): record the two seam gaps this branch leaves open"
```

---

## Self-Review

**Spec coverage.** §2 (Option A) is costed, not implemented — correct, it is the
rejected option. §3 (Option B) → Tasks 1–2. §4's recommendation (B + the narrow
promotion) → Task 3. §4's "what this deliberately does not do" → Global
Constraints + Task 4. No spec requirement is unimplemented.

**Placeholders.** None: every code step carries the literal block to insert, and
every test step names the file and the expected outcome.

**Type consistency.** `classify()`'s return shape is
`array{platform:string, category:string, label:string}` throughout — Task 3 adds
no key and removes none, so the nine `@param array{platform:string, category:string, label:string}`
docblocks in `LinkRouter` stay accurate. `sweepKnownLinkOnly()` mirrors
`sweepKnownInvisible()`'s `list<string>`. `PROMOTABLE_ROUTING_CLASS` is
`array<string, string>` keyed by `routing_class`, consumed only via `??`.
