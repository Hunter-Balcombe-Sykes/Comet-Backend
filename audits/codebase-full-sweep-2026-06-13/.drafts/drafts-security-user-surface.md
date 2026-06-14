- [ ] **#SEC-1** · P0 — Analytics ingest requests accept `site_id` without verifying it matches the subdomain, enabling cross-tenant data injection
    - **Where:** app/Http/Requests/Api/PublicSite/Analytics/PageviewRequest.php:31, ClickRequest.php:35, PingRequest.php:28, SectionSeenRequest.php:27
    - **Affects:** All analytics data – an attacker can post pageviews, clicks, pings, or section-seen events for any professional’s site by supplying any existing `site_id`, poisoning metrics and “live now” counts.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Remove `site_id` from the public-facing request contract entirely; only accept `subdomain` (resolved from the route or `X-Site-Subdomain` header).
        - Resolve the site server-side from the canonical `subdomain` before writing events.
    - **Technical:** The current rules use `required_without:subdomain` and only check that the UUID `exists` in `site.sites`. There is no cross-reference to the subdomain. An attacker can send `{ site_id: "<any-valid-uuid>", ... }` and bypass subdomain-based routing, injecting data into another professional’s analytics. This is a direct tenant-boundary failure. The fix is to eliminate the untrusted `site_id` parameter from the public contract and derive the site deterministically from the validated subdomain.
    - **Plain English:** There’s a “please deliver to room number” field on the public form. The form only checks that the room number exists somewhere in the hotel, not that it matches the guest who provided the key card. Someone can fill in a different room number and the analytics go to the wrong guest’s dashboard. The fix is to remove that field and look up the right room from the key card alone.
    - **Evidence:**
        ```php
        // PageviewRequest.php
        'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
        // ClickRequest.php
        'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
        // PingRequest.php
        'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
        // SectionSeenRequest.php
        'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#SEC-2** · P2 — Public waitlist and email-subscribe forms lack bot-protection fields present in other public forms
    - **Where:** app/Http/Requests/Api/PublicSite/PublicWaitlistSignupRequest.php, app/Http/Requests/Api/PublicSite/PublicEmailSubscribeRequest.php
    - **Affects:** Waitlist signup and email subscription endpoints – susceptible to automated spam, list bombing, and abuse.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a hidden honeypot field (e.g., `website`) and a required `form_started_at_ms` timing field to both Form Requests, matching the pattern used in `PublicEnquiryRequest` and `PublicCustomerLeadRequest`.
        - Document the expected client-side behaviour (honeypot must remain empty, `form_started_at_ms` sent as millisecond epoch).
    - **Technical:** The other public-facing forms (`PublicEnquiryRequest`, `PublicCustomerLeadRequest`) include `'website' => ['nullable', 'string', 'max:255']` and `'form_started_at_ms' => ['required', 'integer', 'min:0']` as bot protection. `PublicWaitlistSignupRequest` and `PublicEmailSubscribeRequest` have no such fields, making them easier targets for scripted submission. Adding these fields brings them into line with the existing anti-bot strategy and allows the controller to reject requests that fill the honeypot or have an impossibly small time delta.
    - **Plain English:** Our checkout and contact forms have a hidden “prove you’re human” trapdoor and a timer that rejects submissions that happen too quickly. Two other forms that collect emails are missing both protections, so a script can flood them without hitting any speed bump. Adding the same trapdoor and timer makes them equally resistant to bots.
    - **Evidence:**
        ```php
        // PublicWaitlistSignupRequest.php — no honeypot or timing field
        public function rules(): array
        {
            return [
                'email' => ['required', 'email:rfc', 'max:255'],
                'name' => ['nullable', 'string', 'max:200'],
                // … no 'website' honeypot, no 'form_started_at_ms'
            ];
        }
        // PublicEmailSubscribeRequest.php — similarly lacks them
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-3** · P2 — Document download URL may leak draft documents if the public download endpoint lacks an `is_active` check
    - **Where:** app/Http/Controllers/Api/User/Account/UserDocumentController.php (buildDocumentPayload method, line ~138)
    - **Affects:** Any professional’s draft documents — a person who obtains or guesses a document’s UUID could download content that is not yet published.
    - **Effort:** M (~2–4h) — depends on the state of the public download controller
    - **What to do:**
        - In `buildDocumentPayload`, only include `download_url` when `$media->is_active` is true (i.e., the document is published).
        - Verify that the corresponding `GET /api/public/documents/{id}/download` controller checks both `is_active` and the site’s published status.
    - **Technical:** The payload unconditionally sets `download_url` for every document, regardless of its `is_active` flag. Since the download URL is a direct, unauthenticated path based only on a UUID, anyone with knowledge of the UUID can attempt to fetch the file. If the public download endpoint does not independently verify that the document is published (and that its owning site is published), draft documents become publicly accessible. The UUID reduces risk but does not eliminate it, especially if IDs appear in client-side logs or network inspectors.
    - **Plain English:** Imagine a filing cabinet where every drawer has a key and some drawers are marked “draft — do not open.” The dashboard is handing out a generic “open drawer” key for every drawer, regardless of the draft tag. If a visitor finds that key, they can open a draft drawer. The fix is to only hand out keys for drawers that are ready to be shown.
    - **Evidence:**
        ```php
        // UserDocumentController::buildDocumentPayload()
        return [
            // …
            'preview_url' => $previewUrl,
            'download_url' => '/api/public/documents/'.$media->id.'/download',
            // …
        ];
        // No check on $media->is_active before adding download_url
        ```
    - `[DRAFT, confidence: 0.7]` — the actual risk depends on the public download endpoint’s authorisation logic, which was not included in the audit scope.

- [ ] **#SEC-4** · P2 — Analytics ingest requests lack built-in anti-abuse validation, increasing risk of queue exhaustion
    - **Where:** app/Http/Requests/Api/PublicSite/Analytics/PageviewRequest.php, ClickRequest.php, PingRequest.php, SectionSeenRequest.php
    - **Affects:** Analytics processing pipeline — a flood of forged requests could exhaust the job queue and degrade site performance.
    - **Effort:** M (~2–4h) — requires both request-level hardening and route-level throttle tuning
    - **What to do:**
        - Apply strict per-IP and per-site `throttle` middleware to all analytics ingest routes.
        - Add a basic anti-bot signal to the Form Requests (e.g., a timestamp or nonce) that allows the worker to reject obviously automated bursts before queue dispatch.
    - **Technical:** The analytics ingest endpoints accept data from any origin (the public site’s JavaScript beacon) with no server-side origin validation visible in the provided code. The requests carry no anti-forgery token, no signed payload, and no honeypot field. While rate limiting is the primary defence, the absence of any lightweight request-level check means that even a modest attacker can push through a large volume of events, consuming queue capacity and potentially delaying legitimate jobs. A small, cheap client-side timestamp or nonce checked at the HTTP layer can cull the noisiest abuse before reaching the job system.
    - **Plain English:** The analytics pipeline is like a mailroom that accepts every envelope that arrives, no matter how many or how fast. There’s currently a doorman (rate limiter) but the envelope itself has no stamp. Adding a quick “is this envelope at least shaped like a real one?” check at the door catches the most obvious junk before it clogs the sorting machines.
    - **Evidence:**
        ```php
        // PageviewRequest.php — no honeypot, nonce, or timestamp
        public function rules(): array
        {
            return [
                'site_id' => ['required_without:subdomain', 'uuid', Rule::exists('pgsql.site.sites', 'id')],
                'subdomain' => ['required_without:site_id', 'string', 'max:63'],
                // … no anti-abuse fields
            ];
        }
        ```
    - `[DRAFT, confidence: 0.6]` — the presence or absence of throttle middleware on the routes was not verifiable from the provided files; this finding assumes the current protection is light.
