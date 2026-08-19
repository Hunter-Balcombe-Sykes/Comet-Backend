<?php

namespace App\Services\Platforms;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Shared base for the test-mode platform scrapers. Holds the one browser
// user-agent they all present, plus the generic HTML-extraction helpers
// (og: meta tags, favicon/logo discovery, JSON-LD flattening, URL
// absolutising) the per-platform scrapers compose. All helpers are pure
// functions over an HTML string — fetching stays in each subclass, which
// keeps their constructor signatures (and DI) untouched.
abstract class PlatformScraper
{
    protected const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // Reduce any URL to its scheme://host origin — deep links are built
    // relative to it.
    public function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return "{$parts['scheme']}://{$parts['host']}";
    }

    // <meta property="og:X" content="..."> — property/content in either order.
    protected function metaContent(string $html, string $property): ?string
    {
        $p = preg_quote($property, '~');
        if (preg_match('~<meta[^>]+property=["\']'.$p.'["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']'.$p.'["\']~i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    // Pick the best favicon from the page's <link rel="...icon..."> tags.
    //
    // Strategy: collect every non-apple-touch-icon link, score by (clamped
    // size + format bonus), return the winner. Apple-touch icons are
    // excluded — they're 180×180+ iOS chrome images, oversized and often
    // visually different from the real brand favicon. PNG/SVG beat ICO
    // since the result renders at ~32px in the dashboard.
    protected function favicon(string $html, string $origin): ?string
    {
        $candidates = [];
        foreach ($this->linkTags($html) as $link) {
            if (preg_match('~rel=["\'][^"\']*apple-touch-icon[^"\']*["\']~i', $link)) {
                continue;
            }
            if (! preg_match('~rel=["\'][^"\']*icon[^"\']*["\']~i', $link)) {
                continue;
            }
            if (! preg_match('~href=["\']([^"\']+)["\']~i', $link, $h)) {
                continue;
            }

            $size = 0;
            if (preg_match('~sizes=["\'](\d+)x\d+["\']~i', $link, $s)) {
                $size = (int) $s[1];
            }
            $type = '';
            if (preg_match('~type=["\']image/([a-z0-9+.\-]+)["\']~i', $link, $t)) {
                $type = strtolower($t[1]);
            }

            $candidates[] = [
                'href' => html_entity_decode(trim($h[1]), ENT_QUOTES | ENT_HTML5),
                'size' => $size,
                'type' => $type,
            ];
        }

        if (! empty($candidates)) {
            usort($candidates, function (array $a, array $b): int {
                // Bigger is better up to 192px; past that the icon is
                // wasted bandwidth for the dashboard's ~32px slot.
                $sizeA = min($a['size'] !== 0 ? $a['size'] : 16, 192);
                $sizeB = min($b['size'] !== 0 ? $b['size'] : 16, 192);
                $typeA = in_array($a['type'], ['png', 'svg+xml'], true) ? 16 : 0;
                $typeB = in_array($b['type'], ['png', 'svg+xml'], true) ? 16 : 0;

                return ($sizeB + $typeB) <=> ($sizeA + $typeA);
            });

            return $this->absoluteUrl($candidates[0]['href'], $origin);
        }

        // No declared <link rel="icon"> — don't guess /favicon.ico (it often
        // 404s) since a broken favicon would shadow a usable logo. Null lets
        // the brand fall back to the logo instead.
        return null;
    }

    // Pick the best brand logo from the page.
    //
    // Source priority (high → low):
    //   1. JSON-LD Organization.logo as a plain string.
    //   2. JSON-LD Organization.logo as an ImageObject ({ "url": "…" }).
    //   3. og:logo meta tag.
    //   4. <img> whose class contains a known logo class (header__logo,
    //      site-logo, brand-logo, custom-logo, plain "logo"). Covers most
    //      Shopify, WooCommerce, and generic themes.
    //   5. <img src> on the Shopify CDN whose filename contains "logo"
    //      (harmless no-op on non-Shopify sites).
    //   6. apple-touch-icon (180×180+ — last-resort brand identity).
    //   7. og:image (social-share brand image).
    //   8. null.
    protected function logo(string $html, string $origin): ?string
    {
        if (preg_match('~"logo"\s*:\s*"(https?:[^"]+)"~i', $html, $m)) {
            return $this->absoluteUrl($this->cleanJsonString($m[1]), $origin);
        }

        if (preg_match('~"logo"\s*:\s*\{[^}]*?"url"\s*:\s*"(https?:[^"]+)"~i', $html, $m)) {
            return $this->absoluteUrl($this->cleanJsonString($m[1]), $origin);
        }

        if ($og = $this->metaContent($html, 'og:logo')) {
            return $this->absoluteUrl($og, $origin);
        }

        // Class-name signal — most storefront themes wrap the header logo in
        // an <img> whose class contains "header__logo", "site-logo",
        // "brand-logo", WordPress's "custom-logo", or just "logo". Match the
        // attribute in either order (class before src or vice versa).
        $logoClassRegex = '(?:header__logo|header-logo|site-logo|brand-logo|custom-logo|\blogo\b)';
        if (preg_match('~<img[^>]+class=["\'][^"\']*'.$logoClassRegex.'[^"\']*["\'][^>]*src=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<img[^>]+src=["\']([^"\']+)["\'][^>]*class=["\'][^"\']*'.$logoClassRegex.'[^"\']*["\']~i', $html, $m)) {
            return $this->absoluteUrl(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5), $origin);
        }

        // Filename signal — Shopify CDN paths often embed "logo" in the
        // uploaded asset name. Keyed on the cdn.shopify.com host; allows a
        // `?v=` cache-buster after the extension.
        if (preg_match('~<img[^>]+src=["\']([^"\']*cdn\.shopify\.com/[^"\']*logo[^"\']*\.(?:png|svg|jpg|jpeg|webp)(?:\?[^"\']*)?)["\']~i', $html, $m)) {
            return $this->absoluteUrl(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5), $origin);
        }

        foreach ($this->linkTags($html) as $link) {
            if (preg_match('~rel=["\'][^"\']*apple-touch-icon[^"\']*["\']~i', $link)
                && preg_match('~href=["\']([^"\']+)["\']~i', $link, $h)) {
                return $this->absoluteUrl(html_entity_decode(trim($h[1]), ENT_QUOTES | ENT_HTML5), $origin);
            }
        }

        // Last resort — the social-share image. On stores with no logo class,
        // logo filename, or favicon link this is the only brand-identity
        // image exposed in the homepage <head>.
        if ($og = $this->metaContent($html, 'og:image')) {
            return $this->absoluteUrl($og, $origin);
        }

        return null;
    }

    // Flatten every <script type="application/ld+json"> block (expanding
    // @graph and top-level arrays) into one list of nodes.
    protected function jsonLdNodes(string $html): array
    {
        $nodes = [];
        if (preg_match_all('~<script type="application/ld\+json"[^>]*>(.+?)</script>~s', $html, $m)) {
            foreach ($m[1] as $block) {
                $data = json_decode(trim($block), true);
                if (! is_array($data)) {
                    continue;
                }
                if (isset($data['@graph']) && is_array($data['@graph'])) {
                    $nodes = array_merge($nodes, $data['@graph']);
                } elseif (array_is_list($data)) {
                    $nodes = array_merge($nodes, $data);
                } else {
                    $nodes[] = $data;
                }
            }
        }

        return $nodes;
    }

    /** Strip JSON escapes and HTML entities from a scraped URL string. */
    protected function cleanJsonString(string $value): string
    {
        return stripslashes(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
    }

    /** @return list<string> all <link ...> tags in the html */
    protected function linkTags(string $html): array
    {
        return preg_match_all('~<link[^>]+>~i', $html, $m) ? $m[0] : [];
    }

    protected function absoluteUrl(string $url, string $origin): string
    {
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (str_starts_with($url, 'http')) {
            return $url;
        }

        return $origin.'/'.ltrim($url, '/');
    }

    // AggregateOffer → a display string. "Free", "AUD 20.00", or "AUD 20.00 – 50.00".
    protected function formatPrice(array $offers): ?string
    {
        $low = data_get($offers, 'lowPrice') ?? data_get($offers, 'price');
        if ($low === null) {
            return null;
        }
        $high = data_get($offers, 'highPrice');
        $cur = data_get($offers, 'priceCurrency');
        $prefix = $cur ? $cur.' ' : '';

        if ((float) $low === 0.0 && ($high === null || (float) $high === 0.0)) {
            return 'Free';
        }
        if ($high !== null && (float) $high !== (float) $low) {
            return "{$prefix}{$low} – {$high}";
        }

        return "{$prefix}{$low}";
    }

    /**
     * The LOWEST ticket price + its currency across a JSON-LD `offers` value —
     * tolerating every shape the events platforms emit: a single AggregateOffer
     * dict, a list of Offers, or (Humanitix) an AggregateOffer that carries only
     * priceCurrency followed by per-ticket Offer entries each carrying `price`.
     * Per entry, `lowPrice` wins over `price` (an AggregateOffer's own low);
     * the min is taken across entries. Currency = the first entry declaring
     * one. Both null when nothing numeric/declared exists (free-form pages).
     *
     * @return array{priceMin: ?float, currency: ?string}
     */
    protected function lowestOffer(mixed $offers): array
    {
        $list = is_array($offers) ? (array_is_list($offers) ? $offers : [$offers]) : [];

        $min = null;
        $currency = null;
        foreach ($list as $offer) {
            if (! is_array($offer)) {
                continue;
            }
            $cur = $offer['priceCurrency'] ?? null;
            if ($currency === null && is_string($cur) && $cur !== '') {
                $currency = strtoupper($cur);
            }
            foreach ([$offer['lowPrice'] ?? null, $offer['price'] ?? null] as $candidate) {
                if (is_numeric($candidate)) {
                    $price = (float) $candidate;
                    if ($min === null || $price < $min) {
                        $min = $price;
                    }
                    break; // lowPrice already IS this entry's low — don't also read price.
                }
            }
        }

        return ['priceMin' => $min, 'currency' => $currency];
    }

    // schema.org availability URL → "available" | "sold_out" | null.
    protected function normalizeAvailability(?string $availability): ?string
    {
        if (! $availability) {
            return null;
        }
        $a = strtolower($availability);
        // 'outofstock' = schema.org's OutOfStock — distinct from SoldOut and
        // previously fell through to unknown→available (critic 2026-07-17;
        // matters now that soldOut is a user-facing boolean).
        if (str_contains($a, 'soldout') || str_contains($a, 'outofstock')) {
            return 'sold_out';
        }
        if (str_contains($a, 'instock') || str_contains($a, 'limited') || str_contains($a, 'presale') || str_contains($a, 'preorder')) {
            return 'available';
        }

        return null;
    }

    /**
     * One schema.org Event JSON-LD node → the stored event shape every events
     * lane shares. Extracted from EventbriteScraper::parseEvent and
     * HumanitixScraper::parseEventNode, whose mappings were field-for-field
     * identical (same first-entry offers collapse, same lowest-price scan of
     * the RAW offers list, same image-list collapse). Eventbrite's regional
     * host rewrite is the one per-platform delta and stays in that scraper,
     * applied over this result's `link`.
     *
     * @param  array<string, mixed>  $event
     * @return array{name:?string, venue:?string, location:?string, startDate:?string, endDate:?string, description:?string, startsAt:?string, endsAt:?string, price:?string, priceMin:?float, currency:?string, availability:?string, soldOut:bool, image:?string, link:string}
     */
    protected function schemaOrgEventNode(array $event, string $fallbackUrl): array
    {
        $loc = is_array($event['location'] ?? null) ? $event['location'] : [];
        // $offers keeps the historical first-entry collapse (price string +
        // availability read it); lowestOffer() scans the RAW value so a multi-
        // tier offers list still yields the true minimum.
        $offersRaw = $event['offers'] ?? [];
        $offers = $offersRaw;
        if (isset($offers[0])) {
            $offers = $offers[0];
        }
        if (! is_array($offers)) {
            $offers = [];
        }
        $image = $event['image'] ?? null;
        if (is_array($image)) {
            $image = $image[0] ?? null;
        }

        $availability = $this->normalizeAvailability(data_get($offers, 'availability'));
        $lowest = $this->lowestOffer($offersRaw);
        $venue = data_get($loc, 'name');
        $link = $event['url'] ?? null;

        return [
            'name' => $event['name'] ?? null,
            'venue' => $venue !== null ? trim((string) $venue) : null,
            'location' => data_get($loc, 'address.addressLocality') ?? data_get($loc, 'address.addressRegion'),
            'startDate' => $event['startDate'] ?? null,
            'endDate' => $event['endDate'] ?? null,
            // startsAt/endsAt are the contract-named ISO aliases of start/endDate
            // (kept: existing consumers read the old keys); price stays the legacy
            // display STRING while priceMin/currency carry the machine-readable
            // lowest ticket price; soldOut is the boolean face of availability.
            'description' => $this->sanitizeDescription($event['description'] ?? null),
            'startsAt' => $event['startDate'] ?? null,
            'endsAt' => $event['endDate'] ?? null,
            'price' => $this->formatPrice($offers),
            'priceMin' => $lowest['priceMin'],
            'currency' => $lowest['currency'],
            'availability' => $availability,
            'soldOut' => $availability === 'sold_out',
            'image' => is_string($image) ? $image : null,
            'link' => is_string($link) && $link !== '' ? $link : $fallbackUrl,
        ];
    }

    // Sort scraped events soonest-first, in place. Events with an empty/missing
    // startDate sort first (preserving their pre-extraction order); the rest by
    // Carbon-parsed timestamp. Carbon::parse is required because the dates carry
    // each event's LOCAL timezone offset — a plain string compare against a UTC
    // value mis-orders them. Shared by EventbriteScraper and HumanitixScraper,
    // whose sort closures were byte-for-byte identical.
    protected function sortByStartDate(array &$events): void
    {
        usort($events, function ($a, $b) {
            $aDate = $a['startDate'] ?? '';
            $bDate = $b['startDate'] ?? '';
            if ($aDate === '' && $bDate === '') {
                return 0;
            }
            if ($aDate === '') {
                return -1;
            }
            if ($bDate === '') {
                return 1;
            }

            return Carbon::parse($aDate)->getTimestamp() <=> Carbon::parse($bDate)->getTimestamp();
        });
    }

    // Block-level element boundaries that must become whitespace BEFORE
    // strip_tags() runs — otherwise adjacent blocks glue together with no
    // separator ("<p>Hello</p><p>world</p>" → "Helloworld"). Matches both the
    // opening and closing form (and self-closing <br/>, <br />) so a stray
    // bare-text-then-block seam is covered too, though in practice it's each
    // block's OWN closing tag that supplies the separating space.
    private const BLOCK_BOUNDARY_TAGS = 'p|div|li|ul|ol|h[1-6]|br|hr|tr|td|th|table|thead|tbody|blockquote';

    /**
     * Product-description HTML → plain text: insert whitespace at block-element
     * boundaries, strip remaining tags, decode entities, collapse whitespace,
     * cap length. Blank/non-string input becomes null. Shared by ShopifyScraper,
     * WooCommerceScraper, and GenericShopScraper — their sanitizeDescription()
     * bodies were byte-for-byte identical (and shared the same glued-text bug:
     * strip_tags() alone has no concept of block boundaries, and Str::squish()
     * only collapses whitespace that already exists — it can't invent it).
     */
    protected function sanitizeDescription(mixed $html): ?string
    {
        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        // preg_replace returns null on a PCRE engine error (e.g. the
        // backtrack/recursion limit) rather than throwing — fall back to the
        // untouched $html so a rare engine error degrades to "no boundary
        // spaces inserted" instead of silently producing an empty/null
        // description (fix-round P4).
        $withBoundarySpaces = preg_replace(
            '~</?(?:'.self::BLOCK_BOUNDARY_TAGS.')(?:\s[^>]*)?/?>~i',
            ' ',
            $html
        ) ?? $html;

        $text = Str::limit(Str::squish(html_entity_decode(strip_tags((string) $withBoundarySpaces), ENT_QUOTES | ENT_HTML5)), 2000, '');

        return $text !== '' ? $text : null;
    }
}
