- [ ] **LIFE-1** · P1 — SendEnquiryNotificationJob has a read-modify-write race on `email_sent_at`
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:38-50
    - **Affects:** Site enquiry email recipients — duplicate enquiry notification emails when the job retries or two workers pick up the same job concurrently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `email_sent_at` null check + email send + stamp in a `DB::transaction()` with `Enquiry::query()->lockForUpdate()->find($this->enquiryId)`.
        - Follow the exact pattern used by `SendTransactionalNotificationEmailJob` (same file group), which already does `lockForUpdate` inside a transaction.
    - **Technical:** The job reads `$enquiry->email_sent_at !== null`, sends email if false, then stamps the field. Between the read and the write there is no row lock. Two concurrent job instances (retry overlapping with original, or Horizon scaling) both see `null`, both call `Mail::send()`, both stamp — the recipient gets a duplicate. The `SendTransactionalNotificationEmailJob` in the same directory already uses the correct `lockForUpdate` pattern and should be the reference implementation.
    - **Plain English:** Picture two postal workers both checking a mailbox flag that says "mail already delivered." If neither locks the flag while they check it, both see "not delivered," both drop off the same letter, and the recipient gets two copies. The fix is having the first worker flip the flag while holding it so the second worker sees "already delivered" and walks away.
    - **Evidence:**
        ```php
        if ($enquiry->email_sent_at !== null) {
            return; // already sent on a previous attempt
        }

        Mail::to($this->notificationEmail)->send(new SiteEnquiryNotification($enquiry));

        $enquiry->forceFill(['email_sent_at' => now()])->saveQuietly();
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-2** · P1 — ContactCaptureService catches generic `QueryException` instead of typed `UniqueConstraintViolationException`
    - **Where:** app/Services/Customers/ContactCaptureService.php:144-150, 165-177, 226-234
    - **Affects:** Every Shopify order webhook, Square booking, and site lead that races on customer creation or marketing subscription upsert. At ~3K orders/day peak, race windows produce ~30 unique-violation exceptions/day that are caught imprecisely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace all three `catch (QueryException $e)` blocks that match on `23505` with `catch (UniqueConstraintViolationException $e)` (Laravel 10+).
        - Follow the canonical `#STRIPE-3` / `35c6f31` pattern — the typed catch is version-stable across Postgres releases and constraint renames.
    - **Technical:** The current code uses `catch (QueryException $e)` + `$e->getCode() === '23505'` to detect unique-constraint violations. This string-based dispatch is fragile — Postgres error codes are stable but the generic `QueryException` catch also intercepts unrelated query failures (deadlocks, serialization failures, connection drops), silently swallowing them on the `!== '23505'` re-throw path or misrouting them into the race-reconciliation logic. The typed `UniqueConstraintViolationException` is a first-class Laravel 10+ exception that maps directly to SQLSTATE 23505.
    - **Plain English:** The code catches every kind of database error in one net, then checks a numeric code to decide if it's the specific "two people tried to insert the same thing" error. If Postgres changes how it reports errors, or if a completely different error happens to share a code in a future version, the wrong recovery path runs. The fix is using a purpose-built net that only catches that one specific error type — like a filter that only lets through the exact fish you're looking for.
    - **Evidence:**
        ```php
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            // Phone collides with another contact
        ```
        ```php
        } catch (QueryException $e) {
            // 23505 = another request beat us to the INSERT.
            if ($e->getCode() === '23505') {
        ```
        ```php
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            $customer->phone = $customer->getOriginal('phone');
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-3** · P2 — PublicCustomerLeadController catches generic `QueryException` for unique-constraint detection
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php:184-188
    - **Affects:** Marketing subscription upserts during lead form submissions. Lower throughput than the Shopify path (~dozens/day vs ~3K orders/day), but same fragility.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (QueryException $e)` with `catch (UniqueConstraintViolationException $e)`.
        - Same canonical pattern: `#STRIPE-3` / `35c6f31`.
    - **Technical:** Identical pattern to LIFE-2 — `QueryException` catch + `$e->getCode() === '23505'` branch. Same risk of intercepting unrelated query failures. Typed exception is the correct defense.
    - **Plain English:** Same issue as LIFE-2 but in the public lead-capture flow. The net is catching all fish when it only wants one specific species.
    - **Evidence:**
        ```php
        } catch (QueryException $e) {
            // If a race creates the row first, just ignore.
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-4** · P1 — PublicEmailSubscriptionController swallows customer-upsert exception without reporting to Nightwatch
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php:104-113
    - **Affects:** Every newsletter signup where the follow-up customer upsert fails. The subscription succeeds silently but the customer record is never created or updated — data drifts with zero observability. At ~40K daily notifications fan-out peak and growing subscriber lists, this silent failure accumulates.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($exception);` before the `Log::warning(...)` call inside the catch block.
        - Follow the canonical Log-with-context pattern — Nightwatch alerts trigger on exceptions, not on log queries.
    - **Technical:** The `try/catch` around `upsertMarketingCustomer()` logs a warning but never calls `report($exception)`. Laravel's exception handler (`report()`) forwards to Nightwatch/Datadog — without it, the failure is invisible to alerting. The `Log::warning` call is a breadcrumb, not an alert trigger. Nightwatch groups and alerts on exceptions; a log query requires manual dashboard creation. The `Log::warning` context already includes `professional_id` and `email`, which is good — but the missing `report()` means the ops team never knows the customer record drifted.
    - **Plain English:** When someone signs up for a newsletter, the system also tries to create or update their customer profile. If that second step fails, the code writes a note in a journal but doesn't sound the fire alarm. The journal sits unread until someone manually checks it. The fix is adding one line that triggers the fire alarm so the team knows something needs attention.
    - **Evidence:**
        ```php
        try {
            $this->upsertMarketingCustomer(
                (string) $site->professional_id,
                $email,
                $resolvedName,
                $overwriteName,
            );
        } catch (\Throwable $exception) {
            // Do not block successful subscription if customer sync fails.
            Log::warning('Public subscribe customer upsert failed', [
                'professional_id' => (string) $site->professional_id,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-5** · P2 — ContactCaptureService swallows all exceptions without reporting
    - **Where:** app/Services/Customers/ContactCaptureService.php:101-108, 164-176
    - **Affects:** Every order webhook, booking, and lead that calls `captureContact()` or `captureMarketingSubscription()`. Failures are logged as warnings but never surface to Nightwatch. At ~3K orders/day peak, this is the highest-throughput silent-failure path in the provided scope.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before each `Log::warning(...)` in both catch blocks.
        - Follow the canonical Log-with-context pattern.
    - **Technical:** Both `captureContact()` and `captureMarketingSubscription()` catch `Throwable`, log a warning, and return null / continue. Neither calls `report($e)`. This is the same pattern as LIFE-4 but on a higher-throughput path — a Postgres connectivity blip during the 3K/day order peak would produce ~30 swallowed exceptions per peak-hour minute with no Nightwatch visibility.
    - **Plain English:** The contact-capture service is designed to never crash the main flow — if saving a customer fails, the order still processes. But when it fails, it only writes a journal entry. No alarm goes off. If the database has a hiccup, hundreds of customer records silently fail to save and no one knows until a support ticket comes in.
    - **Evidence:**
        ```php
        } catch (Throwable $e) {
            Log::warning('Contact capture failed', [
                'professional_id' => $professionalId,
                'source' => $data['source'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
        ```
        ```php
        } catch (Throwable $e) {
            Log::warning('Marketing subscription capture failed', [
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-6** · P2 — StaffEmailSubscriberController has no Policy authorization gate
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php:30-65, 71-102
    - **Affects:** GDPR Article 15/20 right-of-access requests routed through the platform inbox. Any authenticated staff member can list and export any professional's email subscribers without a Policy check.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Policy ability (e.g., `viewSubscribers`) to `SubscriptionPolicy` or a new `EmailSubscriptionPolicy` that gates staff access to a professional's subscriber list.
        - Call `$this->authorizeForUser($staffUser, 'viewSubscribers', $professional)` in both `index()` and `export()` methods before the query.
        - Follow the canonical `#STRIPE-1` / `Policy + Form Request` pattern — authorization through Policies, never implicit.
    - **Technical:** The Partna Authorization Doctrine mandates that authorization goes through Policies, never inline. The controller accepts a `Professional` via route model binding and queries `EmailSubscription` scoped to that professional without any `authorizeForUser` call. The staff `auth` middleware gates authentication, not authorization — it confirms the actor IS staff but not WHICH professional's data they may access. At 200 brands, the staff team may need role-based access (support tier 1 vs. compliance officer); embedding that logic in a Policy now keeps the controller clean as staff roles evolve.
    - **Plain English:** The controller is like a filing cabinet that any employee with a staff badge can open. Right now that's fine because the staff team is small, but at 200 brands with differentiated staff roles (support agent vs. compliance officer), you'll want a lock that checks the employee's role before letting them pull a specific brand's subscriber list. Adding a Policy now means the lock is already installed when you need it.
    - **Evidence:**
        ```php
        // No authorizeForUser call anywhere in the controller
        public function index(Request $request, Professional $professional): JsonResponse
        {
            // ... directly queries EmailSubscription for $professional->id
            $query = EmailSubscription::query()
                ->where('professional_id', $professional->id)
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **LIFE-7** · P2 — InviteExpirySweepJob log context missing `brand_professional_id` for Nightwatch correlation
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php:72-76
    - **Affects:** Operational visibility into the daily invite-expiry sweep. When the job fails at the top level, Nightwatch cannot correlate the failure to a specific brand.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - The per-invite `Log::warning` inside the loop already includes `brand_professional_id` (good), but the top-level `failed()` method's `Log::error` does not — add `brand_professional_id` context by capturing it from the last-processed chunk or by making the job brand-scoped.
        - Follow the canonical Log-with-context pattern — every log emit should carry `brand_professional_id`, operation name, and `request_id` where available.
    - **Technical:** The `failed()` method logs only `'message' => $e->getMessage()` with no tenant or operation context. Nightwatch groups exceptions by message string + stack trace; without a tenant discriminator, a single bad invite row that crashes the sweep produces an alert with no way to identify which brand's invite caused it. The per-invite warning inside the loop does include context, but if the job fails before processing any chunk (e.g., DB connection timeout), the `failed()` log is the only record.
    - **Plain English:** When the overnight invite cleanup job crashes, the error log says "sweep failed" but doesn't say whose invite broke it. Support has to manually scan the database to find the bad row. Adding the brand ID to the crash log is like putting the room number on a fire alarm — the responder knows exactly where to go.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::error('Invite expiry sweep job failed', [
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-8** · P3 — CommerceNotificationService catches `\Throwable` at the top level without `report()`
    - **Where:** app/Services/Notifications/CommerceNotificationService.php:80-87
    - **Affects:** Booking-completion notification fan-out. When the entire `notifyBookingCompleted()` call fails, the exception is reported but the inner catch also logs a warning — the `report($e)` is present (good), but this `catch` block duplicates the logging that would happen anyway from the exception bubbling up. Harmless but unnecessary.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `Log::warning` call — `report($e)` already forwards to Nightwatch, and the warning is duplicate signal.
        - Alternatively, if the warning adds context the exception doesn't carry, keep it but ensure it doesn't fire on the same exception that `report()` already sent — use `report($e)` only and let the exception's own message carry the context.
    - **Technical:** The method wraps the entire body in `try { ... } catch (\Throwable $e) { report($e); Log::warning(...); }`. `report($e)` already logs the exception with full stack trace to Nightwatch. The additional `Log::warning` produces a second, less-structured log entry for the same failure. At ~40K daily notifications, this doubles the log volume for booking-notification failures without adding signal.
    - **Plain English:** When the booking notification engine fails, it writes two separate journal entries for the same problem — a detailed one (the automatic crash report) and a shorter handwritten one. The shorter one adds no new information and clutters the journal. Pick one.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Booking notifications failed', [
                'professional_id' => $context['professional_id'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.70]`
