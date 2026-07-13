<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use App\Http\Requests\Api\User\Site\UpdateSiteRequest;
use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\DesignKitValidationRules;
use App\Http\Requests\Concerns\SiteOrderingValidationRules;
use App\Models\Core\Site\Site;
use App\Rules\SubdomainValidationRule;
use Illuminate\Validation\Rule;

// Validates staff update of a site — architecture selection, subdomain (with
// uniqueness + reserved-word checks), publish status, settings (non-design
// only). Per-user design vars are written via the `design_kit` field which
// is processed separately by the controller (writes to site.design_kits).
class StaffUpdateSiteRequest extends BaseFormRequest
{
    use DesignKitValidationRules, SiteOrderingValidationRules;

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->subdomain ?? null)) {
            $merge['subdomain'] = strtolower(trim($this->subdomain));
        }

        // Legacy field-name compat: old clients send skeleton_id. Merge it into
        // architecture_id, then normalize legacy VALUES — same collapse
        // affordance as UpdateSiteRequest (the platform is single-architecture).
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

    public function rules(): array
    {
        $professional = $this->route('professional');
        $currentSiteId = $professional?->site?->id;

        return [
            // Architecture — always 'one' (shares UpdateSiteRequest::ALLOWED_ARCHITECTURES;
            // legacy ids collapse in prepareForValidation).
            'architecture_id' => ['sometimes', 'string', Rule::in(UpdateSiteRequest::ALLOWED_ARCHITECTURES)],

            // Per-user design kit. Defined in DesignKitValidationRules trait so
            // this class and UpdateSiteRequest share a single source of truth
            // (TEST-5 / LIFE-4). Any design_kit column change must be made in the
            // trait only.
            ...$this->designKitRules(),

            'settings' => ['sometimes', 'array'],
            // settings.design.* is dead — reject any incoming key under it.
            'settings.design' => ['prohibited'],

            // Subdomain: staff can update with same constraints as pros
            'subdomain' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:63',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                new SubdomainValidationRule($currentSiteId, $professional),
            ],

            'is_published' => ['sometimes', 'boolean'],

            // Non-design settings — allowlist specific keys with validation.
            'settings.show_branding' => ['sometimes', 'boolean'],
            'settings.charlie_enabled' => ['sometimes', 'boolean'],
            'settings.services_auto_sync_enabled' => ['sometimes', 'boolean'],
            'settings.booking_mode' => [
                'sometimes',
                'string',
                Rule::in(Site::BOOKING_MODES),
            ],
            'settings.manual_booking_url' => ['sometimes', 'nullable', 'url', 'max:2048'],

            // Ordering preferences (OV-I actions system) — shared with the user
            // endpoint via SiteOrderingValidationRules so a staff edit can't write a
            // malformed/hostile ordering payload the user endpoint would reject (esp.
            // a custom-action {label,url} whose URL isn't http(s)).
            ...$this->orderingRules(),

            // Staff-only override hatch — honoured by UpdateSiteAction when
            // options['allow_force_publish'] is true.
            'force_publish' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'subdomain.regex' => 'The subdomain must contain only lowercase letters, numbers, and hyphens, and cannot start or end with a hyphen.',
            'subdomain.unique' => 'This subdomain is already taken.',
            'subdomain.min' => 'The subdomain must be at least 3 characters.',
            'subdomain.max' => 'The subdomain cannot exceed 63 characters.',
            'settings.design.prohibited' => 'settings.design.* is no longer accepted. Use the design_kit field instead.',
            'architecture_id.in' => 'Unknown layout.',
        ];
    }
}
