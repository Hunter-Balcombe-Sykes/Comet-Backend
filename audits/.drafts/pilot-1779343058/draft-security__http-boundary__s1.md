- [ ] **#SEC-1** · P0 — Bootstrap request allows any new user to self-escalate to brand role, bypassing brand invite requirements
    - **Where:** app/Http/Requests/Api/BootstrapRequest.php:43-49 (requiresInvite logic) and :66 (professional_type rule)
    - **Affects:** Tenant roles: a brand-new user can sign up as a brand without any invitation, gaining full brand dashboard, site creation, and store management capabilities.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a server-side check in the controller that rejects `professional_type=brand` (or `account_type=brand`) for uninvited users, or require explicit brand verification (e.g., an admin approval or invite token).
        - Consider removing `brand` from the list of types a self-signup can claim, or require a separate brand-signup flow with shop verification.
    - **Technical:** The `$requiresInvite` condition in `rules()` explicitly excludes the case where `$declaredType === 'brand'`, so no invite fields are validated. The controller then creates a professional with type 'brand'. Since the authorization system uses `professional_type` for role-based access (e.g., `brand.only` middleware), this allows the user full brand privileges with no gate. This is a direct privilege escalation under the Supabase JWT scheme where the actor is resolved from the token, not from the request body.
    - **Plain English:** Door says “Brands must be invited,” but a new user can just write “brand” on the sign-up form and walk in. They get the keys to the brand-only room without anyone checking credentials.
    - **Evidence:**
        ```php
        $declaredType = mb_strtolower(trim((string) ($this->professional_type ?? '')));
        // ...
        $requiresInvite = $isFirstTimeSignup
            && ! $accountTypeIsSelfOnboard
            && $declaredType !== ''
            && $declaredType !== 'brand';
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#SEC-2** · P2 — StaffUpdateSiteRequest does not sanitize HTML from public-facing text fields, risking stored XSS
    - **Where:** app/Http/Requests/Api/Staff/ProfessionalSite/StaffUpdateSiteRequest.php (entire file, notably lack of prepareForValidation sanitization for hero_title, hero_subtitle, primary_button_text, bio_text)
    - **Affects:** Public site visitors if a staff user inserts malicious scripts into site text settings; affects all brands whose sites can be edited by staff.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `prepareForValidation()` method that loops over `'hero_title'`, `'hero_subtitle'`, `'primary_button_text'`, `'bio_text'` and applies `static::cleanString()` (or a similar strip-tags helper) before validation, matching the professional `UpdateSiteRequest`.
        - Ensure the public site renderer also escapes these values, but defense-in-depth is proper here.
    - **Technical:** The professional `UpdateSiteRequest` sanitizes those four fields via `cleanString()`, which strips HTML tags and control characters. The staff counterpart lacks this step entirely, so any HTML/JS is stored verbatim in the site's JSONB settings. If the Hydrogen storefront or any admin view renders these fields without escaping, it becomes a stored XSS vector. Staff accounts are trusted, but a compromised staff account could inject scripts across all sites and pivot from the internal to the public surface.
    - **Plain English:** A staff member can add a design detail that includes hidden code, like typing a script tag into a hero title. When customers visit the store, that code could run — even though staff are trusted, if their account gets hijacked, every brand’s front page could be poisoned.
    - **Evidence:**
        ```php
        // StaffUpdateSiteRequest has NO prepareForValidation() sanitization.
        // Compare with UpdateSiteRequest which does:
        foreach (['hero_title', 'hero_subtitle', 'primary_button_text', 'bio_text'] as $field) {
            if (! array_key_exists($field, $settings) || ! is_string($settings[$field])) {
                continue;
            }
            $settings[$field] = static::cleanString($settings[$field]);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SEC-3** · P3 — UploadDocumentRequest lacks magic-byte validation, allowing disguised file uploads
    - **Where:** app/Http/Requests/Api/Professional/Documents/UploadDocumentRequest.php:15-20 (rules only specify `mimes`)
    - **Affects:** Document storage: a malicious user could upload a file with a faked extension (e.g., .php renamed to .pdf) and potentially trick the system into storing or serving an executable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use the `SniffsFileMimeType` trait (or similar) to verify the actual file content matches an allowed MIME type (application/pdf, image/jpeg, image/png).
        - Add `mimetypes:application/pdf,image/jpeg,image/png` rule in addition to `mimes` for extra validation, but the trait performs a deeper byte check.
    - **Technical:** Laravel's `mimes` rule trusts the client-provided extension, not the actual file content. The `UploadImageRequest` uses `SniffsFileMimeType` to guard against this, but `UploadDocumentRequest` does not. Since documents are later downloaded by customers, a disguised file could be exploited if the server relies on extension to set Content-Type, causing browsers to execute it rather than open as a document. Risk is low because typical document viewing doesn't execute code, but it's a defense gap against file-type confusion attacks.
    - **Plain English:** Checking a file's type just by its name is like verifying someone’s age by their handwriting — easy to fake. Other upload endpoints actually peek inside the file to confirm it’s really a picture; this one doesn’t, so a clever renamed file could slip through.
    - **Evidence:**
        ```php
        'file' => [
            'required',
            'file',
            'mimes:pdf,jpg,jpeg,png',
            'max:10240',
        ],
        // No withValidator() or SniffsFileMimeType usage.
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SEC-4** · P3 — allowedRedirectRule permits redirection to localhost/127.0.0.1 in all environments
    - **Where:** app/Http/Requests/BaseFormRequest.php:155-170 (allowedRedirectRule closure)
    - **Affects:** Any endpoint accepting a redirect URL (Stripe onboarding, plan changes, payment method setup) — could be used as a building block in phishing or local-network exploration, though practical impact is low.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'localhost'` and `'127.0.0.1'` from the allowed hosts array when in a non-local environment, or conditionally include them only when `app()->environment('local')`.
        - Alternatively, make the allowed redirect domains configurable via a config array.
    - **Technical:** The closure builds an allowlist by parsing configured frontend and app URLs and adding localhost/127.0.0.1. In production, these internal hostnames could be used in a social-engineering scenario where a maliciously crafted link redirects the user to a service running on their own machine after a legitimate Stripe flow. While the attack surface is narrow (requires the user to have a local web server listening), security best practice for open-redirect protection is to exclude loopback addresses in production.
    - **Plain English:** The system’s list of safe redirect destinations includes “localhost” and “127.0.0.1” — addresses that point to the user’s own computer. In a production environment this is like leaving a side gate open; an attacker could use it to redirect someone to a fake page on their own machine after a real login, though it’s hard to pull off.
    - **Evidence:**
        ```php
        $allowed = array_filter([$frontendHost, $appHost, 'localhost', '127.0.0.1']);
        ```
    - `[DRAFT, confidence: 0.6]`
