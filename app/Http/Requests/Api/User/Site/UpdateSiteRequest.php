<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\DesignKitValidationRules;
use App\Models\Core\Site\Site;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Site\SubdomainAvailabilityService;
use Illuminate\Validation\Rule;

// Validates site updates — settings (non-design only), subdomain uniqueness,
// skeleton selection, and publish readiness checks. Per-user design vars are
// written via the `design_kit` field which is processed separately by the
// controller (writes to site.design_kits, not site.sites).
class UpdateSiteRequest extends BaseFormRequest
{
    use DesignKitValidationRules;

    /**
     * Allowed skeleton IDs — mirrors the DB CHECK constraint. 'atlas' (the
     * multi-page site) is Business-only; the Rule::in below accepts it for
     * everyone, and withValidator() rejects it for accounts without the
     * can_use_multipage_site capability (#30).
     */
    public const ALLOWED_SKELETONS = [
        'bento', 'dock', 'flick', 'deck', 'sheet', 'thread', 'atlas',
    ];

    /** Skeletons gated to a capability, not available to every account (#30). */
    public const CAPABILITY_GATED_SKELETONS = [
        'atlas' => 'can_use_multipage_site',
    ];

    /**
     * Pre-rename ids, both generations (2026-07-07: skeleton-N → named, then
     * the bento-class renames hub→dock / stories→flick / flow→deck). Accepted
     * on write and normalized to the canonical id so a not-yet-updated
     * dashboard build can still save its selection during the rollout window.
     */
    public const LEGACY_SKELETON_IDS = [
        'skeleton-1' => 'bento',
        'skeleton-2' => 'dock',
        'skeleton-3' => 'flick',
        'skeleton-4' => 'deck',
        'hub' => 'dock',
        'stories' => 'flick',
        'flow' => 'deck',
    ];

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->subdomain ?? null)) {
            $merge['subdomain'] = strtolower(trim($this->subdomain));
        }

        if (is_string($this->skeleton_id ?? null) && isset(self::LEGACY_SKELETON_IDS[$this->skeleton_id])) {
            $merge['skeleton_id'] = self::LEGACY_SKELETON_IDS[$this->skeleton_id];
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

            // Skeleton — one of bento/dock/flick/deck/sheet/thread (legacy ids,
            // both generations, normalized in prepareForValidation). Replaces theme_id.
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
            // Capability-gated skeletons (atlas = Business-only multi-page). The
            // Rule::in accepts them for everyone; this rejects one the account
            // lacks the capability for, so selection stays capability-driven (#30).
            $skeleton = $this->input('skeleton_id');
            if (is_string($skeleton) && isset(self::CAPABILITY_GATED_SKELETONS[$skeleton])) {
                $professional = $this->attributes->get('professional');
                $capability = self::CAPABILITY_GATED_SKELETONS[$skeleton];
                if (! $professional || ! AccountCapabilities::for($professional)->{$capability}) {
                    $validator->errors()->add('skeleton_id', 'This layout is only available on Business Partna accounts.');
                }
            }

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
            'skeleton_id.in' => 'Skeleton must be one of: bento, dock, flick, deck, sheet, thread, atlas.',
        ];
    }
}
