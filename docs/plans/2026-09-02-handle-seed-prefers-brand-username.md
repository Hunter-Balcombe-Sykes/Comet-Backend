# Handle seed prefers a brand username — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An Instagram username that carries no part of the person's own name seeds the handle instead of the cleaned display name, so `themetapunter`/"Joe Osborne" keeps `themetapunter` while `ryanfitzsimonshair`/"Ryan Fitzsimons" still trims to `ryanfitzsimons`.

**Architecture:** One pure static predicate on `App\Services\Profile\NameShapeGate` answers "does this username carry the person's own name?", and `PreAccountBuildService::materializeIdentity()` picks the seed from its answer. `HandleAllocator` is untouched — when the username wins, the cleaned display name rides as the allocator's existing ladder fallback, so a taken brand handle falls to the person's name before anyone gets a digit.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4. No new dependencies, no migration, no wire change.

**Spec:** None — this plan is the record. The rule ("rule C") was selected by the owner on 2026-09-02 after both candidate rules were run over all 120 live Instagram builds on dev; the measured before/after tables are reproduced in [Evidence](#evidence) below.

## Global Constraints

- **Instagram only.** A Google Business build has no username to prefer; `handleSeed()` there returns the listing name. The new branch must be gated on `source_type === 'instagram'` and GB behaviour must be provably unchanged.
- **New builds only.** No backfill, no sweep, no migration. Existing unclaimed sites keep their handles; they pick the rule up only if someone rebuilds them.
- **Display names are NOT touched.** Only the handle/subdomain seed changes. `$sourceName` in `materializeIdentity()` keeps its current derivation, and `NameShapeGate::apply()` is not modified.
- **Never `iconv('UTF-8', 'ASCII//TRANSLIT', …)`.** It delegates to the C library and returns different output on macOS and on Cloud's glibc ("Böhmer" → `bo"hmer` vs `b?hmer`). This predicate COMPARES its output, so it must fold with `Illuminate\Support\Str::ascii()` — which is also what `Str::slug()` (and therefore `HandleAllocator::base()`) uses, so predicate and slug agree on every accent.
- **`HandleAllocator` is not modified.** Its signature `allocate(string $seed, ?string $untrimmedSeed = null)` already carries the two-step ladder this change needs.
- Tests run SQLite; nothing here touches the database schema. `composer test` is the gate. Do **not** use `--filter` (broken in this repo) — pass a file path.
- 4-space indent, LF. Comments explain WHY. No banners, no restatements.

---

## The rule

`handleCarriesName(username, name)` decides which seed wins. `true` → the cleaned display name seeds the handle (today's behaviour). `false` → the username seeds it.

1. If the username has no ASCII letters → **true**. There is no username to prefer, and falling through would seed the allocator with `'professional'`.
2. If the name is not *person-shaped* → **false**. Person-shaped means 2–3 whitespace tokens, each with ≥2 letters, none a `NameShapeGate` descriptor. This is the arm that fixes "Melbourne Cake decorator" and bare "Lucy".
3. If any name token of ≥3 letters appears as a substring of the username's letters → **true**. Three letters is the evidence floor: "jo" appears inside a large fraction of words by accident, "bentley" does not.
4. Otherwise → **false**.

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `app/Services/Profile/NameShapeGate.php` | Judges the shape of a derived name. Gains the username-vs-name question, which is the same family of letter/token judgement it already owns (`isDescriptor`, `nameFromHandle`, `isLetterSpaced`). | Modify — add `use Illuminate\Support\Str;`, one public static, two private statics |
| `app/Services/PreAccount/PreAccountBuildService.php` | Owns identity materialization and therefore the seed choice. | Modify — add `use App\Services\Profile\NameShapeGate;`, replace the `$seed` expression in `materializeIdentity()` (currently lines 269–272) |
| `tests/Unit/Profile/NameShapeGateTest.php` | Pins the predicate against real fleet strings. | Modify — append 5 tests |
| `tests/Feature/PreAccount/PreAccountBuildServiceTest.php` | Pins the seed choice end to end. | Modify — append 4 tests |

Two tasks. Task 1 is a pure function a reviewer can accept or reject on its semantics alone; Task 2 is the wiring, and is worthless without Task 1.

---

### Task 1: The predicate on NameShapeGate

**Files:**
- Modify: `app/Services/Profile/NameShapeGate.php`
- Test: `tests/Unit/Profile/NameShapeGateTest.php`

**Interfaces:**
- Consumes: `NameShapeGate::isDescriptor(string $token): bool` (already exists, line 55)
- Produces: `NameShapeGate::handleCarriesName(string $username, string $name): bool` — Task 2 calls exactly this name and signature.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Profile/NameShapeGateTest.php`. Every fixture is a real `(source_ref, display_name)` pair read from `core.pre_account_builds` on dev, 2026-09-02.

```php
// Handle seed (2026-09-02): which of the two strings a build has — the IG
// username and the IG Name field — should seed the handle. Fixtures are real
// dev builds; the expectations are the owner-approved outcomes.

it('keeps the name when the username carries part of it', function () {
    expect(NameShapeGate::handleCarriesName('ryanfitzsimonshair', 'Ryan Fitzsimons'))->toBeTrue()
        ->and(NameShapeGate::handleCarriesName('by.dannydixon', 'Danny Dixon'))->toBeTrue()
        ->and(NameShapeGate::handleCarriesName('jordan.dimitriadis', 'Jordan Dimitriadis'))->toBeTrue()
        // Persona-looking username, but "sam" is right there in it.
        ->and(NameShapeGate::handleCarriesName('sammy.pdf', 'Sam Akhurst'))->toBeTrue()
        // Leetspeak in the username does not hide the given name.
        ->and(NameShapeGate::handleCarriesName('rubytallu1ah', 'Ruby Warren'))->toBeTrue()
        // Only the SURNAME survives in the username; one token is enough.
        ->and(NameShapeGate::handleCarriesName('emdinonhair', 'Emma Dinon'))->toBeTrue()
        ->and(NameShapeGate::handleCarriesName('georgetheosteo', 'George Sotiri'))->toBeTrue()
        // "Jo" is two letters and skipped; "bentley" carries the decision.
        ->and(NameShapeGate::handleCarriesName('joannebentleymakeup', 'Jo Bentley'))->toBeTrue();
});

it('prefers the username when it carries no part of the name', function () {
    // The case that motivated the change.
    expect(NameShapeGate::handleCarriesName('themetapunter', 'Joe Osborne'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('certifiedbarberboy', 'Jesse Jensz'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('barberfaydos', 'Jaiden Acallar'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('_designdivine_', 'Christiana Masina'))->toBeFalse();
});

it('prefers the username when the name field is not a person name at all', function () {
    // These are the ~30 dev builds whose handle is today a slugged description.
    expect(NameShapeGate::handleCarriesName('hoasisbeauty', 'Heavenly Oasis Laser Skin Beauty'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('sweetcakesofmine', 'Melbourne Cake decorator'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('makeupbykatarina', 'MELBOURNE MAKE UP ARTIST'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('nailsbylaurissa', 'Sydney Nail Artist'))->toBeFalse()
        // One token is not a person's full name. `lucy` / `amber` are handles
        // the next Lucy and the next Amber cannot have.
        ->and(NameShapeGate::handleCarriesName('nailsbyluuce', 'Lucy'))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('get_scissored', 'Amber'))->toBeFalse();
});

it('folds accents deterministically, never through iconv', function () {
    // Str::ascii gives 'Ben Bohmer' on macOS AND on Cloud's glibc;
    // iconv('ASCII//TRANSLIT') does not agree with itself across the two.
    expect(NameShapeGate::handleCarriesName('benbohmermusic', 'Ben Böhmer'))->toBeTrue();
});

it('fails toward the name when there is no username to prefer', function () {
    // Returning false here would seed HandleAllocator with '' -> 'professional'.
    expect(NameShapeGate::handleCarriesName('', 'Joe Osborne'))->toBeTrue()
        ->and(NameShapeGate::handleCarriesName('___', 'Joe Osborne'))->toBeTrue()
        // A blank name is not a name; the username wins.
        ->and(NameShapeGate::handleCarriesName('somebrand', ''))->toBeFalse()
        ->and(NameShapeGate::handleCarriesName('somebrand', '   '))->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Profile/NameShapeGateTest.php --no-coverage`

Expected: FAIL — `Call to undefined method App\Services\Profile\NameShapeGate::handleCarriesName()`.

- [ ] **Step 3: Write the implementation**

In `app/Services/Profile/NameShapeGate.php`, add the import directly under the `namespace` line:

```php
use Illuminate\Support\Str;
```

Then add these three methods to the class, immediately after `isDescriptor()`:

```php
    /**
     * Whether an Instagram username carries the person's OWN name, and so is
     * decoration around it rather than a brand in its own right.
     *
     * The handle seed turns on this question (2026-09-02, owner-approved after
     * both candidate rules were run over the 120 live IG builds on dev).
     * `ryanfitzsimonshair`/"Ryan Fitzsimons" carries "ryan", so the cleaned
     * name still wins and trims the noise. `themetapunter`/"Joe Osborne"
     * carries neither token — that username is a chosen brand and keeps the
     * handle.
     *
     * FALSE for a name field that is not a person's name at all ("Melbourne
     * Cake decorator", bare "Lucy"): those are the ~30 dev builds whose handle
     * is today a slugged description or a first name the next Lucy cannot have.
     *
     * Fails toward the NAME when there is no username to prefer, so an empty
     * ref can never seed HandleAllocator with 'professional'.
     */
    public static function handleCarriesName(string $username, string $name): bool
    {
        $handle = self::letters($username);
        if ($handle === '') {
            return true;
        }

        if (! self::isPersonShaped($name)) {
            return false;
        }

        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $token) {
            $letters = self::letters($token);
            // 3 is the evidence floor: a two-letter token ("jo", "an") appears
            // inside an unrelated handle by accident far too often to be proof
            // that the person put their own name there.
            if (mb_strlen($letters) >= 3 && str_contains($handle, $letters)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The shape of a person's full name: two or three tokens, each at least two
     * letters, none a descriptor. "Lucy" (one token) and "Melbourne Cake
     * decorator" (every token a descriptor) are both rejected, which is the
     * point — neither is a name, and neither should seed a handle.
     */
    private static function isPersonShaped(string $name): bool
    {
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', trim($name)) ?: [],
            static fn (string $token): bool => $token !== '',
        ));

        if (count($tokens) < 2 || count($tokens) > 3) {
            return false;
        }

        foreach ($tokens as $token) {
            if (mb_strlen(self::letters($token)) < 2 || self::isDescriptor($token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lowercase ASCII letters only.
     *
     * Str::ascii, NEVER iconv('UTF-8', 'ASCII//TRANSLIT', …): iconv delegates to
     * the C library, so "Böhmer" folds to `bo"hmer` on macOS and `b?hmer` on
     * Cloud's glibc, and this value is COMPARED. Str::slug folds through the
     * same Str::ascii table, so this predicate and HandleAllocator::base()
     * cannot disagree about an accented name.
     */
    private static function letters(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z]/i', '', Str::ascii($value)));
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Profile/NameShapeGateTest.php --no-coverage`

Expected: PASS — 397 existing + 5 new. If any pre-existing test in this file turns red, STOP: `letters()` or the descriptor list has been changed in a way that affects `nameFromHandle`, which is not in scope.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Profile/NameShapeGate.php tests/Unit/Profile/NameShapeGateTest.php
git commit -m "Name gate: does this username carry the person's own name?"
```

---

### Task 2: The seed choice in PreAccountBuildService

**Files:**
- Modify: `app/Services/PreAccount/PreAccountBuildService.php` (the `$seed` assignment in `materializeIdentity()`, currently lines 269–272)
- Test: `tests/Feature/PreAccount/PreAccountBuildServiceTest.php`

**Interfaces:**
- Consumes: `NameShapeGate::handleCarriesName(string $username, string $name): bool` from Task 1; `HandleAllocator::allocate(string $seed, ?string $untrimmedSeed = null): array{handle: string, handle_lc: string}` (unchanged).
- Produces: nothing new — this is the last task.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PreAccount/PreAccountBuildServiceTest.php`. Verified 2026-09-02: the file already imports `PreAccountBuildService` (line 10), `SourcePrefetch` (11), `User` (8) and `Site` (4) — these tests need no new imports.

```php
// Handle seed (2026-09-02): a username that carries no part of the person's
// own name is a chosen brand and seeds the handle. Fixtures are real dev
// builds — see docs/plans/2026-09-02-handle-seed-prefers-brand-username.md.

it('seeds the handle from a brand username that carries no part of the name', function () {
    $svc = app(PreAccountBuildService::class);
    $build = $svc->requestBuild('partna', 'instagram', 'themetapunter', null, hash('sha256', 'tmp'))['build'];

    $svc->materializeIdentity($build, new SourcePrefetch(payload: [], displayName: 'Joe Osborne'));

    $user = $build->refresh()->user;
    expect($user->handle_lc)->toBe('themetapunter')
        // Handle and subdomain converge or nothing downstream resolves.
        ->and($user->site->subdomain)->toBe('themetapunter')
        // Only the address moved: the person is still called by their name.
        ->and($user->display_name)->toBe('Joe Osborne');
});

it('still trims a username that decorates the person own name', function () {
    $svc = app(PreAccountBuildService::class);
    $build = $svc->requestBuild('partna', 'instagram', 'ryanfitzsimonshair', null, hash('sha256', 'rfs'))['build'];

    $svc->materializeIdentity($build, new SourcePrefetch(payload: [], displayName: 'Ryan Fitzsimons'));

    expect($build->refresh()->user->handle_lc)->toBe('ryanfitzsimons');
});

it('falls back to the person name when the brand username is taken', function () {
    // The ladder HandleAllocator already has: untrimmedSeed before a digit.
    $other = User::factory()->create(['handle' => 'themetapunter', 'handle_lc' => 'themetapunter']);
    Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'themetapunter']);

    $svc = app(PreAccountBuildService::class);
    $build = $svc->requestBuild('partna', 'instagram', 'themetapunter', null, hash('sha256', 'tmp2'))['build'];

    $svc->materializeIdentity($build, new SourcePrefetch(payload: [], displayName: 'Joe Osborne'));

    // 'joeosborne', NOT 'themetapunter1'.
    expect($build->refresh()->user->handle_lc)->toBe('joeosborne');
});

it('leaves a google business build seeding from the listing name', function () {
    // A place_id is opaque — there is no username to prefer, and this branch
    // must never fire for GB.
    $svc = app(PreAccountBuildService::class);
    $build = $svc->requestBuild('business', 'google_business', 'ChIJfamishedwolf', 'The Famished Wolf', hash('sha256', 'fw'))['build'];

    $svc->materializeIdentity($build, new SourcePrefetch(payload: [], displayName: 'The Famished Wolf'));

    expect($build->refresh()->user->handle_lc)->toBe('thefamishedwolf');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/PreAccount/PreAccountBuildServiceTest.php --no-coverage`

Expected: the first test FAILS with `expect('joeosborne')->toBe('themetapunter')`. The second and fourth already PASS (they pin behaviour this change must preserve); the third FAILS with `themetapunter1`.

- [ ] **Step 3: Write the implementation**

In `app/Services/PreAccount/PreAccountBuildService.php`, add the import alongside the other `App\Services` imports (after line 12, `use App\Models\Core\User\User;`):

```php
use App\Services\Profile\NameShapeGate;
```

Then replace this, in `materializeIdentity()`:

```php
        $seed = $prefetch->displayName
            ?? $this->generators->for($build->source_type)->handleSeed($build->source_ref, $build->source_name);

        $user = $this->createProvisionalUserWithRetry($seed, $accountType, $sourceName, $prefetch->untrimmedName);
```

with this:

```php
        // Both handleSeed() implementations are pure returns (the IG one hands
        // back the normalized ref, the GB one the listing name), so evaluating
        // it eagerly instead of through ?? costs nothing and buys the username
        // as a value the rule below can read.
        $generatorSeed = $this->generators->for($build->source_type)->handleSeed($build->source_ref, $build->source_name);
        $seed = $prefetch->displayName ?? $generatorSeed;
        $untrimmedSeed = $prefetch->untrimmedName;

        // 2026-09-02, owner-approved: an Instagram username carrying no part of
        // the person's own name is a chosen brand, not noise around the name —
        // themetapunter/"Joe Osborne" keeps its handle where
        // ryanfitzsimonshair/"Ryan Fitzsimons" still trims. The cleaned name
        // becomes the ladder fallback, so a taken brand handle lands on
        // 'joeosborne' rather than 'themetapunter1'. Instagram only: a Google
        // listing has no username to prefer.
        if ($build->source_type === 'instagram'
            && $prefetch->displayName !== null
            && ! NameShapeGate::handleCarriesName($generatorSeed, $prefetch->displayName)) {
            $seed = $generatorSeed;
            $untrimmedSeed = $prefetch->displayName;
        }

        $user = $this->createProvisionalUserWithRetry($seed, $accountType, $sourceName, $untrimmedSeed);
```

Leave the `$sourceName` assignment above it exactly as it is — the display name written to `core.users` does not change.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PreAccount/PreAccountBuildServiceTest.php --no-coverage`

Expected: PASS, all four new tests plus every pre-existing test in the file.

- [ ] **Step 5: Run the handle/subdomain convergence suites**

The seed feeds both `core.users.handle` and `site.sites.subdomain`; these three files are what pin that they never drift apart.

```bash
./vendor/bin/pest tests/Feature/PreAccount/HandleSubdomainConvergenceTest.php --no-coverage
./vendor/bin/pest tests/Feature/PreAccount/HandleSubdomainPropertyTest.php --no-coverage
./vendor/bin/pest tests/Unit/Services/HandleAllocatorTest.php --no-coverage
```

Expected: PASS on all three, unchanged. A red here means the seed choice broke convergence — STOP and report rather than adjusting the assertion.

- [ ] **Step 6: Run Pint and the full suite**

```bash
./vendor/bin/pint --test app/Services/Profile/NameShapeGate.php app/Services/PreAccount/PreAccountBuildService.php
composer test
```

Expected: Pint clean on the two touched files (`pint --test` is the gate, not `pint`). `composer test` green — note the baseline is 3 pre-existing local reds unrelated to this change if the working tree carries other in-flight work; verify any red is pre-existing on `git stash` before attributing it here.

- [ ] **Step 7: Commit**

```bash
git add app/Services/PreAccount/PreAccountBuildService.php tests/Feature/PreAccount/PreAccountBuildServiceTest.php
git commit -m "Handle seed: a brand username outranks the name it does not carry"
```

---

## Evidence

Measured 2026-09-02 by running both candidate rules over all 120 `source_type = 'instagram'` rows in `core.pre_account_builds` joined to `core.users` on dev (`glncumufgaqcmqhzwrxm`). Rule C — the one this plan implements — changes **52 of 120**.

The three buckets it changes, with real rows:

| Bucket | IG username | IG name field | Handle today | After |
|---|---|---|---|---|
| Name field is a description | `madphysiotherapy` | Melbourne Athletic Development Physiotherapy | `melbourneathleticdevelopmentphysiotherapy` | `madphysiotherapy` |
| | `benwardscissorhands` | `ʙᴇɴ ”SUGAR” ᴋᴀɴᴇ \| ʜᴀɪʀ…` | `sugar` | `benwardscissorhands` |
| | `emilyhowlettphotography` | Melbourne Wedding & Family Photographer | `melbourneweddingfamilyphotographer` | `emilyhowlettphotography` |
| | `makeupbykatarina` | MELBOURNE MAKE UP ARTIST | `melbournemakeupartist` | `makeupbykatarina` |
| Name field is one word | `nailsbyluuce` | Lucy | `lucy` | `nailsbyluuce` |
| | `get_scissored` | Amber | `amber` | `get_scissored` → `getscissored` |
| | `jimmythehairdresser` | Jimmy | `jimmy` | `jimmythehairdresser` |
| Username is a separate brand | `themetapunter` | Joe Osborne | `joeosborne` | `themetapunter` |
| | `certifiedbarberboy` | Jesse Jensz | `jessejensz` | `certifiedbarberboy` |
| | `barberfaydos` | Jaiden Acallar | `jaidenacallar` | `barberfaydos` |

And the rows it deliberately leaves alone, which the rejected whole-name-containment rule would have broken:

| IG username | IG name | Today & after | Rejected rule would have given |
|---|---|---|---|
| `sammy.pdf` | Sam Akhurst | `samakhurst` | `sammypdf` |
| `rubytallu1ah` | Ruby Warren | `rubywarren` | `rubytallu1ah` |
| `emdinonhair` | Emma Dinon | `emmadinon` | `emdinonhair` |
| `themarshallarts` | Lauren Marshall | `laurenmarshall` | `themarshallarts` |

**Accepted rough edges** (all cases where today's value is also imperfect, none regressions worth blocking on):

- `sammylalorr` / "Sammylalor" → picks the username with its doubled `r`.
- `the.shelly.editt` / "The Shelly Edit" → picks the typo'd `theshellyeditt`, because "The" and "Edit" are both on the descriptor list so the name is not person-shaped.
- `djrubyofficial` / "DJ Ruby" → picks the username, because "DJ" is a descriptor. Defensible for a DJ.

## Out of scope — do not do these here

- **Backfilling existing sites.** No sweep, no `fleet:rebuild` run over the fleet. `FleetNewCommand` already routes through `PreAccountBuildService`, so a rebuild of any one site picks the rule up for free; that is the only migration path.
- **Title-casing an all-caps BRAND display name** ("MELBOURNE MAKE UP ARTIST" stays as it is). Yesterday's gate title-cases person-shaped all-caps names only, by design. Raised by the owner 2026-09-02 and explicitly deferred.
- **Changing `NameShapeGate::apply()`, `nameFromHandle()`, or the DESCRIPTORS list.** Adding a descriptor changes name derivation for every existing build; it is a separate, larger change.
- **Google Business behaviour.** Pinned unchanged by Task 2's fourth test.
