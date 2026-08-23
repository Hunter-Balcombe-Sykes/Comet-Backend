<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Concerns\RefreshesLatestTile;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\DeferredConnect;
use App\Services\Platforms\YoutubeScraper;

// YouTube connect: channel handle/URL → channel's auto-latest video tile.
// Moved verbatim from YoutubeController.
class YoutubeConnect implements DeferredConnect
{
    use RefreshesLatestTile;

    private const FLAT_TILE_FIELDS = ['name', 'description', 'link', 'thumbnail'];

    // Characters that CANNOT appear in ANY real YouTube handle, in ANY script —
    // used by identify() to reject garbage before it becomes a pending row.
    // Deliberately a BLOCKLIST, not a charset allowlist: YouTube handles admit
    // letters and numbers from 75+ supported languages (Latin, CJK, Cyrillic,
    // Arabic, ...), plus four separators — underscore, hyphen, period, and the
    // Latin middle dot U+00B7 (per YouTube Help, "Learn about YouTube
    // handles") — so any positive character class risks silently rejecting a
    // real user's real handle in a script it didn't anticipate (this constant
    // used to be exactly that mistake: an ASCII-only allowlist). Whitespace
    // and "/" are the only characters genuinely impossible in a handle: the
    // separator set above never includes a space, and "/" is reserved for URL
    // path boundaries — a bare handle is definitionally not a path. Nothing
    // else is gated here, INCLUDING LENGTH: YouTube's general bound is 3-30
    // characters but narrower scripts (e.g. Han/Hangul) go as low as 1, so no
    // single length check is safe for every script; the upstream connectInput
    // rule (`max:200` on the raw request field) is the only length bound.
    // identify() does NOT validate that a handle exists — that is the fetch's
    // job, both here and in resolve(). A garbage string that slips past this
    // gate (e.g. "!!!###") costs one wasted job and an honest "couldn't find
    // that channel" from the fetch; that is the correct trade — a false
    // reject here tells a real user their real channel is invalid, and that
    // cost is far higher than one wasted fetch.
    private const IMPOSSIBLE_HANDLE_CHARS = '~[\s/]~u';

    public function __construct(private readonly YoutubeScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $handle = $this->scraper->normalizeHandle($input);
        if ($handle === '') {
            return ConnectResult::fail();
        }

        // One channel-page fetch for id + avatar; the feed is then reached by
        // the raw UC… id, which channelIdFrom() short-circuits without a second
        // page fetch. A pasted /channel/UC… URL already IS the id — no page
        // exists to read an avatar from, and that is fine: no avatar, no lie.
        $profile = preg_match('~^UC[A-Za-z0-9_-]{22}$~', $handle)
            ? ['id' => $handle, 'avatar' => null]
            : $this->scraper->fetchChannelProfile($handle);
        if ($profile === null) {
            return ConnectResult::fail('Could not find that YouTube channel or its latest video.', 404);
        }

        $videos = $this->scraper->fetchRecentVideos($profile['id']);
        if (empty($videos)) {
            return ConnectResult::fail('Could not find that YouTube channel or its latest video.', 404);
        }
        $latest = $videos[0];

        return ConnectResult::ok([
            'handle' => $handle,
            // The channel's own face for the dashboard's connect summary and
            // connections table (ConnectionDisplayName::avatarFor reads
            // `avatarUrl` before it falls back to the latest video's thumbnail).
            ...($profile['avatar'] !== null ? ['avatarUrl' => $profile['avatar']] : []),
            // Flat fields retained for partna-pages + back-compat; nested
            // `latest` is the canonical shape.
            ...$this->flatTileFields($latest, self::FLAT_TILE_FIELDS),
            'latest' => $latest,
            // Warm the picker's private snapshot at connect time (HighlightsPicker::
            // SNAPSHOT_KEY) so the picker is fast on the very first open, not just
            // after the first 12h refresh.
            'recent' => array_slice($videos, 0, 15),
        ], $handle);
    }

    // DeferredConnect — no network. Writes exactly what YoutubeFetch reads
    // (handle). Adds the IMPOSSIBLE_HANDLE_CHARS post-normalize check (see the
    // const's docblock) — this is the one platform where identify() rejects
    // something resolve() wouldn't have, because resolve()'s live fetch has no
    // deferred-split equivalent to fall back on. The check is intentionally
    // narrow (impossible characters only, no charset allowlist, no length
    // bound) and does NOT attempt to confirm the handle is real — that stays
    // the fetch's job. On rejection returns the SAME descriptor message as
    // every other parse failure ('Enter your YouTube channel.') — fail() with
    // no message resolves to $descriptor->connectErrorMessage() at the
    // controller, so this is not a new wording, just a narrower gate.
    public function identify(string $input): ConnectResult
    {
        $handle = $this->scraper->normalizeHandle($input);
        if ($handle === '' || preg_match(self::IMPOSSIBLE_HANDLE_CHARS, $handle) === 1) {
            return ConnectResult::fail();
        }

        return ConnectResult::ok(['handle' => $handle], $handle);
    }
}
