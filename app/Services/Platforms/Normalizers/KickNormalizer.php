<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Kick link-only normalizer. Accepts a bare handle or a kick.com/<handle>
// channel URL (any scheme or none, www optional, trailing junk tolerated) →
// {username, url}; null when a kick.com URL carries no handle or the handle
// is invalid.
class KickNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~kick\.com/([^/?#]+)~i', $s, $m)) {
            $candidate = $m[1];
        } elseif (str_contains($s, 'kick.com')) {
            // A kick.com URL without an extractable handle.
            return null;
        } else {
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9_-]{3,50}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://kick.com/'.$candidate];
    }
}
