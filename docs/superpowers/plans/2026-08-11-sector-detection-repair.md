# Sector Detection Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Raise Instagram-sourced sector detection from 1-in-3 by stopping placeholder scrape values reaching the public wire, closing the `artist`/`makeup-artist` taxonomy hole, and adding an Instagram-native category map.

**Architecture:** Three independent changes at two seams. (A) `InstagramConnectionSeeder` filters placeholder category strings at the single write seam, and `SectorTaxonomy::classify()` widens its existing `'none'` guard to the same shared list. (B) Two keywords added to the shared substring map `KEYWORD_SECTORS`, ordered specific-before-generic. (C) `fromInstagramCategory()` becomes two-pass: an **exact-match** lookup against a new `INSTAGRAM_CATEGORY_SECTORS` map (Instagram categories are a closed vocabulary, so exact match has zero collision surface), falling through to the existing substring classifier. `fromGoogleCategory()` is untouched by C — zero Google regression risk.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4. No migration, no new dependency, no schema change.

## Global Constraints

- **No Laravel migration files.** None of these tasks need schema changes. If one seems to, stop.
- **`KEYWORD_SECTORS` ordering is a contract.** `classify()` returns the FIRST substring match. A generic keyword must never precede a more specific colliding one. `tests/Unit/Profile/SectorTaxonomyClassificationTest.php` pins one representative input per entry; a bad reorder flips one of those assertions.
- **`composer test --filter` is broken in this repo.** Run Pest directly with a file path: `./vendor/bin/pest tests/path/File.php`. Never `composer test --filter=...`.
- **Tests run SQLite, prod is Postgres.** All three tasks are pure PHP logic with no constraint-bound writes, so this does not bite here — but do not add DB assertions beyond the existing `setupUsersTable()` helpers.
- **`sector` feeds capability gating.** `SectorTaxonomy::FOOD_SECTORS` drives `AccountCapabilities::can_use_menu` / `can_use_reservations` / `can_use_online_ordering`. Never add a keyword or exact-map entry that could resolve a non-food business to `restaurant`, `cafe`, `bakery`, `bar`, `food-truck`, `caterer`, or `personal-chef`.
- **Comment for WHY, not what.** Brief. No banners, no paragraphs.
- Run `php artisan pint` on touched files before each commit. Invoke the binary directly (`./vendor/bin/pint`) — the composer script wrapper is broken.

---

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `app/Services/Profile/SectorTaxonomy.php` | Curated sector vocabulary + both classifiers. Gains `PLACEHOLDER_CATEGORIES` (A), two `KEYWORD_SECTORS` entries (B), `INSTAGRAM_CATEGORY_SECTORS` + two-pass `fromInstagramCategory()` (C). | A, B, C |
| `app/Services/Platforms/InstagramConnectionSeeder.php` | Builds the stored `$selection` payload. Gains `categoryOrNull()` applied at `:161`. | A |
| `tests/Unit/Profile/SectorTaxonomyClassificationTest.php` | Pins both classifiers. Gains placeholder cases (A), artist/makeup cases (B), Instagram exact-map cases (C). | A, B, C |
| `tests/Feature/Platforms/InstagramIdentitySyncTest.php` | Pins the identity fold. Gains a placeholder-category case (A). | A |
| `tests/Feature/Platforms/InstagramSeederCategoryTest.php` | **New.** Pins that the seeder never stores a placeholder category. | A |

---

## Task 1: Stop placeholder categories at the write seam (A)

**Why:** `crucibletattooco`'s degraded scrape (F4) returned the literal four-character string `"None"` — Python's `None` stringified by the figue actor. It is stored in `site.platform_connections.payload.businessCategory` and `businessCategory` is on `PublicIntegrationConnectionResource::ALLOWLIST['instagram']` (`:80`), so the word "None" is published as that professional's business category. `SectorTaxonomy::classify()` already guards `'none'` for classification (`:308`), but nothing guards the stored payload.

**Files:**
- Modify: `app/Services/Profile/SectorTaxonomy.php` — add `PLACEHOLDER_CATEGORIES` const; use it in `classify()` at `:305-319`
- Modify: `app/Services/Platforms/InstagramConnectionSeeder.php:161-162` — wrap in `categoryOrNull()`; add the private method
- Create: `tests/Feature/Platforms/InstagramSeederCategoryTest.php`
- Modify: `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`
- Modify: `tests/Feature/Platforms/InstagramIdentitySyncTest.php`

**Interfaces:**
- Produces: `SectorTaxonomy::PLACEHOLDER_CATEGORIES` — `public const list<string>`, lowercase, used by Tasks 1 only but public so the seeder can read it.
- Produces: `InstagramConnectionSeeder::categoryOrNull(mixed $value): ?string` — private.

- [ ] **Step 1: Write the failing classifier test**

Append to `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`:

```php
/**
 * F4/F5 (2026-08-10 build wave): a degraded figue actor run stringifies
 * Python's None into businessCategoryName. Guard the whole placeholder set,
 * not just "none".
 */
it('returns null for every placeholder category string, in any casing', function (string $input) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBeNull()
        ->and(SectorTaxonomy::fromGoogleCategory($input))->toBeNull();
})->with(['None', 'none', 'NONE', ' None ', 'null', 'NULL', 'N/A', 'n/a', '-']);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

Expected: FAIL on `'null'`, `'N/A'`, `'n/a'`, `'-'` (the `'none'` variants already pass via the existing guard).

- [ ] **Step 3: Add the const and widen the guard**

In `app/Services/Profile/SectorTaxonomy.php`, add above `KEYWORD_SECTORS` (after the `FOOD_SECTORS` const):

```php
    /**
     * Values a scraper emits in place of a real category — the figue actor
     * stringifies Python's None. Never classified, and never stored (the
     * Instagram seeder reads this same list at its write seam, because
     * businessCategory is on the public wire).
     *
     * @var list<string>
     */
    public const PLACEHOLDER_CATEGORIES = ['none', 'null', 'n/a', '-'];
```

Then replace the guard in `classify()`:

```php
        $lower = strtolower(trim($raw));
        if ($lower === '' || in_array($lower, self::PLACEHOLDER_CATEGORIES, true)) {
            return null;
        }
```

- [ ] **Step 4: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

Expected: PASS, all cases. The 52-entry representative table must stay green.

- [ ] **Step 5: Write the failing seeder-payload test**

Create `tests/Feature/Platforms/InstagramSeederCategoryTest.php`:

```php
<?php

use App\Services\Platforms\InstagramConnectionSeeder;

/**
 * F4 (2026-08-10): a degraded actor run returned businessCategoryName "None",
 * which was stored and published — businessCategory is on
 * PublicIntegrationConnectionResource::ALLOWLIST['instagram'].
 */
it('normalises placeholder business categories to null', function (?string $raw) {
    $method = new ReflectionMethod(InstagramConnectionSeeder::class, 'categoryOrNull');

    expect($method->invoke(app(InstagramConnectionSeeder::class), $raw))->toBeNull();
})->with(['None', 'none', ' NONE ', 'null', 'N/A', '-', '', '   ', null]);

it('keeps a real business category verbatim', function () {
    $method = new ReflectionMethod(InstagramConnectionSeeder::class, 'categoryOrNull');
    $seeder = app(InstagramConnectionSeeder::class);

    expect($method->invoke($seeder, 'Hair Stylist'))->toBe('Hair Stylist')
        ->and($method->invoke($seeder, '  Tattoo & Piercing Shop  '))->toBe('Tattoo & Piercing Shop');
});
```

- [ ] **Step 6: Run it to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/InstagramSeederCategoryTest.php`

Expected: FAIL with `ReflectionException: Method ... does not exist`.

- [ ] **Step 7: Implement `categoryOrNull` and apply it**

In `app/Services/Platforms/InstagramConnectionSeeder.php`, replace `:161-162`:

```php
            'businessCategory' => $this->categoryOrNull(
                data_get($profile, 'businessCategoryName')
                    ?? data_get($profile, 'business_category_name')
            ),
```

Add the private method next to the file's other small helpers:

```php
    /**
     * businessCategory is on the public wire, so a degraded actor run's
     * stringified Python None must not reach it (F4, 2026-08-10). Same list
     * SectorTaxonomy::classify() refuses to classify.
     */
    private function categoryOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' || in_array(strtolower($trimmed), SectorTaxonomy::PLACEHOLDER_CATEGORIES, true)
            ? null
            : $trimmed;
    }
```

Add the import at the top of the file:

```php
use App\Services\Profile\SectorTaxonomy;
```

- [ ] **Step 8: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Feature/Platforms/InstagramSeederCategoryTest.php`

Expected: PASS.

- [ ] **Step 9: Add the identity-sync regression case**

Append to `tests/Feature/Platforms/InstagramIdentitySyncTest.php`:

```php
it('leaves sector untouched when the actor returns a placeholder category', function () {
    $user = User::factory()->create([
        'sector' => null,
        'sector_source' => null,
        'display_name' => '',
        'handle' => 'existing-handle',
        'handle_lc' => 'existing-handle',
    ]);

    app(InstagramIdentitySync::class)->applyIdentity($user, [
        'businessCategoryName' => 'None',
        'fullName' => 'Crucible Tattoo Co.',
    ]);

    $user->refresh();
    expect($user->sector)->toBeNull()
        ->and($user->sector_source)->toBeNull()
        ->and($user->display_name)->toBe('Crucible Tattoo Co.'); // other fields still fold
});
```

- [ ] **Step 10: Run the three affected files together**

Run:
```bash
./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php \
                  tests/Feature/Platforms/InstagramSeederCategoryTest.php \
                  tests/Feature/Platforms/InstagramIdentitySyncTest.php
```

Expected: PASS, all files.

- [ ] **Step 11: Commit**

```bash
./vendor/bin/pint app/Services/Profile/SectorTaxonomy.php app/Services/Platforms/InstagramConnectionSeeder.php
git add app/Services/Profile/SectorTaxonomy.php app/Services/Platforms/InstagramConnectionSeeder.php tests/Unit/Profile/SectorTaxonomyClassificationTest.php tests/Feature/Platforms/InstagramSeederCategoryTest.php tests/Feature/Platforms/InstagramIdentitySyncTest.php
git commit -m "fix(instagram): stop placeholder scrape categories reaching the public payload

A degraded figue actor run stringifies Python's None into
businessCategoryName. businessCategory is on the public wire
(PublicIntegrationConnectionResource ALLOWLIST), so 'None' rendered as
crucibletattooco's business category. Filter at the seeder write seam and
widen SectorTaxonomy's existing 'none' classification guard to the same list.

F4/F5, docs/reviews/2026-08-10-instagram-build-wave-RESULTS.md"
```

- [ ] **Step 12: Clean the one polluted dev row**

The fix is write-side only; `crucibletattooco`'s stored row still reads `"None"`.

**Preferred:** re-run the Instagram connect for that account through the normal path. It re-scrapes (which may also resolve F4's thin scrape) and rewrites the payload through the now-fixed seeder, firing `IntegrationConnectionObserver` correctly.

**Fallback only if a re-scrape is not possible:** a raw `UPDATE` on `site.platform_connections` bypasses every Eloquent observer, so the connection cache will not invalidate on its own. Before running it, check which `CacheKeyGenerator` keys `IntegrationConnectionObserver` purges for `platform = 'instagram'` and purge them explicitly afterwards. Do not run the `UPDATE` without doing that check first.

---

## Task 2: Close the artist / makeup-artist keyword hole (B)

**Why:** `SectorTaxonomy` carries `['slug' => 'artist', 'label' => 'Artist / Illustrator']` (`:113`) and `['slug' => 'makeup-artist', ...]` (`:56`), but `KEYWORD_SECTORS` has no keyword that can reach either. It maps `'art gallery'` and `'gallery'` to `artist` (`:155-156`) and nothing at all to `makeup-artist`. So the literal category `"Artist"` — what `jesshairstylist` returned — cannot resolve to the slug that exists for exactly it, and Google's `"Make-up artist"` place type resolves to nothing.

**Ordering hazard:** `'artist'` is generic. It MUST sit after every specific keyword that can co-occur with it. `'tattoo'` (`:144`), `'nail'` (`:142`) and `'hair'` (`:141`) already precede any position we could add it, so "Tattoo Artist" / "Nail Artist" / "Hair Artist" stay correct. `'makeup'` does not exist yet and must be added **before** `'artist'`, or "Makeup Artist" mis-resolves to `artist`. Placing `'artist'` last in the map is the maximally conservative position and is what this task does.

**Files:**
- Modify: `app/Services/Profile/SectorTaxonomy.php` — `KEYWORD_SECTORS`, two insertion points
- Modify: `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `KEYWORD_SECTORS` entries `'makeup'`, `'make-up'` → `makeup-artist`; `'artist'` → `artist`. Task 3's fallback pass relies on `'artist'` existing.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`:

```php
/**
 * The taxonomy carried an `artist` slug and a `makeup-artist` slug that no
 * keyword could reach — "Artist" is a first-class Instagram business category
 * and it classified to null (F5, 2026-08-10).
 */
it('classifies a bare "Artist" category as artist', function () {
    expect(SectorTaxonomy::fromInstagramCategory('Artist'))->toBe('artist')
        ->and(SectorTaxonomy::fromGoogleCategory('Artist'))->toBe('artist');
});

it('classifies makeup artists to makeup-artist, both spellings', function () {
    expect(SectorTaxonomy::fromGoogleCategory('Make-up artist'))->toBe('makeup-artist')
        ->and(SectorTaxonomy::fromInstagramCategory('Makeup Artist'))->toBe('makeup-artist');
});

/**
 * The generic 'artist' keyword must never shadow a more specific one. These
 * would all flip to 'artist' if it were inserted too early in KEYWORD_SECTORS.
 */
it('keeps specific artist categories ahead of the generic artist keyword', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    'tattoo' => ['Tattoo Artist', 'tattoo-artist'],
    'nail' => ['Nail Artist', 'nail-technician'],
    'hair' => ['Hair Artist', 'hair-salon'],
    'makeup' => ['Makeup Artist', 'makeup-artist'],
    'gallery' => ['Art gallery', 'artist'],
]);
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

Expected: FAIL — `'Artist'` returns `null`, `'Make-up artist'` returns `null`, `'Makeup Artist'` returns `null`.

- [ ] **Step 3: Add the keywords in the correct positions**

In `app/Services/Profile/SectorTaxonomy.php`, insert into `KEYWORD_SECTORS` immediately after `'nail' => 'nail-technician',` (`:142`):

```php
        // Before the generic 'artist' at the end of this map, or "Makeup Artist"
        // falls through to it.
        'makeup' => 'makeup-artist',
        'make-up' => 'makeup-artist',
```

Then append as the **last** entry of `KEYWORD_SECTORS`, after `'bar' => 'bar',` (`:191`):

```php
        // LAST deliberately: 'artist' is the most generic key in this map, so
        // every specific keyword above ('tattoo', 'nail', 'hair', 'makeup',
        // 'art gallery') wins the match first. Never move it up.
        'artist' => 'artist',
```

- [ ] **Step 4: Run them to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

Expected: PASS. The pre-existing 52-entry representative table must stay green — in particular `'art gallery'`, `'gallery'`, `'tattoo'` and `'nail'`.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint app/Services/Profile/SectorTaxonomy.php
git add app/Services/Profile/SectorTaxonomy.php tests/Unit/Profile/SectorTaxonomyClassificationTest.php
git commit -m "fix(sector): reach the artist and makeup-artist slugs from the keyword map

Both slugs existed in SECTORS with no KEYWORD_SECTORS entry able to reach
them, so a literal 'Artist' category classified to null. 'makeup'/'make-up'
go in ahead of the generic 'artist', which goes last so tattoo/nail/hair/
makeup keep winning the first-substring match.

F5, docs/reviews/2026-08-10-instagram-build-wave-RESULTS.md"
```

---

## Task 3: Instagram-native exact-match category pass (C)

**Why:** `KEYWORD_SECTORS` was built against Google Places `primaryTypeDisplayName` — "Hair salon", "Barber shop", "Italian restaurant" — and `fromInstagramCategory()` reuses it verbatim (`:294`). Instagram categories come from Facebook's Page taxonomy, a different vocabulary: "Hair Stylist", "Artist", "Digital Creator", "Skin Care Service", "Music Lessons & Instruction School". `SectorTaxonomyClassificationTest.php` pins 52 inputs, **every one Google-shaped**. Account 1's hit was incidental — "Hair Stylist" matched the four characters `hair`.

**Design:** Instagram categories are a **closed vocabulary**, so the new map is **exact-match on the whole normalised string**, not substring. That eliminates the collision class substring matching creates (a naive `'lash' => 'brows-lashes'` entry would capture "Flash Tattoo"). `fromInstagramCategory()` becomes: normalise → exact lookup → fall through to the existing substring classifier. `fromGoogleCategory()` is not touched, so Google classification cannot regress.

**Provenance — read this before implementing.** Only three real Instagram category strings are confirmed from live dev data: `"Hair Stylist"`, `"Artist"`, `"None"`. Every other key below is drawn from the Facebook/Instagram Page category vocabulary and is **unverified against a live scrape**. That is acceptable because the map is strictly additive — an unrecognised key simply never matches and the existing fallback runs unchanged, so a wrong key costs nothing. Do not "improve" this by guessing at vague categories; see the deliberately-unmapped list below.

**Files:**
- Modify: `app/Services/Profile/SectorTaxonomy.php` — add `INSTAGRAM_CATEGORY_SECTORS`; rewrite `fromInstagramCategory()` (`:288-295`)
- Modify: `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

**Interfaces:**
- Consumes: Task 1's widened `classify()` placeholder guard (reached via the fallback pass, not called directly); `KEYWORD_SECTORS`'s `'artist'` entry (Task 2) for the fallback pass.
- Produces: `private const INSTAGRAM_CATEGORY_SECTORS` — `array<string, string>`, keys **lowercased and pre-trimmed**, values curated sector slugs.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Profile/SectorTaxonomyClassificationTest.php`:

```php
/**
 * KEYWORD_SECTORS is tuned to Google Places primaryTypeDisplayName. Instagram
 * categories come from Facebook's Page taxonomy — a different, CLOSED
 * vocabulary — so fromInstagramCategory() checks an exact-match map first and
 * only then falls through to the shared substring classifier (F5, 2026-08-10).
 */
it('resolves Instagram-native categories the substring map gets wrong', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    // Corrections: the substring map returns a WRONG slug for these.
    'fitness trainer' => ['Fitness Trainer', 'personal-trainer'],          // 'fitness' => gym wins otherwise
    'fitness coach' => ['Fitness Coach', 'personal-trainer'],
    'music school' => ['Music Lessons & Instruction School', 'music-teacher'], // 'music' => musician wins otherwise
    'music teacher' => ['Music Teacher', 'music-teacher'],

    // Gaps: the substring map returns null for these.
    'digital creator' => ['Digital Creator', 'content-creator'],
    'content creator' => ['Content Creator', 'content-creator'],
    'blogger' => ['Blogger', 'content-creator'],
    'videographer' => ['Videographer', 'videographer'],
    'video creator' => ['Video Creator', 'videographer'],
    'graphic designer' => ['Graphic Designer', 'graphic-designer'],
    'writer' => ['Writer', 'writer'],
    'skin care' => ['Skin Care Service', 'esthetician'],
    'waxing' => ['Waxing Service', 'esthetician'],
    'eyelash' => ['Eyelash Service', 'brows-lashes'],
    'eyebrow' => ['Eyebrow Service', 'brows-lashes'],
    'massage service' => ['Massage Service', 'spa'],
    'massage therapist' => ['Massage Therapist', 'spa'],
    'pilates' => ['Pilates Studio', 'yoga-instructor'],
    'life coach' => ['Life Coach', 'life-coach'],
    'nutritionist' => ['Nutritionist', 'nutritionist'],
    'dietitian' => ['Dietitian', 'nutritionist'],
    'physical therapist' => ['Physical Therapist', 'physiotherapist'],
    'consulting agency' => ['Consulting Agency', 'consultant'],
    'marketing agency' => ['Marketing Agency', 'marketing-agency'],
    'advertising agency' => ['Advertising Agency', 'marketing-agency'],
    'automotive repair' => ['Automotive Repair Shop', 'mechanic'],
    'plumbing service' => ['Plumbing Service', 'plumber'],
    'contractor' => ['Contractor', 'builder'],
    'general contractor' => ['General Contractor', 'builder'],
    'bed and breakfast' => ['Bed and Breakfast', 'accommodation'],
    'vacation rental' => ['Vacation Home Rental', 'accommodation'],
    'financial planner' => ['Financial Planner', 'financial-advisor'],
    'insurance agent' => ['Insurance Agent', 'insurance-broker'],
]);

it('matches Instagram categories case-insensitively and ignores surrounding whitespace', function () {
    expect(SectorTaxonomy::fromInstagramCategory('  DIGITAL CREATOR  '))->toBe('content-creator')
        ->and(SectorTaxonomy::fromInstagramCategory('digital creator'))->toBe('content-creator');
});

it('falls through to the shared substring classifier for unlisted Instagram categories', function (string $input, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBe($expected);
})->with([
    'hair stylist' => ['Hair Stylist', 'hair-salon'],                 // the one confirmed live hit
    'barber shop' => ['Barber Shop', 'barber'],
    'tattoo shop' => ['Tattoo & Piercing Shop', 'tattoo-artist'],
    'nail salon' => ['Nail Salon', 'nail-technician'],
    'restaurant' => ['Restaurant', 'restaurant'],
]);

/**
 * Deliberately unmapped. These Instagram categories are too vague to pick a
 * sector from, and sector drives both sitepage styling and the FOOD_SECTORS
 * capability gates — a wrong guess is worse than null, which the user can fix
 * from the dashboard picker.
 */
it('leaves genuinely ambiguous Instagram categories null', function (string $input) {
    expect(SectorTaxonomy::fromInstagramCategory($input))->toBeNull();
})->with([
    'Health/Beauty',
    'Beauty, Cosmetic & Personal Care',
    'Public Figure',
    'Personal Blog',
    'Entrepreneur',
    'Product/Service',
    'Local Business',
]);

it('never resolves a non-food Instagram category into a FOOD_SECTORS slug', function () {
    $nonFood = ['Artist', 'Digital Creator', 'Hair Stylist', 'Contractor', 'Life Coach', 'Public Figure'];

    foreach ($nonFood as $category) {
        $slug = SectorTaxonomy::fromInstagramCategory($category);
        expect(SectorTaxonomy::isFood($slug))->toBeFalse("'{$category}' resolved to food slug '{$slug}'");
    }
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

Expected: FAIL. `'Fitness Trainer'` returns `gym`, `'Music Lessons & Instruction School'` returns `musician`, and every gap case returns `null`. The fall-through, ambiguous and food-safety tests should already PASS — they pin behaviour that must survive the change.

- [ ] **Step 3: Add the exact-match map**

In `app/Services/Profile/SectorTaxonomy.php`, add immediately **after** the `KEYWORD_SECTORS` const:

```php
    /**
     * Instagram business categories that the shared substring map gets WRONG or
     * misses entirely. Instagram's categories come from Facebook's Page
     * taxonomy — a CLOSED vocabulary, unlike Google's free-ish place-type
     * strings — so this is an EXACT match on the whole normalised category,
     * not a substring pass. Exact matching is what makes it collision-free: a
     * substring entry for lashes would capture "Flash Tattoo".
     *
     * Only entries the fallback gets wrong belong here. Anything the substring
     * map already resolves correctly ("Hair Stylist", "Barber Shop", "Tattoo &
     * Piercing Shop", "Restaurant") is deliberately absent.
     *
     * Keys MUST be lowercase and trimmed — fromInstagramCategory() normalises
     * the input before looking up, and does not normalise these.
     *
     * @var array<string, string>
     */
    private const INSTAGRAM_CATEGORY_SECTORS = [
        // Corrections — the substring map returns the wrong slug for these.
        'fitness trainer' => 'personal-trainer',
        'fitness coach' => 'personal-trainer',
        'music lessons & instruction school' => 'music-teacher',
        'music teacher' => 'music-teacher',

        // Creative
        'digital creator' => 'content-creator',
        'content creator' => 'content-creator',
        'blogger' => 'content-creator',
        'videographer' => 'videographer',
        'video creator' => 'videographer',
        'graphic designer' => 'graphic-designer',
        'writer' => 'writer',

        // Beauty & personal care
        'skin care service' => 'esthetician',
        'skincare service' => 'esthetician',
        'waxing service' => 'esthetician',
        'eyelash service' => 'brows-lashes',
        'eyebrow service' => 'brows-lashes',
        'massage service' => 'spa',
        'massage therapist' => 'spa',

        // Health & fitness
        'pilates studio' => 'yoga-instructor',
        'nutritionist' => 'nutritionist',
        'dietitian' => 'nutritionist',
        'physical therapist' => 'physiotherapist',

        // Education & coaching
        'life coach' => 'life-coach',
        'business coach' => 'life-coach',

        // Professional services
        'consulting agency' => 'consultant',
        'marketing agency' => 'marketing-agency',
        'advertising agency' => 'marketing-agency',
        'financial planner' => 'financial-advisor',
        'insurance agent' => 'insurance-broker',

        // Trades & automotive
        'automotive repair shop' => 'mechanic',
        'plumbing service' => 'plumber',
        'contractor' => 'builder',
        'general contractor' => 'builder',

        // Hospitality
        'bed and breakfast' => 'accommodation',
        'vacation home rental' => 'accommodation',

        // NOT mapped, deliberately: 'health/beauty', 'beauty, cosmetic &
        // personal care', 'public figure', 'personal blog', 'entrepreneur',
        // 'product/service', 'local business'. Too vague to pick a sector from,
        // and sector drives FOOD_SECTORS capability gating — null is safer than
        // a guess, and the user can pick from the dashboard.
    ];
```

- [ ] **Step 4: Rewrite `fromInstagramCategory()`**

Replace the method body at `:288-295`:

```php
    public static function fromInstagramCategory(?string $category): ?string
    {
        if (! is_string($category) || trim($category) === '') {
            return null;
        }

        // Exact pass first: Instagram's vocabulary is closed, so an exact hit is
        // authoritative and beats anything the substring map would infer. No
        // placeholder check needed here — no placeholder is a key below, so they
        // fall through to classify(), which already refuses them.
        return self::INSTAGRAM_CATEGORY_SECTORS[strtolower(trim($category))]
            ?? self::classify($category, self::KEYWORD_SECTORS);
    }
```

Update its docblock's first line to say the mapping is two-pass — exact Instagram vocabulary first, then the shared classifier.

- [ ] **Step 5: Run them to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

Expected: PASS, every test in the file including the pre-existing 52-entry Google table and Tasks 1–2's cases.

- [ ] **Step 6: Guard the map against typos in either direction**

Append one more test — every value must be a real slug, every key must be pre-normalised:

```php
it('maps every Instagram category to a real sector slug, from a normalised key', function () {
    $map = (new ReflectionClass(SectorTaxonomy::class))
        ->getConstant('INSTAGRAM_CATEGORY_SECTORS');

    foreach ($map as $category => $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("'{$category}' maps to unknown slug '{$slug}'")
            ->and($category)->toBe(strtolower(trim($category)));
    }
});
```

- [ ] **Step 7: Run it to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Profile/SectorTaxonomyClassificationTest.php`

Expected: PASS. If it fails, a value is a typo'd slug or a key has stray case/whitespace — fix the map, not the test.

- [ ] **Step 8: Run the full unit + platforms surface**

Run:
```bash
./vendor/bin/pest tests/Unit/Profile/ tests/Feature/Platforms/
```

Expected: PASS. `InstagramIdentitySyncTest` and `SectorTaxonomyTest` are the ones most likely to surface an unintended change.

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint app/Services/Profile/SectorTaxonomy.php
git add app/Services/Profile/SectorTaxonomy.php tests/Unit/Profile/SectorTaxonomyClassificationTest.php
git commit -m "feat(sector): Instagram-native exact-match category pass

KEYWORD_SECTORS is tuned to Google Places primaryTypeDisplayName and was
reused verbatim for Instagram, whose categories come from Facebook's Page
taxonomy. Account 1's hit was incidental — 'Hair Stylist' matched the
substring 'hair'.

Instagram's vocabulary is closed, so the new map is exact-match, not
substring: collision-free by construction. fromInstagramCategory() checks it
first and falls through to the shared classifier unchanged.
fromGoogleCategory() is untouched.

Genuinely ambiguous categories (Health/Beauty, Public Figure, Entrepreneur)
stay null deliberately — sector drives FOOD_SECTORS capability gating, so a
guess is worse than a dashboard pick.

F5, docs/reviews/2026-08-10-instagram-build-wave-RESULTS.md"
```

---

## Final verification

- [ ] **Run the full suite**

```bash
COMPOSER_PROCESS_TIMEOUT=0 composer test
```

Expected: PASS. `COMPOSER_PROCESS_TIMEOUT=0` is required or the run dies partway.

- [ ] **Run PHPStan**

```bash
./vendor/bin/phpstan analyse app/Services/Profile/SectorTaxonomy.php app/Services/Platforms/InstagramConnectionSeeder.php
```

Expected: no new errors. The `array<string, string>` and `list<string>` annotations on the two new consts must match their literals.

- [ ] **Re-check the three live accounts after deploying to dev**

Re-run the Instagram connect for `jesshairstylist` and `crucibletattooco`, then:

```sql
select u.handle, u.sector, u.sector_source,
       pc.payload->>'businessCategory' as business_category
from core.users u
join site.platform_connections pc on pc.user_id = u.id and pc.platform = 'instagram'
where u.handle in ('simondoylehair','jesshairstylist','crucibletattooco');
```

Expected after this plan:
- `simondoylehair` — unchanged: `hair-salon` / `instagram` / `"Hair Stylist"`
- `jesshairstylist` — `artist` / `instagram` / `"Artist"`. **This is the correct output of this plan and still the wrong sector for that business** — they are a hairdresser ("Prahran Hairdresser", `jess.hair.stylist`). Fixing that needs option D (name/username fallback), which is deliberately **not** in this plan because a false hit could resolve a non-food business to `bar` and flip its `can_use_menu` / `can_use_reservations` capabilities. Decide D separately.
- `crucibletattooco` — `businessCategory` must be `null`, not `"None"`. `sector` stays `null` until F4 (the degraded zero-post scrape) is fixed and a real category comes back.

---

## Out of scope — recorded so it is not lost

- **Option D — fall back to `fullName` / `username` when the category yields null.** Highest yield of the four (it is the only one that would resolve `jesshairstylist` correctly), and the signal is already in `$payload` at `InstagramIdentitySync::applySector()`. Excluded here because `bar` is in `FOOD_SECTORS`, so a false positive on a business name flips capability gates. Needs its own decision on a restricted non-food keyword subset.
- **F4 — the degraded `crucibletattooco` scrape** (0 posts, `postsCount: null`, `images: []` on a 30k-follower public account). Task 1 stops its symptom reaching the wire; it does not fix the scrape. Tracked in `docs/reviews/2026-08-10-instagram-build-wave-RESULTS.md`.
