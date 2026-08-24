# Security Audit — 2026-08-18

**Branch:** audit-fix/instagram-wave-findings-2026-08-18
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Providers/PlatformRegistryServiceProvider.php
- config/partna.php
- app/Http/Requests/Platforms/PlatformConnectRequest.php
- app/Http/Controllers/Api/Platforms/DisplaySettingsController.php
- app/Http/Controllers/Api/Platforms/FreshaController.php
- app/Http/Controllers/Api/Platforms/GenericPlatformController.php
- app/Http/Controllers/Api/Platforms/RefreshController.php
- app/Http/Resources/Routing/RoutingConnectionResource.php
- app/Http/Controllers/Api/Routing/RoutingController.php
- app/Services/Http/SafeUrlFetcher.php
- app/Services/Media/MediaMirror.php / WebpEncoder.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Ingest/Connectors/GoogleBusinessConnector.php
- app/Ingest/Landing/Redactor.php, app/Ingest/Manifest/Manifest.php
- app/Site/Pools/BorrowedMedia.php, ItemLinkRules.php
- app/Jobs/Media/MirrorMediaAssetJob.php, app/Jobs/Platforms/MenuFetchJob.php
- app/Services/Platforms/WebsiteLinkHarvester.php, FreshaScraper.php, LinkCardScraper.php
- app/Routing/LinkRoutingService.php, IriCanonicalizer.php, SecretParams.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [x] **#SEC-1** · P1 — Mirrored third-party image bytes are decoded with no pixel-count guard, only a byte-size cap
    - **Where:** app/Services/Media/WebpEncoder.php:31; app/Services/Media/MediaMirror.php:107-111
    - **Affects:** The Instagram media-mirror pipeline (`MirrorMediaAssetJob` → `MediaMirror::mirror()` → `WebpEncoder::encode()`), which decodes bytes fetched from an Instagram CDN URL arriving inside a third-party scrape payload — a source `MediaMirror`'s own docblock calls "untrusted by definition."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `MediaMirror::mirror()`, before calling `$this->encoder->encode($body, ...)`, run a header-only `getimagesize()` on `$body` and reject when the declared pixel count exceeds `config('partna.image_max_pixels')` — the same guard `ImageVariantService::assertWithinPixelBudget()` (or equivalent) already applies to the upload pipeline.
        - Add a regression test: a small, highly-compressed but enormous-dimension PNG (e.g. 20000×20000) must be rejected before `imagecreatefromstring()` runs.
    - **Technical:** `MediaMirror::mirror()` enforces only `strlen($body) > self::MAX_BYTES` (15 MB) before handing the raw bytes to `WebpEncoder::encode()`, which calls `imagecreatefromstring($body)` directly with no pixel-count check. `ImageVariantService::assertWithinPixelBudget()` documents exactly this class of defence for the upload pipeline ("Sniff actual bytes before getimagesize — prevents a crafted file from …") using `config('partna.image_max_pixels', 24_000_000)`, but `WebpEncoder` — the mirror pipeline's own encoder — has no equivalent. A small, highly-compressed image with enormous declared dimensions (a classic decompression bomb) passes the byte cap easily and then causes GD to allocate a multi-hundred-megapixel canvas inside `imagecreatetruecolor()`, which can exhaust worker memory. Because the source URL is explicitly documented as untrusted (arriving via a third-party scrape), this is a real DoS surface on the ingest queue, not a hypothetical one.
    - **Plain English:** Before opening a photo, our system checks how heavy the file is, but not how big a picture it will unfold into once opened — like weighing a suitcase without checking whether it unpacks into a tent. A tiny, cleverly compressed image file can unpack into a picture so enormous it overwhelms the server's memory and can crash the background worker that processes it. We already do this check correctly for photos people upload directly; the same check is missing on the path that copies photos in from a connected Instagram account.
    - **Evidence:**
        ```php
        private const MAX_BYTES = 15728640;
        // ...
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return $this->fail($assetId, 'body_rejected', $sourceUrl);
        }

        $variant = $this->encoder->encode($body, $this->maxEdge());
        ```
        ```php
        $source = @imagecreatefromstring($body);
        if ($source === false) {
            return null;
        }
        ```

- [x] **#SEC-2** · P1 — Google Business photo attribution PII survives the `when_unclaimed` redaction for unclaimed listings
    - **Where:** app/Ingest/Connectors/GoogleBusinessConnector.php:98-103 (manifest redactions), :281-300 (`mapPhoto()`); app/Ingest/Landing/Redactor.php:31-63 (`strip()`)
    - **Affects:** Third-party Google reviewers/photographers whose name and profile URI are stored on any unclaimed business listing — GDPR/DSAR exposure for data subjects who never consented and whose data the owner never claimed responsibility for.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `attribution.authors.*.name` and `attribution.authors.*.uri` (dot-path with `*` wildcard, matching `Redactor::strip()`'s existing wildcard support) to the connector's `redactions` list, scoped `when_unclaimed` the same as `author`/`author_uri`/`author_photo`.
        - Add a regression test that runs the `media` stream for an `unclaimed`-status connection and asserts the landed doc's `attribution` key is absent or has no `authors[].name`/`uri`.
    - **Technical:** `GoogleBusinessConnector::manifest()` declares `redactions: ['author', 'author_uri', 'author_photo']` scoped `when_unclaimed`, and `Redactor::strip()` walks these as top-level dot-paths against the mapped doc — which correctly strips those keys from `mapReview()`'s output. `mapPhoto()`, however, emits a differently-shaped `attribution` array (`['authors' => [['name' => ..., 'uri' => ...]], 'maps_uri' => ..., 'flag_uri' => ...]`) built from Google's `authorAttributions`. None of the declared redaction paths name `attribution` or its nested keys, so `Redactor::apply()` leaves it completely intact, and `ProjectionWriter::resolveMediaAssets()` persists the full JSON-encoded `attribution` blob into `content.media_assets.attribution` regardless of claim status. The `when_unclaimed` scope exists precisely to withhold third-party PII from listings nobody has taken ownership of; the photo-attribution path is a straight gap in that coverage that the review path doesn't have.
    - **Plain English:** When a business hasn't claimed its Google listing yet, the system has a rule that says "don't keep personal details about the people who reviewed or photographed this place." That rule works correctly for written reviews, but it was never extended to photo credits — so a photographer's name and profile link can still end up stored against an unclaimed business, even though nobody at that business ever agreed to hold that person's information. The fix is to extend the same "leave names out" rule to cover photo credits too.
    - **Evidence:**
        ```php
        redactions: ['author', 'author_uri', 'author_photo'],
        redactionScopes: [
            'author' => 'when_unclaimed',
            'author_uri' => 'when_unclaimed',
            'author_photo' => 'when_unclaimed',
        ],
        ```
        ```php
        $authors = array_values(array_filter(array_map(
            static fn ($a) => is_array($a) && is_string($a['displayName'] ?? null)
                ? ['name' => $a['displayName'], 'uri' => is_string($a['uri'] ?? null) ? $a['uri'] : null]
                : null,
            (array) ($photo['authorAttributions'] ?? []),
        )));
        // ...
        $attribution = array_filter([
            'authors' => $authors !== [] ? $authors : null,
            'maps_uri' => is_string($photo['googleMapsUri'] ?? null) ? $photo['googleMapsUri'] : null,
            'flag_uri' => is_string($photo['flagContentUri'] ?? null) ? $photo['flagContentUri'] : null,
        ], static fn ($v) => $v !== null);
        ```

## P2 — Should fix

- [x] **#SEC-3** · P2 — Square's `host_pattern` matches any `order.*` host, not just the five explicitly excluded brand hosts
    - **Where:** config/partna.php:931
    - **Affects:** Platform classification for menu/link detection (`WebsiteLinkHarvester` / catalog host-pattern matching) — a submitted URL whose host merely starts with `order.` (e.g. `order.attacker-controlled.example`) is classified as Square.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Anchor the third alternate so it matches only the intended Square-owned `order.*` shape (e.g. require the remainder to be a bounded, known-Square suffix) rather than any string starting with `order.` that isn't one of the five named exceptions.
        - Add regression tests asserting `order.evil.com`, `order.com`, and `order.foo` do **not** classify as Square.
    - **Technical:** The pattern `^order\.(?!online$|toasttab\.com$|ubereats\.com$|doordash\.com$|menulog\.com\.au$)` anchors only at the start (`^order\.`); the negative lookahead is a zero-width assertion that doesn't require matching to the end of the host, so `preg_match()` succeeds against *any* host beginning with `order.` other than the five explicit exceptions. `SafeUrlFetcher` still independently blocks private/reserved network targets at actual fetch time, so this is not an SSRF path — but it is a real platform-misclassification bug: a user (or an attacker steering a Google Business enrichment field) can get an arbitrary `order.*` host accepted and stored as a "Square" connection.
    - **Plain English:** The system recognizes Square's ordering pages with a short checklist, but one item on that checklist only checks the first six letters of the address (`order.`) and stops — so almost any web address starting with those six letters gets waved through as "this is Square," even if the rest of the address is something else entirely. The checklist needs to check the whole address, not just its start.
    - **Evidence:**
        ```php
        'host_pattern' => '~(^|\.)square\.site$|(^|\.)square\.com$|^order\.(?!online$|toasttab\.com$|ubereats\.com$|doordash\.com$|menulog\.com\.au$)~',
        ```

- [x] **#SEC-4** · P2 — `SafeUrlFetcher` replays the caller's exact headers on every redirect hop with no cross-origin stripping
    - **Where:** app/Services/Http/SafeUrlFetcher.php:196-224 (`send()`), :450-499 (`pooledGet()`)
    - **Affects:** Any current or future caller of `fetch()`/`post()`/`fetchMany()` that passes sensitive headers (`Authorization`, API keys, cookies) — a redirect to an attacker-controlled host would receive them unchanged. No current caller in this codebase passes such headers (all pass only `User-Agent`/`Accept`), so this is a latent primitive gap rather than an active leak today.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before following a redirect whose target host differs from the current host, drop `Authorization`, `Cookie`, and any caller-declared auth-style headers from the header set used for the next hop.
        - Add a regression test: a cross-host 302 with an `Authorization` header set must not carry that header to the redirect target.
    - **Technical:** `send()`'s redirect loop (`for ($hop = 0; ...)`) rebuilds the request each hop via `Http::withHeaders($headers)` using the *same* `$headers` array passed in on the very first call — there is no per-hop comparison of the current vs. next host before headers are reused, and `pooledGet()`'s pool-based path has the identical property (`$mergedHeaders` reused unchanged across every chunk and every redirect round). Every hop *is* re-validated for SSRF (`assertSafe($current)`), but header scope isn't part of that re-validation. This is a defense-in-depth gap in a shared primitive rather than an exploitable path today, since no caller in this codebase currently sends the class of header that would matter.
    - **Plain English:** Imagine a courier carrying a package with a security pass taped to it, meant for one specific building. If that building's front desk says "actually, deliver this next door instead," the courier follows the redirected address but leaves the same security pass taped on — and the building next door could be run by someone untrustworthy. Nothing in our system currently tapes a security pass onto these packages, so nobody is at risk today, but the courier's rulebook should still say "take the pass off before following a redirect to a different address," so a future addition can't quietly reopen this.
    - **Evidence:**
        ```php
        $request = Http::withHeaders($headers)
            ->timeout($hopTimeout)
            ->connectTimeout(min($this->connectTimeoutSeconds, $hopTimeout))
            ->withoutRedirecting();

        $response = $method === 'POST' ? $request->post($current, $body) : $request->get($current);
        // ...
        if ($status >= 300 && $status < 400 && $response->header('Location')) {
            $location = $response->header('Location');
            $current = $this->resolveRedirect($current, $location);
            // ...
            continue;
        }
        ```

- [x] **#SEC-5** · P2 — `MediaMirror`'s `content.media_assets` updates key only on `id`, never on `user_id`
    - **Where:** app/Services/Media/MediaMirror.php:101-103, :131-142
    - **Affects:** `content.media_assets` rows written by the Instagram media-mirror pipeline. Currently exploitable only if a future caller ever paired a mismatched `(userId, assetId)`; today `assetId` always originates from `ProjectionWriter::dispatchMirrors()`, which resolves it from a user-scoped lookup/insert in the same run, so the pairing is correct by construction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->where('user_id', $userId)` to both `content.media_assets` update queries in `MediaMirror::mirror()`.
        - Check the affected row count and `fail()` if it isn't exactly 1, so a future mismatched caller fails loudly instead of silently overwriting the wrong row.
    - **Technical:** `mirror(string $userId, string $assetId, string $sourceUrl)` receives the tenant id and uses it only to build the storage path (`'content-media/'.$userId.'/...'`); both `DB::table('content.media_assets')->where('id', $assetId)->update(...)` calls omit a `user_id` predicate entirely. The only current caller, `ProjectionWriter::dispatchMirrors()`, always derives `$assetId` from a `lookupMediaAssets($userId, ...)` read or a freshly `insertOrIgnore`'d row scoped to that same `$userId`, so no cross-tenant write is reachable today. The missing predicate is nonetheless a real tenant-scoping omission on a raw query-builder write against a tenant-owned table — exactly the pattern the doctrine asks every mutation to close, as a backstop against a future caller (or an `assetId` sourced from elsewhere) breaking the invariant silently.
    - **Plain English:** When the system updates a stored photo's file details, it looks the row up only by the photo's own ID number, not by also checking whose account it belongs to — like a warehouse worker updating a shelf's inventory using only the shelf number, without re-confirming whose shelf it is. Today, every caller of this code always hands over a shelf number and a matching customer, so nothing goes wrong in practice — but the check itself is missing, so if a future change ever mismatches the two, the system would happily overwrite someone else's photo record without noticing.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->table('content.media_assets')
            ->where('id', $assetId)
            ->update(['storage_path' => $path, 'mime_type' => 'video/mp4', 'variant_family' => 'native']);
        ```
        ```php
        DB::connection('pgsql')->table('content.media_assets')
            ->where('id', $assetId)
            ->update([
                'storage_path' => $path,
                'mime_type' => 'image/webp',
                'width' => $variant['width'],
                'height' => $variant['height'],
                'dims_confidence' => 'measured',
                'variant_family' => 'native',
            ]);
        ```

- [x] **#SEC-6** · P2 — `SafeUrlFetcher` follows a 3xx before applying its own byte cap to that hop's response
    - **Where:** app/Services/Http/SafeUrlFetcher.php:206-226 (`send()`), :396-424 (`fetchManyFollowingRedirects()`)
    - **Affects:** Any fetch target that returns an oversized body alongside a 3xx status, across both the serial and pooled fetch paths.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Call `assertWithinByteCap($response)` (or the pooled equivalent, `exceedsByteCap()`) before acting on a 3xx response, not only on the terminal (non-redirect) response.
    - **Technical:** In `send()`, the redirect branch (`if ($status >= 300 && $status < 400 ...)`) runs and `continue`s before `assertWithinByteCap($response)` is reached — that check only executes on the loop's terminal, non-redirect return. The pooled path has the identical gap: `exceedsByteCap()` is checked only in the terminal `else` branch, never against a 3xx response. Guzzle has already buffered the response body into memory by the time the status is inspected either way (the class's own docblock acknowledges this cap "cannot prevent the download itself"), so this doesn't change per-hop memory pressure — but it does mean an oversized 3xx body is silently accepted and its (unvalidated) redirect chased, rather than the fetch failing fast at the first oversized hop.
    - **Plain English:** The size check on incoming pages only runs at the very last stop of a journey, not at any stopover along the way. If an early stop hands over an oversized package and simply says "go to this next address instead," the oversized package sails through unnoticed because the size check hasn't run yet. The check should apply at every stop, not just the final one.
    - **Evidence:**
        ```php
        // Follow 3xx manually so each Location is re-validated.
        if ($status >= 300 && $status < 400 && $response->header('Location')) {
            $location = $response->header('Location');
            $current = $this->resolveRedirect($current, $location);
            // ...
            continue;
        }

        $this->assertWithinByteCap($response);
        ```

- [x] **#SEC-7** · P2 — Link-in-bio custom-link write stores the raw pasted URL, bypassing the secret-param redaction the sibling "note" branch applies
    - **Where:** app/Http/Controllers/Api/Routing/RoutingController.php:67, :79 (link-in-bio branch); compare :118 (note branch)
    - **Affects:** Any user pasting a link-in-bio page URL (linktr.ee, beacons.ai, msha.ke, stan.store) that carries a query-string token — the raw URL is persisted as a public custom-link card and dispatched to a scan job verbatim.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route the link-in-bio branch's URL through `IriCanonicalizer::canonicalize()` (which already strips `SecretParams::isSecret()`-flagged query params via `filterQuery()`) before calling `$this->links->addManual()`, matching the pattern the "note" branch already uses (`$result['canonicalUrl'] ?? SecretParams::redactUrl($url) ?? ''`).
        - Add a regression case: a link-in-bio URL such as `https://linktr.ee/x?token=secret` must not store `token=secret` in the persisted card.
    - **Technical:** `CustomLinkSeeder::addManual()` has exactly two call sites in this file. The "note" branch (line 118) deliberately never stores the raw `$url`: it prefers `$result['canonicalUrl']` (itself secret-filtered by `IriCanonicalizer::filterQuery()`, which drops any `SecretParams::isSecret()` query param before producing the canonical string) and falls back to `SecretParams::redactUrl($url)`, with an explicit comment ("never fall back to the raw, possibly-secret-bearing URL"). The link-in-bio branch (line 67) calls `$this->links->addManual($user, trim($url))` with the untouched raw input — `CustomLinkSeeder::addManual()` → `LinkCardScraper::normalizeUrl()` only prepends `https://` and validates host shape; it does no query-param filtering. The two branches sit five lines apart in the same method, one of them explicitly guards against exactly this leak, and the other doesn't — a straightforward inconsistency, not a deliberate distinction.
    - **Plain English:** When someone pastes a link to their own "link-in-bio" page, our system saves that address exactly as typed — including any hidden tracking or access token baked into the web address — and can publish it on their public site. Just a few lines away, a very similar piece of code is careful to strip those hidden tokens out before saving, specifically because it doesn't want to accidentally publish someone's private token to the whole internet. The link-in-bio path was simply missed when that protection was added.
    - **Evidence:**
        ```php
        if (app(LinkInBioDetector::class)->matches($url)) {
            $write = $this->links->addManual($user, trim($url));
        // ...
            LinkInBioScanJob::dispatch((string) $user->id, trim($url), AccountCapabilities::for($user)->can_use_booking);
        ```
        ```php
        // redactUrl() fails closed (returns '' on a PCRE engine error), so
        // this fallback is unreachable for the validated, non-null $url —
        // but never fall back to the raw, possibly-secret-bearing URL.
        $write = $this->links->addManual($user, $result['canonicalUrl'] ?? SecretParams::redactUrl($url) ?? '');
        ```

## P3 — Nice to have

- [x] **#SEC-8** · P3 — `DisplaySettingsController::update` validates inline instead of via a Form Request
    - **Where:** app/Http/Controllers/Api/Platforms/DisplaySettingsController.php:86-89
    - **Affects:** `PATCH /api/platforms/{platform}/display-settings`. No exploitable mass-assignment today — the controller only reads `$validated['toggles']` and separately allowlists keys against the registry — but it's a doctrine deviation from the project's standard "Form Request classes for validation" convention.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the two-rule validation into a small Form Request (e.g. `UpdateDisplaySettingsRequest`) and inject it in place of `Request`.
    - **Technical:** Every other mutation endpoint in this file's sibling controllers resolves a Form Request; this one calls `$request->validate([...])` inline. The current rule set is simple and the controller already separately rejects unknown toggle keys, so there is no live mass-assignment risk — this is purely a convention gap that makes the next person's job of adding a field correctly slightly easier to get right.
    - **Plain English:** Most of this system's forms go through a dedicated, reviewed checklist before any data is accepted. This one endpoint checks its two fields by hand instead. It happens to be correct today, but it's easy for the next person adding a field here to forget a check that the standard checklist would have caught automatically.
    - **Evidence:**
        ```php
        $validated = $request->validate([
            'toggles' => ['required', 'array'],
            'toggles.*' => ['boolean'],
        ]);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Media-mirror hardening:** #SEC-1, #SEC-5
    - **Why grouped:** Both are in `app/Services/Media/MediaMirror.php` (one also touches its `WebpEncoder` collaborator) — one session, one focused review of the mirror pipeline's write/decode safety.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — SafeUrlFetcher redirect-loop hardening:** #SEC-4, #SEC-6
    - **Why grouped:** Both are in the same redirect-following loop in `SafeUrlFetcher::send()`/`fetchManyFollowingRedirects()` — fixing them together avoids touching that loop twice.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Platform config/validation hygiene:** #SEC-3, #SEC-8
    - **Why grouped:** Two small, independent, low-risk correctness/convention fixes with no shared file — bundled purely to avoid two near-trivial standalone sessions.
    - **Model:** Plan: Sonnet · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEC-2 — Google Business photo attribution PII leak** · standalone: third-party PII/GDPR exposure on a live ingest connector — touches data already landed for existing unclaimed listings, so the fix needs its own review plus a decision on whether a backfill/redaction pass over already-stored `content.media_assets.attribution` rows is required (data remediation, not just a code fix).
- **#SEC-7 — Link-in-bio raw-URL secret leak** · standalone: touches the public-wire custom-link write path (`RoutingController::store`) shared with the booking/reservation reconciler flow — worth its own focused review given the adjacent lock/XOR discipline already documented in that file.
