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
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9_-]{3,40}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://medium.com/@'.$candidate];
    }
}
