<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Discord link-only normalizer. The "handle" is a server invite code, not a
// username — accepts a bare code or a discord.gg/<code> / discord.com/invite/<code>
// URL (any scheme or none, trailing junk tolerated) → {username, url}; null when
// a discord.gg/discord.com URL carries no recognised invite path or the code is
// invalid. Only the literal /invite/ path on discord.com is treated as a code —
// other discord.com paths (login, channels, …) fall through to rejection instead
// of being mis-stored as if they were codes.
class DiscordNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~discord\.gg/([^/?#]+)~i', $s, $m) || preg_match('~discord\.com/invite/([^/?#]+)~i', $s, $m)) {
            $candidate = $m[1];
        } elseif (str_contains($s, 'discord.gg') || str_contains($s, 'discord.com')) {
            // A discord.gg/discord.com URL without a recognised invite path.
            return null;
        } else {
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9-]{2,32}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://discord.gg/'.$candidate];
    }
}
