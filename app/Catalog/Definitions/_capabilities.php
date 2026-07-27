<?php

use App\Services\Platforms\Normalizers\DiscordNormalizer;
use App\Services\Platforms\Normalizers\FacebookNormalizer;
use App\Services\Platforms\Normalizers\KickNormalizer;
use App\Services\Platforms\Normalizers\LinkedinNormalizer;
use App\Services\Platforms\Normalizers\MediumNormalizer;
use App\Services\Platforms\Normalizers\RedditNormalizer;
use App\Services\Platforms\Normalizers\SnapchatNormalizer;
use App\Services\Platforms\Normalizers\TelegramNormalizer;
use App\Services\Platforms\Normalizers\ThreadsNormalizer;
use App\Services\Platforms\Normalizers\TiktokNormalizer;
use App\Services\Platforms\Normalizers\XNormalizer;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\Strategies\Connect\BandcampConnect;
use App\Services\Platforms\Strategies\Connect\NowBookitConnect;
use App\Services\Platforms\Strategies\Connect\OpenTableConnect;
use App\Services\Platforms\Strategies\Connect\PinterestConnect;
use App\Services\Platforms\Strategies\Connect\ResDiaryConnect;
use App\Services\Platforms\Strategies\Connect\SoundcloudConnect;
use App\Services\Platforms\Strategies\Connect\SpotifyConnect;
use App\Services\Platforms\Strategies\Connect\StravaConnect;
use App\Services\Platforms\Strategies\Connect\TwitchConnect;
use App\Services\Platforms\Strategies\Connect\UrlConnect;
use App\Services\Platforms\Strategies\Connect\VimeoConnect;
use App\Services\Platforms\Strategies\Connect\YoutubeConnect;
use App\Services\Platforms\Strategies\Connect\YoutubeMusicConnect;
use App\Services\Platforms\Strategies\Fetch\AppleMusicFetch;
use App\Services\Platforms\Strategies\Fetch\ApplePodcastFetch;
use App\Services\Platforms\Strategies\Fetch\BandcampFetch;
use App\Services\Platforms\Strategies\Fetch\EventbriteFetch;
use App\Services\Platforms\Strategies\Fetch\FreshaConnectFetch;
use App\Services\Platforms\Strategies\Fetch\FreshaFetch;
use App\Services\Platforms\Strategies\Fetch\GoogleBusinessFetch;
use App\Services\Platforms\Strategies\Fetch\HumanitixFetch;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;
use App\Services\Platforms\Strategies\Fetch\PinterestFetch;
use App\Services\Platforms\Strategies\Fetch\SkoolFetch;
use App\Services\Platforms\Strategies\Fetch\StravaFetch;
use App\Services\Platforms\Strategies\Fetch\TwitchFetch;
use App\Services\Platforms\Strategies\Fetch\VimeoFetch;
use App\Services\Platforms\Strategies\Fetch\YoutubeFetch;
use App\Services\Platforms\Strategies\Fetch\YoutubeMusicFetch;
use App\Services\Platforms\Strategies\Highlights\BandcampHighlights;
use App\Services\Platforms\Strategies\Highlights\VimeoHighlights;
use App\Services\Platforms\Strategies\Highlights\YoutubeHighlights;
use App\Services\Platforms\Strategies\Highlights\YoutubeMusicHighlights;

// Capability manifest data: versioned name => [class, namedCtorArgs] | Closure.
// Read via App\Catalog\CapabilityManifest — never require this directly.
// P1: every entry maps to the EXISTING strategy class (behaviour unchanged);
// P3+ swaps entries to the connector runtime without touching any surface.
// Closures are used where construction composes concrete collaborators
// (UrlConnect wraps a brand's invokable normalizer; OEmbedFetch takes a
// per-platform endpoint closure) — mirroring PlatformRegistryServiceProvider's
// own construction verbatim.
return [
    // ── Connect: core-11 socials via UrlConnect + invokable normalizer ──────
    'connect.discord.url.v1' => fn (): object => new UrlConnect(new DiscordNormalizer),
    'connect.facebook.url.v1' => fn (): object => new UrlConnect(new FacebookNormalizer),
    'connect.kick.url.v1' => fn (): object => new UrlConnect(new KickNormalizer),
    'connect.linkedin.url.v1' => fn (): object => new UrlConnect(new LinkedinNormalizer),
    'connect.medium.url.v1' => fn (): object => new UrlConnect(new MediumNormalizer),
    'connect.reddit.url.v1' => fn (): object => new UrlConnect(new RedditNormalizer),
    'connect.snapchat.url.v1' => fn (): object => new UrlConnect(new SnapchatNormalizer),
    'connect.telegram.url.v1' => fn (): object => new UrlConnect(new TelegramNormalizer),
    'connect.threads.url.v1' => fn (): object => new UrlConnect(new ThreadsNormalizer),
    'connect.tiktok.url.v1' => fn (): object => new UrlConnect(new TiktokNormalizer),
    'connect.x.url.v1' => fn (): object => new UrlConnect(new XNormalizer),

    // ── Connect: dedicated strategy classes (container-resolvable) ──────────
    'connect.bandcamp.url.v1' => [BandcampConnect::class, []],
    'connect.nowbookit.url.v1' => [NowBookitConnect::class, []],
    'connect.opentable.url.v1' => [OpenTableConnect::class, []],
    'connect.pinterest.url.v1' => [PinterestConnect::class, []],
    'connect.resdiary.url.v1' => [ResDiaryConnect::class, []],
    'connect.soundcloud.url.v1' => [SoundcloudConnect::class, []],
    'connect.spotify.url.v1' => [SpotifyConnect::class, []],
    'connect.strava.url.v1' => [StravaConnect::class, []],
    'connect.twitch.url.v1' => [TwitchConnect::class, []],
    'connect.vimeo.url.v1' => [VimeoConnect::class, []],
    'connect.youtube.url.v1' => [YoutubeConnect::class, []],
    'connect.youtube_music.url.v1' => [YoutubeMusicConnect::class, []],

    // ── Fetch ────────────────────────────────────────────────────────────────
    'fetch.apple_music.itunes.v1' => [AppleMusicFetch::class, []],
    'fetch.apple_podcasts.itunes.v1' => [ApplePodcastFetch::class, []],
    'fetch.bandcamp.scrape.v1' => [BandcampFetch::class, []],
    'fetch.eventbrite.scrape.v1' => [EventbriteFetch::class, []],
    'fetch.fresha.scrape.v1' => [FreshaFetch::class, []],
    'connect_fetch.fresha.scrape.v1' => [FreshaConnectFetch::class, []],
    'fetch.google_business.places.v1' => [GoogleBusinessFetch::class, []],
    'fetch.humanitix.scrape.v1' => [HumanitixFetch::class, []],
    'fetch.pinterest.scrape.v1' => [PinterestFetch::class, []],
    'fetch.skool.scrape.v1' => [SkoolFetch::class, []],
    'fetch.soundcloud.oembed.v1' => fn (): object => new OEmbedFetch(
        app(OEmbedService::class),
        fn (string $link): string => 'https://soundcloud.com/oembed?format=json&url='.rawurlencode($link),
        'soundcloud',
    ),
    'fetch.spotify.oembed.v1' => fn (): object => new OEmbedFetch(
        app(OEmbedService::class),
        fn (string $link): string => 'https://open.spotify.com/oembed?url='.rawurlencode($link),
        'spotify',
    ),
    'fetch.strava.scrape.v1' => [StravaFetch::class, []],
    'fetch.twitch.scrape.v1' => [TwitchFetch::class, []],
    'fetch.vimeo.api.v1' => [VimeoFetch::class, []],
    'fetch.youtube.scrape.v1' => [YoutubeFetch::class, []],
    'fetch.youtube_music.scrape.v1' => [YoutubeMusicFetch::class, []],

    // ── Highlights ───────────────────────────────────────────────────────────
    'highlights.bandcamp.v1' => [BandcampHighlights::class, []],
    'highlights.vimeo.v1' => [VimeoHighlights::class, []],
    'highlights.youtube.v1' => [YoutubeHighlights::class, []],
    'highlights.youtube_music.v1' => [YoutubeMusicHighlights::class, []],
];
