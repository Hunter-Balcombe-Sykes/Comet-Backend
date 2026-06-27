I now have everything I need. Let me produce the final adjudicated audit.

`★ Insight ─────────────────────────────────────`
`AccountDeletionService::pseudonymiseAccountPii()` pseudonymizes the `professionals` table immediately on erasure confirmation but the cascade to `brand_profiles` only fires 30 days later on `forceDelete()`. This creates a split-brain state where email+phone are scrubbed but ABN+legal name are still raw — a pattern easy to miss because both tables look "covered" at a glance.
`─────────────────────────────────────────────────`

# Data Integrity & Privacy Audit — 2026-05-15

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
- app/Http/Controllers/Api/Internal/EmbeddedConnectController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
- app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Requests/Api/Internal/Embedded/*.php
- app/Services/Shopify/ShopifyShopResolver.php
- app/Services/Professional/AccountDeletionService.php *(verified via `Read`)*
- app/Jobs/Shopify/Gdpr/RedactCustomerJob.php *(verified via `Read`)*
- app/Jobs/Shopify/Gdpr/RedactShopJob.php *(verified via `Read`)*
- supabase/migrations/20260403000000_v2_baseline.sql *(verified via `Grep`)*
- supabase/migrations/20260505000000_redesign_brand_status_stages.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#DATA-1** · P1 — `brand_profiles.abn` and `brand_profiles.legal_business_name` not pseudonymized during 30-day deletion grace period
    - **Where:** app/Services/Professional/AccountDeletionService.php:214–227 (`pseudonymiseAccountPii`); written via app/Http/Controllers/Api/Internal/EmbeddedSetupController.php:165–172
    - **Affects:** Brand professionals who have completed Step 2 of the embedded wizard and subsequently request account erasure — their Australian Business Number and legal trading name remain in raw, queryable form for the full 30-day grace period, while all other PII (email, phone, first name) is immediately pseudonymized on deletion confirmation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend `AccountDeletionService::pseudonymiseAccountPii()` to immediately null/pseudonymize `brand_profiles.legal_business_name` and `brand_profiles.abn` on the same DB transaction as the `professionals` table scrub.
        - Also audit and null `professionals.public_contact_email`, `professionals.public_contact_number`, and `professionals.about` — these columns are not in the current `forceFill` list but may contain PII depending on what brands enter.
        - Add a comment to `pseudonymiseAccountPii()` referencing the `brand_profiles` scrub so future new PII columns are caught by the same checklist at code-review time.
    - **Technical:** `AccountDeletionService::pseudonymiseAccountPii()` (line 214–227) performs an immediate one-way scrub of the `core.professionals` row — email, phone, first/last name, and location columns. It does **not** touch `brand.brand_profiles`. The `brand_profiles` row is only removed 30 days later when `purge()` calls `$professional->forceDelete()`, which cascades via `ON DELETE CASCADE`. During the grace window, `brand_profiles.abn` (Australian Business Number, which maps 1:1 to a sole-trader individual) and `brand_profiles.legal_business_name` remain raw and queryable. The Shopify GDPR webhooks (`RedactCustomerJob`, `RedactShopJob`) are a separate code path scoped to end-consumer data (Shopify's customers, not Partna professionals) and are not relevant to this gap. The fix is a one-line `BrandProfile::where('professional_id', $professional->id)->update([...])` inside the existing `pseudonymiseAccountPii()` call, co-located with the `professionals` scrub.
    - **Plain English:** When a brand owner clicks "delete my account," the system immediately scrambles their email address and phone number so they can't be identified — good. But their ABN (a government tax number that uniquely identifies a person if they're a sole trader) and their legal business name sit in the database as-is for 30 more days before being erased. Privacy law generally requires all personal information to be erased at once, not just the most obvious fields first. It's like shredding someone's business card but leaving their tax return on the desk — the intent is clear but the execution is incomplete.
    - **Evidence:**
        ```php
        // AccountDeletionService::pseudonymiseAccountPii() — scrubs professionals but NOT brand_profiles
        private function pseudonymiseAccountPii(Professional $professional): void
        {
            $professional->forceFill([
                'phone' => 'redacted',
                'primary_email' => "deleted+{$professional->id}@partna.au",
                'first_name' => 'Deleted',
                'last_name' => null,
                'location_street_address' => null,
                'location_postcode' => null,
                'location_city' => null,
                'location_state' => null,
                'location_country' => null,
                // brand_profiles.legal_business_name — not touched; raw for 30 days
                // brand_profiles.abn               — not touched; raw for 30 days
            ])->save();
        }
        ```
        ```php
        // EmbeddedSetupController::saveBusinessDetails — writes ABN + legal name to brand_profiles
        BrandProfile::updateOrCreate(
            ['professional_id' => $professionalId],
            [
                'legal_business_name' => $data['legal_business_name'],
                'abn' => $data['abn'],
                'business_type' => $data['business_type'],
                'industries' => $data['industries'],
            ],
        );
        ```

---

## P2 — Should fix

- [ ] **#DATA-2** · P2 — `provider_metadata` JSONB key proliferation across four controllers with no canonical definition — evidence of past key drift already present
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php:391–400; app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php:53–69; app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php (reads `favourites_collection_handle`, `default_collection_handle`, `custom_photos_enabled`)
    - **Affects:** All brand Shopify integrations; operationally visible when two controllers read/write different keys for the same concept — the current dual-write of `webhook_registration_state` AND `webhooks_state` in the uninstall handler is a live example of the risk materialising.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Define a canonical `ProfessionalIntegration::PROVIDER_METADATA_KEYS` constant (or a dedicated `ShopifyIntegrationMetadata` DTO/value-object) enumerating every allowed key: `shop_domain`, `shop_id`, `scopes`, `connected_at`, `webhook_registration_state`, `connected_via`, `disconnected_at`, `disconnected_reason`, `active_collection_handle`, `default_collection_handle`, `favourites_collection_handle`, `high_commission_collection_handle`, `uninstalled_from_status`, `uninstalled_wizard_state`, `custom_photos_enabled`, `webhooks_state`.
        - Remove the redundant `webhooks_state` key from `ShopifyAppUninstalledWebhookController` (it was added to paper over a past mismatch with `webhook_registration_state`); use only `webhook_registration_state` going forward, or consciously rename and update all readers atomically.
        - Promote `webhook_registration_state` and `disconnected_at` to real nullable columns — both are used for control-flow decisions (`if ($existingWebhookState === 'queued')`, `BrandStatusService::determine()` reads `disconnected_at` first) and would benefit from a typed column and an index rather than a JSONB key-path lookup.
    - **Technical:** `ProfessionalIntegration.provider_metadata` is a schemaless JSONB blob that currently holds at least 16 distinct keys written by four separate controllers with no shared definition. The uninstall webhook writes both `webhook_registration_state = 'uninstalled'` AND `webhooks_state = 'uninstalled'` simultaneously — the comment on that line is `// note: different key`, which is the smoking gun. If a future controller checks only `webhook_registration_state` and another writes only `webhooks_state`, the system silently diverges with no exception. `BrandStatusService::determine()` reads `disconnected_at` to gate the entire brand lifecycle; a key typo in one write path would leave brands stuck in `Disconnected` with no error. The fix does not require a migration immediately — a PHP constant and documentation cost is ~1h; the column promotions for `webhook_registration_state` and `disconnected_at` are a follow-up migration.
    - **Plain English:** Four different parts of the codebase write notes into the same unlabelled filing cabinet drawer — and they're not using the same vocabulary. One writes "webhook\_registration\_state" and another wrote "webhooks\_state" as a workaround when things got out of sync. If someone in the future looks for "webhook\_registration\_state" but a bug caused only "webhooks\_state" to be written, the system silently does the wrong thing (a brand gets stuck as Disconnected) with no error message. The fix is to create a single master vocabulary list for what's allowed in the drawer, enforce it in code, and move the two most important notes out into their own properly labelled slots.
    - **Evidence:**
        ```php
        // EmbeddedSetupController::provisionShopifyIntegration — writes 6+ keys including webhook_registration_state
        $metadata = array_merge($existingMetadata, [
            'shop_domain' => $shopDomain,
            'shop_id' => $data['shop_id'] ?? Arr::get($existingMetadata, 'shop_id'),
            'scopes' => $scopesArray ?: Arr::get($existingMetadata, 'scopes', []),
            'connected_at' => now()->toIso8601String(),
            'webhook_registration_state' => 'queued',
            'connected_via' => 'embedded_wizard',
        ]);
        ```
        ```php
        // ShopifyAppUninstalledWebhookController — writes BOTH webhook_registration_state AND webhooks_state
        $metadata['disconnected_at'] = now()->toIso8601String();
        $metadata['disconnected_reason'] = 'app_uninstalled';
        $metadata['webhook_registration_state'] = 'uninstalled';
        $metadata['webhooks_state'] = 'uninstalled';  // note: different key
        ```
        ```php
        // EmbeddedProductSettingsController::show() — reads yet more keys from the same blob
        $inFavourites = $this->isInCollection($metadata, 'favourites_collection_handle', $productGid, $integration);
        $inDefault = $this->isInCollection($metadata, 'default_collection_handle', $productGid, $integration);
        $globalCustomPhotosEnabled = (bool) Arr::get($metadata, 'custom_photos_enabled', false);
        ```

`★ Insight ─────────────────────────────────────`
The dual `webhook_registration_state` / `webhooks_state` write in the uninstall webhook is a textbook example of a "defensive duplicate" anti-pattern: instead of fixing the original inconsistency, a second key was added alongside the first. Both keys must now be maintained forever or one becomes a time-bomb. Promoting control-flow keys like `disconnected_at` to real DB columns eliminates this class of silent-drift bug entirely — the schema becomes the contract.
`─────────────────────────────────────────────────`
