
<!-- ═══ LENS: code-quality-slop | CHUNK: services ═══ -->

- [ ] **#SLOP-1** · P3 — Copy-paste `formatPrice` and `normalizeAvailability` duplicated across EventbriteScraper and HumanitixScraper
    - **Where:** app/Services/Platforms/EventbriteScraper.php:328-357 and app/Services/Platforms/HumanitixScraper.php:185-214
    - **Affects:** Future maintainers updating event price/availability display logic — must remember to change both files.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `formatPrice` and `normalizeAvailability` into `PlatformScraper` (already the shared base class for both) or into `EventsPayload` (already the shared shaping class for events).
        - Call the shared methods from both scrapers.
    - **Technical:** Both scrapers parse schema.org `Event` JSON-LD with the same `AggregateOffer` price fields and `availability` URL. The two private methods are character-for-character identical — a bug fix or format change in one will inevitably drift from the other. `PlatformScraper` is the natural home since both scrapers extend it and the helpers are pure functions over arrays with no external dependencies. `EventsPayload` already shapes event output; price/availability normalisation is a natural fit there too.
    - **Plain English:** Two different scrapers (Eventbrite and Humanitix) each have their own copy of the same two helper functions. It's like having two identical sets of measuring cups in the same kitchen — if the recipe changes, someone has to remember to update both drawers. Merge them into one.
    - **Evidence:**
        ```php
        // EventbriteScraper.php:328-357
        private function formatPrice(array $offers): ?string
        {
            $low = data_get($offers, 'lowPrice') ?? data_get($offers, 'price');
            if ($low === null) {
                return null;
            }
            $high = data_get($offers, 'highPrice');
            $cur = data_get($offers, 'priceCurrency');
            $prefix = $cur ? $cur.' ' : '';

            if ((float) $low === 0.0 && ($high === null || (float) $high === 0.0)) {
                return 'Free';
            }
            if ($high !== null && (float) $high !== (float) $low) {
                return "{$prefix}{$low} – {$high}";
            }

            return "{$prefix}{$low}";
        }

        private function normalizeAvailability(?string $availability): ?string
        {
            if (! $availability) {
                return null;
            }
            $a = strtolower($availability);
            if (str_contains($a, 'soldout')) {
                return 'sold_out';
            }
            if (str_contains($a, 'instock') || str_contains($a, 'limited') || str_contains($a, 'presale') || str_contains($a, 'preorder')) {
                return 'available';
            }

            return null;
        }
        ```
        ```php
        // HumanitixScraper.php:185-214 — identical body, different file
        private function formatPrice(array $offers): ?string
        {
            $low = data_get($offers, 'lowPrice') ?? data_get($offers, 'price');
            // ... identical to EventbriteScraper
        }

        private function normalizeAvailability(?string $availability): ?string
        {
            // ... identical to EventbriteScraper
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SLOP-2** · P3 — Copy-paste site-republish and cancellation-mail logic duplicated between `cancel()` and `adminCancel()`
    - **Where:** app/Services/User/AccountDeletionService.php — `cancel()` ~lines 246-300 and `adminCancel()` ~lines 219-243
    - **Affects:** Maintainers changing the grace-period undo logic — must remember to update both methods identically.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the shared site-republish block (status restore + site re-publish under `lockForUpdate`) into a private method like `restoreProfessionalFromDeletion(User $professional, string $previousStatus): void`.
        - Extract the `AccountDeletionCancelledMail` dispatch into a private method so the mail-sending and error-logging logic isn't duplicated.
        - Call both from `cancel()` and `adminCancel()`, keeping only the audit-logging difference (different event constant and actor parameters) inline.
    - **Technical:** The two public methods share ~25 lines of character-for-character identical code: the status-rollback `$professional->update(...)` block, the `Site::query()->lockForUpdate()` re-publish block, and the `Mail::to(...)->send(new AccountDeletionCancelledMail(...))` try/catch. Only the audit event constant (`EVENT_CANCELLED` vs `EVENT_ADMIN_CANCELLED`) and actor parameters differ. A future change to the cancellation mail (new template, additional context) or the re-publish logic (new guard condition) would need to be applied in two places.
    - **Plain English:** The "undo deletion" button and the "staff undoes your deletion" admin action share most of their plumbing — restoring your account status, re-publishing your site, and sending you a confirmation email. Someone copied and pasted the plumbing into two separate pipes. If a plumber fixes one pipe later, the other pipe might still leak. Merge the shared plumbing into one pipe and let each button attach its own label.
    - **Evidence:**
        ```php
        // cancel() — lines ~270-285
        DB::transaction(function () use ($professional, $previousStatus) {
            $professional->update([
                'status' => $previousStatus,
                'deletion_requested_at' => null,
                'deletion_confirmed_at' => null,
                'deletion_previous_status' => null,
                'deletion_token_hash' => null,
            ]);

            $site = Site::query()
                ->where('user_id', $professional->id)
                ->lockForUpdate()
                ->first();
            if ($site && $site->unpublished_at !== null) {
                $site->update([
                    'is_published' => true,
                    'unpublished_at' => null,
                ]);
            }
        });

        try {
            Mail::to($professional->primary_email)->send(
                new AccountDeletionCancelledMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                )
            );
        } catch (\Throwable $e) {
            Log::error('Account deletion cancelled mail failed', [...]);
        }
        ```
        ```php
        // adminCancel() — same blocks, verbatim copy
        DB::transaction(function () use ($professional, $previousStatus) {
            // ... identical $professional->update(...) block
            // ... identical Site::query()->lockForUpdate() block
        });

        try {
            Mail::to($professional->primary_email)->send(
                new AccountDeletionCancelledMail(
                    displayName: (string) ($professional->display_name ?? 'there'),
                )
            );
        } catch (\Throwable $e) {
            Log::error('Account deletion cancelled mail failed', [...]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

<!-- ═══ LENS: code-quality-slop | CHUNK: http-jobs ═══ -->

- [ ] **#SLOP-1** · P3 — `shouldRememberConfirmationPreference` copy-pasted verbatim across three controllers
    - **Where:** `app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php`, `app/Http/Controllers/Api/User/Uploads/UserUploadController.php`, `app/Http/Controllers/Api/User/Customers/UserCustomerController.php`
    - **Affects:** Developers maintaining destructive-action confirmation dialogs; any change must be applied in triplicate.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the method to a shared trait (e.g. `ConfirmsDestructiveActions`) or a helper on `ConfirmationPreferenceService`.
        - Replace the three private copies with a single call.
    - **Technical:** The three private methods are character-for-character identical — each returns the boolean OR of three request flags. Every controller that wires `ConfirmationPreferenceService` currently ships its own copy. A shared location would keep them from drifting when a fourth flag is added or the logic changes.
    - **Plain English:** The same small helper lives in three different files. It's like having the same sticky note on three different desks — if the rule changes, you have to update every copy by hand. Move it to one shared spot.
    - **Evidence:**
        ```php
        // UserGalleryController (lines near bottom of class)
        private function shouldRememberConfirmationPreference(Request $request): bool
        {
            return $request->boolean('remember_confirmation_preference')
                || $request->boolean('always_allow_confirmation')
                || $request->boolean('dont_ask_again');
        }

        // UserUploadController (identical)
        private function shouldRememberConfirmationPreference(Request $request): bool
        {
            return $request->boolean('remember_confirmation_preference')
                || $request->boolean('always_allow_confirmation')
                || $request->boolean('dont_ask_again');
        }

        // UserCustomerController (identical)
        private function shouldRememberConfirmationPreference(Request $request): bool
        {
            return $request->boolean('remember_confirmation_preference')
                || $request->boolean('always_allow_confirmation')
                || $request->boolean('dont_ask_again');
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SLOP-2** · P3 — `normaliseOptionalString` copy-pasted verbatim across two controllers
    - **Where:** `app/Http/Controllers/Api/User/Account/UserDocumentController.php` and `app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php`
    - **Affects:** Developers touching string normalisation; any behavioural change (e.g. stripping non-breaking spaces) must be applied in two places.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move to a shared helper (e.g. a `TrimsOptionalStrings` trait, or a static method on a `StringNormalizer` utility).
        - Replace both private copies with the shared call.
    - **Technical:** The two `normaliseOptionalString` methods are character-for-character identical — `null` passthrough, `trim`, coerce empty-string to `null`. `UserWorkplaceController::trimOrNull` is a near-variant (adds an `is_string` guard) that would also benefit from a single canonical implementation.
    - **Plain English:** Same small utility, same logic, sitting in two different places. It's a duplicate key on the workbench — one source of truth is easier to maintain.
    - **Evidence:**
        ```php
        // UserDocumentController
        private function normaliseOptionalString(?string $raw): ?string
        {
            if ($raw === null) {
                return null;
            }
            $trimmed = trim($raw);
            return $trimmed === '' ? null : $trimmed;
        }

        // UserGalleryController (identical)
        private function normaliseOptionalString(?string $raw): ?string
        {
            if ($raw === null) {
                return null;
            }
            $trimmed = trim($raw);
            return $trimmed === '' ? null : $trimmed;
        }
        ```
    - `[DRAFT, confidence: 0.9]`
