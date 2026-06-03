- [ ] **TEST-1** · P1 — UpdateSiteAction has no dedicated test coverage despite containing critical subdomain-rename, alias-creation, and publish-enforcement logic
    - **Where:** app/Services/Site/UpdateSiteAction.php (entire class); no corresponding test file exists in `tests/Feature/`.
    - **Affects:** Every dashboard user who changes a subdomain, renames a handle, or publishes a site. A regression here would silently break subdomain assignment, orphan alias rows, or allow publication of incomplete profiles.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Services/UpdateSiteActionTest.php` covering: happy-path subdomain rename with alias creation, cooldown enforcement (re-rename blocked before 30 days), handle sync on rename, collision with an existing subdomain in sites/aliases/handle-aliases tables, publish validation (missing display_name rejected unless staff force_publish), and settings merge preserving unknown keys.
        - Assert database state after each operation — alias row created, handle alias written, `HandleChangeLog` row inserted, `subdomain_changed_at` advanced, `professional.handle` updated.
    - **Technical:** The service contains 150+ lines of branching business logic (subdomain cooldown, alias snapshot, handle sync, collapse logic, publish guard) that is not exercised by any existing test. The codebase has tests for the design-kit path through the same controller, but they sidestep real subdomain mutations. Per audit rule, a P1 applies when a correctness gap ships bad behavior in known scenarios — a subdomain conflict that silently overwrites another user's subdomain, or a cooldown that doesn't fire, would do exactly that.
    - **Plain English:** A dry-cleaner that only tests the button on the jacket but never runs the machine. Changing a subdomain is the machine — if it breaks, customers land on the wrong shopfront or can't publish their page. We need to verify the machine actually washes clothes.
    - **Evidence:**
        ```php
        // app/Services/Site/UpdateSiteAction.php — no test file exists
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **TEST-2** · P2 — writeDesignKit()'s `lockForUpdate()` concurrency guard is untestable under SQLite and lacks a real-PostgreSQL race-condition test
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSiteController.php (writeDesignKit method, line containing `lockForUpdate()`)
    - **Affects:** Designers (or staff) editing the same professional's design kit simultaneously; losing one set of changes because two writes land in parallel and overwrite each other's columns.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a PostgreSQL-only integration test that dispatches two concurrent `PATCH /api/site` requests with disjoint design-kit keys (e.g., one sets `color_accent`, the other sets `color_bg`) and asserts that after both commit, **both** columns contain the requested values, not just the last writer.
        - Tag the test with `@group pgsql` so it is skipped on SQLite CI runs.
    - **Technical:** `writeDesignKit()` wraps its read-then-write in a transaction with `lockForUpdate()` to serialise concurrent requests. SQLite compiles `lockForUpdate()` to a no-op, so all existing tests that exercise the write path (including the reflected unit tests in WriteDesignKitTest) cannot verify that the lock actually prevents torn writes. A torn write would produce a design kit with colours from one request and typography from another, which is pathologically hard to debug from the frontend.
    - **Plain English:** Two stylists editing a mannequin at the same time — one changes the shirt, the other the trousers. If they both grab the mannequin simultaneously, the second stylist might overwrite the first's shirt change. We added a rope-lock system to prevent this, but our test floor uses a flimsy prop mannequin that can't test the rope. Until we test on the real thing, we don't know if the rope actually holds.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->transaction(function () use ($siteId, $valid): void {
            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->get(); // acquire the lock before writing

            DB::connection('pgsql')
                ->table('site.design_kits')
                ->where('site_id', $siteId)
                ->update($valid);
        });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **TEST-3** · P2 — SendEnquiryConfirmationJob and SendSubscriptionConfirmationJob lack tests for the rate-limiting guard (withinRateLimit returns false)
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php: `withinRateLimit()` method; app/Jobs/Notifications/SendSubscriptionConfirmationJob.php: `withinRateLimit()` method
    - **Affects:** Visitors who submit multiple enquiries or subscribe repeatedly in a short window — when the rate limit is hit, the job silently exits without logging to the test-observable log (only logs in the method itself), but no test verifies that exit.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For the enquiry confirmation job: add a test that simulates the rate limit being hit (`RateLimiter::shouldReceive('tooManyAttempts')->once()->andReturn(true)`), dispatches the job, and asserts no email was sent and the warning log was written.
        - Apply the same pattern for the subscription confirmation job.
    - **Technical:** Both jobs call `withinRateLimit()` before sending mail. If the limit is exceeded, the job returns early without updating `confirmation_sent_at`. The existing tests never configure the rate limiter to be exhausted, so the early-return branch is untested. In production, a misconfigured rate limit (e.g., `partna.throttle.visitor_confirmation_per_hour` set to 0) would silently suppress all confirmations with no easy way to detect in Nightwatch because the warning log is only written when the limit is hit.
    - **Plain English:** There's a safety valve on the confirmation emails that says "don't send more than 5 emails per hour to the same person." The tests always run with the valve wide open. If someone accidentally turns the valve off in configuration, we'd never know from our tests — the emails would just stop going out to new people.
    - **Evidence:**
        ```php
        // app/Jobs/Notifications/SendEnquiryConfirmationJob.php
        if (! $this->withinRateLimit($recipient)) {
            return;
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **TEST-4** · P2 — UpdateSiteRequest and StaffUpdateSiteRequest have no integration tests exercising their validation rules (422 on bad input)
    - **Where:** app/Http/Requests/Api/User/Site/UpdateSiteRequest.php; app/Http/Requests/Api/Staff/UserSite/StaffUpdateSiteRequest.php
    - **Affects:** Dashboard users and staff who might submit invalid design-kit values (non-hex colours, unknown skeleton IDs, reserved subdomains) and get a 500 instead of a helpful 422, or worse, have invalid data pass through due to a rule being accidentally dropped.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test that sends a PATCH to `/api/site` with each of these invalid payloads and asserts a 422 with the correct error message: a colour like `'red'` (not hex), `skeleton_id: 'skeleton-9'`, `subdomain: 'api'` (reserved), `settings.design: {}` (prohibited), and a subdomain that contains uppercase letters.
        - Duplicate for the staff endpoint.
    - **Technical:** The structural drift tests in `DesignKitRequestDriftTest` verify that the `design_kit.*` rules match the DB columns, but they don't fire the validation engine. A functional test that hits the endpoint with invalid data would catch a rule that was removed during a refactor but the structural test would not notice because both the column and the rule would be missing. Also, the hex regex and reserved-subdomain closures are only exercised if a real HTTP request triggers validation.
    - **Plain English:** We've installed a lock on the front door and checked that the key fits the lock. But we've never actually tried to open the door with a bad key to make sure the lock says "no." Without that test, someone could remove the lock mechanism entirely and the real-estate inspection (structural test) would still pass because the door looks fine.
    - **Evidence:**
        ```php
        // app/Http/Requests/Api/User/Site/UpdateSiteRequest.php
        'design_kit.color_accent' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
        'skeleton_id' => ['sometimes', 'string', Rule::in(self::ALLOWED_SKELETONS)],
        // … plus closure-based reserved-word and uniqueness checks
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **TEST-5** · P3 — StaffSiteController has zero test coverage
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php (both `show` and `showByProfessional` methods)
    - **Affects:** Staff dashboard users — if the endpoint breaks, staff cannot view a professional's site details, which is a support-ops dependency.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Api/Staff/StaffSiteControllerTest.php` with two tests: `it returns site data for a given subdomain` and `it returns 404 when subdomain not found`.
        - Optionally add `showByProfessional` test.
    - **Technical:** The controller reads from `AllSiteData` (a DB view) and returns a `StaffSiteResource`. There is no test file in the test suite for this controller. While the view itself is covered indirectly by other tests, the controller's error handling (404) and response shape are untested. This is low-risk (staff-only route) but the cost to add coverage is minimal.
    - **Plain English:** The staff have a back-office tool that shows them a professional's site. Nobody has ever written an automated check to make sure that page actually loads. It's a low-traffic corner, but the cost of adding a check is tiny — like throwing a smoke alarm into a storage closet.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Staff/StaffSite/StaffSiteController.php — no test file exists
        ```
    - `[DRAFT, confidence: 1.0]`
