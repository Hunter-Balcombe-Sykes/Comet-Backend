<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\DesignKitValidationRules;
use App\Models\Core\Site\Site;
use App\Services\Site\SubdomainAvailabilityService;
use Illuminate\Validation\Rule;

// Validates site updates — settings (non-design only), subdomain uniqueness,
// skeleton selection, and publish readiness checks. Per-user design vars are
// written via the `design_kit` field which is processed separately by the
// controller (writes to site.design_kits, not site.sites).
class UpdateSiteRequest extends BaseFormRequest
{
    use DesignKitValidationRules;

    /** Allowed skeleton IDs — mirrors the DB CHECK constraint. */
    public const ALLOWED_SKELETONS = [
        'skeleton-1', 'skeleton-2', 'skeleton-3', 'skeleton-4',
    ];

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->subdomain ?? null)) {
            $merge['subdomain'] = strtolower(trim($this->subdomain));
        }

        $settings = $this->input('settings');
        if (is_array($settings)) {
            foreach (['hero_title', 'hero_subtitle', 'primary_button_text', 'bio_text'] as $field) {
                if (! array_key_exists($field, $settings) || ! is_string($settings[$field])) {
                    continue;
                }
                $settings[$field] = static::cleanString($settings[$field]);
            }
            $merge['settings'] = $settings;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $professional = $this->attributes->get('professional');
        $currentSiteId = $professional?->site?->id;

        return [
            // Non-design settings — design moved to site.design_kits, all
            // settings.design.* paths are rejected outright.
            'settings' => ['sometimes', 'array'],
            'settings.hero_title' => ['sometimes', 'string', 'max:100'],
            'settings.hero_subtitle' => ['sometimes', 'string', 'max:200'],
            'settings.primary_button_text' => ['sometimes', 'string', 'max:50'],
            'settings.primary_button_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.bio_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            // settings.design.* is dead — reject any incoming key under it.
            'settings.design' => ['prohibited'],
            'settings.show_branding' => ['sometimes', 'boolean'],
            'settings.charlie_enabled' => ['sometimes', 'boolean'],
            'settings.services_auto_sync_enabled' => ['sometimes', 'boolean'],
            'settings.booking_mode' => [
                'sometimes',
                'string',
                Rule::in(Site::BOOKING_MODES),
            ],
            'settings.manual_booking_url' => ['sometimes', 'nullable', 'url', 'max:2048'],

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

            // Skeleton — one of skeleton-1..4. Replaces theme_id.
            'skeleton_id' => ['sometimes', 'string', Rule::in(self::ALLOWED_SKELETONS)],

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
            'skeleton_id.in' => 'Skeleton must be one of: skeleton-1, skeleton-2, skeleton-3, skeleton-4.',
        ];
    }
}
