# Social Links — Conceptual Guide

> **Audience:** Backend, frontend (affiliate dashboard), and anyone adding/removing supported social platforms.
> **Status:** Active. This is the canonical model for distinguishing "social" links from "custom" links in `site.blocks`.
> **See also:** [api.md](./api.md) for the endpoint reference, [brand-catalog-v2.md](./brand-catalog-v2.md) for the analogous Shopify-metafield pattern.

---

## 1. Overview

Link blocks on the public mini-site live in `site.blocks` with `block_group = 'links'`. Originally, every link was treated identically — the only signal of "platform" was `icon_key`, validated against a flat allowlist. The frontend had to maintain its own `icon_key → display name` mapping, the user had to know to paste full Instagram URLs, and there was no validation that a URL behind an Instagram icon actually pointed to Instagram.

V2 adds a **server-side registry of social platforms** (`config('partna.social_platforms')`) that:
- Tells the frontend what icon, display name, and input format to use per platform.
- Validates and normalizes user input (handles or URLs) before storing.
- Forces a canonical `https://` URL so all stored social links are consistent.
- Lets us add new platforms by editing config alone — no frontend deploy.

**Zero schema changes.** Platform identity lives in the existing `settings` JSONB column (`settings.platform`, `settings.handle`, `settings.category`). When/if query-ability matters later (e.g. "show me all users with Instagram"), promoting these to real columns is a purely additive migration — no breaking change.

---

## 2. The supported platforms (37 entries as of 2026-08-27 — the config array is the count)

Grouped by `default_category`. See `config('partna.social_platforms')` for the full per-platform config.

### 2.1 Social (8)

Instagram, Facebook, LinkedIn, YouTube, TikTok, X, Spotify, SoundCloud — all path-mode, `default_category: 'social'`. See §2.6 below for handle/URL detail.

### 2.2 Booking (5) — path mode

`default_category: 'booking'`. Handles are ASCII alphanumerics with hyphens or underscores; strict regex per platform.

| Key | Display name | Example URL |
|-----|--------------|-------------|
| `fresha` | Fresha | `https://fresha.com/a/{slug}` |
| `booksy` | Booksy | `https://booksy.com/en-us/{slug}` |
| `timely` | Timely | `https://book.gettimely.com/book/{slug}` |
| `calendly` | Calendly | `https://calendly.com/{handle}` |
| `square` | Square | `https://book.squareup.com/appointments/{slug}` |

### 2.3 Education (4)

`default_category: 'education'`. Stan and Skool are path-mode; Kajabi and Circle are subdomain-mode (see §3 for subdomain platform details).

| Key | Display name | Example URL | Mode |
|-----|--------------|-------------|------|
| `stan` | Stan | `https://stan.store/{handle}` | path |
| `skool` | Skool | `https://skool.com/{slug}` | path |
| `kajabi` | Kajabi | `https://{handle}.mykajabi.com/` | subdomain |
| `circle` | Circle | `https://{handle}.circle.so/` | subdomain |

### 2.4 Events (4) — path mode

`default_category: 'events'`. Handles are ASCII alphanumerics with hyphens.

| Key | Display name | Example URL |
|-----|--------------|-------------|
| `eventbrite` | Eventbrite | `https://eventbrite.com/o/{slug}` |
| `humanitix` | Humanitix | `https://humanitix.com/host/{slug}` |
| `luma` | Luma | `https://lu.ma/{handle}` |
| `partiful` | Partiful | `https://partiful.com/u/{handle}` |

### 2.5 Content (3)

`default_category: 'content'`. Apple Podcasts is path-mode (numeric ID handle); Substack and Bandcamp are subdomain-mode.

| Key | Display name | Example URL | Mode |
|-----|--------------|-------------|------|
| `apple_podcasts` | Apple Podcasts | `https://podcasts.apple.com/us/podcast/id{numeric-id}` | path |
| `substack` | Substack | `https://{handle}.substack.com/` | subdomain |
| `bandcamp` | Bandcamp | `https://{handle}.bandcamp.com/` | subdomain |

> Apple Podcasts note: the handle is the numeric ID at the end of the URL. Most users paste the full URL, so the lenient fallback (§5.2) is the common path.

### 2.6 Legacy social-only reference table

Original per-platform handle/URL detail, preserved for reference.

| Key | Display name | Handle format | URL format | Allowed hosts |
|-----|-------------|---------------|------------|---------------|
| `instagram` | Instagram | 1-30 chars, ASCII alphanumerics + `.` `_` | `https://instagram.com/{handle}` | instagram.com, www.instagram.com |
| `facebook` | Facebook | 5-50 chars, ASCII alphanumerics + `.` | `https://facebook.com/{handle}` | facebook.com, www.facebook.com, fb.com, m.facebook.com |
| `linkedin` | LinkedIn | 3-100 chars, ASCII alphanumerics + `-` | `https://linkedin.com/in/{handle}` | linkedin.com, www.linkedin.com |
| `youtube` | YouTube | 3-30 chars, ASCII alphanumerics + `.` `_` `-` | `https://youtube.com/@{handle}` | youtube.com, www.youtube.com, m.youtube.com, youtu.be |
| `tiktok` | TikTok | 2-24 chars, ASCII alphanumerics + `.` `_` | `https://tiktok.com/@{handle}` | tiktok.com, www.tiktok.com, vm.tiktok.com |
| `x` | X | 1-15 chars, ASCII alphanumerics + `_` | `https://x.com/{handle}` | x.com, www.x.com, twitter.com, www.twitter.com, mobile.twitter.com |
| `spotify` | Spotify | 3-40 chars, ASCII alphanumerics + `.` `_` `-` | `https://open.spotify.com/user/{handle}` | open.spotify.com, spotify.com |
| `soundcloud` | SoundCloud | 3-40 chars, ASCII alphanumerics + `_` `-` | `https://soundcloud.com/{handle}` | soundcloud.com, www.soundcloud.com |

**Functional icons** (`scissors`, `calendar`, `map`, `phone`, `website`, `link`, `email`, `whatsapp`) remain available as **custom** link icons — no platform binding, no validation rules beyond the existing allowlist.

---

## 3. Adding a new platform

1. Add an entry to `config/partna.php` under `social_platforms`. Required fields:
   - `display_name` (string)
   - `icon_key` (string — must also be added to `link_block_icon_keys` below)
   - `placeholder` (string — shown as input hint, e.g. `@yourname`)
   - `handle_pattern` (PHP regex, ASCII-only, bounded quantifiers)
   - `url_template` (string with `{handle}` placeholder, **must be https**)
   - `host_allowlist` (array of plain-ASCII hosts)
   - `url_path_extractor` (PHP regex matching the path portion to extract a handle)
   - `default_category` (one of `config('partna.link_categories')`)
   - `handle_location` (`'path'` or `'subdomain'`)
2. Add the new `icon_key` value to the `link_block_icon_keys` allowlist in the same file.
3. Add a unit test case in [tests/Feature/Site/SocialLinkNormalizerTest.php](../tests/Feature/Site/SocialLinkNormalizerTest.php) covering: clean handle, URL extraction, deep-link fallback, wrong-host rejection.
4. Update the platforms table in §2 above.
5. Frontend automatically picks up the new platform on next bootstrap — no frontend deploy needed.

### Subdomain-mode platforms

If the platform assigns each user their own subdomain (e.g. `alice.substack.com`), set `handle_location: 'subdomain'`. The `host_allowlist` stores only the base domain (`['substack.com']`) — the normalizer applies a labelled-suffix check (`.substack.com`) to validate the host. The `url_template` uses `{handle}` in the subdomain position: `https://{handle}.substack.com/`. The `url_path_extractor` is unused in subdomain mode; set it to `'#^/?$#'` for consistency.

**Security:** the leading dot in the labelled-suffix check is critical — without it, `evilsubstack.com` would match `substack.com`. See §8.4.

---

## 3.5 Link categories

Every link block has a required `category` stored in `settings.category`. The six valid values live in `config('partna.link_categories')`:

`social`, `booking`, `education`, `content`, `events`, `other`

**Resolution order:**
1. Request-provided `category` wins (must pass the enum).
2. Else for platform-tagged links, the platform's `default_category` is used.
3. Else for custom links (no `platform`), the request must include `category` (422 otherwise).

The override is by design: a brand using their Instagram exclusively to announce events can tag that link as `events` even though Instagram's `default_category` is `social`.

**Storage:** `settings.category` JSONB — zero schema change. Promotion to a real column follows the additive-migration path in §9; same trigger conditions apply as for `settings.platform`.

The public registry endpoint (`GET /api/public/config/social-platforms`) returns the platform-to-category mapping (each platform entry includes a `category` field) plus a top-level `categories` array so the frontend can build a picker without hardcoding the enum. See §6 for the full response shape.

---

## 4. The two write shapes

The link block create/update endpoints accept **two different request shapes**. The presence of `platform` is the discriminator.

### 4.1 Social mode

Either of these works:

```json
{ "platform": "instagram", "handle": "joshhunter" }
```

```json
{ "platform": "instagram", "url": "https://instagram.com/joshhunter" }
```

Optional fields: `title` (auto-defaults to the platform's display name), `is_active`, `category` (overrides the platform's `default_category`), `settings` (highlight, note).

The backend normalizes either input to:
- `url` = canonical `https://instagram.com/joshhunter`
- `icon_key` = `instagram`
- `title` = `Instagram` (or whatever the user supplied)
- `settings.platform` = `instagram` (soft tag)
- `settings.handle` = `joshhunter` (or null if URL was a deep link with no extractable handle)
- `settings.category` = `social` (or whatever was supplied/resolved)

### 4.2 Custom mode

```json
{ "title": "Book Now", "url": "https://booking.example.com/joshhunter", "icon_key": "calendar", "category": "booking" }
```

`title`, `url`, and `category` are required. `icon_key` is optional but must be in the `link_block_icon_keys` allowlist if provided. No platform binding, no settings tagging.

### 4.3 Custom-mode URL scheme allowlist

Custom links are restricted to `http://` or `https://`. The following are rejected with 422:
- `javascript:` (XSS vector)
- `data:` (XSS vector)
- `file:` (info disclosure / SSRF vector)
- `ftp:` (legacy / often unwanted)
- Anything else exotic

---

## 5. Normalization rules

### 5.1 Handle path

When a `handle` is provided:
1. Strip a leading `@` if present (`@joshhunter` → `joshhunter`).
2. Trim whitespace.
3. Validate against the platform's `handle_pattern` regex. Reject 422 on failure.
4. Build the canonical URL via `url_template` substitution.

### 5.2 URL path

When a `url` is provided (no handle):
1. Parse via PHP's `parse_url()` — handles IDN/punycode safely.
2. Lowercase the host and check against the platform's `host_allowlist`. Reject 422 on host mismatch.
3. Try to extract a handle via the platform's `url_path_extractor` regex.
   - If it matches → recurse into the handle path. Result: clean canonical URL, handle stored.
   - If it doesn't match (e.g. `instagram.com/p/abc123` post URL) → fall back to lenient mode: keep the URL but force https + drop tracking by rebuilding the URL from parsed parts. No handle extracted.

### 5.3 Strict vs lenient

- **Handles are strict.** ASCII-only, bounded length, no exceptions. Catches typos, homoglyph attacks, and prevents users from accidentally storing `@joshhunter please dm me` in a handle field.
- **URLs are lenient.** A wrong host is rejected, but a deep link to a post or video is accepted as-is (just upgraded to https). This lets users link to specific content on their profiles, not just the profile root.

### 5.4 https forcing

Every URL — handle-derived or extracted from input — is rebuilt with `https://`. Even if the user pastes `http://instagram.com/joshhunter`, the stored value is `https://instagram.com/joshhunter`. No mixed-content warnings on the public site, no MITM risk on link clicks.

---

## 6. The public registry endpoint

`GET /api/public/config/social-platforms`

**Auth:** None.
**Throttle:** 60 req/min/IP via the `public-site` rate limiter.
**Cache headers:** `Cache-Control: public, max-age=3600` — the registry is static between deploys, so the CDN absorbs traffic.

**Response:**
```json
{
  "platforms": [
    { "key": "instagram", "display_name": "Instagram", "icon_key": "instagram", "placeholder": "@yourname", "category": "social" },
    { "key": "calendly", "display_name": "Calendly", "icon_key": "calendly", "placeholder": "yourname", "category": "booking" },
    ...
  ],
  "categories": ["social", "booking", "education", "content", "events", "other"]
}
```

The `category` field on each platform reflects its `default_category`. The top-level `categories` array lets the frontend build a category picker without hardcoding the enum.

**What's NOT in the response:**
- `handle_pattern` — server-side regex stays server-side
- `host_allowlist` — server-side allowlist stays server-side
- `url_path_extractor` — server-side regex stays server-side
- `url_template` — derivable from the canonical URL, not needed by the frontend
- `handle_location` — server-side routing detail, not needed by the frontend

Internal validation logic never reaches the wire. This prevents attackers from reading the regex and crafting bypass payloads.

---

## 7. Frontend integration expectations

### 7.1 Affiliate dashboard

1. **At app load**: fetch `GET /api/public/config/social-platforms` once. Cache in app state. The registry only changes on backend deploy, so a long TTL is safe.
2. **Add Link UI**: render a platform picker from the registry — show display name + icon for each platform, optionally grouped by the `categories` array for section headings. Include a "Custom" option for non-social links (requires an explicit `category`).
3. **Per-platform input affordance**:
   - Show a single input field with the platform's `placeholder` as the hint.
   - Accept either a handle OR a full URL — both work, the backend normalizes either.
   - For instant feedback, mirror the handle regex client-side (display "Instagram handles must be 1-30 letters/numbers/dots/underscores"). This is a UX nicety — the backend is still the source of truth.
4. **On save**: POST `{ platform: 'instagram', handle: 'joshhunter' }` (or `url` for deep links). Don't send `title` unless the user typed a custom one — let the backend auto-fill from the platform's display name. Don't send `category` unless overriding the platform's default.
5. **Clear-the-platform / convert to custom**: send `{ platform: null, title: 'Custom Title', url: '...', category: 'other' }`. (Not yet exposed in the affiliate dashboard, but the API supports it.)
6. **Render**: use `block.icon_key` for the visual icon (it's set automatically by the backend in social mode). Read `block.settings.platform` if you need to render social-specific UI (e.g. a different button style for Instagram vs custom). Read `block.settings.category` for grouped rendering on the public mini-site.

### 7.2 All link block consumers (public mini-site, dashboards)

**Treat every link block field as untrusted user input on render.** The backend strips HTML tags and control characters from `title` as defense-in-depth, but the frontend MUST also escape on render. React, Vue, Svelte, and Hydrogen all auto-escape by default — DON'T use `dangerouslySetInnerHTML` or `v-html` on link block fields.

The handle field is also untrusted but already constrained to ASCII alphanumerics + a few punctuation characters by the regex, so it's safe to render directly as text.

---

## 8. Security considerations

### 8.1 Frontend escaping responsibility

The backend's title sanitization (`strip_tags()` + control char strip) is **defense-in-depth, not the primary defense**. The primary defense is frontend escaping. If a future renderer uses `dangerouslySetInnerHTML` on a link block field, you have a problem regardless of what the backend does.

### 8.2 Homoglyph attacks (ASCII-only handles)

Handle regexes are deliberately ASCII-only. A user can't store `joshhunteг` (Cyrillic `г` U+0433) that looks identical to `joshhunter` (Latin `r`) and link to an attacker's profile. The backend rejects it at validation.

### 8.3 Punycode / IDN host attacks

`parse_url()` returns IDN hosts in their punycode form (e.g. `xn--instagram-...`). The `host_allowlist` is plain ASCII, so a punycode lookalike domain fails the allowlist check naturally. No additional code needed — this is a property of the existing check.

### 8.4 Subdomain-mode labelled-suffix check

Subdomain-mode platforms (Substack, Bandcamp, Kajabi, Circle) validate the host with:

```php
$host === $base || str_ends_with($host, '.' . $base)
```

The leading dot is critical: without it, `evilsubstack.com` would match `substack.com` and become an open-phishing vulnerability. The bare base domain (`substack.com` with no subdomain) is also rejected — there's no handle to extract. Covered by a dedicated Pest test asserting `evilsubstack.com` rejects.

### 8.5 https-only canonical URLs

Every stored social URL is rebuilt with `https://`, even if the user pasted `http://`. This prevents:
- Mixed-content warnings on the public mini-site.
- MITM downgrade attacks on link clicks.
- Inconsistent storage where the same profile is linked via 5 different URL variants.

### 8.6 ReDoS

All `handle_pattern` regexes use bounded quantifiers (`{1,30}`, `{2,24}` etc.) with no nesting. They are not vulnerable to catastrophic backtracking even on adversarial input.

### 8.7 SSRF (future risk if link previews are added)

**Not currently a concern** because the backend never fetches a user-supplied URL. We only store and return URLs; the browser fetches them.

If you ever add link previews / oEmbed / OpenGraph metadata fetching, you **must**:
- Filter private IP ranges (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `127.0.0.0/8`, `169.254.169.254`, etc.) — both pre-DNS and post-DNS.
- DNS-pin the resolved IP across the request lifecycle (defeats DNS rebinding).
- Enforce a small timeout (≤5s) and response size cap (≤512KB).
- Strict scheme allowlist (https only, ideally).
- Don't follow redirects to private IPs.

This is out of scope for the current implementation. If a future PR adds previews, link to this section in the PR description.

### 8.8 Click tracking

Click tracking is **frontend-only today**. The backend's `POST /api/public/analytics/clicks` endpoint records a click in the DB and returns JSON — it does NOT issue a redirect. The frontend opens the URL itself via a normal anchor tag click.

If a future PR adds a backend redirect endpoint (e.g. for cross-domain tracking), it **must**:
- Only redirect to the URL stored in the DB row, never to a query parameter.
- Re-validate the scheme at click time (https or http only).
- Return 410 Gone if the block is soft-deleted.
- Never trust the route param as the redirect target.

Without these guarantees, a backend redirect endpoint is an open-redirect vulnerability.

---

## 9. Deferred — Option B (`platform` / `category` columns)

The current design stores platform identity and category in `settings` JSONB. Pros: zero schema change, additive migration when needed. Cons: not indexable, slow to query at scale.

**Add real `platform` and `category` columns** when one of these is true:
- A product feature requires "find all professionals with an Instagram link" as a fast query.
- Per-platform analytics (clicks grouped by platform) becomes a hotspot. Today this works as a join through `blocks.settings->>'platform'` — fine for a few hundred users, slow for 100k+.
- Per-category filtering or analytics (e.g. "show me all booking links site-wide") is needed at scale.
- A platform-level integration is added (e.g. "sync your Instagram posts") that needs efficient lookups.

The migration is purely additive (same pattern for both columns):
1. `ALTER TABLE site.blocks ADD COLUMN platform TEXT NULL`
2. Backfill: `UPDATE site.blocks SET platform = settings->>'platform' WHERE settings ? 'platform'`
3. Update Block model fillable + observers.
4. Update controller writes to set the column alongside `settings.platform` (keep both during transition).
5. Eventually drop `settings.platform` once all readers use the column.

Repeat steps 1-5 for `category`. No breaking changes to any API. The `settings.*` fields stay valid for clients that read from them.

---

## 10. Backfill command

**Why:** When the social platforms registry was introduced, existing link blocks used `icon_key='instagram'` etc. but had no platform tag in `settings`. The backfill command brings those rows up to the new shape so the brand UI can render them in social mode. It also backfills `settings.category` (using the platform's `default_category`, or `'other'` for untagged custom links) so all rows satisfy the category requirement introduced alongside the 16 new platforms.

**Usage:**
```bash
# Always run dry-run first to preview the stats table
php artisan sidest:backfill-social-links --dry-run

# Apply for real
php artisan sidest:backfill-social-links

# Cautious first batch
php artisan sidest:backfill-social-links --limit=10
```

The command is also aliased as `sidest:backfill-link-categories` for discoverability.

**Properties:**
- **Idempotent**: skips rows that already have `settings.platform` and `settings.category` set. Safe to re-run.
- **Chunked**: processes 200 rows at a time, each chunk in its own transaction.
- **Honest**: prints a stats table at the end (`total / already_tagged / tagged_with_handle / tagged_url_only / url_normalized / unmatched_host / errors`).
- **Fail-soft**: a block whose URL doesn't match the platform's host_allowlist (e.g. someone put a Linktree URL behind an Instagram icon) is left alone with a warning. No data lost.
- **Audit-logged**: writes a `Log::info` entry on start with the operator identity and run mode.
- **Logging hygiene**: warnings reference block ID + platform key only, never full URLs or handles.

**Where to run:** Locally during dev, then once on staging to verify, then once on production after deploy. Because it's idempotent, running it twice is harmless.

**No automated test.** The project has no precedent for console command tests, no Block factory, and no test schema bootstrap for `site.blocks`. The risky logic (URL parsing, regex validation) is heavily covered by [SocialLinkNormalizerTest](../tests/Feature/Site/SocialLinkNormalizerTest.php). The command's unique logic is straightforward iteration glue, verified manually via `--dry-run`.

---

## 11. Implementation pointers

| Concern | File |
|---------|------|
| Registry definition | [config/partna.php](../config/partna.php) — `social_platforms` key |
| Category enum | [config/partna.php](../config/partna.php) — `link_categories` key |
| Validation + normalization | [`SocialLinkNormalizer`](../app/Services/Site/SocialLinkNormalizer.php) |
| Public registry endpoint | [`PublicConfigController::socialPlatforms`](../app/Http/Controllers/Api/PublicSite/PublicConfigController.php) |
| Form request — create | [`StoreLinkBlockRequest`](../app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php) |
| Form request — update | [`UpdateLinkBlockRequest`](../app/Http/Requests/Api/Professional/Site/UpdateLinkBlockRequest.php) |
| Controller — social/custom split | [`ProfessionalLinkBlockController`](../app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalLinkBlockController.php) — `buildBlockFields()` is the single source of truth |
| Backfill command | [`BackfillSocialLinksCommand`](../app/Console/Commands/BackfillSocialLinksCommand.php) |
| Normalizer tests | [tests/Feature/Site/SocialLinkNormalizerTest.php](../tests/Feature/Site/SocialLinkNormalizerTest.php) |
| Endpoint test | [tests/Feature/PublicSite/PublicConfigSocialPlatformsTest.php](../tests/Feature/PublicSite/PublicConfigSocialPlatformsTest.php) |
| Validation regression tests | [tests/Feature/Site/LinkBlockSocialValidationTest.php](../tests/Feature/Site/LinkBlockSocialValidationTest.php) |
| Category validation tests | [tests/Feature/Site/LinkBlockCategoryValidationTest.php](../tests/Feature/Site/LinkBlockCategoryValidationTest.php) |
| Category controller tests | [tests/Unit/Controllers/BuildBlockFieldsCategoryTest.php](../tests/Unit/Controllers/BuildBlockFieldsCategoryTest.php) |
| Backfill category tests | [tests/Feature/Console/BackfillLinkCategoriesTest.php](../tests/Feature/Console/BackfillLinkCategoriesTest.php) |
| Category config tests | [tests/Unit/Config/LinkCategoriesConfigTest.php](../tests/Unit/Config/LinkCategoriesConfigTest.php) |
