<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;
use Carbon\Carbon;

// The universal podcast surface: every show ships a public RSS feed (Apple
// requires it), and host pages (Buzzsprout, Podbean, Transistor, Captivate,
// WordPress…) advertise it via <link rel="alternate"
// type="application/rss+xml">. Connect accepts either the feed URL itself or
// any page that autodiscovers one — no keys, no auth.
class PodcastFeedService extends PlatformScraper
{
    private const MAX_EPISODES = 10;

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Resolve the input to a feed and parse it. Accepts a raw feed URL or an
     * HTML page that links one.
     *
     * @return array{feedUrl:string, show:array, episodes:list<array>}|null
     */
    public function fetchFromInput(string $input): ?array
    {
        $res = $this->fetcher->tryFetch($input, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200 || trim($res['body']) === '') {
            return null;
        }

        if ($this->looksLikeFeed($res['body'], $res['contentType'] ?? '')) {
            $parsed = $this->parseFeed($res['body']);

            return $parsed === null ? null : ['feedUrl' => $input, ...$parsed];
        }

        // HTML page — autodiscover the advertised feed and fetch that.
        $feedUrl = $this->discoverFeedUrl($res['body'], $this->originOf($input) ?? $input);
        if ($feedUrl === null) {
            return null;
        }

        $feedRes = $this->fetcher->tryFetch($feedUrl, ['User-Agent' => self::USER_AGENT]);
        if ($feedRes === null || $feedRes['status'] !== 200) {
            return null;
        }
        $parsed = $this->parseFeed($feedRes['body']);

        return $parsed === null ? null : ['feedUrl' => $feedUrl, ...$parsed];
    }

    /** First advertised RSS/Atom alternate link on an HTML page. */
    public function discoverFeedUrl(string $html, string $origin): ?string
    {
        foreach ($this->linkTags($html) as $link) {
            if (! preg_match('~type=["\']application/(?:rss|atom)\+xml["\']~i', $link)) {
                continue;
            }
            if (preg_match('~href=["\']([^"\']+)["\']~i', $link, $m)) {
                return $this->absoluteUrl(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5), $origin);
            }
        }

        return null;
    }

    /**
     * Parse an RSS 2.0 podcast feed (the universal podcast format).
     *
     * @return array{show:array{name:?string, thumbnail:?string, description:?string, link:?string}, episodes:list<array{itemId:string, title:?string, date:?string, duration:?string, audioUrl:?string, link:?string}>}|null
     */
    public function parseFeed(string $xml): ?array
    {
        // Feeds never need a DOCTYPE — its presence is an XXE smell, reject.
        if (stripos($xml, '<!DOCTYPE') !== false) {
            return null;
        }

        $doc = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($doc === false || ! isset($doc->channel)) {
            return null;
        }
        $channel = $doc->channel;
        $itunes = $channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');

        $show = [
            'name' => $this->text($channel->title),
            // itunes:image is namespaced — array-access would look the href up
            // in the itunes namespace too; attributes() gets the plain one.
            'thumbnail' => $this->text(isset($itunes->image) ? ($itunes->image->attributes()['href'] ?? null) : null)
                ?? $this->text($channel->image->url ?? null),
            'description' => $this->clip($this->text($itunes->summary ?? null) ?? $this->text($channel->description), 300),
            'link' => $this->text($channel->link),
        ];

        $episodes = [];
        foreach ($channel->item as $item) {
            if (count($episodes) >= self::MAX_EPISODES) {
                break;
            }
            $epItunes = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');

            $audio = null;
            if (isset($item->enclosure) && str_starts_with((string) ($item->enclosure['type'] ?? ''), 'audio')) {
                $audio = $this->text($item->enclosure['url']);
            }

            $date = $this->text($item->pubDate);
            $link = $this->text($item->link);
            $guid = $this->text($item->guid);

            $episodes[] = [
                'itemId' => $guid ?? $link ?? (string) count($episodes),
                'title' => $this->text($item->title),
                'date' => $date ? $this->isoDate($date) : null,
                'duration' => $this->normalizeDuration($this->text($epItunes->duration ?? null)),
                'audioUrl' => $audio,
                'link' => $link,
            ];
        }

        if ($show['name'] === null && $episodes === []) {
            return null;
        }

        return ['show' => $show, 'episodes' => $episodes];
    }

    private function looksLikeFeed(string $body, string $contentType): bool
    {
        if (preg_match('~(rss|atom|xml)~i', $contentType)) {
            return str_contains(substr($body, 0, 2000), '<rss') || str_contains(substr($body, 0, 2000), '<feed');
        }

        return (bool) preg_match('~^\s*(?:<\?xml[^>]*>\s*)?(?:<!--.*?-->\s*)*<rss~s', substr($body, 0, 2000));
    }

    /** "HH:MM:SS" / "MM:SS" / seconds → "1 hr 2 min" style display string. */
    private function normalizeDuration(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);

        if (str_contains($raw, ':')) {
            $parts = array_map('intval', explode(':', $raw));
            $seconds = match (count($parts)) {
                3 => $parts[0] * 3600 + $parts[1] * 60 + $parts[2],
                2 => $parts[0] * 60 + $parts[1],
                default => (int) $raw,
            };
        } elseif (ctype_digit($raw)) {
            $seconds = (int) $raw;
        } else {
            return null;
        }

        $minutes = max(1, intdiv($seconds, 60));

        return $minutes >= 60
            ? sprintf('%d hr %d min', intdiv($minutes, 60), $minutes % 60)
            : "{$minutes} min";
    }

    private function isoDate(string $date): ?string
    {
        try {
            return Carbon::parse($date)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function text(mixed $node): ?string
    {
        if ($node === null) {
            return null;
        }
        $value = trim(html_entity_decode((string) $node, ENT_QUOTES | ENT_HTML5));

        return $value === '' ? null : $value;
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(strip_tags($value));

        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)).'…' : ($value === '' ? null : $value);
    }
}
