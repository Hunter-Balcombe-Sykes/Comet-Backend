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
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * Facebook page posts via the paid Apify posts actor (T27c, 2026-08-28) —
 * the Instagram pattern: CostClass::Actor keeps it off the scheduler,
 * eagerOnConnect is the one trigger, SocialActorDriver claims the budget,
 * `hosts` is empty (nothing fetched from Facebook directly).
 *
 * One stream off the actor result:
 *   - `media` (Feed): posts that carry their OWN imagery, one `media` item
 *     each — a multi-photo post is ONE item whose gallery rows are the
 *     attached photos in order, exactly the Instagram carousel rule (§6).
 *     Text-only posts and bare reshares are skipped: the media pool is a
 *     gallery, and a caption with no picture has nothing to hang on it.
 *
 * Field shapes verified against a live apify~facebook-posts-scraper dataset
 * (2026-08-28): postId, url, time, text, media[] with __typename
 * Photo{photo_image.uri, thumbnail} / Video{thumbnail}, isVideo. fbcdn URLs
 * carry signed `oe=` expiries — volatile for the hash, and the projector
 * hands them to MediaMirror under an owned `facebook:` ref so the served
 * bytes outlive the signature.
 */
class FacebookConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('facebook'),
            identifierKind: 'url',
            hosts: [],
            streams: [
                'media' => new StreamSpec(
                    name: 'media',
                    target: 'media',
                    profile: SourceProfile::Feed,
                    requires: ['post_id'],
                    volatile: ['images.url?query'],
                    orderField: 'published_at',
                ),
            ],
            cost: CostClass::Actor,
            defaultIntervalSeconds: 604800,
            eagerOnConnect: true,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $pageUrl = trim($pull->identifier);
        if (preg_match('~^https://(www\.)?facebook\.com/~i', $pageUrl) !== 1) {
            yield new Unavailable('facebook identifier is not a facebook.com page URL');

            return;
        }

        $effect = $io->effect('actor', 'facebook', ['page_url' => $pageUrl]);

        if (($effect['status'] ?? null) !== 'ok') {
            yield new Unavailable("facebook actor effect returned status '{$effect['status']}'");

            return;
        }

        $items = [];
        foreach ((array) $effect['data'] as $row) {
            $item = is_array($row) ? $this->mapPost($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            // The page may genuinely post only text/reshares — a Note, no
            // coverage, nothing tombstones.
            yield new Note('no_media_posts', 'No own-imagery posts parsed from the actor result');

            return;
        }

        usort($items, static fn (array $a, array $b) => strcmp((string) ($b['published_at'] ?? ''), (string) ($a['published_at'] ?? '')));

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('media', $item['post_id'], $item);
        }

        // Only ever the recent window — a prefix, never the whole page (C5).
        $dates = array_filter(array_column($items, 'published_at'));
        yield new Covered('media', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /**
     * A post with its own imagery, or null for text-only posts and bare
     * reshares (media absent, sharedPost present — the pictures are someone
     * else's post).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapPost(array $row): ?array
    {
        $postId = is_string($row['postId'] ?? null) ? trim($row['postId']) : '';
        if ($postId === '') {
            return null;
        }

        $images = [];
        foreach ((array) ($row['media'] ?? []) as $media) {
            if (! is_array($media)) {
                continue;
            }
            $url = data_get($media, 'photo_image.uri') ?? ($media['thumbnail'] ?? null);
            if (is_string($url) && $url !== '') {
                $images[] = ['url' => $url];
            }
        }

        if ($images === []) {
            return null;
        }

        $text = is_string($row['text'] ?? null) ? trim($row['text']) : '';

        return array_filter([
            'post_id' => $postId,
            'url' => is_string($row['url'] ?? null) ? $row['url'] : null,
            'text' => $text !== '' ? $text : null,
            'published_at' => is_string($row['time'] ?? null) ? $row['time'] : null,
            'images' => $images,
            'is_video' => ($row['isVideo'] ?? null) === true ? true : null,
        ], static fn ($v) => $v !== null);
    }
}
