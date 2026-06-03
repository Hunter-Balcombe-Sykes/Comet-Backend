# Design-Kit Email Branding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** White-label the two pro-originated visitor confirmation emails (enquiry + subscription) with the professional's design-kit colors and logo, via a reusable, cached branding layer.

**Architecture:** A `ProEmailBrandResolver` turns a `site_id` into an immutable `EmailBrand` value object (pro name, logo URL, site URL, reply-to, resolved 8-token palette), cached per-site through `CacheLockService::rememberLocked` and invalidated through the existing `SiteCacheService::invalidateSite` seam. The shared mail layout becomes brand-aware; the two confirmation templates extend it; their Mailables and jobs pass the resolved brand.

**Tech Stack:** PHP 8.2, Laravel 12, Pest 4, Redis cache, Blade email templates.

**Spec:** `docs/superpowers/specs/2026-05-30-design-kit-email-branding-design.md`

---

## File Structure

**New:**
- `app/Mail/Branding/EmailPalette.php` — immutable 8-token palette value object.
- `app/Mail/Branding/EmailBrandDefaults.php` — static default tokens + palette builder (single source of truth for email-safe defaults & derivation).
- `app/Mail/Branding/EmailBrand.php` — immutable brand DTO + `partna()` factory + array (de)serialization for cache.
- `app/Mail/Branding/ProEmailBrandResolver.php` — `forSite()/partna()/forget()`.
- Tests under `tests/Unit/Mail/Branding/` and `tests/Feature/Notifications/`.

**Modified:**
- `app/Services/Cache/CacheKeyGenerator.php` — add `emailBrand()`.
- `app/Services/Cache/SiteCacheService.php` — add `email_brand` (+`:stale`) to `invalidateSite()`.
- `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php` — post-`writeDesignKit()` invalidation.
- `config/partna.php` — `cache.ttls.email_brand`.
- `.env.example` — `PARTNA_CACHE_TTL_EMAIL_BRAND`.
- `resources/views/mail/layouts/partna.blade.php` — brand-aware.
- `resources/views/emails/enquiry-confirmation.blade.php` — extend layout.
- `resources/views/emails/subscription-confirmation.blade.php` — extend layout.
- `app/Mail/EnquiryConfirmationMail.php` — take `EmailBrand`.
- `app/Mail/SubscriptionConfirmationMail.php` — take `EmailBrand`.
- `app/Jobs/Notifications/SendEnquiryConfirmationJob.php` — resolve brand after txn.
- `app/Jobs/Notifications/SendSubscriptionConfirmationJob.php` — resolve brand after txn.

---

## Task 1: EmailPalette value object

**Files:**
- Create: `app/Mail/Branding/EmailPalette.php`
- Test: `tests/Unit/Mail/Branding/EmailPaletteTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Mail\Branding\EmailPalette;

it('exposes all eight email-safe tokens as readonly strings', function () {
    $p = new EmailPalette(
        accent: '#111111',
        accentContrast: '#ffffff',
        bg: '#fafafa',
        text: '#222222',
        textMuted: '#888888',
        buttonBg: '#111111',
        buttonText: '#ffffff',
        borderRadius: '8px',
    );

    expect($p->accent)->toBe('#111111')
        ->and($p->accentContrast)->toBe('#ffffff')
        ->and($p->bg)->toBe('#fafafa')
        ->and($p->text)->toBe('#222222')
        ->and($p->textMuted)->toBe('#888888')
        ->and($p->buttonBg)->toBe('#111111')
        ->and($p->buttonText)->toBe('#ffffff')
        ->and($p->borderRadius)->toBe('8px');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Mail/Branding/EmailPaletteTest.php`
Expected: FAIL — class `App\Mail\Branding\EmailPalette` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Mail\Branding;

// Email-safe subset of the design kit (colors + border radius). Every field is
// non-null: defaults/derivation are pre-applied by EmailBrandDefaults before
// construction, so templates never have to handle a missing token. Fonts are
// deliberately excluded — email clients fall back to system fonts regardless.
final class EmailPalette
{
    public function __construct(
        public readonly string $accent,
        public readonly string $accentContrast,
        public readonly string $bg,
        public readonly string $text,
        public readonly string $textMuted,
        public readonly string $buttonBg,
        public readonly string $buttonText,
        public readonly string $borderRadius,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Mail/Branding/EmailPaletteTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Mail/Branding/EmailPalette.php tests/Unit/Mail/Branding/EmailPaletteTest.php
git commit -m "feat(email): add EmailPalette value object"
```

---

## Task 2: EmailBrandDefaults — defaults + palette builder

**Files:**
- Create: `app/Mail/Branding/EmailBrandDefaults.php`
- Test: `tests/Unit/Mail/Branding/EmailBrandDefaultsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\Branding\EmailPalette;

it('returns the static defaults when the kit is empty', function () {
    $p = EmailBrandDefaults::palette([]);

    expect($p)->toBeInstanceOf(EmailPalette::class)
        ->and($p->accent)->toBe('#3a6efc')
        ->and($p->accentContrast)->toBe('#ffffff')
        ->and($p->bg)->toBe('#ffffff')
        ->and($p->text)->toBe('#1d1d1f')
        ->and($p->textMuted)->toBe('#6e6e73')
        ->and($p->borderRadius)->toBe('8px');
});

it('derives button tokens from accent/accent-contrast when the kit leaves them null', function () {
    $p = EmailBrandDefaults::palette([
        'color_accent' => '#aa0000',
        'color_accent_contrast' => '#ffeeee',
        // button_primary_bg / button_primary_text intentionally absent (NULL columns)
    ]);

    expect($p->buttonBg)->toBe('#aa0000')        // derived from accent
        ->and($p->buttonText)->toBe('#ffeeee');  // derived from accentContrast
});

it('prefers stored values over defaults and over derivation', function () {
    $p = EmailBrandDefaults::palette([
        'color_accent' => '#aa0000',
        'color_bg' => '#000000',
        'button_primary_bg' => '#00ff00',
        'button_primary_text' => '#0000ff',
        'border_radius' => '2px',
    ]);

    expect($p->accent)->toBe('#aa0000')
        ->and($p->bg)->toBe('#000000')
        ->and($p->buttonBg)->toBe('#00ff00')     // stored wins over derived accent
        ->and($p->buttonText)->toBe('#0000ff')
        ->and($p->borderRadius)->toBe('2px');
});

it('ignores empty-string stored values and falls back', function () {
    $p = EmailBrandDefaults::palette(['color_accent' => '']);
    expect($p->accent)->toBe('#3a6efc');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Mail/Branding/EmailBrandDefaultsTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Mail\Branding;

/**
 * Single source of truth for email-safe design-kit defaults.
 *
 * These mirror the email-relevant subset of @partnaau/design-system's
 * design-kit/defaults.ts. That package is the system-wide source of truth; this
 * is a deliberate, contained PHP copy because the package is not reachable from
 * Blade at render time. WHEN A DEFAULT CHANGES THERE, CHANGE IT HERE.
 *
 * Two token kinds:
 *  - 6 STATIC tokens have literal defaults below.
 *  - 2 DERIVED tokens (button_primary_bg / button_primary_text) are NULLABLE
 *    columns with no DB default and no defaults.ts entry; the design system
 *    derives them from accent / accent-contrast at render time, so we do the same.
 */
final class EmailBrandDefaults
{
    public const ACCENT = '#3a6efc';

    public const ACCENT_CONTRAST = '#ffffff';

    public const BG = '#ffffff';

    public const TEXT = '#1d1d1f';

    public const TEXT_MUTED = '#6e6e73';

    public const BORDER_RADIUS = '8px';

    /**
     * Build a fully-populated palette from a raw site.design_kits row
     * (flat snake_case column => value; nulls/missing/empty fall back).
     *
     * @param  array<string, mixed>  $kit
     */
    public static function palette(array $kit): EmailPalette
    {
        $accent = self::pick($kit, 'color_accent', self::ACCENT);
        $accentContrast = self::pick($kit, 'color_accent_contrast', self::ACCENT_CONTRAST);

        return new EmailPalette(
            accent: $accent,
            accentContrast: $accentContrast,
            bg: self::pick($kit, 'color_bg', self::BG),
            text: self::pick($kit, 'color_text', self::TEXT),
            textMuted: self::pick($kit, 'color_text_muted', self::TEXT_MUTED),
            // Derived: stored value wins, else fall back to the resolved base token.
            buttonBg: self::pick($kit, 'button_primary_bg', $accent),
            buttonText: self::pick($kit, 'button_primary_text', $accentContrast),
            borderRadius: self::pick($kit, 'border_radius', self::BORDER_RADIUS),
        );
    }

    /** Default palette (empty kit) — used by EmailBrand::partna(). */
    public static function defaults(): EmailPalette
    {
        return self::palette([]);
    }

    /** Stored value if a non-empty string, else the fallback. */
    private static function pick(array $kit, string $key, string $fallback): string
    {
        $value = $kit[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return $fallback;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Mail/Branding/EmailBrandDefaultsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Mail/Branding/EmailBrandDefaults.php tests/Unit/Mail/Branding/EmailBrandDefaultsTest.php
git commit -m "feat(email): add EmailBrandDefaults (static + derived tokens)"
```

---

## Task 3: EmailBrand DTO + partna() + cache (de)serialization

**Files:**
- Create: `app/Mail/Branding/EmailBrand.php`
- Test: `tests/Unit/Mail/Branding/EmailBrandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\Branding\EmailPalette;

it('builds a Partna-branded brand from defaults', function () {
    config()->set('mail.from.name', 'Partna');

    $b = EmailBrand::partna();

    expect($b->isPartna)->toBeTrue()
        ->and($b->proName)->toBe('Partna')
        ->and($b->siteUrl)->toBe('https://partna.au')
        ->and($b->logoUrl)->toBeNull()
        ->and($b->replyToEmail)->toBeNull()
        ->and($b->palette->accent)->toBe(EmailBrandDefaults::ACCENT);
});

it('round-trips through toArray/fromArray (cache payload)', function () {
    $brand = new EmailBrand(
        isPartna: false,
        proName: 'Jane Doe',
        siteUrl: 'https://jane.partna.au',
        logoUrl: 'https://media.example/logo.webp',
        replyToEmail: 'jane@example.com',
        palette: EmailBrandDefaults::palette(['color_accent' => '#aa0000']),
    );

    $rebuilt = EmailBrand::fromArray($brand->toArray());

    expect($rebuilt->isPartna)->toBeFalse()
        ->and($rebuilt->proName)->toBe('Jane Doe')
        ->and($rebuilt->siteUrl)->toBe('https://jane.partna.au')
        ->and($rebuilt->logoUrl)->toBe('https://media.example/logo.webp')
        ->and($rebuilt->replyToEmail)->toBe('jane@example.com')
        ->and($rebuilt->palette)->toBeInstanceOf(EmailPalette::class)
        ->and($rebuilt->palette->accent)->toBe('#aa0000')
        ->and($rebuilt->palette->buttonBg)->toBe('#aa0000');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Mail/Branding/EmailBrandTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Mail\Branding;

/**
 * Immutable branding bundle for one email send. Pure data — no I/O.
 *
 * isPartna=true is the platform-branded variant (Partna logo/footer); false is a
 * professional's white-label brand. Cached as a primitive array (toArray) and
 * rebuilt on read (fromArray) so the shape can evolve without poisoning cached
 * blobs across deploys.
 */
final class EmailBrand
{
    public function __construct(
        public readonly bool $isPartna,
        public readonly string $proName,
        public readonly string $siteUrl,
        public readonly ?string $logoUrl,
        public readonly ?string $replyToEmail,
        public readonly EmailPalette $palette,
    ) {}

    public static function partna(): self
    {
        return new self(
            isPartna: true,
            proName: (string) config('mail.from.name', 'Partna'),
            siteUrl: 'https://partna.au',
            logoUrl: null,
            replyToEmail: null,
            palette: EmailBrandDefaults::defaults(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'isPartna' => $this->isPartna,
            'proName' => $this->proName,
            'siteUrl' => $this->siteUrl,
            'logoUrl' => $this->logoUrl,
            'replyToEmail' => $this->replyToEmail,
            'palette' => [
                'accent' => $this->palette->accent,
                'accentContrast' => $this->palette->accentContrast,
                'bg' => $this->palette->bg,
                'text' => $this->palette->text,
                'textMuted' => $this->palette->textMuted,
                'buttonBg' => $this->palette->buttonBg,
                'buttonText' => $this->palette->buttonText,
                'borderRadius' => $this->palette->borderRadius,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $p = $data['palette'] ?? [];

        return new self(
            isPartna: (bool) ($data['isPartna'] ?? true),
            proName: (string) ($data['proName'] ?? ''),
            siteUrl: (string) ($data['siteUrl'] ?? 'https://partna.au'),
            logoUrl: $data['logoUrl'] ?? null,
            replyToEmail: $data['replyToEmail'] ?? null,
            palette: new EmailPalette(
                accent: (string) ($p['accent'] ?? EmailBrandDefaults::ACCENT),
                accentContrast: (string) ($p['accentContrast'] ?? EmailBrandDefaults::ACCENT_CONTRAST),
                bg: (string) ($p['bg'] ?? EmailBrandDefaults::BG),
                text: (string) ($p['text'] ?? EmailBrandDefaults::TEXT),
                textMuted: (string) ($p['textMuted'] ?? EmailBrandDefaults::TEXT_MUTED),
                buttonBg: (string) ($p['buttonBg'] ?? EmailBrandDefaults::ACCENT),
                buttonText: (string) ($p['buttonText'] ?? EmailBrandDefaults::ACCENT_CONTRAST),
                borderRadius: (string) ($p['borderRadius'] ?? EmailBrandDefaults::BORDER_RADIUS),
            ),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Mail/Branding/EmailBrandTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Mail/Branding/EmailBrand.php tests/Unit/Mail/Branding/EmailBrandTest.php
git commit -m "feat(email): add EmailBrand DTO with partna() + cache serialization"
```

---

## Task 4: CacheKeyGenerator::emailBrand()

**Files:**
- Modify: `app/Services/Cache/CacheKeyGenerator.php`
- Test: `tests/Unit/Mail/Branding/EmailBrandCacheKeyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Cache\CacheKeyGenerator;

it('namespaces the email brand key by site id', function () {
    expect(CacheKeyGenerator::emailBrand('abc-123'))->toBe('site:abc-123:email_brand');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Mail/Branding/EmailBrandCacheKeyTest.php`
Expected: FAIL — `Method ...emailBrand does not exist`.

- [ ] **Step 3: Add the method**

Add to `app/Services/Cache/CacheKeyGenerator.php`, immediately after the `siteImages()` method (around line 78):

```php
    /**
     * Per-site cached email-branding bundle (logo, palette, reply-to).
     * Busted via SiteCacheService::invalidateSite().
     */
    public static function emailBrand(string $siteId): string
    {
        return "site:{$siteId}:email_brand";
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Mail/Branding/EmailBrandCacheKeyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Cache/CacheKeyGenerator.php tests/Unit/Mail/Branding/EmailBrandCacheKeyTest.php
git commit -m "feat(cache): add emailBrand cache key"
```

---

## Task 5: config + .env.example TTL

**Files:**
- Modify: `config/partna.php:1115-1122`
- Modify: `.env.example`

- [ ] **Step 1: Add the TTL to config**

In `config/partna.php`, inside `'cache' => ['ttls' => [ ... ]]` (after the `webhook_idempotency` line, ~line 1121), add:

```php
            'email_brand' => (int) env('PARTNA_CACHE_TTL_EMAIL_BRAND', 86400),                                 // 24h
```

- [ ] **Step 2: Add the env key to .env.example**

In `.env.example`, near the other `PARTNA_CACHE_TTL_*` keys (search for `PARTNA_CACHE_TTL_PUBLIC_PAYLOAD`; if none present, add under a "Cache TTLs" comment), add:

```dotenv
PARTNA_CACHE_TTL_EMAIL_BRAND=86400
```

- [ ] **Step 3: Verify config loads**

Run: `php artisan config:clear && php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('partna.cache.ttls.email_brand');"`
Expected: prints `86400`.

- [ ] **Step 4: Commit**

```bash
git add config/partna.php .env.example
git commit -m "feat(email): add email_brand cache TTL config"
```

---

## Task 6: ProEmailBrandResolver

**Files:**
- Create: `app/Mail/Branding/ProEmailBrandResolver.php`
- Test: `tests/Feature/Notifications/ProEmailBrandResolverTest.php`

Note: this test hits the DB (sites, design_kits, site_media), so it lives under `tests/Feature`. Use the project's existing factories for `Site`/`User`; write the `design_kits` row and `site_media` row with the DB builder shown.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\ProEmailBrandResolver;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush();
});

function makeProSite(array $userAttrs = []): Site
{
    $user = User::factory()->create(array_merge([
        'display_name' => 'Jane Doe',
        'handle' => 'jane',
        'handle_lc' => 'jane',
    ], $userAttrs));

    return Site::factory()->create(['user_id' => $user->id]);
}

it('resolves a pro brand from display_name, handle and design kit', function () {
    $site = makeProSite();
    DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $site->id)
        ->update(['color_accent' => '#aa0000']);

    $brand = app(ProEmailBrandResolver::class)->forSite($site->id);

    expect($brand)->toBeInstanceOf(EmailBrand::class)
        ->and($brand->isPartna)->toBeFalse()
        ->and($brand->proName)->toBe('Jane Doe')
        ->and($brand->siteUrl)->toBe('https://jane.partna.au')
        ->and($brand->palette->accent)->toBe('#aa0000')
        ->and($brand->palette->buttonBg)->toBe('#aa0000')   // derived
        ->and($brand->logoUrl)->toBeNull();
});

it('falls back to defaults when display_name/handle are missing', function () {
    $site = makeProSite(['display_name' => null, 'handle' => null, 'handle_lc' => null]);

    $brand = app(ProEmailBrandResolver::class)->forSite($site->id);

    expect($brand->proName)->toBe('the team')
        ->and($brand->siteUrl)->toBe('https://partna.au')
        ->and($brand->palette->accent)->toBe('#3a6efc');
});

it('uses a ready design-pool logo url when present', function () {
    $site = makeProSite();
    $media = SiteMedia::create([
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_DESIGN,
        'purpose' => SiteMedia::PURPOSE_LOGO_FULL,
        'is_active' => true,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'bucket' => 'media',
        'path' => 'design/logo.png',
    ]);
    $media->mediaVariants()->create([
        'variant_key' => 'md',
        'artifact_type' => 'webp',
        'disk' => 'media',
        'path' => 'design/logo-md.webp',
        'url' => 'https://media.partna.au/design/logo-md.webp',
    ]);

    $brand = app(ProEmailBrandResolver::class)->forSite($site->id);

    expect($brand->logoUrl)->toBe('https://media.partna.au/design/logo-md.webp');
});

it('returns the partna brand for an unknown site', function () {
    $brand = app(ProEmailBrandResolver::class)->forSite('00000000-0000-0000-0000-000000000000');
    expect($brand->isPartna)->toBeTrue();
});

it('forget() clears the cached brand', function () {
    $site = makeProSite();
    $resolver = app(ProEmailBrandResolver::class);
    $resolver->forSite($site->id); // primes cache

    $resolver->forget($site->id);

    expect(Cache::get(\App\Services\Cache\CacheKeyGenerator::emailBrand($site->id)))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Notifications/ProEmailBrandResolverTest.php`
Expected: FAIL — class `ProEmailBrandResolver` not found.

- [ ] **Step 3: Write the resolver**

```php
<?php

namespace App\Mail\Branding;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a per-site EmailBrand (pro name, logo, palette, reply-to) for
 * white-label visitor emails. The only DB/cache-touching unit in the branding
 * stack — everything downstream is pure data + rendering.
 *
 * Cached per site through CacheLockService::rememberLocked (single-flight +
 * jitter + SWR), so a broadcast to N recipients of one pro resolves once.
 * Invalidated via SiteCacheService::invalidateSite (see SiteCacheService).
 */
class ProEmailBrandResolver
{
    public function __construct(private readonly CacheLockService $cacheLock) {}

    public function partna(): EmailBrand
    {
        return EmailBrand::partna();
    }

    public function forSite(string $siteId): EmailBrand
    {
        $key = CacheKeyGenerator::emailBrand($siteId);
        $ttl = (int) config('partna.cache.ttls.email_brand', 86400);

        $payload = $this->cacheLock->rememberLocked(
            $key,
            $ttl,
            fn (): array => $this->build($siteId)->toArray(),
        );

        return EmailBrand::fromArray($payload);
    }

    public function forget(string $siteId): void
    {
        $key = CacheKeyGenerator::emailBrand($siteId);
        Cache::deleteMultiple([$key, $key.':stale']);
    }

    private function build(string $siteId): EmailBrand
    {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            return EmailBrand::partna();
        }

        $user = $site->user_id ? User::query()->find($site->user_id) : null;

        $proName = trim((string) ($user->display_name ?? '')) ?: 'the team';
        $siteUrl = ($user && $user->handle)
            ? 'https://'.$user->handle.'.partna.au'
            : 'https://partna.au';

        $kit = (array) (DB::connection('pgsql')
            ->table('site.design_kits')
            ->where('site_id', $siteId)
            ->first() ?? []);

        return new EmailBrand(
            isPartna: false,
            proName: $proName,
            siteUrl: $siteUrl,
            logoUrl: $this->resolveLogoUrl($siteId),
            replyToEmail: $this->resolveReplyTo($siteId, $user),
            palette: EmailBrandDefaults::palette($kit),
        );
    }

    /** Prefer logo_full over logo_square; only ready, active design-pool media. */
    private function resolveLogoUrl(string $siteId): ?string
    {
        $media = SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->whereIn('purpose', [SiteMedia::PURPOSE_LOGO_FULL, SiteMedia::PURPOSE_LOGO_SQUARE])
            ->where('is_active', true)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->with('mediaVariants')
            ->orderByRaw("case purpose when '".SiteMedia::PURPOSE_LOGO_FULL."' then 0 else 1 end")
            ->first();

        if ($media === null) {
            return null;
        }

        $urls = $media->variantUrls();
        $url = $urls === [] ? null : (string) reset($urls);

        return ($url !== null && $this->isSafeLogoUrl($url)) ? $url : null;
    }

    /** Defence-in-depth: https only, and (if configured) from the media host. */
    private function isSafeLogoUrl(string $url): bool
    {
        if (! str_starts_with($url, 'https://')) {
            return false;
        }

        $disk = (string) config('partna.media_disk');
        $base = (string) config("filesystems.disks.{$disk}.url", '');
        if ($base === '') {
            return true; // no configured host to assert against
        }

        $expectedHost = parse_url($base, PHP_URL_HOST);
        $actualHost = parse_url($url, PHP_URL_HOST);

        return $expectedHost === null || $expectedHost === $actualHost;
    }

    /** Contact-block inbox, else account email, else null (→ Partna default). */
    private function resolveReplyTo(string $siteId, ?User $user): ?string
    {
        $block = Block::query()
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', 'contact')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        $email = $block ? trim((string) data_get($block->settings, 'notification_email', '')) : '';
        if ($email !== '') {
            return $email;
        }

        $account = trim((string) ($user->email ?? ''));

        return $account !== '' ? $account : null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Notifications/ProEmailBrandResolverTest.php`
Expected: PASS. (If `Site::factory()` does not auto-create a `design_kits` row in the test DB, insert one first with `DB::connection('pgsql')->table('site.design_kits')->insert(['site_id' => $site->id]);` in `makeProSite()` — the production trigger `trg_create_empty_design_kit` does this automatically.)

- [ ] **Step 5: Commit**

```bash
git add app/Mail/Branding/ProEmailBrandResolver.php tests/Feature/Notifications/ProEmailBrandResolverTest.php
git commit -m "feat(email): add ProEmailBrandResolver with cached per-site brand"
```

---

## Task 7: Add email_brand to SiteCacheService::invalidateSite()

**Files:**
- Modify: `app/Services/Cache/SiteCacheService.php:503-510`
- Test: `tests/Feature/Notifications/EmailBrandInvalidationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

it('forgets the email_brand key (and :stale) on invalidateSite', function () {
    $user = User::factory()->create(['handle' => 'jane', 'handle_lc' => 'jane']);
    $site = Site::factory()->create(['user_id' => $user->id]);

    $key = CacheKeyGenerator::emailBrand($site->id);
    Cache::put($key, ['marker' => 1], 600);
    Cache::put($key.':stale', ['marker' => 1], 600);

    app(SiteCacheService::class)->invalidateSite($site);

    expect(Cache::get($key))->toBeNull()
        ->and(Cache::get($key.':stale'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Notifications/EmailBrandInvalidationTest.php`
Expected: FAIL — `email_brand` key still present after invalidateSite.

- [ ] **Step 3: Add the key**

In `app/Services/Cache/SiteCacheService.php`, inside the `$keys = [ ... ]` array in `invalidateSite()` (after the `siteImages` line, ~line 509), add:

```php
            // White-label email branding bundle (logo, palette, reply-to). Same SWR
            // contract as the payload keys — bust both primary and :stale.
            ...self::bustWithStale(CacheKeyGenerator::emailBrand($site->id)),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Notifications/EmailBrandInvalidationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Cache/SiteCacheService.php tests/Feature/Notifications/EmailBrandInvalidationTest.php
git commit -m "feat(cache): bust email_brand on site invalidation"
```

---

## Task 8: Fix design-kit write invalidation timing in UserSiteController

**Files:**
- Modify: `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php:36-56`
- Test: `tests/Feature/Notifications/DesignKitWriteInvalidatesBrandTest.php`

The design-kit write goes through `writeDesignKit()` (raw `DB::update`) AFTER `execute()` already fired `invalidateSite`, so the bust precedes the kit write. Add one post-write `invalidateSite` so the freshly-written kit is reflected on the next brand resolve.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Mail\Branding\ProEmailBrandResolver;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => Cache::flush());

it('reflects a design-kit-only update in the next resolved brand', function () {
    $user = User::factory()->create([
        'display_name' => 'Jane Doe', 'handle' => 'jane', 'handle_lc' => 'jane',
    ]);
    $site = Site::factory()->create(['user_id' => $user->id]);

    // Prime the brand cache with the default accent.
    $first = app(ProEmailBrandResolver::class)->forSite($site->id);
    expect($first->palette->accent)->toBe('#3a6efc');

    // Simulate the controller's design-kit write path + the post-write bust.
    DB::connection('pgsql')->table('site.design_kits')
        ->where('site_id', $site->id)->update(['color_accent' => '#aa0000']);
    app(\App\Services\Cache\SiteCacheService::class)->invalidateSite($site->fresh());

    $second = app(ProEmailBrandResolver::class)->forSite($site->id);
    expect($second->palette->accent)->toBe('#aa0000');
});
```

- [ ] **Step 2: Run test to verify it passes the cache-busting assertion only after the fix**

Run: `vendor/bin/pest tests/Feature/Notifications/DesignKitWriteInvalidatesBrandTest.php`
Expected: PASS already (this test simulates the corrected sequence). It guards the behavior; the controller change below makes the real endpoint match it.

- [ ] **Step 3: Add the post-write invalidation to the controller**

In `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php`, change the `update()` method's design-kit branch (lines 51-53) from:

```php
        if (is_array($designKit)) {
            $this->writeDesignKit($site->id, $designKit);
        }
```

to:

```php
        if (is_array($designKit)) {
            $this->writeDesignKit($site->id, $designKit);
            // execute() already fired invalidateSite via $site->save(), but that
            // ran BEFORE the raw design_kits write above — bust again so the new
            // kit (and the email-brand bundle that reads it) is reflected.
            app(\App\Services\Cache\SiteCacheService::class)->invalidateSite($site);
        }
```

- [ ] **Step 4: Run the controller's existing site-update tests to confirm no regression**

Run: `vendor/bin/pest --filter=UserSite`
Expected: PASS (no regressions in existing site-update coverage).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php tests/Feature/Notifications/DesignKitWriteInvalidatesBrandTest.php
git commit -m "fix(cache): invalidate site after design-kit write (brand + payload freshness)"
```

---

## Task 9: Brand-aware shared layout

**Files:**
- Modify: `resources/views/mail/layouts/partna.blade.php`
- Test: `tests/Feature/Notifications/BrandedLayoutRenderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\EmailBrandDefaults;

it('renders pro logo + accent button in white-label mode', function () {
    $brand = new EmailBrand(
        isPartna: false,
        proName: 'Jane Doe',
        siteUrl: 'https://jane.partna.au',
        logoUrl: 'https://media.partna.au/logo.webp',
        replyToEmail: null,
        palette: EmailBrandDefaults::palette(['color_accent' => '#aa0000', 'button_primary_bg' => '#aa0000']),
    );

    $html = view('mail.layouts.partna', ['brand' => $brand])
        ->with('__test_content', 'BODY')
        ->render();

    expect($html)->toContain('https://media.partna.au/logo.webp')
        ->and($html)->toContain('sent via Partna')
        ->and($html)->not->toContain('email-wordmark.png'); // Partna wordmark hidden
});

it('falls back to Partna branding when no brand is passed', function () {
    $html = view('mail.layouts.partna')->render();

    expect($html)->toContain('email-wordmark.png')
        ->and($html)->not->toContain('sent via Partna');
});
```

Note: the layout uses `@yield('content')`; for the render test we don't have a child view, so the assertions target header/footer only. The `->with('__test_content', ...)` call is inert (ignored by the layout) and present only to keep the test explicit about intent.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Notifications/BrandedLayoutRenderTest.php`
Expected: FAIL — current layout has no `$brand` handling; `sent via Partna` absent; logo not rendered.

- [ ] **Step 3: Edit the layout**

In `resources/views/mail/layouts/partna.blade.php`:

(a) At the very top of the file (before `<!DOCTYPE`), add a brand default so the layout is safe when no brand is passed:

```blade
@php($brand = $brand ?? \App\Mail\Branding\EmailBrand::partna())
```

(b) Replace the header block (the `<tr>` containing the Partna icon + wordmark, lines ~68-83) with:

```blade
                    {{-- Header: pro logo / wordmark in white-label mode, Partna assets otherwise. --}}
                    <tr>
                        <td class="px-gutter" align="left" style="padding: 8px 40px 40px 40px;">
                            @if (! $brand->isPartna && $brand->logoUrl)
                                <a href="{{ $brand->siteUrl }}" style="text-decoration:none;">
                                    <img src="{{ $brand->logoUrl }}" alt="{{ $brand->proName }}" height="32" style="display:block; max-height:48px; border:0; outline:none;">
                                </a>
                            @elseif (! $brand->isPartna)
                                <a href="{{ $brand->siteUrl }}" style="text-decoration:none;">
                                    <span style="font-size:20px; font-weight:600; color:{{ $brand->palette->text }};">{{ $brand->proName }}</span>
                                </a>
                            @else
                                <a href="https://app.partna.au" style="text-decoration:none;">
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                        <tr>
                                            <td valign="middle" style="line-height:0;">
                                                <img src="https://app.partna.au/branding/Partna/email-icon.png" alt="" width="20" height="20" style="display:block; width:20px; height:20px; border:0; outline:none;">
                                            </td>
                                            <td valign="middle" style="line-height:0; padding-left:14px;">
                                                <img src="https://app.partna.au/branding/Partna/email-wordmark.png" alt="Partna" width="76" height="20" style="display:block; width:76px; height:20px; border:0; outline:none;">
                                            </td>
                                        </tr>
                                    </table>
                                </a>
                            @endif
                        </td>
                    </tr>
```

(c) Set the body background + default text color from the palette. Change the outer `<body class="bg-body" style="...background-color:#ffffff;...">` and the two `background-color:#ffffff;` table attributes (lines ~53, 60, 64 area) to use `{{ $brand->palette->bg }}` in place of `#ffffff` on the `<body>` tag and the outermost wrapper `<table ... style="background-color:#ffffff;">`. Leave the `@media (prefers-color-scheme: dark)` block untouched.

(d) Replace the footer block (lines ~93-106) with:

```blade
                    {{-- Footer --}}
                    <tr>
                        <td class="px-gutter" align="left" style="padding: 24px 40px 8px 40px; border-top: 1px solid #f0f0f2;">
                            @if ($brand->isPartna)
                                <p style="margin: 0 0 8px 0; font-size: 12px; line-height: 1.5; color:#86868b;">
                                    {{ config('mail.from.name', 'Partna') }} ·
                                    <a href="https://partna.au" style="color:#86868b; text-decoration:none;">partna.au</a> ·
                                    <a href="mailto:{{ config('mail.from.address', 'hello@partna.au') }}" style="color:#86868b; text-decoration:none;">{{ config('mail.from.address', 'hello@partna.au') }}</a>
                                </p>
                                <p style="margin: 0; font-size: 11px; line-height: 1.5; color:#a1a1a6;">
                                    @yield('footer_note')
                                    @hasSection('footer_note') &nbsp;·&nbsp; @endif
                                    You're receiving this because you have an account at Partna.
                                </p>
                            @else
                                <p style="margin: 0 0 6px 0; font-size: 12px; line-height: 1.5; color:#86868b;">
                                    {{ $brand->proName }} &nbsp;·&nbsp; sent via <a href="https://partna.au" style="color:#86868b; text-decoration:none;">Partna</a>
                                </p>
                                @hasSection('footer_note')
                                    <p style="margin: 0; font-size: 11px; line-height: 1.5; color:#a1a1a6;">@yield('footer_note')</p>
                                @endif
                            @endif
                        </td>
                    </tr>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Notifications/BrandedLayoutRenderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/mail/layouts/partna.blade.php tests/Feature/Notifications/BrandedLayoutRenderTest.php
git commit -m "feat(email): make shared mail layout brand-aware"
```

---

## Task 10: EnquiryConfirmationMail takes EmailBrand + template extends layout

**Files:**
- Modify: `app/Mail/EnquiryConfirmationMail.php`
- Modify: `resources/views/emails/enquiry-confirmation.blade.php`
- Test: `tests/Feature/Notifications/EnquiryConfirmationMailTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\EnquiryConfirmationMail;

it('renders the enquiry confirmation with pro name, accent button and reply-to', function () {
    $brand = new EmailBrand(
        isPartna: false,
        proName: 'Jane Doe',
        siteUrl: 'https://jane.partna.au',
        logoUrl: null,
        replyToEmail: 'jane@example.com',
        palette: EmailBrandDefaults::palette(['color_accent' => '#aa0000', 'button_primary_bg' => '#aa0000']),
    );

    $mail = (new EnquiryConfirmationMail(
        brand: $brand,
        visitorName: 'Sam',
        subject: 'A new project',
    ))->build();

    $rendered = $mail->render();

    expect($rendered)->toContain('Jane Doe')
        ->and($rendered)->toContain('Sam')
        ->and($rendered)->toContain('A new project')
        ->and($rendered)->toContain('#aa0000')             // accent button
        ->and($rendered)->toContain('jane.partna.au');

    $mail->assertHasSubject('We received your enquiry — Jane Doe');
    $mail->assertHasReplyTo('jane@example.com', 'Jane Doe');
    $mail->assertFrom(config('mail.from.address', 'hello@partna.au'), 'Jane Doe');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Notifications/EnquiryConfirmationMailTest.php`
Expected: FAIL — constructor signature mismatch (`brand` arg does not exist yet).

- [ ] **Step 3a: Rewrite the Mailable**

Replace `app/Mail/EnquiryConfirmationMail.php` with:

```php
<?php

namespace App\Mail;

use App\Mail\Branding\EmailBrand;

// Visitor-facing "we received your enquiry" receipt, white-labelled to the
// professional via EmailBrand. From shows the pro's name (Partna sending domain);
// Reply-To is the pro's contact inbox so a visitor reply reaches them directly.
// Tier-2 transactional email: not registered in config('partna.notifications.mailables').
class EnquiryConfirmationMail extends BaseTransactionalMail
{
    // Mailable::$subject is non-readonly, so we cannot promote a "subject" arg
    // as readonly — keep the form subject under a distinct name.
    public readonly string $enquirySubject;

    public function __construct(
        public readonly EmailBrand $brand,
        public readonly string $visitorName,
        string $subject,
    ) {
        $this->enquirySubject = $subject;
    }

    public function build(): self
    {
        $this->buildEnvelope()
            ->from(config('mail.from.address', 'hello@partna.au'), $this->brand->proName)
            ->subject("We received your enquiry — {$this->brand->proName}")
            ->view('emails.enquiry-confirmation', [
                'brand' => $this->brand,
                'proDisplayName' => $this->brand->proName,
                'visitorName' => $this->visitorName,
                'subject' => $this->enquirySubject,
                'siteUrl' => $this->brand->siteUrl,
            ]);

        // Replace the Partna default reply-to with the pro inbox when present.
        if ($this->brand->replyToEmail !== null && trim($this->brand->replyToEmail) !== '') {
            $this->replyTo = [];
            $this->replyTo(trim($this->brand->replyToEmail), $this->brand->proName);
        }

        return $this;
    }
}
```

- [ ] **Step 3b: Rewrite the template to extend the layout**

Replace `resources/views/emails/enquiry-confirmation.blade.php` with:

```blade
@extends('mail.layouts.partna')

@section('preheader'){{ $proDisplayName }} has received your enquiry and will reply soon.@endsection

@section('content')
    <h1 class="headline text-primary" style="margin:0 0 16px; font-size:24px; line-height:1.2; color:{{ $brand->palette->text }};">
        Thanks{{ $visitorName !== '' ? ', '.e($visitorName) : '' }} — we've got your enquiry
    </h1>

    <p class="body-text" style="margin:0 0 12px; font-size:15px; line-height:1.5; color:{{ $brand->palette->text }};">
        {{ $proDisplayName }} has received your message about &ldquo;{{ $subject }}&rdquo; and will get back to you soon.
    </p>

    <p class="body-text" style="margin:0 0 12px; font-size:15px; line-height:1.5; color:{{ $brand->palette->textMuted }};">
        You can reply directly to this email if you need to add anything.
    </p>

    <p class="button-cell" style="margin:24px 0 0;">
        <a href="{{ $siteUrl }}" style="display:inline-block; background:{{ $brand->palette->buttonBg }}; color:{{ $brand->palette->buttonText }}; padding:12px 22px; border-radius:{{ $brand->palette->borderRadius }}; font-size:15px; font-weight:600; text-decoration:none;">
            Visit {{ $proDisplayName }}'s page
        </a>
    </p>
@endsection
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Notifications/EnquiryConfirmationMailTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Mail/EnquiryConfirmationMail.php resources/views/emails/enquiry-confirmation.blade.php tests/Feature/Notifications/EnquiryConfirmationMailTest.php
git commit -m "feat(email): white-label EnquiryConfirmationMail via EmailBrand"
```

---

## Task 11: SubscriptionConfirmationMail takes EmailBrand + template extends layout

**Files:**
- Modify: `app/Mail/SubscriptionConfirmationMail.php`
- Modify: `resources/views/emails/subscription-confirmation.blade.php`
- Test: `tests/Feature/Notifications/SubscriptionConfirmationMailTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\EmailBrandDefaults;
use App\Mail\SubscriptionConfirmationMail;

it('renders the subscription confirmation with unsubscribe + one-click headers', function () {
    $brand = new EmailBrand(
        isPartna: false,
        proName: 'Jane Doe',
        siteUrl: 'https://jane.partna.au',
        logoUrl: null,
        replyToEmail: 'jane@example.com',
        palette: EmailBrandDefaults::palette(['color_accent' => '#aa0000', 'button_primary_bg' => '#aa0000']),
    );

    $mail = (new SubscriptionConfirmationMail(
        brand: $brand,
        unsubscribeUrl: 'https://jane.partna.au/unsubscribe/tok',
        visitorName: 'Sam',
    ))->build();

    $rendered = $mail->render();

    expect($rendered)->toContain('Jane Doe')
        ->and($rendered)->toContain('Sam')
        ->and($rendered)->toContain('https://jane.partna.au/unsubscribe/tok')
        ->and($rendered)->toContain('#aa0000');

    $mail->assertHasSubject("You're subscribed — Jane Doe");
    $mail->assertHasReplyTo('jane@example.com', 'Jane Doe');
    $mail->assertFrom(config('mail.from.address', 'hello@partna.au'), 'Jane Doe');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Notifications/SubscriptionConfirmationMailTest.php`
Expected: FAIL — constructor signature mismatch.

- [ ] **Step 3a: Rewrite the Mailable**

Replace `app/Mail/SubscriptionConfirmationMail.php` with:

```php
<?php

namespace App\Mail;

use App\Mail\Branding\EmailBrand;

// Visitor-facing "you're subscribed" receipt, white-labelled to the professional
// via EmailBrand. Carries the unsubscribe link + RFC 8058 one-click headers.
// Tier-2 transactional email: not registered in config('partna.notifications.mailables').
class SubscriptionConfirmationMail extends BaseTransactionalMail
{
    public function __construct(
        public readonly EmailBrand $brand,
        public readonly string $unsubscribeUrl,
        public readonly ?string $visitorName = null,
    ) {}

    public function build(): self
    {
        $this->buildEnvelope()
            ->from(config('mail.from.address', 'hello@partna.au'), $this->brand->proName)
            ->subject("You're subscribed — {$this->brand->proName}")
            ->view('emails.subscription-confirmation', [
                'brand' => $this->brand,
                'proDisplayName' => $this->brand->proName,
                'siteUrl' => $this->brand->siteUrl,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'visitorName' => $this->visitorName,
            ])
            ->withSymfonyMessage(function ($message): void {
                // RFC 8058 one-click unsubscribe — required by Gmail/Yahoo bulk rules.
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });

        if ($this->brand->replyToEmail !== null && trim($this->brand->replyToEmail) !== '') {
            $this->replyTo = [];
            $this->replyTo(trim($this->brand->replyToEmail), $this->brand->proName);
        }

        return $this;
    }
}
```

- [ ] **Step 3b: Rewrite the template to extend the layout**

Replace `resources/views/emails/subscription-confirmation.blade.php` with:

```blade
@extends('mail.layouts.partna')

@section('preheader')You're subscribed to {{ $proDisplayName }}'s updates.@endsection

@section('footer_note')
    Didn't sign up, or changed your mind? <a href="{{ $unsubscribeUrl }}" style="color:#a1a1a6; text-decoration:underline;">Unsubscribe</a>.
@endsection

@section('content')
    <h1 class="headline text-primary" style="margin:0 0 16px; font-size:24px; line-height:1.2; color:{{ $brand->palette->text }};">
        You're subscribed{{ $visitorName ? ', '.e($visitorName) : '' }}
    </h1>

    <p class="body-text" style="margin:0 0 12px; font-size:15px; line-height:1.5; color:{{ $brand->palette->text }};">
        Thanks for joining {{ $proDisplayName }}'s list. You'll hear about news and updates straight from them.
    </p>

    <p class="button-cell" style="margin:24px 0 0;">
        <a href="{{ $siteUrl }}" style="display:inline-block; background:{{ $brand->palette->buttonBg }}; color:{{ $brand->palette->buttonText }}; padding:12px 22px; border-radius:{{ $brand->palette->borderRadius }}; font-size:15px; font-weight:600; text-decoration:none;">
            Visit {{ $proDisplayName }}'s page
        </a>
    </p>
@endsection
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Notifications/SubscriptionConfirmationMailTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Mail/SubscriptionConfirmationMail.php resources/views/emails/subscription-confirmation.blade.php tests/Feature/Notifications/SubscriptionConfirmationMailTest.php
git commit -m "feat(email): white-label SubscriptionConfirmationMail via EmailBrand"
```

---

## Task 12: Wire SendEnquiryConfirmationJob to resolve the brand

**Files:**
- Modify: `app/Jobs/Notifications/SendEnquiryConfirmationJob.php:85-96`
- Test: `tests/Feature/Notifications/SendEnquiryConfirmationJobBrandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use App\Mail\EnquiryConfirmationMail;
use App\Mail\Branding\ProEmailBrandResolver;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

it('sends a brand-resolved enquiry confirmation', function () {
    Mail::fake();
    $user = User::factory()->create(['display_name' => 'Jane Doe', 'handle' => 'jane', 'handle_lc' => 'jane']);
    $site = Site::factory()->create(['user_id' => $user->id]);
    $enquiry = Enquiry::factory()->create([
        'user_id' => $user->id,
        'site_id' => $site->id,
        'email' => 'sam@example.com',
        'name' => 'Sam',
        'subject' => 'Hello',
        'confirmation_sent_at' => null,
    ]);

    (new SendEnquiryConfirmationJob($enquiry->id))->handle();

    Mail::assertSent(EnquiryConfirmationMail::class, function (EnquiryConfirmationMail $m) {
        return $m->brand->proName === 'Jane Doe' && $m->visitorName === 'Sam';
    });
    expect($enquiry->fresh()->confirmation_sent_at)->not->toBeNull();
});

it('falls back to the Partna brand (and still sends + logs) when resolution throws', function () {
    Mail::fake();
    $user = User::factory()->create(['display_name' => 'Jane Doe', 'handle' => 'jane', 'handle_lc' => 'jane']);
    $site = Site::factory()->create(['user_id' => $user->id]);
    $enquiry = Enquiry::factory()->create([
        'user_id' => $user->id, 'site_id' => $site->id,
        'email' => 'sam@example.com', 'name' => 'Sam', 'subject' => 'Hello',
        'confirmation_sent_at' => null,
    ]);

    Log::spy();
    $this->mock(ProEmailBrandResolver::class, function ($mock) {
        $mock->shouldReceive('forSite')->andThrow(new RuntimeException('boom'));
        $mock->shouldReceive('partna')->andReturn(\App\Mail\Branding\EmailBrand::partna());
    });

    (new SendEnquiryConfirmationJob($enquiry->id))->handle();

    Mail::assertSent(EnquiryConfirmationMail::class, fn (EnquiryConfirmationMail $m) => $m->brand->isPartna === true);
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => str_contains((string) $msg, 'email brand') && ($ctx['site_id'] ?? null) === $site->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Notifications/SendEnquiryConfirmationJobBrandTest.php`
Expected: FAIL — job still builds the Mailable with the old constructor (no `brand`).

- [ ] **Step 3: Edit the job**

In `app/Jobs/Notifications/SendEnquiryConfirmationJob.php`:

(a) Add imports at the top (after the existing `use App\Mail\EnquiryConfirmationMail;`):

```php
use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\ProEmailBrandResolver;
```

(b) Replace the brand/recipient send block (current lines 85-96, which compute `$user`, `$proName`, `$siteUrl`, `$replyTo` and send) with:

```php
        // Resolve the white-label brand AFTER the idempotency transaction has
        // committed — never inside the lockForUpdate hold above. A branding
        // failure must never drop a transactional email, so fall back to Partna.
        $resolver = app(ProEmailBrandResolver::class);
        try {
            $brand = $resolver->forSite((string) $enquiry->site_id);
        } catch (\Throwable $e) {
            Log::warning('email brand resolve failed; falling back to Partna brand', [
                'site_id' => (string) $enquiry->site_id,
                'error' => $e->getMessage(),
            ]);
            $brand = $resolver->partna();
        }

        Mail::to($recipient)->send(new EnquiryConfirmationMail(
            brand: $brand,
            visitorName: trim((string) ($enquiry->name ?? '')),
            subject: (string) $enquiry->subject,
        ));
```

(Leave the `$block` toggle check, the rate-limit call, and the `confirmation_sent_at` write exactly as they are.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Notifications/SendEnquiryConfirmationJobBrandTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Notifications/SendEnquiryConfirmationJob.php tests/Feature/Notifications/SendEnquiryConfirmationJobBrandTest.php
git commit -m "feat(email): resolve white-label brand in SendEnquiryConfirmationJob"
```

---

## Task 13: Wire SendSubscriptionConfirmationJob to resolve the brand

**Files:**
- Modify: `app/Jobs/Notifications/SendSubscriptionConfirmationJob.php:94-103`
- Test: `tests/Feature/Notifications/SendSubscriptionConfirmationJobBrandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\Notifications\SendSubscriptionConfirmationJob;
use App\Mail\SubscriptionConfirmationMail;
use App\Models\Core\Notifications\EmailSubscription;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Mail;

it('sends a brand-resolved subscription confirmation', function () {
    Mail::fake();
    $user = User::factory()->create(['display_name' => 'Jane Doe', 'handle' => 'jane', 'handle_lc' => 'jane']);
    Site::factory()->create(['user_id' => $user->id]);
    $sub = EmailSubscription::factory()->create([
        'user_id' => $user->id,
        'email' => 'sam@example.com',
        'full_name' => 'Sam',
        'status' => 'subscribed',
        'confirmation_sent_at' => null,
    ]);

    (new SendSubscriptionConfirmationJob($sub->id))->handle();

    Mail::assertSent(SubscriptionConfirmationMail::class, function (SubscriptionConfirmationMail $m) {
        return $m->brand->proName === 'Jane Doe'
            && $m->visitorName === 'Sam'
            && str_contains($m->unsubscribeUrl, 'unsubscribe');
    });
    expect($sub->fresh()->confirmation_sent_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/Notifications/SendSubscriptionConfirmationJobBrandTest.php`
Expected: FAIL — old Mailable constructor (`proDisplayName`/`siteUrl` args).

- [ ] **Step 3: Edit the job**

In `app/Jobs/Notifications/SendSubscriptionConfirmationJob.php`:

(a) Add imports after `use App\Mail\SubscriptionConfirmationMail;`:

```php
use App\Mail\Branding\EmailBrand;
use App\Mail\Branding\ProEmailBrandResolver;
```

(b) Replace the send block (current lines 94-103, which compute `$proName`/`$siteUrl` and send) with:

```php
        $unsubscribeUrl = route('public.unsubscribe', ['token' => $sub->unsubscribe_token]);

        // Resolve the white-label brand outside any DB lock; fall back to Partna
        // on failure so a branding error never drops the confirmation.
        $resolver = app(ProEmailBrandResolver::class);
        $siteId = ($user && $user->site) ? (string) $user->site->id : null;
        try {
            $brand = $siteId !== null ? $resolver->forSite($siteId) : $resolver->partna();
        } catch (\Throwable $e) {
            Log::warning('email brand resolve failed; falling back to Partna brand', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
            $brand = $resolver->partna();
        }

        Mail::to($recipient)->send(new SubscriptionConfirmationMail(
            brand: $brand,
            unsubscribeUrl: $unsubscribeUrl,
            visitorName: $sub->full_name ?: null,
        ));
```

(Leave the status check, the `$block` toggle check, the rate-limit call, and the `confirmation_sent_at` write exactly as they are. The `$user` variable is already loaded above as `$sub->user`.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/Notifications/SendSubscriptionConfirmationJobBrandTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/Notifications/SendSubscriptionConfirmationJob.php tests/Feature/Notifications/SendSubscriptionConfirmationJobBrandTest.php
git commit -m "feat(email): resolve white-label brand in SendSubscriptionConfirmationJob"
```

---

## Task 14: Full-suite regression + style

- [ ] **Step 1: Run the branding + notifications tests together**

Run: `vendor/bin/pest tests/Unit/Mail/Branding tests/Feature/Notifications`
Expected: all PASS.

- [ ] **Step 2: Run the existing visitor-confirmation + capability sweep tests**

Run: `vendor/bin/pest --filter="Confirmation|Capability"`
Expected: PASS (idempotency, rate-limit, toggle, and the capability sweep that exempts the visitor-confirmation jobs still hold).

- [ ] **Step 3: Pint on changed files only**

Run (scoped to this feature's files — the repo baseline is not pint-clean, so do NOT run a repo-wide fix):
```bash
vendor/bin/pint app/Mail/Branding app/Mail/EnquiryConfirmationMail.php app/Mail/SubscriptionConfirmationMail.php app/Jobs/Notifications/SendEnquiryConfirmationJob.php app/Jobs/Notifications/SendSubscriptionConfirmationJob.php app/Services/Cache/CacheKeyGenerator.php app/Services/Cache/SiteCacheService.php app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php
```
Expected: clean or auto-fixed; review the diff.

- [ ] **Step 4: Commit any style fixes**

```bash
git add -A
git commit -m "style(email): pint on design-kit email branding files"
```

---

## Self-Review notes (author)

- **Spec coverage:** resolver+cache (T6), `rememberLocked` (T6), primitive-array payload (T3), centralized invalidation key (T7), design-kit write timing fix (T8), 6-static/2-derived defaults + guard (T2), brand-aware layout + template refactor (T9-11), sender "pro name only" + reply-to consolidation (T10-11), transaction-boundary + Partna fallback + structured warning (T12-13), security escaping via Blade `{{ }}`/`e()` and https/host logo assertion (T9 layout `<img>`, T6 `isSafeLogoUrl`), config + `.env.example` (T5). All spec sections map to a task.
- **No new observer:** logo (SiteMediaObserver) and contact-block (BlockObserver) invalidation ride the existing touch→SiteObserver→invalidateSite chain, covered by T7.
- **Open items carried from spec:** the logo variant selected is `reset($urls)` (first webp variant) — revisit if a size-specific key is wanted; the host allowlist uses the configured media disk URL host.
