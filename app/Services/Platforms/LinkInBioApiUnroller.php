<?php

namespace App\Services\Platforms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Recovers the destination links from link-in-bio pages that ship no anchors.
 *
 * Most aggregators server-render their links, so WebsiteLinkHarvester's <a href>
 * pass sees them. Four of the hosts in LinkInBioDetector do not: the delivered
 * page is an empty SPA shell and every link is drawn client-side, so the anchor
 * harvest returns zero for a page carrying eight buttons. Three of those four
 * are answered here, by the same public read API the page's own JavaScript
 * calls — no headless browser anywhere. The fourth (liinks.co) needs no request
 * at all and lives in LinkInBioInlinePayloadReader.
 *
 * Host                Seam                                                Verified
 * linkin.bio (Later)  api-prod.linkin.bio/api/v2/pages?nickname=<slug>    2026-08-19
 * taplink.cc          taplink.cc/<nickname>/api/page/get.json             2026-08-19
 * stan.store          api.stanwith.me/api/v1/stores?username=<slug>       2026-08-19
 *
 * Each endpoint is public and unauthenticated, and each was read off the host's
 * own bundle rather than guessed — the field paths below are quoted from the
 * vendor's client code and then confirmed against a live response.
 *
 * SSRF: pattern D (FixedHostVariablePath). Every host is a class const — closed
 * before any slug is substituted — so no value can steer the request elsewhere.
 * The slug is still constrained by NICKNAME_PATTERN, because a value that cannot
 * change the HOST can still carry SYNTAX (see the pattern's docblock in
 * YoutubeThumbnailResolver). Registered in
 * tests/Feature/Architecture/OutboundHttpGuardTest.php.
 *
 * Declining (null) and finding nothing ([]) are deliberately different answers:
 * null means "not my host / could not look", and the caller falls back to the
 * anchor harvest exactly as before. Every failure mode here returns null, so the
 * worst case is today's behaviour, never worse than it. An EMPTY array is a real
 * answer — "I read the page, the owner published no outbound links" — and the
 * zero-yield floor in LinkInBioImporter is what turns that into a card.
 */
class LinkInBioApiUnroller
{
    /** Later's public read API, named by the page's own Ember config. */
    private const LINKIN_BIO_API_URL = 'https://api-prod.linkin.bio/api/v2/pages';

    /** Taplink's own frontend.js builds this: `"/" + nickname + "/api/" + action + ".json"`. */
    private const TAPLINK_HOST = 'https://taplink.cc';

    /** Stan's Nuxt bundle: `$get('v1/stores', {baseURL: 'https://api.stanwith.me/api/'})`. */
    private const STAN_API_URL = 'https://api.stanwith.me/api/v1/stores';

    /**
     * Slugs on all three hosts mirror the Instagram handle they were created
     * from: letters, digits, dot, underscore. The hyphen is allowed because
     * none of them re-validate legacy slugs. Anything carrying '?', '#', '&'
     * or '/' is refused rather than encoded — we have no business guessing at
     * a slug shaped like a URL.
     */
    private const NICKNAME_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    private const TIMEOUT_SECONDS = 5;

    /**
     * stan's user.data.socials stores SOME networks as bare handles which its
     * own client expands into profile URLs. Only the expansions CONFIRMED
     * against a live store are listed (natalieannehair, 2026-08-20:
     * tiktok "natalieannehair" → tiktok.com/@natalieannehair, instagram
     * "Natalieannehair" → instagram.com/Natalieannehair — both verified in
     * the hydrated DOM). A key not listed here is skipped unless its value
     * is already a full URL; add expansions when a real page exercises them,
     * never by guessing — a wrong guess MINTS a URL, worse than missing one.
     */
    private const STAN_SOCIAL_PROFILES = [
        'tiktok' => 'https://www.tiktok.com/@%s',
        'instagram' => 'https://www.instagram.com/%s',
    ];

    /**
     * Destination URLs the page owner has published, in page order.
     *
     * @return list<string>|null null when this class cannot answer for the URL
     */
    public function unroll(string $pageUrl): ?array
    {
        $host = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
        $slug = trim((string) parse_url($pageUrl, PHP_URL_PATH), '/');

        if (preg_match(self::NICKNAME_PATTERN, $slug) !== 1) {
            return null;
        }

        return match ($host) {
            'linkin.bio', 'www.linkin.bio' => $this->linkinBio($slug),
            'taplink.cc', 'www.taplink.cc' => $this->taplink($slug),
            'stan.store', 'www.stan.store' => $this->stanStore($slug),
            default => null,
        };
    }

    /**
     * linkin.bio (Later) — an Ember shell whose <meta name="linkinbio/config/
     * environment"> names its own backend.
     *
     * NOT read, deliberately — each was visible in the payload but unverifiable
     * against a live example, and guessing a shape here loses links silently,
     * which is the bug this class exists to end. Add one when a real page
     * exercises it:
     *   - block_data.button_groups[] — the app ships a button-list/button-group
     *     component, so grouped buttons are real; supernormal's array is empty.
     *   - social_profiles[].default_link — the "Visit Website" CTA; null here.
     *   - block_type 'feed' — Instagram post links, which duplicate the IG
     *     connection the harvest already makes. Skipped on purpose.
     *
     * @return list<string>|null
     */
    private function linkinBio(string $slug): ?array
    {
        $blocks = $this->json(self::LINKIN_BIO_API_URL, ['nickname' => $slug], 'linkinbio_page.linkinbio_blocks', 'linkin.bio');
        if ($blocks === null) {
            return null;
        }

        $urls = [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['block_type'] ?? null) !== 'button_list') {
                continue;
            }

            $data = is_array($block['block_data'] ?? null) ? $block['block_data'] : [];
            // A block the owner switched off is not on their page.
            if (($data['enabled'] ?? false) !== true) {
                continue;
            }

            foreach (is_array($data['buttons'] ?? null) ? $data['buttons'] : [] as $button) {
                if (is_array($button) && ($button['enabled'] ?? false) === true) {
                    $this->collect($urls, $button['url'] ?? '');
                }
            }
        }

        return $urls;
    }

    /**
     * taplink.cc — a Vue shell. Its `window.account` inline blob carries the
     * theme and nickname but NOT the links; those come from page/get.
     *
     * Two traps, both measured on the live endpoint 2026-08-19:
     *   - Sending `?page_id=` or `?id=` answers {"result":"redirect"} instead
     *     of data. The request must go BARE.
     *   - `options.type === 'page'` is an internal Taplink sub-page, and its
     *     `options.value` is null. Only a block with a real http(s) value is an
     *     outbound link; collect() drops the rest.
     *
     * @return list<string>|null
     */
    private function taplink(string $slug): ?array
    {
        $fields = $this->json(self::TAPLINK_HOST.'/'.$slug.'/api/page/get.json', [], 'response.fields', 'taplink.cc');
        if ($fields === null) {
            return null;
        }

        $urls = [];

        foreach ($fields as $field) {
            foreach (is_array($field['items'] ?? null) ? $field['items'] : [] as $item) {
                if (is_array($item) && ($item['block_type_name'] ?? null) === 'link') {
                    $options = is_array($item['options'] ?? null) ? $item['options'] : [];
                    $this->collect($urls, $options['value'] ?? '');
                }
            }
        }

        return $urls;
    }

    /**
     * stan.store — a Nuxt shell. Its inline `window.__NUXT__` holds the same
     * data but as a minified JS IIFE rather than JSON, so the API is the seam.
     *
     * **A Stan store is a STOREFRONT, not a link list.** Most of its pages are
     * products, bookings and courses hosted on stan.store itself; those are
     * same-host and the importer's chrome rule drops them anyway. Only pages of
     * `type: 'link'` point outward, and their URL sits at
     * `data.product.link.url` — quoted from Stan's own `openLink()`
     * (`new URL(e.data.product.link.url)`) and confirmed against a live store.
     * So an empty array here is the COMMON answer for this host, not a failure.
     *
     * `status` is filtered fail-closed: every live, visible page observed across
     * the stores probed carries `status: 2`, and the enum is not published
     * anywhere we can read. Skipping anything else risks hiding a link; showing
     * it risks re-posting one the owner unpublished, and that is the worse of
     * the two (same reasoning as `enabled: false` on linkin.bio).
     *
     * @return list<string>|null
     */
    private function stanStore(string $slug): ?array
    {
        $doc = $this->jsonDoc(self::STAN_API_URL, ['username' => $slug]);
        if ($doc === null) {
            return null;
        }

        $pages = data_get($doc, 'store.pages');
        if (! is_array($pages)) {
            Log::warning('platforms.link_in_bio_api.unrecognised_payload', [
                'host' => 'stan.store',
                'path' => 'store.pages',
                'keys' => array_keys($doc),
            ]);

            return null;
        }

        $urls = [];

        foreach ($pages as $page) {
            if (! is_array($page) || ($page['type'] ?? null) !== 'link' || ($page['status'] ?? null) !== 2) {
                continue;
            }

            $link = data_get($page, 'data.product.link.url');
            $this->collect($urls, is_string($link) ? $link : '');
        }

        // F12 (2026-08-20, natalieannehair): the owner's SOCIALS are neither
        // tiles nor anchors — the delivered shell carries them only inside
        // the minified __NUXT__ blob, so the anchor harvest can never see
        // them either. The API carries them at user.data.socials in MIXED
        // shapes, confirmed live: facebook is a full URL, tiktok/instagram
        // are bare handles the client expands. Full URLs pass through
        // whatever their key; only handle keys named in STAN_SOCIAL_PROFILES
        // are expanded (same rule as linkinBio() above: shapes are quoted
        // from live responses, never guessed — a wrong guess would MINT a
        // URL, worse than missing one). mail_to is an email; collect()'s
        // http(s) test already refuses it.
        foreach ((array) data_get($doc, 'user.data.socials', []) as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $value = trim($value);

            if (preg_match('~^https?://~i', $value) === 1) {
                $this->collect($urls, $value);

                continue;
            }

            $format = self::STAN_SOCIAL_PROFILES[$key] ?? null;
            if ($format !== null && ! str_contains($value, '/')) {
                $this->collect($urls, sprintf($format, rawurlencode(ltrim($value, '@'))));
            }
        }

        return $urls;
    }

    /**
     * One GET, one dotted path out of the JSON. Returns null on any failure so
     * the caller falls through to the anchor harvest.
     *
     * @param  array<string, string>  $query
     * @return array<int|string, mixed>|null
     */
    private function json(string $url, array $query, string $path, string $host): ?array
    {
        $doc = $this->jsonDoc($url, $query);
        if ($doc === null) {
            return null;
        }

        $found = data_get($doc, $path);
        if (! is_array($found)) {
            // The vendor revved their API. Say so — the alternative is this
            // class quietly returning nothing forever, which is the exact
            // failure it was written to end.
            Log::warning('platforms.link_in_bio_api.unrecognised_payload', [
                'host' => $host,
                'path' => $path,
                'keys' => array_keys($doc),
            ]);

            return null;
        }

        return $found;
    }

    /**
     * One GET, the whole decoded document. stanStore() reads TWO paths out of
     * one response (tiles + socials), so the fetch and the path-extraction had
     * to come apart; json() above keeps the one-path convenience for the hosts
     * that need only one.
     *
     * @param  array<string, string>  $query
     * @return array<int|string, mixed>|null
     */
    private function jsonDoc(string $url, array $query): ?array
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout((int) config('partna.http_fetch.connect_timeout_seconds', 3))
            ->get($url, $query);

        if (! $response->successful()) {
            return null;
        }

        $doc = $response->json();

        return is_array($doc) ? $doc : null;
    }

    /**
     * mailto:/tel: are real buttons on these pages but are not links the router
     * can place, and the anchor harvest never surfaced them either.
     *
     * @param  list<string>  $urls
     */
    private function collect(array &$urls, mixed $url): void
    {
        $url = trim((string) $url);

        if (preg_match('~^https?://~i', $url) === 1 && ! in_array($url, $urls, true)) {
            $urls[] = $url;
        }
    }
}
