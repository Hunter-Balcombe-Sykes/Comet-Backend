<?php

namespace App\Http\Requests\Api\User\Site;

use App\Enums\SitepageId;
use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\DesignKitValidationRules;
use App\Models\Core\Site\Site;
use App\Services\Site\SubdomainAvailabilityService;
use Illuminate\Validation\Rule;

// Validates site updates — settings (non-design only), subdomain uniqueness,
// architecture selection, and publish readiness checks. Per-user design vars are
// written via the `design_kit` field which is processed separately by the
// controller (writes to site.design_kits, not site.sites).
class UpdateSiteRequest extends BaseFormRequest
{
    use DesignKitValidationRules;

    /**
     * The platform is single-architecture: 'one' is the only layout (2026-07-10
     * — the bento/dock/flick/deck/atlas architectures were deleted and the
     * dashboard picker removed). Mirrors the DB CHECK constraint.
     */
    public const ALLOWED_ARCHITECTURES = [
        'one',
    ];

    /**
     * Every historical architecture id, all generations (skeleton-N → named →
     * bento-class renames → the 2026-07-10 collapse to 'one'). Accepted on
     * write and normalized to 'one' so a stale dashboard/chat build can never
     * 422 on a value that used to be valid — the layout is fixed regardless.
     */
    public const LEGACY_ARCHITECTURE_IDS = [
        'skeleton-1' => 'one',
        'skeleton-2' => 'one',
        'skeleton-3' => 'one',
        'skeleton-4' => 'one',
        'hub' => 'one',
        'stories' => 'one',
        'flow' => 'one',
        'sheet' => 'one',
        'thread' => 'one',
        'bento' => 'one',
        'dock' => 'one',
        'flick' => 'one',
        'deck' => 'one',
        'atlas' => 'one',
    ];

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
            $merge['architecture_id'] = self::LEGACY_ARCHITECTURE_IDS[$v] ?? $v;
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

            // Ordering preferences (OV-I actions system). Absent = smart (the
            // read side defaults both toggles to true). The two lists REPLACE
            // atomically on write — see UpdateSiteAction::LIST_SETTINGS_KEYS.
            'settings.smart_page_order' => ['sometimes', 'boolean'],
            'settings.manual_page_order' => ['sometimes', 'array', 'max:16'],
            'settings.manual_page_order.*' => ['string', 'distinct', Rule::in(SitepageId::canonicalOrder())],
            'settings.smart_actions' => ['sometimes', 'boolean'],
            'settings.manual_actions' => ['sometimes', 'array', 'max:12', $this->distinctActionRefsRule()],
            'settings.manual_actions.*' => ['array', $this->manualActionEntryRule()],
            'settings.manual_actions.*.kind' => ['required', 'string', Rule::in(['page', 'item', 'button', 'custom'])],
            'settings.manual_actions.*.ref' => ['sometimes', 'string', 'max:160'],
            'settings.manual_actions.*.label' => ['sometimes', 'string', 'min:1', 'max:80'],
            'settings.manual_actions.*.url' => ['sometimes', 'string', 'url:http,https', 'max:2048'],

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

            // Architecture — always 'one' (every legacy id normalized to it in
            // prepareForValidation; only genuinely unknown strings 422).
            'architecture_id' => ['sometimes', 'string', Rule::in(self::ALLOWED_ARCHITECTURES)],

            // Per-user design kit. Defined in DesignKitValidationRules trait so
            // this class and StaffUpdateSiteRequest share a single source of truth
            // (TEST-5 / LIFE-4). Any design_kit column change must be made in the
            // trait only.
            ...$this->designKitRules(),

            // Publish
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Per-entry strictness for settings.manual_actions.* — each entry is
     * EXACTLY one of:
     *   {kind: page,   ref: <taxonomy page-id>}
     *   {kind: item,   ref: "<itemType>:<itemKey>"}
     *   {kind: button, ref: <platform slug>}   ('booking' = general booking link)
     *   {kind: custom, label: 1..80, url: http(s)}
     * Non-custom entries must not carry label/url; custom must not carry ref;
     * no unknown keys. (Type/length/url formats are covered by the sibling
     * dotted rules — this closure enforces the cross-field shape.)
     */
    private function manualActionEntryRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return; // the 'array' rule already fails this entry
            }

            $kind = $value['kind'] ?? null;
            if (! is_string($kind)) {
                return; // .kind rules report the missing/invalid kind
            }

            if ($kind === 'custom') {
                if (array_key_exists('ref', $value)) {
                    $fail('Custom actions must not include a ref.');
                }
                if (! is_string($value['label'] ?? null) || trim((string) ($value['label'] ?? '')) === '') {
                    $fail('Custom actions require a label.');
                }
                if (! is_string($value['url'] ?? null) || trim((string) ($value['url'] ?? '')) === '') {
                    $fail('Custom actions require a url.');
                }
                $allowed = ['kind', 'label', 'url'];
            } else {
                if (array_key_exists('label', $value) || array_key_exists('url', $value)) {
                    $fail('Only custom actions may carry label/url.');
                }
                $ref = $value['ref'] ?? null;
                if (! is_string($ref) || $ref === '') {
                    $fail('The action ref is required.');
                } else {
                    $refValid = match ($kind) {
                        'page' => in_array($ref, SitepageId::canonicalOrder(), true),
                        'button' => (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $ref),
                        'item' => (bool) preg_match('/^[a-z][a-z0-9_]*:\S{1,120}$/', $ref),
                        default => true, // unknown kind — .kind Rule::in reports it
                    };
                    if (! $refValid) {
                        $fail('The action ref is not valid for its kind.');
                    }
                }
                $allowed = ['kind', 'ref'];
            }

            if (array_diff(array_keys($value), $allowed) !== []) {
                $fail('The action entry contains unknown keys.');
            }
        };
    }

    /**
     * Reject duplicate kind:ref pairs in settings.manual_actions (customs are
     * exempt — several custom buttons are legitimate).
     */
    private function distinctActionRefsRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return;
            }
            $seen = [];
            foreach ($value as $entry) {
                if (! is_array($entry) || ($entry['kind'] ?? null) === 'custom') {
                    continue;
                }
                $key = ($entry['kind'] ?? '').':'.($entry['ref'] ?? '');
                if (isset($seen[$key])) {
                    $fail('Duplicate action refs are not allowed.');

                    return;
                }
                $seen[$key] = true;
            }
        };
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
            'architecture_id.in' => 'Unknown layout.',
        ];
    }
}
