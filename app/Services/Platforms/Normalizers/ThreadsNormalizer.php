<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Threads link-only normalizer — ported verbatim from the former ThreadsController.
// Threads handles mirror Instagram usernames; both threads.net and threads.com
// URLs are accepted (any scheme or none, trailing junk tolerated) → {username, url}.
class ThreadsNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~threads\.(?:net|com)/@?([^/?#]+)~i', $s, $m)) {
            $candidate = ltrim($m[1], '@');
        } else {
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9._]{1,30}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://www.threads.net/@'.$candidate];
    }
}
