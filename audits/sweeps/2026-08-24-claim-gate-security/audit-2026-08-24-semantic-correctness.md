# Semantic Correctness Audit — 2026-08-24

**Branch:** development
**Lens:** Semantic Correctness — code that compiles and type-checks but does the wrong thing (plausible-but-wrong logic, config/flag misuse, magic-value drift, intent-contradicting logic, codebase-idiom drift)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/EarlyAccess/EarlyAccessService.php
- app/Services/PreAccount/ClaimSiteService.php
- app/Services/PreAccount/PreAccountBuildException.php
- app/Services/PreAccount/PreAccountBuildService.php
- app/Http/Controllers/Api/PublicSite/ClaimController.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php
- app/Http/Requests/Api/Staff/UserSite/StaffAttachContactEmailRequest.php
- app/Models/Core/User/PreAccountBuild.php
- app/Services/Site/SocialLinkNormalizer.php
- bootstrap/app.php
- config/partna.php
- routes/api/staff.php
- routes/api/user.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 1 of 3 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEM-1** · P1 — LinkedIn and Spotify extractors accept a path shape their single `url_template` cannot rebuild, silently rewriting company/artist links into broken personal-profile URLs
    - **Where:** config/partna.php:486-489 (linkedin), config/partna.php:532-535 (spotify); consumed by app/Services/Site/SocialLinkNormalizer.php:186-194 and :138-157
    - **Affects:** Any professional linking a LinkedIn company page or a Spotify artist page from their sitepage — the stored, canonical link visitors click is wrong and 404s on the target platform.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Preserve the matched path-shape discriminator (`in`/`company`, `user`/`artist`) through `SocialLinkNormalizer::normalizeUrl()` and select the matching `url_template` variant, instead of collapsing both into one template.
        - Alternatively, narrow `url_path_extractor` to only the shape `url_template` can reproduce, and let the other shape fall through to the existing lenient deep-link fallback (which preserves the original path/host as-is).
        - Add a regression test pasting `https://linkedin.com/company/{x}` and `https://open.spotify.com/artist/{x}` and asserting the stored URL keeps the original path segment (or at minimum does not silently become `/in/{x}` / `/user/{x}`).
    - **Technical:** `SocialLinkNormalizer::normalizeUrl()` (`app/Services/Site/SocialLinkNormalizer.php:192-193`) matches the incoming path against `url_path_extractor`, and on a match recurses into `normalizeHandle()` (`:150-151`), which rebuilds the canonical URL via `str_replace('{handle}', $cleaned, $config['url_template'])`. For `linkedin`, `url_path_extractor` is `#^/(?:in|company)/([a-zA-Z0-9-]{3,100})/?$#` — it matches both `/in/{handle}` and `/company/{handle}` — but `url_template` is hardcoded to `https://linkedin.com/in/{handle}`. The capture group discards which alternative matched, so a pasted `https://linkedin.com/company/acme-corp` is rebuilt as `https://linkedin.com/in/acme-corp`, a URL LinkedIn will 404 on (`/in/` and `/company/` are disjoint namespaces). The identical defect exists for `spotify`: `url_path_extractor` matches both `/user/{id}` and `/artist/{id}`, but `url_template` only rebuilds `/user/{handle}`, so an artist page becomes a non-existent user profile URL. The config's own comments (`// Matches both /in/{handle} (personal) and /company/{handle} (company pages)` and the Spotify equivalent) show the dual-shape acceptance is deliberate — this is a documented, common-path scenario, not a hypothetical.
    - **Plain English:** Imagine a business-card printer that accepts both a home address and a work address, but every card it prints out only ever shows the home-address template with the work address's street number stuck onto it — so anyone using the printed card to visit is sent to a house that doesn't have that address. Someone pastes their LinkedIn company page or Spotify artist link expecting it to work, and instead a broken link gets published on their public sitepage.
    - **Evidence:**
        ```php
        // config/partna.php — linkedin
        'url_template' => 'https://linkedin.com/in/{handle}',
        // Matches both /in/{handle} (personal) and /company/{handle} (company pages)
        'url_path_extractor' => '#^/(?:in|company)/([a-zA-Z0-9-]{3,100})/?$#',

        // config/partna.php — spotify
        'url_template' => 'https://open.spotify.com/user/{handle}',
        // Matches /user/{handle} (profiles) and /artist/{id} (artist pages)
        'url_path_extractor' => '#^/(?:user|artist)/([a-zA-Z0-9._-]{3,40})/?$#',
        ```
        ```php
        // app/Services/Site/SocialLinkNormalizer.php — normalizeUrl()
        if (preg_match($config['url_path_extractor'], $path, $matches) === 1) {
            return $this->normalizeHandle($platformKey, $config, $matches[1]);
        }

        // normalizeHandle()
        return [
            'url' => str_replace('{handle}', $cleaned, $config['url_template']),
            ...
        ];
        ```

## P2 — Should fix

- [ ] **#SEM-2** · P2 — `isOutreach()` reverts a staff-built site to first-come claiming if the creating staff row is later hard-deleted
    - **Where:** app/Models/Core/User/PreAccountBuild.php:122-126
    - **Affects:** Outreach pre-account builds (real businesses scraped and profiled by staff before they know Partna exists) whose creating staff account is later removed — the claim invite-gate silently disengages.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `|| $this->built_via === self::VIA_STAFF` to `isOutreach()` so the classification survives `built_by_staff_id` being nulled.
        - Add a regression test for a `VIA_STAFF` build with `built_by_staff_id === null`, asserting `isOutreach()` still returns `true`.
    - **Technical:** `built_by_staff_id` carries `ON DELETE SET NULL` (`supabase/migrations/20260726000000_baseline_pilot.sql:3707`: `FOREIGN KEY ("built_by_staff_id") REFERENCES "core"."partna_staff"("id") ON DELETE SET NULL`). `isOutreach()` currently keys off `built_by_staff_id !== null`. If the staff row is hard-deleted, that column nulls out but `built_via` (a separate, unaffected text column) stays `'staff'` — `PreAccountBuildService::requestBuild()` sets `built_via = 'staff'` only when `$staff` is present (`built_via` line 174), and the public unauthenticated endpoint (`PreAccountBuildController::store()`) never passes `$staff` or `builtVia`, so `built_via === 'staff'` can only originate from an actual staff-authenticated write. Checking `built_via` in addition to `built_by_staff_id` is therefore safe and restores the invariant the model's own docblock states: an outreach build "must only ever be claimed by an invited address, never first-come."
    - **Plain English:** An employee builds a preview page for a real local business before that business has heard of us — that page is supposed to stay locked until we personally invite the right person to claim it. If that employee later leaves and their staff account gets deleted, the system currently forgets the page was staff-made and treats it as an open, first-come page — meaning anyone who guesses the web address could claim someone else's business listing.
    - **Evidence:**
        ```php
        public function isOutreach(): bool
        {
            return $this->built_by_staff_id !== null
                || $this->built_via === self::VIA_EARLY_ACCESS;
        }
        ```
        ```php
         * @property string|null $built_by_staff_id FK to core.partna_staff.id, ON DELETE SET NULL. NULL for signup-originated builds. Not fillable — set via ->builtByStaff()->associate().
        ```

- [x] **#SEM-3** · P2 — `CLAIM_NOT_INVITED` returns a distinct 409 on the public claim endpoint, creating the exact oracle its own inline comment says it must avoid
    - **Where:** app/Http/Controllers/Api/PublicSite/ClaimController.php:54-64
    - **Affects:** The unauthenticated-but-JWT-required public claim endpoint (`POST /api/claim`); any caller with a valid Supabase account can distinguish "no site at this handle" from "a staff-groomed outreach site exists here awaiting invite."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Map `CLAIM_NOT_INVITED` to the same 404 response shape used for `CLAIM_NOT_FOUND` (or otherwise make it byte-for-byte indistinguishable), removing the distinguishing `code` and message from the public response.
        - If staff need a way to check invite status, expose it through the already-authenticated staff surface (`StaffPreAccountBuildResource`), not the public endpoint.
    - **Technical:** `ClaimSiteService::claim()` throws `CLAIM_NOT_FOUND` when no site/professional row exists (`app/Services/PreAccount/ClaimSiteService.php:46-53`) and `CLAIM_NOT_INVITED` when the row exists, is an outreach build, and has no `contact_email` attached yet (`:86-88`). `ClaimController` maps the former to a bare 404 (`'CLAIM_NOT_FOUND' => $this->error('No site found for that address.', 404, ...)`) but the latter to a distinct 409 with its own message and `code`. The branch's own inline comment states the response "Deliberately does NOT confirm the site exists in any way a bare 404 wouldn't ... it must not become an oracle for 'this handle is a staff-built site worth taking'" — but the 404-vs-409 status split, plus the distinct `CLAIM_NOT_INVITED` code, is precisely that oracle: any caller (needing only a free Supabase account, not staff credentials) can enumerate handles and learn which ones are staff-groomed outreach targets awaiting a personalized invite, versus ordinary self-serve or nonexistent handles.
    - **Plain English:** It's like a receptionist who says "no such office" for unknown addresses, but for a specific set of hidden addresses says "that one exists, it's just not open to visitors yet." Anyone can sweep through addresses to find out which businesses staff have flagged and are quietly working on — exactly the kind of leak the code's own comment says it's trying to prevent.
    - **Evidence:**
        ```php
        // Outreach build with no invited address: staff must attach one
        // before anyone can claim it. Deliberately does NOT confirm the
        // site exists in any way a bare 404 wouldn't — it is reachable
        // only by handle, which is public anyway, and it must not become
        // an oracle for "this handle is a staff-built site worth taking".
        'CLAIM_NOT_INVITED' => $this->error(
            "This site isn't open for claiming yet. If it's yours, reply to the email we sent you or contact support.",
            409,
            [],
            ['code' => 'CLAIM_NOT_INVITED']
        ),
        ```

- [ ] **#SEM-4** · P2 — The unique-constraint race-loser branch re-serves an existing build without applying the same-day contact-email conflict/attach logic
    - **Where:** app/Services/PreAccount/PreAccountBuildService.php:193-204 (compare :81-97)
    - **Affects:** Concurrent requests for the same brand-new source (two staff members, or a staff single-create racing a batch-CSV row) where the losing request carries a `contact_email` — the invite-gate silently loses that address with no error surfaced to the caller.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the staff `contact_email` attach/conflict logic (empty→forceFill, different→`CONTACT_EMAIL_CONFLICT`, same→no-op) from the `findLive()`-hit branch (lines 81-97) into a private helper.
        - Call that helper from both the early `findLive()` branch and the `catch (UniqueConstraintViolationException)` branch before returning `reserve($existing)`.
        - Add a concurrency test: two staff `requestBuild()` calls for the same new source, one carrying `contact_email`, forced to race so the email-carrying request loses the insert.
    - **Technical:** The normal existing-build path (lines 81-97) explicitly reconciles a staff-supplied `contact_email` against the winning row — attaching it if absent, or throwing `CONTACT_EMAIL_CONFLICT` if it differs from one already set — per the invite-gate shipped today (commit `45a87669a`, "Close the claim invite-gate, the early-access squat, and the PII export door"). The `catch (UniqueConstraintViolationException)` branch a few lines below handles the identical scenario (a build for this source already exists) reached via a different code path — losing the DB insert race instead of the earlier `findLive()` check — but skips that logic entirely: `$existing = $this->findLive(...); if ($existing) { return ['build' => $this->reserve($existing), 'reused' => true]; }`. The caller's contact email is dropped with no exception, no log, and the response (built from `PreAccountBuildStatusResource`, which does not surface `contact_email`) gives no indication anything was skipped.
    - **Plain English:** Two staff members happen to process the same business at nearly the same instant. The first one wins and creates the record. The second one — who has the crucial contact email that decides who's allowed to claim the site — hits a "someone already made this, here it is" shortcut that never writes down their email. They believe the site is safely gated to the right person; it's actually still open to whoever guesses the handle.
    - **Evidence:**
        ```php
        } catch (UniqueConstraintViolationException) {
            // Lost the race on pre_account_builds_live_source_unique — the other
            // request's build is the canonical one; re-serve it (spec §4.1).
            $existing = $this->findLive($sourceType, $refLc);
            if ($existing) {
                return ['build' => $this->reserve($existing), 'reused' => true];
            }
            throw new PreAccountBuildException(
                PreAccountBuildException::SOURCE_REF_INVALID,
                'Could not create the build. Try again.'
            );
        }
        ```

## P3 — Nice to have

- [ ] **#SEM-5** · P3 — `notification_retention_days` uses singular `profile_task`, disagreeing with the `profile_tasks` category key everywhere else — currently dormant, will silently misconfigure retention the moment it's wired up
    - **Where:** config/partna.php:2150 (retention map) vs config/partna.php:2203 (mailables/category registry)
    - **Affects:** Future profile-task notification retention/pruning, once a dispatcher is wired to pass this category through `NotificationPublisher`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rename the retention key from `profile_task` to `profile_tasks` to match the `mailables` registry and `ProfileTaskMail::CATEGORY`.
        - Add a lightweight assertion that every category key referenced in `notifications.mailables` that expects non-default retention also exists in `notification_retention_days`.
    - **Technical:** `notifications.mailables` is the category registry (`config/partna.php:2203`: `'profile_tasks' => ProfileTaskMail::class`), matching `ProfileTaskMail::CATEGORY = 'profile_tasks'`. The retention map instead defines `'profile_task' => 180` (singular, `config/partna.php:2150`). `NotificationPublisher::publish()`/`publishBatch()` look up `config("partna.notification_retention_days.{$retentionKey}") ?? config('...default', 30)` using whatever `retentionConfigKey` the caller passes. Grepping every current `retentionConfigKey:` call site (`enquiry_reminder`, `analytics_weekly`, `integration_connected`, `achievement`, `content_scrape`, `null`) shows **no live dispatcher currently passes `profile_task` or `profile_tasks`** — `ProfileTaskMail` is referenced only by the dev-only `MailPreviewController` and in doc-comment examples. So today this mismatch has zero observable effect; it becomes a live bug only the moment a `ProfileTaskNotifier`-style dispatcher is added and naturally reaches for the `mailables` key name (`profile_tasks`), silently falling back to the 30-day default instead of the intended 180.
    - **Plain English:** Two filing cabinets use slightly different labels for the same folder — "Profile Task" in one, "Profile Tasks" in the other. Right now nobody's using that folder yet, so it's harmless. But the day someone starts filing profile-task reminders under the plural label, the retention rule intended for it (keep six months) won't be found, and they'll get thrown out after one month instead.
    - **Evidence:**
        ```php
        // config/partna.php — retention map
        'profile_task' => 180,

        // config/partna.php — category registry / mailables
        'profile_tasks' => ProfileTaskMail::class,
        ```

- [ ] **#SEM-6** · P3 — The `ModelNotFoundException` branch in the API exception renderer is unreachable; Laravel's `prepareException()` already converts it to `NotFoundHttpException` before this callback runs
    - **Where:** bootstrap/app.php:207-219
    - **Affects:** API consumers hitting a model-binding 404 (e.g. a route-bound UUID that doesn't exist) receive the generic "Endpoint not found" message instead of the intended "Resource not found."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the dead `elseif ($e instanceof ModelNotFoundException)` branch, since the following `NotFoundHttpException` branch already catches every case that actually reaches this callback.
        - If the distinct "Resource not found" message is wanted for model-binding misses specifically, detect it via the exception message/previous-exception chain rather than `instanceof ModelNotFoundException`, since by render time the type has already changed.
    - **Technical:** Verified against `vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php:600-620`: `render()` calls `$e = $this->prepareException($e);` (line 616) **before** `$this->renderViaCallbacks($request, $e)` (line 618) — and `renderViaCallbacks()` is what invokes the closures registered via `$exceptions->render(...)` in `bootstrap/app.php`. `prepareException()` (`Handler.php:664-668`) maps `$e instanceof ModelNotFoundException => new NotFoundHttpException($e->getMessage(), $e)`. So by the time `bootstrap/app.php`'s render closure sees the exception, a `ModelNotFoundException` has already become a `NotFoundHttpException`, and the `elseif ($e instanceof ModelNotFoundException)` branch (lines 207-212) can never match — every model-binding miss falls through to the `NotFoundHttpException` branch (lines 214-219) instead, returning "Endpoint not found" rather than "Resource not found."
    - **Plain English:** There's a sign at the front desk with special instructions for a "record not found" visitor, but by the time any visitor reaches the desk they've already been relabeled "wrong door" — so the special instructions never get used, and everyone hears the same generic message regardless of what actually went wrong.
    - **Evidence:**
        ```php
        // Model not found (404)
        elseif ($e instanceof ModelNotFoundException) {
            $response = response()->json([
                'message' => 'Resource not found',
            ], 404);
        }

        // Route not found (404)
        elseif ($e instanceof NotFoundHttpException) {
            $response = response()->json([
                'message' => 'Endpoint not found',
            ], 404);
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Social link canonical-URL rebuild:** #SEM-1
    - **Why grouped:** Single subsystem (`SocialLinkNormalizer` + `config/partna.php` social_platforms registry); no other finding touches this file family.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Config/error-handling hygiene:** #SEM-5, #SEM-6
    - **Why grouped:** Both are isolated, low-risk config/bootstrap corrections with no shared runtime path — safe to fix together in one pass since neither touches auth, money, or a migration.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#SEM-2 — `isOutreach()` loses staff origin on staff-row deletion** · touches the claim-gating invariant that decides who may claim a business's site (authorization-adjacent security gate); run alone with its own sign-off.
- **#SEM-3 — `CLAIM_NOT_INVITED` enumeration oracle** · directly implicates the project's 403-vs-404/anti-enumeration authorization doctrine on a public claim endpoint; run alone with its own sign-off.
- **#SEM-4 — Race-loser path skips contact-email conflict logic** · touches the same claim-gating security invariant (who may claim a site) under a concurrency/race condition; run alone with its own sign-off.
