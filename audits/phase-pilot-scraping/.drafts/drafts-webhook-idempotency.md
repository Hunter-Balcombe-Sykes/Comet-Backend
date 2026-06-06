- [ ] **WHK-1** · P0 — Webhook ingestion surfaces listed in audit scope are absent from the provided file set; audit of categories 1–10 cannot proceed
    - **Where:** scope groups A–D (Shopify/Stripe/Square/Fresha/Supabase webhook controllers and jobs) — none present in Files Under Audit
    - **Affects:** All vendor webhook processing paths — Shopify orders/payments, Stripe payment intents, Square catalog sync, Fresha catalog sync, Supabase auth hooks, internal webhooks
    - **Effort:** N/A (scoping gap, not a code fix)
    - **What to do:**
        - Re-run this audit with the actual webhook controller and job files included: `app/Http/Controllers/Api/Webhooks/Shopify/`, `app/Http/Controllers/Api/Webhooks/Stripe/`, `app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php`, `app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php`, `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`, `app/Jobs/Shopify/`, `app/Jobs/Stripe/`, `app/Services/Stripe/`, and `routes/api.php` webhook route groups.
        - Verify HMAC ordering (cat 1), idempotency anchors (cat 2), dispatch-failure-200 (cat 3), controller DB writes (cat 4), job idempotency (cat 5), out-of-order tolerance (cat 6), validation-200 (cat 7), retry budget match (cat 8), raw payload archival (cat 9), and replay tooling (cat 10) against the gold standard for every vendor.
    - **Technical:** The provided file set contains only the user-facing "platforms" dashboard integration feature (`app/Http/Controllers/Api/Platforms/*`, `app/Services/Platforms/*`, `routes/api/integrations.php`) — user-initiated, authenticated CRUD for connecting external profiles (TikTok, Facebook, YouTube, etc.) to a Partna account. These are NOT webhook ingestion endpoints. The actual vendor webhook receivers (Shopify order events, Stripe payment events, Square/Fresha catalog pushes, Supabase auth hooks) are referenced in the audit scope groups but were not included in the file list. Without those files, every gold-standard property (HMAC verification ordering, idempotency key persistence, dispatch-failure handling, raw payload archival, replay tooling) is unverifiable. The only POST routes in the provided `routes/api/integrations.php` are behind `user.api` middleware — dashboard auth, not vendor webhook signatures.
    - **Plain English:** The audit asked me to inspect every door where external services knock to deliver data (Shopify sending order updates, Stripe sending payment confirmations). But the files you gave me show the interior of the house — the dashboard where users connect their accounts — not the front doors where those external knocks arrive. I can't check if the doors have proper locks, peepholes, or visitor logs until I can see them. The next step is to include the actual webhook entry-point files in the audit.
    - **Evidence:**
        ```php
        // routes/api/integrations.php — all POST routes are user dashboard actions,
        // NOT vendor webhook receivers:
        Route::prefix("{$base}/fresha")
            ->middleware(['user.api', 'throttle:authenticated'])  // ← authenticated user, not vendor
            ->group(function () {
                Route::post('/connect', [FreshaController::class, 'connect']);
                // ...
            });
        ```
    - `[DRAFT, confidence: 1.0]`
