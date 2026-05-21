<?php

namespace App\Services\Shopify;

use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use Illuminate\Support\Arr;

// Auto-fills professional, brand profile, and integration fields from Shopify shop data
// during OAuth onboarding, and handles on-demand resyncs.
//
// Sync semantics — NON-DESTRUCTIVE for non-design fields, source-of-truth for design.
//   Non-design fields (name, email, phone, address, locale, plan, money_format,
//   public_contact_email, etc.): FILL EMPTY ONLY. The brand's manually-typed
//   value always wins; Shopify can only fill in blanks. Applies to both first-
//   sync at signup (fillFromShopData) and manual resync (resyncFromShopData).
//   Design fields (logo, colours, slogan, short_description, cover image):
//   Shopify is source of truth. Handled by SyncShopifyBrandDesignJob, not this
//   service — that job overwrites freely on every run.
//   Per-field opt-out is still respected via provider_metadata.shopify_sync_locked_fields.
//
// See PARTNA-SIGNUP-OVERHAUL-PLAN.md §6.1a for the full rule.
class ShopProfileAutoFillService
{
    /**
     * Shopify → Partna field mappings driving fillFromShopData and resyncFromShopData.
     *
     * Each entry:
     *   - 'shopify' = key on the shop.json payload (or 'customer_email' which is
     *                  REST shop.json key 'customer_email')
     *   - 'target'  = one of 'professional', 'brand_profile', 'integration'
     *   - 'column'  = column (or metadata key, for integration target) on the target.
     *                  Also used as the logical field name in the resync response.
     *
     * shop_owner is NOT in this map — it's a single Shopify string that splits
     * into Professional.first_name + Professional.last_name. Handled as a
     * special case in fillProfessional and resyncFromShopData.
     */
    private const FIELD_MAP = [
        ['shopify' => 'name', 'target' => 'professional', 'column' => 'display_name'],
        ['shopify' => 'email', 'target' => 'professional', 'column' => 'primary_email'],
        ['shopify' => 'customer_email', 'target' => 'professional', 'column' => 'public_contact_email'],
        ['shopify' => 'phone', 'target' => 'professional', 'column' => 'phone'],
        ['shopify' => 'address1', 'target' => 'professional', 'column' => 'location_street_address'],
        ['shopify' => 'city', 'target' => 'professional', 'column' => 'location_city'],
        ['shopify' => 'province', 'target' => 'professional', 'column' => 'location_state'],
        ['shopify' => 'zip', 'target' => 'professional', 'column' => 'location_postcode'],
        ['shopify' => 'country_name', 'target' => 'professional', 'column' => 'location_country'],
        ['shopify' => 'country_code', 'target' => 'professional', 'column' => 'country_code'],
        ['shopify' => 'iana_timezone', 'target' => 'professional', 'column' => 'timezone'],
        ['shopify' => 'domain', 'target' => 'brand_profile', 'column' => 'business_website'],
        ['shopify' => 'primary_locale', 'target' => 'brand_profile', 'column' => 'locale'],
        ['shopify' => 'plan_display_name', 'target' => 'brand_profile', 'column' => 'shopify_plan'],
        ['shopify' => 'money_format', 'target' => 'brand_profile', 'column' => 'money_format'],
        ['shopify' => 'currency', 'target' => 'integration', 'column' => 'shop_currency'],
    ];

    /**
     * Fill Professional, Site, and BrandProfile fields from a Shopify shop object (signup path).
     * Only fills fields that are currently empty — never overwrites existing values.
     * Design fields (logo, colours, slogan, short_description) are NOT covered here;
     * they sync through SyncShopifyBrandDesignJob with overwrite semantics.
     *
     * @param  array  $shopData  The `shop` object from Shopify's Admin API shop.json response
     */
    public function fillFromShopData(
        Professional $professional,
        Site $site,
        ?BrandProfile $brandProfile,
        array $shopData,
        ?ProfessionalIntegration $integration = null,
    ): void {
        $this->fillProfessional($professional, $shopData);
        $this->fillBrandProfile($brandProfile, $shopData);
        $this->fillIntegrationCurrency($integration, $shopData);

        $professional->save();

        if ($brandProfile !== null) {
            $brandProfile->save();
        }
    }

    /**
     * True when a stored value should be treated as "empty" for fill-empty-only purposes.
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Split Shopify's shop_owner string ("Sarah Jones") into first/last on the
     * first whitespace. Returns ['', ''] when no owner string is present.
     *
     * @return array{0: string, 1: string} [firstName, lastName]
     */
    private function splitShopOwner(array $shopData): array
    {
        $owner = $this->str($shopData, 'shop_owner');
        if ($owner === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $owner, 2);
        if (! is_array($parts)) {
            return ['', ''];
        }

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /**
     * Resync Shopify-sourced fields. For each field in FIELD_MAP:
     *   - If the field is in shopify_sync_locked_fields → preserve local (brand opted out).
     *   - If the local value is already non-empty → preserve local (fill-empty-only).
     *   - If Shopify returns empty/missing → preserve local.
     *   - Else → write Shopify value.
     *
     * Brands can additionally protect specific fields from Shopify writes by listing their
     * column names in provider_metadata.shopify_sync_locked_fields. Default: [] (all
     * fields fill-empty-only). Design fields (logo, colours, slogan, short_description)
     * sync through SyncShopifyBrandDesignJob with overwrite-on-sync semantics — they
     * bypass this service entirely.
     *
     * @return array{updated: string[], preserved: string[]}
     */
    public function resyncFromShopData(ProfessionalIntegration $integration, array $shopData): array
    {
        $professional = Professional::findOrFail($integration->professional_id);
        $brandProfile = BrandProfile::where('professional_id', $integration->professional_id)->first();

        $lockedFields = $this->getLockedFields($integration);

        $updated = [];
        $preserved = [];
        $professionalDirty = false;
        $brandProfileDirty = false;

        // Integration-target field writes (currently just shop_currency) go through
        // mergeProviderMetadata so sibling keys (webhook ids, storefront tokens, etc.)
        // written by concurrent jobs survive this update.
        $metadataMerge = [];

        foreach (self::FIELD_MAP as $field) {
            if (in_array($field['column'], $lockedFields, true)) {
                $preserved[] = $field['column'];

                continue;
            }

            $freshValue = $this->freshValueForField($field, $shopData);

            if ($freshValue === '') {
                $preserved[] = $field['column'];

                continue;
            }

            // Fill-empty-only: never overwrite a non-empty local value. Brand's
            // typed input is the source of truth for non-design fields.
            $existing = $this->existingValueForField($field, $professional, $brandProfile, $integration);
            if (! $this->isEmpty($existing)) {
                $preserved[] = $field['column'];

                continue;
            }

            $this->applyFreshValue($field, $freshValue, $professional, $brandProfile, $metadataMerge, $professionalDirty, $brandProfileDirty);
            $updated[] = $field['column'];
        }

        // shop_owner — single Shopify string that splits to two Professional
        // columns (first_name, last_name). Same fill-empty-only rule, with the
        // lock list checked against each target column independently.
        [$ownerFirst, $ownerLast] = $this->splitShopOwner($shopData);
        if ($ownerFirst !== '' && ! in_array('first_name', $lockedFields, true) && $this->isEmpty($professional->first_name)) {
            $professional->first_name = $ownerFirst;
            $professionalDirty = true;
            $updated[] = 'first_name';
        } elseif ($ownerFirst !== '') {
            $preserved[] = 'first_name';
        }
        if ($ownerLast !== '' && ! in_array('last_name', $lockedFields, true) && $this->isEmpty($professional->last_name)) {
            $professional->last_name = $ownerLast;
            $professionalDirty = true;
            $updated[] = 'last_name';
        } elseif ($ownerLast !== '') {
            $preserved[] = 'last_name';
        }

        if ($professionalDirty) {
            $professional->save();
        }
        if ($brandProfileDirty && $brandProfile !== null) {
            $brandProfile->save();
        }

        if ($metadataMerge !== []) {
            $integration->mergeProviderMetadata($metadataMerge);
        }

        return [
            'updated' => $updated,
            'preserved' => $preserved,
        ];
    }

    private function fillProfessional(Professional $professional, array $shopData): void
    {
        // Fill-empty-only. Shopify provides defaults for blanks, never overwrites
        // values the brand already entered. Match fillBrandProfile + fillIntegrationCurrency,
        // which were already correct.
        if ($this->isEmpty($professional->display_name)) {
            $professional->display_name = $this->str($shopData, 'name') ?: $professional->display_name;
        }
        if ($this->isEmpty($professional->primary_email)) {
            $professional->primary_email = $this->str($shopData, 'email') ?: $professional->primary_email;
        }
        if ($this->isEmpty($professional->public_contact_email)) {
            $professional->public_contact_email = $this->str($shopData, 'customer_email') ?: $professional->public_contact_email;
        }
        if ($this->isEmpty($professional->phone)) {
            $professional->phone = $this->str($shopData, 'phone') ?: $professional->phone;
        }
        if ($this->isEmpty($professional->location_street_address)) {
            $professional->location_street_address = $this->str($shopData, 'address1') ?: $professional->location_street_address;
        }
        if ($this->isEmpty($professional->location_city)) {
            $professional->location_city = $this->str($shopData, 'city') ?: $professional->location_city;
        }
        if ($this->isEmpty($professional->location_state)) {
            $professional->location_state = $this->str($shopData, 'province') ?: $professional->location_state;
        }
        if ($this->isEmpty($professional->location_postcode)) {
            $professional->location_postcode = $this->str($shopData, 'zip') ?: $professional->location_postcode;
        }
        if ($this->isEmpty($professional->location_country)) {
            $professional->location_country = $this->str($shopData, 'country_name') ?: $professional->location_country;
        }
        if ($this->isEmpty($professional->country_code)) {
            $professional->country_code = $this->str($shopData, 'country_code') ?: $professional->country_code;
        }
        if ($this->isEmpty($professional->timezone)) {
            $professional->timezone = $this->str($shopData, 'iana_timezone') ?: $professional->timezone;
        }

        // shop_owner → first_name + last_name (split on first whitespace).
        // Fill-empty-only per column.
        [$ownerFirst, $ownerLast] = $this->splitShopOwner($shopData);
        if ($ownerFirst !== '' && $this->isEmpty($professional->first_name)) {
            $professional->first_name = $ownerFirst;
        }
        if ($ownerLast !== '' && $this->isEmpty($professional->last_name)) {
            $professional->last_name = $ownerLast;
        }
    }

    private function fillBrandProfile(?BrandProfile $brandProfile, array $shopData): void
    {
        if ($brandProfile === null) {
            return;
        }

        // Fill-empty-only for all non-design BrandProfile fields. slogan and
        // short_description are NOT here — they're design-tier and overwrite
        // freely via SyncShopifyBrandDesignJob.
        $domain = $this->str($shopData, 'domain');
        if ($domain !== '' && $this->isEmpty($brandProfile->business_website)) {
            $brandProfile->business_website = $domain;
        }

        $locale = $this->str($shopData, 'primary_locale');
        if ($locale !== '' && $this->isEmpty($brandProfile->locale)) {
            $brandProfile->locale = $locale;
        }

        $plan = $this->str($shopData, 'plan_display_name');
        if ($plan !== '' && $this->isEmpty($brandProfile->shopify_plan)) {
            $brandProfile->shopify_plan = $plan;
        }

        $moneyFormat = $this->str($shopData, 'money_format');
        if ($moneyFormat !== '' && $this->isEmpty($brandProfile->money_format)) {
            $brandProfile->money_format = $moneyFormat;
        }
    }

    /**
     * Read the current value of a FIELD_MAP-described field from the right target.
     * Used by resync to decide whether to skip (non-empty existing) or write (empty).
     */
    private function existingValueForField(
        array $field,
        Professional $professional,
        ?BrandProfile $brandProfile,
        ProfessionalIntegration $integration,
    ): mixed {
        if ($field['target'] === 'professional') {
            return $professional->{$field['column']} ?? null;
        }

        if ($field['target'] === 'brand_profile') {
            return $brandProfile?->{$field['column']} ?? null;
        }

        if ($field['target'] === 'integration') {
            $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

            return $metadata[$field['column']] ?? null;
        }

        return null;
    }

    private function fillIntegrationCurrency(?ProfessionalIntegration $integration, array $shopData): void
    {
        if ($integration === null) {
            return;
        }

        $currency = strtoupper($this->str($shopData, 'currency'));
        if ($currency === '') {
            return;
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];

        // Only set if not already recorded — same idempotency rule, but use atomic merge so we
        // don't clobber sibling keys written by concurrent onboarding jobs.
        if (($metadata['shop_currency'] ?? '') === '') {
            $integration->mergeProviderMetadata(['shop_currency' => $currency]);
        }
    }

    /**
     * Normalize a Shopify shop field to its stored form.
     * Country code and currency are upper-cased; everything else is trimmed as-is.
     */
    private function freshValueForField(array $field, array $shopData): string
    {
        $raw = $this->str($shopData, $field['shopify']);

        if (in_array($field['shopify'], ['currency', 'country_code'], true)) {
            return strtoupper($raw);
        }

        return $raw;
    }

    /**
     * Apply a non-empty fresh Shopify value to the correct target + mark the dirty flag
     * for batched save. For integration-target fields (currently just shop_currency),
     * the value is added to $metadataMerge which the caller passes to mergeProviderMetadata().
     */
    private function applyFreshValue(
        array $field,
        string $freshValue,
        Professional $professional,
        ?BrandProfile $brandProfile,
        array &$metadataMerge,
        bool &$professionalDirty,
        bool &$brandProfileDirty,
    ): void {
        if ($field['target'] === 'professional') {
            $professional->{$field['column']} = $freshValue;
            $professionalDirty = true;

            return;
        }

        if ($field['target'] === 'brand_profile') {
            if ($brandProfile === null) {
                return;
            }
            $brandProfile->{$field['column']} = $freshValue;
            $brandProfileDirty = true;

            return;
        }

        $metadataMerge[$field['column']] = $freshValue;
    }

    /**
     * Fields listed in provider_metadata.shopify_sync_locked_fields are skipped during
     * resync — the brand has opted to manage them locally rather than let Shopify overwrite.
     * Returns an empty array by default (all fields sync).
     *
     * @return string[]
     */
    private function getLockedFields(ProfessionalIntegration $integration): array
    {
        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $locked = $metadata['shopify_sync_locked_fields'] ?? [];

        return is_array($locked) ? $locked : [];
    }

    private function str(array $data, string $key): string
    {
        $value = Arr::get($data, $key);

        return is_string($value) ? trim($value) : '';
    }
}
