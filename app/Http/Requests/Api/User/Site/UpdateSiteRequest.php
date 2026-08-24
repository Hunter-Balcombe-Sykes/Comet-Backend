<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\DesignKitValidationRules;
use App\Http\Requests\Concerns\NormalizesSiteUpdateInput;
use App\Http\Requests\Concerns\SiteOrderingValidationRules;
use App\Models\Core\Site\Site;
use App\Services\Site\SubdomainAvailabilityService;
use Illuminate\Validation\Rule;

// Validates site updates — settings (non-design only), subdomain uniqueness,
// architecture selection, and publish readiness checks. Per-user design vars are
// written via the `design_kit` field which is processed separately by the
// controller (writes to site.design_kits, not site.sites).
class UpdateSiteRequest extends BaseFormRequest
{
    use DesignKitValidationRules, NormalizesSiteUpdateInput, SiteOrderingValidationRules;

    public function rules(): array
    {
        $professional = $this->attributes->get('professional');
        $currentSiteId = $professional?->site?->id;

        return [
            // Non-design settings — design moved to site.design_kits, all
            // settings.design.* paths are rejected outright.
            'settings' => ['sometimes', 'array'],
            // architecture_id left the wire 2026-08-20 — no rule, so it is an
            // unknown key: accepted and dropped, never persisted. Deliberately
            // NOT 'prohibited', which would 422 any client still sending it.
            // settings.design.* is dead — reject any incoming key under it.
            'settings.design' => ['prohibited'],
            'settings.show_branding' => ['sometimes', 'boolean'],
            'settings.charlie_enabled' => ['sometimes', 'boolean'],
            'settings.services_auto_sync_enabled' => ['sometimes', 'boolean'],
            // Owner switch over whether the sitepage carries a Gallery page.
            // Absent = true (permissive-on-absent, like smart_page_order).
            // Stored only for now — the resolver gate lands separately.
            'settings.display_gallery_page' => ['sometimes', 'boolean'],
            'settings.booking_mode' => [
                'sometimes',
                'string',
                Rule::in(Site::BOOKING_MODES),
            ],
            'settings.manual_booking_url' => ['sometimes', 'nullable', 'url', 'max:2048'],

            // Site policies (Privacy / Terms) — automated flags + the owner's
            // own text for manual mode. Generated texts are never stored;
            // SitePolicyResolver derives them at read time.
            'settings.privacy' => ['sometimes', 'array'],
            'settings.privacy.automated_privacy' => ['sometimes', 'boolean'],
            'settings.privacy.automated_terms' => ['sometimes', 'boolean'],
            'settings.privacy.privacy_manual_text' => ['sometimes', 'nullable', 'string', 'max:30000'],
            'settings.privacy.terms_manual_text' => ['sometimes', 'nullable', 'string', 'max:30000'],

            // Ordering preferences (OV-I actions system) — shared with
            // StaffUpdateSiteRequest via SiteOrderingValidationRules so the two
            // endpoints can't drift (esp. the custom-action http(s) URL rule).
            ...$this->orderingRules(),

            // Subdomain: must be unique, not reserved, DNS-safe
            'subdomain' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:63',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                function ($attribute, $value, $fail) use ($currentSiteId, $professional) {
                    // Single source of truth shared with the live availability
                    // endpoint (SubdomainAvailabilityService) — the check while
                    // typing and the check on save can never disagree.
                    $check = app(SubdomainAvailabilityService::class)->check(
                        (string) $value,
                        $currentSiteId,
                        $professional?->id,
                    );

                    if ($check['available']) {
                        return;
                    }

                    match ($check['reason']) {
                        // Length/format already fail via the min/max/regex rules above.
                        SubdomainAvailabilityService::REASON_INVALID => null,
                        SubdomainAvailabilityService::REASON_RESERVED => $fail('The subdomain "'.$value.'" is reserved and cannot be used.'),
                        // held / taken / own-alias variants all keep the original
                        // user-facing message. (own_current can't occur here — no
                        // currentSubdomain is passed on the write path.)
                        default => $fail('This subdomain is already taken.'),
                    };
                },
            ],

            // Architecture selection (owner, 2026-08-24 — reopens the
            // 2026-08-20 lockdown): which code-side layout partna-pages
            // renders. Top-level column, not a settings/design_kit key.
            'architecture_id' => ['sometimes', 'string', Rule::in(Site::ARCHITECTURE_IDS)],

            // Per-user design kit. Defined in DesignKitValidationRules trait so
            // this class and StaffUpdateSiteRequest share a single source of truth
            // (TEST-5 / LIFE-4). Any design_kit column change must be made in the
            // trait only.
            ...$this->designKitRules(),

            // Publish
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('is_published') === true) {
                $professional = $this->attributes->get('professional');
                $site = $professional?->site;

                if (! $site) {
                    $validator->errors()->add('is_published', 'Site not found.');

                    return;
                }

                if (empty($professional->display_name)) {
                    $validator->errors()->add('is_published', 'Cannot publish: professional must have a display name.');
                }

            }
        });
    }

    public function messages(): array
    {
        return [
            'subdomain.regex' => 'The subdomain must contain only lowercase letters, numbers, and hyphens, and cannot start or end with a hyphen.',
            'subdomain.unique' => 'This subdomain is already taken.',
            'subdomain.min' => 'The subdomain must be at least 3 characters.',
            'subdomain.max' => 'The subdomain cannot exceed 63 characters.',
            'settings.design.prohibited' => 'settings.design.* is no longer accepted. Use the design_kit field instead.',
        ];
    }
}
