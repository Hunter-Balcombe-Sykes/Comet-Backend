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
