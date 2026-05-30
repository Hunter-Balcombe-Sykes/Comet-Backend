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
            // Colors group
            'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_text' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_text_muted' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_accent' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_accent_contrast' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Typography group — body + title font + size + weight; fontFamily
            // is a slug resolved by @partnaau/design-system/design-assets.
            'design_kit.typography_font_family' => ['sometimes', 'nullable', 'string', 'max:64'],
            'design_kit.typography_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_font_weight' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.typography_h1_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_h1_font_weight' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.typography_h2_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_h2_font_weight' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.typography_uppercase' => ['sometimes', 'nullable', 'boolean'],
            // Orphan typography slots from the earlier wiped vars — left in
            // the allowlist so the request doesn't 422 on legacy clients,
            // but the columns store NULL and nothing reads them.
            'design_kit.typography_font_heading' => ['sometimes', 'nullable', 'string', 'max:64'],
            'design_kit.typography_font_body' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Borders (focus color is a derived default — null = follow accent)
            'design_kit.border_thickness' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.border_color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.border_radius' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.border_focus_color' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Spacing
            'design_kit.spacing_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.spacing_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.spacing_general' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.spacing_large' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Padding
            'design_kit.padding_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.padding_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.padding_general' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.padding_large' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Icons
            'design_kit.icon_size' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.icon_color' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Effects
            'design_kit.effect_overlay_blur' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.effect_overlay_opacity' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Sizing — UI control intrinsic dimensions
            'design_kit.sizing_button_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_input_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_header_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Responsive companion groups — per-breakpoint partial overrides
            // (tablet = @media min-width 640px, desktop = min-width 1024px).
            // Empty values cascade from the next-smaller breakpoint.
            'design_kit.padding_tablet_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.padding_tablet_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.padding_tablet_general' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.padding_tablet_large' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.padding_desktop_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.padding_desktop_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.padding_desktop_general' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.padding_desktop_large' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.spacing_tablet_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.spacing_tablet_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.spacing_tablet_general' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.spacing_tablet_large' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.spacing_desktop_extra_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.spacing_desktop_small' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.spacing_desktop_general' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.spacing_desktop_large' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_tablet_button_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_tablet_input_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_tablet_header_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_desktop_button_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_desktop_input_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_desktop_header_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.typography_tablet_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_desktop_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_desktop_h1_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.typography_desktop_h2_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Motion — animation durations consumed by skeleton interactions
            'design_kit.motion_expand_duration' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.motion_fade_duration' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Buttons — all derived-default colors (null = follow the linked
            // colors.* var via vars.css; non-null = explicit override).
            'design_kit.button_primary_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.button_primary_text' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.button_secondary_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.button_secondary_text' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.button_general_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.button_general_text' => ['sometimes', 'nullable', 'string', 'max:32'],

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
