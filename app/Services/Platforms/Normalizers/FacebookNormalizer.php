<?php

namespace App\Services\Platforms\Normalizers;

// Facebook link-only normalizer — ports the former FacebookController. Accept a
// bare handle, a facebook.com/<handle> vanity URL, or a facebook.com/profile.php
// or legacy /pages/<Name>/<id> link → {username, url}. The old controller always
// built an array, then 422'd when the username was empty AND the URL was neither
// a profile.php nor a /pages/ link; that decision is folded in here as a null
// return, so the generic controller's null-check reproduces the exact behavior.
class FacebookNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $selection = $this->parse($input);

        // Empty username is only valid for numeric profile.php links and legacy
        // /pages/<Name>/<id> Page links; anything else with no handle is junk input.
        if ($selection['username'] === ''
            && ! str_contains($selection['url'], 'profile.php')
            && ! str_contains($selection['url'], '/pages/')) {
            return null;
        }

        return $selection;
    }

    /**
     * @return array{username:string, url:string}
     */
    private function parse(string $input): array
    {
        $s = trim($input);

        if (preg_match('~facebook\.com/(.+)$~i', $s, $m)) {
            $path = trim($m[1], '/');

            if (str_starts_with(strtolower($path), 'profile.php')) {
                // Numeric profile link — no vanity username; keep the full path.
                return ['username' => '', 'url' => 'https://www.facebook.com/'.$path];
            }

            if (str_starts_with(strtolower($path), 'pages/')) {
                // Legacy Page link /pages/<Name>/<id> — the username is NOT the
                // first segment ("pages"); there's no vanity handle. Keep the
                // path (minus any query) and leave username empty.
                $clean = explode('?', $path)[0];

                return ['username' => '', 'url' => 'https://www.facebook.com/'.$clean];
            }

            // Vanity URL — first path segment is the username; drop query/trailing path.
            $username = explode('/', explode('?', $path)[0])[0];

            return ['username' => $username, 'url' => 'https://www.facebook.com/'.$username];
        }

        // A facebook.com URL with no path (e.g. https://www.facebook.com/) — no handle.
        if (preg_match('~facebook\.com~i', $s)) {
            return ['username' => '', 'url' => $s];
        }

        $username = ltrim($s, '@');

        return ['username' => $username, 'url' => 'https://www.facebook.com/'.$username];
    }
}
