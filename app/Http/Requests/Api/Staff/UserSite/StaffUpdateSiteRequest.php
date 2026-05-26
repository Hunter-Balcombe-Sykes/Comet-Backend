<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use App\Http\Requests\Api\User\Site\UpdateSiteRequest;
use App\Http\Requests\BaseFormRequest;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Validates staff update of a site — skeleton selection, subdomain (with
// uniqueness + reserved-word checks), publish status, settings (non-design
// only). Per-user design vars are written via the `design_kit` field which
// is processed separately by the controller (writes to site.design_kits).
class StaffUpdateSiteRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->subdomain ?? null)) {
            $this->merge([
                'subdomain' => strtolower(trim($this->subdomain)),
            ]);
        }
    }

    public function rules(): array
    {
        $professional = $this->route('professional');
        $currentSiteId = $professional?->site?->id;

        return [
            // Skeleton — one of skeleton-1..4. Replaces theme_id.
            'skeleton_id' => ['sometimes', 'string', Rule::in(UpdateSiteRequest::ALLOWED_SKELETONS)],

            // Per-user design kit. Object keyed by site.design_kits column
            // names (snake_case, matching the DB). The controller's
            // writeDesignKit() filters against information_schema.columns so
            // unknown keys are silently dropped, but we allowlist the known
            // shapes here for clear 422s on typos.
            'design_kit' => ['sometimes', 'array'],
            // Colors group (spec §5.1)
            'design_kit.color_accent' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_text' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Typography group — font slugs that resolve to
            // @partnaau/design-system/design-assets/fonts/<slug>/, plus the
            // body-text size/weight.
            'design_kit.typography_font_heading' => ['sometimes', 'nullable', 'string', 'max:64'],
            'design_kit.typography_font_body' => ['sometimes', 'nullable', 'string', 'max:64'],
            'design_kit.typography_font_family' => ['sometimes', 'nullable', 'string', 'max:64'],
            'design_kit.typography_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_font_weight' => ['sometimes', 'nullable', 'string', 'max:16'],

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
                function ($attribute, $value, $fail) use ($currentSiteId) {
                    $reserved = array_map('strtolower', config('partna.reserved_subdomains', []));
                    if (in_array(strtolower($value), $reserved, true)) {
                        $fail('The subdomain "'.$value.'" is reserved and cannot be used.');

                        return;
                    }

                    $exists = Site::whereRaw('lower(subdomain) = ?', [strtolower($value)])
                        ->when($currentSiteId, function ($query) use ($currentSiteId) {
                            $query->where('id', '!=', $currentSiteId);
                        })
                        ->exists();

                    if ($exists) {
                        $fail('This subdomain is already taken.');

                        return;
                    }

                    $aliasExists = DB::table('site.site_subdomain_aliases')
                        ->whereRaw('lower(subdomain) = ?', [strtolower($value)])
                        ->exists();

                    if ($aliasExists) {
                        $fail('This subdomain is already taken.');
                    }
                },
            ],

            'is_published' => ['sometimes', 'boolean'],

            // Non-design settings — allowlist specific keys with validation.
            'settings.hero_title' => ['sometimes', 'string', 'max:100'],
            'settings.hero_subtitle' => ['sometimes', 'string', 'max:200'],
            'settings.primary_button_text' => ['sometimes', 'string', 'max:50'],
            'settings.primary_button_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.bio_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.show_branding' => ['sometimes', 'boolean'],
            'settings.services_auto_sync_enabled' => ['sometimes', 'boolean'],
            'settings.booking_mode' => [
                'sometimes',
                'string',
                Rule::in(['manual']),
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
            'skeleton_id.in' => 'Skeleton must be one of: skeleton-1, skeleton-2, skeleton-3, skeleton-4.',
        ];
    }
}
