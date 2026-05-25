<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Http\Requests\BaseFormRequest;
use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

// V2: Validates site updates — settings (design, colors, typography, media), subdomain uniqueness, theme, and publish readiness checks.
class UpdateSiteRequest extends BaseFormRequest
{
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
            // Settings: allowlist specific keys with validation
            'settings' => ['sometimes', 'array'],
            'settings.hero_title' => ['sometimes', 'string', 'max:100'],
            'settings.hero_subtitle' => ['sometimes', 'string', 'max:200'],
            'settings.primary_button_text' => ['sometimes', 'string', 'max:50'],
            'settings.primary_button_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'settings.bio_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.secondary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design' => ['sometimes', 'array'],
            // Legacy free-key colour aliases (border_color / white_color / dark_color /
            // background_color / text_color) were retired by the theme_mode migration —
            // brands no longer pick these individually. accent_color stays writable
            // until a follow-up move to the unified colors.accent slot.
            'settings.design.border_color' => ['prohibited'],
            'settings.design.accent_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.white_color' => ['prohibited'],
            'settings.design.dark_color' => ['prohibited'],
            'settings.design.border_radius' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.design.border_width' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.design.general_spacing_padding' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.design.background_color' => ['prohibited'],
            'settings.design.text_color' => ['prohibited'],
            'settings.design.button_background' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.button_text_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.secondary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.theme_variant' => ['sometimes', 'nullable', 'string', 'max:50'],
            'settings.design.product_image_ratio' => ['sometimes', 'nullable', 'string', 'in:1/1,4/5'],
            'settings.design.custom_photo_position' => ['sometimes', 'nullable', 'string', 'in:before,after,mixed'],
            'settings.design.typography' => ['sometimes', 'array'],
            // heading_font / body_font were free-string legacy fields, replaced
            // by the single `font_family` picker further down. Explicitly
            // prohibited so old clients can't repopulate the removed keys.
            'settings.design.typography.heading_font' => ['prohibited'],
            'settings.design.typography.body_font' => ['prohibited'],
            'settings.design.typography.font_file_name' => ['prohibited'],
            'settings.design.typography.font_file_path' => ['prohibited'],
            'settings.design.typography.font_file_url' => ['prohibited'],
            'settings.design.typography.logo_letter_spacing' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.design.typography.logo_font_size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'settings.design.media' => ['sometimes', 'array'],
            'settings.design.media.placeholder_sitepage_images' => ['prohibited'],
            'settings.design.media.placeholder_sitepage_images.*' => ['prohibited'],

            // --- New unified brand-design shape ---
            // Populated by the Shopify sync job (SyncShopifyBrandDesignJob) and, later,
            // by the restructured /account/design UI. Nullable so the sync job can
            // leave unset values alone when Shopify returns no value for a field.
            // The legacy design.* keys above stay in place until the UI is rebuilt
            // against this shape — drop them in a follow-up migration after that.
            'settings.design.colors' => ['sometimes', 'array'],
            // Background / text / border are now derived from theme_mode (light|dark)
            // — see the 20260419120000 migration. Only accent stays brand-pickable.
            'settings.design.colors.background' => ['prohibited'],
            'settings.design.colors.text' => ['prohibited'],
            'settings.design.colors.accent' => ['sometimes', 'nullable', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'settings.design.colors.border' => ['prohibited'],
            // 3-bucket enums + theme_mode normalise design choices into coherent
            // tokens that each Sidest theme can map to its own concrete values.
            // Enum values mirror the dashboard dropdown labels (lowercased): the
            // middle value is `default` for every 3-bucket; theme_mode is binary.
            'settings.design.theme_mode' => ['sometimes', 'nullable', 'string', Rule::in(['light', 'dark'])],
            'settings.design.theme' => ['sometimes', 'nullable', 'string', Rule::in(['theme-1'])],
            // Logos are downloaded from Shopify into our own storage so the URLs
            // are stable even if Shopify CDN tokens rotate.
            'settings.design.logo' => ['prohibited'],
            'settings.design.logo.full_url' => ['prohibited'],
            'settings.design.logo.square_url' => ['prohibited'],
            'settings.design.slogan' => ['sometimes', 'nullable', 'string', 'max:200'],
            // Font picker. Not Shopify-synced — purely a Sidest-side choice
            // from a fixed shortlist. Keep this in sync with lib/design/fonts.ts
            // on the frontend. Default for new brands: helvetica_neue.
            'settings.design.font_family' => ['sometimes', 'nullable', 'string', Rule::in([
                'neue_haas_grotesk',
                'helvetica_neue',
                'forma_djr',
                'nb_architekt',
                'swiss_721',
            ])],
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
                    $currentProfessionalId = $professional?->id;

                    try {
                        $existsInProfessionalAliases = DB::connection('pgsql')
                            ->table('site.professional_handle_aliases')
                            ->whereRaw('LOWER(handle) = LOWER(?)', [$value])
                            ->where('professional_id', '!=', $currentProfessionalId)
                            ->exists();
                    } catch (QueryException $e) {
                        report($e);
                        Log::warning('Professional alias check failed in UpdateSiteRequest', ['error' => $e->getMessage()]);
                        $existsInProfessionalAliases = false;
                    }

                    if ($existsInProfessionalAliases) {
                        $fail('This subdomain is already taken.');
                    }
                },
            ],

            // Theme (FIXED)
            'theme_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('themes', 'id'),
            ],

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
            'settings.primary_color.regex' => 'The primary color must be a valid hex color (e.g., #000000 or #000).',
            'settings.design.border_color.prohibited' => 'Border colour is now derived from theme_mode (light|dark).',
            'settings.design.accent_color.regex' => 'The design accent color must be a valid hex color.',
            'settings.design.white_color.prohibited' => 'Background colour is now derived from theme_mode (light|dark).',
            'settings.design.dark_color.prohibited' => 'Text colour is now derived from theme_mode (light|dark).',
            'settings.design.background_color.prohibited' => 'Background colour is now derived from theme_mode (light|dark).',
            'settings.design.text_color.prohibited' => 'Text colour is now derived from theme_mode (light|dark).',
            'settings.design.button_background.regex' => 'The button background color must be a valid hex color.',
            'settings.design.button_text_color.regex' => 'The button text color must be a valid hex color.',
            'settings.design.primary_color.regex' => 'The primary color must be a valid hex color.',
            'settings.design.secondary_color.regex' => 'The secondary color must be a valid hex color.',
            'settings.design.typography.heading_font.prohibited' => 'Use settings.design.font_family for the unified font picker.',
            'settings.design.typography.body_font.prohibited' => 'Use settings.design.font_family for the unified font picker.',
            'settings.design.typography.font_file_name.prohibited' => 'Custom font uploads are no longer supported — use settings.design.font_family.',
            'settings.design.typography.font_file_path.prohibited' => 'Custom font uploads are no longer supported — use settings.design.font_family.',
            'settings.design.typography.font_file_url.prohibited' => 'Custom font uploads are no longer supported — use settings.design.font_family.',
            // New unified shape messages.
            'settings.design.colors.background.prohibited' => 'Background colour is now derived from theme_mode (light|dark).',
            'settings.design.colors.text.prohibited' => 'Text colour is now derived from theme_mode (light|dark).',
            'settings.design.colors.accent.regex' => 'The accent color must be a valid hex color.',
            'settings.design.colors.border.prohibited' => 'Border colour is now derived from theme_mode (light|dark).',
            'settings.design.theme_mode.in' => 'Theme mode must be one of: light, dark.',
            'settings.design.font_family.in' => 'Font must be one of: neue_haas_grotesk, helvetica_neue, forma_djr, nb_architekt, swiss_721.',
            'settings.design.media.placeholder_sitepage_images.prohibited' => 'Use /api/uploads/brand-placeholder-image and the brand-placeholder-images management endpoints.',
            'settings.design.media.placeholder_sitepage_images.*.prohibited' => 'Use /api/uploads/brand-placeholder-image and the brand-placeholder-images management endpoints.',
            'settings.design.logo.prohibited' => 'Use /api/uploads/brand-logo (managed by site_media).',
            'settings.design.logo.full_url.prohibited' => 'Use /api/uploads/brand-logo (managed by site_media).',
            'settings.design.logo.square_url.prohibited' => 'Use /api/uploads/brand-logo (managed by site_media).',
        ];
    }
}
