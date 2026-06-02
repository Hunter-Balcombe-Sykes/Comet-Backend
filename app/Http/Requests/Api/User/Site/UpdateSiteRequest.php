<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

// Validates site updates — settings (non-design only), subdomain uniqueness,
// skeleton selection, and publish readiness checks. Per-user design vars are
// written via the `design_kit` field which is processed separately by the
// controller (writes to site.design_kits, not site.sites).
class UpdateSiteRequest extends BaseFormRequest
{
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
            'settings.charlieEnabled' => ['sometimes', 'boolean'],
            'settings.services_auto_sync_enabled' => ['sometimes', 'boolean'],
            'settings.booking_mode' => [
                'sometimes',
                'string',
                Rule::in(['manual']),
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
                    // Check reserved words
                    $reserved = array_map('strtolower', config('partna.reserved_subdomains', []));
                    if (in_array(strtolower($value), $reserved, true)) {
                        $fail('The subdomain "'.$value.'" is reserved and cannot be used.');

                        return;
                    }

                    // Check case-insensitive uniqueness
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

                        return;
                    }

                    // Also block handles claimed by another professional's old handle alias.
                    // These are preserved for redirect/SEO purposes and must not be re-used.
                    $currentUserId = $professional?->id;

                    try {
                        $existsInUserAliases = DB::connection('pgsql')
                            ->table('core.user_handle_aliases')
                            ->whereRaw('LOWER(handle) = LOWER(?)', [$value])
                            ->where('user_id', '!=', $currentUserId)
                            ->exists();
                    } catch (QueryException $e) {
                        report($e);
                        Log::warning('Professional alias check failed in UpdateSiteRequest', ['error' => $e->getMessage()]);
                        $existsInUserAliases = false;
                    }

                    if ($existsInUserAliases) {
                        $fail('This subdomain is already taken.');
                    }
                },
            ],

            // Skeleton — one of skeleton-1..4. Replaces theme_id.
            'skeleton_id' => ['sometimes', 'string', Rule::in(self::ALLOWED_SKELETONS)],

            // Per-user design kit. Must stay in sync with StaffUpdateSiteRequest — see TEST-5.
            // An object whose keys are individual site.design_kits column names
            // (snake_case, matching the DB). The controller's writeDesignKit() filters against
            // information_schema.columns so unknown keys are silently dropped — but we still
            // allowlist the known shapes here for clear 422s on typos and to document the contract.
            'design_kit' => ['sometimes', 'array'],
            // Colors group
            'design_kit.color_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_text' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_text_muted' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_accent' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_accent_contrast' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_placeholder' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_contrasting_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.color_contrasting_text' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Typography group — body + heading scale (h1 / h2; h3 reuses
            // body fontSize). fontFamily is a slug resolved by
            // @partnaau/design-system/design-assets.
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
            // Space — single scale used for both CSS padding props AND
            // flex/grid gap props. Replaces the prior padding + spacing pair.
            'design_kit.space_xs' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_s' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_regular' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_medium' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_large' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Icons
            'design_kit.icon_size' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.icon_color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'design_kit.icons_xl_size' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.icons_xxl_size' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.icons_stroke_width' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.icons_large_stroke_width' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Effects
            'design_kit.effect_overlay_blur' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.effect_overlay_opacity' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Sizing — UI control intrinsic dimensions
            'design_kit.sizing_button_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_input_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_header_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            // Responsive companion groups — single desktop breakpoint
            // (@media min-width 550px). Empty values cascade from the
            // mobile base via the CSS cascade.
            'design_kit.space_desktop_xs' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_desktop_s' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_desktop_regular' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_desktop_medium' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.space_desktop_large' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_desktop_button_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_desktop_input_height' => ['sometimes', 'nullable', 'string', 'max:16'],
            'design_kit.sizing_desktop_header_height' => ['sometimes', 'nullable', 'string', 'max:16'],
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
