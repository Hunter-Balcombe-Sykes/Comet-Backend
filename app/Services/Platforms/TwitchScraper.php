<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

// Twitch channel pages server-render their og tags (display name, avatar,
// bio) for any client — no auth. The live player embeds keylessly via
// player.twitch.tv/?channel={login}&parent={host}; `parent` is filled in by
// the sitepage at render time because it must equal the embedding hostname.
class TwitchScraper extends PlatformScraper
{
    private const RESERVED = ['directory', 'downloads', 'jobs', 'turbo', 'settings', 'subscriptions', 'wallet', 'drops', 'search', 'videos', 'p', 'login', 'signup', 'friends'];

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** Channel login from a twitch.tv URL or a bare handle. */
    public function parseLogin(string $input): ?string
    {
        $input = PlatformInput::urlish($input);

        if (preg_match('~^https?://(?:www\.|m\.)?twitch\.tv/([A-Za-z0-9_]{3,25})/?~i', $input, $m)) {
            $candidate = $m[1];
        } elseif (preg_match('~^@?([A-Za-z0-9_]{3,25})$~', $input, $m)) {
            $candidate = $m[1];
        } else {
            return null;
        }

        $login = strtolower($candidate);

        return in_array($login, self::RESERVED, true) ? null : $login;
    }

    /**
     * @return array{login:string, name:?string, image:?string, description:?string}|null
     */
    public function fetchChannel(string $login): ?array
    {
        $res = $this->fetcher->tryFetch("https://www.twitch.tv/{$login}", ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $html = $res['body'];

        $title = $this->metaContent($html, 'og:title');
        if ($title === null) {
            return null;
        }

        return [
            'login' => $login,
            // og:title is "DisplayName - Twitch".
            'name' => trim(preg_replace('~\s*-\s*Twitch\s*$~i', '', $title)) ?: $login,
            'image' => $this->metaContent($html, 'og:image'),
            'description' => $this->metaContent($html, 'og:description'),
        ];
    }
}
