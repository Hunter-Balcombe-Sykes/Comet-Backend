<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Skool link-only normalizer. Accepts a bare community slug or a
// skool.com/<slug> URL → {username, url}; null when the slug is invalid or
// names Skool's own product chrome rather than a community.
//
// Both the slug pattern and the chrome list are carried over verbatim from
// SourceProvisioner::skoolUrl(), deleted when Phase 1 de-sourced Skool. The
// chrome list is the load-bearing half: /signup and /login are valid-looking
// slugs, and without the guard a paste of the sign-up page would save as
// somebody's "community".
class SkoolNormalizer
{
    private const CHROME = ['signup', 'login', 'discovery', 'games', 'about', 'legal', 'careers', 'affiliates'];

    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~skool\.com/([^/?#]+)~i', $s, $m)) {
            $candidate = $m[1];
        } elseif (str_contains($s, 'skool.com')) {
            // A skool.com URL without an extractable slug.
            return null;
        } else {
            $candidate = PlatformInput::token($s);
        }

        $candidate = strtolower($candidate);

        if (! preg_match('~^[a-z0-9][a-z0-9-]*$~', $candidate) || in_array($candidate, self::CHROME, true)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://www.skool.com/'.$candidate];
    }
}
