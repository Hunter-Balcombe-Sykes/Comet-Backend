# clk.bio Unroll + Hidden-Anchor Chrome Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unroll Lnk.Bio pages served on the `clk.bio` mirror, without importing that vendor's hidden SEO backlinks or a page's own share-widget endpoints as if they were the owner's links.

**Architecture:** Three independent one-line-ish changes at three existing seams — the curated aggregator host list (`LinkInBioDetector::HOSTS`), the anchor harvest (`WebsiteLinkHarvester::extractLinks`), and the share/intent widget guard (`WebsiteLinkHarvester::looksLikeProfile`) — plus one recorded fixture that proves the three compose correctly against the real page that exposed all of this.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, `DOMDocument`, recorded-fixture corpus (`tests/fixtures/recorded/`).

**Spec:** No separate design doc. The evidence this plan argues from is the live investigation of the `themetapunter` build (2026-08-24), reproduced in full under "Evidence" below.

## Global Constraints

- **No Laravel migration files.** Nothing here touches the DB schema. (Composer guard rejects them.)
- **Comment for WHY, not what.** Brief docblocks on public methods, one line above non-trivial blocks. No banners, no restatements.
- 4-space indent, LF line endings.
- `composer test` runs SQLite; nothing in this plan is constraint-bound, so the SQLite lane is sufficient here.
- Recorded fixtures are byte-exact evidence: capture with `php artisan fixtures:capture`, never hand-type. `.gitattributes` already carries `tests/fixtures/recorded/** -text`, so the manifest sha256 survives a fresh checkout.
- Every finding below is a **behaviour** change to a shared harvest path. Both new rules must be justified to a reviewer as "a human visitor could not have clicked this", not as "it looked like junk".

---

## Evidence

Live build `01a033ae-d141-7084-a155-ada10ddab202` (`themetapunter`, 2026-08-24 12:11:29 UTC) captured two bio links and unrolled neither.

**`beacons.ai/themetapunter`** — `routing.import_runs` row `3cb2280f`: `outcome: unavailable`, `error_class: fetch_failed`, `unavailable_reasons: {"bot_challenge": 1}`, `bio_url_seeded: true`. Re-probed 2026-08-24 with a Chrome UA: **HTTP 403, `<title>Attention Required! | Cloudflare</title>`**. This is a documented WAF block with no API seam behind it. **Out of scope — nothing in this plan can fix it, and the zero-yield floor already handles it correctly.**

**`clk.bio/TheMetaPunter`** — no `import_runs` row at all. `LinkInBioDetector::matches()` returned false, so `InstagramAutoSync` never dispatched `LinkInBioScanJob`; the URL fell through to `classify()` → null → an inert custom card + a `catalog.unmatched_domains` row with `has_detectors: false`. Probed 2026-08-24, same IP and UA for both:

| URL | Result |
|---|---|
| `lnk.bio/TheMetaPunter` | **403** Cloudflare |
| `clk.bio/TheMetaPunter` | **200**, 58,029 bytes, server-rendered, 32 anchors |

`clk.bio` is Lnk.Bio's mirror (page title `@themetapunter Lnk.Bio · link in bio`) and is **not** behind the WAF. The importer's comment listing `lnk.bio` among the unfixably-blocked hosts is true of that hostname only.

The 32 anchors decompose into exactly three groups:

1. **Four genuine owner links**, each marked `rel="external nofollow ugc" data-click="true" data-id="…"`:
   `instagram.com/themetapunter`, `tiktok.com/@joe__o`, `youtube.com/@themetapunter`, `kick.com/themetapunter`.
2. **Seven share widgets** inside `div.menu-container`, each carrying the page's own URL in the query string.
3. **Five hidden SEO backlinks to Lnk.Bio's own portfolio**, verbatim from the page:
   ```html
   <a href="https://cruciverba.io/"   title="Soluzioni cruciverba"   class="d-none" style="display:none">Soluzioni cruciverba</a>
   <a href="https://petrolprice.sg/"  title="Petrol Price Singapore" class="d-none" style="display:none">Petrol Price Singapore</a>
   <a href="https://mediakit.bio/"    title="Mediakit"               class="d-none" style="display:none">Mediakit</a>
   <a href="https://menoo.me/"        title="Menoo"                  class="d-none" style="display:none">Menoo</a>
   <a href="https://calcio.dev/"      title="pc calcio 7 trainer"    class="d-none">pc calcio 7 trainer</a>
   ```
   Those exact five domains are **already in `catalog.unmatched_domains`** (first seen 2026-08-21 06:11, 2 hits each) — proof this footer has leaked through a harvest before.

The existing chrome rule (`LinkInBioImporter.php:336-343`) only skips links on the *same host as the bio page*, so neither group 2 nor group 3 is caught today.

Group 2 is worse than junk. Measured against the live classifier (`php artisan tinker`, 2026-08-24):

```
https://www.linkedin.com/sharing/share-offsite/?url=…  => linkedin/social   ← BOGUS
https://www.reddit.com/submit?url=…                    => reddit/social     ← BOGUS
https://www.facebook.com/sharer.php?u=…                => NULL              (guarded)
https://twitter.com/intent/tweet?text=…                => NULL              (guarded)
https://wa.me/?text=…                                  => NULL              (bare-path rule)
```

`looksLikeProfile()` guards `facebook` and `twitter` only, against path prefixes `sharer|share|intent|dialog`. LinkedIn's `/sharing/` and Reddit's `/submit` are neither. Enabling `clk.bio` without fixing this would connect a share endpoint as the owner's LinkedIn and Reddit account.

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `app/Services/Platforms/LinkInBioDetector.php` | Curated aggregator host list — the only signal that a bio link is a page to unroll | Add `clk.bio` |
| `app/Services/Platforms/WebsiteLinkHarvester.php` | Anchor extraction + host→platform classification, shared by the bio-link AND website lanes | Add hidden-anchor skip to `extractLinks()`; widen `looksLikeProfile()` |
| `tests/Unit/Platforms/LinkInBioDetectorTest.php` | Host-list coverage | Add `clk.bio` + the missing `sprout.link` row |
| `tests/Feature/Platforms/WebsiteLinkHarvesterHiddenAnchorTest.php` | **New** — hidden-anchor rule | Create |
| `tests/Feature/Platforms/WebsiteLinkHarvesterShareWidgetTest.php` | **New** — share-widget rule | Create |
| `tests/fixtures/recorded/linkinbio/lnkbio.clkbio.html` | **New** — byte-exact capture of the page that exposed all three defects | Create via `fixtures:capture` |
| `tests/Feature/Routing/LinkInBioImporterTest.php` | Importer behaviour | Add one end-to-end test against the fixture |

**Blast radius, stated plainly:** `WebsiteLinkHarvester` is shared with the website-harvest lane (`GoogleBusinessEnrichJob`, the `website` importer kind). Both new rules therefore change website harvesting too. That is intended in both cases — a hidden anchor and a share endpoint are equally wrong to publish on someone's page whichever lane found them — but it is why Tasks 2 and 3 are separate commits with their own reviewable tests, and why Task 5 re-runs the neighbouring suites rather than only the ones it touched.

---

### Task 1: Recognise `clk.bio` as a Lnk.Bio mirror

**Files:**
- Modify: `app/Services/Platforms/LinkInBioDetector.php:18-24`
- Test: `tests/Unit/Platforms/LinkInBioDetectorTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `LinkInBioDetector::matches('https://clk.bio/…') === true`. Task 5's end-to-end test depends on this being true.

- [ ] **Step 1: Write the failing test**

In `tests/Unit/Platforms/LinkInBioDetectorTest.php`, add two rows to the existing `->with([...])` dataset on the *first* test (`it('matches each of the curated link-in-bio hosts')`), immediately after the `'https://lnk.bio/venue',` line:

```php
    // 2026-08-24 (themetapunter live): clk.bio is Lnk.Bio's mirror and is NOT
    // behind the Cloudflare block that makes lnk.bio itself unfetchable —
    // 200 + 32 server-rendered anchors where lnk.bio answers 403.
    'https://clk.bio/venue',
    // sprout.link joined HOSTS on 2026-08-21 without joining this dataset.
    'https://sprout.link/venue',
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Unit/Platforms/LinkInBioDetectorTest.php
```

Expected: FAIL — two dataset rows fail with `Failed asserting that false is true` (`clk.bio` and `sprout.link` — the latter passes already if `sprout.link` is in `HOSTS`; if it passes, only `clk.bio` fails).

- [ ] **Step 3: Write minimal implementation**

In `app/Services/Platforms/LinkInBioDetector.php`, extend the `HOSTS` const. Replace:

```php
        // M-1 (2026-08-21, industrybeans live): Sprout Social's link-in-bio.
        // Client-rendered shell, unrolled via LinkInBioApiUnroller::sprout().
        'sprout.link',
    ];
```

with:

```php
        // M-1 (2026-08-21, industrybeans live): Sprout Social's link-in-bio.
        // Client-rendered shell, unrolled via LinkInBioApiUnroller::sprout().
        'sprout.link',
        // 2026-08-24 (themetapunter live): Lnk.Bio serves the same page on two
        // hostnames and only one of them is reachable — lnk.bio answers 403
        // behind Cloudflare, clk.bio answers 200 with the anchors intact. A
        // vendor's blocked-host verdict does not transfer to its own mirror.
        'clk.bio',
    ];
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/pest tests/Unit/Platforms/LinkInBioDetectorTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/LinkInBioDetector.php tests/Unit/Platforms/LinkInBioDetectorTest.php
git commit -m "fix(platforms): recognise clk.bio, Lnk.Bio's reachable mirror"
```

---

### Task 2: Skip anchors no visitor can see

**Files:**
- Modify: `app/Services/Platforms/WebsiteLinkHarvester.php` — `extractLinks()` (around line 484)
- Test: `tests/Feature/Platforms/WebsiteLinkHarvesterHiddenAnchorTest.php` (create)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `WebsiteLinkHarvester::allOutboundLinks()` no longer returns hrefs from anchors carrying `hidden`, `style="display:none"`, or `style="visibility:hidden"`. Task 5's end-to-end test relies on this to drop the five Lnk.Bio portfolio links.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/WebsiteLinkHarvesterHiddenAnchorTest.php`:

```php
<?php

use App\Services\Platforms\WebsiteLinkHarvester;

// Verbatim from https://clk.bio/TheMetaPunter (2026-08-24): Lnk.Bio ships five
// display:none backlinks to its own portfolio on every page it serves. They are
// SEO, not content — a visitor cannot click them, so the owner never published
// them, and all five had already leaked into catalog.unmatched_domains.
it('drops anchors the page hides from its visitors', function () {
    $links = app(WebsiteLinkHarvester::class)->allOutboundLinks('<html><body>
        <a href="https://kick.com/themetapunter">Kick</a>
        <a href="https://cruciverba.io/" class="d-none" style="display:none">Soluzioni cruciverba</a>
        <a href="https://petrolprice.sg/" style="display: none">Petrol Price Singapore</a>
        <a href="https://mediakit.bio/" style="DISPLAY:NONE">Mediakit</a>
        <a href="https://menoo.me/" hidden>Menoo</a>
        <a href="https://calcio.dev/" style="visibility:hidden">pc calcio 7 trainer</a>
    </body></html>', 'https://clk.bio/TheMetaPunter');

    expect($links)->toBe(['https://kick.com/themetapunter']);
});

it('keeps a visible anchor that merely carries an unrelated inline style', function () {
    $links = app(WebsiteLinkHarvester::class)->allOutboundLinks(
        '<html><body><a href="https://example.org/real" style="color:red;display:block">Real</a></body></html>',
        'https://clk.bio/TheMetaPunter'
    );

    expect($links)->toBe(['https://example.org/real']);
});

// A collapsed mobile nav hides its CONTAINER, not each link. Matching ancestors
// would need cascade the DOM parser never computes, and would silently eat real
// navigation — so the rule deliberately reads the anchor's own markup only.
it('keeps a visible anchor nested inside a hidden container', function () {
    $links = app(WebsiteLinkHarvester::class)->allOutboundLinks(
        '<html><body><div style="display:none"><a href="https://example.org/nav">Nav</a></div></body></html>',
        'https://clk.bio/TheMetaPunter'
    );

    expect($links)->toBe(['https://example.org/nav']);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Platforms/WebsiteLinkHarvesterHiddenAnchorTest.php
```

Expected: FAIL on the first test — the array contains all six URLs, not one. The second and third tests pass already (they assert the rule does NOT over-reach).

- [ ] **Step 3: Write minimal implementation**

In `app/Services/Platforms/WebsiteLinkHarvester.php`, inside `extractLinks()`, add the skip immediately after the existing empty/fragment guard. Replace:

```php
        $seen = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }
            $abs = $this->absolutize($href, $baseUrl);
```

with:

```php
        $seen = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }
            if ($this->isHiddenAnchor($a)) {
                continue;
            }
            $abs = $this->absolutize($href, $baseUrl);
```

Then add the helper directly below `extractLinks()`:

```php
    /**
     * A link no visitor can see is not a link the owner published. Lnk.Bio
     * ships five display:none backlinks to its own portfolio on every page
     * (measured on clk.bio/TheMetaPunter, 2026-08-24) and all five had already
     * reached catalog.unmatched_domains through a harvest.
     *
     * Reads the anchor's OWN markup only. An ancestor's style would need the
     * computed cascade DOMDocument never builds, and a collapsed mobile nav
     * hides its container while its links are real — so climbing the tree
     * would eat genuine navigation to catch a footer.
     */
    private function isHiddenAnchor(\DOMElement $a): bool
    {
        if ($a->hasAttribute('hidden')) {
            return true;
        }

        $style = $a->getAttribute('style');

        return $style !== ''
            && preg_match('~(display\s*:\s*none|visibility\s*:\s*hidden)~i', $style) === 1;
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Platforms/WebsiteLinkHarvesterHiddenAnchorTest.php
```

Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/WebsiteLinkHarvester.php tests/Feature/Platforms/WebsiteLinkHarvesterHiddenAnchorTest.php
git commit -m "fix(platforms): drop hidden anchors from the link harvest"
```

---

### Task 3: Widen the share/intent widget guard

**Files:**
- Modify: `app/Services/Platforms/WebsiteLinkHarvester.php:387`, `:491`, `:760-774`
- Test: `tests/Feature/Platforms/WebsiteLinkHarvesterShareWidgetTest.php` (create)

**Interfaces:**
- Consumes: nothing from Tasks 1-2.
- Produces: `WebsiteLinkHarvester::classify()` returns `null` for share/intent endpoints on **every** social host, not just facebook/twitter. The private helper's signature changes from `looksLikeProfile(string $key, string $url)` to `looksLikeProfile(string $url)`; both call sites must drop the first argument.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/WebsiteLinkHarvesterShareWidgetTest.php`:

```php
<?php

use App\Services\Platforms\WebsiteLinkHarvester;

// Every one of these is a "post this page to X" button lifted verbatim from
// https://clk.bio/TheMetaPunter (2026-08-24). Classifying one as a profile
// connects a share endpoint as the owner's account.
it('refuses to read a share widget as somebody\'s profile', function (string $url) {
    expect(app(WebsiteLinkHarvester::class)->classify($url))->toBeNull();
})->with([
    'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=https%3A%2F%2Fclk.bio%2FTheMetaPunter',
    'reddit' => 'https://www.reddit.com/submit?url=https%3A%2F%2Fclk.bio%2FTheMetaPunter&title=Check',
    'facebook' => 'https://www.facebook.com/sharer.php?u=https%3A%2F%2Fclk.bio%2FTheMetaPunter',
    'twitter' => 'https://twitter.com/intent/tweet?text=Check',
    'whatsapp' => 'https://wa.me/?text=Check',
]);

// The guard must not swallow the real accounts sitting next to those buttons
// on the same page.
it('still reads a real profile on the same hosts', function (string $url, string $platform) {
    expect(app(WebsiteLinkHarvester::class)->classify($url))
        ->not->toBeNull()
        ->and(app(WebsiteLinkHarvester::class)->classify($url)['platform'])->toBe($platform);
})->with([
    ['https://www.linkedin.com/in/joe-osborne', 'linkedin'],
    ['https://www.linkedin.com/company/partna', 'linkedin'],
    ['https://www.reddit.com/user/themetapunter', 'reddit'],
    ['https://www.instagram.com/themetapunter', 'instagram'],
    ['https://www.tiktok.com/@joe__o', 'tiktok'],
]);
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/pest tests/Feature/Platforms/WebsiteLinkHarvesterShareWidgetTest.php
```

Expected: FAIL — the `linkedin` and `reddit` rows of the first test return `linkedin/social` and `reddit/social` instead of null. The second test passes already.

- [ ] **Step 3: Write minimal implementation**

In `app/Services/Platforms/WebsiteLinkHarvester.php`, replace the whole `looksLikeProfile` method:

```php
    /**
     * A profile link, not a share/intent widget ("facebook.com/sharer",
     * "twitter.com/intent") — the classic false positives on business sites.
     */
    private function looksLikeProfile(string $key, string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (in_array($key, ['facebook', 'twitter'], true)
            && preg_match('~^/(sharer|share|intent|dialog)~', $path)) {
            return false;
        }

        // Bare-domain links (e.g. "https://instagram.com") carry no profile.
        return $path !== '' && $path !== '/';
    }
```

with:

```php
    /**
     * A profile link, not a share/intent widget ("facebook.com/sharer",
     * "twitter.com/intent") — the classic false positives on business sites.
     *
     * Host-agnostic since 2026-08-24: the old facebook/twitter allowlist let
     * linkedin.com/sharing/share-offsite and reddit.com/submit through as real
     * profiles on the live clk.bio page, and a per-host list guarantees the
     * next vendor's share button repeats the defect. A share endpoint carries
     * the page it shares in its QUERY, so no owner's handle is lost by reading
     * these paths as non-profiles — the worst case is an inert custom card.
     */
    private function looksLikeProfile(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (preg_match('~^/(sharer|share|sharing|intent|intents|dialog|submit)(/|\.|$)~', $path) === 1) {
            return false;
        }

        // Bare-domain links (e.g. "https://instagram.com") carry no profile.
        return $path !== '' && $path !== '/';
    }
```

Then update both call sites to drop the now-unused first argument.

Line 387 — replace:
```php
                if (! isset($socials[$key]) && preg_match($pattern, $host) && $this->looksLikeProfile($key, $url)) {
```
with:
```php
                if (! isset($socials[$key]) && preg_match($pattern, $host) && $this->looksLikeProfile($url)) {
```

Line 491 — replace:
```php
            if (preg_match($pattern, $host) && $this->looksLikeProfile($key, $url)) {
```
with:
```php
            if (preg_match($pattern, $host) && $this->looksLikeProfile($url)) {
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/pest tests/Feature/Platforms/WebsiteLinkHarvesterShareWidgetTest.php
```

Expected: PASS, 10 tests (5 + 5 dataset rows).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/WebsiteLinkHarvester.php tests/Feature/Platforms/WebsiteLinkHarvesterShareWidgetTest.php
git commit -m "fix(platforms): guard share widgets on every social host, not two"
```

---

### Task 4: Record the page that exposed all three defects

**Files:**
- Create: `tests/fixtures/recorded/linkinbio/lnkbio.clkbio.html` (via artisan, not by hand)
- Modify: `tests/fixtures/recorded/MANIFEST.json` (written by the same command)

**Interfaces:**
- Consumes: nothing.
- Produces: fixture key `linkinbio/lnkbio.clkbio.html`, loadable in Task 5 as `Recorded::get('linkinbio/lnkbio.clkbio.html')`.

- [ ] **Step 1: Capture the live page**

```bash
php artisan fixtures:capture linkinbio lnkbio.clkbio \
  --from=url \
  --url="https://clk.bio/TheMetaPunter" \
  --notes="Lnk.Bio via its clk.bio mirror (themetapunter, 2026-08-24). 32 anchors: 4 owner links (rel=external nofollow ugc), 7 share widgets, 5 display:none SEO backlinks to Lnk.Bio's own portfolio. lnk.bio/TheMetaPunter answers 403 for the same content."
```

Expected: writes the file and a `MANIFEST.json` row. If the capture fails (page moved, host down), STOP and report — do not hand-write the fixture.

- [ ] **Step 2: Verify the corpus is self-consistent**

```bash
php artisan fixtures:verify
./vendor/bin/pest tests/Feature/Fixtures
```

Expected: PASS. `RecordedFixtureManifestGuardTest` must be green — a red one means the sha256 did not survive, which `.gitattributes` (`tests/fixtures/recorded/** -text`) is there to prevent.

- [ ] **Step 3: Confirm the fixture actually carries the three groups**

```bash
php -r '
$h = file_get_contents("tests/fixtures/recorded/linkinbio/lnkbio.clkbio.html");
printf("anchors=%d hidden=%d owner=%d\n",
  preg_match_all("~<a\b~i", $h),
  preg_match_all("~<a\b[^>]*display\s*:\s*none~i", $h),
  preg_match_all("~<a\b[^>]*data-click=\"true\"~i", $h));
'
```

Expected: `anchors` ≥ 30, `hidden` ≥ 4, `owner` ≥ 4. If `hidden` is 0 the page changed and Task 5's assertions must be re-derived from the new capture, not forced.

- [ ] **Step 4: Commit**

```bash
git add tests/fixtures/recorded/linkinbio/lnkbio.clkbio.html tests/fixtures/recorded/MANIFEST.json
git commit -m "test(fixtures): record the clk.bio page behind the themetapunter miss"
```

---

### Task 5: Prove the three rules compose on the real page

**Files:**
- Modify: `tests/Feature/Routing/LinkInBioImporterTest.php` (append one test)

**Interfaces:**
- Consumes: Task 1 (`clk.bio` matches), Task 2 (hidden anchors dropped), Task 3 (share widgets unclassified), Task 4 (fixture key `linkinbio/lnkbio.clkbio.html`).
- Produces: nothing downstream.

**Why `registrable_key` and not `surface_key`:** the negative half of this test is the whole point, and a `not->toContain('cruciverba.io')` against `surface_key` would be **vacuous** — an unroutable domain never gets a surface, so that assertion passes whether or not the link leaked. `routing.link_observations.registrable_key` holds the bare domain for every observation, routed or not, so the same assertion there actually fails when a hidden backlink gets through. Confirmed live: `instagram.profile`, `tiktok.profile` and `youtube.channel` all exist as real surface keys, and `classify()` returns `instagram`/`tiktok`/`youtube` as `social` and `kick` as `link`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Routing/LinkInBioImporterTest.php`:

```php
// The whole chain against the page that exposed it (themetapunter, 2026-08-24):
// clk.bio is recognised, its four owner links are observed, its seven share
// widgets and five display:none SEO backlinks are not. Asserted on
// registrable_key, which every observation carries whether or not it routed —
// the same assertion on surface_key would pass vacuously for the leaks.
it('unrolls a real Lnk.Bio page to the owner\'s links and nothing else', function () {
    $pro = createTenant('bio-clkbio');
    bioPage(Tests\Support\Fixtures\Recorded::html('linkinbio/lnkbio.clkbio.html'));

    $result = app(LinkInBioImporter::class)->import($pro, 'https://example.com/TheMetaPunter');

    $domains = DB::table('routing.link_observations')
        ->where('source', 'link_in_bio')
        ->pluck('registrable_key')
        ->all();

    expect($result['outcome'])->toBe('ok')
        ->and($domains)->toContain('instagram.com', 'tiktok.com', 'youtube.com', 'kick.com');

    // Split from the chain above deliberately: a chained expect() aborts on its
    // first failure, so bundling these would prove only whichever ran first.
    // The five hidden Lnk.Bio portfolio backlinks.
    expect($domains)->not->toContain('cruciverba.io');
    expect($domains)->not->toContain('petrolprice.sg');
    expect($domains)->not->toContain('mediakit.bio');
    expect($domains)->not->toContain('menoo.me');
    expect($domains)->not->toContain('calcio.dev');
    // The share widgets, which classify as real accounts without Task 3.
    expect($domains)->not->toContain('linkedin.com');
    expect($domains)->not->toContain('reddit.com');
});

// Independent of the routing assertions above, because a negative assertion
// that passes for the wrong reason is invisible: this pins that the harvest
// itself hands the importer 4 classifiable links, not 16.
it('hands the importer only the links a visitor could click', function () {
    $harvester = app(App\Services\Platforms\WebsiteLinkHarvester::class);

    $classified = array_values(array_filter(
        $harvester->allOutboundLinks(
            Tests\Support\Fixtures\Recorded::html('linkinbio/lnkbio.clkbio.html'),
            'https://clk.bio/TheMetaPunter'
        ),
        fn (string $u): bool => $harvester->classify($u) !== null
    ));

    expect($classified)->toHaveCount(4);
});
```

> **Executor note:** `toHaveCount(4)` is derived from the live page (4 anchors carrying `data-click="true"`). If the real number differs, the page changed since capture — dump `$classified`, justify every entry, and correct the number. Do **not** weaken either test to a bare count or drop a negative assertion to get green.

- [ ] **Step 2: Run test to verify it fails without the fixes**

```bash
git stash push app/Services/Platforms/LinkInBioDetector.php app/Services/Platforms/WebsiteLinkHarvester.php
./vendor/bin/pest tests/Feature/Routing/LinkInBioImporterTest.php --filter="visitor could click"
git stash pop
```

Expected: FAIL with a count well above 4 (the hidden backlinks and share widgets still classify). This is the mutation check — it proves the new test is not vacuous.

- [ ] **Step 3: Run the test with the fixes in place**

```bash
./vendor/bin/pest tests/Feature/Routing/LinkInBioImporterTest.php
```

Expected: PASS, all tests in the file.

- [ ] **Step 4: Run the full neighbouring surface**

```bash
./vendor/bin/pest \
  tests/Unit/Platforms \
  tests/Feature/Platforms \
  tests/Feature/Routing \
  tests/Feature/Fixtures
```

Expected: PASS, 0 failures. These are the suites that exercise `WebsiteLinkHarvester` on both lanes.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Routing/LinkInBioImporterTest.php
git commit -m "test(routing): pin the clk.bio unroll end to end"
```

---

### Task 6: Full verification

**Files:** none modified.

- [ ] **Step 1: Lint**

```bash
./vendor/bin/pint --test
```

Expected: PASS. (`pint` without `--test` FIXES and then reports success — the gate is `--test`.)

- [ ] **Step 2: Static analysis**

```bash
./vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: no new errors. A dependency-graph change can surface latent findings in untouched files — if that happens, confirm with a cold cache before attributing them to this branch.

- [ ] **Step 3: Full suite**

```bash
composer test
```

Expected: 0 failures.

- [ ] **Step 4: Report**

Report the actual counts from Steps 1-3. Do not claim success without pasting the output.

---

## Out of Scope — stated so it is not mistaken for an oversight

- **`beacons.ai` remains unrollable.** Hard Cloudflare WAF, 403 at the edge, no API seam behind it (re-verified 2026-08-24). The zero-yield floor already leaves the inert card, which is the correct behaviour. Changing this needs a residential-proxy vendor — a spend decision, not a code fix.
- **The `lnk.bio` hostname stays in `HOSTS`** even though it 403s. Removing it would lose the zero-yield floor's inert card for anyone who publishes the canonical URL.
- **No `catalog` change.** `clk.bio` will still have no catalog detector, so a *pasted* clk.bio URL (as opposed to a scraped bio link) still routes as unknown. That is the `catalog.unmatched_domains` triage queue's job and a separate decision.
- **The 5 stale `catalog.unmatched_domains` rows** (cruciverba.io et al, 2026-08-21) are left in place. They are triage data, not live state.
- **No backfill.** The existing `themetapunter` build keeps its two inert cards; re-running the build is a manual operator action.
