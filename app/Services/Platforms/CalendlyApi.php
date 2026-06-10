<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Calendly's booking pages are backed by a public JSON API (no auth):
// calendly.com/api/booking/profiles/{slug} for the profile and
// …/profiles/{slug}/event_types for the bookable session list. The stored
// card shows who you're booking with and which sessions exist; every link
// deep-links to the real Calendly page.
class CalendlyApi
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const RESERVED = ['api', 'app', 'd', 'event_types', 'integrations', 'pricing', 'teams', 'features', 'blog', 'help', 'login', 'signup', 'pages', 'legal', 'newsletter', 'embed'];

    private const MAX_EVENT_TYPES = 6;

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** Scheduling-page slug from a calendly.com URL or a bare slug. */
    public function parseSlug(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('~^https?://(?:www\.)?calendly\.com/([A-Za-z0-9._-]{2,100})/?~i', $input, $m)) {
            $candidate = $m[1];
        } elseif (preg_match('~^@?([A-Za-z0-9._-]{2,100})$~', $input, $m)) {
            $candidate = $m[1];
        } else {
            return null;
        }

        $slug = strtolower($candidate);

        return in_array($slug, self::RESERVED, true) ? null : $slug;
    }

    /**
     * @return array{name:?string, image:?string, description:?string}|null
     */
    public function fetchProfile(string $slug): ?array
    {
        $data = $this->json('https://calendly.com/api/booking/profiles/'.rawurlencode($slug));
        if (! is_array($data) || ! isset($data['name'])) {
            return null;
        }

        return [
            'name' => is_string($data['name']) ? trim($data['name']) : null,
            'image' => is_string($data['avatar_url'] ?? null) ? $data['avatar_url'] : null,
            'description' => is_string($data['description'] ?? null) ? trim(strip_tags($data['description'])) : null,
        ];
    }

    /**
     * Bookable session types (name + per-type booking slug).
     *
     * @return list<array{name:?string, slug:?string, description:?string}>
     */
    public function fetchEventTypes(string $slug): array
    {
        $data = $this->json('https://calendly.com/api/booking/profiles/'.rawurlencode($slug).'/event_types');
        $list = is_array($data) ? (array_is_list($data) ? $data : ($data['collection'] ?? [])) : [];

        $types = [];
        foreach ($list as $type) {
            if (! is_array($type) || count($types) >= self::MAX_EVENT_TYPES) {
                continue;
            }
            $types[] = [
                'name' => is_string($type['name'] ?? null) ? trim($type['name']) : null,
                'slug' => is_string($type['slug'] ?? null) ? $type['slug'] : null,
                'description' => is_string($type['description'] ?? null)
                    ? (trim(strip_tags($type['description'])) ?: null)
                    : null,
            ];
        }

        return $types;
    }

    private function json(string $url): mixed
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json']);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        return json_decode($res['body'], true);
    }
}
