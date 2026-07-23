<?php

namespace App\Http\Requests\Concerns;

use App\Http\Requests\Api\User\Site\UpdateSiteRequest;

// Shared prepareForValidation() body for UpdateSiteRequest (user-facing) and
// StaffUpdateSiteRequest (staff-facing): subdomain casing, legacy skeleton_id
// field-name compat, LEGACY_ARCHITECTURE_IDS value collapse, and ordering
// page-id normalization. Kept in lockstep for the same TEST-5 / LIFE-4 reason
// the two classes already share DesignKitValidationRules and
// SiteOrderingValidationRules.
trait NormalizesSiteUpdateInput
{
    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->subdomain ?? null)) {
            $merge['subdomain'] = strtolower(trim($this->subdomain));
        }

        // Legacy field-name compat: old clients send skeleton_id. Merge it into
        // architecture_id (then normalize legacy VALUES the same as before).
        if (is_string($this->skeleton_id ?? null) && $this->architecture_id === null) {
            $merge['architecture_id'] = $this->skeleton_id;
        }
        if (is_string($merge['architecture_id'] ?? $this->architecture_id ?? null)) {
            $v = $merge['architecture_id'] ?? $this->architecture_id;
            $merge['architecture_id'] = UpdateSiteRequest::LEGACY_ARCHITECTURE_IDS[$v] ?? $v;
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
