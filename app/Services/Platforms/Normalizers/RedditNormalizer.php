<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Reddit link-only normalizer — ported verbatim from the former RedditController.
// Accepts user profiles (u/name, user/name, reddit.com/user/name) AND subreddits
// (r/name), any reddit subdomain or a bare username → {username, url}; null when a
// reddit.com URL carries no profile/community path or the name is invalid.
class RedditNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        // URL forms (any reddit subdomain) and bare "u/…" / "r/…" prefixes.
        if (preg_match('~(?:reddit\.com/|^)(u|user|r)/([^/?#]+)~i', $s, $m)) {
            $kind = strtolower($m[1]) === 'r' ? 'r' : 'user';
            $name = $m[2];
        } elseif (str_contains($s, 'reddit.com')) {
            // A reddit.com URL without a profile/community path.
            return null;
        } else {
            $kind = 'user';
            $name = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9_\-]{2,21}$~', $name)) {
            return null;
        }

        return [
            'username' => $name,
            'url' => "https://www.reddit.com/{$kind}/{$name}/",
        ];
    }
}
