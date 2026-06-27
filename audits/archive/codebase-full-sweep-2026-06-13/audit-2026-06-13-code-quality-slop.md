# AI Slop & Low-Value Code Audit — 2026-06-13

**Branch:** development
**Lens:** AI Slop & Low-Value Code — comment noise, premature abstraction, dead code, defensive cruft, copy-paste drift
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Services/Platforms/EventbriteScraper.php`
- `app/Services/Platforms/HumanitixScraper.php`
- `app/Services/User/AccountDeletionService.php`
- `app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php`
- `app/Http/Controllers/Api/User/Uploads/UserUploadController.php`
- `app/Http/Controllers/Api/User/Customers/UserCustomerController.php`
- `app/Http/Controllers/Api/User/Account/UserDocumentController.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 3 complete

---

## P2 — Should fix

- [ ] **#SLOP-1** · P2 — `cancel()` and `adminCancel()` duplicate the same DB-locking site-restore block
    - **Where:** `app/Services/User/AccountDeletionService.php:357–395` (`adminCancel`) and `:430–468` (`cancel`)
    - **Affects:** Any future change to the re-publish logic (e.g. adding a custom-domain clearance step) must be applied twice. The duplicated block contains a `lockForUpdate` — getting one copy right while missing the other leaves a correctness gap on the deletion-recovery path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the shared DB transaction (status rollback + `Site::query()->lockForUpdate()` re-publish) into a private `restoreSiteAndStatus(User $professional, string $previousStatus): void`.
        - Extract the mail try/catch into a private `sendDeletionCancelledMail(User $professional): void`.
        - In both public methods, call those two helpers; keep only the `logAuditEvent(...)` call inline since it carries different event constants and actor parameters.
    - **Technical:** Both `cancel()` and `adminCancel()` contain character-for-character identical 38-line blocks covering (1) `$professional->update([...])`, (2) `Site::query()->lockForUpdate()->first()` conditional re-publish, and (3) `Mail::to()->send(new AccountDeletionCancelledMail(...)` with the same error-log shape. The only per-method differences are the `logAuditEvent` constants (`EVENT_CANCELLED` vs `EVENT_ADMIN_CANCELLED`) and the actor parameters. The `lockForUpdate` in the shared block is the correctness-critical pattern — if a future change (e.g., clearing a custom-domain record on restore) is added to one copy and not the other, one cancellation path silently skips the new step. CLAUDE.md "Simplicity first: make every change as simple as possible. Impact minimal code" + "three similar lines > a premature abstraction" both apply; here the copies are long enough and the risk real enough that extraction is clearly warranted. Category 6 (copy-paste duplication with real drift risk).
    - **Plain English:** When someone cancels a deletion themselves versus when a staff member cancels it for them, the backend runs the same 38-line procedure twice — in two different places in the code. Right now they're identical, but the next time someone adds a step to that procedure (like "also remove the custom domain when restoring an account"), they'll likely add it to one copy and forget the other. One user will get fully restored; another won't. Move the shared steps into one place that both cancellation paths call.
    - **Evidence:**
        ```php
        // adminCancel() — AccountDeletionService.php:357–395
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
            Log::error('Account deletion cancelled mail failed', [
                'user_id' => $professional->id,
                'error' => $e->getMessage(),
            ]);
        }

        // cancel() — AccountDeletionService.php:430–468
        // Identical DB::transaction block and Mail try/catch — verbatim copy.
        ```

---

## P3 — Nice to have

- [ ] **#SLOP-2** · P3 — `formatPrice` and `normalizeAvailability` copy-pasted verbatim across two event scrapers
    - **Where:** `app/Services/Platforms/EventbriteScraper.php:203–238` and `app/Services/Platforms/HumanitixScraper.php:219–254`
    - **Affects:** Future maintainers changing event price/availability display (e.g. adding a new `schema.org` availability token) must remember to update both files.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move both methods to `app/Services/Platforms/PlatformScraper.php` (the shared base class both scrapers already extend) as `protected` methods.
        - Delete the private copies from each scraper.
    - **Technical:** Both methods are character-for-character identical — same inline comment, same logic, same return shapes. `PlatformScraper` already exists and is the correct home: both scrapers extend it, the helpers are pure functions over schema.org arrays with no per-scraper dependencies, and `PlatformScraper::formatPrice` would be visible to any future third event-scraper automatically. CLAUDE.md "three similar lines > a premature abstraction" — here the reverse applies: two identical 18-line private methods that belong in a shared parent is the case for consolidation, not duplication. Category 6.
    - **Plain English:** Two helpers — one that formats ticket prices into readable strings like "AUD 20 – 50", and one that turns schema.org availability URLs into "available" or "sold_out" — live in both the Eventbrite scraper and the Humanitix scraper as separate copies. Both scrapers already share a common parent class, which is the natural single home for shared helpers. Merge them upward so a fix in one place benefits both scrapers automatically.
    - **Evidence:**
        ```php
        // EventbriteScraper.php:203–238 and HumanitixScraper.php:219–254 — identical bodies

        // AggregateOffer → a display string. "Free", "AUD 20.00", or "AUD 20.00 – 50.00".
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

        // schema.org availability URL → "available" | "sold_out" | null.
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

- [ ] **#SLOP-3** · P3 — `shouldRememberConfirmationPreference` copy-pasted verbatim across three controllers
    - **Where:** `app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php:179`, `app/Http/Controllers/Api/User/Uploads/UserUploadController.php:338`, `app/Http/Controllers/Api/User/Customers/UserCustomerController.php:180`
    - **Affects:** Any future change to the confirmation-preference flag set (e.g. adding a fourth flag name) must be applied in three files.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `static shouldRemember(Request $request): bool` method to `ConfirmationPreferenceService` (already imported by all three controllers).
        - Replace the three private copies with `ConfirmationPreferenceService::shouldRemember($request)`.
    - **Technical:** All three private methods are character-for-character identical — three OR'd `$request->boolean()` calls. `ConfirmationPreferenceService` is already injected or resolved via `app()` at every call site, making it the natural home; no new dependency is introduced. Adding it as `static` avoids needing to thread the service instance just for a three-liner. CLAUDE.md "three similar lines > a premature abstraction" applies in reverse: when a three-liner is already in triplicate, consolidation is a net win, not over-engineering. Category 6.
    - **Plain English:** The same three-line check — "did the user tick any of the 'don't ask again' checkboxes?" — is copy-pasted into three different controllers. It's a single rule living in three places; if a new checkbox name is added to the frontend, all three files need updating. One static method on the existing service that already handles confirmation preferences is the obvious home.
    - **Evidence:**
        ```php
        // Identical in UserGalleryController.php:179, UserUploadController.php:338, UserCustomerController.php:180
        private function shouldRememberConfirmationPreference(Request $request): bool
        {
            return $request->boolean('remember_confirmation_preference')
                || $request->boolean('always_allow_confirmation')
                || $request->boolean('dont_ask_again');
        }
        ```

- [ ] **#SLOP-4** · P3 — `normaliseOptionalString` copy-pasted verbatim across two controllers
    - **Where:** `app/Http/Controllers/Api/User/Account/UserDocumentController.php:304` and `app/Http/Controllers/Api/User/SiteManagement/UserGalleryController.php:145`
    - **Affects:** Any change to the trim/null-coercion logic (e.g. also stripping non-breaking spaces) must be applied in two files.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move to a `TrimsOptionalStrings` trait or a `StringNormalizer` utility with a `static normalise(?string $raw): ?string` method.
        - Note: `UserWorkplaceController::trimOrNull` and `StaffWorkplaceController::trimOrNull` (both confirmed identical) do the same thing with a `mixed` input guard — consider folding those into the same canonical implementation while you're there.
    - **Technical:** The two `normaliseOptionalString` bodies are character-for-character identical: null passthrough, `trim()`, empty-string-to-null coercion. A near-variant (`trimOrNull(mixed $value)`) exists in two additional controllers (`UserWorkplaceController:104`, `StaffWorkplaceController:37`) adding only an `is_string` guard. A single canonical helper with an overloaded signature or a `mixed`-input variant would cover all four callers. CLAUDE.md "Simplicity first / impact minimal code" — this is a one-file fix that closes four drift sites. Category 6.
    - **Plain English:** Four controllers each carry their own private copy of a tiny helper that trims whitespace and converts empty strings to null. They're all doing the same thing. Put it in one shared place; any future improvement (or bug fix) automatically reaches all four.
    - **Evidence:**
        ```php
        // UserDocumentController.php:304–313 and UserGalleryController.php:145–154 — identical bodies

        private function normaliseOptionalString(?string $raw): ?string
        {
            if ($raw === null) {
                return null;
            }

            $trimmed = trim($raw);

            return $trimmed === '' ? null : $trimmed;
        }
        ```
