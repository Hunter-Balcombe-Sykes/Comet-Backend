<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

// Reads a Skool community's public profile (name, avatar, description) from
// the og: tags on its about page — skool.com/{slug}/about is public even for
// private communities; the bare community root works for public ones. No API.
class SkoolScraper extends PlatformScraper
{
    // og:title values that mean "not a community page" (signup wall / product
    // chrome) — a real community page titles itself with the community name.
    private const NON_COMMUNITY_TITLES = ['skool: sign up', 'skool', 'skool: discover communities or create your own'];

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // Any skool.com community URL (scheme optional) or a bare community slug
    // → canonical https://www.skool.com/{slug}.
    public function normalizeUrl(string $input): ?string
    {
        $input = PlatformInput::urlish($input);

        if (! preg_match('~^https?://(?:www\.)?skool\.com/([a-z0-9][a-z0-9-]*)~i', $input, $m)
            && PlatformInput::isBareToken($input, '~^[a-z0-9][a-z0-9-]*$~i')) {
            $m = [null, PlatformInput::token($input)];
        }

        if (isset($m[1])) {
            $slug = strtolower($m[1]);
            // Product pages, not communities.
            if (in_array($slug, ['signup', 'login', 'discovery', 'games', 'about', 'legal', 'careers', 'affiliates'], true)) {
                return null;
            }

            return 'https://www.skool.com/'.$slug;
        }

        return null;
    }

    /**
     * @return array{url:string, name:string, image:?string, description:?string}|null
     */
    public function fetchCommunity(string $canonicalUrl): ?array
    {
        foreach ([$canonicalUrl.'/about', $canonicalUrl] as $candidate) {
            $res = $this->fetcher->tryFetch($candidate, ['User-Agent' => self::USER_AGENT]);
            if ($res === null || $res['status'] !== 200) {
                continue;
            }

            $name = $this->metaContent($res['body'], 'og:title');
            if (! is_string($name) || in_array(strtolower(trim($name)), self::NON_COMMUNITY_TITLES, true)) {
                continue;
            }

            return [
                'url' => $canonicalUrl,
                'name' => trim($name),
                'image' => $this->metaContent($res['body'], 'og:image'),
                'description' => $this->metaContent($res['body'], 'og:description'),
            ];
        }

        return null;
    }
}
