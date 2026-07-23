<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\LinkBlockRequestHelpers;
use App\Models\Core\Site\Block;
use Illuminate\Validation\Rule;

/**
 * Validates partial link block updates. Mirrors StoreLinkBlockRequest's two-mode
 * contract (social vs custom) but every field is `sometimes` — clients only need
 * to send what they're changing.
 *
 * Mode detection on update:
 *   - If `platform` is sent (non-null/empty), social mode: re-normalize via
 *     SocialLinkNormalizer using the new handle/url.
 *   - If `platform` is NOT sent, the existing block's mode is preserved by
 *     the controller — fields fall through to the existing values.
 *
 * Same security guarantees as StoreLinkBlockRequest: title sanitization,
 * scheme allowlist for custom URLs, normalizer-driven validation for socials.
 *
 * See docs/social-links.md.
 */
class UpdateLinkBlockRequest extends BaseFormRequest
{
    use LinkBlockRequestHelpers;

    protected function prepareForValidation(): void
    {
        $url = $this->input('url');
        $iconKey = $this->input('icon_key');
        $platform = $this->input('platform');
        $handle = $this->input('handle');

        // Same defense-in-depth title sanitization as the Store request
        $this->cleanText(['title']);

        $this->merge([
            'id' => $this->resolveRouteBlockId(),
            'url' => is_string($url) ? trim($url) : $url,
            'icon_key' => is_string($iconKey) ? trim($iconKey) : $iconKey,
            'platform' => is_string($platform) ? trim($platform) : $platform,
            'handle' => is_string($handle) ? trim($handle) : $handle,
        ]);

        $this->trimSettingsNote();
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],

            // Social mode fields
            'platform' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('partna.social_platforms', [])))],
            'handle' => ['sometimes', 'nullable', 'string', 'max:100'],

            // Custom / shared fields — all optional on update
            'title' => ['sometimes', 'nullable', 'string', 'max:80'],
            'url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'icon_key' => ['sometimes', 'nullable', 'string', Rule::in(config('partna.link_block_icon_keys', []))],

            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.highlight' => ['sometimes', 'boolean'],
            'settings.note' => ['sometimes', 'string', 'max:140'],

            // Category enum — all-optional on update (partial updates allowed).
            // Enum is still checked when present; controller applies override semantics.
            'category' => ['sometimes', 'nullable', 'string', Rule::in(config('partna.link_categories', []))],
            // Phase 2: live_check_enabled is a top-level field (promoted column),
            // no longer nested under settings.
            'live_check_enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $platform = $this->input('platform');
            $handle = $this->input('handle');
            $url = $this->input('url');

            // Social mode: if platform is being set, must have handle or url
            if ($platform !== null && $platform !== '') {
                if (($handle === null || $handle === '') && ($url === null || $url === '')) {
                    $validator->errors()->add('handle', 'Provide either a handle or a URL for this platform.');
                }
            } else {
                // Custom mode (or partial update): if a URL is being set, enforce scheme allowlist.
                // Don't require title/url here — partial updates are allowed.
                if ($url !== null && $url !== '' && ! $this->isAllowedScheme($url)) {
                    $validator->errors()->add('url', 'Custom link URLs must use http or https.');
                }
            }

            // Settings allowlist (existing behaviour)
            $settings = $this->input('settings');
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

            // Per-site cap on live_check_enabled blocks — prevents one user from
            // monopolizing the streaming poll budget.
            // Phase 2: live_check_enabled is top-level (not nested under settings).
            if ((bool) $this->input('live_check_enabled')) {
                $currentBlock = $this->route('linkBlock') ?? $this->route('block');
                $siteId = is_object($currentBlock) ? ($currentBlock->site_id ?? null) : null;
                $currentBlockId = is_object($currentBlock) && method_exists($currentBlock, 'getKey')
                    ? (string) $currentBlock->getKey()
                    : null;

                if ($siteId) {
                    $cap = (int) config('partna.streaming.max_live_check_per_site', 5);
                    $existing = Block::query()
                        ->where('site_id', $siteId)
                        ->where('block_group', 'links')
                        ->when($currentBlockId, fn ($q) => $q->where('id', '!=', $currentBlockId))
                        ->where('live_check_enabled', true)
                        ->count();

                    if ($existing >= $cap) {
                        $validator->errors()->add(
                            'live_check_enabled',
                            "You can enable live status checking on at most {$cap} link blocks per site."
                        );
                    }
                }
            }
        });
    }
}
