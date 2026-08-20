# Pipeline Assurance — Plan 1 of 3: Foundation (A) + Registry-Derived Matrices (B1–B4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the recorded-fixture corpus + capture tooling (spec A1/A2) and the five free, registry-derived matrix tests (spec B1–B4) so the bug classes the live build waves kept finding are caught offline, in seconds, and a new platform/sector/source without coverage turns CI red.

**Architecture:** A `tests/fixtures/recorded/` corpus with a JSON manifest, filled by an artisan `fixtures:capture` command (file / URL / dev-DB / live-scraper sources, PII redacted at capture, spend-gated) and read by a `Tests\Support\Fixtures\Recorded` loader + mutator. On top: Pest tests that enumerate `CompiledCatalog::surfaces()`, `config('partna.pre_account.sources')`, `LinkRouter`'s categories, `SectorTaxonomy` and `HandleAllocator`/`SiteProvisioningService` at runtime, pinning current behaviour with ratchet baselines where the answer is "known gap".

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, SQLite in-memory Feature suite (`tests/Pest.php` `setup*Table()` helpers), `Http` facade only for outbound.

**Spec:** `docs/superpowers/specs/2026-08-18-pipeline-assurance-design.md` — this plan implements §5 A1, A2, B1, B2, B3, B4. **B5 (every-surface connect contract) is deferred to Plan 2** with C1–C4, because it needs the per-platform fixtures this plan's capture command produces. C5, D1–D3 → Plans 2/3.

## Global Constraints

- **No app-behaviour changes.** Everything under `app/` added here is dev tooling (`app/Console/Commands/`, `app/Support/Fixtures/`). A red matrix test is a *finding*, recorded in the report — never weaken an assertion or add a skip to go green. Where a known gap must not block CI, pin it in a **baseline file** so the test still fails on *new* regressions and on *stale* baseline rows.
- **No Laravel migration files. No `supabase/migrations/` changes.** Nothing here touches schema.
- **Outbound HTTP:** the `Http` facade only, through `App\Services\Http\SafeUrlFetcher` for any URL a human types (`fixtures:capture --from=url`). Category B of `OutboundHttpGuardTest`.
- **Spend gate:** `fixtures:capture --from=live` for `instagram`, `places`, `menus` bills Apify/Places — refuse without `--confirm-spend`. `--from=file`, `--from=url`, `--from=db` cost nothing.
- **Fixture PII:** `places` JSON runs through `GoogleBusinessPayload::stripThirdPartyPii()` at capture; every body has emails/phones regex-redacted. Never commit reviewer names.
- **Tests:** Feature tests live in EXISTING dirs (`tests/Feature/Platforms`, `tests/Feature/PreAccount`, `tests/Feature/Console`) — a new subdir under `tests/Feature/` turns `AuditPipelineIntegrityTest` red unless wired into `scripts/audit/audit.sh codebase_chunks()`. Unit tests using the container need `uses(TestCase::class)->in(__FILE__)`. No cross-file global helper functions in test files (parallel-suite rule) — shared helpers go in `tests/Support/`. `--filter` is broken with Pint here — run tests **by path**: `php artisan test tests/Feature/Platforms/FooTest.php`.
- **Never** negated `toContain`, never chained `expect()` when proving non-vacuity — one assertion per lane.
- **Branch:** `feat/pipeline-assurance-ab-2026-08-18` off `origin/development`, in its own worktree (`superpowers:using-git-worktrees`). Commit after every task.

---

## File map

| Path | Responsibility |
|---|---|
| `tests/fixtures/recorded/MANIFEST.json` | one row per recorded file: path, source_url, captured_at, sha256, captured_by, notes |
| `tests/fixtures/recorded/{instagram,places,fresha,linkinbio,websites,shop,media,menus}/` | the corpus (shop = the 7 existing files, moved) |
| `tests/Support/Fixtures/Recorded.php` | test-side loader: `path/raw/json/html/mutate` |
| `tests/Support/Fixtures/FixtureMutator.php` | reality-shaped edge cases: `without/nullify/set/snakeCaseKeys/camelCaseKeys/get` |
| `app/Support/Fixtures/FixtureManifest.php` | read/write/verify the manifest (used by command + guard test) |
| `app/Support/Fixtures/FixtureRedactor.php` | PII redaction at capture |
| `app/Support/Fixtures/FixtureStore.php` | write a body under `recorded/<source>/<name>.<ext>` + manifest upsert |
| `app/Console/Commands/FixturesCaptureCommand.php` | `fixtures:capture {source} {name} --from=file\|url\|db\|live …` |
| `app/Console/Commands/FixturesVerifyCommand.php` | `fixtures:verify` — re-hash every file vs manifest, orphans, missing |
| `tests/Feature/Console/FixturesCaptureCommandTest.php` | command behaviour incl. spend gate + redaction |
| `tests/Feature/Architecture/RecordedFixtureManifestGuardTest.php` | orphan file / stale hash = red |
| `tests/Unit/Support/FixtureMutatorTest.php` | mutator behaviour |
| `tests/Support/Catalog/SweepProbeUrl.php` | probe URL for a surface: template substitution or hand map |
| `tests/fixtures/catalog/probe-urls.php` | hand-written probe URLs for surfaces with no `canonical_url_template` |
| `tests/fixtures/catalog/known-invisible.php` | ratchet baseline: surfaces `classify()` cannot see today |
| `tests/Feature/Platforms/CatalogClassificationSweepTest.php` | B1 |
| `tests/Feature/Platforms/LinkRouterGateMatrixTest.php` | B2 gate × account |
| `tests/Feature/PreAccount/SignupPairingMatrixTest.php` | B2 pairing table |
| `tests/Unit/Profile/SectorFoldTableTest.php` | B3 |
| `tests/Feature/PreAccount/HandleSubdomainPropertyTest.php` | B4 |
| `docs/reviews/2026-08-18-platform-coverage-sweep-RESULTS.md` | B1 report |

---

### Task 1: Recorded fixture directory, `Recorded` loader, and moving the shop fixtures

**Files:**
- Create: `tests/fixtures/recorded/MANIFEST.json`
- Create: `tests/Support/Fixtures/Recorded.php`
- Move: `tests/fixtures/shop/*` → `tests/fixtures/recorded/shop/*` (7 files, `git mv`)
- Modify: `tests/Unit/Platforms/WooCommerceScraperTest.php:97-101`, `tests/Unit/Platforms/GenericShopScraperTest.php:194-198`, `tests/Feature/Platforms/ShopUrlValidationTest.php:44-48` (path strings only)
- Test: `tests/Unit/Support/RecordedLoaderTest.php`

**Interfaces:**
- Produces: `Tests\Support\Fixtures\Recorded::path(string $rel): string`, `::raw(string $rel): string`, `::json(string $rel): array`, `::html(string $rel): string`, `::mutate(array $payload): FixtureMutator` (mutator added in Task 5 — leave the method out until then).

- [ ] **Step 1: Write the failing loader test**

```php
<?php
// tests/Unit/Support/RecordedLoaderTest.php

use Tests\Support\Fixtures\Recorded;

it('resolves a relative fixture path under tests/fixtures/recorded', function () {
    expect(Recorded::path('shop/bluelane-store-api.json'))
        ->toBe(dirname(__DIR__, 2).'/fixtures/recorded/shop/bluelane-store-api.json');
});

it('reads a recorded JSON fixture as an array', function () {
    $data = Recorded::json('shop/bluelane-store-api.json');
    expect($data)->toBeArray()->not->toBeEmpty();
});

it('reads a recorded HTML fixture as a string', function () {
    expect(Recorded::html('shop/bluelane-homepage-head.html'))->toContain('<');
});

it('throws a clear error for a missing fixture', function () {
    Recorded::raw('shop/does-not-exist.html');
})->throws(RuntimeException::class, 'Recorded fixture missing: shop/does-not-exist.html');
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test tests/Unit/Support/RecordedLoaderTest.php`
Expected: FAIL — `Class "Tests\Support\Fixtures\Recorded" not found`.

- [ ] **Step 3: Move the shop fixtures and create the manifest + loader**

```bash
mkdir -p tests/fixtures/recorded/shop
git mv tests/fixtures/shop/abovetheground-homepage.html tests/fixtures/recorded/shop/
git mv tests/fixtures/shop/bluelane-homepage-head.html tests/fixtures/recorded/shop/
git mv tests/fixtures/shop/bluelane-product-page.html tests/fixtures/recorded/shop/
git mv tests/fixtures/shop/bluelane-store-api.json tests/fixtures/recorded/shop/
git mv tests/fixtures/shop/fearnoevil-homepage-head.html tests/fixtures/recorded/shop/
git mv tests/fixtures/shop/fearnoevil-product-page.html tests/fixtures/recorded/shop/
git mv tests/fixtures/shop/fearnoevil-store-api.json tests/fixtures/recorded/shop/
```

`tests/fixtures/recorded/MANIFEST.json` — one entry per moved file (sha256 via `shasum -a 256 <file>`; `captured_at` unknown for the WS-B1 captures, use the WS-B1 commit date from `git log --diff-filter=A --format=%cs -- tests/fixtures/shop/<file>`):

```json
{
  "version": 1,
  "entries": {
    "shop/abovetheground-homepage.html": {
      "source_url": "https://abovetheground.com.au/",
      "captured_at": "<git add date>",
      "sha256": "<shasum>",
      "captured_by": "manual",
      "notes": "WS-B1 live-site capture; used by GenericShopScraperTest"
    },
    "shop/bluelane-homepage-head.html": { "source_url": "https://bluelane.co/", "captured_at": "<git add date>", "sha256": "<shasum>", "captured_by": "manual", "notes": "WS-B1" },
    "shop/bluelane-product-page.html":  { "source_url": "https://bluelane.co/product/…", "captured_at": "<git add date>", "sha256": "<shasum>", "captured_by": "manual", "notes": "WS-B1" },
    "shop/bluelane-store-api.json":     { "source_url": "https://bluelane.co/wp-json/wc/store/v1/products", "captured_at": "<git add date>", "sha256": "<shasum>", "captured_by": "manual", "notes": "WS-B1" },
    "shop/fearnoevil-homepage-head.html": { "source_url": "https://fearnoevil.com.au/", "captured_at": "<git add date>", "sha256": "<shasum>", "captured_by": "manual", "notes": "WS-B1" },
    "shop/fearnoevil-product-page.html":  { "source_url": "https://fearnoevil.com.au/products/…", "captured_at": "<git add date>", "sha256": "<shasum>", "captured_by": "manual", "notes": "WS-B1" },
    "shop/fearnoevil-store-api.json":     { "source_url": "https://fearnoevil.com.au/wp-json/wc/store/v1/products", "captured_at": "<git add date>", "sha256": "<shasum>", "captured_by": "manual", "notes": "WS-B1" }
  }
}
```
(Fill the `…` product paths from the fixture's own `<link rel="canonical">` / `og:url` — grep the file; if absent, use the homepage URL and say so in `notes`.)

```php
<?php
// tests/Support/Fixtures/Recorded.php

namespace Tests\Support\Fixtures;

use RuntimeException;

/**
 * Loader for the recorded-reality corpus under tests/fixtures/recorded/.
 * Fixtures are REAL upstream responses captured by `fixtures:capture` (or
 * imported by hand and registered in MANIFEST.json) — never hand-typed
 * payloads. See docs/superpowers/specs/2026-08-18-pipeline-assurance-design.md §5 A1.
 */
final class Recorded
{
    public static function root(): string
    {
        return dirname(__DIR__, 2).'/fixtures/recorded';
    }

    public static function path(string $rel): string
    {
        return self::root().'/'.ltrim($rel, '/');
    }

    public static function raw(string $rel): string
    {
        $path = self::path($rel);
        if (! is_file($path)) {
            throw new RuntimeException("Recorded fixture missing: {$rel}");
        }

        return (string) file_get_contents($path);
    }

    /** @return array<string, mixed> */
    public static function json(string $rel): array
    {
        $decoded = json_decode(self::raw($rel), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Recorded fixture is not a JSON object/array: {$rel}");
        }

        return $decoded;
    }

    public static function html(string $rel): string
    {
        return self::raw($rel);
    }
}
```

Update the three shop helper functions' path strings:
- `WooCommerceScraperTest.php:100`: `'/fixtures/shop/'` → `'/fixtures/recorded/shop/'`
- `GenericShopScraperTest.php:197`: same
- `ShopUrlValidationTest.php:47`: `base_path('tests/fixtures/shop/'.$name)` → `base_path('tests/fixtures/recorded/shop/'.$name)`

- [ ] **Step 4: Run loader test + the three shop test files**

Run: `php artisan test tests/Unit/Support/RecordedLoaderTest.php tests/Unit/Platforms/WooCommerceScraperTest.php tests/Unit/Platforms/GenericShopScraperTest.php tests/Feature/Platforms/ShopUrlValidationTest.php`
Expected: PASS, all four.

- [ ] **Step 5: Commit**

```bash
git add tests/fixtures/recorded tests/Support/Fixtures/Recorded.php tests/Unit/Support/RecordedLoaderTest.php tests/Unit/Platforms/WooCommerceScraperTest.php tests/Unit/Platforms/GenericShopScraperTest.php tests/Feature/Platforms/ShopUrlValidationTest.php
git rm -r --cached tests/fixtures/shop 2>/dev/null || true
git commit -m "test(fixtures): recorded-reality corpus root, Recorded loader, move shop captures under recorded/"
```

---

### Task 2: `FixtureManifest` + `FixtureRedactor` + `FixtureStore` (app-side, pure)

**Files:**
- Create: `app/Support/Fixtures/FixtureManifest.php`, `app/Support/Fixtures/FixtureRedactor.php`, `app/Support/Fixtures/FixtureStore.php`
- Test: `tests/Unit/Support/FixtureManifestTest.php`, `tests/Unit/Support/FixtureRedactorTest.php`

**Interfaces:**
- Produces:
  - `FixtureManifest::__construct(string $manifestPath)`, `load(): array{version:int, entries: array<string, array>}`, `entries(): array<string, array>`, `upsert(string $rel, array $entry): void`, `remove(string $rel): void`, `verify(string $root): array<string> problems` (each problem a human-readable line: `missing file: …`, `hash mismatch: …`, `orphan file: …`).
  - `FixtureRedactor::apply(string $source, string $body, string $ext): string` — static.
  - `FixtureStore::__construct(string $root, FixtureManifest $manifest)`, `put(string $source, string $name, string $ext, string $body, array $meta): string $rel` — writes redacted body, upserts manifest with `sha256` of what was written, returns `"<source>/<name>.<ext>"`.
  - `FixtureStore::SOURCES = ['instagram','places','fresha','linkinbio','websites','shop','media','menus']`.

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Support/FixtureManifestTest.php

use App\Support\Fixtures\FixtureManifest;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/fixman-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/websites', 0777, true);
    $this->manifest = new FixtureManifest($this->dir.'/MANIFEST.json');
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/websites/*') ?: []);
    @unlink($this->dir.'/MANIFEST.json');
    @rmdir($this->dir.'/websites');
    @rmdir($this->dir);
});

it('starts empty when the manifest file does not exist', function () {
    expect($this->manifest->entries())->toBe([]);
});

it('upserts an entry and persists it as sorted JSON', function () {
    $this->manifest->upsert('websites/b.html', ['sha256' => 'x', 'source_url' => 'https://b']);
    $this->manifest->upsert('websites/a.html', ['sha256' => 'y', 'source_url' => 'https://a']);

    $raw = json_decode((string) file_get_contents($this->dir.'/MANIFEST.json'), true);
    expect(array_keys($raw['entries']))->toBe(['websites/a.html', 'websites/b.html'])
        ->and($raw['version'])->toBe(1);
});

it('verify() reports missing files, hash mismatches and orphans', function () {
    file_put_contents($this->dir.'/websites/present.html', 'hello');
    file_put_contents($this->dir.'/websites/orphan.html', 'nobody registered me');
    $this->manifest->upsert('websites/present.html', ['sha256' => hash('sha256', 'DIFFERENT')]);
    $this->manifest->upsert('websites/gone.html', ['sha256' => hash('sha256', 'x')]);

    $problems = $this->manifest->verify($this->dir);

    expect($problems)->toContain('hash mismatch: websites/present.html')
        ->and($problems)->toContain('missing file: websites/gone.html')
        ->and($problems)->toContain('orphan file: websites/orphan.html');
});

it('verify() is empty when everything matches', function () {
    file_put_contents($this->dir.'/websites/ok.html', 'hello');
    $this->manifest->upsert('websites/ok.html', ['sha256' => hash('sha256', 'hello')]);

    expect($this->manifest->verify($this->dir))->toBe([]);
});
```

```php
<?php
// tests/Unit/Support/FixtureRedactorTest.php

use App\Support\Fixtures\FixtureRedactor;

it('strips reviewer PII from a places JSON body via GoogleBusinessPayload', function () {
    $body = json_encode([
        'name' => 'Beef\'s Barbers',
        'reviews' => [['author' => 'Jane Reviewer', 'text' => 'great']],
        'photos' => [['ref' => 'p1', 'authors' => [['displayName' => 'Bob']]]],
    ]);

    $out = json_decode(FixtureRedactor::apply('places', $body, 'json'), true);

    expect($out)->not->toHaveKey('reviews')
        ->and($out['photos'][0])->not->toHaveKey('authors')
        ->and($out['name'])->toBe('Beef\'s Barbers');
});

it('redacts email addresses and phone numbers in any body', function () {
    $html = '<a href="mailto:jane@example.com">jane@example.com</a> call +61 412 345 678 or (03) 9123 4567';

    $out = FixtureRedactor::apply('websites', $html, 'html');

    expect($out)->not->toContain('jane@example.com')
        ->and($out)->not->toContain('412 345 678')
        ->and($out)->not->toContain('9123 4567')
        ->and($out)->toContain('[redacted-email]')
        ->and($out)->toContain('[redacted-phone]');
});

it('leaves binary media bodies untouched', function () {
    $bytes = random_bytes(64);
    expect(FixtureRedactor::apply('media', $bytes, 'jpg'))->toBe($bytes);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Unit/Support/FixtureManifestTest.php tests/Unit/Support/FixtureRedactorTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

```php
<?php
// app/Support/Fixtures/FixtureManifest.php

namespace App\Support\Fixtures;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * MANIFEST.json for tests/fixtures/recorded/: one row per recorded file so a
 * capture is traceable (where from, when, what hash) and an unregistered file
 * — or a hand-edited one — turns the guard test red.
 */
final class FixtureManifest
{
    public const VERSION = 1;

    /** @var array{version:int, entries: array<string, array<string, mixed>>}|null */
    private ?array $data = null;

    public function __construct(private readonly string $manifestPath) {}

    /** @return array{version:int, entries: array<string, array<string, mixed>>} */
    public function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        if (! is_file($this->manifestPath)) {
            return $this->data = ['version' => self::VERSION, 'entries' => []];
        }
        $decoded = json_decode((string) file_get_contents($this->manifestPath), true);

        return $this->data = [
            'version' => (int) ($decoded['version'] ?? self::VERSION),
            'entries' => is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function entries(): array
    {
        return $this->load()['entries'];
    }

    /** @param array<string, mixed> $entry */
    public function upsert(string $rel, array $entry): void
    {
        $data = $this->load();
        $data['entries'][$rel] = $entry + ($data['entries'][$rel] ?? []);
        ksort($data['entries']);
        $this->data = $data;
        $this->flush();
    }

    public function remove(string $rel): void
    {
        $data = $this->load();
        unset($data['entries'][$rel]);
        $this->data = $data;
        $this->flush();
    }

    /**
     * Compare manifest to disk. Every problem is one actionable line.
     *
     * @return list<string>
     */
    public function verify(string $root): array
    {
        $root = rtrim($root, '/');
        $problems = [];
        $entries = $this->entries();

        foreach ($entries as $rel => $entry) {
            $abs = $root.'/'.$rel;
            if (! is_file($abs)) {
                $problems[] = "missing file: {$rel}";

                continue;
            }
            if (($entry['sha256'] ?? null) !== hash_file('sha256', $abs)) {
                $problems[] = "hash mismatch: {$rel}";
            }
        }

        if (is_dir($root)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getFilename() === 'MANIFEST.json' || $file->getFilename() === '.gitkeep') {
                    continue;
                }
                $rel = ltrim(str_replace($root, '', str_replace('\\', '/', $file->getPathname())), '/');
                if (! isset($entries[$rel])) {
                    $problems[] = "orphan file: {$rel}";
                }
            }
        }

        sort($problems);

        return $problems;
    }

    private function flush(): void
    {
        file_put_contents(
            $this->manifestPath,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );
    }
}
```

```php
<?php
// app/Support/Fixtures/FixtureRedactor.php

namespace App\Support\Fixtures;

use App\Services\Platforms\Payloads\GoogleBusinessPayload;

/**
 * PII redaction applied to every body BEFORE it lands in tests/fixtures/recorded/.
 * Places JSON drops reviewer attribution (PRIV-1, same strip as the unclaimed
 * write path); every text body has emails and phone numbers masked. Binary
 * media is passed through untouched.
 */
final class FixtureRedactor
{
    private const BINARY_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'ico', 'pdf', 'bin'];

    private const EMAIL = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    // International or AU-local phone shapes with ≥ 8 digits; deliberately
    // loose — a fixture with a false positive is still a valid fixture.
    private const PHONE = '/(?:\+?\d{1,3}[\s.-]?)?(?:\(?\d{2,4}\)?[\s.-]?)\d{3,4}[\s.-]?\d{3,4}/';

    public static function apply(string $source, string $body, string $ext): string
    {
        if (in_array(strtolower($ext), self::BINARY_EXTS, true)) {
            return $body;
        }

        if ($source === 'places' && strtolower($ext) === 'json') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $body = (string) json_encode(
                    GoogleBusinessPayload::stripThirdPartyPii($decoded),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            }
        }

        $body = (string) preg_replace(self::EMAIL, '[redacted-email]', $body);
        $body = (string) preg_replace(self::PHONE, '[redacted-phone]', $body);

        return $body;
    }
}
```

```php
<?php
// app/Support/Fixtures/FixtureStore.php

namespace App\Support\Fixtures;

use InvalidArgumentException;

/** Writes one recorded body under <root>/<source>/<name>.<ext> and registers it. */
final class FixtureStore
{
    public const SOURCES = ['instagram', 'places', 'fresha', 'linkinbio', 'websites', 'shop', 'media', 'menus'];

    public function __construct(
        private readonly string $root,
        private readonly FixtureManifest $manifest,
    ) {}

    /**
     * @param  array<string, mixed>  $meta  source_url, captured_by, notes — sha256/captured_at are filled here
     * @return string the manifest-relative path written
     */
    public function put(string $source, string $name, string $ext, string $body, array $meta): string
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown fixture source '{$source}'. Known: ".implode(', ', self::SOURCES));
        }
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name)) {
            throw new InvalidArgumentException("Fixture name '{$name}' must be lowercase [a-z0-9._-]");
        }

        $redacted = FixtureRedactor::apply($source, $body, $ext);
        $rel = "{$source}/{$name}.{$ext}";
        $abs = rtrim($this->root, '/').'/'.$rel;

        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0777, true);
        }
        file_put_contents($abs, $redacted);

        $this->manifest->upsert($rel, [
            'source_url' => (string) ($meta['source_url'] ?? ''),
            'captured_at' => now()->toIso8601String(),
            'sha256' => hash('sha256', $redacted),
            'captured_by' => (string) ($meta['captured_by'] ?? 'manual'),
            'notes' => (string) ($meta['notes'] ?? ''),
        ]);

        return $rel;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/Support/FixtureManifestTest.php tests/Unit/Support/FixtureRedactorTest.php`
Expected: PASS. (`FixtureRedactorTest` uses no container — plain PHPUnit is fine; `now()` in `FixtureStore` is only exercised in Task 3's Feature test.)

- [ ] **Step 5: Commit**

```bash
git add app/Support/Fixtures tests/Unit/Support/FixtureManifestTest.php tests/Unit/Support/FixtureRedactorTest.php
git commit -m "feat(fixtures): manifest, redactor and store for the recorded corpus"
```

---

### Task 3: `fixtures:capture --from=file|url` and `fixtures:verify` + manifest guard test

**Files:**
- Create: `app/Console/Commands/FixturesCaptureCommand.php`, `app/Console/Commands/FixturesVerifyCommand.php`
- Create: `tests/Feature/Console/FixturesCaptureCommandTest.php`, `tests/Feature/Architecture/RecordedFixtureManifestGuardTest.php`

**Interfaces:**
- Consumes: `FixtureStore`, `FixtureManifest`, `FixtureRedactor` (Task 2); `App\Services\Http\SafeUrlFetcher::fetch(string $url, array $headers = []): array{status:int, body:string, finalUrl:string, contentType:string, …}`.
- Produces: `php artisan fixtures:capture {source} {name} {--from=file} {--file=} {--url=} {--ref=} {--ext=} {--notes=} {--root=} {--confirm-spend}` (db/live arms arrive in Task 4 and must fail with "not implemented" here), `php artisan fixtures:verify {--root=}` exit 0 / 1.
- Both commands honour `--root` (default `base_path('tests/fixtures/recorded')`) so tests never touch the real corpus.

- [ ] **Step 1: Write failing command tests**

```php
<?php
// tests/Feature/Console/FixturesCaptureCommandTest.php

use App\Services\Http\SafeUrlFetcher;
use App\Support\Fixtures\FixtureManifest;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/fixcap-'.bin2hex(random_bytes(4));
    mkdir($this->root, 0777, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->root));
});

it('imports a local file, redacts it, and registers it in the manifest', function () {
    $src = $this->root.'/in.html';
    file_put_contents($src, '<p>mail me jane@example.com</p>');

    $this->artisan('fixtures:capture', [
        'source' => 'websites', 'name' => 'acme.home',
        '--from' => 'file', '--file' => $src, '--root' => $this->root, '--notes' => 'unit',
    ])->assertExitCode(0);

    $written = (string) file_get_contents($this->root.'/websites/acme.home.html');
    expect($written)->toContain('[redacted-email]');

    $entry = (new FixtureManifest($this->root.'/MANIFEST.json'))->entries()['websites/acme.home.html'];
    expect($entry['sha256'])->toBe(hash('sha256', $written))
        ->and($entry['captured_by'])->toBe('fixtures:capture')
        ->and($entry['notes'])->toBe('unit');
});

it('fetches a URL through SafeUrlFetcher and infers the extension from content type', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->with('https://linktr.ee/acme', Mockery::type('array'))
        ->andReturn(['status' => 200, 'body' => '<html>links</html>', 'finalUrl' => 'https://linktr.ee/acme', 'contentType' => 'text/html; charset=utf-8', 'etag' => null, 'lastModified' => null]);
    app()->instance(SafeUrlFetcher::class, $fetcher);

    $this->artisan('fixtures:capture', [
        'source' => 'linkinbio', 'name' => 'linktree.acme',
        '--from' => 'url', '--url' => 'https://linktr.ee/acme', '--root' => $this->root,
    ])->assertExitCode(0);

    expect(is_file($this->root.'/linkinbio/linktree.acme.html'))->toBeTrue();
    $entry = (new FixtureManifest($this->root.'/MANIFEST.json'))->entries()['linkinbio/linktree.acme.html'];
    expect($entry['source_url'])->toBe('https://linktr.ee/acme');
});

it('refuses an unknown source with a non-zero exit', function () {
    $this->artisan('fixtures:capture', ['source' => 'nope', 'name' => 'x', '--from' => 'file', '--file' => '/dev/null', '--root' => $this->root])
        ->assertExitCode(1);
});

it('refuses --from=url on a non-2xx response and writes nothing', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn(['status' => 404, 'body' => '', 'finalUrl' => 'x', 'contentType' => 'text/html', 'etag' => null, 'lastModified' => null]);
    app()->instance(SafeUrlFetcher::class, $fetcher);

    $this->artisan('fixtures:capture', ['source' => 'websites', 'name' => 'gone', '--from' => 'url', '--url' => 'https://example.com/gone', '--root' => $this->root])
        ->assertExitCode(1);

    expect(glob($this->root.'/websites/*') ?: [])->toBe([]);
});

it('fixtures:verify exits 1 and names each problem when the corpus and manifest disagree', function () {
    mkdir($this->root.'/websites');
    file_put_contents($this->root.'/websites/orphan.html', 'x');

    $this->artisan('fixtures:verify', ['--root' => $this->root])
        ->expectsOutputToContain('orphan file: websites/orphan.html')
        ->assertExitCode(1);
});

it('fixtures:verify exits 0 on a consistent corpus', function () {
    $src = $this->root.'/in.html';
    file_put_contents($src, '<p>hi</p>');
    $this->artisan('fixtures:capture', ['source' => 'websites', 'name' => 'ok', '--from' => 'file', '--file' => $src, '--root' => $this->root])->assertExitCode(0);
    unlink($src);

    $this->artisan('fixtures:verify', ['--root' => $this->root])->assertExitCode(0);
});
```

```php
<?php
// tests/Feature/Architecture/RecordedFixtureManifestGuardTest.php

use App\Support\Fixtures\FixtureManifest;

// A recorded fixture nobody registered — or one somebody hand-edited — must not
// pass silently: the manifest is what makes a capture traceable (source URL,
// date, hash). Orphans and hash mismatches are both red here.
it('every file under tests/fixtures/recorded is registered in MANIFEST.json with a matching hash', function () {
    $root = base_path('tests/fixtures/recorded');
    $problems = (new FixtureManifest($root.'/MANIFEST.json'))->verify($root);

    expect($problems)->toBeEmpty(
        "tests/fixtures/recorded/ and MANIFEST.json disagree — run `php artisan fixtures:verify` and either\n"
        ."re-capture with `fixtures:capture` or register the file by hand:\n - ".implode("\n - ", $problems),
    );
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/Console/FixturesCaptureCommandTest.php tests/Feature/Architecture/RecordedFixtureManifestGuardTest.php`
Expected: capture tests FAIL (`Command "fixtures:capture" is not defined`); the guard test PASSES already if Task 1's manifest hashes were correct (if it fails, fix the manifest hashes — that IS the guard doing its job).

- [ ] **Step 3: Implement the two commands**

```php
<?php
// app/Console/Commands/FixturesCaptureCommand.php

namespace App\Console\Commands;

use App\Services\Http\SafeUrlFetcher;
use App\Support\Fixtures\FixtureManifest;
use App\Support\Fixtures\FixtureStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * Capture ONE real upstream response into tests/fixtures/recorded/ and register
 * it in MANIFEST.json (spec 2026-08-18-pipeline-assurance §5 A1).
 *
 *   --from=file  import a body you already have (a tinker dump, a curl save)
 *   --from=url   one GET through SafeUrlFetcher — HTML pages: websites, linkinbio, fresha, shop
 *   --from=db    (Task 4) copy a stored payload / ingest doc from the connected DB
 *   --from=live  (Task 4) run the real scraper and record every HTTP body it received
 *
 * PII is redacted at write (FixtureRedactor). Paid sources refuse --from=live
 * without --confirm-spend.
 */
class FixturesCaptureCommand extends Command
{
    protected $signature = 'fixtures:capture
                            {source : one of '.'instagram|places|fresha|linkinbio|websites|shop|media|menus'.'}
                            {name : lowercase [a-z0-9._-], e.g. linktree.mixed}
                            {--from=file : file|url|db|live}
                            {--file= : path to import (--from=file)}
                            {--url= : URL to fetch (--from=url)}
                            {--ref= : connection id / user id / stream key (--from=db) or scraper ref (--from=live)}
                            {--ext= : override the file extension (default inferred)}
                            {--notes= : free text stored in the manifest}
                            {--root= : corpus root (default tests/fixtures/recorded) — tests point this at a temp dir}
                            {--confirm-spend : required for --from=live on a billed source}';

    protected $description = 'Record one real upstream response into tests/fixtures/recorded/ with PII redacted and a manifest row.';

    public function handle(SafeUrlFetcher $fetcher): int
    {
        $source = (string) $this->argument('source');
        $name = (string) $this->argument('name');
        $root = (string) ($this->option('root') ?: base_path('tests/fixtures/recorded'));

        if (! in_array($source, FixtureStore::SOURCES, true)) {
            $this->error("Unknown source '{$source}'. Known: ".implode(', ', FixtureStore::SOURCES));

            return self::FAILURE;
        }

        $store = new FixtureStore($root, new FixtureManifest($root.'/MANIFEST.json'));

        try {
            [$body, $ext, $meta] = match ((string) $this->option('from')) {
                'file' => $this->fromFile(),
                'url' => $this->fromUrl($fetcher),
                'db' => $this->fromDb($source),
                'live' => $this->fromLive($source),
                default => throw new \InvalidArgumentException('--from must be file|url|db|live'),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ext = (string) ($this->option('ext') ?: $ext);
        $meta['captured_by'] = 'fixtures:capture';
        $meta['notes'] = (string) ($this->option('notes') ?? '');

        $rel = $store->put($source, $name, $ext, $body, $meta);
        $this->info("Recorded {$rel} (".strlen($body).' bytes, redacted).');

        return self::SUCCESS;
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromFile(): array
    {
        $path = (string) $this->option('file');
        if ($path === '' || ! is_file($path)) {
            throw new \InvalidArgumentException("--file must point at an existing file (got '{$path}')");
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'txt';

        return [(string) file_get_contents($path), $ext, ['source_url' => 'file://'.realpath($path)]];
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromUrl(SafeUrlFetcher $fetcher): array
    {
        $url = (string) $this->option('url');
        if ($url === '') {
            throw new \InvalidArgumentException('--url is required for --from=url');
        }
        // Category B: a URL a human typed goes through the guarded fetcher.
        $res = $fetcher->fetch($url);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new \RuntimeException("GET {$url} returned {$res['status']}; nothing recorded.");
        }

        return [$res['body'], self::extFromContentType($res['contentType']), ['source_url' => $url, 'final_url' => $res['finalUrl']]];
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromDb(string $source): array
    {
        throw new \RuntimeException('--from=db is not implemented yet (Task 4).');
    }

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromLive(string $source): array
    {
        throw new \RuntimeException('--from=live is not implemented yet (Task 4).');
    }

    public static function extFromContentType(string $contentType): string
    {
        $ct = strtolower(trim(explode(';', $contentType)[0]));

        return match (true) {
            str_contains($ct, 'json') => 'json',
            str_contains($ct, 'html') => 'html',
            str_contains($ct, 'pdf') => 'pdf',
            str_contains($ct, 'jpeg') => 'jpg',
            str_contains($ct, 'png') => 'png',
            str_contains($ct, 'webp') => 'webp',
            str_contains($ct, 'xml') => 'xml',
            default => 'txt',
        };
    }
}
```

```php
<?php
// app/Console/Commands/FixturesVerifyCommand.php

namespace App\Console\Commands;

use App\Support\Fixtures\FixtureManifest;
use Illuminate\Console\Command;

/** Re-hash every recorded fixture against MANIFEST.json; list orphans and gaps. Exit 1 on any problem. */
class FixturesVerifyCommand extends Command
{
    protected $signature = 'fixtures:verify {--root= : corpus root (default tests/fixtures/recorded)}';

    protected $description = 'Verify tests/fixtures/recorded/ against MANIFEST.json (hashes, orphans, missing files).';

    public function handle(): int
    {
        $root = (string) ($this->option('root') ?: base_path('tests/fixtures/recorded'));
        $problems = (new FixtureManifest($root.'/MANIFEST.json'))->verify($root);

        if ($problems === []) {
            $this->info('Recorded corpus matches MANIFEST.json.');

            return self::SUCCESS;
        }

        foreach ($problems as $p) {
            $this->line($p);
        }
        $this->error(count($problems).' problem(s).');

        return self::FAILURE;
    }
}
```

Both files sit directly in `app/Console/Commands/` → auto-discovered; no registration.

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Feature/Console/FixturesCaptureCommandTest.php tests/Feature/Architecture/RecordedFixtureManifestGuardTest.php`
Expected: PASS (6 + 1).

Also run the outbound-HTTP guard, since a new `app/` file calls a fetcher: `php artisan test tests/Feature/Architecture/OutboundHttpGuardTest.php` → PASS (the command injects `SafeUrlFetcher`, category B).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/FixturesCaptureCommand.php app/Console/Commands/FixturesVerifyCommand.php tests/Feature/Console/FixturesCaptureCommandTest.php tests/Feature/Architecture/RecordedFixtureManifestGuardTest.php
git commit -m "feat(fixtures): fixtures:capture (file|url) + fixtures:verify + manifest guard test"
```

---

### Task 4: `fixtures:capture --from=db` and `--from=live` (recorded HTTP bodies, spend gate)

**Files:**
- Modify: `app/Console/Commands/FixturesCaptureCommand.php` (replace `fromDb`/`fromLive` stubs)
- Modify: `tests/Feature/Console/FixturesCaptureCommandTest.php` (append tests)

**Interfaces:**
- Consumes: `App\Models\Core\Site\IntegrationConnection` (`payload` cast array, `surface_key`, `user_id`); `DB::table('ingest.record_versions')` columns `stream_id, key, doc, first_seen_at`; scrapers `App\Services\Platforms\InstagramScraper::fetchProfileResult(string $username, ?string $userId = null)`, `App\Services\Platforms\GoogleBusinessService::fetchPlaceDetailsRaw(string $placeId, string $userId)`; `Http::globalResponseMiddleware(callable)`.
- Produces: `--from=db --ref=<connection uuid>` writes the stored payload JSON; `--from=db --ref=<stream_id>:<key>` writes `ingest.record_versions.doc` for the newest version; `--from=live --ref=<username|place_id|url>` runs the source's scraper and records **every HTTP response body it received** as `<name>.<n>.<ext>` (n from 1), so C1 tests replay the wire, not the normalised output.

**Billed sources** (`--confirm-spend` required): `instagram`, `places`, `menus`. Free: everything else.

- [ ] **Step 1: Append failing tests**

```php
// append to tests/Feature/Console/FixturesCaptureCommandTest.php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ProfileFetchResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('--from=db copies a platform_connections payload by connection id', function () {
    setupUsersTable();
    setupSitesTable();
    $user = User::factory()->create();
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'surface_key' => 'instagram.profile', 'resource_id' => 'instagram',
        'payload' => ['fullName' => 'Jane', 'biography' => 'call 0412 345 678'],
    ]);

    $this->artisan('fixtures:capture', ['source' => 'instagram', 'name' => 'stored.jane', '--from' => 'db', '--ref' => (string) $conn->id, '--root' => $this->root])
        ->assertExitCode(0);

    $json = json_decode((string) file_get_contents($this->root.'/instagram/stored.jane.json'), true);
    expect($json['fullName'])->toBe('Jane')
        ->and($json['biography'])->toBe('call [redacted-phone]');
});

it('--from=db copies the newest ingest.record_versions doc for stream:key', function () {
    setupIngestTables();
    DB::connection('pgsql')->table('ingest.record_versions')->insert([
        ['stream_id' => 'st1', 'key' => 'k1', 'doc_hash' => 'h1', 'doc' => json_encode(['v' => 1]), 'first_seen_run' => 'r1', 'first_seen_at' => '2026-08-01 00:00:00', 'is_current' => false],
        ['stream_id' => 'st1', 'key' => 'k1', 'doc_hash' => 'h2', 'doc' => json_encode(['v' => 2]), 'first_seen_run' => 'r2', 'first_seen_at' => '2026-08-02 00:00:00', 'is_current' => true],
    ]);

    $this->artisan('fixtures:capture', ['source' => 'places', 'name' => 'ingest.k1', '--from' => 'db', '--ref' => 'st1:k1', '--root' => $this->root])
        ->assertExitCode(0);

    expect(json_decode((string) file_get_contents($this->root.'/places/ingest.k1.json'), true))->toBe(['v' => 2]);
});

it('--from=live refuses a billed source without --confirm-spend and makes no request', function () {
    Http::fake();

    $this->artisan('fixtures:capture', ['source' => 'instagram', 'name' => 'live.acme', '--from' => 'live', '--ref' => 'acme', '--root' => $this->root])
        ->expectsOutputToContain('--confirm-spend')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('--from=live records every HTTP body the scraper received, numbered', function () {
    Http::fake([
        'api.apify.com/*' => Http::sequence()
            ->push(['data' => ['id' => 'run1', 'defaultDatasetId' => 'ds1']], 201)
            ->push([['username' => 'acme', 'fullName' => 'Acme Co', 'biography' => 'hi']], 200),
    ]);
    // Drive the real scraper class so the middleware sees the real requests; the
    // token is empty under phpunit.xml (APIFY_TOKEN=""), so set one for the run.
    config(['services.apify.token' => 'test-token']);

    $this->artisan('fixtures:capture', ['source' => 'instagram', 'name' => 'live.acme', '--from' => 'live', '--ref' => 'acme', '--root' => $this->root, '--confirm-spend' => true])
        ->assertExitCode(0);

    $files = array_map('basename', glob($this->root.'/instagram/live.acme.*.json') ?: []);
    sort($files);
    expect($files)->toBe(['live.acme.1.json', 'live.acme.2.json']);
    expect(json_decode((string) file_get_contents($this->root.'/instagram/live.acme.2.json'), true)[0]['fullName'])->toBe('Acme Co');
});
```

⚠️ The last test's fake URLs/shape must match what `InstagramScraper::fetchProfileResult()` actually calls. Before writing it, read `app/Services/Platforms/InstagramScraper.php:52-200` and `app/Services/Platforms/Actors/*Adapter.php`, and copy the real host + the number of round-trips (run start → dataset read; there may be a status poll — count them and adjust the expected file list). Read the config key for the token from that file too (grep `config('services.` in it) and use that key, not `services.apify.token`, if it differs. **Do not guess** — the whole point of this arm is fidelity to the wire.

- [ ] **Step 2: Run to verify the new tests fail**

Run: `php artisan test tests/Feature/Console/FixturesCaptureCommandTest.php`
Expected: the 4 new tests FAIL with "not implemented yet"; the earlier 6 still PASS.

- [ ] **Step 3: Implement `fromDb` and `fromLive`**

Replace the two stubs in `FixturesCaptureCommand`:

```php
    private const BILLED_SOURCES = ['instagram', 'places', 'menus'];

    /** @return array{0:string,1:string,2:array<string,mixed>} */
    private function fromDb(string $source): array
    {
        $ref = (string) $this->option('ref');
        if ($ref === '') {
            throw new \InvalidArgumentException('--ref is required for --from=db: a platform_connections id, or <stream_id>:<key> for ingest.record_versions');
        }

        if (str_contains($ref, ':')) {
            [$streamId, $key] = explode(':', $ref, 2);
            $row = \Illuminate\Support\Facades\DB::connection('pgsql')->table('ingest.record_versions')
                ->where('stream_id', $streamId)->where('key', $key)
                ->orderByDesc('first_seen_at')->first();
            if ($row === null) {
                throw new \RuntimeException("No ingest.record_versions row for {$ref}");
            }

            return [(string) $row->doc, 'json', ['source_url' => "db://ingest.record_versions/{$ref}"]];
        }

        $conn = \App\Models\Core\Site\IntegrationConnection::query()->find($ref);
        if ($conn === null) {
            throw new \RuntimeException("No site.platform_connections row with id {$ref}");
        }
        $body = (string) json_encode($conn->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [$body, 'json', ['source_url' => "db://site.platform_connections/{$conn->id} ({$conn->surface_key})"]];
    }

    /**
     * Run the source's real scraper and record EVERY response body it received.
     * The first body is returned as the primary; the rest are written here as
     * <name>.<n>.<ext>. That gives C1 the wire, not the normalised output.
     *
     * @return array{0:string,1:string,2:array<string,mixed>}
     */
    private function fromLive(string $source): array
    {
        $ref = (string) $this->option('ref');
        if ($ref === '') {
            throw new \InvalidArgumentException('--ref is required for --from=live (instagram username, place_id, or URL)');
        }
        if (in_array($source, self::BILLED_SOURCES, true) && ! $this->option('confirm-spend')) {
            throw new \RuntimeException("Source '{$source}' bills a third party. Re-run with --confirm-spend to spend one call.");
        }

        $captured = [];
        \Illuminate\Support\Facades\Http::globalResponseMiddleware(function ($response) use (&$captured) {
            $captured[] = [
                'body' => (string) $response->getBody(),
                'contentType' => (string) ($response->getHeaderLine('Content-Type') ?: 'application/json'),
            ];
            $response->getBody()->rewind();

            return $response;
        });

        match ($source) {
            'instagram' => app(\App\Services\Platforms\InstagramScraper::class)->fetchProfileResult($ref, 'fixtures-capture'),
            'places' => app(\App\Services\Platforms\GoogleBusinessService::class)->fetchPlaceDetailsRaw($ref, 'fixtures-capture'),
            default => app(SafeUrlFetcher::class)->fetch($ref),
        };

        if ($captured === []) {
            throw new \RuntimeException('The scraper made no HTTP request — nothing to record.');
        }

        // Write bodies 2..n here; body 1 is returned to handle() and written by the caller.
        $root = (string) ($this->option('root') ?: base_path('tests/fixtures/recorded'));
        $store = new FixtureStore($root, new FixtureManifest($root.'/MANIFEST.json'));
        $name = (string) $this->argument('name');
        foreach (array_slice($captured, 1, null, true) as $i => $c) {
            $store->put($source, $name.'.'.($i + 1), self::extFromContentType($c['contentType']), $c['body'], [
                'source_url' => "live://{$source}/{$ref}#".($i + 1), 'captured_by' => 'fixtures:capture', 'notes' => (string) ($this->option('notes') ?? ''),
            ]);
        }

        return [$captured[0]['body'], self::extFromContentType($captured[0]['contentType']), ['source_url' => "live://{$source}/{$ref}#1"]];
    }
```

And in `handle()`, when `--from=live`, the primary body must be written as `<name>.1.<ext>` so numbering is contiguous: change the `$store->put($source, $name, …)` call to use `$this->option('from') === 'live' ? $name.'.1' : $name`.

- [ ] **Step 4: Run all capture tests**

Run: `php artisan test tests/Feature/Console/FixturesCaptureCommandTest.php`
Expected: PASS (10). If the live test's request count differs from the fake sequence, fix the **fake** to match the real scraper — never the scraper.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/FixturesCaptureCommand.php tests/Feature/Console/FixturesCaptureCommandTest.php
git commit -m "feat(fixtures): fixtures:capture --from=db and --from=live (recorded wire bodies, spend gate)"
```

---

### Task 5: `FixtureMutator` (A2)

**Files:**
- Create: `tests/Support/Fixtures/FixtureMutator.php`
- Modify: `tests/Support/Fixtures/Recorded.php` (add `mutate()`)
- Test: `tests/Unit/Support/FixtureMutatorTest.php`

**Interfaces:**
- Produces: `Recorded::mutate(array $payload): FixtureMutator`; `FixtureMutator::without(string ...$dotKeys): self`, `nullify(string ...$dotKeys): self`, `set(string $dotKey, mixed $value): self`, `emptyArray(string ...$dotKeys): self`, `snakeCaseKeys(): self`, `camelCaseKeys(): self`, `get(): array`. Immutable-style: each call returns a new instance.

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Unit/Support/FixtureMutatorTest.php

use Tests\Support\Fixtures\Recorded;

$base = ['fullName' => 'Jane Doe', 'biography' => 'Hair by Jane', 'externalUrl' => 'https://x', 'postsCount' => 56, 'latestPosts' => [['id' => 1]], 'businessCategoryName' => 'Hair salon'];

it('drops keys with without()', function () use ($base) {
    expect(Recorded::mutate($base)->without('biography', 'externalUrl')->get())
        ->not->toHaveKeys(['biography', 'externalUrl'])
        ->toHaveKey('fullName');
});

it('nullifies and sets by dot key', function () use ($base) {
    $out = Recorded::mutate($base)->nullify('externalUrl')->set('postsCount', 0)->set('latestPosts.0.id', 99)->get();
    expect($out['externalUrl'])->toBeNull()
        ->and($out['postsCount'])->toBe(0)
        ->and($out['latestPosts'][0]['id'])->toBe(99);
});

it('empties arrays', function () use ($base) {
    expect(Recorded::mutate($base)->emptyArray('latestPosts')->get()['latestPosts'])->toBe([]);
});

it('snake_cases every key recursively — the SIGNUP-2 shape', function () use ($base) {
    $out = Recorded::mutate($base)->snakeCaseKeys()->get();
    expect($out)->toHaveKeys(['full_name', 'external_url', 'posts_count', 'business_category_name'])
        ->not->toHaveKey('fullName');
});

it('camelCases keys back and round-trips', function () use ($base) {
    expect(Recorded::mutate($base)->snakeCaseKeys()->camelCaseKeys()->get())->toBe($base);
});

it('does not mutate the original payload', function () use ($base) {
    $m = Recorded::mutate($base);
    $m->without('fullName');
    expect($m->get())->toBe($base);
});
```

- [ ] **Step 2: Run to verify fail**

Run: `php artisan test tests/Unit/Support/FixtureMutatorTest.php` → FAIL (`mutate` undefined).

- [ ] **Step 3: Implement**

```php
<?php
// tests/Support/Fixtures/FixtureMutator.php

namespace Tests\Support\Fixtures;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Derive edge cases from ONE recorded fixture so they stay shaped like reality:
 * a dropped field, a null, a zero, a key-case change (the actor swap that made
 * every IG site nameless — SIGNUP-2). Immutable: each call returns a new instance.
 */
final class FixtureMutator
{
    /** @param array<string, mixed> $payload */
    public function __construct(private readonly array $payload) {}

    public function without(string ...$dotKeys): self
    {
        $p = $this->payload;
        foreach ($dotKeys as $k) {
            Arr::forget($p, $k);
        }

        return new self($p);
    }

    public function nullify(string ...$dotKeys): self
    {
        $p = $this->payload;
        foreach ($dotKeys as $k) {
            Arr::set($p, $k, null);
        }

        return new self($p);
    }

    public function set(string $dotKey, mixed $value): self
    {
        $p = $this->payload;
        Arr::set($p, $dotKey, $value);

        return new self($p);
    }

    public function emptyArray(string ...$dotKeys): self
    {
        $p = $this->payload;
        foreach ($dotKeys as $k) {
            Arr::set($p, $k, []);
        }

        return new self($p);
    }

    public function snakeCaseKeys(): self
    {
        return new self(self::rekey($this->payload, fn (string $k) => Str::snake($k)));
    }

    public function camelCaseKeys(): self
    {
        return new self(self::rekey($this->payload, fn (string $k) => Str::camel($k)));
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        return $this->payload;
    }

    /** @param array<mixed> $arr */
    private static function rekey(array $arr, callable $fn): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $nk = is_string($k) ? $fn($k) : $k;
            $out[$nk] = is_array($v) ? self::rekey($v, $fn) : $v;
        }

        return $out;
    }
}
```

Add to `Recorded`:

```php
    /** @param array<string, mixed> $payload */
    public static function mutate(array $payload): FixtureMutator
    {
        return new FixtureMutator($payload);
    }
```

- [ ] **Step 4: Run** → `php artisan test tests/Unit/Support/FixtureMutatorTest.php` PASS (6).

- [ ] **Step 5: Commit**

```bash
git add tests/Support/Fixtures tests/Unit/Support/FixtureMutatorTest.php
git commit -m "test(fixtures): FixtureMutator — reality-shaped edge cases from one recorded payload"
```

---

### Task 6: B1 — catalog classification sweep + report

**Files:**
- Create: `tests/Support/Catalog/SweepProbeUrl.php`, `tests/fixtures/catalog/probe-urls.php`, `tests/fixtures/catalog/known-invisible.php`
- Create: `tests/Feature/Platforms/CatalogClassificationSweepTest.php`
- Create: `docs/reviews/2026-08-18-platform-coverage-sweep-RESULTS.md`

**Interfaces:**
- Consumes: `App\Catalog\CompiledCatalog::surfaces(): array<string, array>` (keys incl. `key`, `brand_key`, `display_name`, `routing_class`, `canonical_url_template`, `is_connectable`, `lifecycle`), `::isCompiled()`; `App\Services\Platforms\WebsiteLinkHarvester::classify(string $url): ?array{platform:string,category:string,label:string}`; `App\Models\Core\Site\IntegrationConnection::RETIRED_SURFACES`.
- Produces: `Tests\Support\Catalog\SweepProbeUrl::for(array $surface, array $handWritten): ?string`; `::bucket(?array $classified): 'connectable'|'link-only'|'invisible'`.

**Ratchet semantics (read before implementing):** the sweep must be *permanent CI*, so a surface `classify()` cannot see today is pinned in `known-invisible.php`. The test then fails on (1) a surface with no probe URL, (2) a NEW invisible surface not in the baseline, and (3) a STALE baseline row that now classifies (so improvements are recorded too). This is a baseline, not a weakened assertion — the invisible list is the report's headline.

- [ ] **Step 1: Write the support class + fixture files**

```php
<?php
// tests/Support/Catalog/SweepProbeUrl.php

namespace Tests\Support\Catalog;

/** Turns a compiled surface into ONE probe URL for the classification sweep. */
final class SweepProbeUrl
{
    /** Placeholder → sample value. Anything else falls back to 'acme'. */
    private const SAMPLES = [
        'handle' => 'acme', 'slug' => 'acme', 'store' => 'acme', 'username' => 'acme', 'user' => 'acme',
        'id' => '1234567', 'event_id' => '1234567', 'video_id' => 'dQw4w9WgXcQ', 'channel' => 'acme',
        'artist' => 'acme', 'album' => 'acme', 'shop' => 'acme', 'domain' => 'acme',
    ];

    /**
     * @param  array<string, mixed>  $surface   one entry of CompiledCatalog::surfaces()
     * @param  array<string, string>  $handWritten  surface key => URL, from tests/fixtures/catalog/probe-urls.php
     */
    public static function for(array $surface, array $handWritten): ?string
    {
        $key = (string) $surface['key'];
        if (isset($handWritten[$key])) {
            return $handWritten[$key];
        }
        $template = $surface['canonical_url_template'] ?? null;
        if (! is_string($template) || $template === '') {
            return null;
        }

        return (string) preg_replace_callback('/\{([a-z_]+)\}/i', fn ($m) => self::SAMPLES[strtolower($m[1])] ?? 'acme', $template);
    }

    /** @param  array{platform:string,category:string,label:string}|null  $classified */
    public static function bucket(?array $classified): string
    {
        if ($classified === null) {
            return 'invisible';
        }

        return $classified['category'] === 'link' ? 'link-only' : 'connectable';
    }
}
```

`tests/fixtures/catalog/probe-urls.php` — start EMPTY and let the test tell you which surfaces need one:

```php
<?php

// Hand-written probe URLs for catalog surfaces that declare NO canonical_url_template.
// One entry per surface key. Keep the URL realistic (a real profile/venue shape).
// The sweep test lists every surface missing from here AND from the template set.
return [
    // 'brand.surface' => 'https://…',
];
```

`tests/fixtures/catalog/known-invisible.php` — start EMPTY:

```php
<?php

// Ratchet baseline for CatalogClassificationSweepTest: surfaces that
// WebsiteLinkHarvester::classify() returns null for TODAY. Each is a known
// gap of the N1 class (see docs/reviews/2026-08-18-platform-coverage-sweep-RESULTS.md).
// The test fails on a NEW invisible surface (regression) and on a STALE row here
// (improvement) — update this list only with the report.
return [
    // 'brand.surface',
];
```

- [ ] **Step 2: Write the sweep test**

```php
<?php
// tests/Feature/Platforms/CatalogClassificationSweepTest.php

use App\Catalog\CompiledCatalog;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\WebsiteLinkHarvester;
use Tests\Support\Catalog\SweepProbeUrl;

// B1 (spec 2026-08-18-pipeline-assurance §5): every catalog surface must be
// reachable through the real classifier. Cases are DERIVED from the compiled
// catalog at runtime — a new definition adds its own coverage; a hand list
// would rot exactly the way that produced N1 (39 invisible hosts).

function sweepSurfaces(): array
{
    expect(CompiledCatalog::isCompiled())->toBeTrue('bootstrap/catalog/compiled.php missing — run php artisan catalog:compile');
    $retired = array_flip(IntegrationConnection::RETIRED_SURFACES);

    return array_filter(CompiledCatalog::surfaces(), fn ($s, $k) => ! isset($retired[$k]), ARRAY_FILTER_USE_BOTH);
}

function sweepHandWritten(): array
{
    return require dirname(__DIR__, 2).'/fixtures/catalog/probe-urls.php';
}

function sweepKnownInvisible(): array
{
    return require dirname(__DIR__, 2).'/fixtures/catalog/known-invisible.php';
}

it('has a probe URL for every surface (template or hand-written)', function () {
    $missing = [];
    foreach (sweepSurfaces() as $key => $surface) {
        if (SweepProbeUrl::for($surface, sweepHandWritten()) === null) {
            $missing[] = $key;
        }
    }
    sort($missing);

    expect($missing)->toBeEmpty(
        "These surfaces declare no canonical_url_template and have no entry in tests/fixtures/catalog/probe-urls.php:\n - "
        .implode("\n - ", $missing),
    );
});

it('classifies every surface, or the surface is a pinned known gap', function () {
    $classifier = app(WebsiteLinkHarvester::class);
    $known = array_flip(sweepKnownInvisible());
    $newlyInvisible = [];
    $nowVisible = [];

    foreach (sweepSurfaces() as $key => $surface) {
        $url = SweepProbeUrl::for($surface, sweepHandWritten());
        if ($url === null) {
            continue; // reported by the test above
        }
        $bucket = SweepProbeUrl::bucket($classifier->classify($url));
        if ($bucket === 'invisible' && ! isset($known[$key])) {
            $newlyInvisible[] = "{$key}  ({$url})";
        }
        if ($bucket !== 'invisible' && isset($known[$key])) {
            $nowVisible[] = $key;
        }
    }

    expect($newlyInvisible)->toBeEmpty(
        "classify() returns null for these surfaces and they are NOT in known-invisible.php (regression, or a new gap to record in the report):\n - "
        .implode("\n - ", $newlyInvisible),
    );
    expect($nowVisible)->toBeEmpty(
        "These known-invisible.php rows now classify — remove them and note the improvement in the report:\n - "
        .implode("\n - ", $nowVisible),
    );
});

it('does not classify a retired surface into a real connection', function () {
    $classifier = app(WebsiteLinkHarvester::class);
    foreach (IntegrationConnection::RETIRED_SURFACES as $key) {
        $surface = CompiledCatalog::surface($key);
        if ($surface === null) {
            continue; // not in the catalog at all — nothing to check
        }
        $url = SweepProbeUrl::for($surface, sweepHandWritten());
        if ($url === null) {
            continue;
        }
        expect(SweepProbeUrl::bucket($classifier->classify($url)))->not->toBe('connectable', "retired surface {$key} classified as connectable");
    }
});

// The report's headline numbers, printed once so the executor can paste them.
it('prints the bucket split', function () {
    $classifier = app(WebsiteLinkHarvester::class);
    $counts = ['connectable' => [], 'link-only' => [], 'invisible' => [], 'no-probe' => []];
    foreach (sweepSurfaces() as $key => $surface) {
        $url = SweepProbeUrl::for($surface, sweepHandWritten());
        if ($url === null) {
            $counts['no-probe'][] = $key;

            continue;
        }
        $counts[SweepProbeUrl::bucket($classifier->classify($url))][] = $key;
    }
    fwrite(STDERR, "\nCATALOG SWEEP: ".json_encode(array_map('count', $counts))."\n".json_encode($counts, JSON_PRETTY_PRINT)."\n");
    expect(true)->toBeTrue();
})->skip(getenv('CATALOG_SWEEP_REPORT') !== '1', 'set CATALOG_SWEEP_REPORT=1 to print');
```

Note the four `function`s are file-local Pest globals — the same house pattern as `catalogClassifier()` in `CatalogBackedClassificationTest.php`. Prefix them `sweep*` so they cannot collide with any other file (the parallel-suite rule).

- [ ] **Step 3: Run once, fill the two fixture files from the output**

Run: `php artisan test tests/Feature/Platforms/CatalogClassificationSweepTest.php`
Expected first run: test 1 FAILS listing every surface without a template (~62). For each, write a realistic URL into `probe-urls.php` (a real profile/venue path shape from that brand — check the definition's detectors' `registrable_key`/`path_pattern` in `bootstrap/catalog/compiled.php` so the URL actually matches the detector). Re-run until test 1 passes.

Then test 2 FAILS listing the newly-invisible set. **Do not fix any detector.** Copy each key into `known-invisible.php`. Re-run: all green.

Then: `CATALOG_SWEEP_REPORT=1 php artisan test tests/Feature/Platforms/CatalogClassificationSweepTest.php` and copy the JSON split.

- [ ] **Step 4: Write the report**

`docs/reviews/2026-08-18-platform-coverage-sweep-RESULTS.md` with exactly the sections the prompt asks for:

```markdown
# Platform coverage sweep — RESULTS, 2026-08-18

Prompt: `2026-08-18-platform-coverage-sweep-PROMPT.md`. Test: `tests/Feature/Platforms/CatalogClassificationSweepTest.php`.
Run: `<commit sha>` · `<date time UTC>` · `<N>` surfaces (RETIRED_SURFACES excluded: 6).

## 1. Headline
| bucket | count |
|---|---|
| connectable (a hand table answers) | … |
| link-only (only the catalog answers — the P8 backlog, by design) | … |
| invisible (`classify()` → null) — **the finding class N1 was made of** | … |

## 2. The invisible list (ranked by plausibility a real user links it)
| surface | probe URL | routing_class | why it matters |
…
## 3. The link-only list (by design today; P8 sizes it)
…
## 4. Fixture debt — surfaces with no `canonical_url_template` (hand-written URL in probe-urls.php; these rot silently)
…
## 5. Findings (evidence only, no fixes proposed)
…
## 6. Correct-by-design (so the next reader does not re-raise them)
- `link` category is deliberate — recognised, never auto-connected, spends no probe.
- Storefront hosts (Gumroad, stan.store, Squarespace, WooCommerce) return null on purpose — the probe arm reads the product.
```

(§4 of the prompt's Phase 4 — the gate matrix — is Task 7's file; reference it from this report.)

- [ ] **Step 5: Run the neighbours and commit**

Run: `php artisan test tests/Feature/Platforms/CatalogClassificationSweepTest.php tests/Feature/Platforms/CatalogBackedClassificationTest.php tests/Unit/Catalog/CatalogArtefactTest.php` → PASS.

```bash
git add tests/Support/Catalog tests/fixtures/catalog tests/Feature/Platforms/CatalogClassificationSweepTest.php docs/reviews/2026-08-18-platform-coverage-sweep-RESULTS.md
git commit -m "test(platforms): catalog classification sweep derived from CompiledCatalog + known-invisible ratchet + report"
```

---

### Task 7: B2 — `LinkRouter` gate × account matrix

**Files:**
- Create: `tests/Feature/Platforms/LinkRouterGateMatrixTest.php`

**Interfaces:**
- Consumes: `LinkRouter::route(User $user, string $url, RouteContext $ctx): RouteResult` (`app/Services/Platforms/LinkRouter.php:44`); private `LinkRouter::gateAllows(User $user, string $category): bool` (`:201`); `RouteResult->outcome` ∈ `seeded|conflict|custom|pending|skipped`; `RouteContext::__construct(int $maxProbes = 6, bool $autoConnectBooking = false)`; `User::isBusiness()` compares `account_type === AccountType::Business`; `SectorTaxonomy::isFood(?string)`.

The matrix (from the spec / prompt Phase 3 — every cell is CURRENT behaviour to pin):

| category | partna | business non-food | business food |
|---|---|---|---|
| social | allow | allow | allow |
| booking | allow | allow | **deny** |
| event, event-organiser | allow | allow | allow |
| shop | allow | allow | allow |
| link | allow | allow | allow |
| reservations | deny | deny | allow |
| online-ordering | deny | deny | allow |
| (anything else) | deny | deny | deny |

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/Platforms/LinkRouterGateMatrixTest.php

use App\Enums\AccountType;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Support\Facades\Queue;

// B2 (spec 2026-08-18-pipeline-assurance §5): every LinkRouter category × account
// shape, pinned. Two cells carry product consequences found by live waves:
//   • booking INVERTS on food — `$isBusiness ? !$isFood : true`
//   • a restaurant that signs up via Instagram is `partna` (config
//     partna.pre_account.sources), so its reservations/online-ordering demote to custom.
// gateAllows() is private; the matrix drives it by reflection so it needs no DB,
// and two end-to-end cells below prove the reflection reflects route().

function gateUser(string $accountType, ?string $sector): User
{
    $u = new User;
    $u->forceFill(['account_type' => $accountType, 'sector' => $sector]);

    return $u;
}

function gateAllows(User $user, string $category): bool
{
    $m = new ReflectionMethod(LinkRouter::class, 'gateAllows');

    return (bool) $m->invoke(app(LinkRouter::class), $user, $category);
}

dataset('gate_matrix', function () {
    $shapes = [
        'partna' => ['partna', 'hair-salon'],
        'business non-food' => ['business', 'barber'],
        'business food' => ['business', 'restaurant'],
    ];
    $expected = [
        'social' => [true, true, true],
        'booking' => [true, true, false],
        'event' => [true, true, true],
        'event-organiser' => [true, true, true],
        'shop' => [true, true, true],
        'link' => [true, true, true],
        'reservations' => [false, false, true],
        'online-ordering' => [false, false, true],
        'not-a-category' => [false, false, false],
    ];
    $rows = [];
    $i = 0;
    foreach ($shapes as $shapeName => [$type, $sector]) {
        foreach ($expected as $category => $cells) {
            $rows["{$category} × {$shapeName}"] = [$type, $sector, $category, $cells[$i]];
        }
        $i++;
    }

    return $rows;
});

it('gates each category per account shape', function (string $type, string $sector, string $category, bool $allowed) {
    expect(gateAllows(gateUser($type, $sector), $category))->toBe($allowed);
})->with('gate_matrix');

it('treats a business with an unresolved sector as non-food (the gelato gap)', function () {
    // SectorTaxonomy::FOOD_SECTORS has no gelato/ice-cream/dessert slug, so a
    // gelateria whose sector never resolves — or resolves outside FOOD_SECTORS —
    // takes the non-food column: booking allowed, reservations/ordering denied.
    $gelateria = gateUser('business', null);
    expect(gateAllows($gelateria, 'booking'))->toBeTrue()
        ->and(gateAllows($gelateria, 'online-ordering'))->toBeFalse()
        ->and(gateAllows($gelateria, 'reservations'))->toBeFalse();
});

it('is the gate route() actually applies — a denied booking link becomes a custom link end to end', function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupNotificationsTable();
    Queue::fake();

    $user = User::factory()->create(['account_type' => 'business', 'sector' => 'restaurant']);
    // Sanity: the URL classifies as booking before the gate sees it.
    expect(app(WebsiteLinkHarvester::class)->classify('https://www.fresha.com/a/doc-cuts')['category'])->toBe('booking');

    $result = app(LinkRouter::class)->route($user, 'https://www.fresha.com/a/doc-cuts', new RouteContext);

    expect($result->outcome)->toBe('custom');
});

it('is the gate route() actually applies — the same booking link on a partna account is not gate-denied', function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupNotificationsTable();
    Queue::fake();

    $user = User::factory()->create(['account_type' => 'partna', 'sector' => 'hair-salon']);

    $result = app(LinkRouter::class)->route($user, 'https://www.fresha.com/a/doc-cuts', new RouteContext);

    // seeded / pending / conflict are all "the gate let it through"; only custom is a denial.
    expect($result->outcome)->not->toBe('custom', "partna booking link came back {$result->outcome}");
});
```

- [ ] **Step 2: Run**

Run: `php artisan test tests/Feature/Platforms/LinkRouterGateMatrixTest.php`
Expected: 27 matrix rows + 3 = PASS. If the last test returns `custom` because `seedBooking` needs a table the setup list lacks, read `LinkRouter::seedBooking()` and add the missing `setup*Table()` — do NOT relax the assertion. If a cell of the matrix fails, the code and the spec disagree: **stop and report** (that is a finding, and the matrix is the spec's source of truth for expected values).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Platforms/LinkRouterGateMatrixTest.php
git commit -m "test(platforms): LinkRouter gate × account matrix, pinned incl. booking-inverts-on-food"
```

---

### Task 8: B2 — signup pairing matrix

**Files:**
- Create: `tests/Feature/PreAccount/SignupPairingMatrixTest.php`

**Interfaces:**
- Consumes: `POST /api/public/signup/build` (`routes/api.php:165`) → 202 + `{build_id, build_state}` on a new build; 422 `{code: 'SOURCE_PAIRING_INVALID'}` for a disallowed pair (`PreAccountBuildService.php:74-82`, `PreAccountBuildController.php:41-52`); `config('partna.pre_account.sources')` = `['partna' => ['instagram'], 'business' => ['google_business']]`; `config('partna.pre_account.generators')` keys; `App\Enums\AccountType::cases()`; `source_name` required for `google_business`.
- ⚠️ `requestBuild` **dedupes before pairing** — every cell must use a distinct `source_ref`. The per-IP cap is 3 unclaimed; each `it()` runs on a fresh DB so no cell exhausts it.

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/PreAccount/SignupPairingMatrixTest.php

use App\Enums\AccountType;
use Illuminate\Support\Facades\Queue;

// B2 (spec 2026-08-18-pipeline-assurance §5): the account_type × source_type
// pairing table, DERIVED from config('partna.pre_account.sources') ×
// AccountType::cases() × config('partna.pre_account.generators') at runtime, so a
// third source or a new pairing gets a row automatically. Allowed → 202, every
// other pair → 422 SOURCE_PAIRING_INVALID. Also pins: the two registries agree.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

dataset('pairing_matrix', function () {
    $sources = config('partna.pre_account.sources', []);
    $generators = array_keys(config('partna.pre_account.generators', []));
    $rows = [];
    $n = 0;
    foreach (AccountType::cases() as $type) {
        foreach ($generators as $sourceType) {
            $allowed = in_array($sourceType, $sources[$type->value] ?? [], true);
            $rows["{$type->value} × {$sourceType} → ".($allowed ? '202' : '422')] = [$type->value, $sourceType, $allowed, 'ref'.(++$n)];
        }
    }

    return $rows;
});

it('accepts allowed pairs with 202 and rejects every other pair with 422 SOURCE_PAIRING_INVALID', function (string $accountType, string $sourceType, bool $allowed, string $ref) {
    $body = ['account_type' => $accountType, 'source_type' => $sourceType, 'source_ref' => $ref];
    if ($sourceType === 'google_business') {
        $body['source_name'] = 'Acme '.$ref;
    }

    $res = $this->postJson('/api/public/signup/build', $body);

    if ($allowed) {
        $res->assertStatus(202)->assertJsonStructure(['build_id', 'build_state']);
    } else {
        $res->assertStatus(422)->assertJsonPath('code', 'SOURCE_PAIRING_INVALID');
    }
})->with('pairing_matrix');

it('lists every allowed source in the generator registry and every account type in the pairing map', function () {
    $sources = config('partna.pre_account.sources', []);
    $generators = array_keys(config('partna.pre_account.generators', []));

    foreach ($sources as $accountType => $allowedSources) {
        expect(AccountType::tryFrom($accountType))->not->toBeNull("sources map has unknown account_type '{$accountType}'");
        foreach ($allowedSources as $s) {
            expect($generators)->toContain($s, "sources map allows '{$s}' but no generator is registered");
        }
    }
    foreach (AccountType::cases() as $type) {
        expect($sources)->toHaveKey($type->value, "account_type '{$type->value}' has no sources entry");
    }
});

it('rejects an unknown source_type at validation, before pairing', function () {
    $this->postJson('/api/public/signup/build', ['account_type' => 'partna', 'source_type' => 'tiktok', 'source_ref' => 'x'])
        ->assertStatus(422)
        ->assertJsonMissingPath('code');
});
```

- [ ] **Step 2: Run** → `php artisan test tests/Feature/PreAccount/SignupPairingMatrixTest.php` → PASS (4 matrix rows + 2). If `assertJsonMissingPath('code')` fails because the validation error envelope also carries a `code`, read `ApiController::error()` and assert on the actual validation code instead — pin, don't guess.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PreAccount/SignupPairingMatrixTest.php
git commit -m "test(pre-account): signup pairing matrix derived from partna.pre_account.sources × AccountType"
```

---

### Task 9: B3 — sector fold table

**Files:**
- Create: `tests/Unit/Profile/SectorFoldTableTest.php`

**Interfaces:**
- Consumes: `SectorTaxonomy::fromGoogleCategory(?string): ?string` (`app/Services/Profile/SectorTaxonomy.php:517`), `::fromInstagramCategory(?string): ?string` (`:541`), `::isFood(?string)`, `::isValid(string)`, `::all()`, `FOOD_SECTORS`.

Two groups: **rows expected to fold** (a real trade word → its slug) and **known food gaps** (obviously-food categories that fold to `null` or to a non-food slug today, pinned so the gap is visible in the test names). Expected values in group 1 are derived from the taxonomy's own keyword maps — verify each row against `KEYWORD_SECTORS` / `INSTAGRAM_CATEGORY_SECTORS` in `SectorTaxonomy.php` before committing; if a row disagrees with the constant, the constant wins and the row is corrected. If a row you believe SHOULD fold does not, it goes to group 2 with a comment — never delete it.

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Unit/Profile/SectorFoldTableTest.php

use App\Services\Profile\SectorTaxonomy;

// B3 (spec 2026-08-18-pipeline-assurance §5): every Google category and Instagram
// businessCategoryName we have SEEN (build waves 2026-08-05 → 08-18, RESULTS files)
// → the sector it folds to. Group 2 pins the food gap: categories that are food in
// reality but not to SectorTaxonomy::isFood(), because FOOD_SECTORS has no
// gelato / ice-cream / dessert slug. Those rows assert CURRENT behaviour and are
// named so the gap is legible — changing them is a product decision.

it('folds a Google category to the expected sector', function (string $category, string $expected) {
    expect(SectorTaxonomy::fromGoogleCategory($category))->toBe($expected);
})->with([
    'Barber shop' => ['Barber shop', 'barber'],
    'Hair salon' => ['Hair salon', 'hair-salon'],
    'Tattoo shop' => ['Tattoo shop', 'tattoo-artist'],
    'Restaurant' => ['Restaurant', 'restaurant'],
    'Spanish restaurant' => ['Spanish restaurant', 'restaurant'],
    'Bar' => ['Bar', 'bar'],
    'Wine bar' => ['Wine bar', 'bar'],
    'Cafe' => ['Cafe', 'cafe'],
    'Coffee shop' => ['Coffee shop', 'cafe'],
    'Bakery' => ['Bakery', 'bakery'],
    'Beauty salon' => ['Beauty salon', 'esthetician'],
    'Nail salon' => ['Nail salon', 'nail-technician'],
    'Photographer' => ['Photographer', 'photographer'],
    'Personal trainer' => ['Personal trainer', 'personal-trainer'],
    'Plumber' => ['Plumber', 'plumber'],
]);

it('folds an Instagram businessCategoryName to the expected sector', function (string $category, string $expected) {
    expect(SectorTaxonomy::fromInstagramCategory($category))->toBe($expected);
})->with([
    'Hair salon' => ['Hair salon', 'hair-salon'],
    'Barber Shop' => ['Barber Shop', 'barber'],
    'Tattoo & Piercing Shop' => ['Tattoo & Piercing Shop', 'tattoo-artist'],
    'Restaurant' => ['Restaurant', 'restaurant'],
    'compound with None first (F5)' => ['None,Fast food restaurant', 'restaurant'],
    'Photographer' => ['Photographer', 'photographer'],
    'Musician/Band' => ['Musician/Band', 'musician'],
    'Artist' => ['Artist', 'artist'],
]);

it('returns null for placeholder categories', function (string $category) {
    expect(SectorTaxonomy::fromInstagramCategory($category))->toBeNull()
        ->and(SectorTaxonomy::fromGoogleCategory($category))->toBeNull();
})->with(['None', 'none', ' None ', 'null', 'N/A', '-', '']);

// GROUP 2 — the food gap, pinned as current behaviour.
it('KNOWN GAP: an obviously-food category is not food to the gate', function (string $category) {
    $sector = SectorTaxonomy::fromGoogleCategory($category);
    expect(SectorTaxonomy::isFood($sector))->toBeFalse(
        "'{$category}' now folds to food sector '{$sector}' — the gelato gap closed; move this row to group 1 and update the report",
    );
})->with([
    'Ice cream shop' => ['Ice cream shop'],
    'Gelato shop' => ['Gelato shop'],
    'Dessert shop' => ['Dessert shop'],
    'Juice bar' => ['Juice bar'],
]);

it('keeps FOOD_SECTORS inside the valid slug set and every fold inside all()', function () {
    $valid = collect(SectorTaxonomy::all())->flatMap(fn ($g) => collect($g['options'])->pluck('slug'))->all();
    foreach (SectorTaxonomy::FOOD_SECTORS as $slug) {
        expect(SectorTaxonomy::isValid($slug))->toBeTrue("FOOD_SECTORS has unknown slug '{$slug}'")
            ->and($valid)->toContain($slug);
    }
});
```

- [ ] **Step 2: Run** → `php artisan test tests/Unit/Profile/SectorFoldTableTest.php`. For every group-1 row that fails: open `SectorTaxonomy.php`, find the keyword/exact map, and correct the row's expected value **to what the map says** — unless the map has no entry at all for an obviously-trade word (e.g. "Nail salon" → null), in which case move the row to a third `it('KNOWN GAP: a trade category folds to null')` dataset with the same "…now folds — move to group 1" message shape. Nothing in `app/` changes.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Profile/SectorFoldTableTest.php
git commit -m "test(profile): sector fold table for seen Google/Instagram categories + pinned food gap"
```

---

### Task 10: B4 — name → handle → subdomain property test

**Files:**
- Create: `tests/Feature/PreAccount/HandleSubdomainPropertyTest.php`

**Interfaces:**
- Consumes: `App\Services\User\HandleAllocator::allocate(string $seed): array{handle:string, handle_lc:string}` (`Str::slug` inside; `app/Services/User/HandleAllocator.php:34`); `App\Services\User\SiteProvisioningService::subdomainBaseFromHandle(string): string` (`:156`); `App\Support\BusinessName::wordTrim(string, int $max = 15): string`; `PreAccountBuildService::requestBuild(accountType, sourceType, rawSourceRef, sourceName, ipHash)` returning `['build' => PreAccountBuild, 'reused' => bool]`; the invariant helper shape from `HandleSubdomainConvergenceTest::expectConverged`.
- ⚠️ Per-IP cap = 3 unclaimed builds: every end-to-end row uses its **own** `ipHash`.

Two properties, ~30 names:
1. **Idempotence:** for the allocated `handle_lc`, `subdomainBaseFromHandle(handle_lc) === handle_lc` and it is a valid DNS label (`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`). This is the structural reason handle == subdomain holds post-SIGNUP-1.
2. **Business name cap:** `strlen(wordTrim(name)) <= 15` and it never ends mid-word (the trimmed string is a prefix of `Str::squish($name)` at a word boundary or the whole thing).
3. **End to end** for 4 of the ugliest names via `requestBuild` on the `business`/`google_business` path — converged.

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/PreAccount/HandleSubdomainPropertyTest.php

use App\Models\Core\User\User;
use App\Services\PreAccount\PreAccountBuildService;
use App\Services\User\HandleAllocator;
use App\Services\User\SiteProvisioningService;
use App\Support\BusinessName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// B4 (spec 2026-08-18-pipeline-assurance §5). SIGNUP-1 shipped because the two
// normalisations were only ever tested on a slug-shaped seed. This is the
// property version: for ANY name, the allocated handle is a valid DNS label and
// re-deriving a subdomain from it is the identity — so handle == subdomain holds
// structurally, not by luck. Plus the business-name cap that guards the owner's
// first PATCH after claim (display_name max:15 for business accounts).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

dataset('ugly_names', [
    'apostrophe' => ["Beef's Barbers"],
    'periods' => ['D.O.C. Pizza'],
    'accented n' => ['Añada'],
    'accented e' => ['Café Été'],
    '23 chars' => ['Melbourne Tattoo Company'],
    'ampersand' => ['Bar & Grill'],
    'emoji' => ['Glow ✨ Studio'],
    'leading digit' => ['3 Little Pigs'],
    'all digits' => ['1234'],
    'double spaces' => ['Two  Spaces   Here'],
    'trailing punctuation' => ['Errol\'s.'],
    'leading punctuation' => ['-Dash Studio'],
    'all caps' => ['LOUD BARBERS'],
    'hyphenated' => ['Cut-Throat Barbers'],
    'slash' => ['Hair/Beauty'],
    'parentheses' => ['Acme (Carlton)'],
    'quotes' => ['"Quoted" Salon'],
    'plus' => ['A+ Nails'],
    'underscore' => ['snake_case_studio'],
    'very long' => [str_repeat('Supercalifragilistic', 4)],
    'single char' => ['X'],
    'unicode only' => ['日本料理'],
    'mixed unicode' => ['Ramen 一番'],
    'at sign ig style' => ['@janedoe'],
    'dotted ig' => ['jane.doe.hair'],
    'reserved word' => ['admin'],
    'reserved www' => ['www'],
    'trailing hyphen slug' => ['Salon-'],
    'ellipsis' => ['Wait…'],
    'tabs' => ["Tab\tName"],
]);

it('allocates a handle that is a valid DNS label and a subdomain fixed point', function (string $name) {
    $handle = app(HandleAllocator::class)->allocate($name)['handle_lc'];
    $subdomain = app(SiteProvisioningService::class)->subdomainBaseFromHandle($handle);

    expect($handle)->toMatch('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', "handle '{$handle}' from '{$name}' is not a DNS label")
        ->and($subdomain)->toBe($handle, "subdomainBaseFromHandle('{$handle}') diverged to '{$subdomain}' for '{$name}'");
})->with('ugly_names');

it('word-trims a business name to ≤ 15 chars at a word boundary', function (string $name) {
    $trimmed = BusinessName::wordTrim($name);
    $squished = Str::squish($name);

    expect(mb_strlen($trimmed))->toBeLessThanOrEqual(15);
    if ($squished !== '' && mb_strlen($squished) <= 15) {
        expect($trimmed)->toBe($squished);
    } elseif ($trimmed !== '' && ! str_contains($trimmed, ' ') && mb_strlen(explode(' ', $squished)[0]) > 15) {
        // single over-long first word: a hard cut is the documented behaviour
        expect(mb_strlen($trimmed))->toBe(15);
    } elseif ($trimmed !== '') {
        // multi-word: the kept prefix must end exactly at a word boundary
        expect(str_starts_with($squished, $trimmed))->toBeTrue("'{$trimmed}' is not a prefix of '{$squished}'")
            ->and(mb_strlen($squished) === mb_strlen($trimmed) || mb_substr($squished, mb_strlen($trimmed), 1) === ' ')->toBeTrue("'{$trimmed}' ends mid-word");
    }
})->with('ugly_names');

it('converges handle and subdomain end to end on the business path for the ugliest names', function (string $name, string $salt) {
    $build = app(PreAccountBuildService::class)->requestBuild(
        'business', 'google_business', 'ChIJ'.md5($name), $name, hash('sha256', $salt),
    )['build'];

    $subdomain = DB::connection('pgsql')->table('site.sites')->where('user_id', $build->user_id)->value('subdomain');
    $user = User::query()->find($build->user_id);

    expect($subdomain)->not->toBeNull()
        ->and(strtolower((string) $subdomain))->toBe($user->handle_lc);
})->with([
    'apostrophe' => ["Beef's Barbers", 'a'],
    'accented n' => ['Añada', 'b'],
    'emoji' => ['Glow ✨ Studio', 'c'],
    'unicode only' => ['日本料理', 'd'],
]);
```

- [ ] **Step 2: Run** → `php artisan test tests/Feature/PreAccount/HandleSubdomainPropertyTest.php`.
Expected: PASS. If a `ugly_names` row fails the fixed-point property, that is a **real SIGNUP-1-class finding** — leave the row, do not change `app/`, and record it in the B1 report's Findings section (§5) with the name and both outputs. If `wordTrim` fails a row, check the row against `tests/Unit/Support/BusinessNameTest.php`'s documented behaviour first (e.g. `'!!!'` → `'!!!'`); adjust the *test's branch conditions* only if the documented behaviour differs from the property as written — never `BusinessName`.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PreAccount/HandleSubdomainPropertyTest.php
git commit -m "test(pre-account): handle/subdomain fixed-point + business-name cap property test over 30 ugly names"
```

---

### Task 11: Seed the corpus (dev DB + free URLs; owner-gated live spend)

**Files:**
- Modify: `tests/fixtures/recorded/**` (new files) + `MANIFEST.json`

**Interfaces:** the commands from Tasks 3–4. **Free arms only unless Josh confirms spend** — the two billed captures below are listed but marked "owner confirms".

- [ ] **Step 1: Free captures — link-in-bio + websites + fresha + shop (`--from=url`)**

Pick real public pages (the build waves used these hosts; choose accounts that are public businesses, not private individuals):

```bash
php artisan fixtures:capture linkinbio linktree.mixed   --from=url --url='https://linktr.ee/<a business linktree with ≥8 mixed links>' --notes='Linktree; IG+booking+music+shop mix'
php artisan fixtures:capture linkinbio beacons.sample   --from=url --url='https://beacons.ai/<business>'
php artisan fixtures:capture linkinbio linkinbio.spa    --from=url --url='https://linkin.bio/<business>' --notes='JS SPA — expect 0 anchors (N2)'
php artisan fixtures:capture websites  squarespace.tattoo --from=url --url='https://www.melbournetattoocompany.com/'
php artisan fixtures:capture websites  wordpress.draft-product --from=url --url='<the R7 WordPress page that exposed a Private: Demo draft>' --notes='N3/R7 og:type=product without price'
php artisan fixtures:capture fresha    venue.sample      --from=url --url='https://www.fresha.com/a/<venue-slug>'
php artisan fixtures:capture fresha    venue.book-now    --from=url --url='https://www.fresha.com/book-now/<slug>/all-offer' --notes='R1 share-URL shape'
php artisan fixtures:capture menus     squarespace-menu  --from=url --url='<Añada menu page>'
```

- [ ] **Step 2: Free captures — stored payloads from dev (`--from=db`)**

Against dev (`DB_*` for `glncumufgaqcmqhzwrxm` in the local `.env`, or via `cloud tinker development` + `--from=file`): find one `instagram.profile` and one `google-business` connection id from a wave account (`select id, surface_key, user_id from site.platform_connections where surface_key in ('instagram.profile','google_business.listing') order by created_at desc limit 10;`) and:

```bash
php artisan fixtures:capture instagram stored.business-hair --from=db --ref=<connection uuid> --notes='STORED payload (PRIV-2 stripped), not the raw actor item'
php artisan fixtures:capture places    details.barber        --from=db --ref=<connection uuid> --notes='STORED Places payload; reviews stripped at capture'
```

- [ ] **Step 3: Billed captures — owner confirms first**

Do **not** run these until Josh says yes in the session (each spends one Apify run ≈ 50 units, or one Places Details call):

```bash
php artisan fixtures:capture instagram actor-item.business-hair --from=live --ref=<public business ig username> --confirm-spend
php artisan fixtures:capture instagram actor-item.restaurant    --from=live --ref=<public restaurant ig username> --confirm-spend
php artisan fixtures:capture places    details.gelateria        --from=live --ref=<Pidapipo Carlton place_id> --confirm-spend
```

- [ ] **Step 4: Verify and commit**

```bash
php artisan fixtures:verify
php artisan test tests/Feature/Architecture/RecordedFixtureManifestGuardTest.php
git add tests/fixtures/recorded
git commit -m "test(fixtures): seed the recorded corpus — link-in-bio, websites, fresha, menus, stored dev payloads"
```

If Step 3 was skipped, say so in the commit body and in the handoff — Plan 2 (C1/C3) needs the raw actor items and cannot start on IG scraper contracts without them.

---

### Task 12: Docs + full-suite gate

**Files:**
- Modify: `CLAUDE.md` (one bullet under "Testing" row or a short "Recorded fixtures" note under Workflow)
- Modify: `docs/superpowers/specs/2026-08-18-pipeline-assurance-design.md` (Status line: "A1, A2, B1–B4 shipped <date>; B5 → Plan 2")

- [ ] **Step 1: CLAUDE.md** — add under `## Workflow`:

```
- **Recorded fixtures.** Real upstream responses live in `tests/fixtures/recorded/` (loader `Tests\Support\Fixtures\Recorded`, mutator `Recorded::mutate()`); capture with `php artisan fixtures:capture` (`--from=file|url|db|live`; billed sources need `--confirm-spend`), check with `fixtures:verify`. Every file needs a `MANIFEST.json` row (`RecordedFixtureManifestGuardTest`). New scraper/pipeline tests fake upstream from these — never hand-type an Apify/Places payload. Spec: `docs/superpowers/specs/2026-08-18-pipeline-assurance-design.md`.
```

- [ ] **Step 2: Full local gate**

Run: `composer test` (serial; ~15–20 min) and `php artisan pint --test`.
Expected: green. Known-red allowed ONLY for a documented finding in a matrix test that you have recorded in the report — and even then prefer the baseline/known-gap pin so `development` CI stays green.

- [ ] **Step 3: Commit + PR**

```bash
git add CLAUDE.md docs/superpowers/specs/2026-08-18-pipeline-assurance-design.md
git commit -m "docs: recorded fixtures workflow + spec status for pipeline assurance A/B"
```
Open a PR to `development` titled "test: pipeline assurance — recorded fixture corpus + registry-derived matrices (A1–A2, B1–B4)". Body: the sweep headline numbers, the known-invisible count, any matrix findings, and whether Task 11 Step 3 (billed captures) ran.

---

## Self-review against the spec

- **A1** — Tasks 1–4, 11 (corpus, manifest, capture file/url/db/live, redaction, spend gate, verify, orphan guard). ✔
- **A2** — Task 5. ✔
- **B1** — Task 6, incl. report + retired-surface check + ratchet. ✔
- **B2** — Tasks 7 (gate × account) and 8 (pairing). ✔
- **B3** — Task 9. ✔
- **B4** — Task 10. ✔
- **B5** — deferred to Plan 2 (stated in header). ✔
- Names consistent: `Recorded::{path,raw,json,html,mutate}`, `FixtureMutator::{without,nullify,set,emptyArray,snakeCaseKeys,camelCaseKeys,get}`, `FixtureManifest::{load,entries,upsert,remove,verify}`, `FixtureStore::{put,SOURCES}`, `FixtureRedactor::apply`, `SweepProbeUrl::{for,bucket}`, commands `fixtures:capture` / `fixtures:verify` with `--root`. ✔
- Placeholders: Task 11's `<…>` are owner-chosen live targets by design (spec §9); Task 4's fake sequence carries an explicit "read the scraper first" instruction. No TBDs.
