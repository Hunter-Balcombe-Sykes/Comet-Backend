<?php

namespace App\Services\Platforms\Normalizers;

// TikTok link-only normalizer — ports the former TiktokController. We do NOT
// scrape (TikTok anti-bot makes server-side profile fetching unreliable); accept
// a bare handle, @handle, or a tiktok.com/@handle URL → {username, url}. Returns
// null when no handle survives (e.g. "@" alone) so the controller emits its 422.
class TiktokNormalizer
{
    /**
     * @return array{username:string, url:string}|null
     */
    public function __invoke(string $input): ?array
    {
        $username = $this->normalizeUsername($input);
        if ($username === '') {
            return null;
        }

        return [
            'username' => $username,
            'url' => 'https://www.tiktok.com/@'.$username,
        ];
    }

    // Accept a bare handle, @handle, or a tiktok.com/@handle URL → bare handle.
    private function normalizeUsername(string $input): string
    {
        $s = trim($input);
        if (preg_match('~tiktok\.com/@([A-Za-z0-9._]+)~i', $s, $m)) {
            return $m[1];
        }

        return ltrim($s, '@');
    }
}
