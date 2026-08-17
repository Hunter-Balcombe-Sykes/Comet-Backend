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
use App\Ingest\Support\Fields;

/**
 * Instagram via the paid Apify profile actor (plan §11) — OFF THE SCHEDULER by
 * construction, not by convention: CostClass::Actor means SourceProvisioner
 * provisions the source with auto_sync=false, and SourceScheduler::scoreDue()
 * selects only auto_sync=true, so the scheduler never touches it.
 *
 * A run therefore happens on exactly ONE trigger: `eagerOnConnect` below, which
 * fires once when the source row is first created. This is not a redundant
 * restatement of the paragraph above — from 2026-07-28 to 2026-08-17 that
 * sentence read "a run happens only on an explicit manual/connect trigger" and
 * NO such trigger existed in the codebase. The connector was unreachable, and a
 * pre-account build's scraped grid never reached any surface. Do not delete the
 * eagerOnConnect line believing the scheduler will pick this up; it will not.
 *
 * The hard cap lives where the money moves: ApifyBudget's per-actor + global
 * daily caps (config partna.limits.apify), claimed by InstagramActorDriver
 * immediately before the run. There is deliberately NO per-user cooldown —
 * `partna.instagram.apify_cooldown_seconds` is vestigial config with no reader,
 * and InstagramController documents the same decision for the connect path.
 * This connector only DESCRIBES the effect, and a refused/failed effect verdict
 * folds into Unavailable exactly like an unreachable vendor.
 *
 * `hosts` is EMPTY on purpose: the connector never fetches Instagram over
 * HTTP — everything arrives through the ledgered actor effect, and the
 * media bytes themselves are mirrored to R2 by the driver (owned-bytes
 * policy §12), never hot-linked from Instagram's expiring CDN URLs.
 *
 * One stream off the actor result:
 *   - `media` (Feed): the recent grid. A carousel (Sidecar) is ONE media
 *     record whose `images` list carries every child frame (plan §6: one
 *     item, N gallery rows). Pinned posts are re-sorted by timestamp so a
 *     pinned-but-old post cannot masquerade as the newest.
 *
 * Field names drift across actor versions (raw GraphQL snake_case on
 * today's figue actor, camelCase historically) — every read tolerates both,
 * mirroring what InstagramScraper learned in production.
 */
class InstagramConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('instagram'),
            identifierKind: 'username',
            hosts: [],
            streams: [
                'media' => new StreamSpec(
                    name: 'media',
                    target: 'media',
                    profile: SourceProfile::Feed,
                    requires: ['shortcode'],
                    // Instagram CDN URLs carry rotating signatures; without
                    // this every run would look like a full change.
                    volatile: ['display_url?query', 'video_url?query'],
                    orderField: 'taken_at',
                ),
            ],
            cost: CostClass::Actor,
            defaultIntervalSeconds: 604800,
            // The connect trigger this connector's docblock has always claimed.
            // Until 2026-08-17 no such trigger existed anywhere in the codebase,
            // so an instagram source was provisioned auto_sync=false and then
            // never ran — a pre-account build's scraped grid reached no surface
            // at all. Costs a second actor call per connect, on top of the
            // build-time InstagramConnectionSeeder scrape; capped by ApifyBudget,
            // which InstagramActorDriver claims immediately before the run.
            eagerOnConnect: true,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $username = strtolower(ltrim(trim($pull->identifier), '@'));

        $effect = $io->effect('actor', 'instagram', [
            'username' => $username,
            'include_posts' => true,
        ]);

        if (($effect['status'] ?? null) !== 'ok') {
            // A refused/abandoned/failed ledger verdict is the budget doing
            // its job, not a crash — same fold as an unreachable vendor.
            yield new Unavailable("instagram actor effect returned status '{$effect['status']}'");

            return;
        }

        $profile = $this->profileItem($effect['data']);
        if ($profile === null) {
            yield new Unavailable('instagram actor returned no usable profile item');

            return;
        }

        yield from $this->mediaMessages($profile, $pull);
    }

    /**
     * The dataset's profile item — or null for an empty/error-shaped result
     * (the actor returns a profile-shaped item with null fields plus `error`
     * for unknown/private accounts).
     *
     * @return array<string, mixed>|null
     */
    private function profileItem(mixed $data): ?array
    {
        $item = is_array($data) ? ($data[0] ?? $data) : null;
        if (! is_array($item) || $item === []) {
            return null;
        }
        if (isset($item['error']) && $item['error'] !== '') {
            return null;
        }

        return $item;
    }

    /** @param array<string, mixed> $profile */
    private function mediaMessages(array $profile, Pull $pull): iterable
    {
        $posts = $profile['latestPosts'] ?? $profile['latest_posts'] ?? null;
        if (! is_array($posts)) {
            // Post-less actor output is a config/actor-version condition, not
            // an emptied account — no coverage, nothing tombstones.
            yield new Note('no_posts_field', 'Actor result carried no latestPosts list');

            return;
        }

        $items = [];
        foreach ($posts as $post) {
            $item = $this->mapPost($post);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            yield new Note('no_posts', 'No posts parsed from the actor result');

            return;
        }

        // Apify returns pinned posts first — order by recency, not array
        // order, so a pinned-but-old post cannot claim the top of the feed.
        usort($items, static fn (array $a, array $b) => strcmp((string) ($b['taken_at'] ?? ''), (string) ($a['taken_at'] ?? '')));

        $limit = $pull->scopeLimit();
        if ($limit !== null) {
            $items = array_slice($items, 0, $limit);
        }

        foreach ($items as $item) {
            yield new Record('media', $item['shortcode'], $item);
        }

        // The grid is only ever the recent window — a prefix down to the
        // oldest post actually seen, never the whole account (C5).
        $dates = array_filter(array_column($items, 'taken_at'));
        yield new Covered('media', Coverage::prefix($dates === [] ? null : min($dates), count($items)));
    }

    /** @return array<string, mixed>|null */
    private function mapPost(mixed $post): ?array
    {
        if (! is_array($post)) {
            return null;
        }

        $shortcode = Fields::firstString($post, ['shortCode', 'shortcode', 'short_code', 'code']);
        if ($shortcode === null) {
            return null;
        }

        $cover = Fields::firstString($post, ['displayUrl', 'display_url', 'thumbnailUrl', 'thumbnail_url']);
        $type = Fields::firstString($post, ['type', '__typename']);

        // A carousel is ONE record: every child frame's display URL in order.
        $images = [];
        foreach (['childPosts', 'child_posts', 'sidecarItems', 'sidecar_items'] as $childKey) {
            if (is_array($post[$childKey] ?? null)) {
                foreach ($post[$childKey] as $child) {
                    $childUrl = is_array($child) ? Fields::firstString($child, ['displayUrl', 'display_url']) : null;
                    if ($childUrl !== null) {
                        $images[] = $childUrl;
                    }
                }
                break;
            }
        }
        if ($images === [] && $cover !== null) {
            $images = [$cover];
        }

        return array_filter([
            'shortcode' => $shortcode,
            'type' => $type,
            'caption' => Fields::firstString($post, ['caption', 'edge_media_to_caption.edges.0.node.text']),
            'taken_at' => $this->takenAt($post),
            'url' => 'https://www.instagram.com/p/'.$shortcode.'/',
            'display_url' => $cover,
            'video_url' => Fields::firstString($post, ['videoUrl', 'video_url']),
            'images' => $images === [] ? null : $images,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $post */
    private function takenAt(array $post): ?string
    {
        $timestamp = Fields::firstString($post, ['timestamp', 'taken_at_timestamp', 'takenAt']);
        if ($timestamp === null) {
            return null;
        }
        if (preg_match('/^\d+$/', $timestamp)) {
            // Raw GraphQL epoch seconds → ISO, deterministically.
            return gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp);
        }

        return $timestamp;
    }
}
