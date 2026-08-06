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

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
