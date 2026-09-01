<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 8 G3: ScrapeCreators' /v1/linktree returns the page's published tile
// list as plain rows ({id, type, title, url}) plus page identity
// (username, profilePictureUrl) — no HTML, no __NEXT_DATA__ excavation. This
// normalizer distils that into exactly what the link-in-bio unroll consumes:
// the owner's outbound URLs, in page order, one entry per distinct URL — the
// same list-of-strings contract LinkInBioInlinePayloadReader::linktree()
// emits — while keeping the per-link rows (trimmed title, stable id, type
// discriminator) for callers that want them.
//
// Trial-verified quirks absorbed here (2026-09-01 recorded payload):
// titles carry real trailing whitespace ("Book with me ") — trimmed; optional
// keys are OMITTED by the vendor, never null — mirrored, so a row without a
// usable title simply has no 'title' key. credits_* ride in the body and are
// deliberately NOT copied into the result — nothing from the raw body is
// spread through, so they can never reach persistence.
//
// Returns null unless the payload positively carries a usable page: a
// username AND at least one http(s) link row. A NotFound husk, shape drift,
// or an empty page must all read as "vendor miss" so the existing HTML parse
// runs exactly as it does today.
class LinktreeLinksNormalizer
{
    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array{username: string, links: non-empty-list<array{url: string, title?: string, id?: int|string, type?: string}>, profilePictureUrl?: string}|null
     */
    public function normalize(array $body): ?array
    {
        $username = $body['username'] ?? null;
        $links = $body['links'] ?? null;
        if (! is_string($username) || $username === '' || ! is_array($links)) {
            return null;
        }

        $rows = [];
        $seen = [];
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }

            $url = trim((string) ($link['url'] ?? ''));
            if (preg_match('~^https?://~i', $url) !== 1 || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $row = ['url' => $url];

            $title = trim((string) ($link['title'] ?? ''));
            if ($title !== '') {
                $row['title'] = $title;
            }

            $id = $link['id'] ?? null;
            if (is_int($id) || (is_string($id) && $id !== '')) {
                $row['id'] = $id;
            }

            $type = $link['type'] ?? null;
            if (is_string($type) && $type !== '') {
                $row['type'] = $type;
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            return null;
        }

        $page = ['username' => $username, 'links' => $rows];

        $picture = $body['profilePictureUrl'] ?? null;
        if (is_string($picture) && $picture !== '') {
            $page['profilePictureUrl'] = $picture;
        }

        return $page;
    }

    /**
     * The unroll-facing view: destination URLs in page order — the exact
     * shape the inline HTML parser hands LinkInBioImporter.
     *
     * @param  array{links: non-empty-list<array{url: string}>}  $page  a normalize() result
     * @return non-empty-list<string>
     */
    public function urls(array $page): array
    {
        return array_column($page['links'], 'url');
    }
}
