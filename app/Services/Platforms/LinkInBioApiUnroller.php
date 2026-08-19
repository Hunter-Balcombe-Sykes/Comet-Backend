<?php

namespace App\Services\Platforms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Recovers the destination links from link-in-bio pages that ship no anchors.
 *
 * Most aggregators server-render their links, so WebsiteLinkHarvester's <a href>
 * pass sees them. linkin.bio (Later) does not: the delivered page is a 6.4 KB
 * Ember shell with an EMPTY <body>, and every link is drawn client-side. The
 * anchor harvest therefore returned zero for every linkin.bio account since the
 * host was added to LinkInBioDetector on 2026-07-23 — a silent total loss that
 * only the zero-yield floor (LinkInBioImporter, N2) kept off the floor entirely.
 *
 * No browser is needed to fix it. The shell embeds Ember's runtime config in a
 * <meta name="linkinbio/config/environment"> tag naming its own backend, and the
 * app's data service calls `pages?nickname=<slug>` against it. That endpoint is
 * public, unauthenticated, and answers with the blocks as JSON.
 *
 * SSRF: pattern D (FixedHostVariablePath). The host is a const — closed before
 * the nickname is substituted — so no value can steer the request elsewhere. The
 * nickname is still constrained by NICKNAME_PATTERN, because a value that cannot
 * change the HOST can still carry SYNTAX (see the pattern's docblock in
 * YoutubeThumbnailResolver). Http::get()'s query array encodes it as well; the
 * pattern is the cheaper of the two guards and rejects garbage before we spend
 * a request. Registered in tests/Feature/Architecture/OutboundHttpGuardTest.php.
 *
 * NOT read, deliberately — each was visible in the payload but unverifiable
 * against a live example, and guessing a shape here loses links silently, which
 * is the bug this class exists to end. Add one when a real page exercises it:
 *   - block_data.button_groups[] — the app ships a button-list/button-group
 *     component, so grouped buttons are real; supernormal's array is empty.
 *   - social_profiles[].default_link — the "Visit Website" CTA; null here.
 *   - block_type 'feed' — Instagram post links, which duplicate the IG
 *     connection the harvest already makes. Skipped on purpose, not overlooked.
 *
 * Declining (null) and finding nothing ([]) are deliberately different answers:
 * null means "not my host / could not look", and the caller falls back to the
 * anchor harvest exactly as before. Every failure mode here returns null, so the
 * worst case is today's behaviour, never worse than it.
 */
class LinkInBioApiUnroller
{
    /** Hosts whose pages this class can read. Aggregators only, one vendor each. */
    private const SUPPORTED_HOSTS = ['linkin.bio', 'www.linkin.bio'];

    /** Later's public read API, named by the page's own Ember config. */
    private const API_URL = 'https://api-prod.linkin.bio/api/v2/pages';

    /**
     * A Later nickname mirrors the Instagram handle it was created from:
     * letters, digits, dot, underscore. The hyphen is allowed because Later
     * does not re-validate legacy slugs. Anything carrying '?', '#', '&' or
     * '/' is refused rather than encoded — we have no business guessing at a
     * slug that shaped like a URL.
     */
    private const NICKNAME_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    private const TIMEOUT_SECONDS = 5;

    /**
     * Destination URLs the page owner has published, in page order.
     *
     * @return list<string>|null null when this class cannot answer for the URL
     */
    public function unroll(string $pageUrl): ?array
    {
        $host = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
        if (! in_array($host, self::SUPPORTED_HOSTS, true)) {
            return null;
        }

        $nickname = trim((string) parse_url($pageUrl, PHP_URL_PATH), '/');
        if (preg_match(self::NICKNAME_PATTERN, $nickname) !== 1) {
            return null;
        }

        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout((int) config('partna.http_fetch.connect_timeout_seconds', 3))
            ->get(self::API_URL, ['nickname' => $nickname]);

        if (! $response->successful()) {
            return null;
        }

        $blocks = $response->json('linkinbio_page.linkinbio_blocks');
        if (! is_array($blocks)) {
            // Later revved the API. Say so — the alternative is this class
            // quietly returning nothing forever, which is the exact failure
            // it was written to end.
            Log::warning('platforms.link_in_bio_api.unrecognised_payload', [
                'host' => $host,
                'keys' => array_keys((array) $response->json()),
            ]);

            return null;
        }

        return $this->buttonUrls($blocks);
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return list<string>
     */
    private function buttonUrls(array $blocks): array
    {
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
                if (! is_array($button) || ($button['enabled'] ?? false) !== true) {
                    continue;
                }

                $url = trim((string) ($button['url'] ?? ''));
                // mailto:/tel: are real buttons on these pages but are not links
                // the router can place, and the harvest never surfaced them either.
                if (preg_match('~^https?://~i', $url) === 1 && ! in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }
}
