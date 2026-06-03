# Design-Kit Email Branding — Design Spec

**Date:** 2026-05-30
**Status:** Approved (brainstorm) — pending spec review → implementation plan
**Author:** Josh + Claude

## Problem

Professionals on Partna have a per-site design kit (colors, typography, etc.) and
optional logos. Today, the visitor-facing confirmation emails they trigger
(enquiry confirmation, subscription confirmation) are **un-branded**: bare
standalone HTML with a hardcoded Partna-blue link (`#3a6efc`), no logo, no per-pro
colors, and they don't even use the polished shared mail layout that the rest of
the transactional emails use.

We want these visitor-facing emails to feel like they come from the
**professional** (white-label), driven by the professional's design kit + logo —
built as a durable foundation that any future pro-originated email can reuse
without rework, and that scales (no per-send N+1) as volume grows.

## Goals

- White-label feel for **pro-originated, visitor-facing** emails.
- A single, reusable branding layer — new pro emails are "resolve brand → extend
  layout," no new plumbing.
- Scale-safe: branding resolution is cached per site; broadcasts to many
  recipients of one pro resolve the brand once.
- Graceful degradation: a missing kit, missing logo, or branding failure never
  produces a blank email and never drops a transactional send.
- No disturbance to the existing delivery guarantees (idempotency, rate-limiting,
  per-block toggle, UUID-only job payloads, header-injection defence).

## Non-goals (YAGNI)

- Full white-label on a custom sending domain (per-pro DKIM/SPF). Deferred.
- A per-pro "disable branding" toggle. Branding is simply on for in-scope emails.
- Best-effort web fonts in email. Excluded from the contract (clients fall back to
  system fonts anyway); can be added later as an additive field.
- Branding Partna's own platform/auth/staff/moderation emails (they stay Partna).

## Decisions (from brainstorm)

| Decision | Choice |
|---|---|
| **Identity model** | White-label (pro-branded): pro logo/name + palette; Partna shrinks to a "sent via Partna" footer line. |
| **Sender identity** | From shows the **pro's name only** (`Jane Doe <hello@partna.au>`); envelope/domain stays Partna for DKIM/SPF/DMARC alignment. Reply-To = pro inbox. Gmail/Apple may auto-append "via partna.au" — expected and acceptable. |
| **Logo source** | `site.site_media`, `pool='design'`, `purpose ∈ {logo_full, logo_square}`, `is_active`, `processing_state=ready`; URL via `variantUrls()`. Prefer `logo_full`, fall back `logo_square`, else text wordmark of `display_name`. |
| **Branding scope** | White-label = pro-originated visitor-facing emails (enquiry confirmation, subscription confirmation, future pro newsletter). Everything Partna-originated stays Partna-branded. Rule: "who is this email from?" decides. |
| **Token scope** | 8 email-safe tokens (colors + border-radius). Fonts excluded. |
| **Defaults** | A documented PHP mirror of the email-relevant subset of `@partnaau/design-system` defaults, with a CI guard. |
| **Architecture** | Approach A — resolver + immutable value object + brand-aware shared layout, cached. |

## Architecture (Approach A)

Five units, one-way dependencies, no cycles. Only the resolver touches the DB.

```
  Job (send-time) ──▶ ProEmailBrandResolver.forSite(siteId) ──▶ EmailBrand (DTO)
                          - cache get/put                          (+ EmailBrandDefaults)
                          - reads design_kit row                        │
                          - reads logo media                            │ passed into
                          - merges over defaults                        ▼
                                                          Mailable ──▶ layouts/partna.blade.php
                                                          (dumb)        (brand-aware: logo/
                                                                         colors/footer from $brand)

  Cache invalidation:  email_brand:{siteId} is added to SiteCacheService::invalidateSite()'s
                       key list. Logo media (SiteMediaObserver touch), contact-block
                       (BlockObserver touch) and site-row changes all funnel through that
                       seam already. Design-kit writes get ONE explicit post-write
                       invalidateSite() call (the write bypasses the model's dirty state
                       and is mistimed relative to the observer — see Invalidation below).
```

### Unit responsibilities

- **`EmailBrand`** — immutable value object, pure data, no I/O.
- **`EmailPalette`** — the 8-token email-safe palette; every field non-null.
- **`EmailBrandDefaults`** — canonical default per email token; single source of truth in PHP.
- **`ProEmailBrandResolver`** — the only DB/cache-touching unit; `site_id → EmailBrand`.
- **Layout + templates** — rendering only.
- **Jobs** — orchestration; resolve brand, build Mailable.

## Data contracts

### `EmailBrand` — `app/Mail/Branding/EmailBrand.php`

```php
final class EmailBrand
{
    public function __construct(
        public readonly bool $isPartna,        // mode flag → footer + logo behaviour
        public readonly string $proName,        // display_name, or 'the team' fallback
        public readonly string $siteUrl,        // https://{handle}.partna.au, else https://partna.au
        public readonly ?string $logoUrl,       // absolute https; null → text wordmark
        public readonly ?string $replyToEmail,  // pro inbox; null → Partna default reply-to
        public readonly EmailPalette $palette,  // ALWAYS fully populated
    ) {}

    public static function partna(): self;      // platform-branded variant (defaults palette, isPartna=true)
}
```

### `EmailPalette` — `app/Mail/Branding/EmailPalette.php`

Email-safe subset only; every field non-null (defaults pre-merged at construction).

```php
final class EmailPalette
{
    public function __construct(
        public readonly string $accent,          // color_accent
        public readonly string $accentContrast,  // color_accent_contrast (text on accent)
        public readonly string $bg,              // color_bg
        public readonly string $text,            // color_text
        public readonly string $textMuted,       // color_text_muted
        public readonly string $buttonBg,        // DERIVED: button_primary_bg ?? accent
        public readonly string $buttonText,      // DERIVED: button_primary_text ?? accentContrast
        public readonly string $borderRadius,    // border_radius (e.g. '8px')
    ) {}
}
```

**Static vs derived tokens (correctness — these are NOT all literal defaults):**
`button_primary_bg` and `button_primary_text` are NULLABLE columns with **no DB
default and no entry in `defaults.ts`** (migration `20260527110000`); the design
system derives them at render time from `--dk-color-accent` /
`--dk-color-accent-contrast`. So the palette resolves with a two-level fallback:

- **6 static tokens** (`accent`, `accentContrast`, `bg`, `text`, `textMuted`,
  `borderRadius`) — literal defaults mirroring `defaults.ts`.
- **2 derived tokens** — `buttonBg = stored button_primary_bg ?? resolved accent`;
  `buttonText = stored button_primary_text ?? resolved accentContrast`.

Fonts are intentionally excluded — email clients silently fall back to system
fonts, so a typed font field would over-promise. Adding best-effort fonts later is
an additive field, not a reshape.

### `EmailBrandDefaults` — `app/Mail/Branding/EmailBrandDefaults.php`

- Holds the canonical default for each of the 8 tokens (e.g. `accent = '#3a6efc'`),
  replacing the hardcoded `#3a6efc` literals currently in the layout.
- Doc-comment (load-bearing): *"These mirror the email-relevant subset of
  `@partnaau/design-system/design-kit/defaults.ts`. That package is the
  system-wide source of truth; this is a deliberate, contained PHP copy because
  the package isn't reachable from Blade. When a default changes there, change it
  here."*
- **Drift safeguard:** a unit test asserts the **6 static tokens** have literal
  defaults mirroring `defaults.ts`, and that on `EmailBrand::partna()->palette` the
  **2 derived tokens** resolve correctly (`buttonBg == accent` default,
  `buttonText == accentContrast` default). Every `EmailPalette` field must resolve
  non-null — a forgotten token fails CI rather than rendering blank in an inbox.
  Drift risk is contained: email consumes only 8 of 53 kit tokens, the most stable.

## Resolver, caching, invalidation

### `ProEmailBrandResolver` — `app/Mail/Branding/ProEmailBrandResolver.php`

```php
public function forSite(string $siteId): EmailBrand;  // pro-branded, cached
public function partna(): EmailBrand;                  // pass-through to EmailBrand::partna()
public function forget(string $siteId): void;          // cache bust
```

`forSite` on cache miss resolves:
1. Site's user → `proName` (`display_name`, else `'the team'`), `siteUrl`
   (`https://{handle}.partna.au`, else `https://partna.au`).
2. `replyToEmail` — pro's stable contact inbox: contact-block `notification_email`
   if set, else account email, else null. **Consolidates** the reply-to logic
   currently inlined in `SendEnquiryConfirmationJob`; both confirmation emails get
   correct reply-to from the brand — notably the **subscription** path, which loads
   the newsletter block today and has no path to the pro's reply-to.
   *Freshness:* caching reply-to is safe **only because** contact-block edits bust
   `email_brand` via `BlockObserver → touch → invalidateSite` (verified). Without
   the centralized key this would regress today's live read; with it, a changed
   inbox is reflected on the next send. The account-email fallback changes rarely
   and degrades to the Partna default reply-to, so it needs no extra trigger.
3. `design_kits` row → `EmailPalette`, each token merged over `EmailBrandDefaults`.
4. Logo → `logoUrl`: prefer `logo_full`, fall back `logo_square`, else null; ready
   webp variant URL via `variantUrls()`.

### Caching

- Key `email_brand:{siteId}`.
- **Use `CacheLockService::rememberLocked()`, not `Cache::remember()`** — the house
  gold standard (single-flight lock + ±20% TTL jitter + stale-while-revalidate on a
  separate lock Redis DB). This directly serves the broadcast use case: on a cold
  or expired key, a bare `Cache::remember` lets every Horizon worker miss
  simultaneously and run the resolver query in parallel (cache stampede);
  `rememberLocked` lets one worker rebuild while the rest get the stale copy.
- Store a **primitive array payload** and rebuild the DTO on read — robust against
  `EmailBrand` shape changes across deploys (no serialized-object landmines).
- TTL ~24h as a safety net (config-driven); correctness comes from explicit
  invalidation, not expiry.
- Scale win: a broadcast to N subscribers of one pro resolves the brand once, then
  N-1 cache hits — per-send DB cost collapses to a single Redis get.

### Invalidation — centralize on the existing seam

Add `email_brand:{siteId}` (key + `:stale`) to **`SiteCacheService::invalidateSite()`'s
key list** (`SiteCacheService.php:499`). Almost everything that should bust the
brand already funnels through that one method:

| Trigger | Existing path | Covered by centralizing the key? |
|---|---|---|
| Logo media add/replace/delete | `SiteMediaObserver` → `$media->site->touch()` → `SiteObserver::saved` → `invalidateSite` | ✅ yes (no new observer) |
| Contact-block `notification_email` change | `BlockObserver::updated` → `$block->site->touch()` → `invalidateSite` | ✅ yes (this is what makes cached reply-to safe) |
| Site-row change (subdomain, settings, publish) | `UpdateSiteAction::execute` → `$site->save()` → `invalidateSite` | ✅ yes |
| Design-kit-only PATCH | see below | ⚠ fires, but **mistimed** — needs one explicit call |

**The one explicit call.** `UserSiteController::update()` runs
`$action->execute()` (which always `save()`s → fires `invalidateSite` even on a
non-dirty save) **before** `writeDesignKit()` does a raw `DB::update()` on
`site.design_kits` (`UserSiteController.php:49-53`). So on a design-kit edit the
cache is busted *before* the new kit rows are committed — a concurrent read could
repopulate it with stale kit data. Fix: after `writeDesignKit()`, call
`invalidateSite($site)` once more (post-write). This is a precise one-liner, not an
open "find the service" item — and it also tightens the same latent staleness race
for the public site payload, which embeds the design kit.

No `resolver->forget()` and no new observer are required; the resolver may still
expose `forget()` for tests/manual use, but production invalidation rides the
`invalidateSite()` seam.

## Layout, templates, sender wiring

### Brand-aware layout — `resources/views/mail/layouts/partna.blade.php`

Evolves to render from a `$brand` (`EmailBrand`), defaulting to
`EmailBrand::partna()` when none is passed so existing Partna emails are untouched.

- **Header:** `logoUrl` → `<img>`; else text wordmark of `proName`. Partna mode →
  existing Partna icon + wordmark.
- **Colors:** hardcoded `#3a6efc` literals (CSS `a`, button, links) become
  `$brand->palette->*`. Body bg = `palette->bg`, text = `palette->text`,
  CTA = `buttonBg`/`buttonText`, radius = `borderRadius`.
- **Footer:** branded → "{proName} · sent via Partna · unsubscribe (if present)";
  Partna mode → today's "Partna · partna.au · …" footer. `@yield('footer_note')`
  and preheader slots preserved.
- Table-based, inline-style, Outlook-safe structure preserved exactly — swapping
  literals for `$brand` values, not modernising to flexbox/grid.

### Template refactor

`enquiry-confirmation.blade.php` and `subscription-confirmation.blade.php` are
rewritten to `@extends` the shared layout and fill `@section('content')`,
inheriting branding, responsiveness, and dark-mode safety.

### Mailable + sender wiring

`EnquiryConfirmationMail` / `SubscriptionConfirmationMail` take an `EmailBrand`
plus their message data. In `build()`:

- `from(config('mail.from.address'), $brand->proName)` — pro's name, Partna address.
- `replyTo($brand->replyToEmail ?? config default)` — replaces inlined reply-to.
- Pass `$brand` into the view.
- `BaseTransactionalMail` envelope/CRLF/header-injection protections untouched.
- `SubscriptionConfirmationMail` keeps its RFC 8058 one-click unsubscribe headers.

### Jobs

Each resolves `ProEmailBrandResolver::forSite($siteId)` and passes the
`EmailBrand` to the Mailable. Idempotency (`confirmation_sent_at`), rate-limiting
(`visitor_confirmation_per_hour`), the per-block `send_visitor_confirmation`
toggle, and UUID-only payloads stay exactly as they are.

**Transaction boundary (load-bearing):** the brand MUST be resolved **after** the
idempotency `DB::transaction` commits, never inside it. A brand read does Redis +
DB I/O and must not extend the `lockForUpdate` hold on the enquiry/subscription
row. The current job structure (transaction first → `Mail::send` after) makes this
natural; the spec states it so a future edit doesn't move the resolve inside the
lock.

## Concurrency, observability, security

- **Observability (load-bearing).** The resolver-throws → fall back to
  `EmailBrand::partna()` → still-send path is critical: `confirmation_sent_at` is
  set only after a successful send, so an *uncaught* throw means the email never
  goes out. The fallback MUST be logged as a structured `Log::warning` with
  `site_id` so Nightwatch can surface a spike — otherwise a systematic resolver
  bug is invisible because mail still flows (just unbranded).
- **Security — output escaping.** `proName` is user-controlled (`display_name`) and
  flows into both the `From` display-name and the HTML body. Render it in Blade
  with `{{ }}` (auto-escaped), never `{!! !!}`. (`BaseTransactionalMail` already
  strips CR/LF from the subject.)
- **Security — logo URL.** Validate `logoUrl` is `https` and from the expected
  media host before injecting into `<img src>`. It comes from our own
  `variantUrls()` so risk is low, but the assertion is cheap defence-in-depth.

## Edge cases

| Case | Behaviour |
|---|---|
| No design-kit row / all-null | Full default palette (looks like today's Partna blue) — never blank |
| No logo uploaded | Text wordmark of `proName` |
| No `display_name` | `'the team'` (matches current job) |
| No handle | `siteUrl` → `https://partna.au` |
| Logo media not `ready` | Treated as no logo → text wordmark |
| Resolver throws (DB blip) | Caught → fall back to `EmailBrand::partna()`; email still sends; logged. **A branding failure must never drop a transactional email.** |

## Testing strategy

- **Unit:** `EmailBrand`/`EmailPalette` (factory, completeness);
  `EmailBrandDefaults` (every token has a default; `partna()` palette == defaults).
- **Unit:** `ProEmailBrandResolver` — kit present/absent, logo full/square/none/
  not-ready, reply-to resolution order, cache hit/miss, `forget()`.
- **Feature:** each job renders a branded email (pro name in From, accent color +
  logo/wordmark in body, reply-to); Partna emails stay Partna-branded;
  idempotency/rate-limit/toggle still hold; resolver-failure falls back to Partna
  and still sends.
- **Invalidation:** assert `invalidateSite()` forgets `email_brand` (+`:stale`);
  logo-media change, contact-block `notification_email` change, and a design-kit
  PATCH each leave no stale brand on the next resolve. Include a regression test
  that a design-kit-only PATCH busts the brand **after** the kit write (the
  mistiming fix).
- **Defaults guard:** 6 static tokens match `defaults.ts` literals; `partna()`
  palette derives `buttonBg == accent`, `buttonText == accentContrast`.
- **Resilience:** a throwing resolver falls back to `partna()`, still sends, and
  logs a structured warning with `site_id`.

## File-level change summary

**New:**
- `app/Mail/Branding/EmailBrand.php`
- `app/Mail/Branding/EmailPalette.php`
- `app/Mail/Branding/EmailBrandDefaults.php`
- `app/Mail/Branding/ProEmailBrandResolver.php` (uses `CacheLockService::rememberLocked`)
- Tests (unit + feature) per the testing strategy

**Changed:**
- `resources/views/mail/layouts/partna.blade.php` — brand-aware
- `resources/views/emails/enquiry-confirmation.blade.php` — extend layout
- `resources/views/emails/subscription-confirmation.blade.php` — extend layout
- `app/Mail/EnquiryConfirmationMail.php` — take `EmailBrand`, sender wiring
- `app/Mail/SubscriptionConfirmationMail.php` — take `EmailBrand`, sender wiring
- `app/Jobs/Notifications/SendEnquiryConfirmationJob.php` — resolve brand (after txn)
- `app/Jobs/Notifications/SendSubscriptionConfirmationJob.php` — resolve brand (after txn)
- `app/Services/Cache/SiteCacheService.php` — add `email_brand:{siteId}` (+`:stale`) to `invalidateSite()`
- `app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php` — one post-`writeDesignKit()` `invalidateSite()` call (timing fix)
- `config/partna.php` — brand cache TTL
- `.env.example` — brand cache TTL env key (if env-backed)

*No new observer:* logo and contact-block invalidation ride the existing
`SiteMediaObserver`/`BlockObserver` → `touch` → `SiteObserver` → `invalidateSite`
chain.

## Open items to resolve during implementation

- Confirm the best logo variant key to select for email width (from `variantUrls()`).
- Confirm the expected media host(s) for the `logoUrl` https/host assertion.
