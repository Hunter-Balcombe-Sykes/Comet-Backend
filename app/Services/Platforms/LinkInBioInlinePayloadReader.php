<?php

namespace App\Services\Platforms;

use Illuminate\Support\Facades\Log;

/**
 * Reads the destination links out of a link-in-bio page whose SPA payload is
 * embedded in the HTML we already fetched.
 *
 * The sibling of LinkInBioApiUnroller, and deliberately a separate class: this
 * one makes NO outbound request at all, so it sits outside the SSRF surface
 * entirely rather than being one more arm on a class the outbound-HTTP guard
 * has to reason about. It is handed the body the importer already has.
 *
 * Host        Payload                                             Verified
 * liinks.co   `window.CONTEXT = {…}` → USER_DATA.links[]           2026-08-19
 * linktr.ee   `<script id="__NEXT_DATA__">` → props.pageProps.     2026-08-20
 *             links[].url (+ socialLinks[].url)
 *
 * liinks.co ships a ~360 KB page with ZERO <a> anchors — every link is drawn by
 * React — but the same page inlines the entire link list as JSON. So the
 * anchor harvest reads nothing off a page whose links are sitting in the bytes
 * it was just given.
 *
 * linktr.ee DOES server-render anchors — which is exactly why its gap hid for
 * so long (FI-5, reproduced live on linktr.ee/samakhurst): the anchor harvest
 * reads the CLASSIC buttons fine, but the music-embed link types
 * (SPOTIFY_SONG, SOUNDCLOUD_SONG, SPOTIFY_ARTIST…) render as players with no
 * <a> at all, so 3 of that page's 6 published links were structurally
 * invisible. All 6 sit in the page's own __NEXT_DATA__ JSON.
 *
 * Same null-vs-[] contract as LinkInBioApiUnroller: null means "not my host /
 * could not read it" and the caller falls back to the anchor harvest; an empty
 * array is a real answer that the zero-yield floor turns into a card.
 */
class LinkInBioInlinePayloadReader
{
    private const SUPPORTED_HOSTS = ['liinks.co', 'www.liinks.co'];

    private const LINKTREE_HOSTS = ['linktr.ee', 'www.linktr.ee'];

    private const ASSIGNMENT = 'window.CONTEXT';

    /** Guard against walking a multi-megabyte body brace by brace. */
    private const MAX_PAYLOAD_BYTES = 4 * 1024 * 1024;

    /**
     * Destination URLs the page owner has published, in page order.
     *
     * @return list<string>|null null when this class cannot answer for the URL
     */
    public function read(string $pageUrl, string $body): ?array
    {
        $host = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));

        if (in_array($host, self::LINKTREE_HOSTS, true)) {
            return $this->linktree($host, $body);
        }

        if (! in_array($host, self::SUPPORTED_HOSTS, true)) {
            return null;
        }

        $json = $this->objectAfterAssignment($body);
        if ($json === null) {
            return null;
        }

        $context = json_decode($json, true);
        $links = is_array($context) ? data_get($context, 'USER_DATA.links') : null;

        if (! is_array($links)) {
            // Liinks changed their shell. Say so rather than returning nothing
            // forever — a silent zero here is the failure this class ends.
            Log::warning('platforms.link_in_bio_inline.unrecognised_payload', [
                'host' => $host,
                'keys' => is_array($context) ? array_keys($context) : [],
            ]);

            return null;
        }

        return $this->targets($links);
    }

    /**
     * linktr.ee — Next.js, so the whole page state ships as pure JSON inside
     * `<script id="__NEXT_DATA__" type="application/json">…</script>`. Next
     * escapes `<` as \u003c inside the JSON, so scanning to the first
     * `</script>` cannot truncate the payload.
     *
     * Shapes quoted from a live page (linktr.ee/samakhurst, 2026-08-20), not
     * guessed: `props.pageProps.links[]` is the published tile list — every
     * entry carries `url` regardless of `type` (CLASSIC button, SPOTIFY_SONG /
     * SOUNDCLOUD_SONG embed player, INSTAGRAM_PROFILE…) — and
     * `props.pageProps.socialLinks[]` is the icon row, same `url` field.
     * Locked/hidden tiles don't ship in the SSR payload, so no re-posting
     * risk. An entry without an http(s) url is skipped, never invented.
     *
     * @return list<string>|null
     */
    private function linktree(string $host, string $body): ?array
    {
        if (! preg_match('~<script[^>]*id="__NEXT_DATA__"[^>]*>(.*?)</script>~s', $body, $m)) {
            return null;
        }

        $doc = json_decode($m[1], true);
        if (! is_array($doc)) {
            return null;
        }

        $links = data_get($doc, 'props.pageProps.links');
        $socials = data_get($doc, 'props.pageProps.socialLinks');
        // T24/issue 19 (2026-08-28): the socialLinks icon row must SURVIVE a
        // missing/renamed tile list — the old early-return on !links discarded
        // it, so a page whose shell revved (or genuinely has only the icon
        // row) fell back to the anchor harvest and Bandcamp-shaped socials
        // were lost (benbohmer/memphislk live). Null only when BOTH are gone.
        if (! is_array($links) && ! is_array($socials)) {
            // Linktree revved their shell. Say so — a silent null falls back
            // to the anchor harvest, which is exactly the 3-of-6 gap this
            // arm exists to close.
            Log::warning('platforms.link_in_bio_inline.unrecognised_payload', [
                'host' => $host,
                'keys' => array_keys((array) data_get($doc, 'props.pageProps', [])),
            ]);

            return null;
        }

        $urls = [];
        $rows = [...(is_array($links) ? $links : []), ...(is_array($socials) ? $socials : [])];
        foreach ($rows as $row) {
            $url = is_array($row) ? trim((string) ($row['url'] ?? '')) : '';
            if (preg_match('~^https?://~i', $url) === 1 && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * The JSON object assigned to `window.CONTEXT`, by brace matching.
     *
     * A regex cannot do this. JSON escapes `"` and `\` inside strings but NOT
     * `}` — so a link titled `Weird }; title` closes a non-greedy `/\{.*?\};/`
     * match early and silently truncates the payload to something that still
     * parses as an object but has lost the links array. Walking the braces with
     * string-awareness is the only correct read.
     */
    private function objectAfterAssignment(string $body): ?string
    {
        $at = strpos($body, self::ASSIGNMENT);
        if ($at === false) {
            return null;
        }

        $start = strpos($body, '{', $at + strlen(self::ASSIGNMENT));
        if ($start === false || strlen($body) - $start > self::MAX_PAYLOAD_BYTES) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start, $len = strlen($body); $i < $len; $i++) {
            $char = $body[$i];

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($inString) {
                match ($char) {
                    '\\' => $escaped = true,
                    '"' => $inString = false,
                    default => null,
                };

                continue;
            }

            match ($char) {
                '"' => $inString = true,
                '{' => $depth++,
                '}' => $depth--,
                default => null,
            };

            if ($depth === 0) {
                return substr($body, $start, $i - $start + 1);
            }
        }

        return null;
    }

    /**
     * `linkType` distinguishes a real outbound link from page furniture:
     * DIVIDER is a section heading with no target, INSTAGRAM is an embedded
     * post grid that duplicates the IG connection the harvest already makes.
     *
     * `isHidden` / `isDeleted` are the owner's own switches — a hidden link is
     * not on their page, and publishing it would re-post something they took
     * down (the `enabled: false` rule from linkin.bio, same reasoning).
     *
     * @param  array<int, mixed>  $links
     * @return list<string>
     */
    private function targets(array $links): array
    {
        $urls = [];

        foreach ($links as $link) {
            if (! is_array($link) || ($link['linkType'] ?? null) !== 'LINK') {
                continue;
            }

            if (($link['isHidden'] ?? false) === true || ($link['isDeleted'] ?? false) === true) {
                continue;
            }

            $url = trim((string) ($link['target'] ?? ''));
            // Liinks stores some targets without a scheme ("liinks.co/foo").
            // Those are relative to their own host, so the importer's chrome
            // rule would drop them anyway — refuse rather than invent a scheme.
            if (preg_match('~^https?://~i', $url) === 1 && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
