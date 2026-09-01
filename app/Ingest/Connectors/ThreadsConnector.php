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
 * Threads via the ScrapeCreators vendor lane (Item 10a, 2026-09-01) — the
 * Instagram pattern with the Pinterest substitution: the billed effect is
 * ('vendor', 'threads') on ThreadsVendorDriver, because threads has no Apify
 * actor behind it and the vendor is the ONLY lane (a miss must stay a miss).
 * CostClass::Actor still applies — "third-party billed per invocation" keeps
 * this off the scheduler by construction (auto_sync=false at provisioning),
 * and eagerOnConnect is the ONE trigger, exactly the InstagramConnector
 * contract. The daily ceiling is ScrapeCreatorsBudget's 'threads' source cap,
 * claimed per call inside the driver. `hosts` is empty because nothing here
 * fetches Threads over HTTP.
 *
 * One stream off the vendor rows:
 *   - `media` (Feed → media pool): the account's last ~20-30 public posts.
 *     A carousel is ONE record whose gallery frames are the child posters in
 *     order (the Instagram sidecar precedent); a text-only thread keeps its
 *     record with media:[] and the projector — not this class — decides
 *     whether it enters the media pool. Rows re-sort by taken_at because the
 *     wire can carry pinned posts first (pinned_post_info exists upstream),
 *     and a pinned-but-old post must not masquerade as the newest.
 *
 * Every asset URL in this lane is IG-signed and expiring (Threads rides
 * Instagram's CDN), which cuts twice:
 *   - hashing: the path half is stable, only the signature query rotates —
 *     `media.url?query` copies Instagram's volatile stance, or every run
 *     would look like a full change;
 *   - serving: hot-linking is forbidden. Each entry carries its stable ref
 *     in the owned `threads:` namespace (minted by ThreadsPostsNormalizer),
 *     which MediaMirror::OWNED_REF_PREFIXES recognises so the bytes are
 *     mirrored to R2 — an unlisted ref would fail safe as never-mirrored,
 *     and on an expiring CDN that means never-rendered.
 */
class ThreadsConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('threads'),
            identifierKind: 'username',
            hosts: [],
            streams: [
                'media' => new StreamSpec(
                    name: 'media',
                    target: 'media',
                    profile: SourceProfile::Feed,
                    requires: ['id'],
                    // IG-signed CDN URLs carry rotating signatures on every
                    // frame and mp4; without this every run would look like a
                    // full change. DocHasher descends the media list, so one
                    // path covers cover, gallery and video entries alike.
                    volatile: ['media.url?query'],
                    orderField: 'taken_at',
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
        $username = strtolower(ltrim(trim($pull->identifier), '@'));

        $effect = $io->effect('vendor', 'threads', ['username' => $username]);

        if (($effect['status'] ?? null) !== 'ok') {
            // A refused/abandoned/failed ledger verdict is the budget doing
            // its job, not a crash — same fold as an unreachable vendor.
            yield new Unavailable("threads vendor effect returned status '{$effect['status']}'");

            return;
        }

        $items = [];
        foreach ((array) $effect['data'] as $row) {
            $item = is_array($row) ? $this->mapRow($row) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('no_posts', 'No posts parsed from the vendor result');

            return;
        }

        // The vendor can serve pinned posts first — order by recency, not
        // array order, so a pinned-but-old post cannot claim the top of the
        // feed (the Instagram/TikTok rule).
        usort($items, static fn (array $a, array $b) => strcmp((string) ($b['taken_at'] ?? ''), (string) ($a['taken_at'] ?? '')));

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('media', $item['id'], $item);
        }

        // The feed is only ever the recent window — a prefix down to the
        // oldest post actually seen, never the whole account (C5).
        $dates = array_filter(array_column($items, 'taken_at'));
        yield new Covered('media', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /**
     * Re-validate one driver row. The effect answer can arrive from the
     * ledger's freshness cache, written by an older driver — so the connector
     * rebuilds the doc from the keys it owns rather than trusting the blob,
     * exactly as PinterestConnector::mapPin does. Media entries must already
     * be in projector vocabulary with owned `threads:` refs; anything else is
     * not ours to mirror and drops here.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapRow(array $row): ?array
    {
        $id = is_string($row['id'] ?? null) ? trim($row['id']) : '';
        if (preg_match('/^\d+$/', $id) !== 1) {
            return null;
        }

        $media = [];
        foreach (is_array($row['media'] ?? null) ? $row['media'] : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $role = $entry['role'] ?? null;
            $url = $entry['url'] ?? null;
            $ref = $entry['ref'] ?? null;
            if (in_array($role, ['cover', 'gallery', 'video'], true)
                && is_string($url) && str_starts_with($url, 'https://')
                && is_string($ref) && str_starts_with($ref, 'threads:')) {
                $media[] = ['role' => $role, 'url' => $url, 'ref' => $ref];
            }
        }

        return array_filter([
            'id' => $id,
            'code' => is_string($row['code'] ?? null) ? $row['code'] : null,
            'caption' => is_string($row['caption'] ?? null) ? $row['caption'] : null,
            'taken_at' => is_string($row['taken_at'] ?? null) ? $row['taken_at'] : null,
            'url' => is_string($row['url'] ?? null) ? $row['url'] : null,
            'is_video' => ($row['is_video'] ?? null) === true,
            'like_count' => is_numeric($row['like_count'] ?? null) ? (int) $row['like_count'] : null,
            'reply_count' => is_numeric($row['reply_count'] ?? null) ? (int) $row['reply_count'] : null,
            'repost_count' => is_numeric($row['repost_count'] ?? null) ? (int) $row['repost_count'] : null,
            // Deliberately kept when empty: a text-only thread's record
            // survives with media:[] — pool entry is the projector's call.
            'media' => $media,
        ], static fn ($v) => $v !== null);
    }
}
