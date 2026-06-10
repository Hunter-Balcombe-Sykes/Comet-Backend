`★ Insight ─────────────────────────────────────`
The systemic `catch (\RuntimeException $e) { return $this->error(...) }` pattern across Store controllers is a structural observability gap — the framework exception handler only sees *uncaught* exceptions. By catching and converting to a response, every Shopify API failure becomes permanently invisible to Nightwatch even when it's happening to 200 brands simultaneously. The canonical `Log-with-context` fix is one line per catch block but the diagnostic value at scale is enormous.
`─────────────────────────────────────────────────`

# Lifecycle Correctness Audit (Group D + Stripe/Store) — 2026-05-11

**Branch:** development
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php`
- `app/Http/Controllers/Api/Professional/Stripe/AffiliateStripeOnboardingController.php`
- `app/Http/Controllers/Api/Professional/Brand/BrandBillingSummaryController.php`
- `app/Http/Controllers/Api/Professional/Brand/BrandPayoutsController.php`
- `app/Http/Controllers/Api/Professional/Affiliate/AffiliatePayoutsController.php`
- `app/Http/Controllers/Api/Professional/Store/BrandCatalogController.php`
- `app/Http/Middleware/Auth/VerifySupabaseJwt.php`
- `app/Http/Middleware/Auth/VerifyHydrogenApiKey.php`
- `app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php`
- `app/Http/Middleware/Auth/EnsurePartnaStaff.php`
- `app/Http/Middleware/Auth/EnsurePartnaAdmin.php`
- `app/Http/Requests/Api/BootstrapRequest.php`
- `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php`
- `app/Policies/*.php` (full set)
- `app/Http/Requests/**/*.php` (full set)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#LIFE-1** · P1 — `syncPaymentMethodSession` and `confirmTopUpCheckoutSession` swallow `\RuntimeException` with zero server-side trace
    - **Where:** `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php` — `syncPaymentMethodSession()` and `confirmTopUpCheckoutSession()` catch blocks
    - **Affects:** Every brand setting up a payment method or confirming a manual wallet top-up. At 200 brands, a Stripe partial outage produces up to 200 silent 422 responses — Nightwatch never fires, no alert emits, the operator discovers the problem only when brands complain.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::error(...)` inside each catch block **before** returning the 422 — include `brand_professional_id`, `session_id` (from the request), and `$e->getMessage()` verbatim.
        - Narrow the catch from broad `\RuntimeException` to the Stripe SDK subtypes you actually expect (e.g. `Stripe\Exception\ApiErrorException`). Any non-Stripe `RuntimeException` should be allowed to bubble — the framework logger then captures it automatically.
        - Apply the same fix to both endpoints; they are structurally identical.
    - **Technical:** Catching `\RuntimeException` and immediately returning a 422 is a swallowed exception — Laravel's exception handler never sees it, Nightwatch gets no event, and `Log::error` never fires. This is the same failure mode as `#STRIPE-2` (commit `35c6f31`) where two distinct outcomes shared one silent code path. The canonical `Log-with-context` + `verbatim vendor error capture` pattern from `bf6e46d` requires: (a) log with `brand_professional_id`, `request_id`, and operation name, (b) preserve the Stripe error message verbatim rather than paraphrasing. A Stripe API degradation at the scale target (200 brands) produces 200 invisible 422s. No `Log::*` call exists anywhere in `app/Http/Controllers/Api/Professional/Stripe/` — confirmed by search.
    - **Plain English:** Imagine a store's card terminal silently swallowing every "card declined" without printing any record for the store manager. The customer sees "Something went wrong" but the manager has no alert, no log, and no idea whether the problem is the terminal, the network, or Stripe. That's what these two payment endpoints do — they catch Stripe failures and return an error to the browser, but tell nobody on the engineering side. At 200 brands trying to set up payments during a Stripe incident, the ops team would have zero visibility until brands start emailing.
    - **Evidence:**
        ```php
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        ```
        *(Identical block appears in both `syncPaymentMethodSession()` and `confirmTopUpCheckoutSession()`. No `Log::` call present anywhere in the Stripe controller directory.)*

---

## P2 — Should fix

- [ ] **#LIFE-2** · P2 — Store controllers catch Shopify `\RuntimeException` and `\Throwable` with no server-side logging — systemic across 13+ endpoints
    - **Where:** `app/Http/Controllers/Api/Professional/Store/BrandCatalogController.php` — `index()`, `all()`, `refreshDerivedFlags()`, and at least 10 additional endpoints across `BrandCollectionController`, `BrandStoreSettingsController`, `AffiliateProductController`, `AffiliateProductPhotoController` (13 call-sites confirmed by search)
    - **Affects:** Every Shopify-connected brand. At 200 brands, a Shopify API degradation produces hundreds of silent 500/502 responses with no server-side trace. Operators cannot distinguish a code regression from infrastructure noise, and cannot triage which brands are affected without reading client-side bug reports.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `Log::warning('Shopify catalog fetch failed', ['brand_professional_id' => $pro->id, 'error' => $e->getMessage(), 'operation' => __METHOD__])` inside each `\RuntimeException` catch block before returning the error response.
        - The `\Throwable` catch (which already paraphrases the error for users with `'Unable to reach Shopify...'`) should additionally log the actual exception message verbatim for operators: `Log::error('Shopify unreachable', ['brand_professional_id' => $pro->id, 'error' => $e->getMessage(), 'operation' => __METHOD__])`.
        - Confirm the `ShareCheckoutLinkController` logging pattern (which does have `Log::warning` / `Log::error` calls) and use it as the model for the missing cases.
    - **Technical:** Category 10 (`Log-with-context`). A caught exception that becomes a returned response is permanently invisible to the framework exception handler and Nightwatch. The `\Throwable` catch additionally violates the `verbatim vendor error capture` pattern — the Shopify GraphQL or HTTP error message is discarded and replaced with a user-facing paraphrase, destroying debug signal. At 1M orders/year across 200 brands, the catalog read path is one of the highest-volume Shopify-touching endpoints; any API regression surfaces as unexplained 5xx noise with no server-side anchor for correlation. The `ShareCheckoutLinkController` (which correctly has `Log::warning` / `Log::error` in its catch paths) demonstrates the expected pattern already exists in adjacent code.
    - **Plain English:** The store catalog endpoints are like a warehouse stock-check system that notices when the inventory database is down, shows "warehouse unavailable" to the staff member asking, but doesn't record anywhere that the warehouse was down or for how long. When the CEO asks "why couldn't anyone see product stock for the last two hours?", the answer is: "we don't know, we have no records." Adding a log line to each of these catch blocks is equivalent to writing "warehouse offline — 10:32am" in a maintenance log.
    - **Evidence:**
        ```php
        // BrandCatalogController::index() and ::all() — identical pattern
        try {
            $products = $this->catalogService->fetchBrandCatalog($pro);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Throwable $e) {
            return $this->error('Unable to reach Shopify. Please try again.', 502);
        }
        ```

- [ ] **#LIFE-3** · P2 — `VerifySupabaseJwt` warning logs lack `request_id` and operation name — auth-failure incidents un-debuggable at scale
    - **Where:** `app/Http/Middleware/Auth/VerifySupabaseJwt.php` — both `Log::warning` call-sites in the JWKS fallback path and the final auth-failure path
    - **Affects:** Every authenticated API call that hits the JWKS-failure or auth-server-failure path. At ~40K daily authenticated calls, an auth infrastructure incident (JWKS endpoint degraded, Supabase Auth outage) produces a flood of correlated warning logs with no request identifier — operators cannot trace a specific user's repeated failures, measure failure rate, or distinguish a spike from normal noise.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'request_id' => $request->header('X-Request-Id', str()->uuid())` and `'operation' => 'VerifySupabaseJwt'` to both `Log::warning` context arrays.
        - On the second log call (final auth-failure, inside the auth-server fallback), also include `'supabase_uid' => null` as a reminder that the uid was unresolved — keeps the log shape consistent with authenticated-path logs that carry it.
        - Note: `brand_professional_id` is not available at this middleware layer (runs before `LoadCurrentProfessional`); `request_id` + operation + IP is the correct floor for this call site.
    - **Technical:** Category 10 (`Log-with-context`). Nightwatch correlates log lines to traces via `request_id` and `operation`. Without these fields, JWKS-failure warnings and auth-failure warnings are floating uncorrelated entries — during an auth incident, support has raw IP addresses but no way to group by user, request chain, or time window. The canonical `Log-with-context` pattern requires at minimum `request_id` and operation name on every warning/error. The `ip` field already present is necessary but not sufficient for Nightwatch correlation.
    - **Plain English:** Think of a building's access-log system that records "badge read failed" but doesn't record which door, which badge reader, or which transaction ID. During a security audit you can see that failures happened, but you can't trace whether it was one person trying 50 times at one door or 50 different people failing once each. These JWT failure logs have the same problem — they record the reason and IP but not which API request triggered the failure, so during an outage the logs are a pile of isolated warnings with no thread to pull.
    - **Evidence:**
        ```php
        Log::warning('JWT JWKS verification failed, falling back to auth server', [
            'reason' => $e->getMessage(),
            'ip' => $request->ip(),
        ]);
        ```
        ```php
        Log::warning('JWT verification failed', [
            'reason' => $e2->getMessage(),
            'ip' => $request->ip(),
        ]);
        ```

---

## P3 — Nice to have

- [ ] **#LIFE-4** · P3 — `BootstrapRequest` and `UpdateSiteRequest` silently swallow DB exceptions during handle-alias uniqueness checks without logging
    - **Where:** `app/Http/Requests/Api/BootstrapRequest.php` — inline `handle_lc` validator closure; `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php` — inline `subdomain` validator closure
    - **Affects:** New professional signups and site subdomain changes. If `site.professional_handle_aliases` is transiently unavailable (connection timeout, schema search_path misconfiguration, rolling deploy), the alias-uniqueness guardrail silently drops. Duplicate handles become possible for the duration of the outage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('Handle alias check skipped — alias table unavailable', ['handle' => $value, 'error' => $e->getMessage()])` inside each empty catch block.
        - Narrow the catch from the root `\Exception` to `\Illuminate\Database\QueryException` so non-database exceptions (e.g. type errors in the validator logic) still surface as unexpected failures rather than silently degrading the check.
    - **Technical:** Category 10 (`Log-with-context`). The intentional skip-on-failure behavior is correct — blocking signups because a side-table is down is worse than the risk of a duplicate alias. But the silent swallow means an ops team has no signal that the alias check is degraded. The primary enforcement is the `UNIQUE` index on `professionals.handle_lc`; this check is defense-in-depth, which is why P3 is appropriate. Adding a `Log::warning` makes the degraded state visible without changing behavior. Narrowing from `\Exception` to `\Illuminate\Database\QueryException` is a secondary hardening: today, a miscoded validator closure would silently skip itself, but with the narrowed catch it would bubble as an unexpected exception.
    - **Plain English:** A backup alarm system that fails silently when its battery dies. The primary lock still works, but you lose the defense-in-depth layer without knowing it. In this case, the "battery died" is the alias-check database table being temporarily unreachable. Adding a log line is like adding a low-battery indicator: same behavior, but now someone gets notified that the backup check isn't running.
    - **Evidence:**
        ```php
        // BootstrapRequest.php
        } catch (\Exception) {
            // Alias table unavailable — skip the check rather than blocking signup.
        }
        ```
        ```php
        // UpdateSiteRequest.php
        } catch (\Exception) {
            $existsInProfessionalAliases = false;
        }
        ```
