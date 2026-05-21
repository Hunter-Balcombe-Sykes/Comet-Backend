`★ Insight ─────────────────────────────────────`
Key adjudication decisions:
- **INV-1 dropped**: The `destroy` route sits inside a `brand.only` middleware group; the relationship-scope ownership check (`brandAffiliateInvites()->whereKey(...)`) is not an inline 403 bypass — it's a query-scope pattern. Always-drop rule #7 applies.
- **BOOT-2 dropped**: Confidence 0.5 < 0.7 threshold and no confirmed double-write; `applyDefaults` and `applyAffiliateDefaults` are distinct call sites.
- **BrandAffiliateInvite** confirmed to have **no SoftDeletes trait**, making INV-2's hard-delete concern valid.
`─────────────────────────────────────────────────`

# Single-Brand Cap / Invite Pipeline / Signup Bootstrap Audit — 2026-05-18

**Branch:** development
**Lens:** single-brand cap enforcement, invite publisher pipeline correctness, and signup bootstrap resilience
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php
- app/Http/Controllers/Api/PublicSite/BootstrapController.php
- app/Services/Professional/Brand/BrandAffiliateInviteService.php
- app/Services/Professional/Brand/BrandPartnerLinkService.php
- app/Services/Notifications/NotificationPublisher.php
- app/Models/Core/Professional/BrandPartnerLink.php
- routes/api/professional.php
- tests/Feature/Brand/OpenInviteTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 2 of 2 complete

---

## P3 — Nice to have

- [x] **#INV-1** · P3 — Hard delete on invite `destroy` permanently erases accepted-invite audit record
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php:338
    - **Affects:** Support and audit trails — deleting an accepted invite leaves no record of how the affiliate was connected to the brand.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a status guard in `destroy`: reject deletion of `accepted` invites with a 422 and a clear message (`"Accepted invites cannot be deleted — they are part of the connection audit trail."`).
        - Pending, expired, and declined invites may still be deleted freely.
    - **Technical:** `BrandAffiliateInvite` does not use the `SoftDeletes` trait (confirmed by inspection of the model file). `$invite->delete()` is therefore a hard delete — no recovery path exists. In the single-brand-cap model, an accepted invite is the canonical audit record of how an affiliate was first connected to a brand; it also carries `claimed_professional_id` and `accepted_at`. Allowing a brand to delete it after the fact makes support triage (and any future dispute resolution) harder. The fix is a one-line status guard before the delete call; no migration needed.
    - **Plain English:** When a brand sends an invite and an affiliate accepts it, that invite record is the paper trail showing how the partnership started — it records who accepted, and when. Right now a brand can delete that record at any time, shredding the paper trail permanently. Adding a rule that says "you can only delete invites that were never accepted" keeps the history intact while still letting brands clean up pending or expired invites they no longer need.
    - **Evidence:**
        ```php
        $invite = $professional->brandAffiliateInvites()
            ->whereKey($inviteId)
            ->first();

        if (! $invite) {
            return $this->error('Invite not found.', 404);
        }

        $invite->delete();
        ```

- [x] **#BOOT-1** _(resolved by existing schema — see note below)_ · P3 — Shopify setup token consumed outside the DB transaction, leaving a narrow race window for duplicate integrations
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php:241–247
    - **Affects:** Brand signups using a Shopify setup token — two simultaneous bootstrap requests with the same token could each pass the `peek` check and each create a `ProfessionalIntegration` row before either consumes the token.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `UNIQUE` constraint on `(professional_id, provider)` in `professional_integrations` (if not already present) to make the second concurrent insert fail at the DB level rather than silently succeeding.
        - Alternatively, wrap the `peek + create` + `consume` sequence in a Redis lock keyed on the token value, serialising concurrent calls.
        - Do **not** move `consume` inside the transaction — the existing comment correctly explains that consuming inside the transaction risks token loss on rollback.
    - **Technical:** The current design is intentional: `peek` inside the transaction validates the token without consuming it, ensuring rollback doesn't destroy the token. The gap is that two concurrent requests can both succeed the `peek` before either calls `consume`, resulting in two `ProfessionalIntegration::create(...)` calls for the same Shopify shop. A DB unique constraint on `(professional_id, provider)` (or `(external_account_id, provider)`) would ensure only the first insert succeeds and the second raises an exception caught by the outer `catch (\Exception $e)` block. This is a defence-in-depth fix — the scenario requires a genuine race during Shopify OAuth signup, which is uncommon in practice.
    - **Plain English:** During signup, the app checks a one-time Shopify setup code before creating the store connection, then destroys the code afterward. There's a tiny window where two browser tabs or network retries could both check the code at the same time, both think it's valid, and both try to create the connection — ending up with duplicate records. Adding a database rule that says "only one Shopify connection per account" would automatically block the second one, making the system safe regardless of timing.
    - **Evidence:**
        ```php
        // Peek first — consume only after transaction succeeds (prevents token loss on rollback)
        $shopifyData = app(ShopifySetupTokenService::class)->peek($shopifySetupToken);
        if ($shopifyData === null) {
            throw new RuntimeException('Shopify setup session is invalid or expired. Please reinstall the app from Shopify.');
        }
        // ... integration created inside transaction ...

        // Consume Shopify setup token AFTER transaction succeeds (prevents token loss on rollback)
        if (is_string($result['shopify_integration_id'] ?? null)) {
            $shopifySetupToken = trim((string) ($data['shopify_setup_token'] ?? ''));
            if ($shopifySetupToken !== '') {
                app(ShopifySetupTokenService::class)->consume($shopifySetupToken);
            }
        ```
    - **Resolution (2026-05-18):** No code change required. The recommended `UNIQUE (professional_id, provider)` constraint already exists in the v2 baseline (`supabase/migrations/20260403000000_v2_baseline.sql:366` — `professional_integrations_professional_provider_uq`). A partial unique on `shopify_shop_domain` exists alongside it (line 372). A concurrent race would fail at insert time and be caught by the outer `catch (\Exception $e)` block, rolling back the transaction. Defence-in-depth is already in place.
