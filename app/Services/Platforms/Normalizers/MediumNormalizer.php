<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Medium link-only normalizer. Accepts a bare handle, @handle, or a
// medium.com/@<handle> profile URL (any scheme or none, www optional,
// trailing junk tolerated) → {username, url}; null when a medium.com URL
// carries no @<handle> path or the handle is invalid.
class MediumNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~medium\.com/@([^/?#]+)~i', $s, $m)) {
            $candidate = $m[1];
        } elseif (str_contains($s, 'medium.com')) {
            // A medium.com URL without the @<handle> path.
            return null;
        } else {
            // From the RAW input, not urlish($input): urlish() promotes a
            // dotted bare token ("julie.zhuo") to https://julie.zhuo because
            // it reads as host.tld — but we already know it is not a
            // medium.com URL, so a dotted token here is a handle.
            $candidate = PlatformInput::token($input);
        }

        // {2,40}: Medium itself has 2-char handles (founder @ev). Dots are legal
        // (@julie.zhuo is live) — the sweep rejected every dotted handle (F10).
        if (! preg_match('~^[A-Za-z0-9_.-]{2,40}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://medium.com/@'.$candidate];
    }
}
