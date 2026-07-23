<?php

namespace App\Services\Platforms\Normalizers;

use App\Services\Platforms\PlatformInput;

// Telegram link-only normalizer. Accepts a bare handle, @handle, or a
// t.me/telegram.me/telegram.org/<handle> (optionally @-prefixed in the path)
// profile URL → {username, url}; null when a Telegram host URL carries no
// handle or the handle is invalid. \b guards t.me the way FacebookNormalizer
// guards fb.com — it's a short domain that's otherwise a substring of
// unrelated hosts (e.g. "about.me/handle" must not be parsed as a Telegram link).
class TelegramNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~(?:\bt\.me|telegram\.me|telegram\.org)/@?([^/?#]+)~i', $s, $m)) {
            $candidate = $m[1];
        } elseif (str_contains($s, 't.me') || str_contains($s, 'telegram.me') || str_contains($s, 'telegram.org')) {
            // A Telegram host URL without an extractable handle.
            return null;
        } else {
            $candidate = PlatformInput::token($s);
        }

        if (! preg_match('~^[A-Za-z0-9_]{5,32}$~', $candidate)) {
            return null;
        }

        return ['username' => $candidate, 'url' => 'https://t.me/'.$candidate];
    }
}
