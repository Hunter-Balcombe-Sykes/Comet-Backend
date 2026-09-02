# Fresha Page-Parsing Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the Fresha page/URL *grammar* that `App\Services\Platforms\FreshaScraper` (dashboard lane) and `App\Ingest\Connectors\FreshaConnector` (ingest lane) currently each carry their own copy of into one pure static reader, `App\Services\Platforms\FreshaPage`, without changing either lane's behaviour.

**Architecture:** Modelled on the `SquareBookingPage` + `SquareBookingClient` pair (shipped 2026-09-02): a `final class` of `public static` methods with **zero I/O**, consumed by the ingest connector through `Io` and by the dashboard scraper through `SafeUrlFetcher`. Only *grammar* moves — regexes, URL shapes, the GraphQL request body, the `__NEXT_DATA__` script extraction. Transport policy (`FetchBudget`, `lastResolvedSlug`, storewide degrade, `abort(502)` vs `Unavailable`, throwing vs `tryFetch`) stays in the lane that owns it.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4. No new dependencies.

**Spec:** None — this plan is the record. Source prompt verified against `origin/development` @ `1ddbd904f` on 2026-09-02; see [Verification](#verification-of-the-brief) below for the corrections that verification forced.

## Global Constraints

- **`FreshaScraper` keeps every public method signature.** 25 test files reference `FreshaScraper`; many bind `Mockery::mock(FreshaScraper::class)` and set expectations on `stripLocale`, `fetchMenu`, `fetchEmployeeServices`. A required test edit is a signal the split is wrong — stop and re-plan, do not edit the test.
- **`FreshaPage` performs no I/O.** No `Http`, no `SafeUrlFetcher`, no `Io`, no `config()`, no `Log`, no `abort()`. It takes strings and arrays and returns strings and arrays. This is what makes it unit-testable with no `Http::fake()` and no container.
- **No behaviour change in Tasks 1–3.** Those tasks are a pure move. Tasks 4 and 5 are behaviour changes and are **BLOCKED pending an owner ruling** (see [Rulings Required](#rulings-required)).
- **`Http::fake()` does not stop the DNS check** — a test that resolves a hostname can fail as a DNS bug with zero HTTP traffic. `FreshaPage`'s unit test must touch no network seam at all, like `tests/Unit/Platforms/SquareBookingPageTest.php`.
- Tests run SQLite; nothing here touches the database. `composer test` is the gate. Do not use `--filter` (broken in this repo).
- 4-space indent, LF. Comments explain WHY. No banners, no restatements.

---

## Verification of the brief

Every claim in the source prompt was checked against `origin/development` @ `1ddbd904f`. Results:

### Confirmed

| Claim | Status |
|---|---|
| `GRAPHQL_URL` duplicated verbatim | ✅ `FreshaScraper.php:25`, `FreshaConnector.php:`**`56`** (brief said 55) |
| Identical `__NEXT_DATA__` regex | ✅ `FreshaScraper.php:235`, `FreshaConnector.php:`**`349`** (brief said 362) |
| Two diverged `resolveCurrentSlug` | ✅ `FreshaScraper.php:118`, `FreshaConnector.php:`**`230`** (brief said 243) |
| — different probe URL choice | ✅ scraper reuses a stored `/book-now/` URL **verbatim including its query** (`?share=true&pId=…`); connector always synthesises `/book-now/<slug>/all-offer` |
| — different final-URL key | ✅ `'finalUrl'` vs `headers['final-url']` |
| — inline regex, not shared `slugFromUrl()` | ✅ `FreshaConnector.php:239` re-types the exact pattern from `FreshaScraper.php:99` |
| — no try/catch on the connector side | ✅ literally true — **but justified**, see below |
| Connector docblock is a hand-sync instruction | ✅ `FreshaConnector.php:25–31` |

Both connector line citations are off by exactly 13, suggesting the brief was written against a different revision. **Use the numbers in this plan, not the brief's.**

### Corrected

1. **"This checkout is ~57 commits behind."** False. `HEAD`, `origin/development` and `FETCH_HEAD` are all `1ddbd904f`; `git rev-list --left-right --count HEAD...origin/development` → `0  0`. No pull was needed and none changed anything.

2. **"No try/catch on the connector side"** reads as an oversight; it is not. `HttpIo::get()` (`app/Ingest/Runtime/HttpIo.php:38–53`) calls `SafeUrlFetcher::tryFetch()`, which catches `SafeUrlException|ConnectionException` and returns `null` → mapped to `['status' => 0, …]`. The scraper calls the **throwing** `fetch()`, so it needs the catch. Both lanes are correct for their transport. (Residual: `tryFetch` catches only those two exception types, so an exotic `Throwable` still escapes the connector. Minor, not this refactor's business.)

3. **"Only the scraper carries the reason for preferring the `/book-now/` share form."** False. `FreshaConnector.php:225–227` states it: *"via the `book-now` share alias (kept redirecting after the canonical `/a/<slug>` goes 410)"*. Both docblocks carry the reason. What the connector lacks is the *conditional* — it never reuses a stored share URL because it is handed a **slug**, not a URL.

4. **"Its 38 test files."** There are **35** test files named `*Fresha*` (one, `StaffUserControllerFreshAal2Test.php`, is a false positive on "Fresh"), and **25** test files that reference `FreshaScraper` by name. Only **6** call its public methods directly: `fetchEmployeeServices` (7 calls), `canonicalUrl` (6), `slugFromUrl` (3), `fetchMenu` (3), `fetchLocation` (2), `resolveCurrentSlug` (1). `stripLocale`, `extractVenue`, `extractStoreName`, `extractTeam` and `extractServices` are **never** called directly from a test. The no-test-edits constraint is comfortably achievable.

5. **The proposed fixture cannot do the job asked of it.** `tests/fixtures/recorded/fresha/venue.book-now.html` decodes fine, but its `props.pageProps` keys are `locationSlug, initialData, searchParams, providersProps` — **`props.pageProps.data.location` is `NULL`**. It is a capture of `https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer` (MANIFEST, captured 2026-08-18), i.e. exactly the different Next.js route `FreshaScraper.php:210–213` warns about. It therefore **cannot** exercise `extractVenue`/`extractStoreName`/`extractTeam`/`extractServices`.

   This is not a blocker, because those four take `array $location`, not HTML — they never needed a fixture. The fixture's real value is the opposite assertion: it *proves the book-now route carries no location blob*, which is the entire reason `canonicalUrl()` exists. Task 1 pins that. (Both Fresha fixtures are currently referenced by no test at all — only by `MANIFEST.json`. This plan gives them their first consumer.)

6. **`slugFromFinalUrl()` should not exist.** The brief asks for both `slugFromUrl(string $url)` and `slugFromFinalUrl(string $finalUrl)`. They are the same regex over the same grammar applied to a URL string; adding the second name re-creates, inside the new file, the duplication the file exists to remove. Each lane already knows how to get its own final-URL string out of its own response shape (`$response['finalUrl']` vs `$response['headers']['final-url']`) — that extraction is transport, and stays put. **This plan ships `slugFromUrl()` only.** Flagging rather than silently dropping.

### Found, not in the brief

7. **A second divergence, and it is larger: the name re-caser.** The two lanes title-case scraped service names with **different algorithms, deliberately**, documented at `ScrapedNameCasing.php:10–31`:
   - `FreshaScraper::extractServices()` (`:301`) uses `$this->scanTitleCase()` from the `CasesScannedNames` trait — gates on the **whole string**.
   - `FreshaConnector::mapServiceItem()` (`:498`) uses `ScrapedNameCasing::titleCase()` — gates on the **token**, because *"an ordering-platform or booking-platform payload mixes both inside one name"*.

   Consequence for the brief's item 1: **`extractServices` is not "already pure" in the movable sense.** It calls a private instance method from a trait. Moving it to a static `FreshaPage` forces a choice — drag `CasesScannedNames` into a class that is supposed to be shared (where only one lane uses it), or switch to `titleCase()` and change what the dashboard renders. That is a ruling, not a move. See Task 5.

   **Measured, 2026-09-02.** Both casers were run over all 116 distinct real Fresha service names on dev (`site.platform_connections.payload->raw->services`). **22 differ (19%).** The divergence is not cosmetic drift — it is the documented gate difference doing exactly what it says, and neither side is uniformly better:

   | Input | `scanTitleCase` (dashboard) | `titleCase` (ingest) |
   |---|---|---|
   | `Hair coloring` | `Hair coloring` ✗ | `Hair Coloring` ✓ |
   | `Beard trim & Razor line up` | unchanged ✗ | `Beard Trim & Razor Line Up` ✓ |
   | `Clipper Cut (Buzz cut)` | unchanged ✗ | `Clipper Cut (Buzz Cut)` ✓ |
   | `Curly Hair Treat and Cut` | unchanged ✓ | `Treat **And** Cut` ✗ |
   | `Just a Few Locs` | unchanged ✓ | `Just **A** Few Locs` ✗ |
   | `Toner with Color` | unchanged ✓ | `Toner **With** Color` ✗ |

   The whole-string gate leaves mixed-case strings alone, so the dashboard never fixes a vendor's `Hair coloring`. The token gate fixes those but capitalises connector words. See finding 13 for why, and Task 0 for the half of this that is an outright bug.

13. **`ScrapedNameCasing::CONNECTORS` is declared but `titleCase()` never reads it.** Grepped across `app/`: the only consumer is `CasesScannedNames.php:75`. `ALL_CAPS_MARKS` *is* read by both (`ScrapedNameCasing.php:148` and `CasesScannedNames.php:70`), so half the shared vocabulary is genuinely shared and half is not — despite the class docblock (`:29–31`) claiming *"which connectors drop to lowercase mid-name … lived in two places and drifted; they live here once (2026-08-28)."*

    This is a live defect in the ingest lane, not a hypothetical: 6 of the 116 real dev names are published wrong today (`Just A Few Locs`, `Toner With Color`, `Curly Hair Treat And Cut`, `Restyle With Consultation`, `Junior Zero And Skin Fade`, `Blow Wave Short Or With Color`). It is independent of the extraction and ships on its own — Task 0.

    **It does not resolve Ruling B.** Measured: fixing it changes 6 names and moves the scan-vs-title divergence only from 22 to **18** of 116. The remaining 18 are the gate difference, which is the actual product decision.

8. **`extractServices` and `mapServiceItem` are not duplicates anyway.** They parse **different documents**: `extractServices` reads the venue page's `location.services` category tree; `mapServiceItem` reads the booking flow's `screenServices.categories`. They also differ in output shape (9 keys incl. `priceValue`/`currency`/`hasVariants` vs 7 incl. `categoryId`) and in id grammar — `mapServiceItem` matches `"catalogId":"(s|p):\d+"` (packages included), `fetchEmployeeServices` matches `s:\d+` only. The genuinely shared surface is **smaller than the brief implies**.

9. **Two more copies of the slug regex exist**, outside both files:
   - `app/Ingest/SourceProvisioner.php:837` — same grammar, subtly different: `~` delimiters and `([a-z0-9][a-z0-9-]*)` instead of `([a-z0-9-]+)`, so it rejects a leading hyphen the other two accept.
   - `app/Services/Platforms/Registry/Bindings/FreshaBinding.php:68` — the connect-input validator, `/a/` only, no `book-now` alternative.

   Four call sites, three grammars. Out of scope, but `FreshaPage` is where they should eventually converge. Noted so a later session starts from this fact.

10. **The `__NEXT_DATA__` regex is a platform-wide duplication of four, not a Fresha one of two.** Also at `app/Ingest/Connectors/LumaConnector.php:68` (with `~` delimiters) and described at `app/Services/Platforms/LinkInBioInlinePayloadReader.php:89`. Putting it on `FreshaPage` de-dupes 2 of 4 and leaves the seam misnamed for the others. Accepted for now — a general `NextData` helper is a bigger, separate call.

11. **`tests/Feature/Architecture/OutboundHttpGuardTest.php` is safe but will hold a stale note.** Its allowlist is keyed by **file path only**; the `['A', 'self::GRAPHQL_URL']` second element at `:92` is documentation the test never verifies. `FreshaScraper.php` keeps its `Http::post()` call and stays classified `A`; `FreshaPage.php` makes no HTTP call so never becomes a call site. Task 2 updates the note text.

12. **`tests/Feature/Architecture/FreshaMapperGuardTest.php` is unaffected** — it pins the set of `app/` files containing the literal `projectionFor(`. `FreshaPage` will not contain it.

---

## Rulings Required

**Do not resolve either of these while implementing. Tasks 1–3 are unblocked and must not depend on the answers.**

### Ruling A — which `resolveCurrentSlug` is correct?

The two differ in one behaviourally significant way (the rest is transport):

|  | `FreshaScraper::resolveCurrentSlug(string $url)` | `FreshaConnector::resolveCurrentSlug(string $slug, Io $io)` |
|---|---|---|
| Input | a URL | a bare slug |
| Probe when input is already `/book-now/…` | **reuses it verbatim, query string and all** | n/a — cannot; it has no URL |
| Probe otherwise | synthesises `…/book-now/<slug>/all-offer` | always synthesises `…/book-now/<slug>/all-offer` |
| Rejects non-Fresha input | yes (`slugFromUrl` → null) | no (any slug-shaped string is probed) |
| User-Agent | `SCRAPE_USER_AGENT` (browser) | `SafeUrlFetcher` default |

**Blast radius, measured on dev 2026-09-02** — `site.platform_connections WHERE platform = 'fresha'`, 20 rows:

| Stored `payload.url` shape | n | Which branch |
|---|---|---|
| canonical `/a/<slug>` | 18 | synthesised — **both lanes identical** |
| `/book-now/<slug>/all-offer?share=true&pId=…` | 1 | verbatim — **the only divergent row** |
| `/providers/<slug>?pId=…` | 1 | `slugFromUrl` → null, scraper bails before probing |

This is much narrower than the brief implies. The one divergent row's path is `/all-offer` — **byte-identical to what the connector synthesises**. The sole difference is the query string `?share=true&pId=2835260`.

**The live question, now precise:** does `?share=true&pId=…` change the redirect target versus no query string? `pId` reads like a partner/profile id and `share=true` like an analytics flag; neither plausibly selects a different venue. But that is inference, not evidence, and it cannot be settled offline.

**This is no longer an owner-level ruling — it is one probe against a public page.** Two GETs, compare `final-url`. Downgraded from a blocked task to a verification step (Task 4). The differing User-Agent is a second variable worth capturing in the same probe, since Fresha is known to vary on UA (`SafeUrlFetcher` carries a 403-retry path for exactly this).

The before/after harness already exists on both sides:
- ingest: `freshaRotatingIo()` in `tests/Feature/Ingest/FreshaConnectorTest.php:453–500` — an `Io` double that 410s the old slug and redirects the share alias to the new one.
- dashboard: `tests/Feature/Platforms/FreshaTeamCacheTest.php:224–253` — full rotation-persists-to-connection coverage plus the fail-closed `ConnectionException` case.

A check worth running before ruling: for a handful of real rotated dev connections, probe both forms and diff the `final-url`. That is a live third-party call against Fresha — **Standalone, do not bundle** (`CLAUDE.md` §Opportunistic fixes).

### Ruling B — should the dashboard adopt the token gate? (new; see findings 7 and 13)

**Two separable questions. Only the second is a ruling.**

**B1 — the `CONNECTORS` gap is a bug, not a decision.** `titleCase()` never reads the connector-word list it is documented to share. Six real dev names publish wrong today. Fix it on its own (Task 0), ahead of everything else, because it changes live ingest output and deserves its own before/after.

**B2 — the gate difference is the real ruling, and it survives B1.** Measured over 116 real names: 22 differ now; **18 still differ after B1**. Those 18 are `scanTitleCase`'s whole-string gate declining to touch a mixed-case string that `titleCase` would improve:

- token gate is **better** on 16: `Hair coloring` → `Hair Coloring`, `Beard trim & Razor line up` → `Beard Trim & Razor Line Up`, `Clipper Cut (Buzz cut)` → `Clipper Cut (Buzz Cut)`, `Junior Classic cut` → `Junior Classic Cut`, …
- token gate is **debatable** on 2: `BOYS Standard Haircut` → `Boys Standard Haircut` (a 4-letter caps run; `isDeliberateAllCapsRun` only preserves 2–3), `Gel-x Full Set` → `Gel-X Full Set`.

So adopting the token gate for the dashboard changes **16% of service names, overwhelmingly as corrections**. That is a product call about vendor typography, not a correctness question — `ScrapedNameCasing`'s docblock argues the split is deliberate, while the data argues the whole-string gate is simply too conservative for booking-platform payloads (which is the exact reason `titleCase()` was built).

Until B2 is answered, `extractServices` stays in `FreshaScraper` (Task 5).

**Reproduce either figure:** `php artisan tinker`, run both casers over
`SELECT DISTINCT s->>'name' FROM site.platform_connections c, LATERAL jsonb_array_elements(c.payload->'raw'->'services') s WHERE c.platform='fresha'`.

---

## File Structure

| File | Responsibility |
|---|---|
| **Create** `app/Services/Platforms/FreshaPage.php` | Pure static Fresha grammar: URL shapes, slug extraction, `__NEXT_DATA__` extraction, location-blob readers, the booking-flow GraphQL endpoint + request body. Zero I/O. |
| **Create** `tests/Unit/Platforms/FreshaPageTest.php` | Unit test for the above. No container, no `Http::fake()`, no network seam. |
| **Modify** `app/Services/Platforms/FreshaScraper.php` | Keeps transport + policy. Bodies delegate to `FreshaPage`. Every public signature unchanged. |
| **Modify** `app/Ingest/Connectors/FreshaConnector.php` | Private duplicates delegate to `FreshaPage`. Its own probe URL, response-key extraction and `Unavailable` policy stay. |
| **Modify** `tests/Feature/Architecture/OutboundHttpGuardTest.php:92` | Justification note only — `self::GRAPHQL_URL` → `FreshaPage::GRAPHQL_URL`. |

**Not moving, and why:** `lastResolvedSlug` (mutable per-instance state), the `FetchBudget` clamp (`FreshaScraper.php:347–366`), the storewide-degrade decision (the `return null` contract at `:361`/`:422`/`:440`/`:455`, consumed by `FreshaController.php:548,654`, `FreshaAutoSelector.php:55,77`, `FreshaFetch.php:84`), the `abort(502)` messages, `SCRAPE_USER_AGENT`, and `GRAPHQL_TIMEOUT_SECONDS`. All transport or policy.

---

### Task 0: `titleCase()` honours `CONNECTORS` — independent bug, ship first

Not part of the extraction. It changes live ingest output for 6 of 116 real dev service names, so it gets its own commit and its own before/after. Doing it first also removes the noisier half of Ruling B before anyone has to think about the rest.

**Files:**
- Modify: `app/Services/Platforms/ScrapedNameCasing.php:53–100` (`titleCase()`)
- Test: `tests/Unit/Platforms/CasesScannedNamesTest.php` (add cases; the file already covers both casers)

**Interfaces:**
- Consumes: nothing.
- Produces: no signature change. `ScrapedNameCasing::titleCase(?string): ?string` behaves differently on connector words only.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Platforms/CasesScannedNamesTest.php`:

```php
// CONNECTORS is documented as the vocabulary both re-casers share
// (ScrapedNameCasing docblock), but titleCase() never read it — so the ingest
// lane published "Just A Few Locs" and "Toner With Color". Six real dev names
// were affected (2026-09-02).
it('keeps connector words lowercase mid-name', function (string $in, string $out) {
    expect(ScrapedNameCasing::titleCase($in))->toBe($out);
})->with([
    ['Just a Few Locs', 'Just a Few Locs'],
    ['Toner with Color', 'Toner with Color'],
    ['Curly Hair Treat and Cut', 'Curly Hair Treat and Cut'],
    ['Restyle with consultation', 'Restyle with Consultation'],
    ['Junior Zero and skin fade', 'Junior Zero and Skin Fade'],
    ['Blow Wave Short Or with Color', 'Blow Wave Short Or with Color'],
]);

// First and last word always capitalise, even when they are connector words.
it('still capitalises a connector word at either edge', function () {
    expect(ScrapedNameCasing::titleCase('the works'))->toBe('The Works')
        ->and(ScrapedNameCasing::titleCase('walk in'))->toBe('Walk In');
});

// A connector after '-', '(' or '/' opens a new clause the source capitalised
// on purpose — "Manicure - With Gel Polish" sits beside "Manicure - No Gel
// Polish" in live dev data, and downcasing only one of the pair is wrong.
it('capitalises a connector that opens a new clause', function () {
    expect(ScrapedNameCasing::titleCase('Manicure - With Gel Polish'))->toBe('Manicure - With Gel Polish');
});
```

- [ ] **Step 2: Run it and verify it fails**

```bash
php artisan test tests/Unit/Platforms/CasesScannedNamesTest.php
```

Expected: FAIL — `Just A Few Locs` / `Toner With Color` etc., and `Manicure - with Gel Polish` once a naive fix is in.

- [ ] **Step 3: Implement**

In `ScrapedNameCasing::titleCase()`, capture the first/last letter-run offsets before the callback, and skip capitalisation for a mid-name connector that follows whitespace and does **not** open a clause:

```php
        // First and last letter-run always capitalise, connector word or not.
        preg_match_all('/\p{L}+/u', $s, $runs, PREG_OFFSET_CAPTURE);
        $offsets = array_column($runs[0], 1);
        $firstOffset = $offsets[0] ?? -1;
        $lastOffset = $offsets === [] ? -1 : end($offsets);

        $out = preg_replace_callback(
            '/\p{L}+/u',
            function (array $m) use ($s, $firstOffset, $lastOffset) {
                [$run, $offset] = $m[0];

                if (self::hasInteriorCapital($run)
                    || self::isPreservedAllCapsMark($run)
                    || self::isDeliberateAllCapsRun($run, $s)) {
                    return $run;
                }

                $run = mb_strtolower($run);
                $prev = $offset === 0 ? '' : $s[$offset - 1];
                $boundary = $offset === 0 || strpos(" \t\r\n\f\v/-(", $prev) !== false;

                // CONNECTORS — the vocabulary this class exists to share, which
                // titleCase() never actually read until 2026-09-02. Lowercase
                // only MID-name, only after whitespace, and only when the
                // preceding non-space character does not open a new clause:
                // "Manicure - With Gel Polish" keeps its capital, "Toner with
                // Color" loses one.
                $afterSpace = $prev !== '' && strpos(" \t\r\n\f\v", $prev) !== false;
                $clauseHead = rtrim(substr($s, 0, $offset));
                $opensClause = $clauseHead !== '' && strpos('-(/,:', substr($clauseHead, -1)) !== false;
                $edge = $offset === $firstOffset || $offset === $lastOffset;

                if (! $edge && $afterSpace && ! $opensClause && in_array($run, self::CONNECTORS, true)) {
                    return $run;
                }

                return $boundary
                    ? mb_strtoupper(mb_substr($run, 0, 1)).mb_substr($run, 1)
                    : $run;
            },
            $s,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $s;
```

Also correct the class docblock at `:29–31`: the vocabulary claim was true of `ALL_CAPS_MARKS` and false of `CONNECTORS` until this commit.

- [ ] **Step 4: Run the test and the lanes that consume the caser**

```bash
php artisan test tests/Unit/Platforms tests/Feature/Ingest tests/Feature/Platforms
```

Expected: PASS. Watch for menu-lane fixtures — `NormalizesMenuData` delegates to `titleCase()`, so a menu name containing a mid-name connector will move too. That is the same correction, not a regression; update any fixture that asserts the old output and say so in the commit body.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Services/Platforms/ScrapedNameCasing.php tests/Unit/Platforms/CasesScannedNamesTest.php
git add app/Services/Platforms/ScrapedNameCasing.php tests/Unit/Platforms/CasesScannedNamesTest.php
git commit -m "Scraped-name casing: titleCase() finally reads the connector list it shares

CONNECTORS was declared here and consumed only by CasesScannedNames, so the
ingest lane published 'Just A Few Locs' and 'Toner With Color'. Six of 116 real
dev Fresha names corrected. A connector opening a clause after '-' or '(' keeps
its capital."
```

---

### Task 1: Create `FreshaPage` and its unit test

Pure addition. Nothing calls it yet, so nothing can regress.

**Files:**
- Create: `app/Services/Platforms/FreshaPage.php`
- Test: `tests/Unit/Platforms/FreshaPageTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `const GRAPHQL_URL = 'https://www.fresha.com/graphql'`
  - `static stripLocale(string $url): string`
  - `static canonicalUrl(string $url): string`
  - `static slugFromUrl(string $url): ?string`
  - `static shareProbeUrl(string $slug): string`
  - `static nextDataJson(string $body): ?string`
  - `static parseNextData(string $body): ?array`
  - `static locationFrom(array $nextData): ?array`
  - `static extractStoreName(array $location): ?string`
  - `static extractVenue(array $location): ?array`
  - `static extractTeam(array $location): array`
  - `static bookingFlowPayload(string $slug, ?string $employeeId, string $hash, string $clientVersion): array`

`nextDataJson()` and `parseNextData()` are both public on purpose: the connector wants "decoded or null" in one step, while the scraper must keep its two *distinct* 502 messages ("did not contain" vs "failed to decode"). Neither message is asserted by any test today, but collapsing two diagnostics into one is a regression regardless. The regex — the actually-duplicated thing — lives once; the error policy stays per-lane.

`bookingFlowPayload()` takes `$hash`/`$clientVersion` as **arguments** rather than reading `config()`, because `FreshaPage` does no I/O and `config()` is a container read. Each lane resolves its own config and passes it in — which also preserves the scraper's `config(...)` return type handling and the connector's `(string)` casts exactly as they are.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Platforms/FreshaPageTest.php`. Note the style: plain Pest, **no** `uses(TestCase::class)`, no container, no `Http::fake()` — matching `tests/Unit/Platforms/SquareBookingPageTest.php`.

```php
<?php

use App\Services\Platforms\FreshaPage;

function freshaRecordedPage(string $name): string
{
    return file_get_contents(dirname(__DIR__, 2).'/fixtures/recorded/fresha/'.$name);
}

/** The venue-page location blob, hand-built: the recorded fixtures are book-now pages and carry none (see Task 1 Step 1 note). */
function freshaLocationBlob(): array
{
    return [
        'name' => 'Anseo Studio',
        'contactNumber' => '+61 3 9999 0000',
        'countryCode' => 'AU',
        'address' => [
            'streetAddress' => '140a Chapel Street',
            'cityName' => 'Windsor',
            'postalCode' => '3181',
            'region1' => 'VIC',
            'countryCode' => 'AU',
            'latitude' => -37.8551,
            'longitude' => 144.9928,
            'mapsUrl' => 'https://maps.google.com/?q=-37.8551,144.9928',
        ],
        'employeeProfiles' => ['edges' => [
            ['node' => [
                'employeeId' => 'emp-1',
                'displayName' => 'Simon',
                'jobTitle' => 'Stylist',
                'avatar' => ['url' => 'https://cdn.fresha.com/simon.jpg'],
                'rating' => 4.8,
            ]],
        ]],
    ];
}

it('strips the locale segment from a canonical url', function () {
    expect(FreshaPage::stripLocale('https://www.fresha.com/en-GB/a/anseo-studio-v0v92jna'))
        ->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna');
    expect(FreshaPage::stripLocale('https://www.fresha.com/a/anseo-studio-v0v92jna'))
        ->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna');
});

it('canonicalises a share url and leaves a canonical one alone', function (string $in, string $out) {
    expect(FreshaPage::canonicalUrl($in))->toBe($out);
})->with([
    ['https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260', 'https://www.fresha.com/a/anseo-studio-v0v92jna'],
    ['https://fresha.com/book-now/anseo-studio-v0v92jna/all-offer', 'https://www.fresha.com/a/anseo-studio-v0v92jna'],
    ['https://www.fresha.com/a/anseo-studio-v0v92jna', 'https://www.fresha.com/a/anseo-studio-v0v92jna'],
]);

it('reads the slug from both url shapes, and refuses a foreign host', function (string $url, ?string $slug) {
    expect(FreshaPage::slugFromUrl($url))->toBe($slug);
})->with([
    ['https://www.fresha.com/a/anseo-studio-v0v92jna', 'anseo-studio-v0v92jna'],
    ['https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?pId=2835260', 'anseo-studio-v0v92jna'],
    ['https://www.fresha.com/en-GB/a/anseo-studio-melbourne-w8ajp04r/booking?menu=true', 'anseo-studio-melbourne-w8ajp04r'],
    ['https://example.com/?next=https://www.fresha.com/a/anseo-studio-v0v92jna', null],
    ['https://www.fresha.com/', null],
]);

it('builds the share probe url', function () {
    expect(FreshaPage::shareProbeUrl('anseo-studio-v0v92jna'))
        ->toBe('https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer');
});

it('pulls __NEXT_DATA__ out of a real recorded fresha page', function () {
    $data = FreshaPage::parseNextData(freshaRecordedPage('venue.book-now.html'));

    expect($data)->toBeArray()
        ->and($data['buildId'] ?? null)->toBeString()
        ->and(FreshaPage::nextDataJson(freshaRecordedPage('venue.book-now.html')))->toBeString();
});

// This is WHY canonicalUrl() exists: the share URL is a different Next.js
// route whose __NEXT_DATA__ carries no location blob, so scraping a stored
// share URL verbatim yields an empty menu (-> fresha_no_services).
it('proves the book-now route carries no location blob', function (string $file) {
    $data = FreshaPage::parseNextData(freshaRecordedPage($file));

    expect($data)->toBeArray()
        ->and(FreshaPage::locationFrom($data))->toBeNull();
})->with(['venue.book-now.html', 'venue.book-now-hair.html']);

it('returns null for a page with no __NEXT_DATA__ and for undecodable json', function () {
    expect(FreshaPage::nextDataJson('<html><body>nope</body></html>'))->toBeNull()
        ->and(FreshaPage::parseNextData('<html><body>nope</body></html>'))->toBeNull()
        ->and(FreshaPage::parseNextData('<script id="__NEXT_DATA__">{not json</script>'))->toBeNull();
});

it('reads the location blob when one is present', function () {
    $body = '<script id="__NEXT_DATA__" type="application/json">'
        .json_encode(['props' => ['pageProps' => ['data' => ['location' => ['name' => 'Anseo Studio']]]]])
        .'</script>';

    expect(FreshaPage::locationFrom(FreshaPage::parseNextData($body)))->toBe(['name' => 'Anseo Studio']);
});

it('extracts the store name, or null when absent or empty', function () {
    expect(FreshaPage::extractStoreName(freshaLocationBlob()))->toBe('Anseo Studio')
        ->and(FreshaPage::extractStoreName(['name' => '']))->toBeNull()
        ->and(FreshaPage::extractStoreName([]))->toBeNull();
});

it('extracts the venue identity, and null when there is no address', function () {
    expect(FreshaPage::extractVenue(freshaLocationBlob()))->toBe([
        'name' => 'Anseo Studio',
        'street' => '140a Chapel Street',
        'city' => 'Windsor',
        'postcode' => '3181',
        'region' => 'VIC',
        'country' => 'AU',
        'lat' => -37.8551,
        'lng' => 144.9928,
        'phone' => '+61 3 9999 0000',
        'mapsUrl' => 'https://maps.google.com/?q=-37.8551,144.9928',
    ]);

    expect(FreshaPage::extractVenue(['name' => 'Anseo Studio']))->toBeNull();
});

it('extracts the team, and an empty list when the edges are missing', function () {
    expect(FreshaPage::extractTeam(freshaLocationBlob()))->toBe([[
        'employeeId' => 'emp-1',
        'displayName' => 'Simon',
        'jobTitle' => 'Stylist',
        'avatarUrl' => 'https://cdn.fresha.com/simon.jpg',
        'rating' => 4.8,
    ]]);

    expect(FreshaPage::extractTeam([]))->toBe([])
        ->and(FreshaPage::extractTeam(['employeeProfiles' => ['edges' => 'nope']]))->toBe([]);
});

// The shape both lanes fire at Fresha. Pinned key-for-key because the two
// used to be hand-synced from a docblock instruction.
it('builds the booking-flow persisted-query payload both lanes send', function () {
    $payload = FreshaPage::bookingFlowPayload('anseo-studio-v0v92jna', 'emp-1', 'abc123', '1.2.3');

    expect($payload['operationName'])->toBe('BookingFlow_Initialize_Mutation')
        ->and($payload['variables']['input']['locationSlug'])->toBe('anseo-studio-v0v92jna')
        ->and($payload['variables']['input']['options']['employeeId'])->toBe('emp-1')
        // The picker screen has an empty screenServices — never send true.
        ->and($payload['variables']['input']['options']['shouldShowAllEmployees'])->toBeFalse()
        ->and($payload['variables']['input']['capabilities'])
        ->toBe(['SERVICE_ADDONS', 'CONFIRMATION', 'FULL_UPFRONT_PAYMENT', 'MARKETPLACE_REFRESH'])
        ->and($payload['extensions']['persistedQuery'])->toBe(['version' => 1, 'sha256Hash' => 'abc123'])
        ->and($payload['extensions']['version'])->toBe('1.2.3');
});

it('sends a null employeeId for the storewide menu', function () {
    expect(FreshaPage::bookingFlowPayload('slug', null, 'h', 'v')['variables']['input']['options']['employeeId'])
        ->toBeNull();
});
```

- [ ] **Step 2: Run the test and verify it fails**

```bash
php artisan test tests/Unit/Platforms/FreshaPageTest.php
```

Expected: FAIL — `Class "App\Services\Platforms\FreshaPage" not found`.

- [ ] **Step 3: Write `FreshaPage`**

Create `app/Services/Platforms/FreshaPage.php`. Method bodies are lifted **verbatim** from `FreshaScraper` (`stripLocale`, `canonicalUrl`, `slugFromUrl`, `extractVenue`, `extractStoreName`, `extractTeam`) and from the two payload builders, converted to `static` and to `self::` where they referenced `$this`.

```php
<?php

namespace App\Services\Platforms;

/**
 * Pure reader of Fresha's public venue page and booking-flow GraphQL shape —
 * URL grammar, the `__NEXT_DATA__` blob, and the persisted-query request body.
 *
 * Shared by FreshaScraper (dashboard, via SafeUrlFetcher) and FreshaConnector
 * (ingest, via Io) so the two cannot drift on a regex or a request shape
 * again — the connector's docblock used to carry a hand-sync instruction
 * ("the exact persisted-query shape FreshaScraper::fetchEmployeeServices()"),
 * which is a comment where a function belongs. Same split as
 * SquareBookingPage/SquareBookingClient.
 *
 * ZERO I/O, and that is load-bearing: no Http, no SafeUrlFetcher, no Io, no
 * config(), no abort(). Transport and error POLICY stay in the lanes — the
 * scraper aborts 502, the connector yields Unavailable, and each reads its
 * own response shape. Only grammar lives here.
 */
final class FreshaPage
{
    /** Fresha's internal booking GraphQL — the call the booking page fires. */
    public const GRAPHQL_URL = 'https://www.fresha.com/graphql';

    /** Drop the locale segment so we always cache the canonical /a/<slug> form. */
    public static function stripLocale(string $url): string
    {
        return preg_replace('#fresha\.com/[a-z]{2,3}(-[a-z]{2})?/a/#i', 'fresha.com/a/', $url) ?? $url;
    }

    /**
     * Rewrite a booking-page URL to the canonical `/a/<slug>` form.
     *
     * Bio links are almost always the share URL Fresha's own app hands out
     * (`/book-now/<slug>/all-offer?share=true&pId=…`), but slugFromUrl() and the
     * connect-input validator both only understand `/a/<slug>`. Canonicalising at
     * WRITE time (resolveWrite) rather than read time is deliberate: GET
     * /platforms/fresha/team re-scrapes from payload.url, so the user's own
     * recovery path needs a usable URL just as much as our auto-fetch does.
     */
    public static function canonicalUrl(string $url): string
    {
        return preg_replace(
            '#^(https?://)(?:www\.)?fresha\.com/book-now/([a-z0-9-]+)(?:/[^?\#]*)?.*$#i',
            'https://www.fresha.com/a/$2',
            $url
        ) ?? $url;
    }

    /**
     * Extract the `<slug>` from a Fresha `/a/<slug>` or `/book-now/<slug>/…` URL.
     *
     * Host-anchored (an unanchored `/a/…` would match inside a foreign query
     * string), optional locale segment (Fresha's own redirects land on
     * `/en-GB/a/<slug>/booking?…`). The three write paths that pre-date link
     * routing all canonicalise before they store, so this only ever saw `/a/`.
     * The routing lane does not: SourceReconciler and SuggestionApplier write
     * `intent.canonical_url` verbatim, which for a Fresha link-in-bio is the
     * share URL.
     *
     * Two further copies of this grammar still live at SourceProvisioner.php
     * (`[a-z0-9][a-z0-9-]*`, rejects a leading hyphen) and FreshaBinding.php
     * (`/a/` only) — three grammars, four sites. Converging them is separate
     * work; this is where they should land.
     */
    public static function slugFromUrl(string $url): ?string
    {
        return preg_match('#^https?://(?:www\.)?fresha\.com/(?:[a-z]{2,3}(?:-[a-z]{2})?/)?(?:a|book-now)/([a-z0-9-]+)#i', $url, $m) ? $m[1] : null;
    }

    /**
     * The share-alias URL to probe when asking Fresha what it calls a venue
     * today: the canonical `/a/<slug>` page 410s once a slug rotates, while
     * the `book-now` alias keeps redirecting.
     */
    public static function shareProbeUrl(string $slug): string
    {
        return 'https://www.fresha.com/book-now/'.rawurlencode($slug).'/all-offer';
    }

    /**
     * The raw `__NEXT_DATA__` JSON string, or null when the page has no such
     * script tag.
     *
     * Separate from parseNextData() so a caller can tell "structure moved"
     * apart from "JSON is broken" — FreshaScraper raises a different 502 for
     * each, and one null cannot carry both.
     */
    public static function nextDataJson(string $body): ?string
    {
        return preg_match('#<script id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $body, $m) ? $m[1] : null;
    }

    /** The decoded `__NEXT_DATA__` document, or null if absent or undecodable. */
    public static function parseNextData(string $body): ?array
    {
        $json = self::nextDataJson($body);
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * The `props.pageProps.data.location` blob, or null.
     *
     * Null is the NORMAL answer for a `/book-now/<slug>/all-offer` page: that
     * is a different Next.js route and carries no location. Pinned by
     * FreshaPageTest against both recorded fixtures — it is the whole reason
     * canonicalUrl() exists.
     *
     * @param  array<string, mixed>  $nextData
     * @return array<string, mixed>|null
     */
    public static function locationFrom(array $nextData): ?array
    {
        $location = data_get($nextData, 'props.pageProps.data.location');

        return is_array($location) ? $location : null;
    }

    /** The salon's display name from the Fresha location blob. */
    public static function extractStoreName(array $location): ?string
    {
        $name = $location['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The venue's identity beyond its name (owner, 2026-08-19): what a
     * partna account's Fresha connect hands FreshaWorkplaceLinker so it can
     * find the same place on Google. Every key optional; null when Fresha's
     * blob has no address.
     *
     * @return array{name:?string, street:?string, city:?string, postcode:?string, region:?string, country:?string, lat:?float, lng:?float, phone:?string, mapsUrl:?string}|null
     */
    public static function extractVenue(array $location): ?array
    {
        $address = data_get($location, 'address');
        if (! is_array($address)) {
            return null;
        }
        $str = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $num = static fn (mixed $v): ?float => is_numeric($v) ? (float) $v : null;

        return [
            'name' => self::extractStoreName($location),
            'street' => $str($address['streetAddress'] ?? null),
            'city' => $str($address['cityName'] ?? null),
            'postcode' => $str($address['postalCode'] ?? null),
            'region' => $str($address['region1'] ?? null),
            'country' => $str($address['countryCode'] ?? ($location['countryCode'] ?? null)),
            'lat' => $num($address['latitude'] ?? null),
            'lng' => $num($address['longitude'] ?? null),
            'phone' => $str($location['contactNumber'] ?? null),
            'mapsUrl' => $str($address['mapsUrl'] ?? null),
        ];
    }

    /**
     * @return list<array{employeeId:string, displayName:string, jobTitle:?string, avatarUrl:?string, rating:?float}>
     */
    public static function extractTeam(array $location): array
    {
        $edges = data_get($location, 'employeeProfiles.edges', []);
        if (! is_array($edges)) {
            return [];
        }

        return array_values(array_map(static function (array $edge): array {
            $node = $edge['node'] ?? [];

            return [
                'employeeId' => (string) ($node['employeeId'] ?? ''),
                'displayName' => (string) ($node['displayName'] ?? ''),
                'jobTitle' => $node['jobTitle'] ?? null,
                'avatarUrl' => data_get($node, 'avatar.url'),
                'rating' => isset($node['rating']) ? (float) $node['rating'] : null,
            ];
        }, $edges));
    }

    /**
     * The booking-flow persisted-query request body, identical for both lanes.
     *
     * `$employeeId` null means the location's own (storewide) menu.
     * `shouldShowAllEmployees: true` returns the employee PICKER screen, whose
     * screenServices is `{}` — which is why the ingest stream landed zero
     * records from 2026-07-28. It is hardcoded false and must stay so.
     *
     * The hash and client version are PASSED IN, not read from config(), so
     * this class stays I/O-free; each lane resolves
     * config('services.fresha.*') itself.
     *
     * @return array<string, mixed>
     */
    public static function bookingFlowPayload(string $slug, ?string $employeeId, string $hash, string $clientVersion): array
    {
        return [
            'operationName' => 'BookingFlow_Initialize_Mutation',
            'variables' => [
                'fullUpfrontPaymentEnabled' => true,
                'discountsAndBenefitsEnabled' => false,
                'input' => [
                    'locationSlug' => $slug,
                    'referer' => '',
                    'options' => [
                        'employeeId' => $employeeId,
                        'shouldShowAllEmployees' => false,
                        'isGroupBooking' => false,
                        'isRebook' => false,
                        'isFromLinkBuilder' => false,
                        'clientChannelType' => 'MARKETPLACE',
                        'cartId' => null,
                        'offerItemId' => null,
                        'offerItems' => null,
                    ],
                    'shouldAutoContinue' => true,
                    'capabilities' => ['SERVICE_ADDONS', 'CONFIRMATION', 'FULL_UPFRONT_PAYMENT', 'MARKETPLACE_REFRESH'],
                ],
            ],
            'extensions' => [
                'persistedQuery' => ['version' => 1, 'sha256Hash' => $hash],
                'platform' => 'web',
                'version' => $clientVersion,
            ],
        ];
    }
}
```

- [ ] **Step 4: Run the test and verify it passes**

```bash
php artisan test tests/Unit/Platforms/FreshaPageTest.php
```

Expected: PASS, all cases.

- [ ] **Step 5: Format and commit**

```bash
php artisan pint app/Services/Platforms/FreshaPage.php tests/Unit/Platforms/FreshaPageTest.php
git add app/Services/Platforms/FreshaPage.php tests/Unit/Platforms/FreshaPageTest.php
git commit -m "Fresha: one pure reader for the page grammar both lanes parse"
```

---

### Task 2: `FreshaScraper` delegates to `FreshaPage`

**Files:**
- Modify: `app/Services/Platforms/FreshaScraper.php` — remove `GRAPHQL_URL` (`:25`); replace bodies of `stripLocale` (`:58`), `canonicalUrl` (`:73`), `slugFromUrl` (`:93`), `extractVenue` (`:176`), `extractStoreName` (`:200`), `extractTeam` (`:252`); rewrite the parse block in `fetchLocation` (`:235–246`); rewrite the probe in `resolveCurrentSlug` (`:128–130`) and the payload in `fetchEmployeeServices` (`:370–398`).
- Modify: `tests/Feature/Architecture/OutboundHttpGuardTest.php:92` — justification note only.

**Interfaces:**
- Consumes: everything Task 1 produces.
- Produces: no signature changes. `FreshaScraper`'s public API is byte-for-byte the same.

- [ ] **Step 1: Establish the green baseline before touching anything**

```bash
php artisan test tests/Unit/Platforms tests/Feature/Platforms tests/Feature/Ingest
```

Record the pass/fail counts. This is the number Step 5 must reproduce exactly. If anything is already red, **stop and report** — do not start a refactor on a red baseline (`feedback_verify_implementer_failure_claims`).

- [ ] **Step 2: Delegate the six pure methods and delete the const**

Keep each docblock where it is (callers read the scraper, not `FreshaPage`); replace only the bodies.

```php
public function stripLocale(string $url): string
{
    return FreshaPage::stripLocale($url);
}

public function canonicalUrl(string $url): string
{
    return FreshaPage::canonicalUrl($url);
}

public function slugFromUrl(string $url): ?string
{
    return FreshaPage::slugFromUrl($url);
}

public function extractVenue(array $location): ?array
{
    return FreshaPage::extractVenue($location);
}

public function extractStoreName(array $location): ?string
{
    return FreshaPage::extractStoreName($location);
}

public function extractTeam(array $location): array
{
    return FreshaPage::extractTeam($location);
}
```

Delete `private const GRAPHQL_URL` (`:23–25`) and change the one use at `:408` to `FreshaPage::GRAPHQL_URL`. `FreshaPage` is in the same namespace, so no `use` statement is needed.

Leave `extractServices` (`:279`) **untouched** — it calls `$this->scanTitleCase()` and is blocked on Ruling B2 (Task 5).

- [ ] **Step 3: Rewrite `fetchLocation`'s parse block, preserving both 502 messages**

Replace `FreshaScraper.php:235–246` with:

```php
        // Two distinct 502s, deliberately: "the page shape moved" and "Fresha
        // served us broken JSON" are different incidents. FreshaPage owns the
        // regex; this lane owns what to do when it misses.
        $json = FreshaPage::nextDataJson($response['body']);
        if ($json === null) {
            abort(502, 'Fresha page did not contain __NEXT_DATA__ — structure may have changed.');
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            abort(502, 'Failed to decode __NEXT_DATA__ JSON.');
        }

        return FreshaPage::locationFrom($data) ?? [];
```

Note the last line: the old code was `data_get($data, 'props.pageProps.data.location', [])` then `is_array($location) ? $location : []`. `locationFrom() ?? []` is exactly equivalent for both the missing and the non-array case.

- [ ] **Step 4: Delegate the probe URL and the GraphQL payload**

In `resolveCurrentSlug`, replace only the synthesised arm (`:128–130`) — **the conditional stays**, it is the behaviour Ruling A is about:

```php
        // Prefer the share form for the probe when we only hold a canonical
        // one: `/a/<old>` is exactly the page that goes 410, whereas Fresha
        // keeps the `book-now` alias redirecting.
        //
        // The stored share URL is reused VERBATIM (query string included).
        // Whether that matters is Ruling A — do not collapse this to
        // shareProbeUrl($given) without it.
        $probe = preg_match('#/book-now/#i', $url) === 1
            ? $url
            : FreshaPage::shareProbeUrl($given);
```

In `fetchEmployeeServices`, replace the `$payload = [...]` literal (`:370–398`) with:

```php
        $payload = FreshaPage::bookingFlowPayload(
            $slug,
            $employeeId,
            (string) config('services.fresha.booking_init_hash'),
            (string) $clientVersion,
        );
```

`$clientVersion` is already resolved at `:368` and is still used for the `x-client-version` header, so leave that line alone.

- [ ] **Step 5: Run the full baseline again — it must match Step 1 exactly**

```bash
php artisan test tests/Unit/Platforms tests/Feature/Platforms tests/Feature/Ingest
```

Expected: identical pass/fail counts to Step 1, **with zero test files edited**. If any test needed changing, the split is wrong — revert and report rather than editing the test.

- [ ] **Step 6: Update the outbound-guard justification note**

`tests/Feature/Architecture/OutboundHttpGuardTest.php:92`:

```php
    'app/Services/Platforms/FreshaScraper.php' => ['A', 'FreshaPage::GRAPHQL_URL'],
```

This element is documentation the guard never verifies (it keys on file path), but a stale note is how the next reader gets misled.

- [ ] **Step 7: Run the guards and the full suite**

```bash
php artisan test tests/Feature/Architecture
composer test
```

Expected: green.

- [ ] **Step 8: Format and commit**

```bash
php artisan pint app/Services/Platforms/FreshaScraper.php tests/Feature/Architecture/OutboundHttpGuardTest.php
git add app/Services/Platforms/FreshaScraper.php tests/Feature/Architecture/OutboundHttpGuardTest.php
git commit -m "Fresha scraper: keep the transport, borrow the grammar

Public signatures unchanged; no test edited. extractServices stays behind
pending the caser ruling."
```

---

### Task 3: `FreshaConnector` delegates to `FreshaPage`

Behaviour-preserving. The connector keeps its own probe URL, its own response-key extraction, and its own `Unavailable` policy — only the regex, the const and the payload come from `FreshaPage`.

**Files:**
- Modify: `app/Ingest/Connectors/FreshaConnector.php` — remove `GRAPHQL_URL` (`:56`); rewrite `resolveCurrentSlug`'s regex line (`:239`); rewrite `fetchLocationBlob` (`:343–356`); rewrite the payload in `fetchBookingFlow` (`:540–566`); update the class docblock (`:25–31`).

**Interfaces:**
- Consumes: `FreshaPage::{GRAPHQL_URL, slugFromUrl, shareProbeUrl, parseNextData, locationFrom, bookingFlowPayload}`.
- Produces: nothing new. All four touched methods keep their signatures and their `private` visibility.

- [ ] **Step 1: Baseline the ingest lane**

```bash
php artisan test tests/Feature/Ingest tests/Unit/Ingest
```

Record the counts.

- [ ] **Step 2: Import `FreshaPage` and drop the duplicated const**

Add to the `use` block (alphabetical, after `App\Services\Platforms\ScrapedNameCasing;`):

```php
use App\Services\Platforms\FreshaPage;
```

Delete `private const GRAPHQL_URL` (`:56`) and change `:573` to `FreshaPage::GRAPHQL_URL`.

- [ ] **Step 3: Delegate the slug regex, keeping the probe and the key**

Replace `resolveCurrentSlug`'s body. **The probe URL and the `headers['final-url']` read stay** — the probe is Ruling A, the key is this lane's transport.

```php
    private function resolveCurrentSlug(string $slug, Io $io): ?string
    {
        // Probe form is deliberately the synthesised share alias, NOT a stored
        // URL — this lane is handed a slug and has no URL to replay. Whether
        // that differs from the dashboard lane in a way that matters is an
        // open ruling; do not "unify" it here.
        $response = $io->get(FreshaPage::shareProbeUrl($slug));
        // No `?? 0`: Io::get() returns array{status: int, body: string, headers: array}.
        if ($response['status'] !== 200) {
            return null;
        }

        // Io renames SafeUrlFetcher's `finalUrl` to `headers['final-url']`;
        // reading the right key is transport, the grammar below is not.
        return FreshaPage::slugFromUrl((string) ($response['headers']['final-url'] ?? ''));
    }
```

- [ ] **Step 4: Delegate the `__NEXT_DATA__` parse**

Replace `fetchLocationBlob`'s body (`:343–356`):

```php
    private function fetchLocationBlob(string $slug, Io $io): ?array
    {
        $response = $io->get('https://www.fresha.com/a/'.rawurlencode($slug));
        if ($response['status'] !== 200 || $response['body'] === '') {
            return null;
        }
        $data = FreshaPage::parseNextData($response['body']);

        return $data === null ? null : FreshaPage::locationFrom($data);
    }
```

Equivalent to the original for every input: missing script tag, bad JSON, and non-array location all still yield `null`.

- [ ] **Step 5: Delegate the GraphQL payload**

In `fetchBookingFlow`, replace the `$body = [...]` literal (`:540–566`) with:

```php
        // 'storewide' is the reserved token for "the location's own menu";
        // anything else is an employee id.
        $employeeId = ($selectionRef === null || $selectionRef === 'storewide') ? null : $selectionRef;

        $body = FreshaPage::bookingFlowPayload($slug, $employeeId, $hash, $clientVersion);
```

`$clientVersion` and `$hash` are already resolved at the top of the method and `$clientVersion` is still used for the `x-client-version` header — leave both lines alone. Keep the `shouldShowAllEmployees` explanation as a comment on `$employeeId`; the flag itself now lives in `FreshaPage::bookingFlowPayload()`'s docblock.

- [ ] **Step 6: Replace the hand-sync instruction in the class docblock**

`FreshaConnector.php:25–31` currently tells a future reader to keep two files in step by hand. Replace that sentence with the seam:

```php
 * Fresha via its pinned internal booking-flow GraphQL. The request shape —
 * URL, operation name, persisted-query envelope — comes from
 * App\Services\Platforms\FreshaPage, shared with FreshaScraper, so the two
 * lanes cannot drift; this connector generalises it from one employee's
 * filtered menu to whichever menu the owner chose. `Pull.config['selection_ref']`
 * carries an employee id or the literal 'storewide'.
```

Leave the rest of the docblock (the deleted `profile` stream, the re-pin runbook) as is.

- [ ] **Step 7: Run the ingest lane and confirm it matches Step 1**

```bash
php artisan test tests/Feature/Ingest tests/Unit/Ingest
```

Expected: identical counts, zero test files edited. `FreshaConnectorTest`'s rotation coverage (`freshaRotatingIo`, `:453–500`) is the one that proves the `final-url` path still works — it must stay green untouched.

- [ ] **Step 8: Full suite, then format and commit**

```bash
composer test
php artisan pint app/Ingest/Connectors/FreshaConnector.php
git add app/Ingest/Connectors/FreshaConnector.php
git commit -m "Fresha connector: read the shared grammar instead of a hand-sync comment

Probe URL, final-url key and Unavailable policy unchanged — only the regex,
the endpoint const and the persisted-query body now come from FreshaPage."
```

---

### Task 4: Unify `resolveCurrentSlug` — one probe, then decide

**Downgraded from "blocked on an owner ruling" once the blast radius was measured** (see Ruling A): 18 of 20 dev connections take an identical code path in both lanes, 1 bails before probing, and the single divergent row differs *only* by the query string `?share=true&pId=…` on an otherwise byte-identical URL. This is a verification step, not a decision — but it still ships separately from Tasks 1–3, because if the probe comes back different it changes which slug the scheduler lands on for every rotated venue.

**Files (after Step 1):**
- Modify: `app/Services/Platforms/FreshaPage.php` — add the agreed probe-choice helper.
- Modify: `app/Services/Platforms/FreshaScraper.php:118–143`
- Modify: `app/Ingest/Connectors/FreshaConnector.php:230–241`
- Test: `tests/Feature/Ingest/FreshaConnectorTest.php`, `tests/Feature/Platforms/FreshaTeamCacheTest.php`

- [ ] **Step 1: Probe both forms against live Fresha and compare `final-url`**

Four read-only GETs — two URL forms × two User-Agents — against the one divergent dev row's slug (`anseo-studio-v0v92jna`) and, if it still resolves, a known-rotated slug:

```bash
UA_BROWSER='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
SLUG=anseo-studio-v0v92jna

for ua in "$UA_BROWSER" ""; do
  for url in \
    "https://www.fresha.com/book-now/$SLUG/all-offer?share=true&pId=2835260" \
    "https://www.fresha.com/book-now/$SLUG/all-offer"
  do
    printf '%s\n  UA=%s\n  -> %s\n' "$url" "${ua:0:24}" \
      "$(curl -sSL -o /dev/null -w '%{http_code} %{url_effective}' ${ua:+-A "$ua"} "$url")"
  done
done
```

Read-only, one page, no state touched. Record all four `url_effective` values.

- [ ] **Step 2: Record the outcome in this file**

Write the four observed values into [Ruling A](#ruling-a--which-resolvecurrentslug-is-correct) above and convert it from a question into a stated rule. Both outcomes are legitimate closes: *"query string and UA are immaterial — unify on `FreshaPage::shareProbeUrl($slug)`"* or *"they differ, the conditional stays and gets a comment saying why."*

- [ ] **Step 3: Implement whichever the probe supports**

If immaterial, delete the conditional in `FreshaScraper::resolveCurrentSlug` and call `FreshaPage::shareProbeUrl($given)` unconditionally; the two lanes then differ only in input type (URL vs slug), which is transport.

Either way the acceptance evidence is the same pair, both of which must stay green: `freshaRotatingIo` (`tests/Feature/Ingest/FreshaConnectorTest.php:453`) and the rotation-persists test (`tests/Feature/Platforms/FreshaTeamCacheTest.php:224`). Add one case pinning the *chosen* probe form explicitly, so a later session cannot flip it by accident.

---

### Task 5: `extractServices` and the gate — **BLOCKED on Ruling B2**

**Do not start without an explicit owner ruling.** It changes what renders on every connected salon's page: measured at **18 of 116 real dev names (16%)**, after Task 0 has removed the connector-word noise.

Until it is ruled, `FreshaScraper::extractServices()` stays where it is with `$this->scanTitleCase()` intact.

Three legitimate outcomes:
1. **The gate difference is deliberate and stays.** `extractServices` remains scraper-only; add a comment at `FreshaScraper.php:299–301` pointing at `ScrapedNameCasing`'s docblock so the next reader does not "fix" it. Cheapest, and the existing documentation supports it — but the data does not flatter it: the dashboard keeps showing `Hair coloring` and `Clipper Cut (Buzz cut)`.
2. **The dashboard adopts the token gate.** `extractServices` moves to `FreshaPage` using `ScrapedNameCasing::titleCase()`. 16% of names change, ~16 of 18 as corrections; the two debatable ones are `BOYS Standard Haircut` → `Boys Standard Haircut` and `Gel-x Full Set` → `Gel-X Full Set`. If this is chosen, consider widening `isDeliberateAllCapsRun`'s 2–3 character bound first so `BOYS` survives — its own docblock already flags that as a separate, deliberate call.
3. **`FreshaPage::extractServices()` takes the caser as a `callable(?string): ?string` argument.** Shares the category-tree flattening, keeps casing per-lane. Preserves both behaviours at the cost of one parameter — the honest option if the owner wants the move now and the casing decision later.

Note that even under outcome 2 this is **not** de-duplication with the connector: `extractServices` parses the venue page's `location.services` tree while `FreshaConnector::mapServiceItem()` parses the booking flow's `screenServices.categories`. Different documents, different output shapes, different id grammars (`s:` vs `s:|p:`). Moving it is cohesion only.

The "18 of 116, and those 18 are the gate difference" framing predates Task 0 (`8ac36ec38`) and is no longer the whole story. Task 0 made `titleCase()` read `CONNECTORS`, so the two casers now also diverge wherever a connector word follows a clause opener (`-`, `(`, `/`, `,`, `:`) — `titleCase()` capitalises it there, `scanTitleCase()` never touches it because it gates on the whole string. That is a SEPARATE axis from the ALL-CAPS-vs-mixed-case gate this section is about, and it fires independent of it: `MANICURE - WITH GEL POLISH` → `titleCase`: `Manicure - With Gel Polish`, `scanTitleCase`: `Manicure - with Gel Polish`; `SPECIAL: WITH GEL` → `titleCase`: `Special: With Gel`, `scanTitleCase`: `Special: with Gel`; `CUT, AND COLOUR` → `titleCase`: `Cut, And Colour`, `scanTitleCase`: `Cut, and Colour`. Whoever answers this ruling should weigh both axes, not just the gate.

---

## The other `FreshaServiceProjector` — answered

**They are not the same duplication one layer up. They share a name and nothing else, and this does not belong in this plan.**

| | `App\Ingest\Projection\FreshaServiceProjector` | `App\Services\Platforms\FreshaServiceProjector` |
|---|---|---|
| Shape | `implements Projector`; `static version()`, `static kind()`, `project(RecordView): ?array` | Constructor-injected `FreshaServiceItems`; `dedupe`, `sync`, `compose`, `refreshBlob`, `setHidden`, `parseDuration` |
| Input | one ingest `RecordView` | a `User` + the raw scrape array |
| Output | a `service` item-kind projection (typed offer/duration columns) | `payload.selection` — the dashboard booking blob |
| Consumers | `ConnectorRegistry`/`ProjectionWriter`; tests `FreshaServiceProjectorTest`, `ProjectionTest` | `FreshaController`, `UserServiceController`, `StaffServiceManagementController`, `FreshaFetch`, `FreshaConnectFetch`, `FreshaBinding` |

The ingest one's own docblock (`:41–43`) calls the other *"the legacy lane"* and explains that it deliberately keys on the vendor's numeric category id because the legacy lane matched on title and minted duplicates on rename. The divergence is a documented improvement, not drift. Square shows the resolved end state: it has only `app/Ingest/Projection/SquareServiceProjector.php`, no `Services\Platforms` twin.

**One genuine overlap, worth its own small ticket — and it hides a live bug:**

Both parse Fresha's display duration string, and the implementations have diverged in a way that is not merely stylistic:

```php
// Ingest\Projection\FreshaServiceProjector::durationSeconds()  -> SECONDS
preg_match('/(\d+)\s*h/i', ...)      // hours
preg_match('/(\d+)\s*min/i', ...)    // requires the literal "min"

// Services\Platforms\FreshaServiceProjector::parseDuration()   -> MINUTES
preg_match('/(\d+)\s*h(?:r|our)?s?/i', ...)
preg_match('/(\d+)\s*m(?:in)?(?:ute)?s?/i', ...)   // accepts a bare "m"
```

They return **different units**, so they cannot be trivially merged. More importantly, on a caption of `"1h 30m"` the ingest parser matches the hour and **silently drops the 30 minutes**, landing 3600s instead of 5400s; the dashboard parser gets it right. Whether Fresha ever emits the bare-`m` form is unverified — it should be checked against `content.source_items` payloads on dev before anyone decides it is theoretical.

**Recommendation:** raise it as its own item (a duration-parsing correctness fix, not a refactor). It is a genuine potential data bug, it is independent of everything in Tasks 1–5, and folding a projection-correctness change into a parsing-extraction refactor would make both harder to review.

---

## Self-Review

**Spec coverage.** All five brief items are addressed: (1) Task 1, minus `extractServices` and `slugFromFinalUrl`, both with stated reasons; (2) Task 2, with the no-test-edit constraint as an explicit gate at Steps 1/5; (3) Task 3; (4) honoured — `lastResolvedSlug`, the `FetchBudget` clamp and the degrade contract are named in File Structure as explicitly not moving; (5) Task 1 Step 1, redesigned because the named fixture lacks a location blob. The `resolveCurrentSlug` question is called out and, after measuring its blast radius, downgraded to a one-probe verification (Task 4); the caser question splits into a shipped bug fix (Task 0) and a genuine product ruling (Task 5). The projector question is answered above.

**Placeholder scan.** No TBDs. Every code step carries the actual code. Tasks 4 and 5 carry no implementation code by design — they are blocked on rulings, and writing their implementation now would presuppose the answer.

**Type consistency.** `FreshaPage`'s twelve members are declared once in Task 1's Interfaces block and used with matching names and arities in Tasks 2 and 3. `bookingFlowPayload` takes `(string $slug, ?string $employeeId, string $hash, string $clientVersion)` at both call sites. `parseNextData` returns `?array`, `nextDataJson` returns `?string`, `locationFrom` takes `array` and returns `?array` — consumed accordingly in both lanes. `slugFromFinalUrl` appears nowhere, per finding 6.
