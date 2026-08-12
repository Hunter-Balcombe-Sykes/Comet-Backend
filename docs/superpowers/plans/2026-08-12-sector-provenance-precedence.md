# Sector Provenance Precedence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace three divergent readings of `core.users.sector_source` with one rank ladder, so a scraper's guess stops permanently locking out a better source — and make the Instagram guess good enough to be worth writing.

**Architecture:** Two new small classes (`SectorProvenance`, a pure policy class; `FoodContentProbe`, a one-query collaborator) plus edits to four existing files. `SectorTaxonomy` gains a `fromInstagramProfile()` entry point that **delegates its category tier to the unmodified `fromInstagramCategory()`** and adds a free-text fallback over the Instagram handle and full name. `InstagramIdentitySync` gains the `lockForUpdate` re-read `IdentitySync` already has. Both sync writers gain a site touch so the CDN actually purges.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4. **No migration, no schema change, no new dependency.**

**Spec:** `docs/superpowers/specs/2026-08-11-sector-provenance-precedence-design.md` (rev 4). Section references below (§3.1, §4.8, …) point into it.

## Global Constraints

- **No Laravel migration files.** The Composer guard rejects them. Nothing here needs schema: `sector` and `sector_source` already exist as nullable `text` and `users_sector_source_check` already permits `'instagram'`.
- **`composer test --filter` is broken in this repo.** Run Pest directly with a file path: `./vendor/bin/pest tests/path/File.php`. Never `composer test --filter=...`.
- **`composer pint` / `php artisan pint` is broken.** Invoke the binary directly: `./vendor/bin/pint <paths>`. Run it on touched files before every commit.
- **Never read `account_type` outside `AccountCapabilities`.** The sanctioned discriminator is `AccountCapabilities::for($user)->google_business_full_sync`. `OnboardingSuggestions.php:170` calls it "the one sanctioned account-type discriminator (never raw account_type)". Do not call `$user->isBusiness()` in new code.
- **Tests run SQLite, prod is Postgres.** `users_sector_check` (baseline `:1174`) enumerates all 70 valid slugs and `users_sector_source_check` (`:1175`) the three sources — **neither exists in SQLite**. A typo'd slug passes every test and throws `SQLSTATE 23514` in production. Task 3 and Task 4 add the pins that catch this.
- **`KEYWORD_SECTORS` ordering is a contract.** `classify()` returns the FIRST substring match; a generic keyword must never precede a more specific colliding one. **Do not modify `KEYWORD_SECTORS` or `fromGoogleCategory()` in this plan** — the Google path must not regress (spec §8).
- **`Model::preventLazyLoading(! app()->isProduction())`** is set at `AppServiceProvider.php:372`. Touching an unloaded relation **throws** in dev and test. Task 6's probe must use explicit query builders, never `$user->site` or `$fresh->site`.
- **Comment for WHY, not what.** Brief. No banners, no paragraphs, no restatements of the code.
- **Branch:** `feat/sector-provenance-precedence` off `development`.

---

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `app/Services/Profile/SectorProvenance.php` | **New.** Pure policy: the rank ladder (`mayWrite`), the food-demotion predicate (`isFoodDemotion`), and the transition log (`logTransition`). No DB, no `account_type`. | 1, 5, 7 |
| `app/Services/Profile/FoodContentProbe.php` | **New.** One query answering "does this user have live food content?". The only DB-touching piece of §4.8. | 6 |
| `app/Services/Profile/SectorTaxonomy.php` | Gains `TEXT_KEYWORD_SECTORS`, `AMBIGUOUS_CATEGORY_SECTORS`, `classifyText()`, `fromInstagramProfile()`, and six new `INSTAGRAM_CATEGORY_SECTORS` entries. `KEYWORD_SECTORS` and `fromGoogleCategory()` untouched. | 2, 3, 4 |
| `app/Services/Platforms/IdentitySync.php` | `applySector` collapses to one branch, calls the ladder + food guard, logs, and touches the site after the transaction. | 7, 8 |
| `app/Services/Platforms/InstagramIdentitySync.php` | Same ladder, plus the `lockForUpdate` re-read and `$user->refresh()` it currently lacks. | 9, 10 |
| `app/Services/Onboarding/OnboardingSuggestions.php` | `askSector` fires whenever the sector was not human-chosen, on both account types. | 11 |
| `tests/Unit/Profile/SectorProvenanceTest.php` | **New.** Truth table for the ladder + the food predicate. | 1, 5 |
| `tests/Unit/Profile/SectorTaxonomyClassificationTest.php` | Extended: tier order, primacy migrated to `fromInstagramProfile`, adversarial corpus, food + slug invariants. | 2, 3, 4 |
| `tests/Feature/Platforms/IdentitySyncTest.php` | One assertion **inverted** (`:303-322`), new ladder + demotion + purge cases, `setupSectionsTables()` added. | 8 |
| `tests/Feature/Platforms/InstagramIdentitySyncTest.php` | New ladder cases. | 10 |
| `tests/Feature/Platforms/InstagramIdentitySyncConcurrencyTest.php` | **New.** Stale-instance race + a source-grep pin. | 10 |
| `tests/Feature/Onboarding/OnboardingSuggestionsTest.php` | Two assertions updated (`:66`, `:103`), new cases. | 11 |
| `tests/Schema/SectorSourceCheckTest.php` | **New.** `pg_get_constraintdef` pin. Runs in `composer test:schema`, **not** `composer test`. | 12 |

**Task order rationale:** Tasks 1–6 build leaf units with no dependants, each independently testable. Tasks 7–11 wire them into call sites. Task 12 adds the cross-lane pin. Task 13 repairs comments the earlier tasks falsify. A reviewer can reject any one task without blocking its neighbours, except that 7–10 consume Tasks 1–6.

---

## Task 1: `SectorProvenance` — the rank ladder

**Files:**
- Create: `app/Services/Profile/SectorProvenance.php`
- Test: `tests/Unit/Profile/SectorProvenanceTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `SectorProvenance::MANUAL` / `::GOOGLE` / `::INSTAGRAM` (string consts), and `SectorProvenance::mayWrite(User $user, string $incoming): bool`.

Spec §3.1. Three rules that are easy to get backwards, so read them before writing:

1. An **unrecognised incoming source is refused first**, before the blank check — otherwise an out-of-vocabulary source writes through on a blank row and hits `users_sector_source_check` as a 23514, which is fatal on the Instagram path.
2. A **blank existing value never blocks a fill**, whatever provenance is stamped on it.
3. A **non-blank value with unrecognised provenance is unwritable**. This is the one an earlier draft had inverted. `sector` is fillable and `sector_source` is not (`User.php:105-107`), so mass-assignment produces `(value, null)` rows; those must be the *strongest* rows, not the weakest.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Profile/SectorProvenanceTest.php`:

```php
<?php

// The sector precedence ladder. Every case here encodes a rule that was got
// backwards at least once during design — see the spec's revision notes.

use App\Models\Core\User\User;
use App\Services\Profile\SectorProvenance;

/** A bare in-memory model. mayWrite reads two attributes and touches no database. */
function provenanceUser(?string $sector, ?string $source): User
{
    $user = new User;
    $user->sector = $sector;
    $user->sector_source = $source;

    return $user;
}

it('lets any recognised source fill a blank value, whatever provenance is stamped', function (?string $sector, ?string $source) {
    foreach ([SectorProvenance::INSTAGRAM, SectorProvenance::GOOGLE, SectorProvenance::MANUAL] as $incoming) {
        expect(SectorProvenance::mayWrite(provenanceUser($sector, $source), $incoming))
            ->toBeTrue("{$incoming} should fill a blank value");
    }
})->with([
    'null value, null source' => [null, null],
    'empty value, null source' => ['', null],
    'whitespace value, null source' => [' ', null],
    'null value, instagram source' => [null, 'instagram'],
    'empty value, manual source' => ['', 'manual'],
]);

it('ranks manual above google above instagram', function () {
    // Google beats instagram.
    expect(SectorProvenance::mayWrite(provenanceUser('artist', 'instagram'), SectorProvenance::GOOGLE))->toBeTrue();
    // Instagram loses to google.
    expect(SectorProvenance::mayWrite(provenanceUser('cafe', 'google-business'), SectorProvenance::INSTAGRAM))->toBeFalse();
    // Manual beats both.
    expect(SectorProvenance::mayWrite(provenanceUser('cafe', 'google-business'), SectorProvenance::MANUAL))->toBeTrue();
    expect(SectorProvenance::mayWrite(provenanceUser('artist', 'instagram'), SectorProvenance::MANUAL))->toBeTrue();
    // Nothing automated beats manual.
    expect(SectorProvenance::mayWrite(provenanceUser('barber', 'manual'), SectorProvenance::GOOGLE))->toBeFalse();
    expect(SectorProvenance::mayWrite(provenanceUser('barber', 'manual'), SectorProvenance::INSTAGRAM))->toBeFalse();
});

it('lets google and manual refresh their own value but never instagram', function () {
    expect(SectorProvenance::mayWrite(provenanceUser('cafe', 'google-business'), SectorProvenance::GOOGLE))->toBeTrue();
    expect(SectorProvenance::mayWrite(provenanceUser('barber', 'manual'), SectorProvenance::MANUAL))->toBeTrue();
    // Instagram may not: PARTNA_INSTAGRAM_ACTOR is a no-deploy rollback, and the
    // dashboard refresh button would otherwise silently rewrite a stored sector.
    expect(SectorProvenance::mayWrite(provenanceUser('artist', 'instagram'), SectorProvenance::INSTAGRAM))->toBeFalse();
});

it('treats a set value with unrecognised provenance as unwritable', function (?string $source) {
    foreach ([SectorProvenance::INSTAGRAM, SectorProvenance::GOOGLE, SectorProvenance::MANUAL] as $incoming) {
        expect(SectorProvenance::mayWrite(provenanceUser('restaurant', $source), $incoming))
            ->toBeFalse("{$incoming} must not overwrite a row it did not write");
    }
})->with([
    'null source (the mass-assignment shape)' => [null],
    'bogus source' => ['bogus'],
]);

it('refuses an unrecognised incoming source even on a blank row', function () {
    // Fail-closed BEFORE the blank short-circuit: users_sector_source_check
    // permits exactly three values, and a 23514 kills the whole connect job.
    expect(SectorProvenance::mayWrite(provenanceUser(null, null), 'facebook'))->toBeFalse();
    expect(SectorProvenance::mayWrite(provenanceUser('', null), 'facebook'))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorProvenanceTest.php`
Expected: FAIL — `Class "App\Services\Profile\SectorProvenance" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Services/Profile/SectorProvenance.php`:

```php
<?php

namespace App\Services\Profile;

use App\Models\Core\User\User;

/**
 * Who may overwrite core.users.sector.
 *
 * sector_source used to be read as a permission by three call sites with three
 * different rules, so the FIRST writer won permanently — even a scraper's guess
 * over Google's authoritative answer. This class is the single statement of the
 * law: rank the sources, and let a higher rank correct a lower one.
 */
final class SectorProvenance
{
    public const MANUAL = 'manual';

    public const GOOGLE = 'google-business';

    public const INSTAGRAM = 'instagram';

    /** Mirrors users_sector_source_check, ordered by authority. */
    private const RANKS = [self::INSTAGRAM => 1, self::GOOGLE => 2, self::MANUAL => 3];

    /**
     * May a source refresh a value IT stamped itself? Instagram may not:
     * PARTNA_INSTAGRAM_ACTOR is a no-deploy rollback whose two actors return
     * different keys, so allowing it would let an env flip silently rewrite
     * stored sectors on the next reconnect.
     */
    private const SELF_REFRESH = [self::INSTAGRAM => false, self::GOOGLE => true, self::MANUAL => true];

    public static function mayWrite(User $user, string $incoming): bool
    {
        // Fail closed BEFORE the blank check — an out-of-vocabulary source would
        // otherwise write through on a blank row and hit the CHECK as a 23514,
        // which is unhandled on the Instagram connect path.
        if (! isset(self::RANKS[$incoming])) {
            return false;
        }

        $existingValue = $user->sector;
        if ($existingValue === null || trim($existingValue) === '') {
            return true;
        }

        // A set value with provenance none of the three writers stamped came from
        // a mass-assignment path or a manual data fix. Nothing here outranks it.
        $existingSource = $user->sector_source;
        if (! is_string($existingSource) || ! isset(self::RANKS[$existingSource])) {
            return false;
        }

        $incomingRank = self::RANKS[$incoming];
        $existingRank = self::RANKS[$existingSource];

        return $incomingRank === $existingRank
            ? self::SELF_REFRESH[$incoming]
            : $incomingRank > $existingRank;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorProvenanceTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Services/Profile/SectorProvenance.php tests/Unit/Profile/SectorProvenanceTest.php
git add app/Services/Profile/SectorProvenance.php tests/Unit/Profile/SectorProvenanceTest.php
git commit -m "feat(sector): rank sector sources instead of first-writer-wins

manual > google-business > instagram. A blank value is always fillable; a
set value with unrecognised provenance is not. Instagram may not refresh
its own value — PARTNA_INSTAGRAM_ACTOR is a no-deploy rollback."
```

---

## Task 2: Six `INSTAGRAM_CATEGORY_SECTORS` corrections

**Files:**
- Modify: `app/Services/Profile/SectorTaxonomy.php` (the `INSTAGRAM_CATEGORY_SECTORS` const, `:231-288`)
- Test: `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing new — six map entries that change `fromInstagramCategory()`'s output for six inputs.

Spec §3.3. Six real Facebook categories currently resolve wrongly through `KEYWORD_SECTORS`' trailing `'bar' => 'bar'` (`:203`) and `'sport' => 'gym'` (`:164`). Three land on the **food** slug `bar`, which on a business account switches `can_use_booking` **off** — for a barre studio and a mobile bartender, the two shapes most likely to need booking.

`INSTAGRAM_CATEGORY_SECTORS` is an exact-match map consulted before the substring pass, so adding entries fixes these without touching `KEYWORD_SECTORS`.

> **Verify before merge:** these six keys were derived by confirming the substring map *mis-maps those strings*, not by confirming Instagram emits them. Check them against observed Apify payloads. `barre studio` and `bartender` are the least certain. Keeping an unconfirmed key is safe — a dead exact-match key costs nothing — so do not drop them on doubt, but do not treat the test below as evidence they occur.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`:

```php
// ── categories the substring map gets wrong (2026-08-12) ─────────────────────
//
// All six reach a wrong slug through KEYWORD_SECTORS' trailing 'bar' and
// 'sport' entries. Three land on the FOOD slug 'bar', which flips
// can_use_booking OFF for a business — a barre studio and a mobile bartender
// are exactly the accounts that need booking on.
it('exact-maps categories the substring classifier gets wrong', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    ['Sports Bar', 'bar'],
    ['Juice Bar', 'cafe'],
    ['Bartender', 'bartender'],
    ['Barre Studio', 'yoga-instructor'],
    ['Sportswear Store', 'clothing-boutique'],
    ['Hair Removal Service', 'esthetician'],
]);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`
Expected: FAIL — 6 cases. `Sports Bar` returns `gym`, `Juice Bar` returns `bar`, `Bartender` returns `bar`, `Barre Studio` returns `bar`, `Sportswear Store` returns `gym`, `Hair Removal Service` returns `hair-salon`.

- [ ] **Step 3: Add the six entries**

In `app/Services/Profile/SectorTaxonomy.php`, inside `INSTAGRAM_CATEGORY_SECTORS`, add a block after the existing `// Corrections — the substring map returns the wrong slug for these.` group:

```php
        // The substring map's trailing 'bar' and 'sport' keys capture these.
        // Three would otherwise resolve to the FOOD slug 'bar' and flip
        // can_use_booking off on a business account.
        'sports bar' => 'bar',
        'juice bar' => 'cafe',
        'bartender' => 'bartender',
        'barre studio' => 'yoga-instructor',
        'sportswear store' => 'clothing-boutique',
        'hair removal service' => 'esthetician',
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`
Expected: PASS — the whole file, including the pre-existing `isValid()` pin at `:286-294` (all six values are valid slugs) and the lowercase-key assertion in the same test.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Services/Profile/SectorTaxonomy.php tests/Unit/Profile/SectorTaxonomyClassificationTest.php
git add app/Services/Profile/SectorTaxonomy.php tests/Unit/Profile/SectorTaxonomyClassificationTest.php
git commit -m "fix(sector): exact-map six categories the substring classifier gets wrong

Sports Bar/Juice Bar/Bartender/Barre Studio all reached 'bar' or 'gym' via
KEYWORD_SECTORS' trailing generic keys; three of those are the food slug
'bar', which flips can_use_booking off on a business account."
```

---

## Task 3: `TEXT_KEYWORD_SECTORS` + `classifyText()`

**Files:**
- Modify: `app/Services/Profile/SectorTaxonomy.php`
- Test: `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `SectorTaxonomy::classifyText(?string $raw): ?string` (public static, so it can be tested directly).

Spec §3.4 and §3.5. Three rules:

1. **No value in this map may be in `FOOD_SECTORS`.** A wrong food slug flips four capabilities and misroutes links through `LinkRouter.php:164`, which carries its own copy of the arms. `coffee`, `catering` and `baker` all pass a naive admission rule and produce food slugs — **Baker is a top-20 Australian surname**, so `jessbakerphotography` would become a bakery.
2. **No bare `'hair'`.** Normalisation strips separators, which *manufactures* matches: `beth.airbnb` → `bethairbnb` contains `hair`. So does `sarah.airconditioning`. Compounds (`hairstylist`, `hairdress`, `hairsalon`) are safe and still catch the motivating case.
3. **Order matters.** `classifyText` takes the first substring hit. Every `photograph*` key must precede `barber` and `realestate`, or `sarahbarberphotography` becomes a barber.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`:

```php
// ── free-text classification over handles and display names (2026-08-12) ─────

it('classifies a trade from a handle or display name', function (string $input, string $expected) {
    expect(SectorTaxonomy::classifyText($input))->toBe($expected);
})->with([
    'dotted handle' => ['jess.hair.stylist', 'hair-salon'],
    'run-together handle' => ['crucibletattooco', 'tattoo-artist'],
    'display name' => ['Melbourne Barre Pilates', 'yoga-instructor'],
    'underscored' => ['sam_the_plumber', 'plumber'],
]);

// Each of these was a real false positive in an earlier draft of the map.
it('does not manufacture a match across a separator or a surname', function (string $input, ?string $expected) {
    expect(SectorTaxonomy::classifyText($input))->toBe($expected);
})->with([
    // <name ending in h> + <word starting "air"> — a productive AU handle pattern.
    'airbnb host' => ['beth.airbnb', null],
    'hvac tradie' => ['sarah.airconditioning', null],
    'spray tanner' => ['leah.airbrushtanning', 'esthetician'],
    'airbnb host 2' => ['hannah.airbnbhost', null],
    'pet groomer' => ['hairyhounds', null],
    // Surnames and qualifiers must lose to the trade actually named.
    'Barber the surname' => ['sarahbarberphotography', 'photographer'],
    'real estate niche' => ['realestatephotography', 'photographer'],
    'Baker the surname' => ['jessbakerphotography', 'photographer'],
    // 'spa' is absent from the map precisely so this cannot happen.
    'Spartan' => ['Spartan Fitness', 'gym'],
    'barber not bakery' => ['bakerstreetbarbers', 'barber'],
    'not a painter' => ['facepainting.co', null],
    'furniture' => ['mrchairs.furniture', null],
    'not a barber' => ['thebarberlin', 'barber'],
    'IT consultant' => ['coffeeandcode', null],
]);

it('never resolves free text to a FOOD_SECTORS slug', function () {
    $map = (new ReflectionClass(SectorTaxonomy::class))->getConstant('TEXT_KEYWORD_SECTORS');

    foreach ($map as $keyword => $slug) {
        expect(SectorTaxonomy::isFood($slug))->toBeFalse("'{$keyword}' maps to food slug '{$slug}'");
    }
});

it('maps every free-text keyword to a real sector slug', function () {
    $map = (new ReflectionClass(SectorTaxonomy::class))->getConstant('TEXT_KEYWORD_SECTORS');

    foreach ($map as $keyword => $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("'{$keyword}' maps to unknown slug '{$slug}'")
            ->and($keyword)->toBe(strtolower($keyword))
            ->and(preg_match('/^[a-z]+$/', $keyword))->toBe(1, "'{$keyword}' must be lowercase a-z only (normalisation strips everything else)");
    }
});
```

> `thebarberlin` resolving to `barber` is a **known and accepted** false positive — "the bar, Berlin" joins into `barber`. It is in the corpus so the behaviour is recorded rather than discovered later. `bakerstreetbarbers` correctly gives `barber` because `baker` is not in the map at all.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`
Expected: FAIL — `Call to undefined method App\Services\Profile\SectorTaxonomy::classifyText()`.

- [ ] **Step 3: Add the map and the matcher**

In `app/Services/Profile/SectorTaxonomy.php`, add after `INSTAGRAM_CATEGORY_SECTORS`:

```php
    /**
     * Keywords safe to substring-match against FREE TEXT — an Instagram handle
     * or display name — when the category is too vague to classify.
     *
     * Separate from KEYWORD_SECTORS on purpose. That map is safe against
     * Facebook's closed category vocabulary and dangerous against free text:
     * it holds 'spa' at index 5 and 'fitness' at index 8, so "Spartan Fitness"
     * resolves to 'spa'. Word-boundary anchoring would fix that but break the
     * run-together handles this exists to catch ('\btattoo' misses
     * crucibletattooco). A vetted map with plain substring matching does both.
     *
     * A key qualifies only if it (1) is >=5 characters, (2) is not a substring
     * of a common English word or Australian surname, (3) names a TRADE rather
     * than a medium, and (4) cannot be manufactured by joining across a
     * separator in a plausible handle. Clause 4 is why there is no bare 'hair':
     * normalisation turns beth.airbnb into bethairbnb.
     *
     * NO VALUE MAY BE IN FOOD_SECTORS. A wrong food slug flips four
     * capabilities and misroutes links via LinkRouter's own copy of the arms.
     * That rules out coffee/catering/baker — Baker is a top-20 AU surname.
     *
     * ORDER: first substring hit wins. Where a key is commonly a surname
     * ('barber') or a qualifier of another trade ('realestate', 'wedding'), it
     * MUST come after every trade key that can co-occur with it in one handle.
     * The medium someone practises beats the subject they practise it on.
     *
     * @var array<string, string>
     */
    private const TEXT_KEYWORD_SECTORS = [
        // Media first — these co-occur with surnames and qualifiers below.
        'photograph' => 'photographer',
        'videograph' => 'videographer',
        'graphicdesign' => 'graphic-designer',

        // Beauty & personal care
        'hairstylist' => 'hair-salon',
        'hairdress' => 'hair-salon',
        'hairsalon' => 'hair-salon',
        'hairstudio' => 'hair-salon',
        'barber' => 'barber',
        'tattoo' => 'tattoo-artist',
        'makeup' => 'makeup-artist',
        'lashes' => 'brows-lashes',
        'browsandlashes' => 'brows-lashes',
        'airbrushtanning' => 'esthetician',
        'spraytan' => 'esthetician',
        'skincare' => 'esthetician',
        'esthetic' => 'esthetician',
        'massage' => 'spa',
        'nailtech' => 'nail-technician',
        'nailsalon' => 'nail-technician',

        // Health & fitness
        'pilates' => 'yoga-instructor',
        'barrepilates' => 'yoga-instructor',
        'yogateacher' => 'yoga-instructor',
        'personaltrainer' => 'personal-trainer',
        'fitness' => 'gym',
        'physio' => 'physiotherapist',
        'chiro' => 'chiropractor',
        'dentist' => 'dentist',
        'nutrition' => 'nutritionist',

        // Trades & automotive
        'plumb' => 'plumber',
        'electrician' => 'electrician',
        'landscap' => 'landscaper',
        'carpentry' => 'builder',
        'mechanic' => 'mechanic',
        'cardetailing' => 'car-detailer',

        // Retail & professional — after the media keys above.
        'florist' => 'florist',
        'jeweller' => 'jewellery',
        'realestate' => 'real-estate-agent',
        'bookkeep' => 'accountant',
        'tutoring' => 'tutor',
    ];

    /**
     * Classify free text (an Instagram handle or display name) into a sector.
     *
     * Normalises to a-z only — dots, underscores, spaces, digits and emoji all
     * removed — then takes the first substring hit in map order. Stripping
     * separators is what lets 'crucibletattooco' match; the map's clause-4
     * admission rule is what stops it manufacturing false positives.
     */
    public static function classifyText(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $normalised = preg_replace('/[^a-z]/', '', strtolower($raw)) ?? '';
        if ($normalised === '') {
            return null;
        }

        foreach (self::TEXT_KEYWORD_SECTORS as $keyword => $slug) {
            if (str_contains($normalised, $keyword)) {
                return $slug;
            }
        }

        return null;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`
Expected: PASS. If `leah.airbrushtanning` returns `hair-salon` instead of `esthetician`, `airbrushtanning` is ordered after a hair key — move it above them.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Services/Profile/SectorTaxonomy.php tests/Unit/Profile/SectorTaxonomyClassificationTest.php
git add app/Services/Profile/SectorTaxonomy.php tests/Unit/Profile/SectorTaxonomyClassificationTest.php
git commit -m "feat(sector): vetted free-text keyword map for handles and display names

Separate from KEYWORD_SECTORS, which is safe on Facebook's closed category
vocabulary and dangerous on free text ('Spartan Fitness' -> spa). No value
may be a FOOD_SECTORS slug, and no bare 'hair' — normalisation turns
beth.airbnb into bethairbnb."
```

---

## Task 4: `fromInstagramProfile()` — three tiers

**Files:**
- Modify: `app/Services/Profile/SectorTaxonomy.php`
- Test: `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

**Interfaces:**
- Consumes: `SectorTaxonomy::classifyText()` (Task 3), `AMBIGUOUS_CATEGORY_SECTORS` (added here).
- Produces: `SectorTaxonomy::fromInstagramProfile(array $categoryCandidates, ?string $username, ?string $fullName): ?string`.

Spec §3.3. **The single most important instruction in this plan:** tier 1 is a *call to the unmodified `fromInstagramCategory()`*, not a reimplementation.

Three earlier drafts split the category pass into "exact matches first, then keyword matches", resolved across all candidates. That inverts **category primacy**. Instagram comma-joins categories **primary first**, and `fromInstagramCategory` is segment-major — for each segment, exact `??` keyword — which is what makes the primary category win. Going tier-major lets a *secondary* category outrank the primary one: `Restaurant, Digital Creator` → `content-creator`, a food→non-food demotion. `SectorTaxonomyClassificationTest.php:335-366` pins nine such inputs; eight break under tier-major.

Delegating makes that regression unrepresentable.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`:

```php
// ── fromInstagramProfile: the live classifier (2026-08-12) ───────────────────

// PRIMACY, migrated from fromInstagramCategory. After this change the live
// path is fromInstagramProfile, so the primacy guarantee must be pinned HERE.
// Tier 1 delegates to fromInstagramCategory precisely so these cannot regress:
// resolving exact-matches across all segments before keyword-matches lets a
// SECONDARY category outrank the primary one, and three of these become a
// food -> non-food demotion that silently kills can_use_menu.
it('keeps the primary category when a secondary one also matches', function (string $category, string $expected) {
    expect(SectorTaxonomy::fromInstagramProfile([$category], null, null))->toBe($expected);
})->with([
    ['Restaurant, Digital Creator', 'restaurant'],
    ['Barber Shop, Writer', 'barber'],
    ['Hair Salon, Fitness Trainer', 'hair-salon'],
    ['Cafe, Blogger', 'cafe'],
    ['Tattoo & Piercing Shop, Digital Creator', 'tattoo-artist'],
    ['Restaurant, Contractor', 'restaurant'],
    ['None, Restaurant, Digital Creator', 'restaurant'],
    ['Bakery, Content Creator', 'bakery'],
    ['Digital Creator, Restaurant', 'content-creator'],
]);

it('prefers the category over the handle', function () {
    // The category tier must beat free text. A restaurant whose handle mentions
    // fitness is a restaurant.
    expect(SectorTaxonomy::fromInstagramProfile(['Restaurant'], 'fitzroyfitnesskitchen', null))->toBe('restaurant');
    expect(SectorTaxonomy::fromInstagramProfile(['Nail Salon'], 'sarahsbeautyandhair', null))->toBe('nail-technician');
});

it('falls back to the handle, then the display name, when no category maps', function () {
    // The motivating case: a Prahran hairdresser whose Instagram category is "Artist".
    expect(SectorTaxonomy::fromInstagramProfile(['Artist'], 'jess.hair.stylist', null))->toBe('hair-salon');
    // Handle beats display name.
    expect(SectorTaxonomy::fromInstagramProfile(['Artist'], 'crucibletattooco', 'Jane Photography'))->toBe('tattoo-artist');
    // Display name used when the handle says nothing.
    expect(SectorTaxonomy::fromInstagramProfile(['Artist'], 'jd_official', 'Jane Photography'))->toBe('photographer');
});

it('falls back to an ambiguous category only when nothing else matches', function () {
    expect(SectorTaxonomy::fromInstagramProfile(['Artist'], 'jd_official', null))->toBe('artist');
    // Per SEGMENT, not whole-string: Instagram emits its literal "None" as a
    // real segment, so "None,Artist" must still reach the ambiguous map.
    expect(SectorTaxonomy::fromInstagramProfile(['None,Artist'], null, null))->toBe('artist');
});

it('resolves the first candidate that maps, not the first that is non-null', function () {
    // The figue actor nulls business_category_name and fills category_name.
    expect(SectorTaxonomy::fromInstagramProfile(['None', null, 'Hair Stylist'], null, null))->toBe('hair-salon');
});

it('returns null when every tier misses', function () {
    expect(SectorTaxonomy::fromInstagramProfile(['Public Figure'], 'jd_official', 'JD'))->toBeNull();
    expect(SectorTaxonomy::fromInstagramProfile([], null, null))->toBeNull();
});

it('never resolves an instagram profile to a food slug from free text alone', function () {
    $handles = [
        'beth.airbnb', 'sarah.airconditioning', 'hairyhounds', 'sarahbarberphotography',
        'realestatephotography', 'jessbakerphotography', 'coffeeandcode', 'Spartan Fitness',
        'bakerstreetbarbers', 'facepainting.co', 'mrchairs.furniture', 'thebarberlin',
    ];

    foreach ($handles as $handle) {
        $slug = SectorTaxonomy::fromInstagramProfile(['Artist'], $handle, null);
        expect(SectorTaxonomy::isFood($slug))->toBeFalse("'{$handle}' resolved to food slug '{$slug}'");
    }
});

it('maps every ambiguous category to a real, non-food sector slug', function () {
    $map = (new ReflectionClass(SectorTaxonomy::class))->getConstant('AMBIGUOUS_CATEGORY_SECTORS');

    foreach ($map as $category => $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("'{$category}' maps to unknown slug '{$slug}'")
            ->and(SectorTaxonomy::isFood($slug))->toBeFalse("'{$category}' maps to food slug '{$slug}'")
            ->and($category)->toBe(strtolower(trim($category)));
    }
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`
Expected: FAIL — `Call to undefined method ...::fromInstagramProfile()`.

- [ ] **Step 3: Add the ambiguous map and the entry point**

In `app/Services/Profile/SectorTaxonomy.php`, add after `TEXT_KEYWORD_SECTORS`:

```php
    /**
     * Categories too vague to classify on their own, mapped as a LAST RESORT —
     * only after the category pass and the free-text pass have both missed.
     *
     * "Artist" is the case this exists for: tattooists, musicians, hairdressers
     * and photographers all pick it. Under the old first-writer-wins rule
     * stamping a guess here locked Google out permanently, so the map's policy
     * was "vague => null". The rank ladder makes the guess correctable, so a
     * last-resort guess is now better than nothing.
     *
     * Still deliberately absent: health/beauty, public figure, personal blog,
     * entrepreneur, product/service, local business. No single slug is a
     * defensible guess for any of them.
     *
     * @var array<string, string>
     */
    private const AMBIGUOUS_CATEGORY_SECTORS = ['artist' => 'artist'];
```

Then add the entry point next to `fromInstagramCategory()`:

```php
    /**
     * Resolve a sector from a whole Instagram profile — the live classifier.
     *
     * Three tiers, in order:
     *   1. fromInstagramCategory() per candidate, UNCHANGED. First that maps wins.
     *   2. classifyText() over the handle, then the display name.
     *   3. AMBIGUOUS_CATEGORY_SECTORS per segment.
     *
     * TIER 1 DELEGATES ON PURPOSE — do not inline it. fromInstagramCategory is
     * segment-major (for each segment: exact ?? keyword), and Instagram
     * comma-joins categories PRIMARY FIRST, so segment-major is what makes the
     * primary category win. Resolving exact-matches across all segments before
     * keyword-matches lets a secondary category outrank the primary one:
     * "Restaurant, Digital Creator" becomes content-creator, isFood() goes
     * false, and can_use_menu / can_use_reservations / can_use_online_ordering
     * silently switch off. Three revisions of the design spec proposed exactly
     * that reordering; delegating makes it unrepresentable.
     *
     * @param  list<mixed>  $categoryCandidates  raw per-actor category keys, in precedence order
     */
    public static function fromInstagramProfile(array $categoryCandidates, ?string $username, ?string $fullName): ?string
    {
        foreach ($categoryCandidates as $candidate) {
            $mapped = self::fromInstagramCategory(is_string($candidate) ? $candidate : null);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        foreach ([$username, $fullName] as $text) {
            $mapped = self::classifyText($text);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        // Per segment, not whole-string: Instagram emits its literal "None" as a
        // real segment, so "None,Artist" would never match whole-string.
        foreach ($categoryCandidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            foreach (self::categorySegments($candidate) as $segment) {
                $mapped = self::AMBIGUOUS_CATEGORY_SECTORS[strtolower(trim($segment))] ?? null;
                if ($mapped !== null) {
                    return $mapped;
                }
            }
        }

        return null;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`
Expected: PASS — the whole file, including the pre-existing primacy block at `:335-366`, which still exercises `fromInstagramCategory` directly and must stay green.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Services/Profile/SectorTaxonomy.php tests/Unit/Profile/SectorTaxonomyClassificationTest.php
git add app/Services/Profile/SectorTaxonomy.php tests/Unit/Profile/SectorTaxonomyClassificationTest.php
git commit -m "feat(sector): classify an Instagram profile, not just its category

Three tiers: the unchanged category classifier, then the handle and display
name, then a last-resort ambiguous-category guess. Tier 1 delegates to
fromInstagramCategory so category primacy cannot regress — reordering it
lets a secondary category outrank the primary one and kill can_use_menu."
```

---

## Task 5: `isFoodDemotion` — the pure half of the food guard

**Files:**
- Modify: `app/Services/Profile/SectorProvenance.php`
- Test: `tests/Unit/Profile/SectorProvenanceTest.php`

**Interfaces:**
- Consumes: `SectorTaxonomy::isFood()`.
- Produces: `SectorProvenance::isFoodDemotion(bool $isBusiness, ?string $currentSector, string $incomingSector): bool`.

Spec §4.8. `$isBusiness` is a **parameter**, not derived — CLAUDE.md permits reading `account_type` only inside `AccountCapabilities`, and callers already hold the capability boolean. A bool parameter also keeps this unit-testable with no model.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Profile/SectorProvenanceTest.php`:

```php
it('identifies a food demotion only on a business account leaving a food sector', function () {
    // The case that matters: business, currently food, moving to non-food.
    expect(SectorProvenance::isFoodDemotion(true, 'restaurant', 'event-venue'))->toBeTrue();
    expect(SectorProvenance::isFoodDemotion(true, 'cafe', 'barber'))->toBeTrue();

    // Not a business — sector gates no capability, so nothing to protect.
    expect(SectorProvenance::isFoodDemotion(false, 'restaurant', 'event-venue'))->toBeFalse();
    // Not currently food.
    expect(SectorProvenance::isFoodDemotion(true, 'barber', 'event-venue'))->toBeFalse();
    expect(SectorProvenance::isFoodDemotion(true, null, 'event-venue'))->toBeFalse();
    // Staying in food.
    expect(SectorProvenance::isFoodDemotion(true, 'restaurant', 'cafe'))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorProvenanceTest.php`
Expected: FAIL — `Call to undefined method ...::isFoodDemotion()`.

- [ ] **Step 3: Write the implementation**

Add to `app/Services/Profile/SectorProvenance.php`:

```php
    /**
     * Is this write about to move a BUSINESS out of a food sector?
     *
     * Matters because isFood() gates can_use_menu / can_use_reservations /
     * can_use_booking / can_use_online_ordering, and PageCapabilities enforces
     * at WRITE time so pages are not dropped at render — meaning a demotion
     * leaves a Menu page live on the public site while 403-ing the owner out
     * of editing it. Callers pair this with FoodContentProbe (see §4.8).
     *
     * $isBusiness is passed in, never derived: account_type may only be read
     * inside AccountCapabilities. Callers hold the capability boolean already.
     */
    public static function isFoodDemotion(bool $isBusiness, ?string $currentSector, string $incomingSector): bool
    {
        return $isBusiness
            && SectorTaxonomy::isFood($currentSector)
            && ! SectorTaxonomy::isFood($incomingSector);
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorProvenanceTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Services/Profile/SectorProvenance.php tests/Unit/Profile/SectorProvenanceTest.php
git add app/Services/Profile/SectorProvenance.php tests/Unit/Profile/SectorProvenanceTest.php
git commit -m "feat(sector): pure predicate for a business food demotion"
```

---

## Task 6: `FoodContentProbe` — one query, no lazy loading

**Files:**
- Create: `app/Services/Profile/FoodContentProbe.php`
- Test: `tests/Feature/Profile/FoodContentProbeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `FoodContentProbe::existsFor(User $user): bool` (instance method — injected, not static, so it can be swapped in tests and at slice 4).

Spec §4.8. Four things to get right:

1. **`site.pages` is not optional.** The symptom `PageCapabilities.php:8-16` documents is *"my Menu page disappeared and nothing told me why."* A business with a Menu page and no dishes yet has zero menu items — omitting the pages clause lets exactly the case the guard exists for slip through.
2. **Never touch `$user->site`.** `preventLazyLoading` throws in dev and test. Use `Site::query()->where('user_id', …)` sub-selects.
3. **One query.** It runs inside the LIFE-107 lock. Four `EXISTS` clauses in a single `SELECT`, not four round-trips.
4. **Keep the menu-items clause isolated.** Spec §9: the content-pool convergence retires `site.menu_items` onto `content.items` at slice 4. One named expression means one swap later — and the probe already reads `content.items` for the `menu_item` kind, so slice 4 deletes a clause rather than rewriting one.

> **`menu_item` is a content item kind, not a section kind.** `site.sections.kind` is CHECK-constrained to `collection|richtext|contact_form|newsletter|map|document|policy`; `PageCapabilities::GATED_KINDS` gates `'menu_item'` against `content.items`. Do not add a `site.sections` clause — it cannot match.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Profile/FoodContentProbeTest.php`:

```php
<?php

// The probe behind the food-demotion guard. Each clause is a distinct way a
// business can have live food content that a sector demotion would strand.

use App\Models\Core\User\User;
use App\Services\Profile\FoodContentProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();      // site.menus, site.menu_items, site.platform_connections
    setupSectionsTables();  // site.pages, site.sections
});

function probeUser(): User
{
    return User::factory()->create(['account_type' => 'business', 'sector' => 'restaurant']);
}

function probeSite(User $user): string
{
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'architecture_id' => 'staple',
        'is_published' => true,
    ]);

    return $siteId;
}

it('is false for a user with no food content at all', function () {
    $user = probeUser();
    probeSite($user);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeFalse();
});

it('is true when a menu carries items', function () {
    $user = probeUser();
    $menuId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => $menuId, 'user_id' => $user->id, 'fetch_status' => 'ok',
    ]);
    DB::connection('pgsql')->table('site.menu_items')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menuId, 'name' => 'Laksa',
    ]);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeTrue();
});

it('is true for a Menu page with no dishes yet', function () {
    // THE case the guard exists for. Zero menu items, but a live public page
    // the owner would be 403'd out of editing after a demotion.
    $user = probeUser();
    $siteId = probeSite($user);
    DB::connection('pgsql')->table('site.pages')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $siteId,
        'key' => 'menu', 'label' => 'Menu', 'sort_order' => 1, 'capability' => 'menu',
    ]);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeTrue();
});

it('is true when an online-ordering platform is connected', function () {
    $user = probeUser();
    probeSite($user);
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id,
        'platform' => 'online-ordering', 'is_active' => true,
    ]);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeTrue();
});

it('is true when a menu_item content item exists', function () {
    // menu_item is a content item kind (PageCapabilities::GATED_KINDS), not a
    // section kind — site.sections.kind does not permit it.
    $user = probeUser();
    probeSite($user);
    DB::connection('pgsql')->table('content.items')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'kind' => 'menu_item',
        'first_seen_at' => now()->toDateTimeString(), 'last_seen_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeTrue();
});

it('ignores a soft-deleted menu', function () {
    $user = probeUser();
    probeSite($user);
    $menuId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => $menuId, 'user_id' => $user->id, 'fetch_status' => 'ok',
        'deleted_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.menu_items')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menuId, 'name' => 'Laksa',
    ]);

    expect(app(FoodContentProbe::class)->existsFor($user))->toBeFalse();
});

it('does not lazy-load the site relation', function () {
    // preventLazyLoading is on outside production; a relation access would throw.
    $user = probeUser();
    probeSite($user);

    expect(fn () => app(FoodContentProbe::class)->existsFor($user))->not->toThrow(Exception::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Profile/FoodContentProbeTest.php`
Expected: FAIL — `Target class [App\Services\Profile\FoodContentProbe] does not exist.`

- [ ] **Step 3: Write the implementation**

Create `app/Services/Profile/FoodContentProbe.php`:

```php
<?php

namespace App\Services\Profile;

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

/**
 * Does this user have live food content that a sector demotion would strand?
 *
 * Paired with SectorProvenance::isFoodDemotion — that predicate is pure and
 * short-circuits, so this query runs ONLY on an actual business food ->
 * non-food attempt, not on every identity fold.
 *
 * One query on purpose: it runs inside IdentitySync's lockForUpdate
 * transaction, so four round-trips would be four times the lock hold.
 */
class FoodContentProbe
{
    public function existsFor(User $user): bool
    {
        $connection = $user->getConnectionName();
        $userId = (string) $user->id;

        // Explicit sub-selects, never $user->site: preventLazyLoading is on
        // outside production (AppServiceProvider:372) and would throw.
        $siteIds = DB::connection($connection)
            ->table('site.sites')
            ->select('id')
            ->where('user_id', $userId);

        // SLICE 4 SWAP POINT — the content-pool convergence retires
        // site.menus/site.menu_items onto content.items where kind='menu_item'.
        // Isolated here so that migration replaces one expression.
        $hasMenuItems = DB::connection($connection)
            ->table('site.menu_items')
            ->join('site.menus', 'site.menus.id', '=', 'site.menu_items.menu_id')
            ->where('site.menus.user_id', $userId)
            ->whereNull('site.menus.deleted_at');

        $hasOrderingConnection = DB::connection($connection)
            ->table('site.platform_connections')
            ->where('user_id', $userId)
            ->where('platform', 'online-ordering');

        // A Menu PAGE with no dishes is still live on the public site, and a
        // demotion would 403 the owner out of editing it — the symptom
        // PageCapabilities' docblock names.
        $hasFoodPage = DB::connection($connection)
            ->table('site.pages')
            ->whereIn('site_id', $siteIds)
            ->whereIn('capability', ['menu', 'online_ordering', 'reservations']);

        // menu_item is a CONTENT ITEM kind, not a section kind —
        // site.sections.kind is CHECKed to collection/richtext/contact_form/
        // newsletter/map/document/policy. PageCapabilities::GATED_KINDS gates
        // 'menu_item' because a section rule saying `kind_is menu_item` is a
        // menu page wearing a different hat. This clause also happens to read
        // the table slice 4 migrates site.menu_items INTO.
        $hasFoodItems = DB::connection($connection)
            ->table('content.items')
            ->where('user_id', $userId)
            ->where('kind', 'menu_item')
            ->whereNull('removed_at');

        return DB::connection($connection)
            ->query()
            ->selectRaw('1')
            ->whereExists($hasMenuItems)
            ->orWhereExists($hasOrderingConnection)
            ->orWhereExists($hasFoodPage)
            ->orWhereExists($hasFoodItems)
            ->exists();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Profile/FoodContentProbeTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Services/Profile/FoodContentProbe.php tests/Feature/Profile/FoodContentProbeTest.php
git add app/Services/Profile/FoodContentProbe.php tests/Feature/Profile/FoodContentProbeTest.php
git commit -m "feat(sector): probe for live food content behind the demotion guard

One query, four EXISTS clauses. Includes site.pages because a Menu page with
no dishes is the exact case a demotion strands. Menu-items clause isolated
for the content-pool slice 4 swap."
```

---

## Task 7: `logTransition` — the audit line

**Files:**
- Modify: `app/Services/Profile/SectorProvenance.php`
- Test: `tests/Unit/Profile/SectorProvenanceTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `SectorProvenance::logTransition(User $user, ?string $to, ?string $toSource, string $caller, string $outcome = 'applied'): void`.

Spec §4.9. `$outcome` is required, not decoration: a refusal logged as `to_source: null` is indistinguishable from a clear-to-null. This log is the entire support story for a value Instagram may never refresh.

Note it ships to Nightwatch, not just a file — `config/nightwatch.php:49` is `env('NIGHTWATCH_LOG_LEVEL', env('LOG_LEVEL', 'warning'))` and `NIGHTWATCH_LOG_LEVEL` is unset on both envs while `LOG_LEVEL=debug`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Profile/SectorProvenanceTest.php`:

```php
use Illuminate\Support\Facades\Log;

it('logs a transition with both sources and an outcome', function () {
    $user = provenanceUser('cafe', 'google-business');
    $user->id = '00000000-0000-4000-8000-000000000001';

    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) {
        return $context['from'] === 'cafe'
            && $context['from_source'] === 'google-business'
            && $context['to'] === 'barber'
            && $context['to_source'] === 'google-business'
            && $context['outcome'] === 'applied'
            && $context['user_id'] === '00000000-0000-4000-8000-000000000001';
    });

    SectorProvenance::logTransition($user, 'barber', SectorProvenance::GOOGLE, 'IdentitySync::applySector');
});

it('distinguishes a refusal from an applied write', function () {
    $user = provenanceUser('restaurant', 'google-business');
    $user->id = '00000000-0000-4000-8000-000000000002';

    Log::shouldReceive('info')->once()->withArgs(
        fn (string $message, array $context) => $context['outcome'] === 'refused_food_demotion'
    );

    SectorProvenance::logTransition($user, 'event-venue', SectorProvenance::GOOGLE, 'IdentitySync::applySector', 'refused_food_demotion');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorProvenanceTest.php`
Expected: FAIL — `Call to undefined method ...::logTransition()`.

- [ ] **Step 3: Write the implementation**

Add to `app/Services/Profile/SectorProvenance.php` (and `use Illuminate\Support\Facades\Log;` at the top):

```php
    /**
     * Record a sector transition, applied or refused.
     *
     * Load-bearing rather than decorative: Instagram may never refresh its own
     * value (see SELF_REFRESH), so when a guess turns out wrong this line is
     * the only record of what wrote it and when. $outcome is required because a
     * refusal logged as a null target is indistinguishable from a clear-to-null.
     *
     * Callers MUST pass the LOCKED row, before the assignment — a pre-lock
     * instance logs stale provenance.
     *
     * LOG_LEVEL is debug on both envs and NIGHTWATCH_LOG_LEVEL is unset, so
     * this reaches Nightwatch as an event, not just a log file.
     */
    public static function logTransition(
        User $user,
        ?string $to,
        ?string $toSource,
        string $caller,
        string $outcome = 'applied',
    ): void {
        Log::info('sector.transition', [
            'user_id' => (string) $user->id,
            'from' => $user->sector,
            'from_source' => $user->sector_source,
            'to' => $to,
            'to_source' => $toSource,
            'caller' => $caller,
            'outcome' => $outcome,
        ]);
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorProvenanceTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Services/Profile/SectorProvenance.php tests/Unit/Profile/SectorProvenanceTest.php
git add app/Services/Profile/SectorProvenance.php tests/Unit/Profile/SectorProvenanceTest.php
git commit -m "feat(sector): log every sector transition, applied or refused"
```

---

## Task 8: Wire the ladder into `IdentitySync`

**Files:**
- Modify: `app/Services/Platforms/IdentitySync.php` (`applySector` `:216-249`, `applyUserIdentityFields` `:179-192`)
- Modify: `tests/Feature/Platforms/IdentitySyncTest.php`

**Interfaces:**
- Consumes: `SectorProvenance::mayWrite/isFoodDemotion/logTransition`, `FoodContentProbe::existsFor`.
- Produces: nothing new.

Spec §4.1, §4.4, §4.8. Four changes:

1. `applySector` collapses from two branches to one. `$overwrite` stays as a **parameter** — §4.8 needs it as the sanctioned `$isBusiness` discriminator — but no longer selects a precedence branch.
2. The food guard runs the pure predicate first, the probe only on a real demotion attempt.
3. `logTransition` is called on both the write and the refusal, on the **locked** row, **before** the assignment.
4. The site touch happens **after the transaction commits**, in `applyUserIdentityFields`, on the caller's `$user` — never on `$fresh` inside the lock, whose `site` relation is unloaded and would throw.

`FoodContentProbe` is constructor-injected. `IdentitySync` currently has no constructor; add one.

- [ ] **Step 1: Invert the existing assertion and add the new ones**

In `tests/Feature/Platforms/IdentitySyncTest.php`, add `setupSectionsTables();` to `beforeEach` after `setupBlocksTable();` — `site.pages` and `site.sections` are absent otherwise and the probe would error. (`setupSitesTable()` already creates `site.menus`, `site.menu_items` and `site.platform_connections`.)

Then **replace** the test at `:303-322` — it currently asserts Google never overwrites an instagram sector, which is the behaviour being removed:

```php
it('overwrites an instagram-sourced sector on a business google resync', function () {
    // Was the opposite until 2026-08-12. Commit 30e3d3abb widened a guard meant
    // to protect a MANUAL pick so it protected every non-Google source, which
    // let a scraper's guess outrank Google permanently.
    $user = idsyncUser('bizgooglesector2', 'business');
    $user->forceFill(['sector' => 'artist', 'sector_source' => 'instagram'])->save();
    idsyncSite($user);

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Barber shop']);

    $user->refresh();
    expect($user->sector)->toBe('barber')
        ->and($user->sector_source)->toBe('google-business');
});

it('overwrites an instagram-sourced sector on a partna account too', function () {
    // The account type Instagram pre-account builds actually produce.
    $user = idsyncUser('partnagooglesector', 'partna');
    $user->forceFill(['sector' => 'artist', 'sector_source' => 'instagram'])->save();
    idsyncSite($user);

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Barber shop']);

    $user->refresh();
    expect($user->sector)->toBe('barber')
        ->and($user->sector_source)->toBe('google-business');
});

it('never overwrites a manual sector pick, on either account type', function (string $accountType) {
    $user = idsyncUser("manualsector{$accountType}", $accountType);
    $user->forceFill(['sector' => 'artist', 'sector_source' => 'manual'])->save();
    idsyncSite($user);

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Barber shop']);

    $user->refresh();
    expect($user->sector)->toBe('artist')
        ->and($user->sector_source)->toBe('manual');
})->with(['business', 'partna']);

it('refuses to demote a business out of food while food content is live', function () {
    $user = idsyncUser('foodlock', 'business');
    $user->forceFill(['sector' => 'restaurant', 'sector_source' => 'google-business'])->save();
    $siteId = idsyncSite($user);
    DB::connection('pgsql')->table('site.pages')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $siteId,
        'key' => 'menu', 'label' => 'Menu', 'sort_order' => 1, 'capability' => 'menu',
    ]);

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Event venue']);

    $user->refresh();
    expect($user->sector)->toBe('restaurant');
});

it('allows the demotion when no food content exists', function () {
    $user = idsyncUser('foodfree', 'business');
    $user->forceFill(['sector' => 'restaurant', 'sector_source' => 'google-business'])->save();
    idsyncSite($user);

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Event venue']);

    $user->refresh();
    expect($user->sector)->toBe('event-venue');
});

it('touches the site when the sector changes so the edge cache purges', function () {
    $user = idsyncUser('sectortouch', 'partna');
    $siteId = idsyncSite($user);
    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => '2020-01-01 00:00:00']);

    app(IdentitySync::class)->applyFromGooglePayload($user, ['category' => 'Barber shop']);

    $touched = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    expect($touched)->not->toBe('2020-01-01 00:00:00');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Platforms/IdentitySyncTest.php`
Expected: FAIL — the two overwrite tests fail (sector stays `artist`), the demotion tests fail, and the touch test fails.

- [ ] **Step 3: Rewrite `applySector` and add the touch**

In `app/Services/Platforms/IdentitySync.php`, add the imports and a constructor:

```php
use App\Services\Profile\FoodContentProbe;
use App\Services\Profile\SectorProvenance;
```

```php
    public function __construct(private readonly FoodContentProbe $foodContent) {}
```

Replace the whole of `applySector` (`:216-249`) and its docblock:

```php
    /**
     * One ladder, both account types: manual > google-business > instagram.
     *
     * Was two branches. The business branch froze any non-Google source
     * permanently (commit 30e3d3abb widened a manual-only guard), and the
     * partna branch filled only blanks — so an Instagram guess locked Google
     * out on both. $overwrite no longer selects a precedence branch; it is
     * kept only as the sanctioned isBusiness discriminator for the food guard.
     *
     * $user MUST be the locked $fresh row from applyUserIdentityFields.
     */
    private function applySector(User $user, bool $overwrite, ?string $mappedSector): void
    {
        if ($mappedSector === null) {
            return;
        }

        if (! SectorProvenance::mayWrite($user, SectorProvenance::GOOGLE)) {
            return;
        }

        // Pure predicate first — the probe only queries on a real demotion.
        if (SectorProvenance::isFoodDemotion($overwrite, $user->sector, $mappedSector)
            && $this->foodContent->existsFor($user)) {
            SectorProvenance::logTransition($user, $mappedSector, self::GOOGLE_SOURCE, __METHOD__, 'refused_food_demotion');

            return;
        }

        if ($user->sector !== $mappedSector || $user->sector_source !== self::GOOGLE_SOURCE) {
            SectorProvenance::logTransition($user, $mappedSector, self::GOOGLE_SOURCE, __METHOD__);
            $user->sector = $mappedSector;
            $user->sector_source = self::GOOGLE_SOURCE;
            $user->save();
        }
    }
```

Update the call at `:187` to pass `$overwrite`:

```php
            $this->applySector($fresh, $overwrite, $mappedSector);
```

In `applyUserIdentityFields`, capture the pre-transaction sector and touch the site after the commit and refresh:

```php
    private function applyUserIdentityFields(User $user, bool $overwrite, ?string $mappedSector, ?string $phone): void
    {
        $sectorBefore = $user->sector;

        DB::connection($user->getConnectionName())->transaction(function () use ($user, $overwrite, $mappedSector, $phone) {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($fresh === null) {
                return; // Raced with a hard delete mid-sync — nothing left to fold onto.
            }

            $this->applySector($fresh, $overwrite, $mappedSector);
            $this->mirrorPublicContactNumber($fresh, $overwrite, $phone);
        });

        $user->refresh();

        // AFTER the commit, on the caller's instance: sector drives the design
        // presets, and only SiteObserver::saved dispatches the Cloudflare purge
        // — a bare $user->save() busts Redis but leaves the edge stale. Never
        // inside the lock: $fresh->site is unloaded and preventLazyLoading throws.
        if ($user->sector !== $sectorBefore) {
            $user->site()->first()?->touch();
        }
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Platforms/IdentitySyncTest.php`
Then: `./vendor/bin/pest tests/Feature/Platforms/IdentitySyncConcurrencyTest.php`
Expected: both PASS. `IdentitySyncConcurrencyTest` must pass **unmodified** — it is the LIFE-107 pin, and editing it is the signal that lock semantics moved. Its structural case counts `lockForUpdate` / `->transaction(` in `IdentitySync.php`; both still appear.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Services/Platforms/IdentitySync.php tests/Feature/Platforms/IdentitySyncTest.php
git add app/Services/Platforms/IdentitySync.php tests/Feature/Platforms/IdentitySyncTest.php
git commit -m "fix(identity): one sector ladder for both account types

Google may now correct an Instagram guess on partna as well as business; a
manual pick is untouchable on both. Refuses a business food demotion while
food content is live, and touches the site so the edge purges."
```

---

## Task 9: `FoodContentProbe` into `InstagramIdentitySync`

**Files:**
- Modify: `app/Services/Platforms/InstagramIdentitySync.php`

**Interfaces:**
- Consumes: `FoodContentProbe`, `AccountCapabilities`.
- Produces: nothing — constructor only, so Task 10's edit is a pure behaviour change.

`InstagramIdentitySync` has no constructor today. Add one so Task 10 can use the probe, and resolve the capability boolean once per fold.

- [ ] **Step 1: Add the constructor**

In `app/Services/Platforms/InstagramIdentitySync.php`:

```php
use App\Services\Accounts\AccountCapabilities;
use App\Services\Profile\FoodContentProbe;
use App\Services\Profile\SectorProvenance;
use Illuminate\Support\Facades\DB;
```

```php
    public function __construct(private readonly FoodContentProbe $foodContent) {}
```

- [ ] **Step 2: Verify nothing broke**

Run: `./vendor/bin/pest tests/Feature/Platforms/InstagramIdentitySyncTest.php`
Expected: PASS unchanged — the class is container-resolved everywhere (`app(InstagramIdentitySync::class)` in tests, constructor-injected into `InstagramConnectionSeeder`), so autowiring supplies the probe.

- [ ] **Step 3: Commit**

```bash
./vendor/bin/pint app/Services/Platforms/InstagramIdentitySync.php
git add app/Services/Platforms/InstagramIdentitySync.php
git commit -m "chore(instagram): inject FoodContentProbe ahead of the ladder change"
```

---

## Task 10: The Instagram ladder, lock, and refresh

**Files:**
- Modify: `app/Services/Platforms/InstagramIdentitySync.php` (`applyIdentity` `:22-48`, `applySector` `:58-80`)
- Modify: `tests/Feature/Platforms/InstagramIdentitySyncTest.php`
- Create: `tests/Feature/Platforms/InstagramIdentitySyncConcurrencyTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–7, 9.
- Produces: nothing new.

Spec §4.2, §4.3. Four changes:

1. Call `fromInstagramProfile` with the candidate list **and** the two text signals, each through `stringOrNull` — a non-string from Apify would be a TypeError, and there is no try/catch on this path (`InstagramConnectionSeeder.php:229`; the two in `seed()` at `:148` and `:285` do not enclose it).
2. Take the `lockForUpdate` re-read `IdentitySync` already has. This is a **pre-existing** lost update — a stale `null` read against a row Google just set passes `isBlank()` and clobbers it today — but §1.5 makes Google-then-Instagram on one unclaimed row a live ordering.
3. **`$user->refresh()` after the transaction.** Non-negotiable: `InstagramConnectionSeeder.php:230` passes the same `$user` to `autoSaveUnmatchedLinks` → `CustomLinkSeeder::seed` → `LinkRouter::route` → `gateAllows`, which reads `$user->sector` at `LinkRouter.php:164`. Writing to `$fresh` without refreshing gates the second half of one bio-link run on a stale sector.
4. Touch the site on a change, after the commit.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Platforms/InstagramIdentitySyncTest.php`:

```php
use App\Services\Profile\SectorProvenance;

it('classifies from the handle when the category is too vague', function () {
    // jesshairstylist: a Prahran hairdresser whose Instagram category is "Artist".
    $user = User::factory()->create([
        'account_type' => 'partna', 'sector' => null, 'sector_source' => null,
    ]);

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'businessCategoryName' => 'Artist',
        'username' => 'jess.hair.stylist',
        'fullName' => 'Jess',
    ]);

    $user->refresh();
    expect($user->sector)->toBe('hair-salon')
        ->and($user->sector_source)->toBe('instagram');
});

it('does not overwrite a google-business or manual sector', function (string $source) {
    $user = User::factory()->create([
        'account_type' => 'partna', 'sector' => 'cafe', 'sector_source' => $source,
    ]);

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'businessCategoryName' => 'Hair Salon',
        'username' => 'janes_salon',
    ]);

    $user->refresh();
    expect($user->sector)->toBe('cafe')
        ->and($user->sector_source)->toBe($source);
})->with(['google-business', 'manual']);

it('does not refresh its own earlier value', function () {
    // PARTNA_INSTAGRAM_ACTOR is a no-deploy rollback whose actors return
    // different keys — allowing self-refresh would let an env flip rewrite this.
    $user = User::factory()->create([
        'account_type' => 'partna', 'sector' => 'artist', 'sector_source' => 'instagram',
    ]);

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'businessCategoryName' => 'Hair Salon',
        'username' => 'janes_salon',
    ]);

    $user->refresh();
    expect($user->sector)->toBe('artist');
});

it('refreshes the caller instance so downstream link routing sees the new sector', function () {
    // InstagramConnectionSeeder:230 hands this same instance to
    // autoSaveUnmatchedLinks -> LinkRouter::gateAllows, which reads ->sector.
    $user = User::factory()->create([
        'account_type' => 'partna', 'sector' => null, 'sector_source' => null,
    ]);

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'businessCategoryName' => 'Hair Salon',
        'username' => 'janes_salon',
    ]);

    // No refresh() here on purpose — the service must have done it.
    expect($user->sector)->toBe('hair-salon');
});
```

Create `tests/Feature/Platforms/InstagramIdentitySyncConcurrencyTest.php`:

```php
<?php

// LIFE-107 for the Instagram writer. IdentitySync takes a locked re-read before
// deciding whether to write; InstagramIdentitySync did not, so a stale instance
// could clobber a value Google had just committed.

use App\Models\Core\User\User;
use App\Services\Platforms\InstagramIdentitySync;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
    setupSectionsTables();
});

it('does not clobber a value committed after the caller loaded the user', function () {
    $user = User::factory()->create([
        'account_type' => 'partna', 'sector' => null, 'sector_source' => null,
    ]);

    // Simulate Google's fold committing while $user is still in memory as blank.
    DB::connection('pgsql')->table('core.users')->where('id', $user->id)->update([
        'sector' => 'cafe', 'sector_source' => 'google-business',
    ]);

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'businessCategoryName' => 'Hair Salon',
        'username' => 'janes_salon',
    ]);

    expect(DB::connection('pgsql')->table('core.users')->where('id', $user->id)->value('sector'))
        ->toBe('cafe');
});

it('takes a lock, not just a re-read', function () {
    // lockForUpdate() is a no-op on SQLite, so the behavioural test above passes
    // equally against a bare refresh() with no transaction. Mirrors the
    // structural pin in IdentitySyncConcurrencyTest.
    $source = file_get_contents(app_path('Services/Platforms/InstagramIdentitySync.php'));

    expect(substr_count($source, 'lockForUpdate'))->toBeGreaterThanOrEqual(1)
        ->and(substr_count($source, '->transaction('))->toBeGreaterThanOrEqual(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Platforms/InstagramIdentitySyncTest.php tests/Feature/Platforms/InstagramIdentitySyncConcurrencyTest.php`
Expected: FAIL — the handle test returns null, the self-refresh test overwrites, the concurrency test finds `cafe` clobbered, and the structural pin finds zero `lockForUpdate`.

- [ ] **Step 3: Rewrite the call and the fold**

In `applyIdentity`, replace the `$this->applySector(...)` call:

```php
        $this->applySector(
            $user,
            [
                $payload['businessCategoryName'] ?? null,
                $payload['business_category_name'] ?? null,
                $payload['category_name'] ?? null,
            ],
            $this->stringOrNull($payload['username'] ?? null),
            $this->stringOrNull($payload['fullName'] ?? $payload['full_name'] ?? null),
        );
```

Replace `applySector` entirely:

```php
    /**
     * Fold a sector under the shared ladder (manual > google > instagram).
     *
     * Locked like IdentitySync's fold (LIFE-107): this used to read and write
     * the caller's instance, so a stale blank read could clobber a value Google
     * had just committed — a live ordering, since GoogleBusinessAutoSync
     * dispatches the Instagram connect after Google's own fold on an unclaimed
     * business build.
     *
     * @param  list<mixed>  $categoryCandidates
     */
    private function applySector(User $user, array $categoryCandidates, ?string $username, ?string $fullName): void
    {
        $mapped = SectorTaxonomy::fromInstagramProfile($categoryCandidates, $username, $fullName);
        if ($mapped === null) {
            return;
        }

        $isBusiness = AccountCapabilities::for($user)->google_business_full_sync;
        $sectorBefore = $user->sector;

        DB::connection($user->getConnectionName())->transaction(function () use ($user, $mapped, $isBusiness) {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($fresh === null) {
                return; // Raced with a hard delete mid-sync.
            }

            if (! SectorProvenance::mayWrite($fresh, self::INSTAGRAM_SOURCE)) {
                return;
            }

            if (SectorProvenance::isFoodDemotion($isBusiness, $fresh->sector, $mapped)
                && $this->foodContent->existsFor($fresh)) {
                SectorProvenance::logTransition($fresh, $mapped, self::INSTAGRAM_SOURCE, __METHOD__, 'refused_food_demotion');

                return;
            }

            SectorProvenance::logTransition($fresh, $mapped, self::INSTAGRAM_SOURCE, __METHOD__);
            $fresh->sector = $mapped;
            $fresh->sector_source = self::INSTAGRAM_SOURCE;
            $fresh->save();
        });

        // MUST refresh: InstagramConnectionSeeder:230 hands this same instance to
        // autoSaveUnmatchedLinks -> LinkRouter::gateAllows, which reads ->sector.
        $user->refresh();

        if ($user->sector !== $sectorBefore) {
            $user->site()->first()?->touch();
        }
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Platforms/InstagramIdentitySyncTest.php tests/Feature/Platforms/InstagramIdentitySyncConcurrencyTest.php`
Expected: both PASS. Then run the seeder suites, which exercise this fold end to end:
`./vendor/bin/pest tests/Feature/Platforms/InstagramSeederCategoryTest.php tests/Feature/Platforms/InstagramAsyncConnectTest.php`

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Services/Platforms/InstagramIdentitySync.php tests/Feature/Platforms/InstagramIdentitySyncTest.php tests/Feature/Platforms/InstagramIdentitySyncConcurrencyTest.php
git add app/Services/Platforms/InstagramIdentitySync.php tests/Feature/Platforms/
git commit -m "fix(instagram): fold the sector under the shared ladder, and lock it

Classifies from the handle when the category is vague, so a hairdresser
tagged 'Artist' resolves to hair-salon. Takes the LIFE-107 lock the Google
writer already had, and refreshes the caller instance so the same run's link
routing does not gate on a stale sector."
```

---

## Task 11: `askSector` must survive the guess

**Files:**
- Modify: `app/Services/Onboarding/OnboardingSuggestions.php:214`
- Modify: `tests/Feature/Onboarding/OnboardingSuggestionsTest.php`

**Interfaces:**
- Consumes: `SectorProvenance::MANUAL`.
- Produces: nothing.

Spec §4.6. `askSector` currently fires only when the sector is null — so writing a guess removes the only prompt that lets the user correct it. Both legs change: the null test becomes a provenance test, and `! $isBusiness` is dropped, because business is the one type where sector gates capabilities and it demonstrably acquires Instagram guesses via `GoogleBusinessAutoSync::dispatchInstagram`.

- [ ] **Step 1: Update the two existing assertions and add new ones**

In `tests/Feature/Onboarding/OnboardingSuggestionsTest.php`, the helper `onboardingUser` (`:21-32`) never sets `sector_source`, so both `:66` (partna, `hair-salon`) and `:103` (business, `restaurant`) currently assert `askSector` is **false** and must flip to **true**. Change both assertions, then append:

```php
it('keeps asking for the sector until a human picks one', function (string $accountType, ?string $source, bool $expected) {
    $user = onboardingUser("asksector{$accountType}".($source ?? 'null'), $accountType, 'hair-salon');
    $user->forceFill(['sector_source' => $source])->save();

    expect(app(OnboardingSuggestions::class)->for($user)['askSector'])->toBe($expected);
})->with([
    'partna, instagram guess' => ['partna', 'instagram', true],
    'business, instagram guess' => ['business', 'instagram', true],
    'partna, google sync' => ['partna', 'google-business', true],
    'partna, manual pick' => ['partna', 'manual', false],
    'business, manual pick' => ['business', 'manual', false],
]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Onboarding/OnboardingSuggestionsTest.php`
Expected: FAIL — the business cases return false, and the guess cases return false.

- [ ] **Step 3: Change the gate**

In `app/Services/Onboarding/OnboardingSuggestions.php`, add `use App\Services\Profile\SectorProvenance;` and replace line 214:

```php
            // Ask until a HUMAN picks one. Gating on `$sector === null` meant a
            // scraped guess silently removed the only prompt that lets the user
            // correct it. Business included: it is the one type where sector
            // gates capabilities, and it can acquire an Instagram guess via
            // GoogleBusinessAutoSync::dispatchInstagram.
            'askSector' => $user->sector_source !== SectorProvenance::MANUAL,
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Onboarding/OnboardingSuggestionsTest.php`
Expected: PASS. Check `askWorkplace` (`:216`) assertions too — it is gated on `$sector !== null` and now fires alongside `askSector` more often.

- [ ] **Step 5: Format and commit**

```bash
./vendor/bin/pint app/Services/Onboarding/OnboardingSuggestions.php tests/Feature/Onboarding/OnboardingSuggestionsTest.php
git add app/Services/Onboarding/OnboardingSuggestions.php tests/Feature/Onboarding/OnboardingSuggestionsTest.php
git commit -m "fix(onboarding): ask for the sector until a human picks one

Gating on sector===null meant writing a guess removed the prompt that lets
the user fix it. Business included — it is the only type where sector gates
capabilities."
```

---

## Task 12: Pin the rank table against the real CHECK constraint

**Files:**
- Create: `tests/Schema/SectorSourceCheckTest.php`
- Modify: `tests/Unit/Profile/SectorProvenanceTest.php`

**Interfaces:**
- Consumes: `SectorProvenance::RANKS` via Reflection.
- Produces: nothing.

Spec §5. Two pins, because they run in different lanes. `tests/Schema/` runs in CI (`ci.yml:486 composer test:schema`) but **not** in `composer test` (`phpunit.xml:7-14`), so the fast-lane version is the everyday signal and the schema one is the authority.

Asserting `array_keys(RANKS)` against a hard-coded list would be self-referential — it fails only when someone edits `RANKS`, which is the case that needs no catching. Both pins compare against the constraint's own text.

- [ ] **Step 1: Write both tests**

Create `tests/Schema/SectorSourceCheckTest.php`:

```php
<?php

// Applied-schema lane. Ties SectorProvenance's rank table to the real CHECK, so
// widening the constraint forces a rank decision instead of a silent rank-0.

use App\Services\Profile\SectorProvenance;
use Illuminate\Support\Facades\DB;

it('ranks exactly the sources users_sector_source_check permits', function () {
    $def = DB::connection('pgsql')->selectOne(
        "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'users_sector_source_check'"
    );

    expect($def)->not->toBeNull('users_sector_source_check is missing');

    preg_match_all("/'([a-z-]+)'::text/", $def->def, $matches);
    $allowed = array_values(array_unique($matches[1]));

    $ranked = array_keys((new ReflectionClass(SectorProvenance::class))->getConstant('RANKS'));

    sort($allowed);
    sort($ranked);

    expect($ranked)->toBe($allowed);
})->group('postgres');
```

Append to `tests/Unit/Profile/SectorProvenanceTest.php`:

```php
it('ranks exactly the sources the migrations permit', function () {
    // Fast-lane mirror of tests/Schema/SectorSourceCheckTest.php, which does not
    // run in `composer test`. Scans every migration, not just the baseline — a
    // later one is how the CHECK would actually widen.
    $allowed = [];
    foreach (glob(base_path('supabase/migrations/*.sql')) as $file) {
        $sql = file_get_contents($file);
        if (! str_contains($sql, 'users_sector_source_check')) {
            continue;
        }
        preg_match('/users_sector_source_check.*?ARRAY\[(.*?)\]/s', $sql, $m);
        if (isset($m[1])) {
            preg_match_all("/'([a-z-]+)'/", $m[1], $values);
            $allowed = $values[1];
        }
    }

    expect($allowed)->not->toBeEmpty('users_sector_source_check not found in any migration');

    $ranked = array_keys((new ReflectionClass(SectorProvenance::class))->getConstant('RANKS'));

    sort($allowed);
    sort($ranked);

    expect($ranked)->toBe($allowed);
});
```

- [ ] **Step 2: Run both to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorProvenanceTest.php`
Expected: PASS.

Run the schema lane (needs shell `DB_*` vars pointing at a Postgres instance — see `reference_schema_lane_local_invocation`):
`composer test:schema`
Expected: PASS.

> These pass immediately rather than failing first — they pin an existing invariant rather than driving new behaviour. Verify they can fail: temporarily add `'facebook' => 4` to `RANKS`, confirm both go red, then remove it.

- [ ] **Step 3: Format and commit**

```bash
./vendor/bin/pint tests/Schema/SectorSourceCheckTest.php tests/Unit/Profile/SectorProvenanceTest.php
git add tests/Schema/SectorSourceCheckTest.php tests/Unit/Profile/SectorProvenanceTest.php
git commit -m "test(sector): tie the rank table to users_sector_source_check

Both lanes: pg_get_constraintdef in tests/Schema (CI only) and a migration
scan in the fast lane. Widening the CHECK now forces a rank decision."
```

---

## Task 13: Repair the comments this branch falsifies

**Files:**
- Modify: `app/Services/Profile/SectorTaxonomy.php:204-211`
- Modify: `app/Http/Controllers/Api/User/Profile/SectorController.php:11-16`
- Modify: `app/Services/Platforms/InstagramIdentitySync.php:13-17`

**Interfaces:** none.

Spec §4.10. Four comments state the old precedence as fact. Left alone, the next reader restores the reasoning this branch removes — `SectorTaxonomy.php:204-211` in particular explains why there is no bare `'artist'` keyword, and its stated reason ("a sector is STICKY … locks Google Business out of ever correcting it, permanently") stops being true here.

- [ ] **Step 1: Rewrite the `KEYWORD_SECTORS` closing comment**

Replace `SectorTaxonomy.php:204-211` with:

```php
        // NO bare 'artist' key, deliberately — but not for the old reason.
        // "Artist" is one of Instagram's most generic categories: tattooists,
        // musicians, hairdressers and photographers all pick it, and the
        // substring map has no other signal to disambiguate it. It is handled
        // in fromInstagramProfile's last tier instead, AFTER the handle and
        // display name have had a go — jess.hair.stylist resolves to
        // hair-salon there. Keeping it out of THIS map also keeps it off the
        // Google path, which has no handle to fall back to.
        // 'art gallery'/'gallery' stay: unambiguous.
```

- [ ] **Step 2: Rewrite the other two**

`SectorController.php:11-16` — replace any description of "first non-Google source wins" with a pointer to the ladder:

```php
// Writes sector_source='manual', the top rank in SectorProvenance's ladder:
// nothing automated overwrites a human's pick. Clearing the sector nulls the
// source too, which returns the row to "any source may fill it".
```

`InstagramIdentitySync.php:13-17` — replace the class docblock's precedence prose:

```php
 * Fold Instagram-scraped identity fields into a user's core.users columns.
 * Instagram is the LOWEST-ranked sector source (see SectorProvenance): it fills
 * a blank, and loses to Google Business and to a human's pick. It may not even
 * refresh its own earlier value — PARTNA_INSTAGRAM_ACTOR is a no-deploy
 * rollback whose two actors return different keys.
```

- [ ] **Step 3: Verify nothing broke**

Run: `./vendor/bin/pest tests/Unit/Profile/ tests/Feature/Platforms/ tests/Feature/Onboarding/`
Expected: PASS.

- [ ] **Step 4: Format and commit**

```bash
./vendor/bin/pint app/Services/Profile/SectorTaxonomy.php app/Http/Controllers/Api/User/Profile/SectorController.php app/Services/Platforms/InstagramIdentitySync.php
git add app/Services/Profile/SectorTaxonomy.php app/Http/Controllers/Api/User/Profile/SectorController.php app/Services/Platforms/InstagramIdentitySync.php
git commit -m "docs(sector): correct comments describing the old precedence rule"
```

---

## Task 14: Full-suite verification

**Files:** none modified.

- [ ] **Step 1: Run the related suites**

```bash
./vendor/bin/pest tests/Unit/Profile/ tests/Feature/Profile/ tests/Feature/Platforms/ tests/Feature/Onboarding/
```

Expected: PASS. Pay attention to `SectorCapabilityGatingTest`, `InstagramSeederCategoryTest`, `SectorTaxonomyTest`, `SectorControllerTest` and `ProfileDesignPresetsTest` — all read sector and none should change behaviour.

- [ ] **Step 2: Run the routing corpus**

```bash
./vendor/bin/pest tests/Feature/Routing/ tests/Unit/Routing/
```

`LinkRouter.php:164` reads `$user->sector` through its own copy of the food arms, and Task 10 changed when the sector is visible within a seeder run.

- [ ] **Step 3: Run the full suite**

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer test
```

Expected: PASS. The env var is required — the suite exceeds Composer's default process timeout and dies without it.

- [ ] **Step 4: Static analysis**

```bash
./vendor/bin/phpstan analyse --memory-limit=2G
```

Expected: no new errors. If `SectorProvenance::RANKS[$existingSource]` reports a nullable-offset error, bind `$existingSource` to a local `string` after the `isset` guard — narrowing a nullable offset through `isset` on a private const is not reliable at this level.

- [ ] **Step 5: Schema lane**

```bash
composer test:schema
```

Expected: PASS, including the new `SectorSourceCheckTest`.

---

## Post-merge obligations

These belong to other work and should be recorded wherever that work is tracked (spec §9):

- [ ] **Content-pool slice 4 (menus)** migrates `FoodContentProbe`'s menu-items clause from `site.menus`/`site.menu_items` to `content.items where kind = 'menu_item'`. The probe is a silent consumer, not a wire surface — nothing else will surface it.
- [ ] **The Instagram media-pool / multi-photo-mirror work** re-checks Task 10's `$user->refresh()` placement after reordering `seed()`. It depends on `$user` being resolved at `InstagramConnectionSeeder.php:227` and consumed at `:230`; a reorder breaks that silently while merging cleanly.
- [ ] **Verify the six `INSTAGRAM_CATEGORY_SECTORS` keys** from Task 2 against observed Apify payloads. `barre studio` and `bartender` are the least certain.
- [ ] **Frontend answer needed:** is `OnboardingSuggestions` one-shot (post-claim setup only) or polled? If one-shot, Task 11's prompt never re-fires after a later sector change, and §4.6 is a weaker correction path than it looks.
