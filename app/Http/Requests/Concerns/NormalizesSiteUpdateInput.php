<?php

namespace App\Http\Requests\Concerns;

use App\Http\Requests\Api\User\Site\UpdateSiteRequest;

// Shared prepareForValidation() body for UpdateSiteRequest (user-facing) and
// StaffUpdateSiteRequest (staff-facing): subdomain casing and ordering
// page-id normalization. Kept in lockstep for the same TEST-5 / LIFE-4 reason
// the two classes already share DesignKitValidationRules and
// SiteOrderingValidationRules. (The skeleton_id field alias and the
// LEGACY_ARCHITECTURE_IDS value collapse were removed 2026-08-05 — the audit
// found no client anywhere still sending either; architecture_id is 'staple'
// or absent everywhere.)
trait NormalizesSiteUpdateInput
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->subdomain ?? null)) {
            $merge['subdomain'] = strtolower(trim($this->subdomain));
        }

        // Normalize legacy page-ids ('book' → 'services') in the ordering block
        // so a stale client's manual_page_order / page action refs still validate.
        if (is_array($this->settings ?? null)) {
            $merge['settings'] = $this->normalizeOrderingPageIds($this->settings);
        }

        // SEM-9: the 'boolean' validation rule accepts 1/'1'/0/'0' (and, once
        // normalized here, 'true'/'false'/'on'/'off'/'yes'/'no') without
        // casting them — validated() would still hand back the raw scalar.
        // Both the publish guard below and UpdateSiteAction's own guard
        // compare with `=== true`, so a truthy non-bool like 1 or "1" used to
        // sail through both checks and publish with no display name. Coerce
        // to a real bool here, before validation runs, so every downstream
        // reader (this class's withValidator(), UpdateSiteAction, and
        // StaffUpdateSiteRequest which shares this trait but has no
        // withValidator() guard of its own) sees a native bool. Only touches
        // a *present* scalar key — 'sometimes' means an omitted key must stay
        // omitted, not silently become false.
        if (array_key_exists('is_published', $this->all())) {
            $rawPublished = $this->input('is_published');
            if (is_scalar($rawPublished) && ! is_bool($rawPublished)) {
                $normalizedPublished = filter_var($rawPublished, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalizedPublished !== null) {
                    $merge['is_published'] = $normalizedPublished;
                }
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
