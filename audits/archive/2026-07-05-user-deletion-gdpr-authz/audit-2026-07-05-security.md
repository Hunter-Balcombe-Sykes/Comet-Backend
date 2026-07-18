# Security Audit — 2026-07-05

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Policies/SitePolicy.php, app/Policies/ServicePolicy.php, app/Policies/CustomerPolicy.php, app/Policies/BasePolicy.php, app/Policies/PartnaStaffPolicy.php
- app/Providers/AppServiceProvider.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php, VerifySupabaseAuthHookSignature.php, VerifySupabaseEmailHookSignature.php, VerifyBotToken.php, IdempotencyKey.php, Context/EnforcePendingDeletionReadOnly.php
- app/Services/Webhooks/StandardWebhookVerifier.php
- app/Http/Controllers/Api/Staff/** (UserSiteManagement, StaffSite)
- app/Http/Controllers/Api/User/** (SiteManagement, Account, Customers, Notifications, Site)
- app/Http/Controllers/Api/PublicSite/** (AnalyticsController, PublicSignupAvailabilityController, BootstrapController, PublicEnquiryController)
- app/Http/Requests/Api/** (BootstrapRequest, UpdateUserRequest, User/Uploads/UploadImageRequest, PublicSite/Analytics/*, PublicSite/PublicEnquiryRequest, Staff/**, Concerns/SniffsFileMimeType)
- app/Models/Core/Site/Workplace.php, SiteMedia.php, app/Models/Core/User/Customer.php, User.php
- app/Services/Platforms/PlatformInput.php, PlatformScraper.php, WooCommerceScraper.php, ShopifyScraper.php, GoogleBusinessService.php, YoutubeThumbnailResolver.php
- app/Services/Media/LogoProcessorClient.php
- routes/api/staff.php, routes/api/user.php, routes/api.php, bootstrap/app.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-1** · P1 — Video upload path skips the finfo byte-sniff that the image path enforces
    - **Where:** app/Http/Requests/Api/User/Uploads/UploadImageRequest.php:36-42, 48-76
    - **Affects:** Any authenticated user uploading a "video" file to a gallery/media pool — a forged file (wrong bytes, `.mp4` extension, forged `video/mp4` header) reaches the media storage/processing pipeline unchecked.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a byte-sniff check on the `video` field in `withValidator()` (finfo against an allowed video-MIME whitelist), mirroring `assertImageMimeBytes()`.
        - Extend `SniffsFileMimeType` with a video-oriented assertion so both fields share the same trait.
    - **Technical:** `UploadImageRequest::rules()` validates `video` only via `mimes:mp4,mov,webm` — a rule that trusts the client-declared extension/Content-Type. `withValidator()` calls `$this->assertImageMimeBytes($this->file('image'), $v, 'image')` only when `$hasImage` is true; there is no equivalent call for `$hasVideo`. This means a file with a spoofed `.mp4` extension and forged `video/mp4` Content-Type but arbitrary bytes passes all validation and is handed to the media pipeline as trusted video content — the exact gap the lens's canonical `finfo` byte-sniff requirement exists to close.
    - **Plain English:** When someone uploads a photo, the server actually opens the file and checks it's really a picture before accepting it. When someone uploads a video, the server only checks the label on the box ("this says .mp4") without opening it. Someone could put something harmful inside a box labeled "video" and the server would wave it through. The fix is to open the video box and check its contents too, exactly like it already does for photos.
    - **Evidence:**
        ```php
        'video' => [
            'sometimes',
            'nullable',
            'file',
            'mimes:mp4,mov,webm',
            "max:{$videoMaxKb}",
        ],
        ```
        ```php
        if ($hasImage) {
            $this->assertImageMimeBytes($this->file('image'), $v, 'image');
        }
        ```

## P2 — Should fix

- [ ] **#SEC-2** · P2 — `LogoProcessorClient` embeds the raw upstream response body in exception messages
    - **Where:** app/Services/Media/LogoProcessorClient.php:47-51
    - **Affects:** Nightwatch / Horizon failed-job record retention for logo-processing failures.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Drop the response-body substring from the exception message; log the status code only.
        - Match the established pattern in `SupabaseAdminService::unenrollMfaFactor`, which deliberately excludes response bodies for the same reason.
    - **Technical:** On a non-2xx response, the exception message includes up to 300 characters of the raw upstream body (`mb_substr((string) $response->body(), 0, 300)`). The codebase already has a documented precedent for exactly this class of leak — `SupabaseAdminService::unenrollMfaFactor` strips the response body from its exception message with an explicit comment: "the response body cannot be embedded in the exception message, which would otherwise persist into Horizon failed-job records." `LogoProcessorClient` doesn't follow that convention, so any internal error detail the logo processor returns (paths, stack hints, config) persists into long-lived log/failed-job storage.
    - **Plain English:** When the logo-processing service fails, the error message that gets permanently saved to our logs includes whatever raw text that service sent back — which could include details we don't want sitting around indefinitely. The team already solved this exact problem elsewhere in the code (the Supabase admin service) by only keeping the status code, not the message body. This file should do the same.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            throw new LogoProcessorException(
                "Logo processor returned HTTP {$response->status()}: ".mb_substr((string) $response->body(), 0, 300)
            );
        }
        ```

- [ ] **#SEC-3** · P2 — `PlatformInput::urlish()` passes `javascript:`/`data:` schemes through unvalidated
    - **Where:** app/Services/Platforms/PlatformInput.php:38-40
    - **Affects:** Any downstream code path that stores or renders the result of `urlish()` without its own scheme check.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Reject non-`http(s)` schemes (`javascript:`, `data:`, `file:`, etc.) directly in `urlish()` rather than deferring to "the platform parser."
        - Return an empty string (or a typed exception) for a dangerous scheme so no caller can accidentally forward it.
    - **Technical:** `urlish()` is the shared normalization entry point for every user-pasted platform link/handle across connect strategies and detectors. The current code explicitly passes through any string matching a generic URI-scheme pattern — including `javascript:` and `data:` — with a comment deferring rejection to "the platform parser." `SafeUrlFetcher` would reject such a scheme at actual fetch time, but not every consumer of `urlish()`'s return value routes through a fetch (e.g., values stored directly into a JSONB payload). Rejecting at the shared normalization gate is safer than relying on every future caller to re-implement the check.
    - **Plain English:** Every link a user pastes into the platform-connect flow goes through one cleanup function first. That function is careful to add `https://` to bare domains, but it explicitly lets dangerous-looking links (like ones starting with `javascript:`) through untouched, trusting a later step to catch them. It's safer to stop those at the front door instead of trusting every downstream door to check.
    - **Evidence:**
        ```php
        // Other schemes (ftp:, mailto:, javascript:) are never platform links —
        // return as-is and let the platform parser reject them.
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $s)) {
            return $s;
        }
        ```

- [ ] **#SEC-4** · P2 — `PlatformScraper::absoluteUrl()` accepts any string that merely starts with "http"
    - **Where:** app/Services/Platforms/PlatformScraper.php:197-207
    - **Affects:** All scraped brand logos, favicons, and product images across every platform scraper (Shopify, WooCommerce, Squarespace, Big Cartel, Generic, Skool, Strava, Twitch) that call this shared resolver.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `filter_var($url, FILTER_VALIDATE_URL)` guard (or an `http(s)://` regex) before returning a `str_starts_with($url, 'http')` match verbatim.
    - **Technical:** `absoluteUrl()` is the shared URL resolver every scraper calls to build logo/favicon/product-image URLs from scraped markup. Its check is a bare `str_starts_with($url, 'http')` — a malformed string like `httpTRASH://evil.example` passes this check and is stored verbatim into the database, later rendered on the dashboard and public sitepage. `SafeUrlFetcher` would reject it if anyone tried to fetch it, but the stored value itself is what gets rendered in an `<img src>`/`<a href>`, so validating at write-time (not just at fetch-time) is the correct defense-in-depth layer.
    - **Plain English:** When the system pulls a logo or product photo URL out of a scraped web page, it does a quick check — "does this start with the letters h-t-t-p?" — and if so, trusts it completely. A garbled string that happens to start with those letters but isn't a real web address would still pass and get stored, then later shown on someone's public page. A proper "is this actually a valid web address" check closes that gap.
    - **Evidence:**
        ```php
        protected function absoluteUrl(string $url, string $origin): string
        {
            if (str_starts_with($url, '//')) {
                return 'https:'.$url;
            }
            if (str_starts_with($url, 'http')) {
                return $url;
            }

            return $origin.'/'.ltrim($url, '/');
        }
        ```

- [ ] **#SEC-5** · P2 — `BootstrapController` logs a raw email address and Supabase UID on rejected signup
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php:92-106
    - **Affects:** Log aggregator (Nightwatch) retention of PII for every duplicate-email signup attempt.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'email' => $email` with `'email_hash' => hash('sha256', $email)`, matching the existing convention in `PublicEmailSubscriptionController`.
    - **Technical:** `translateBootstrapException()` logs `Log::info('Bootstrap rejected: email already registered to another auth user', ['uid' => $uid, 'email' => $email])` — a clear-text email and Supabase auth UID persisted into the log stream on every collision. The codebase already has an established pattern for exactly this situation: `PublicEmailSubscriptionController` logs `'email_hash' => hash('sha256', $email)` instead of the raw address. This file is the outlier.
    - **Plain English:** When someone tries to sign up with an email that's already taken, the server writes that person's actual email address into its permanent diagnostic logs. Other parts of the app already know better — they write a scrambled fingerprint of the email instead, which is still useful for debugging but doesn't expose the real address. This file should do the same.
    - **Evidence:**
        ```php
        Log::info('Bootstrap rejected: email already registered to another auth user', [
            'uid' => $uid,
            'email' => $email,
        ]);
        ```

- [ ] **#SEC-6** · P2 — Six Form Request classes extend `FormRequest` instead of `BaseFormRequest`, bypassing the `final` `authorize()` guard
    - **Where:** app/Http/Requests/Api/Staff/Notifications/StaffStoreNotificationRequest.php:9, app/Http/Requests/Api/Staff/FeatureFlag/CreateFeatureFlagRequest.php:7, CreateOverrideRequest.php:7, UpdateFeatureFlagRequest.php:7, app/Http/Requests/Api/Staff/UserSite/StaffBulkUpdateStatusRequest.php:8, StaffUpdateUserStatusRequest.php:8
    - **Affects:** Six staff-facing Form Requests (feature-flag admin, staff notification broadcast, bulk/single user status) — no functional difference today, but they lose the centralized guard against future drift.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change all six classes to `extends BaseFormRequest` and delete their now-redundant `authorize(): bool { return true; }` overrides.
    - **Technical:** `BaseFormRequest::authorize()` is declared `final` specifically because `Auth::user()` is always null under Supabase JWT — the class's docblock states any override risks silently reintroducing a broken auth check. These six classes extend the raw `Illuminate\Foundation\Http\FormRequest` and independently declare `authorize(): bool { return true; }`. Functionally identical today (all routes are already gated by `staff`/`staff.admin` middleware), but the `final` lock exists precisely to prevent this pattern from drifting; these six are outside its protection.
    - **Plain English:** The team set up one shared "form validation" base class with a locked-down rule that always allows submission (since the real security check happens elsewhere, via staff login middleware). Six files quietly built on a different, unlocked foundation instead — they behave identically today, but they're not protected if that shared rule is ever tightened. Moving them onto the standard foundation costs nothing and closes a small consistency gap.
    - **Evidence:**
        ```php
        class StaffStoreNotificationRequest extends FormRequest
        {
            public function authorize(): bool
            {
                return true; // authorization enforced at controller via middleware
            }
        ```
        ```php
        class CreateFeatureFlagRequest extends FormRequest
        class CreateOverrideRequest extends FormRequest
        class UpdateFeatureFlagRequest extends FormRequest
        class StaffBulkUpdateStatusRequest extends FormRequest
        class StaffUpdateUserStatusRequest extends FormRequest
        ```

- [ ] **#SEC-7** · P2 — Assigning a service to another user's category returns 422 instead of 404, leaking its existence
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php:330-345
    - **Affects:** Authenticated users creating/updating a service with a `category_id` — reveals whether a given category UUID belongs to someone else.
    - **Technical:** `assertCategoryBelongsToProfessional()` checks the candidate category is owned by the current user; on failure it calls `abort(422, 'Category is invalid.')`. The house standard (documented in CLAUDE.md's 403-vs-404 section, and applied everywhere else via `denyAsNotFound()`) is 404 for "doesn't exist or isn't yours," specifically to avoid confirming existence to a non-owner. Category UUIDs aren't guessable, so practical enumeration value is low, but this is a clear, mechanical deviation from the documented invariant.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `abort(422, 'Category is invalid.')` to `abort(404)`.
    - **Plain English:** If you try to attach your service to a category that actually belongs to a different professional, the server currently says "that category is invalid" — a response that's subtly different from "not found," and which confirms to a curious user that the category ID they guessed genuinely exists somewhere. It should just say "not found," same as if the category never existed at all.
    - **Evidence:**
        ```php
        $ok = ServiceCategory::query()
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $ok) {
            abort(422, 'Category is invalid.');
        }
        ```

- [ ] **#SEC-8** · P2 — `BootstrapRequest::generateHandleFromDisplayName()` has a check-then-insert race on handle uniqueness
    - **Where:** app/Http/Requests/Api/BootstrapRequest.php:137-155
    - **Affects:** New users signing up concurrently with the same/similar display name — one of the two can hit an unhandled unique-constraint violation instead of a clean auto-incremented handle.
    - **Effort:** M (~2h)
    - **What to do:**
        - Wrap the check-and-generate loop in a retry: on a caught unique-violation `QueryException` from the eventual insert, regenerate with the next suffix and retry a bounded number of times.
        - Alternatively, serialize handle generation with a Postgres advisory lock scoped to the slugified base name.
    - **Technical:** The loop performs a plain `exists()` check per candidate handle with no locking; the actual insert happens later in the bootstrap flow. Two concurrent signups whose display names slugify to the same base (e.g., both "Sarah Jones" → `sarah-jones`) can both observe `exists() === false` for the same candidate and both attempt to insert it. The DB does have a real backstop — `core_users_handle_lc_unique` (a partial unique index on `handle_lc`) — so no duplicate handle is ever persisted, but the loser of the race gets an unhandled `QueryException` (raw 500) rather than a graceful retry onto the next suffix. Given the documented scale context (viral traffic spikes), this is a real, if narrow, reliability gap.
    - **Plain English:** When two people with very similar names sign up at almost the exact same moment, the system checks "is this web address taken?" for both of them at the same time, gets "no" for both, and tries to give them the identical address. The database itself won't let two people end up with the same address, but the person who loses that race currently gets a confusing server error instead of automatically being handed the next available option.
    - **Evidence:**
        ```php
        $handle = $base;
        $attempt = 1;
        while (User::query()->where('handle_lc', strtolower($handle))->exists()) {
            $handle = $base.$attempt;
            $attempt++;
        }
        ```

## P3 — Nice to have

- [ ] **#SEC-9** · P3 — `UserSelfController::show()` returns the internal `supabase_uid` in the dashboard payload
    - **Where:** app/Http/Controllers/Api/User/Account/UserSelfController.php:22-56
    - **Affects:** Authenticated dashboard users — their own Supabase Auth UID is echoed back in the `/me` response.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Drop `'uid' => $uid` from the response unless the frontend has a confirmed dependency on it; if it does, document why it's needed.
    - **Technical:** The dashboard payload includes the resolved `supabase_uid` request attribute as a top-level `uid` key. Since this is returned only to the account's own authenticated owner (not to any other tenant), there's no cross-tenant exposure — the practical risk is limited to unnecessarily handing back an internal auth-system identifier that has no use to the frontend beyond what the `professional` resource's own UUID already provides.
    - **Plain English:** The dashboard's "who am I" response includes your internal sign-in system ID, not just your regular account ID. You're only ever shown your own ID, so this isn't a data leak between users — it's just an unnecessary extra piece of internal plumbing exposed to the browser for no clear benefit.
    - **Evidence:**
        ```php
        $uid = $request->attributes->get('supabase_uid');
        ...
        return $this->success([
            'uid' => $uid,
            ...$payload,
        ```

- [ ] **#SEC-10** · P3 — `Workplace` policy registration is unreachable/broken, and five write endpoints rely solely on the global pending-deletion middleware instead of a Policy gate
    - **Where:** app/Policies/SitePolicy.php:63-95, app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php (upsert/destroy/setPreviousWebsite), app/Http/Controllers/Api/User/Customers/UserEnquiryController.php:165-180 (destroy), app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php:213-260 (reorderLayout), app/Http/Controllers/Api/User/Site/HandleReclaimController.php, app/Http/Controllers/Api/User/Notifications/ConfirmationPreferenceController.php
    - **Affects:** No current user-facing impact (verified: `Workplace` is always resolved via `currentSite($professional)`, never a request-supplied ID, so no IDOR is possible; pending-deletion accounts are already blocked platform-wide by `EnforcePendingDeletionReadOnly` middleware on the `user.api` group for every non-GET request). This is a latent-landmine + doctrine-consistency finding, not an active vulnerability.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `Workplace` handling to `SitePolicy::resolveOwnerId()` (resolve via the `site` relation, same as `SiteMedia`/`SiteSubdomainAlias`) so the registered policy actually works if anything ever calls it — today it would incorrectly deny every request, including the legitimate owner's.
        - Add `authorizeForUser(...)` calls to the five listed write endpoints for consistency with the documented Policy-gate doctrine and to protect any future non-HTTP invocation path (console command, queued job) that wouldn't pass through `EnforcePendingDeletionReadOnly`.
    - **Technical:** `SitePolicy::resolveOwnerId()` special-cases only `SiteMedia`/`SiteSubdomainAlias` for site-relation-based ownership; `Workplace` (registered via `Gate::policy(Workplace::class, SitePolicy::class)` in `AppServiceProvider::boot()`) falls through to the direct `user_id` branch. `Workplace` has no `user_id` column at all (only `site_id`, its PK/FK) — so if anything ever called `authorizeForUser($user, 'view'|'update', $workplace)`, `resolveOwnerId()` would return `null` and deny access to everyone, including the actual owner. In practice this is currently moot: `UserWorkplaceController` never calls `authorizeForUser` on any action, so the broken registration is simply dead code today — but it's a landmine for whoever "fixes" the missing-authorization gap by bolting on a naive `authorizeForUser` call. Separately, `UserEnquiryController::destroy()`, `UserServiceController::reorderLayout()`, `HandleReclaimController::store()`, and `ConfirmationPreferenceController::update()` all mutate state without a Policy gate — but all are HTTP-only, ownership-scoped by `->where('user_id', ...)` or `currentUser()`/`currentSite()` (no request-supplied cross-tenant ID), and the account-state gate they'd otherwise provide (`denyIfPendingDeletion`) is already enforced upstream by `EnforcePendingDeletionReadOnly` middleware on every non-safe HTTP method in the `user.api` group — confirmed via `BasePolicy`'s own docblock, which describes the Policy-level check as existing specifically to "mirror" that middleware for non-HTTP callers (background jobs, console commands).
    - **Plain English:** A security rule was written for the "workplace card" feature, but it was written for the wrong shape of data — if anyone ever turns it on, it would lock everyone out of their own workplace card, including its rightful owner. Right now nobody has turned it on, so nothing is broken today, but it's a trap waiting for whoever tries to "fix" this later. Separately, a handful of settings pages (workplace, enquiry deletion, service reordering, handle reclaim, notification preferences) skip the extra permission check that most similar pages have — but a site-wide safety net already blocks anyone whose account is scheduled for deletion from making any changes, so there's no real gap today, just an inconsistency worth tidying up.
    - **Evidence:**
        ```php
        if ($resource instanceof SiteMedia || $resource instanceof SiteSubdomainAlias) {
            $site = $resource->getRelation('site');
            ...
            return (string) $site->user_id;
        }

        // Direct: Site itself plus denormalized user_id on Block/Enquiry/LeadSubmission.
        $rawAttrs = $resource->getAttributes();

        return array_key_exists('user_id', $rawAttrs) && $rawAttrs['user_id'] !== null
            ? (string) $rawAttrs['user_id']
            : null;
        ```
        ```php
        // AppServiceProvider::boot()
        // FOUND-4: workplace card model. Owned via its parent Site so it maps
        // to the parent's policy.
        Gate::policy(Workplace::class, SitePolicy::class);
        ```
        ```php
        // UserEnquiryController::destroy — no authorizeForUser call
        $enquiry = Enquiry::query()->where('user_id', $pro->id)->find($id);
        if (! $enquiry) {
            return $this->error('Enquiry not found.', 404);
        }
        $enquiry->delete();
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Outbound URL validation hardening:** #SEC-3, #SEC-4
    - **Why grouped:** Same subsystem (`app/Services/Platforms`), same root-cause pattern (a shared URL-normalization helper trusts input shape instead of validating it).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Signup/bootstrap flow hygiene:** #SEC-5, #SEC-8
    - **Why grouped:** Both live in the signup/bootstrap feature area (`BootstrapController` + `BootstrapRequest`) and are small, independent fixes within the same flow.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Form Request base-class consistency:** #SEC-6
    - **Why grouped:** Six files, identical one-line fix pattern (`extends FormRequest` → `extends BaseFormRequest`, delete redundant `authorize()`).
    - **Model:** Plan+Implement combinable (Sonnet) given mechanical nature; Review: Sonnet.

- **Bundle 4 — Dashboard/site-management minor hygiene:** #SEC-7, #SEC-9
    - **Why grouped:** Both are small, single-line-fix hygiene items in the User API surface (`UserServiceController`, `UserSelfController`) with no shared root cause beyond "quick doctrine cleanup" — bundled purely for session efficiency.
    - **Model:** Plan+Implement combinable (Sonnet); Review: Sonnet.

- **Bundle 5 — Workplace policy + pending-deletion authorization consistency sweep:** #SEC-10
    - **Why grouped:** Single finding spanning `SitePolicy` + five controllers; must be planned as one coherent change since fixing `resolveOwnerId()` and adding the controller-side `authorizeForUser` calls are interdependent (adding the call without first fixing the policy would break the Workplace feature entirely).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEC-1 — Video upload skips finfo byte-sniff** · touches upload/media-processing content validation directly (the lens's named "hot zone"); run and verify independently rather than folding into an unrelated bundle.
- **#SEC-2 — LogoProcessorClient exception message leak** · isolated fix with no natural bundle partner (different subsystem from every other finding); small enough to run solo.
