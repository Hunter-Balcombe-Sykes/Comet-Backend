`★ Insight ─────────────────────────────────────`
**Key adjudication takeaways from this audit:**
1. DATA-3 and DATA-4 were hallucinated — the `UNIQUE (idempotency_key)` on `wallet_movements` (line 25 of `20260510300000_add_wallet_movements_ledger.sql`) and the `UNIQUE INDEX notifications_dedupe_key_per_pro_uq` (in `20260423010000_add_dedupe_key_to_notifications.sql`) both exist verbatim in the migrations. DeepSeek's confidence scores of 0.70 were correctly calibrated — "I think the constraint is missing" is exactly the kind of claim that requires tool verification before publishing.
2. `BrandPartnerLinkNotifier` and `SelectionCleanupService` bypass `NotificationPublisher::publish()` entirely — no `dedupe_key`, no email dispatch, non-normalized `type` value. Easy to miss because the hot path (NotificationPublisher) is correct; the bug is in the two cold paths.
`─────────────────────────────────────────────────`

# Data Integrity & Privacy Audit — 2026-05-12

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Auth/SupabaseAdminService.php
- app/Services/Stripe/CommissionPayoutRefundService.php
- app/Services/Stripe/CommissionPayoutService.php
- app/Services/Stripe/StripeConnectService.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Store/SelectionCleanupService.php
- app/Services/Professional/BrandPartnerLinkNotifier.php
- supabase/migrations/20260510300000_add_wallet_movements_ledger.sql
- supabase/migrations/20260423010000_add_dedupe_key_to_notifications.sql
- supabase/migrations/20260506300000_relax_commission_payout_items_link.sql
- supabase/migrations/20260427000000_add_missing_fk_indexes.sql
- supabase/migrations/20260423000001_create_gdpr_requests.sql
- app/Jobs/Gdpr/ExportProfessionalDataJob.php
- app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php
- app/Console/Commands/PurgeSoftDeleted.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#DATA-1** · P1 — PII email address written to log aggregator on every Supabase user creation failure
    - **Where:** app/Services/Auth/SupabaseAdminService.php:69-73
    - **Affects:** Any professional whose Supabase account creation fails — their full email address is written to the Nightwatch/log aggregator with no retention window or redaction path. Transient failures (network blips, Supabase 5xx) mean the same email can appear hundreds of times before the setup wizard gives up.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'email' => $email` in the `Log::error` call with `'email_prefix' => substr($email, 0, 3).'***'` or a `hash('sha256', $email)` fingerprint — enough to correlate logs without storing the raw address.
        - Scan the rest of `SupabaseAdminService` and any callers (`BrandSignupService`, setup wizard controller) for other log statements that include the email variable, and apply the same redaction.
        - Add a note to the PII inventory (memory: `project_shopify_gdpr_webhooks_todo.md`) confirming this log path is remediated.
    - **Technical:** Category 7 (PII inventory & retention). The `createUser` method logs the raw email on two failure branches: a terminal non-422/409 error and the implicitly-caught throw. Log aggregators (Nightwatch, Grafana Loki) have their own retention schedules that are unrelated to GDPR's erasure obligations. The `customer_redact` Shopify GDPR job can anonymise the DB row, but it cannot reach structured log entries. The risk is amplified because this code path runs during the unauthenticated setup wizard — the email hits logs before a `professional` row even exists, so there is no canonical PII record to trace back against.
    - **Plain English:** When the system can't create a new account (for any reason — even just a slow internet connection at exactly the wrong moment), it writes the person's full email address into the server's activity log — like a sticky note on a shared whiteboard. If that error happens ten times during a retry loop, the email is on the whiteboard ten times. The privacy law says we have to be able to delete someone's data, but we can't delete it from the whiteboard. The fix is to only write the first few letters — enough to tell which account had the problem, not enough to identify anyone.
    - **Evidence:**
        ```php
        Log::error('Supabase admin: failed to create user', [
            'email' => $email,
            'status' => $response->status(),
            'error_code' => $response->json('code'),
            'error_msg' => $response->json('msg'),
        ]);
        ```

---

## P2 — Should fix

- [ ] **#DATA-2** · P2 — CommissionPayoutItem rows hard-deleted on refund, severing the per-order → per-payout audit chain
    - **Where:** app/Services/Stripe/CommissionPayoutRefundService.php:113-118
    - **Affects:** Financial audit integrity. After `removeItem()` runs, there is no DB row proving that a given order was ever part of a given payout at a given commission amount. The payout's recalculated totals remain correct, but the per-item chain is gone. Australian financial recordkeeping regulations require 7 years of transaction records.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `status` column (or `removed_at` + `removal_reason` columns) to `commerce.commission_payout_items` via a new Supabase migration. The status column should allow values `'active' | 'removed_by_refund'`.
        - Replace `$item->delete()` with `$item->forceFill(['status' => 'removed_by_refund', 'removed_at' => now()])->save()` so the row survives.
        - Update `revalidatePayoutOrders()` in `CommissionPayoutService` — it also calls `CommissionPayoutItem::where(...)->delete()` on stale items — to use the same soft-tombstone pattern.
        - Add a `WHERE status = 'active'` scope to any aggregation queries that sum `amount_cents` across payout items, so tombstoned rows don't distort totals.
        - Note: the `29b7eb1` CI test asserts financial models carry no Laravel `SoftDeletes` trait — the fix here is an explicit status column, not the trait, so the CI assertion is unaffected.
    - **Technical:** Category 2 (soft-delete coherence) and Category 8 (backup/restore correctness). `commission_payout_items` is an append-only audit table by intent — `ON DELETE RESTRICT` on the `order_id` FK correctly prevents orphan orders, but nothing prevents application code from calling `->delete()` on the item itself. The `removeItem()` path is exercised whenever a refund arrives for an in-flight payout in `pending` or `pending_funds` state, and `revalidatePayoutOrders()` also deletes items for orders that become ineligible before the payout executes. Both paths are reachable on every refund during the grace window.
    - **Plain English:** Imagine a restaurant receipt where a dish was sent back. A well-run kitchen crosses out the line item and writes "returned" next to it — the receipt still shows what was ordered, what was comped, and why. This code erases the line entirely instead. The totals at the bottom still add up, but six months later (or during a tax audit) there's no way to prove which specific orders were included in a payout, at what amounts, and which ones were later refunded out of it. The fix is to cross the line out rather than tear the receipt up.
    - **Evidence:**
        ```php
        private function removeItem(CommissionPayout $payout, Order $order): void
        {
            $item = CommissionPayoutItem::where('payout_id', $payout->id)
                ->where('order_id', $order->id)
                ->first();

            if ($item) {
                $item->delete();
            }

            $order->forceFill(['payout_id' => null])->save();
        ```

---

## P3 — Nice to have

- [ ] **#DATA-3** · P3 — Two services write notification rows directly, bypassing NotificationPublisher's deduplication and email dispatch
    - **Where:** app/Services/Store/SelectionCleanupService.php:62, app/Services/Professional/BrandPartnerLinkNotifier.php:58
    - **Affects:** Notification inbox consistency — rows created via direct `Notification::create()` carry no `dedupe_key`, so if the surrounding job is retried or dispatched twice, duplicate notifications land in the professional's inbox. `BrandPartnerLinkNotifier` also writes a non-normalised `type` value (`'BrandPartnerRemoved'`) that the publisher would have translated to a standard type, and skips the transactional email dispatch path entirely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the direct `Notification::query()->create([...])` calls in both classes with `app(NotificationPublisher::class)->publish(...)`, supplying a stable `dedupeKey` (e.g. `"link_removed.{$affiliate->id}.{$brand->id}"` in `BrandPartnerLinkNotifier` and `"selections_cleanup.{$affiliateProfessionalId}.{$brandProfessionalId}"` in `SelectionCleanupService`).
        - Remove the explicit `type` / `severity` construction from `BrandPartnerLinkNotifier::insert()` — the publisher handles both via `frontendType`.
        - Remove the manual `'ends_at' => null` from both call sites; pass an appropriate `retentionConfigKey` instead so retention is governed by config rather than hardcoded.
    - **Technical:** Category 3 (orphan-row risk / inconsistency). The UNIQUE partial index `notifications_dedupe_key_per_pro_uq ON notifications.notifications (professional_id, dedupe_key) WHERE dedupe_key IS NOT NULL` provides idempotency only when `dedupe_key` is populated. Direct `create()` calls leave `dedupe_key = NULL`, so the unique index never fires. The `BrandPartnerLinkNotifier` is called from a lifecycle service after a transaction commits; if the outer job fails after the commit and retries, the notification fires again. `SelectionCleanupService` is somewhat protected because the delete-then-notify pattern means retries yield `$deleted = 0` and skip the notification, but the architectural inconsistency still violates the invariant that all notifications flow through the publisher.
    - **Plain English:** The notification system has a "don't send the same message twice" rule — every notification gets a unique fingerprint, and if a message with that fingerprint already exists, the system skips it. Two parts of the codebase write notifications directly without providing a fingerprint, like two employees hand-writing messages to bypass the duplicate-detection system. The fix is routing every notification through the same central sender so the duplicate check always applies.
    - **Evidence:**
        ```php
        // SelectionCleanupService.php:62
        Notification::query()->create([
            'professional_id' => $professionalId,
            'type' => 'Info',
            'title' => $title,
            'body' => str_replace('{count}', (string) $count, $body),
            'severity' => Notification::severityForFrontendType('Info'),
            'starts_at' => now(),
            'ends_at' => null,
        ]);
        ```
        ```php
        // BrandPartnerLinkNotifier.php:58
        Notification::query()->create([
            'professional_id' => $professionalId,
            'type' => 'BrandPartnerRemoved',
            'title' => $attrs['title'],
            'body' => $attrs['body'],
            'cta_url' => $attrs['cta_url'],
            'primary_action_label' => null,
            'secondary_action_label' => null,
            'secondary_action_url' => null,
            'severity' => $attrs['severity'],
            'starts_at' => $now,
            'ends_at' => null,
        ]);
        ```
