<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * Pinterest's profile page (GET pinterest.com/{username}/) embeds its page
 * state as one or more `<script type="application/json">` blocks holding
 * MANY nested resource objects — the same mechanism
 * PinterestScraper::fetchProfile() already walks (via its jsonScripts() +
 * findUserNode()) to pull a User object out of the blob. This connector
 * mirrors that exact technique — same script-tag scan, same recursive
 * depth-first search — but collects PIN-shaped objects instead of the one
 * user object, since the profile page's pin grid must be rendered from the
 * same embedded state.
 *
 * DEVIATION TO FLAG (per instructions — not quietly shipped): the User
 * object's fields (`username`, `full_name`, `image_xlarge_url`,
 * `follower_count`) are proven against this codebase's own tested scraper.
 * The Pin object's fields used below (`id`, `images.<size>.url`,
 * `grid_title`) are this connector's own best-effort read of Pinterest's
 * known internal naming conventions — NOT verified against a live capture in
 * this codebase (PinterestScraper has no existing method that extracts pins
 * from this particular embedded state; its own `fetchPins()` reads a
 * completely different surface, the public feed.rss). If the guessed shape
 * is wrong, every pull finds zero pin nodes and degrades to UNAVAILABLE (see
 * below) rather than landing wrong data — a wrong guess fails safe — but the
 * field names should be re-verified against a real captured profile page
 * before this connector is trusted in production.
 *
 * `orderField` is null: Pinterest gives no reliable per-pin ordering key on
 * this surface, so this stream can NEVER dominate and NEVER delete
 * (StreamSpec::mayDelete() is false regardless of profile) — correct, since
 * we can never tell "this pin is gone" apart from "this pin is simply
 * further down in an unordered grid we only partially captured".
 *
 * A 200 response whose state cannot be found/parsed at all — OR that parses
 * fine but yields zero pin-shaped objects — both degrade to UNAVAILABLE,
 * never to an empty stream: unlike Bandcamp/Vimeo/YoutubeRss/Substack, this
 * extraction is a best-effort structural guess rather than a documented
 * response shape, so "found nothing" is far more likely to mean "the guess
 * missed a layout change" than "this account genuinely has zero pins" — and
 * a layout change must never look like the account deleted everything (C5).
 */
class PinterestConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('pinterest'),
            identifierKind: 'username',
            hosts: ['pinterest.com', '*.pinterest.com', '*.pinimg.com'],
            streams: [
                'media' => new StreamSpec(
                    name: 'media',
                    target: 'media',
                    profile: SourceProfile::Feed,
                    // A pin with no id/url is not renderable; landing it
                    // would poison the projection.
                    requires: ['id', 'url'],
                    // Pinterest rotates a cache-busting query on pin image
                    // URLs; stripping it keeps an unchanged pin from looking
                    // like a content change every run.
                    volatile: ['image_url?query'],
                    // Null on purpose (see class docblock): no reliable
                    // per-pin order exists on this surface, so this stream
                    // must NEVER be able to delete.
                    orderField: null,
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 43200,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $username = trim($pull->identifier, '/');
        $response = $io->get("https://www.pinterest.com/{$username}/");

        if ($response['status'] !== 200 || $response['body'] === '') {
            // A failed fetch is UNAVAILABLE, never "this profile has no
            // pins" — emitting nothing here would let absence-folding
            // conclude every pin was deleted (C5).
            yield new Unavailable("profile page returned {$response['status']}", $response['status']);

            return;
        }

        $items = $this->parseEmbeddedPins($response['body']);

        if ($items === []) {
            // Deliberately UNAVAILABLE, not a Note-and-empty-stream: see the
            // class docblock. A best-effort structural extraction that finds
            // nothing cannot be trusted to mean "genuinely no pins" the way
            // a documented, fully-enumerable response shape can.
            yield new Unavailable('could not find any pin data in the embedded page state', $response['status']);

            return;
        }

        foreach ($items as $item) {
            yield new Record('media', $item['id'], $item);
        }

        // Coverage::unknown(), never exhaustive or a prefix: mirrors
        // GoogleBusinessConnector's own Feed-profile `media` stream, which
        // has the identical shape (a vendor-curated subset with no per-item
        // order field). Informational only — mayDelete() is already false
        // via the null orderField above — but honest about what this
        // surface actually lets us claim.
        yield new Covered('media', Coverage::unknown());
    }

    /** @return list<array<string, mixed>> */
    private function parseEmbeddedPins(string $html): array
    {
        $seen = [];
        foreach ($this->jsonScripts($html) as $data) {
            $this->collectPinNodes($data, $seen);
        }

        return array_values($seen);
    }

    /**
     * Every parseable <script type="application/json"> payload on the page —
     * mirrors PinterestScraper::jsonScripts() exactly.
     *
     * @return list<array<array-key, mixed>>
     */
    private function jsonScripts(string $html): array
    {
        $payloads = [];
        if (preg_match_all('~<script[^>]+type=["\']application/json["\'][^>]*>(.*?)</script>~si', $html, $m)) {
            foreach ($m[1] as $block) {
                $data = json_decode(trim($block), true);
                if (is_array($data)) {
                    $payloads[] = $data;
                }
            }
        }

        return $payloads;
    }

    /**
     * Depth-first search collecting every pin-shaped node, keyed by id to
     * dedupe (the same pin object commonly recurs at multiple paths inside
     * the state tree — e.g. a board summary and a feed list both reference
     * it). Mirrors PinterestScraper::findUserNode()'s traversal exactly,
     * generalised to collect every match instead of returning the first.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<array-key, array<string, mixed>>  $seen
     */
    private function collectPinNodes(array $node, array &$seen): void
    {
        $id = $node['id'] ?? null;
        $imageUrl = $this->extractPinImageUrl($node);

        if ((is_string($id) || is_int($id)) && $id !== '' && $imageUrl !== null) {
            $key = (string) $id;
            if (! isset($seen[$key])) {
                $title = is_string($node['grid_title'] ?? null) ? trim($node['grid_title'])
                    : (is_string($node['title'] ?? null) ? trim($node['title']) : null);

                $seen[$key] = [
                    'id' => $key,
                    'url' => "https://www.pinterest.com/pin/{$key}/",
                    'image_url' => $imageUrl,
                    'title' => $title !== '' ? $title : null,
                ];
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectPinNodes($value, $seen);
            }
        }
    }

    /**
     * Pin media specifically uses a size-keyed `images` dict (e.g.
     * "orig"/"236x") — distinct from the flat image_xlarge_url/
     * image_large_url fields PinterestScraper's User node uses, which keeps
     * this from cross-matching a user object that also happens to carry a
     * generic id.
     *
     * @param  array<array-key, mixed>  $node
     */
    private function extractPinImageUrl(array $node): ?string
    {
        $images = $node['images'] ?? null;
        if (! is_array($images)) {
            return null;
        }

        foreach (['orig', '736x', '564x', '474x', '236x'] as $size) {
            $url = $images[$size]['url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }
}
