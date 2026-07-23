<?php

namespace App\Http\Requests\Concerns;

// Shared helpers for Store/Update/DestroyLinkBlockRequest: settings.note
// trimming, the SubstituteBindings route-id normalization workaround, and the
// http/https scheme allowlist (XSS/exfiltration defense — see #SLOP-6).
trait LinkBlockRequestHelpers
{
    /**
     * Trim `settings.note` in place when it's a string, leaving other
     * `settings` keys untouched. No-op when `settings` isn't an array.
     */
    protected function trimSettingsNote(): void
    {
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

    /**
     * `SubstituteBindings` middleware runs before this FormRequest is
     * resolved, so `route('linkBlock')` may already be the bound Block
     * model — not the raw UUID string. Normalise both shapes to the
     * underlying key so the `uuid` rule gets a plain string.
     */
    protected function resolveRouteBlockId(): ?string
    {
        $param = $this->route('linkBlock') ?? $this->route('block');

        return is_object($param) && method_exists($param, 'getKey')
            ? (string) $param->getKey()
            : $param;
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
