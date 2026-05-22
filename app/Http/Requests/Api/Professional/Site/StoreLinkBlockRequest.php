<?php

namespace App\Http\Requests\Api\Professional\Site;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Validates new link block creation. Supports two write modes:
 *
 *   1. **Social mode** — `platform` is set (must be a key in
 *      config('partna.social_platforms')). Either `handle` OR `url` must be
 *      provided. The controller delegates to SocialLinkNormalizer to validate,
 *      strip a leading '@', and rebuild a canonical https URL.
 *
 *   2. **Custom mode** — no `platform`. Behaves like the legacy contract:
 *      `title` AND `url` are both required. `icon_key` is optional.
 *
 * Security:
 *   - Custom-mode URLs are restricted to http/https schemes only — no
 *     javascript:, data:, file:, ftp:. Caught in withValidator() below.
 *   - `title` is sanitized in prepareForValidation(): control chars stripped,
 *     HTML tags removed via strip_tags() as defense-in-depth on top of frontend
 *     escaping.
 *   - Social-mode handle/url validation is delegated to the normalizer service
 *     which enforces ASCII-only handles (homoglyph protection) and host
 *     allowlists (phishing protection).
 *
 * See docs/social-links.md for the full contract.
 */
class StoreLinkBlockRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $url = $this->input('url');
        $iconKey = $this->input('icon_key');
        $platform = $this->input('platform');
        $handle = $this->input('handle');

        $this->cleanText(['title']);

        $this->merge([
            'url' => is_string($url) ? trim($url) : $url,
            'icon_key' => is_string($iconKey) ? trim($iconKey) : $iconKey,
            'platform' => is_string($platform) ? trim($platform) : $platform,
            'handle' => is_string($handle) ? trim($handle) : $handle,
        ]);

        if (is_array($this->settings ?? null)) {
            $this->merge([
                'settings' => array_merge($this->settings, [
                    'note' => is_string($this->settings['note'] ?? null)
                        ? trim($this->settings['note'])
                        : ($this->settings['note'] ?? null),
                ]),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            // Social mode fields. `url` is validated lazily as a string here
            // because the normalizer runs its own host-allowlist check; using
            // Laravel's `url` rule would reject deep links we want to accept.
            'platform' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('partna.social_platforms', [])))],
            'handle' => ['sometimes', 'nullable', 'string', 'max:100'],

            // Custom mode fields (also reused for social mode auto-fallbacks)
            'title' => ['sometimes', 'nullable', 'string', 'max:80'],
            'url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'icon_key' => ['sometimes', 'nullable', 'string', Rule::in(config('partna.link_block_icon_keys', []))],

            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.highlight' => ['sometimes', 'boolean'],
            'settings.live_check_enabled' => ['sometimes', 'boolean'],
            'settings.note' => ['sometimes', 'string', 'max:140'],
            // Defense-in-depth: even though the controller overwrites settings.category
            // with the top-level category before save, enum-validate the nested form
            // in case a code path ever merges settings without overwriting.
            'settings.category' => ['sometimes', 'nullable', 'string', Rule::in(config('partna.link_categories', []))],

            // Category enum — always validated against the registry when supplied.
            // Required for custom links (enforced in withValidator); optional for
            // social links (controller falls back to the platform's default_category).
            'category' => ['sometimes', 'nullable', 'string', Rule::in(config('partna.link_categories', []))],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $platform = $this->input('platform');
            $handle = $this->input('handle');
            $url = $this->input('url');
            $title = $this->input('title');

            // Social vs custom mode discriminator
            if ($platform !== null && $platform !== '') {
                // Social mode: must have handle OR url
                if (($handle === null || $handle === '') && ($url === null || $url === '')) {
                    $validator->errors()->add('handle', 'Provide either a handle or a URL for this platform.');
                }
            } else {
                // Custom mode: title AND url both required (legacy contract preserved)
                if ($title === null || $title === '') {
                    $validator->errors()->add('title', 'The title field is required for custom links.');
                }
                if ($url === null || $url === '') {
                    $validator->errors()->add('url', 'The url field is required for custom links.');
                } elseif (! $this->isAllowedScheme($url)) {
                    $validator->errors()->add('url', 'Custom link URLs must use http or https.');
                }

                // Category is required for custom links; platform links fall back
                // to the registry's default_category when omitted.
                $category = $this->input('category');
                if ($category === null || $category === '') {
                    $validator->errors()->add('category', 'The category field is required for custom links.');
                }
            }

            // Platform-link cap — defence-in-depth on top of the dashboard's
            // disabled-Add-button UX. Resolves the link's effective category
            // (top-level `category`, then platform default) and counts
            // existing rows in the platform-links scope. Refuses creation
            // beyond `config('partna.platform_links_max')` so a savvy user
            // can't bypass the UI cap by hammering the API.
            $effectiveCategory = $this->input('category');
            if (($effectiveCategory === null || $effectiveCategory === '') && is_string($platform) && $platform !== '') {
                $effectiveCategory = config("partna.social_platforms.{$platform}.default_category");
            }

            $cappedCategories = (array) config('partna.platform_links_categories', []);
            if (is_string($effectiveCategory) && in_array($effectiveCategory, $cappedCategories, true)) {
                $max = (int) config('partna.platform_links_max', 7);
                // Resolve the professional whose cap we're enforcing. Auth::user()
                // is always null under Supabase JWT, so $this->user() can't be
                // used. Prefer the route-bound target (staff path:
                // /staff/.../professionals/{professional}/links) and fall back
                // to the auth-context professional placed on request attributes
                // by Context\LoadCurrentProfessional (self path).
                $pro = $this->route('professional') ?? $this->attributes->get('professional');
                $proId = $pro instanceof \App\Models\Core\Professional\User ? $pro->id : null;
                if ($proId !== null && $max > 0) {
                    $existing = \App\Models\Core\Site\Block::query()
                        ->where('professional_id', $proId)
                        ->where('block_group', 'links')
                        ->whereNull('deleted_at')
                        ->whereIn(DB::raw("settings->>'category'"), $cappedCategories)
                        ->count();
                    if ($existing >= $max) {
                        $validator->errors()->add(
                            'category',
                            "You've reached the limit of {$max} platform links. Remove one to add another."
                        );
                    }
                }
            }

            // Per-site cap on live_check_enabled blocks — mirrors UpdateLinkBlockRequest.
            // On creation there's no "current block" to exclude from the count.
            $settings = $this->input('settings');
            if (is_array($settings) && array_key_exists('live_check_enabled', $settings) && (bool) $settings['live_check_enabled']) {
                $pro = $this->route('professional') ?? $this->attributes->get('professional');
                $proId = $pro instanceof \App\Models\Core\Professional\User ? $pro->id : null;

                if ($proId !== null) {
                    // Resolve site_id from the professional so we cap per-site, not per-professional.
                    $siteId = \App\Models\Core\Site\Site::query()
                        ->where('professional_id', $proId)
                        ->value('id');

                    if ($siteId) {
                        $cap = (int) config('partna.streaming.max_live_check_per_site', 5);
                        $existing = \App\Models\Core\Site\Block::query()
                            ->where('site_id', $siteId)
                            ->where('block_group', 'links')
                            ->whereRaw("settings->>'live_check_enabled' = 'true'")
                            ->count();

                        if ($existing >= $cap) {
                            $validator->errors()->add(
                                'settings.live_check_enabled',
                                "You can enable live status checking on at most {$cap} link blocks per site."
                            );
                        }
                    }
                }
            }

            // Settings allowlist (existing behaviour)
            if (is_array($settings)) {
                $allowed = config('partna.link_block_settings_keys', []);
                $extra = array_diff(array_keys($settings), $allowed);
                if (! empty($extra)) {
                    $validator->errors()->add(
                        'settings',
                        'The settings field contains unsupported keys: '.implode(', ', $extra)
                    );
                }
            }
        });
    }

    /**
     * Reject schemes other than http/https for custom links. Blocks
     * javascript:, data:, file:, ftp:, and similar XSS / exfiltration vectors.
     */
    private function isAllowedScheme(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
