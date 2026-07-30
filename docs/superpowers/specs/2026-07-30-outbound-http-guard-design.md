# Outbound HTTP Guard

**Date:** 2026-07-30
**Status:** Design approved, implementation pending
**Origin:** SAST tooling evaluation, 2026-07-30 (Semgrep/Enlightn trial — both rejected; see the "Rejected alternatives" section below)
**Scope:** One sink — outbound HTTP from `app/`. SQL, filesystem paths, and command execution are explicitly **out of scope**.

---

## 1. Problem

`App\Services\Http\SafeUrlFetcher` is the house SSRF guard: public-IP-only, and it re-validates
every hop of a redirect chain. It is used across 20+ files. **Nothing enforces its use.**

Every `SafeUrlFetcher` reference in `tests/` is behavioural — `HttpIoPostSsrfTest` covers one
specific path, `ConnectFetchBudgetTest` uses it as a mock. No test and no CI gate fails if a
developer writes `Http::get($userSuppliedUrl)` in a new file.

Commit `393466a9` (2026-07-30, "route the shop-brand logo fetch through SafeUrlFetcher") is what
that gap looks like in practice: the fetch skipped the sanitizer and nothing caught it until a
human read the code.

### 1.1 Why validation at the source does not help

`UpsertWorkplaceRequest.php:54` validates `'website' => ['nullable', 'url', 'max:2048']`. Laravel's
`url` rule checks *format*, not *destination*. All of these pass it:

```
http://169.254.169.254/latest/meta-data/   # cloud instance metadata
http://127.0.0.1:6379/                     # our own Redis
http://10.0.0.5/internal-admin             # private network
```

The value then travels four files — `UpsertWorkplaceRequest` → workplace row → `WorkplaceObserver`
→ `ScanPreviousWebsiteContentJob` → `WebsiteImporter::import()` → the fetch. Only the last step
decides whether it is safe. That step is currently a convention, not a rule.

### 1.2 Why static analysis cannot currently see this

To grep, and to PHPStan, these two lines are indistinguishable:

```php
$response = Http::get($websiteUrl);                                         // user-controlled → SSRF
$response = Http::withToken($token)->get(config('services.mistral.url'));   // constant → safe
```

The difference is the *provenance* of the argument, four files back. Nothing in the stack performs
dataflow (taint) analysis: Checkpoint is line-by-line `preg_match`, Vigil checks config/filesystem,
PHPStan+larastan analyse types only (no taint extension exists for PHPStan), ZAP and Nuclei are
black-box runtime. The audit pipeline reads dataflow semantically but is non-deterministic and is
not a gate.

## 2. Approach: guard the door, do not trace the flow

Rather than prove "no tainted value reaches a fetch" (taint analysis), assert the contrapositive:
**every outbound fetch is already in a known-safe category.** If every call site is constrained,
there is no unsanitized path left to analyse.

This is the same move as the existing GS-1 cache guard: instead of auditing every cache key for
tenant leaks, force all keys through `CacheKeyGenerator`.

**Stated limitation, so nobody over-reads this document:** this closes one sink. It is not
dataflow analysis, and it says nothing about SQL, file paths, or shell execution. Those are lower
risk here — the codebase is a JSON API using Eloquent almost everywhere, with no HTML rendering —
but they remain uncovered.

## 3. Audit of the current surface (2026-07-30)

### 3.1 There is only one door

Verified across `app/`: **no** `curl_*`, **no** `new GuzzleHttp\Client`, **no**
`file_get_contents`/`fopen`/`readfile`/`copy` on an `http(s)://` argument. The entire outbound
surface is the Laravel `Http` facade. This makes a complete guard tractable.

### 3.2 Call-site census

`git grep -P '\bHttp::' -- 'app/' ':!app/Services/Http/'` returns **44 lines**, of which **11 are
comments** and **33 are real call sites** across 21 files.

**Zero live violations were found.** Every real call site already falls into one of four safe
patterns. The guard's value is preventing the *next* `393466a9`, not fixing a present bug.

### 3.3 The four safe patterns

| Pattern | Meaning | Evidence |
|---|---|---|
| **A — ConstantEndpoint** | Host is a class const or `config()` value; attacker cannot influence it | `FreshaScraper.php:260` → `self::GRAPHQL_URL`; `AccountDeletionService.php:1400` → `{$baseUrl}` from config; `VerifySupabaseJwt.php:498` → `$jwksUrl` from config; Cloudflare, Supabase, Mistral, DeepSeek, Twitch, Kick, hCaptcha, Turnstile, Apify |
| **B — SafeUrlFetcher** | Arbitrary user-supplied URL routed through the house sanitizer | `WebsiteImporter.php:47` → `tryFetch($websiteUrl)`; `LinkInBioImporter`, `ProcessShopBrandLogoJob`, `LogoAutoGrabber`, `HttpIo` |
| **C — HostAllowlist** | Untrusted URL, but constrained to an explicit host allowlist with redirects refused | `InstagramConnectionSeeder.php:34` `ALLOWED_HOSTS = ['cdninstagram.com','fbcdn.net']` + `isAllowedHost()` (:510) + `->withoutRedirecting()` + drop status ≥ 300 |
| **D — FixedHostVariablePath** | Host hardcoded; only the URL *path* is variable | `YoutubeThumbnailResolver.php:43` `https://i.ytimg.com/vi/{$videoId}/…`; `GoogleBusinessService.php:483` `https://places.googleapis.com/v1/{$photo['ref']}/media` |

Pattern C is the reason this design is not "everything must call `SafeUrlFetcher`".
`InstagramConnectionSeeder` fetches **scraped** CDN URLs — genuinely untrusted — and defends with a
control *stricter* than `SafeUrlFetcher`: a two-host allowlist beats "any public IP". A naive rule
would flag correct code as a violation, which is how guards get switched off.

Pattern C is also possible because `SafeUrlFetcher` exposes `assertPublicUrl()` (:494) and
`assertSafe()` (:557) as **public** methods — the sanitizer and the transport are separable. The
rule must therefore be "prove you are in a safe category", not "call this one class".

### 3.4 Pattern D is currently unvalidated

Neither Pattern D site constrains its interpolated segment:

- `YoutubeThumbnailResolver.php:43` — `$videoId` is interpolated directly. `bestForMany()` (:64)
  applies only `array_filter` (drops empties). The id originates from a user's YouTube link.
- `GoogleBusinessService.php:300` — `'ref' => data_get($p, 'name')`, taken verbatim from the Places
  API response, interpolated at :483.

**Severity: low, and this is not SSRF.** The host is closed before the interpolation point, so no
value can redirect the request to another host. What a hostile id *can* do is inject `?`, `#`, or
`../` and reshape the request path or query. This is unvalidated-external-identifier hygiene, of
the same class as unparameterised SQL — the danger is that the value can carry syntax — but with a
far smaller blast radius.

## 4. Design

A single Pest architecture test, `tests/Feature/Architecture/OutboundHttpGuardTest.php`, enforcing
three rules.

### 4.1 Rule 1 — one door

Assert `app/` contains no `curl_*`, no `new GuzzleHttp\Client`, and no
`file_get_contents`/`fopen`/`readfile`/`copy` against an `http(s)://` argument.

All three are absent today (§3.1). Pinning that stops a future developer bypassing Rules 2 and 3
by reaching for a different transport.

### 4.2 Rule 2 — every `Http::` site is classified

Scan `app/`, excluding `app/Services/Http/`, for `Http::` call sites. Every file containing one
must appear in an in-test allowlist declaring its pattern and a one-line reason:

```php
'app/Services/Platforms/FreshaScraper.php'             => [Pattern::ConstantEndpoint,      'self::GRAPHQL_URL'],
'app/Services/Platforms/InstagramConnectionSeeder.php' => [Pattern::HostAllowlist,         'ALLOWED_HOSTS + isAllowedHost() + withoutRedirecting()'],
'app/Routing/Importers/WebsiteImporter.php'            => [Pattern::SafeUrlFetcher,        'tryFetch()'],
'app/Services/Platforms/YoutubeThumbnailResolver.php'  => [Pattern::FixedHostVariablePath, 'i.ytimg.com, id validated'],
```

A file calling `Http::` with no allowlist entry fails, with an error naming the four patterns and
pointing at `SafeUrlFetcher`.

An allowlist entry for a file that no longer contains a call site also fails — a stale entry is a
silent hole, and the GS-1 allowlist has already accumulated one (`IndividualProfileController`,
allowlisted for a docblock match).

### 4.3 Rule 3 — Pattern D sites must validate before interpolating

Each file classified `Pattern::FixedHostVariablePath` must contain a validation call constraining
the interpolated segment. Two fixes ship with the guard:

```php
// YoutubeThumbnailResolver — YouTube ids are exactly 11 chars of [A-Za-z0-9_-]
if (preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) { return null; }

// GoogleBusinessService — Places photo refs are places/<id>/photos/<id>
if (preg_match('#^places/[A-Za-z0-9_-]+/photos/[A-Za-z0-9_-]+$#', $ref) !== 1) { skip this photo; }
```

Rejected ids/refs are dropped, not thrown on: both are best-effort enrichment paths where a
malformed identifier should degrade the result, not fail the job.

### 4.4 Comment handling — `token_get_all()`, not regex

The scanner tokenises each file with PHP's built-in `token_get_all()` and discards `T_COMMENT` and
`T_DOC_COMMENT` before matching.

This is load-bearing: 11 of the 44 census hits are comments, including
`ProcessShopBrandLogoJob.php:67` — a comment that reads *"Never call Http::get() here."* A regex
scanner allowlists that file for mentioning the pattern it forbids. Tokenising removes the entire
false-positive class by construction, with no new tooling and no inline suppression annotations.

### 4.5 Error output

A failure names the offending file and line, the four patterns with a one-line description each,
and the allowlist location. The message must make the *right* fix obvious — the failure mode to
avoid is a developer adding an allowlist entry when they should have used `SafeUrlFetcher`.

## 5. CI wiring

The guard gets **its own job** in `.github/workflows/ci.yml`, on the `schema-drift` precedent.

A Feature test runs inside `composer test`, but `composer test` runs the Unit suite first and a
single unrelated fatal there aborts the run before Feature tests execute — the documented reason
`schema-drift` was given its own job. `composer test` is also currently red (5 standing gates).
A guard that only runs inside a red, abort-prone suite is a guard that does not run.

It stays in `composer test` as well, so a violation is caught locally before push.

## 6. Testing

| # | Test | Expectation |
|---|---|---|
| 1 | Run isolated: `php artisan test --filter=OutboundHttpGuardTest` | Green, independent of the 5 standing red gates |
| 2 | Plant `Http::get($url)` in a new unallowlisted file | Fails, naming file + line |
| 3 | Comment-only hits (the 11 from §3.2) | Not flagged — the regression the GS-1 guard lacks |
| 4 | Remove a Pattern D validation call | Rule 3 fails |
| 5 | Add `curl_init()` to `app/` | Rule 1 fails |
| 6 | Stale allowlist entry (file no longer calls `Http::`) | Fails |
| 7 | Full suite | No new failures beyond the known-red baseline |

Test 3 is the one that distinguishes this guard from its predecessor; test 7 must be reconciled
against the pre-existing red baseline, not read as pass/fail on its own.

## 7. Rejected alternatives

**Semgrep OSS** — trialled 2026-07-30 against `app/` (1289 files). Registry rules found nothing
usable: `p/php` 0 findings, `p/security-audit` 0 (only 2 of its 225 rules apply to PHP),
`p/owasp-top-ten` 0, `p/default` 19 findings all from one syntactic `unlink-use` rule, all false
positives. Porting the GS-1 guard to a custom AST rule did beat grep (38 findings vs 50, the
12-line gap entirely comments, zero false negatives) but removed only **1 of 30** allowlist entries,
trading a reviewable 30-entry list for 38 inline `nosemgrep` comments. Not worth a new toolchain.

**Enlightn** — abandoned on Packagist, and pins `larastan ^2.0`/`phpstan >=1.10` against this
repo's larastan ^3.0. Installing it means downgrading the primary analyser.

**CodeQL** — does not support PHP. Not a beta gap; PHP is not a supported language.

**Psalm `--taint-analysis`** — the only free interprocedural PHP taint engine, and the only option
here that would genuinely do dataflow. Deferred, not dismissed: it means a second analyser with its
own baseline and its own false-positive stream alongside PHPStan. Revisit post-pilot if the sinks
this design leaves uncovered (SQL, file paths, shell) start to matter.

## 8. Follow-ups not in this scope

- Sinks other than outbound HTTP remain uncovered (§2).
- `SafeUrlFetcher`'s own correctness is assumed, not re-verified here.
