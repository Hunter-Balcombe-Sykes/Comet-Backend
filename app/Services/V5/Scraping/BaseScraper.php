<?php

namespace App\Services\V5\Scraping;

use App\Services\Http\SafeUrlFetcher;
use App\Services\V5\Scraping\Budget\FetchBudget;
use Illuminate\Support\Facades\Log;

// V5 BaseScraper — shared foundation for all platform scrapers.
// Eliminates the duplicated fetch-then-check-then-parse pattern that existed
// across 9+ scrapers in the old system.
abstract class BaseScraper
{
    protected const USER_AGENT = 'Mozilla/5.0 (compatible; Partna/1.0)';

    public function __construct(
        protected readonly SafeUrlFetcher $fetcher,
        protected readonly ?FetchBudget $budget = null,
    ) {}

    // -----------------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------------

    /** Fetch HTML content. Returns null on any failure (network, non-200, budget). */
    protected function fetchHtml(string $url, array $headers = []): ?string
    {
        if ($this->budget && $this->budget->isExhausted()) {
            Log::warning('v5.scrape.budget_exhausted', ['url' => $url]);
            return null;
        }

        $headers = array_merge(['User-Agent' => self::USER_AGENT], $headers);

        $res = $this->fetcher->tryFetch($url, $headers);
        if ($res === null) {
            return null;
        }

        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            return null;
        }

        if ($this->budget) {
            $this->budget->tick();
        }

        return $res['body'] ?? null;
    }

    // -----------------------------------------------------------------------
    // URL normalization (replaces 11 normalizer classes)
    // -----------------------------------------------------------------------

    /**
     * Normalize a URL or handle to canonical form.
     * Built-in patterns cover common cases; platforms override for custom logic.
     */
    protected function normalizeUrl(string $input, string $pattern): ?string
    {
        // Pattern placeholders: <handle>, <username>, <slug>, <id>
        return match ($pattern) {
            'handle' => $this->normalizeHandle($input),
            'url' => $this->normalizeToUrl($input),
            'path_segment' => $this->extractPathSegment($input),
            default => $this->applyRegexPattern($input, $pattern),
        };
    }

    protected function normalizeHandle(string $input): string
    {
        // Strip leading @, trim whitespace, lowercase
        return mb_strtolower(trim(ltrim(trim($input), '@')));
    }

    protected function normalizeToUrl(string $input): string
    {
        // Ensure https:// prefix
        if (! str_starts_with($input, 'http')) {
            return 'https://'.$input;
        }
        // Upgrade http to https
        return preg_replace('/^http:/', 'https:', $input);
    }

    protected function extractPathSegment(string $input): ?string
    {
        $path = parse_url($input, PHP_URL_PATH);
        if (! $path) {
            return null;
        }
        return trim($path, '/');
    }

    protected function applyRegexPattern(string $input, string $pattern): ?string
    {
        if (preg_match($pattern, $input, $m)) {
            return $m[1] ?? $m[0] ?? null;
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // HTML parsing (JSON-LD, OG tags, meta, RSS)
    // -----------------------------------------------------------------------

    /** Extract all JSON-LD nodes from HTML, flattening @graph and top-level arrays. */
    protected function jsonLdNodes(string $html): array
    {
        $nodes = [];
        $pattern = '/<script[^>]+type="application\/ld\+json"[^>]*>(.*?)<\/script>/s';
        if (preg_match_all($pattern, $html, $matches)) {
            foreach ($matches[1] as $json) {
                $data = json_decode(trim($json), true);
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

    /** Extract OG meta and standard meta content. Handles property/content in either order. */
    protected function metaContent(string $html, string $property): ?string
    {
        $p = preg_quote($property, '~');
        // OG property — either attribute order
        if (preg_match('~<meta[^>]+property=["\']og:'.$p.'["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:'.$p.'["\']~i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }
        // OG name
        if (preg_match('~<meta[^>]+name=["\']og:'.$p.'["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']og:'.$p.'["\']~i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }
        // Standard meta name (description, keywords, etc.)
        if (preg_match('~<meta[^>]+name=["\']'.$p.'["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']'.$p.'["\']~i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }
        return null;
    }

    /** Extract a link tag href by rel. */
    protected function linkTag(string $html, string $rel): ?string
    {
        if (preg_match('/<link[^>]+rel="'.preg_quote($rel, '/').'"[^>]+href="([^"]*)"[^>]*>/i', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Resolve a relative URL against a base URL. */
    protected function absoluteUrl(string $base, string $relative): string
    {
        if (str_starts_with($relative, 'http')) {
            return $relative;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if (str_starts_with($relative, '//')) {
            return $scheme.':'.$relative;
        }
        if (str_starts_with($relative, '/')) {
            return $scheme.'://'.$host.$relative;
        }
        $path = dirname($parts['path'] ?? '/');
        return $scheme.'://'.$host.$path.'/'.$relative;
    }

    /** Parse an RSS/Atom feed. Returns array of entries with title, link, date, thumbnail. */
    protected function parseRssFeed(string $xml, int $limit = 15): array
    {
        $feed = simplexml_load_string($xml);
        if (! $feed) {
            return [];
        }

        $items = [];
        $count = 0;

        // RSS 2.0
        if (isset($feed->channel->item)) {
            foreach ($feed->channel->item as $item) {
                if ($count >= $limit) break;
                $items[] = $this->mapRssItem($item);
                $count++;
            }
        }

        // Atom
        if (empty($items) && isset($feed->entry)) {
            foreach ($feed->entry as $entry) {
                if ($count >= $limit) break;
                $items[] = $this->mapAtomEntry($entry);
                $count++;
            }
        }

        return $items;
    }

    private function mapRssItem(\SimpleXMLElement $item): array
    {
        $ns = $item->getNamespaces(true);
        $media = $ns['media'] ?? null;

        return [
            'title' => (string) $item->title,
            'url' => (string) ($item->link ?? $item->guid),
            'date' => (string) ($item->pubDate ?? $item->date ?? ''),
            'description' => (string) ($item->description ?? ''),
            'thumbnail' => $media ? (string) ($item->children($media)->thumbnail->attributes()->url ?? '') : '',
        ];
    }

    private function mapAtomEntry(\SimpleXMLElement $entry): array
    {
        $link = $entry->link;
        $href = '';
        if ($link) {
            $attrs = $link->attributes();
            $href = (string) ($attrs['href'] ?? $link);
        }

        return [
            'title' => (string) $entry->title,
            'url' => $href,
            'date' => (string) ($entry->updated ?? $entry->published ?? ''),
            'description' => (string) ($entry->summary ?? $entry->content ?? ''),
            'thumbnail' => '',
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    protected function sanitizeDescription(mixed $text, int $maxLength = 500): ?string
    {
        if (! is_string($text) || trim($text) === '') {
            return null;
        }
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength - 3).'...';
        }
        return $text !== '' ? $text : null;
    }

    /**
     * Format a display price string from a JSON-LD Offer or AggregateOffer array.
     * Returns "Free", "AUD 20.00", "AUD 20.00 – 50.00", or null when unparseable.
     * Shared by EventbriteScraper and HumanitixScraper.
     */
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
     * Scan a JSON-LD `offers` value (single object, list, or mixed) for the
     * lowest numeric ticket price and its currency. Tolerates AggregateOffer
     * (lowPrice), Offer lists (price), and Humanitix's mixed shape.
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

    protected function sortByStartDate(array $items, string $key = 'startDate'): array
    {
        usort($items, function ($a, $b) use ($key) {
            $aDate = $a[$key] ?? '';
            $bDate = $b[$key] ?? '';
            if ($aDate === '' && $bDate === '') {
                return 0;
            }
            if ($aDate === '') {
                return -1;
            }
            if ($bDate === '') {
                return 1;
            }

            return \Carbon\Carbon::parse($aDate)->getTimestamp() <=> \Carbon\Carbon::parse($bDate)->getTimestamp();
        });
        return $items;
    }

    /**
     * Handle field-name drift: given an array of possible field names, return
     * the first one that exists in the data. Used by Apify scrapers where
     * actor output field names vary across versions.
     */
    protected function fieldDrift(array $data, array $fieldChains): mixed
    {
        foreach ($fieldChains as $chain) {
            if (is_string($chain)) {
                if (array_key_exists($chain, $data) && $data[$chain] !== null) {
                    return $data[$chain];
                }
            } elseif (is_array($chain)) {
                // Nested: ['profile', 'pic_url_hd'] → $data['profile']['pic_url_hd']
                $value = $data;
                foreach ($chain as $key) {
                    if (! is_array($value) || ! array_key_exists($key, $value)) {
                        continue 2;
                    }
                    $value = $value[$key];
                }
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // Embed item helpers
    // -----------------------------------------------------------------------

    /**
     * Build an embed item to accompany a regular media item.
     * Embed items carry an embed_url (iframe src) that the sitepage renders
     * inline. Used by Spotify, SoundCloud, Twitch, YouTube, Vimeo, etc.
     *
     * @return array{identifier:string, name:string, item_type:'embed', values:list<array{field_name:string, value:mixed, format:string}>}
     */
    protected function buildEmbedItem(
        string $embedUrl,
        string $title,
        ?string $thumbnail,
        string $provider,
        string $originalIdentifier,
    ): array {
        $values = [
            ['field_name' => 'embed_url', 'value' => $embedUrl, 'format' => 'embed'],
            ['field_name' => 'title', 'value' => $title, 'format' => 'text'],
            ['field_name' => 'provider', 'value' => $provider, 'format' => 'text'],
        ];
        if ($thumbnail !== null) {
            $values[] = ['field_name' => 'thumbnail', 'value' => $thumbnail, 'format' => 'image'];
            $values[] = ['field_name' => 'thumbnail_url', 'value' => $thumbnail, 'format' => 'image'];
        }

        return [
            'identifier' => $originalIdentifier.'-embed',
            'name' => $title.' (Embed)',
            'item_type' => 'embed',
            'values' => $values,
        ];
    }

    // -----------------------------------------------------------------------
    // Logging
    // -----------------------------------------------------------------------

    protected function logFailure(string $platform, string $operation, ?string $detail = null): void
    {
        Log::warning("v5.scrape.{$platform}.{$operation}.failed", [
            'detail' => $detail,
            'hash' => $detail ? substr(sha1($detail), 0, 12) : null,
        ]);
    }

    protected function logSuccess(string $platform, string $operation, int $itemCount = 0): void
    {
        Log::info("v5.scrape.{$platform}.{$operation}.ok", [
            'item_count' => $itemCount,
        ]);
    }
}
