<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// X/Twitter link-only normalizer — ported verbatim from the former XController.
// Accepts a bare handle, @handle, or an x.com / twitter.com profile URL (any
// scheme or none, extra path/query tolerated) → {username, url}; null otherwise.
class XNormalizer
{
    // Non-profile first path segments on x.com — pasting one of these means the
    // input wasn't a profile URL.
    private const RESERVED = [
        'i', 'home', 'explore', 'search', 'hashtag', 'intent', 'share',
        'settings', 'notifications', 'messages', 'compose', 'login', 'signup',
    ];

    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~(?:x|twitter)\.com/([^/?#]+)~i', $s, $m)) {
            $candidate = ltrim($m[1], '@');
            if (in_array(strtolower($candidate), self::RESERVED, true)) {
                return null;
            }
        } else {
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9_]{1,15}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://x.com/'.$candidate];
    }
}
