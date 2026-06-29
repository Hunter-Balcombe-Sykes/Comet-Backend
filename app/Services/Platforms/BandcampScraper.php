<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

// Scrapes a Bandcamp artist/label page with no auth. The /music page renders
// a server-side album grid (#music-grid) of <li class="music-grid-item"
// data-item-id="album-…"> items carrying the album link, art, and title —
// no API needed. Lazy-loaded art keeps the real URL in data-original.
class BandcampScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // Any {sub}.bandcamp.com URL (scheme optional) or a bare artist subdomain
    // → canonical https origin, else null.
    public function normalizeOrigin(string $input): ?string
    {
        $input = PlatformInput::urlish($input);

        if (preg_match('~^https?://([a-z0-9][a-z0-9-]*)\.bandcamp\.com~i', $input, $m)) {
            return 'https://'.strtolower($m[1]).'.bandcamp.com';
        }

        if (PlatformInput::isBareToken($input, '~^[a-z0-9][a-z0-9-]*$~i')) {
            return 'https://'.strtolower(PlatformInput::token($input)).'.bandcamp.com';
        }

        return null;
    }

    /**
     * The artist profile + their releases, newest first (Bandcamp's own grid
     * order). Returns null when the page can't be read; `items` may be empty
     * for a page with no releases.
     *
     * @return array{name:?string, thumbnail:?string, items:list<array{itemId:string, name:string, thumbnail:?string, link:string}>}|null
     */
    public function fetchProfile(string $origin, int $limit = 24): ?array
    {
        $res = $this->fetcher->tryFetch($origin.'/music', ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $html = $res['body'];

        // og:title on /music reads "Music | <Artist>".
        $name = $this->metaContent($html, 'og:title');
        if (is_string($name)) {
            $name = trim(preg_replace('~^Music\s*\|\s*~i', '', $name)) ?: null;
        }
        $name ??= $this->metaContent($html, 'og:site_name');

        $items = [];
        if (preg_match_all('~<li[^>]*data-item-id="((?:album|track)-\d+)"[^>]*>(.*?)</li>~s', $html, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $block) {
                if (count($items) >= $limit) {
                    break;
                }
                [$full, $itemId, $body] = $block;
                if (! str_contains($full, 'music-grid-item')) {
                    continue;
                }
                if (! preg_match('~<a\s+href="([^"]+)"~i', $body, $href)) {
                    continue;
                }
                if (! preg_match('~<p class="title">\s*([^<]+?)\s*(?:<br|</p)~s', $body, $title)) {
                    continue;
                }
                // Lazy images keep a blank gif in src and the real art in
                // data-original; eager ones have the bcbits URL in src.
                $thumbnail = preg_match('~(?:data-original|src)="(https?://f\d\.bcbits\.com/img/[^"]+)"~i', $body, $art)
                    ? $art[1]
                    : null;

                $items[] = [
                    'itemId' => $itemId,
                    'name' => html_entity_decode(trim($title[1]), ENT_QUOTES | ENT_HTML5),
                    'thumbnail' => $thumbnail,
                    'link' => $this->absoluteUrl(html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5), $origin),
                ];
            }
        }

        return [
            'name' => $name,
            'thumbnail' => $this->metaContent($html, 'og:image'),
            'items' => $items,
        ];
    }

    /**
     * Enrich release tiles with their buy price by fetching each release page
     * and reading its schema.org offers / embedded TralbumData. Bounded +
     * concurrent — only the handful of tiles that actually render on the
     * sitepage (latest + curated highlights) are ever passed in. price/currency
     * stay absent for "name your price", free, or unreadable releases, so
     * callers never regress to broken tiles.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public function enrichPrices(array $items, int $cap = 6): array
    {
        $links = [];
        foreach (array_slice($items, 0, $cap, true) as $i => $item) {
            $link = $item['link'] ?? null;
            if (is_string($link) && $link !== '') {
                $links[$link] = $i;
            }
        }
        if ($links === []) {
            return $items;
        }

        foreach ($this->fetcher->fetchMany(array_keys($links), ['User-Agent' => self::USER_AGENT]) as $link => $res) {
            if (! is_array($res) || ($res['status'] ?? null) !== 200) {
                continue;
            }
            $price = $this->priceFromHtml($res['body']);
            if ($price !== null) {
                $i = $links[$link];
                $items[$i] = [...$items[$i], 'price' => $price['price'], 'currency' => $price['currency']];
            }
        }

        return $items;
    }

    /**
     * Buy price off a single release page: schema.org offers first, then the
     * embedded `data-tralbum` current price. Null for free / name-your-price /
     * unreadable so the tile shows no price.
     *
     * @return array{price:string, currency:?string}|null
     */
    private function priceFromHtml(string $html): ?array
    {
        if (preg_match_all('~<script[^>]+type="application/ld\+json"[^>]*>(.*?)</script>~is', $html, $blocks)) {
            foreach ($blocks[1] as $json) {
                $data = json_decode(trim($json), true);
                if (! is_array($data)) {
                    continue;
                }
                foreach ($this->jsonLdCandidates($data) as $node) {
                    $offers = $node['offers'] ?? null;
                    if (is_array($offers) && array_is_list($offers)) {
                        $offers = $offers[0] ?? null;
                    }
                    if (! is_array($offers)) {
                        continue;
                    }
                    $p = $offers['price'] ?? $offers['lowPrice'] ?? null;
                    if (is_numeric($p) && (float) $p > 0) {
                        $c = $offers['priceCurrency'] ?? null;

                        return ['price' => (string) (0 + $p), 'currency' => is_string($c) ? strtoupper($c) : null];
                    }
                }
            }
        }

        // data-tralbum carries the canonical current.price / current.currency.
        if (preg_match('~data-tralbum="([^"]+)"~i', $html, $m)) {
            $tralbum = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
            $price = data_get($tralbum, 'current.price');
            if (is_numeric($price) && (float) $price > 0) {
                $currency = data_get($tralbum, 'current.currency');

                return ['price' => (string) (0 + $price), 'currency' => is_string($currency) ? strtoupper($currency) : null];
            }
        }

        return null;
    }

    /**
     * Flatten a JSON-LD payload to candidate nodes (@graph, list, or single).
     *
     * @param  array<mixed>  $data
     * @return list<array<string,mixed>>
     */
    private function jsonLdCandidates(array $data): array
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return array_values(array_filter($data['@graph'], 'is_array'));
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return [$data];
    }
}
