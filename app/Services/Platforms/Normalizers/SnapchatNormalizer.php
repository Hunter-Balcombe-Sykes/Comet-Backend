<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Snapchat link-only normalizer. Accepts a bare handle, @handle, or a
// snapchat.com/add/<handle> profile URL (any scheme or none, www optional,
// trailing junk tolerated) → {username, url}; null when a snapchat.com URL
// carries no /add/<handle> path or the handle is invalid.
class SnapchatNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~snapchat\.com/add/([^/?#]+)~i', $s, $m)) {
            $candidate = $m[1];
        } elseif (str_contains($s, 'snapchat.com')) {
            // A snapchat.com URL without the /add/<handle> path.
            return null;
        } else {
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9._-]{3,15}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://snapchat.com/add/'.$candidate];
    }
}
