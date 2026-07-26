<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\HtmlScrapeBase;

// V5 Twitch scraper — fetches a channel page and extracts profile info from
// OG tags (og:title → display name, og:image → avatar, og:description → bio).
// The live player embeds keylessly via player.twitch.tv/?channel={login}.
// Replaces the old TwitchScraper.
class TwitchScraper extends HtmlScrapeBase
{
    // Twitch reserved path segments that are not real channels.
    private const RESERVED = [
        'directory', 'downloads', 'jobs', 'turbo', 'settings', 'subscriptions',
        'wallet', 'drops', 'search', 'videos', 'p', 'login', 'signup', 'friends',
    ];

    /**
     * Main entry: fetch channel profile info from a twitch.tv URL or handle.
     *
     * @return array{display_name:?string, profile_pic_url:?string, bio:?string, login:?string, items:list<array>}|null
     */
    public function fetch(string $input): ?array
    {
        $login = $this->parseLogin($input);
        if ($login === null) {
            return null;
        }

        $profile = $this->fetchProfile("https://www.twitch.tv/{$login}");
        if ($profile === null) {
            return null;
        }

        return array_merge($profile, ['items' => []]);
    }

    /**
     * Parse profile info from a channel page HTML.
     * og:title is "DisplayName - Twitch".
     */
    protected function parseProfile(string $html): ?array
    {
        $title = $this->metaContent($html, 'og:title');
        if ($title === null) {
            return null;
        }

        $name = trim(preg_replace('~\s*-\s*Twitch\s*$~i', '', $title)) ?: null;

        return [
            'display_name' => $name,
            'profile_pic_url' => $this->metaContent($html, 'og:image'),
            'bio' => $this->metaContent($html, 'og:description'),
        ];
    }

    /**
     * Extract a channel login (lowercase handle) from a Twitch URL or bare
     * handle. Returns null for reserved paths and invalid formats.
     */
    private function parseLogin(string $input): ?string
    {
        $input = $this->normalizeToUrl($input);

        // twitch.tv URL
        if (preg_match('~^https?://(?:www\.|m\.)?twitch\.tv/([A-Za-z0-9_]{3,25})/?~i', $input, $m)) {
            $candidate = $m[1];
        } elseif (preg_match('~^@?([A-Za-z0-9_]{3,25})$~', $input, $m)) {
            // Bare handle
            $candidate = $m[1];
        } else {
            return null;
        }

        $login = strtolower($candidate);

        return in_array($login, self::RESERVED, true) ? null : $login;
    }

    private function normalizeToUrl(string $input): string
    {
        if (! str_starts_with($input, 'http')) {
            return 'https://'.$input;
        }

        return preg_replace('/^http:/', 'https:', $input);
    }

    /**
     * Extract a platform handle from a URL (used by HtmlScrapeBase trait).
     */
    protected function resolveHandle(string $url): ?string
    {
        return $this->parseLogin($url);
    }
}
