<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Twitch link-only normalizer. Accepts a bare login or a twitch.tv/<login>
// channel URL → {username, url}; null when a twitch.tv URL carries no login or
// the login is invalid.
//
// The 3–25 word-character rule is Twitch's own login format, carried over
// verbatim from SourceProvisioner::twitchLogin() — deleted when Phase 1
// de-sourced Twitch. Keeping the rule here rather than losing it is the point:
// the platform still connects, it just no longer ingests.
class TwitchNormalizer
{
    /** Not channels — Twitch's own site chrome, which a paste can easily land on. */
    private const RESERVED = ['directory', 'settings', 'downloads', 'jobs', 'turbo', 'store', 'p', 'videos'];

    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~twitch\.tv/([^/?#]+)~i', $s, $m)) {
            $candidate = $m[1];
        } elseif (str_contains($s, 'twitch.tv')) {
            // A twitch.tv URL without an extractable login.
            return null;
        } else {
            $candidate = PlatformInput::token($s);
        }

        $candidate = strtolower(ltrim($candidate, '@'));

        if (! preg_match('~^[a-z0-9_]{3,25}$~', $candidate) || in_array($candidate, self::RESERVED, true)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://twitch.tv/'.$candidate];
    }
}
