<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Bookmark;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Deferred;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Ingest\Support\Html;

/**
 * Twitch, two streams two methods (plan §11):
 *
 *   - `channel`: the public channel page server-renders its og: tags for any
 *     client — display name, avatar, bio, keyless. One card, exhaustive.
 *   - `vods`: Helix Get Videos under the same client-credentials app token
 *     that already exists for the live badge (services.twitch.*). Get
 *     Users(login)→id is cached in the stream cursor via Bookmark so steady
 *     state is one token mint + one videos call. The live badge itself stays
 *     on its own 2-minute lane (LiveStatusPoller) — this connector never
 *     touches liveness.
 *
 * The token is minted per run through Io (the connector owns no Redis and
 * must not share StreamingTokenManager's cache — purity). App tokens do not
 * invalidate each other and the videos cadence is 12h, so the extra mint is
 * two requests per source per day. Missing credentials degrade to
 * Unavailable: the stream backs off 1h→7d rather than erroring forever.
 */
class TwitchConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('twitch'),
            identifierKind: 'login',
            hosts: ['twitch.tv', 'www.twitch.tv', 'm.twitch.tv', 'api.twitch.tv', 'id.twitch.tv'],
            streams: [
                'channel' => new StreamSpec(
                    name: 'channel',
                    target: 'channel',
                    profile: SourceProfile::Identity,
                    requires: ['name', 'url'],
                    volatile: [],
                    orderField: null,
                ),
                'vods' => new StreamSpec(
                    name: 'vods',
                    target: 'video',
                    profile: SourceProfile::Feed,
                    requires: ['id', 'title', 'url'],
                    // VOD thumbnails move across CDN shards without meaning.
                    volatile: ['thumbnail?query'],
                    orderField: 'published',
                ),
            ],
            cost: CostClass::Free,
            // Plan §11: VODs on a 12h cadence.
            defaultIntervalSeconds: 43200,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $login = strtolower(ltrim(trim($pull->identifier), '@'));

        yield from $pull->stream->name === 'channel'
            ? $this->channelMessages($login, $io)
            : $this->vodMessages($login, $pull, $io);
    }

    /** @return iterable<Message> */
    private function channelMessages(string $login, Io $io): iterable
    {
        $response = $io->get("https://www.twitch.tv/{$login}");

        if ($response['status'] !== 200 || $response['body'] === '') {
            // A failed fetch is UNAVAILABLE, never "the channel is gone" (C5).
            yield new Unavailable("channel page returned {$response['status']}", $response['status']);

            return;
        }

        $title = Html::metaContent($response['body'], 'og:title');
        if ($title === null) {
            // A 200 without og tags is a consent wall or layout change, not
            // a deleted channel.
            yield new Unavailable('channel page carried no og:title');

            return;
        }

        // og:title is "DisplayName - Twitch".
        $name = trim((string) preg_replace('~\s*-\s*Twitch\s*$~i', '', $title)) ?: $login;

        yield new Record('channel', $login, array_filter([
            'name' => $name,
            'url' => "https://www.twitch.tv/{$login}",
            'handle' => $login,
            'avatar' => Html::metaContent($response['body'], 'og:image'),
            'description' => Html::metaContent($response['body'], 'og:description'),
            'embed' => ['provider' => 'twitch', 'key' => $login],
        ], static fn ($v) => $v !== null));

        // One account card, fully seen.
        yield new Covered('channel', Coverage::exhaustive());
    }

    /** @return iterable<Message> */
    private function vodMessages(string $login, Pull $pull, Io $io): iterable
    {
        $clientId = (string) config('services.twitch.client_id');
        $clientSecret = (string) config('services.twitch.client_secret');
        if ($clientId === '' || $clientSecret === '') {
            yield new Unavailable('twitch helix credentials are not configured');

            return;
        }

        $token = $this->mintAppToken($io, $clientId, $clientSecret);
        if ($token === null) {
            yield new Unavailable('twitch app-token mint failed');

            return;
        }
        $headers = ['Authorization' => "Bearer {$token}", 'Client-ID' => $clientId];

        $cachedLogin = $pull->cursor['login'] ?? null;
        $cachedUserId = $pull->cursor['user_id'] ?? null;
        $userId = ($cachedLogin === $login && is_string($cachedUserId) && $cachedUserId !== '')
            ? $cachedUserId
            : $this->resolveUserId($io, $headers, $login);
        $resolvedFresh = $userId !== null && $userId !== $cachedUserId;

        if ($userId === null) {
            yield new Unavailable("could not resolve twitch login '{$login}' to a user id");

            return;
        }

        $response = $io->get('https://api.twitch.tv/helix/videos?'.http_build_query([
            'user_id' => $userId,
            'first' => 30,
            'type' => 'archive',
        ]), $headers);

        if ($response['status'] === 429) {
            // Helix rate limit: release the claim, come back later — never a
            // failure and never a stranded-looking run.
            yield new Deferred(120, 'helix rate limited');

            return;
        }

        if ($response['status'] !== 200 || $response['body'] === '') {
            yield new Unavailable("helix videos returned {$response['status']}", $response['status']);

            return;
        }

        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded) || ! array_key_exists('data', $decoded) || ! is_array($decoded['data'])) {
            yield new Unavailable('helix videos response had no data array');

            return;
        }

        $items = [];
        foreach ($decoded['data'] as $video) {
            $item = $this->mapVideo($video);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            // Genuinely no public archives (or all sub-only): not deletion
            // evidence — no Coverage, nothing can be tombstoned.
            yield new Note('no_vods', 'Helix returned no archive videos for this channel');
        } else {
            $limit = $pull->scopeLimit();
            if ($limit !== null) {
                $items = array_slice($items, 0, $limit);
            }

            foreach ($items as $item) {
                yield new Record('vods', $item['id'], $item);
            }

            // Newest-first page of 30: a short page IS the whole archive,
            // a full page is only a prefix down to the oldest seen.
            $dates = array_filter(array_column($items, 'published'));
            yield new Covered('vods', (count($decoded['data']) < 30 && $limit === null)
                ? Coverage::exhaustive()
                : Coverage::prefix($dates === [] ? null : min($dates), count($items)));
        }

        if ($resolvedFresh) {
            // Cache Get Users(login)→id so the next run skips the hop.
            yield new Bookmark('vods', ['login' => $login, 'user_id' => $userId]);
        }
    }

    private function mintAppToken(Io $io, string $clientId, string $clientSecret): ?string
    {
        $response = $io->post((string) config('services.twitch.token_url', 'https://id.twitch.tv/oauth2/token'), [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ]);

        if ($response['status'] !== 200) {
            return null;
        }
        $decoded = json_decode($response['body'], true);
        $token = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @param array<string, string> $headers */
    private function resolveUserId(Io $io, array $headers, string $login): ?string
    {
        $response = $io->get('https://api.twitch.tv/helix/users?'.http_build_query(['login' => $login]), $headers);
        if ($response['status'] !== 200) {
            return null;
        }

        $decoded = json_decode($response['body'], true);
        $id = is_array($decoded) ? ($decoded['data'][0]['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @return array<string, mixed>|null */
    private function mapVideo(mixed $video): ?array
    {
        if (! is_array($video)) {
            return null;
        }
        $id = is_string($video['id'] ?? null) ? $video['id'] : '';
        $title = is_string($video['title'] ?? null) ? trim($video['title']) : '';
        if ($id === '' || $title === '') {
            return null;
        }

        return array_filter([
            'id' => $id,
            'title' => $title,
            'url' => is_string($video['url'] ?? null) ? $video['url'] : "https://www.twitch.tv/videos/{$id}",
            'published' => is_string($video['published_at'] ?? null)
                ? $video['published_at']
                : ($video['created_at'] ?? null),
            'thumbnail' => $this->thumbnail($video['thumbnail_url'] ?? null),
            'duration_seconds' => $this->durationSeconds($video['duration'] ?? null),
            'views' => is_numeric($video['view_count'] ?? null) ? (int) $video['view_count'] : null,
        ], static fn ($v) => $v !== null);
    }

    /** Helix returns a %{width}x%{height} template; a still-processing VOD returns ''. */
    private function thumbnail(mixed $template): ?string
    {
        if (! is_string($template) || $template === '') {
            return null;
        }

        return str_replace(['%{width}', '%{height}'], ['640', '360'], $template);
    }

    /** Helix duration grammar: "3h2m1s", any component optional. */
    private function durationSeconds(mixed $duration): ?int
    {
        if (! is_string($duration) || ! preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $duration, $m)) {
            return null;
        }
        $seconds = ((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);

        return $seconds > 0 ? $seconds : null;
    }
}
