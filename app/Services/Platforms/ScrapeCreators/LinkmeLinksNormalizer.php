<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 10b: ScrapeCreators' /v1/linkme (link.me) nests everything under
// `profile`, and the links live in webLinks[] GROUPED by platform — each
// group {title, links: [{linkValue, faceValue…}]}, where linkValue is the
// destination URL and the group title is the owner's label for the row.
// Groups flatten in delivered order; the recorded page ships a literal
// duplicate row (the same YouTube channel twice in one group), folded by the
// same exact-URL dedupe the whole family uses.
//
// Deliberately NOT read: infoLinks (email addresses — contact PII), and the
// profile's referralLink/deeplink (the VENDOR's app-install funnel, not the
// owner's links). Nothing from the raw body is spread through, so none of
// that — nor credits_* — can ever reach persistence.
//
// Returns null unless the payload positively carries a usable page: a
// profile username AND at least one http(s) link. A NotFound husk or shape
// drift must read as "vendor miss" so the caller's fallback runs unchanged.
class LinkmeLinksNormalizer
{
    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array{username: string, links: non-empty-list<array{url: string, title?: string, id?: int|string}>}|null
     */
    public function normalize(array $body): ?array
    {
        $profile = $body['profile'] ?? null;
        if (! is_array($profile)) {
            return null;
        }

        $username = $profile['username'] ?? null;
        $groups = $profile['webLinks'] ?? null;
        if (! is_string($username) || $username === '' || ! is_array($groups)) {
            return null;
        }

        $rows = [];
        $seen = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $title = trim((string) ($group['title'] ?? ''));

            foreach (is_array($group['links'] ?? null) ? $group['links'] : [] as $link) {
                if (! is_array($link)) {
                    continue;
                }

                $url = trim((string) ($link['linkValue'] ?? ''));
                if (preg_match('~^https?://~i', $url) !== 1 || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;

                $row = ['url' => $url];

                if ($title !== '') {
                    $row['title'] = $title;
                }

                $id = $link['webLinkId'] ?? null;
                if (is_int($id) || (is_string($id) && $id !== '')) {
                    $row['id'] = $id;
                }

                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return null;
        }

        return ['username' => $username, 'links' => $rows];
    }

    /**
     * The unroll-facing view: destination URLs in page order.
     *
     * @param  array{links: non-empty-list<array{url: string}>}  $page  a normalize() result
     * @return non-empty-list<string>
     */
    public function urls(array $page): array
    {
        return array_column($page['links'], 'url');
    }
}
