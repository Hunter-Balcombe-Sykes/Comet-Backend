`★ Insight ─────────────────────────────────────`
Key divergence found: `ProcessShopifyOrderWebhookJob` (orders/paid) migrated to the new `_partna_affiliate_id` UUID cart attribute, but `ProcessShopifyOrderUpdatedWebhookJob::resolveAffiliateIdFromPayload()` still uses the legacy `'affiliate'` handle-based lookup for first-seen stub inserts. These two jobs handle disjoint sets of orders, causing silent event loss on out-of-order delivery.
`─────────────────────────────────────────────────`

# Lifecycle Correctness Audit — 2026-05-19

**Branch:** development
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline — Group A (Shopify webhook + integration lifecycle)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php`
- `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php`
- `app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php`
- `app/Jobs/Shopify/RegisterShopifyWebhooksJob.php`
- `app/Http/Controllers/Api/Webhooks/Shopify/ShopifyOrderWebhookController.php`
- `app/Http/Controllers/Concerns/HandlesShopifyWebhook.php`
- `app/Http/Controllers/Concerns/DedupesShopifyWebhookEvent.php`
- `app/Http/Controllers/Concerns/ValidatesShopifyWebhookHmac.php`
- `app/Services/Shopify/BrandSignupService.php`
- `app/Services/Shopify/HydrogenDeploymentService.php`
- `app/Services/Shopify/ShopifyTeardownService.php`
- `app/Services/Professional/Brand/BrandStatusService.php`
- `app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php`

**Note on DeepSeek draft:** DeepSeek refused to produce findings, stating it received a planning document rather than source code. This adjudication is a first-pass audit produced entirely by the adjudicator reading source directly.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **LIFE-1** · P1 — Legacy affiliate cart-attribute key in out-of-order stub inserts
    - **Where:** `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php` — `resolveAffiliateIdFromPayload()` (line 596–613)
    - **Affects:** Any Partna order that receives an `orders/cancelled`, `orders/edited`, or `refunds/create` webhook before the `orders/paid` webhook. At 1 M orders/year with Shopify at-least-once delivery, out-of-order events are a documented normal scenario. A same-session immediate cancellation (e.g. payment fails; Shopify fires `orders/cancelled` ≤1s after `orders/paid`) produces this window on every such order.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - In `resolveAffiliateIdFromPayload`, try `_partna_affiliate_id` (UUID direct-lookup) **first**, then fall back to the legacy `'affiliate'` handle-based lookup for backward compatibility with any pre-migration Hydrogen carts still in flight.
        - Match the lookup logic already in `ProcessShopifyOrderWebhookJob`: `Professional::where('id', $affiliateId)->first()` for the UUID path.
        - Add a unit test covering a first-seen `refunds/create` payload carrying only `_partna_affiliate_id` — assert the stub is created with the correct affiliate FK.
    - **Technical:** `ProcessShopifyOrderWebhookJob` (orders/paid) was migrated to the `_partna_affiliate_id` UUID cart attribute per the comment at line 94–99: *"The legacy `'affiliate'` key + handle-based lookup was a sidest-era pattern that the partna rename missed; resolving by UUID is the new canonical path."* However `ProcessShopifyOrderUpdatedWebhookJob::resolveAffiliateIdFromPayload()` was not updated — it still calls `extractCartAttribute($noteAttributes, 'affiliate')` and resolves by `handle_lc`. Because Shopify webhooks are at-least-once and frequently out-of-order, the first-seen stub path fires whenever a cancellation, edit, or refund webhook lands before the paid webhook. For any order placed through the current Hydrogen storefront (which sets `_partna_affiliate_id` but not `affiliate`), `resolveAffiliateIdFromPayload` returns `null`, the stub is silently skipped, a `Log::warning` is emitted, and the event is permanently lost — `orders/cancelled` will never be retried by Shopify once a 200 was returned. Canonical replacement: the paid-webhook pattern of UUID-first + handle fallback.
    - **Plain English:** Think of an order being placed and immediately cancelled — like a customer who clicks "buy" then immediately clicks "cancel." Our system receives both the "order placed" and "order cancelled" messages from Shopify, but often the "cancelled" message arrives a fraction of a second before the "order placed" message. When that happens, we create a temporary placeholder record so the "cancelled" event has something to attach to. The code that creates that placeholder still uses an old, retired naming scheme to look up which ambassador brought in the sale — so it finds nothing, quietly gives up, and the cancellation is permanently lost. The "order placed" message that arrives moments later creates the full record correctly, but we've already thrown away the cancellation. The fix is a one-line change to look up the affiliate by their account ID (the new scheme) before falling back to the old name-based lookup.
    - **Evidence:**
        ```php
        // ProcessShopifyOrderWebhookJob.php line 94-101 (the canonical pattern — NOT used in updated job)
        // `_partna_affiliate_id` and the value is the affiliate's professional UUID
        // (Hydrogen's `app/routes/$affiliateSlug.tsx` action sets
        // `[{key: '_partna_affiliate_id', value: affiliate.id}]` on cartCreate).
        // The legacy `'affiliate'` key + handle-based lookup was a sidest-era
        // pattern that the partna rename missed; resolving by UUID is the new
        // canonical path and is consistent with the partna-affiliate-discount
        // Shopify Function which also keys on this attribute.
        $affiliateId = $this->extractCartAttribute($noteAttributes, '_partna_affiliate_id');
        ```
        ```php
        // ProcessShopifyOrderUpdatedWebhookJob.php line 596-613 — still on the legacy path
        private function resolveAffiliateIdFromPayload(): ?string
        {
            $noteAttributes = Arr::get($this->payload, 'note_attributes', []);
            $affiliateSlug = $this->extractCartAttribute($noteAttributes, 'affiliate'); // legacy key

            if ($affiliateSlug === '') {
                return null;
            }

            $affiliate = Professional::query()
                ->where('handle_lc', strtolower($affiliateSlug))  // handle lookup, not UUID
                ->first();

            if (! $affiliate) {
                return null;
            }

            return (string) $affiliate->id;
        }
        ```

---

## P2 — Should fix

- [ ] **LIFE-2** · P2 — `BrandStatusService::sync()` — unguarded read-modify-write produces duplicate audit rows
    - **Where:** `app/Services/Professional/Brand/BrandStatusService.php` — `sync()` method (lines 105–155)
    - **Affects:** Any mutation path that can trigger concurrent `sync()` calls on the same brand — e.g. a reinstall OAuth callback racing with the `ReconcileStuckShopifyIntegrationsJob` sweep, or two near-simultaneous wizard-step saves. At 200 brands with 3–5 status transitions/month, the probability per transition is low, but `brand_status_history` has no `UNIQUE` guard, so when it does happen the audit table accumulates phantom rows that misrepresent the timeline.
    - **Effort:** S (~1h)
    - **What to do:**
        - Wrap the `sync()` body in a `DB::transaction()` and add `BrandProfile::where('professional_id', $professional->id)->lockForUpdate()->first()` as the first read inside the transaction, replacing the un-locked `first()` at line 109.
        - This collapses concurrent syncs to a serial queue and eliminates the window where two callers both observe the old status, both compute the same new status, and both insert a `brand_status_history` row.
        - Optionally add a `UNIQUE` constraint on `(professional_id, from_status, to_status, created_at::date)` on `core.brand_status_history` as a belt-and-suspenders guard, though the lock is the primary fix.
    - **Technical:** `sync()` reads `brand_status` at line 109–112, computes a new value via `determine()`, then writes at lines 131–145 (an `updateOrCreate` + a raw `DB::table()->insert()`). The two statements are not inside a transaction and neither is protected by `lockForUpdate`. Two concurrent callers (reinstall callback + reconcile job) can both read the same `currentStatusValue`, both compute the same `newStatusValue`, both update `brand_profiles` (harmless — `updateOrCreate` is idempotent on the same key), and **both insert a row into `brand_status_history`**. The result is a duplicated audit entry that shows the transition happened twice at the same instant. The `brand_status_history` table has no `UNIQUE` constraint (confirmed via migration search). Canonical replacement: `lockForUpdate` on the `BrandProfile` row inside a transaction, matching the `#STRIPE-1` / race-safe wallet-credit pattern.
    - **Plain English:** Imagine two employees both reading the same whiteboard, independently deciding to update it to the same new value, and both writing the change in the logbook — you end up with two logbook entries for a single event. Our code that records when a brand's status changes has no lock preventing two simultaneous processes from both reading the old status, both deciding to write the new one, and both scribbling it in the permanent audit trail. The status itself ends up correct (both processes wrote the same value), but the logbook shows the transition happened twice. For compliance and support use-cases the audit log needs to be accurate, so the fix is to put a "one at a time" lock on the record while the read → update → log sequence runs.
    - **Evidence:**
        ```php
        // BrandStatusService.php sync() — no transaction, no lockForUpdate
        public function sync(Professional $professional): ?string
        {
            $computed = $this->determine($professional);

            $existing = BrandProfile::where('professional_id', $professional->id)
                ->first(); // <-- unguarded read

            $currentStatusValue = $existing?->brand_status ?? 'onboarding';
            // ... compute newStatusValue ...

            BrandProfile::updateOrCreate(          // write #1
                ['professional_id' => $professional->id],
                ['brand_status' => $newStatusValue],
            );

            DB::table('core.brand_status_history')->insert([  // write #2 — no dedup guard
                'professional_id' => $professional->id,
                'from_status' => $currentStatusValue,
                'to_status' => $newStatusValue,
                'reason' => 'auto',
                'created_at' => now(),
            ]);
        }
        ```
