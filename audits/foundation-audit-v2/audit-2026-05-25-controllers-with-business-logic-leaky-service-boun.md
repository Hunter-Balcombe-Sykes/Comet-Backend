Now I have all the evidence I need. Let me produce the final adjudicated audit.

`★ Insight ─────────────────────────────────────`
Three of the seven findings share a common root: the customer-upsert find-or-create pattern diverged into four private controller methods, the notification resolution chain was copy-pasted from a service that already owns it, and a three-boolean helper landed in three controllers instead of one trait. All three are independently addressable in a single focused session.
`─────────────────────────────────────────────────`

# FAT Controller Audit — 2026-05-25

**Branch:** development
**Lens:** Controllers with business logic, leaky service boundaries, fat-controller anti-patterns post-strip
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php
- app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php
- app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php
- app/Http/Controllers/Api/Professional/Customers/ProfessionalCustomerController.php
- app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php
- app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSectionBlockController.php
- app/Http/Controllers/Api/Professional/Notifications/NotificationEmailPreferenceController.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Professional/ConfirmationPreferenceService.php
- app/Http/Controllers/Concerns/ (directory enumerated)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 6 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#FAT-1** · P2 — Name-inference algorithm and customer-upsert logic embedded in `PublicEmailSubscriptionController`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php:21–237
    - **Affects:** Maintainability and testability of the email subscription flow; the 90-entry `COMMON_FIRST_NAMES` dataset and multi-strategy regex heuristic are untestable without instantiating the controller.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract `inferNameFromEmail()` and the two private constant arrays to a new `App\Services\PublicSite\EmailNameInferenceService` (or a static utility class if it stays stateless).
        - Extract `upsertMarketingCustomer()` to `CustomerUpsertService` (see FAT-5 — done together).
        - Controller `subscribe()` calls the service; keeps only HTTP concerns (subdomain resolution, request reading, response building).
    - **Technical:** `inferNameFromEmail()` is 65 lines of pure domain logic with no HTTP dependency. The two class-level constants (`COMMON_FIRST_NAMES`, `NON_NAME_TOKENS`) are a 90-entry and 17-entry dataset—data that belongs in config or a service, not a controller. The method follows three discrete inference strategies (exact match, prefix scan, delimiter split). Each strategy is independently testable in a unit test only if the logic is in a service. The `upsertMarketingCustomer()` private method duplicates the find-or-create customer pattern found in three other controllers (covered in FAT-5).
    - **Plain English:** Imagine your store manager's job description included "also design and maintain the customer name-matching algorithm stored in your head." That's what's happening here—the HTTP handler for subscribing someone to a newsletter is also responsible for a complex name-guessing algorithm based on email addresses. When you need to add a new first name to the recogniser, or fix a bug in how it splits names, you have to open a file called "SubscriptionController" to find it. This should live in a named service where it can be found, tested, and adjusted independently.
    - **Evidence:**
        ```php
        private const COMMON_FIRST_NAMES = [
            'aaron', 'adam', 'alex', 'alice', 'amanda', 'amelia', 'amy', 'andrew', 'anna', 'anthony',
            'ashley', 'ben', 'benjamin', 'blake', 'brad', 'brandon', 'brian', 'caitlin', 'cameron', 'charlotte',
            // ... 70 more entries ...
        ];

        private function inferNameFromEmail(string $email): ?string
        {
            $normalized = strtolower(trim($email));
            if ($normalized === '' || ! str_contains($normalized, '@')) {
                return null;
            }
            [$localPart] = explode('@', $normalized, 2);
            $localPart = preg_replace('/\+.*$/', '', $localPart) ?? $localPart;
            // ... 50 more lines of regex-based heuristics ...
        }
        ```

---

- [ ] **#FAT-2** · P2 — Document upload pipeline (Pattern 16) implemented entirely inside `ProfessionalDocumentController::store`
    - **Where:** app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php:45–191
    - **Affects:** Testability of the flat-replace document upload pipeline; the advisory lock, soft-delete, post-commit R2 upload, and error-recovery logic are all unreachable by unit tests without spinning up the full controller stack.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `App\Services\Media\DocumentUploadService::upload(Professional $pro, Site $site, UploadedFile $file, string $title, ?string $caption): SiteMedia`.
        - Move the entire `store()` body (MIME check, advisory lock transaction, Pattern 16 post-commit PUT, restore-on-failure) into the service method. The Pattern 16 invariants — R2 PUT outside the transaction, restore prior doc on failure — must be preserved verbatim; document this as a "Pattern 16" block comment in the service.
        - `store()` becomes: resolve professional/site, call `DocumentUploadService::upload()`, return `buildDocumentPayload()`.
        - `MediaUploadService` already exists for gallery/video; use the same structural pattern.
    - **Technical:** `store()` is 147 lines mixing HTTP concerns (MIME sniffing via `finfo`, request reading) with domain logic (flat-replace semantics, advisory lock) and infrastructure orchestration (DB transaction, R2 PUT, R2 cleanup). The Pattern 16 comment acknowledges the architectural intent ("R2 PUT is performed OUTSIDE the transaction") but this design decision belongs in a named service where it can be documented and tested. The restore-on-R2-failure branch (`SiteMedia::withTrashed()->where('id', $previousId)->update(['deleted_at' => null])`) is particularly critical to preserve and verify in a unit test.
    - **Plain English:** Uploading a document for a user's site is a complex operation that has to coordinate three separate systems: the database, the file storage, and the cache. The instructions for doing this correctly (including what to do if the file upload fails halfway) are written directly into the file-upload HTTP handler. If you ever need to upload a document from a different part of the app — a scheduled job, an admin tool — you'd have to copy all those instructions. Moving this to a dedicated "document upload service" means the correct procedure lives in one place, can be tested independently, and can be reused.
    - **Evidence:**
        ```php
        // Master Pattern 16 (DB-E#SCALE-1): the R2 PUT is performed OUTSIDE
        // the transaction so the Postgres connection slot + advisory lock are
        // not held across a 50–500ms Cloudflare round-trip.
        $media = DB::transaction(function () use ($site, $file, $actualMime, $title, $caption, $originalFilename, &$previousPath, &$previousId) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-documents:{$site->id}"]);
            }
            // ... soft-delete existing, insert new row with path:'' ...
        });
        // Post-commit: stream the bytes to R2 ...
        try {
            $stream = fopen($file->getRealPath(), 'rb');
            Storage::disk($mediaDisk)->put($path, $stream, 'public');
            // ...
        } catch (\Throwable $e) {
            // Best-effort cleanup, forceDelete, restore prior doc ...
            $media->forceDelete();
            if ($previousId !== null) {
                SiteMedia::withTrashed()->where('id', $previousId)->update(['deleted_at' => null]);
            }
            throw $e;
        }
        ```

---

- [ ] **#FAT-3** · P2 — Section-provisioning domain transaction embedded as private method in `ProfessionalSectionBlockController`
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSectionBlockController.php:341–394
    - **Affects:** Testability of section provisioning; the advisory lock + block-seeding logic is called from three public controller methods (`index`, `upsert`, `remove`) but can only be exercised via full HTTP feature tests.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `syncAllowedSections()` and `needsSyncForAllowed()` to a new `App\Services\Professional\SectionProvisioningService`.
        - Inject `SectionProvisioningService` alongside the existing `SectionVisibilityService` in the controller constructor.
        - Each of the three call sites (`index`, `upsert`, `remove`) becomes a one-liner delegation.
        - `SectionVisibilityService` calls inside `syncAllowedSections` stay injected — pass the service instance through or inject it into `SectionProvisioningService`.
    - **Technical:** `syncAllowedSections` contains a full `DB::transaction` with a PostgreSQL advisory lock, a collection query, a `keyBy` + `max` aggregation, conditional block creation, and a per-block `checkVisibilityRequirements` call. The `needsSyncForAllowed` helper implements a correctness invariant (detect drift without touching the DB write path on a hot GET). Both are pure domain logic with no HTTP dependency. Leaving them as private controller methods means they are unreachable by `SectionProvisioningServiceTest` without reflection and cannot be mocked at call sites.
    - **Plain English:** When a user's dashboard loads, the app checks whether all the page sections (bio, gallery, contact, etc.) have been set up in the database, and creates any that are missing. This "make sure all sections exist" logic is a private function inside the section-display HTTP handler. Because it's private and buried inside the controller, it's very hard to test in isolation — you'd have to make an HTTP request just to exercise it. Moving it to its own "section provisioning service" makes it independently testable and reusable.
    - **Evidence:**
        ```php
        private function syncAllowedSections(string $professionalId, string $siteId, array $allowedSections): Collection
        {
            $orderedAllowed = array_values(array_unique(array_filter($allowedSections, static fn ($value) => is_string($value))));

            return DB::transaction(function () use ($professionalId, $siteId, $orderedAllowed) {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$siteId}"]);

                $allBlocks = Block::query()
                    ->where('professional_id', $professionalId)
                    ->where('site_id', $siteId)
                    ->where('block_group', 'sections')
                    ->get();

                $byType = $allBlocks->keyBy('block_type');
                $maxSortOrder = $allBlocks->max('sort_order') ?? -1;

                foreach ($orderedAllowed as $blockType) {
                    // ... seed new blocks with checkVisibilityRequirements ...
                }

                return $byType->values();
            });
        }
        ```

---

- [ ] **#FAT-4** · P2 — `PublicEnquiryController::submit` handles eight distinct concerns inline with no wrapping transaction
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php:25–125
    - **Affects:** Testability of enquiry submission; each concern (honeypot, timing, block lookup, subject merge, enquiry persist, customer upsert, lead log, notification dispatch) is independently testable only via a full HTTP feature test.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `App\Services\PublicSite\EnquirySubmissionService::submit(Site $site, array $validatedData, Request $request): Enquiry`.
        - Move steps 3–8 (block validation, subject merge, enquiry creation, customer upsert, lead logging, notification dispatch) into the service.
        - Keep steps 1–2 (honeypot, timing) in the controller — they are pre-resolution guards that return early before the site is known.
        - The customer-upsert step should delegate to `CustomerUpsertService` (see FAT-5).
        - Wrap steps 5–7 (enquiry create, customer upsert, lead log) in a single `DB::transaction` so a failed upsert doesn't leave an orphaned enquiry with no lead record.
    - **Technical:** `submit()` is 100 lines of mixed HTTP, domain, and infrastructure logic. The eight steps execute sequentially with no transaction boundary — if `upsertEnquiryCustomer` throws after `Enquiry::create()` succeeds, the saved enquiry has no lead-log record and no customer record, but the HTTP response has already failed (uncaught exception → 500). The caller receives a 500 while the enquiry row persists without its companion records. This partial-write path is currently untestable. Additionally, `upsertEnquiryCustomer` and `logLead` are private methods duplicated in variant form across `PublicEnquiryController` and `PublicCustomerLeadController` (both run identical timing-check blocks from lines 41–52 and 39–51 respectively). DeepSeek rated this P1; downgraded to P2 because the partial-write scenario requires a DB exception on a normally-stable path and is not a "known scenario" at pilot scale.
    - **Plain English:** When someone fills out the contact form on a professional's site, the code does eight separate jobs in a row: check for bots, verify timing, confirm the contact form is active, validate the subject line, save the message, create or update the submitter as a contact, log the lead for analytics, and send a notification email. All eight steps are written back-to-back in a single function with no safety net. If step six (saving the contact) goes wrong, the message is already in the database but the analytics log is missing, and the professional gets an error response. Splitting these into a named service makes each step independently testable and lets you add proper error recovery.
    - **Evidence:**
        ```php
        // 5) Save the enquiry.
        $enquiry = Enquiry::query()->create([...]);

        // 6) Upsert submitter as Customer lead.
        $this->upsertEnquiryCustomer((string) $site->professional_id, $data['email'], $data['name'], $data['phone'] ?? null);

        // 7) Log unified lead analytics.
        $this->logLead($request, $subdomain, $site->id, (string) $site->professional_id, 'created', $startedMs);

        // 8) Dispatch notification email ...
        $notificationEmail = data_get($block->settings, 'notification_email');
        if (is_string($notificationEmail) && trim($notificationEmail) !== '') {
            $notifyKey = 'enquiry_notify:'.$site->professional_id;
            $notifyLimit = config('partna.throttle.enquiry_notification_per_hour', 10);
            if (! RateLimiter::tooManyAttempts($notifyKey, $notifyLimit)) {
                RateLimiter::hit($notifyKey, 3600);
                SendEnquiryNotificationJob::dispatch((string) $enquiry->id, (string) $block->id);
            }
        }
        ```

---

- [ ] **#FAT-5** · P2 — Find-or-create customer upsert pattern duplicated across four controllers with diverging field semantics
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php:127–169 · app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php:124–168 · app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php:84–108 · app/Http/Controllers/Api/Professional/Customers/ProfessionalCustomerController.php:87–116
    - **Affects:** Data integrity for all four customer-creation paths; each copy handles `withTrashed`, restore, source value, phone, `marketing_opt_in_cached`, and name-overwrite differently. Divergence is already present and will grow.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Create `App\Services\Customers\CustomerUpsertService::upsert(string $professionalId, string $email, CustomerUpsertOptions $options): Customer`.
        - `CustomerUpsertOptions` (a simple value object or DTO) carries: `?string $fullName`, `?string $phone`, `?string $source`, `bool $overwriteName`, `bool $restoreIfTrashed`, `?bool $marketingOptInCached`.
        - Each of the four call sites constructs the appropriate `CustomerUpsertOptions` and delegates. The service owns the `withTrashed` + restore + save logic once.
        - Divergence to preserve intentionally: `PublicEmailSubscriptionController` has an `$overwriteName` flag; `PublicCustomerLeadController` sets `marketing_opt_in_cached`; `ProfessionalCustomerController` does not call `withTrashed()` (by design — dashboard create excludes archived). Encode these as explicit option flags, not silent differences.
    - **Technical:** Four implementations of the same find-or-create pattern already differ: `PublicEnquiryController::upsertEnquiryCustomer` queries `withTrashed()` and restores, preserving existing name/phone only if blank (source='enquiry'). `PublicEmailSubscriptionController::upsertMarketingCustomer` also uses `withTrashed()` + restore but adds an `$overwriteName` flag and sets source='site'. `PublicCustomerLeadController::store` does NOT call `withTrashed()` and handles `marketing_opt_in_cached`. `ProfessionalCustomerController::store` does NOT call `withTrashed()` either. These semantics exist for legitimate reasons but are invisible to a reader of any single controller — and any bug fix to the core find-or-create logic (e.g., normalization of email before matching) must be applied to all four copies.
    - **Plain English:** Four different parts of the app all have their own version of the instructions for "find this person in the contacts list; if they're already there, update them; if not, create them." Because these four versions were written separately, they've already drifted apart — some check for archived contacts and restore them, some don't; some overwrite the person's name, some only fill it in if it's blank. Any time a bug is found in this logic (say, email matching isn't case-insensitive), someone has to find and fix all four copies. A single "customer upsert service" would be the one authoritative set of instructions.
    - **Evidence:**
        ```php
        // PublicEnquiryController — source='enquiry', withTrashed, no overwriteName flag
        $existing = Customer::query()
            ->withTrashed()
            ->where('professional_id', $professionalId)
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->first();
        if ($existing) {
            if ($existing->trashed()) { $existing->restore(); }
            if ($fullName && trim((string) ($existing->full_name ?? '')) === '') {
                $existing->full_name = $fullName;
            }
        }

        // PublicEmailSubscriptionController — source='site', withTrashed, $overwriteName flag
        $existing = Customer::query()
            ->withTrashed()
            ->where('professional_id', $professionalId)
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->first();
        if ($existing) {
            if ($existing->trashed()) { $existing->restore(); }
            if ($name !== '' && ($overwriteName || $existingName === '')) {
                $existing->full_name = $name;
            }
        }

        // PublicCustomerLeadController — no withTrashed, sets marketing_opt_in_cached
        $customer = $pro->customers()->where('email', $data['email'])->first();
        if ($customer) {
            $customer->update([
                'full_name' => $data['full_name'],
                'marketing_opt_in_cached' => ! $marketingOptIn ? false : null,
            ]);
        }
        ```

---

- [ ] **#FAT-6** · P2 — Notification preference resolution chain re-implemented in `NotificationEmailPreferenceController::index`, duplicating `NotificationPublisher::computeResolvedMap`
    - **Where:** app/Http/Controllers/Api/Professional/Notifications/NotificationEmailPreferenceController.php:22–91 · app/Services/Notifications/NotificationPublisher.php:451–513
    - **Affects:** All professionals who view their notification preferences — if the resolution chain changes in `NotificationPublisher::computeResolvedMap` (the authoritative path used for actual email dispatch), the API response shown to the user will silently diverge from what the email system uses to decide whether to send. Users could see "enabled" in the UI while emails are suppressed, or vice versa.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline resolution chain in `index()` with a call to `NotificationPublisher::computeResolvedMap($pro->id)` to obtain `array<string, bool>`.
        - Annotate each category in the result using the policy and preference rows already fetched: `preference_set`, `mandatory`, `overridden_by_policy` can be computed from the same three DB queries already run in `computeResolvedMap` (expose them or re-fetch them — the three-query cost is already paid by `loadResolvedMap`).
        - Alternatively, add a `resolveForDisplay(string $professionalId): array` method to `NotificationPublisher` that returns the richer per-category metadata shape the controller needs, keeping the resolution chain in one place.
    - **Technical:** `NotificationPublisher::computeResolvedMap` (lines 451–513) runs three DB queries and iterates the category registry applying the precedence chain: mandatory → per-pro force_on → per-pro force_off → global force_on → global force_off → user pref → default true. `NotificationEmailPreferenceController::index` runs the identical three queries and applies the identical precedence chain inline (lines 54–88). The two implementations are currently in sync, but the service path is cached (`loadResolvedMap` uses `CacheLockService::rememberLocked`) while the controller path hits the DB fresh every time. If a new policy mode is added (e.g., `soft_on`), both must be updated; there is no compile-time or test-time enforcement of this invariant.
    - **Plain English:** The system has two places that answer the question "for this professional, is this notification category currently turned on?" One place is used when actually deciding whether to send an email. The other — a copy of the same logic — is used to build the settings page that shows the user their preferences. These two copies will give the same answer today, but the moment someone changes the email-sending logic without also updating the settings-page logic (or vice versa), users will see the wrong information. Centralising this in one place means the settings page always reflects exactly what the email system will do.
    - **Evidence:**
        ```php
        // NotificationEmailPreferenceController::index — inline resolution chain
        if ($mandatory) {
            $effective = true;
        } elseif ($perProMode === 'force_on') {
            $effective = true;
        } elseif ($perProMode === 'force_off') {
            $effective = false;
        } elseif ($globalMode === 'force_on') {
            $effective = true;
        } elseif ($globalMode === 'force_off') {
            $effective = false;
        } elseif ($prefValue !== null) {
            $effective = $prefValue;
        } else {
            $effective = true;
        }

        // NotificationPublisher::computeResolvedMap — authoritative resolution (used for sending)
        $perPro = $perProPolicies[$category] ?? null;
        if ($perPro === 'force_on') { $map[$category] = true; continue; }
        if ($perPro === 'force_off') { $map[$category] = false; continue; }
        $global = $globalPolicies[$category] ?? null;
        if ($global === 'force_on') { $map[$category] = true; continue; }
        if ($global === 'force_off') { $map[$category] = false; continue; }
        if (array_key_exists($category, $prefs)) { $map[$category] = (bool) $prefs[$category]; continue; }
        $map[$category] = true; // default enabled
        ```

---

## P3 — Nice to have

- [ ] **#FAT-7** · P3 — `shouldRememberConfirmationPreference` private helper duplicated across three controllers
    - **Where:** app/Http/Controllers/Api/Professional/Customers/ProfessionalCustomerController.php:180–185 · app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php · app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php
    - **Affects:** Consistency of confirmation preference detection; adding a fourth accepted query parameter requires updating three files.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a new `app/Http/Controllers/Concerns/HandlesConfirmationPreference.php` trait with a single `shouldRememberConfirmationPreference(Request $request): bool` method.
        - Each of the three controllers `use HandlesConfirmationPreference` and removes its private copy.
        - `ConfirmationPreferenceService` already exists and owns the DB-write side; the trait owns the HTTP-read side. Keep them separate.
    - **Technical:** Identical three-boolean OR (`remember_confirmation_preference || always_allow_confirmation || dont_ask_again`) is repeated verbatim in three controllers. `app/Http/Controllers/Concerns/` already contains nine traits following exactly this pattern — the infrastructure for extraction exists and is the established convention. `ConfirmationPreferenceService::enableForProfessional()` is already correctly referenced at call sites; only the input-parsing helper is duplicated.
    - **Plain English:** Three different parts of the app each contain their own copy of the same three-line check: "did the user tick the 'don't ask me again' box?" If you ever need to add a fourth way of expressing that preference, you'd have to find and update all three copies. Moving this one-liner to a shared "concern" file — which is how the rest of the codebase already handles shared controller helpers — means it lives in one place.
    - **Evidence:**
        ```php
        // ProfessionalCustomerController — identical in GalleryController and UploadController
        private function shouldRememberConfirmationPreference(Request $request): bool
        {
            return $request->boolean('remember_confirmation_preference')
                || $request->boolean('always_allow_confirmation')
                || $request->boolean('dont_ask_again');
        }
        ```
