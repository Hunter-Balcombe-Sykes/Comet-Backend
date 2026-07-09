<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use App\Http\Requests\Api\User\Site\UpdateSiteRequest;
use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\DesignKitValidationRules;
use App\Models\Core\Site\Site;
use App\Rules\SubdomainValidationRule;
use Illuminate\Validation\Rule;

// Validates staff update of a site — skeleton selection, subdomain (with
// uniqueness + reserved-word checks), publish status, settings (non-design
// only). Per-user design vars are written via the `design_kit` field which
// is processed separately by the controller (writes to site.design_kits).
class StaffUpdateSiteRequest extends BaseFormRequest
{
    use DesignKitValidationRules;

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->subdomain ?? null)) {
            $merge['subdomain'] = strtolower(trim($this->subdomain));
        }

        // Legacy skeleton-N ids normalize to the canonical named ids — same
        // rollout affordance as UpdateSiteRequest.
        if (is_string($this->skeleton_id ?? null) && isset(UpdateSiteRequest::LEGACY_SKELETON_IDS[$this->skeleton_id])) {
            $merge['skeleton_id'] = UpdateSiteRequest::LEGACY_SKELETON_IDS[$this->skeleton_id];
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
            // Skeleton — one of bento/dock/flick/deck/atlas/one (shares
            // UpdateSiteRequest::ALLOWED_SKELETONS). Replaces theme_id.
            'skeleton_id' => ['sometimes', 'string', Rule::in(UpdateSiteRequest::ALLOWED_SKELETONS)],

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
            'skeleton_id.in' => 'Skeleton must be one of: bento, dock, flick, deck, atlas, one.',
        ];
    }
}
