<?php

namespace App\Providers;

use App\Http\Controllers\Api\Platforms\GoogleBusinessController;
use App\Http\Controllers\Api\Platforms\SkoolController;
use App\Http\Resources\Platforms\AppleMusicConnectionResource;
use App\Http\Resources\Platforms\ApplePodcastConnectionResource;
use App\Http\Resources\Platforms\BandcampConnectionResource;
use App\Http\Resources\Platforms\FreshaSelectionResource;
use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Http\Resources\Platforms\InstagramConnectionResource;
use App\Http\Resources\Platforms\LinkConnectionResource;
use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Http\Resources\Platforms\NowBookitConnectionResource;
use App\Http\Resources\Platforms\OpenTableConnectionResource;
use App\Http\Resources\Platforms\PinterestConnectionResource;
use App\Http\Resources\Platforms\ResDiaryConnectionResource;
use App\Http\Resources\Platforms\ShopBrandResource;
use App\Http\Resources\Platforms\SkoolConnectionResource;
use App\Http\Resources\Platforms\StravaConnectionResource;
use App\Http\Resources\Platforms\TileConnectionResource;
use App\Http\Resources\Platforms\TwitchConnectionResource;
use App\Http\Resources\Platforms\VimeoConnectionResource;
use App\Http\Resources\Platforms\YoutubeConnectionResource;
use App\Http\Resources\Platforms\YoutubeMusicConnectionResource;
use App\Jobs\Platforms\RefreshConnectionJob;
use App\Jobs\Platforms\ThrottledByProvider;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
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
use App\Services\Platforms\NowBookitService;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\OpenTableService;
use App\Services\Platforms\Payloads\CardPayload;
use App\Services\Platforms\Payloads\EventsAccountPayload;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\Payloads\SelectionPayload;
use App\Services\Platforms\Payloads\ShopPayload;
use App\Services\Platforms\Payloads\StandaloneEventPayload;
use App\Services\Platforms\PinterestScraper;
use App\Services\Platforms\Registry\PlatformCategory as Cat;
use App\Services\Platforms\Registry\PlatformDescriptor as PD;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\ResDiaryService;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\SkoolScraper;
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
use App\Services\Platforms\Strategies\Detect\HostMatch;
use App\Services\Platforms\Strategies\Detect\ServiceMatch;
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
use App\Services\Platforms\Strategies\Fetch\ShopFetch;
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
use App\Services\Platforms\StravaClubScraper;
use App\Services\Platforms\TwitchScraper;
use App\Services\Platforms\VimeoApi;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

// Binds the PlatformRegistry singleton and registers every platform the app
// supports today. This is the single place a platform is declared. In this plan
// the descriptors carry identity + Resource + refreshable flag only; live
// strategies attach as platforms migrate in later plans.
class PlatformRegistryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PlatformRegistry::class, function () {
            $r = new PlatformRegistry;

            // ── Link-only socials (LinkConnectionResource, no refresh) ──
            foreach ([
                'tiktok' => 'TikTok', 'facebook' => 'Facebook', 'x' => 'X',
                'linkedin' => 'LinkedIn', 'threads' => 'Threads', 'reddit' => 'Reddit',
                'snapchat' => 'Snapchat', 'discord' => 'Discord', 'telegram' => 'Telegram',
                'kick' => 'Kick', 'medium' => 'Medium',
            ] as $key => $label) {
                $r->register(PD::linkOnly($key, $label, LinkConnectionResource::class));
            }

            // ── Link-only connect strategies (migrated to GenericPlatformController) ──
            // Each platform's existing URL/handle normalizer, wrapped in UrlConnect,
            // plus its exact 422 message. get() returns the live descriptor; ->connect()
            // mutates it in place. A line is added here as each platform migrates.
            $r->get('x')->connect(new UrlConnect(new XNormalizer), 'Enter your X handle or profile URL (x.com/yourname).');
            $r->get('linkedin')->connect(new UrlConnect(new LinkedinNormalizer), 'Enter your LinkedIn profile URL (linkedin.com/in/yourname).');
            $r->get('threads')->connect(new UrlConnect(new ThreadsNormalizer), 'Enter your Threads handle or profile URL (threads.net/@yourname).');
            $r->get('reddit')->connect(new UrlConnect(new RedditNormalizer), 'Enter your Reddit username or community (u/yourname or r/yourcommunity).');
            $r->get('tiktok')->connect(new UrlConnect(new TiktokNormalizer), 'Enter your TikTok username or profile URL.');
            $r->get('facebook')->connect(new UrlConnect(new FacebookNormalizer), 'Enter your Facebook username or profile URL.');
            $r->get('snapchat')->connect(new UrlConnect(new SnapchatNormalizer), 'Enter your Snapchat username or profile URL (snapchat.com/add/yourname).');
            $r->get('discord')->connect(new UrlConnect(new DiscordNormalizer), 'Enter your Discord invite link or code (discord.gg/yourcode).');
            $r->get('telegram')->connect(new UrlConnect(new TelegramNormalizer), 'Enter your Telegram username or profile URL (t.me/yourname).');
            $r->get('kick')->connect(new UrlConnect(new KickNormalizer), 'Enter your Kick username or channel URL (kick.com/yourname).');
            $r->get('medium')->connect(new UrlConnect(new MediumNormalizer), 'Enter your Medium username or profile URL (medium.com/@yourname).');

            // Skool + Strava are link/card style under their own resources.
            $r->register(PD::make('skool')->label('Skool')->category(Cat::Education)->resource(SkoolConnectionResource::class));
            // CA-W4: attach the fetch strategy so ConnectFetchJob can complete a
            // pending row. Consumed ONLY by that job — skool stays non-refreshable
            // (no ->refreshable() call above), so ScheduledRefresh/the manual
            // refresh button never resolve this (PlatformDescriptor::refreshStrategy()
            // requires BOTH flags).
            $r->get('skool')->fetch(fn () => new SkoolFetch(app(SkoolScraper::class)));
            // The message ConnectFetchJob stores on the row when the deferred
            // fetch fails — verbatim from connect()'s own synchronous 404
            // message. Deliberately NOT ->deferredConnect(): that flag means
            // "this descriptor's ConnectStrategy implements DeferredConnect"
            // (RegistryConnectCoverageTest pins flag<=>instanceof for every
            // descriptor), but Skool has no ConnectStrategy at all — its
            // connect is bespoke (SkoolController::connect(), via
            // DefersBespokeConnect), never routed through
            // ConnectResolver/GenericPlatformController. Mirrors apple-music's
            // identical note above.
            $r->get('skool')->connectFetchError('Could not read that Skool community — check the URL.');
            $r->register(PD::make('strava')->label('Strava')->category(Cat::Content)->resource(StravaConnectionResource::class)->refreshable());
            // Attach the live fetch strategy (Plan 6). Consumed by the registry-driven refresher.
            $r->get('strava')->fetch(fn () => new StravaFetch(app(StravaClubScraper::class)));
            // Connect strategy + read-path DTO (FOUND-24) — parse-fail message is the
            // frozen 422 contract, copied verbatim from the deleted StravaController.
            // Strava's reads now go through FeedPayload too (it gained location/members).
            $r->get('strava')->connect(fn () => new StravaConnect(app(StravaClubScraper::class)), 'Enter your Strava club URL (strava.com/clubs/yourclub).');
            $r->get('strava')->payload(FeedPayload::class);
            // Deferred-connect seam (Phase 2, W4) — StravaConnect implements
            // DeferredConnect. Message copied verbatim from resolve()'s
            // fetch-stage failure.
            $r->get('strava')->deferredConnect()->connectFetchError('Could not read that Strava club page.');

            // ── oEmbed music (MusicEmbedConnectionResource, refreshable) ──
            foreach (['spotify' => 'Spotify', 'soundcloud' => 'SoundCloud'] as $key => $label) {
                $r->register(PD::oEmbed($key, $label, MusicEmbedConnectionResource::class));
            }
            // mixcloud + tidal are keyless embeds sharing the music-embed resource.
            $r->register(PD::oEmbed('mixcloud', 'Mixcloud', MusicEmbedConnectionResource::class)->refreshable(false));
            $r->register(PD::oEmbed('tidal', 'Tidal', MusicEmbedConnectionResource::class)->refreshable(false));

            // Attach the live fetch strategies (Plan 3a). Consumed by Plan 6's
            // registry-driven refresher. Each is a lazy factory: the scraper/API
            // client resolves at fetch-time, not when the registry is built (the
            // registry is built at boot to emit routes — see PlatformDescriptor::fetch).
            $r->get('spotify')->fetch(fn () => new OEmbedFetch(
                app(OEmbedService::class), fn (string $link) => 'https://open.spotify.com/oembed?url='.rawurlencode($link), 'spotify',
            ));
            $r->get('soundcloud')->fetch(fn () => new OEmbedFetch(
                app(OEmbedService::class), fn (string $link) => 'https://soundcloud.com/oembed?format=json&url='.rawurlencode($link), 'soundcloud',
            ));

            // Connect strategies (FOUND-24) — parse-fail messages are the frozen
            // 422 contract, copied verbatim from the deleted controllers.
            $r->get('spotify')->connect(fn () => new SpotifyConnect(app(OEmbedService::class)), 'Enter a Spotify link (open.spotify.com/artist/...).');
            // Deferred-connect seam (Phase 2, W4) — SpotifyConnect implements
            // DeferredConnect. Message copied verbatim from resolve()'s
            // fetch-stage failure.
            $r->get('spotify')->deferredConnect()->connectFetchError('Could not load that Spotify link.');
            $r->get('soundcloud')->connect(fn () => new SoundcloudConnect(app(OEmbedService::class)), 'Enter your SoundCloud link (soundcloud.com/yourname).');

            // ── Scraped / API feed (per-platform resources, refreshable) ──
            $r->register(PD::make('youtube')->label('YouTube')->category(Cat::Content)->resource(YoutubeConnectionResource::class)->refreshable()->coverable()
                ->payload(FeedPayload::class));
            // Attach feed fetch strategy (Plan 3b). Consumed by Plan 6's registry-driven refresher.
            $r->get('youtube')->fetch(fn () => new YoutubeFetch(
                app(YoutubeScraper::class),
            ));
            // Connect + highlights strategies (FOUND-24, Task 7) — the first picker
            // platform migrated onto Task 6's HighlightsStrategy seam. Moved verbatim
            // from the deleted YoutubeController; parse-fail message is the frozen
            // 422 contract.
            $r->get('youtube')->connect(fn () => new YoutubeConnect(app(YoutubeScraper::class)), 'Enter your YouTube channel.');
            $r->get('youtube')->highlights(fn () => new YoutubeHighlights(app(YoutubeScraper::class)));
            // Deferred-connect seam (Phase 2, W4) — YoutubeConnect implements
            // DeferredConnect. Message copied verbatim from resolve()'s
            // fetch-stage failure.
            $r->get('youtube')->deferredConnect()->connectFetchError('Could not find that YouTube channel or its latest video.');
            $r->register(PD::make('youtube-music')->label('YouTube Music')->category(Cat::Music)->resource(YoutubeMusicConnectionResource::class)->refreshable()
                ->payload(FeedPayload::class));
            // Attach feed fetch strategy (Plan 3b). Consumed by Plan 6's registry-driven refresher.
            $r->get('youtube-music')->fetch(fn () => new YoutubeMusicFetch(
                app(YoutubeScraper::class),
            ));
            // Connect + highlights strategies (FOUND-24, Task 8) — moved verbatim from
            // the deleted YoutubeMusicController; parse-fail message is the frozen 422
            // contract.
            $r->get('youtube-music')->connect(fn () => new YoutubeMusicConnect(app(YoutubeScraper::class)), 'Enter your YouTube Music artist URL (music.youtube.com/channel/…) or your channel @handle.');
            $r->get('youtube-music')->highlights(fn () => new YoutubeMusicHighlights(app(YoutubeScraper::class)));
            // Deferred-connect seam (Phase 2, W4) — YoutubeMusicConnect
            // implements DeferredConnect. Message copied verbatim from
            // resolve()'s fetch-stage failure.
            $r->get('youtube-music')->deferredConnect()->connectFetchError('Could not load releases for that channel.');
            $r->register(PD::make('vimeo')->label('Vimeo')->category(Cat::Content)->resource(VimeoConnectionResource::class)->refreshable()
                ->payload(FeedPayload::class));
            // Attach feed fetch strategy (Plan 3b). Consumed by Plan 6's registry-driven refresher.
            $r->get('vimeo')->fetch(fn () => new VimeoFetch(
                app(VimeoApi::class),
            ));
            // Connect + highlights strategies (FOUND-24, Task 8) — moved verbatim from
            // the deleted VimeoController; parse-fail message is the frozen 422 contract.
            $r->get('vimeo')->connect(fn () => new VimeoConnect(app(VimeoApi::class)), 'Enter your Vimeo profile or channel URL (vimeo.com/yourname).');
            $r->get('vimeo')->highlights(fn () => new VimeoHighlights(app(VimeoApi::class)));
            // Deferred-connect seam (Phase 2, W4) — VimeoConnect implements
            // DeferredConnect. Message copied verbatim from resolve()'s
            // fetch-stage failure.
            $r->get('vimeo')->deferredConnect()->connectFetchError('Could not find that Vimeo profile.');
            $r->register(PD::make('twitch')->label('Twitch')->category(Cat::Streaming)->resource(TwitchConnectionResource::class)->refreshable()
                ->payload(FeedPayload::class));
            // Attach feed fetch strategy (Plan 3b / Task 6). Consumed by Plan 6's registry-driven refresher.
            $r->get('twitch')->fetch(fn () => new TwitchFetch(
                app(TwitchScraper::class),
            ));
            // Connect strategy (FOUND-24) — parse-fail message is the frozen 422
            // contract, copied verbatim from the deleted TwitchController.
            $r->get('twitch')->connect(fn () => new TwitchConnect(app(TwitchScraper::class)), 'Enter your Twitch channel (twitch.tv/yourname).');
            // Deferred-connect seam (Phase 2, W4) — TwitchConnect implements
            // DeferredConnect. Message copied verbatim from resolve()'s
            // fetch-stage failure.
            $r->get('twitch')->deferredConnect()->connectFetchError('Could not find that Twitch channel.');
            $r->register(PD::make('pinterest')->label('Pinterest')->category(Cat::Content)->resource(PinterestConnectionResource::class)->refreshable()
                ->payload(FeedPayload::class));
            // Attach feed fetch strategy (Plan 3b / Task 7). Consumed by Plan 6's registry-driven refresher.
            $r->get('pinterest')->fetch(fn () => new PinterestFetch(
                app(PinterestScraper::class),
            ));
            // Connect strategy (FOUND-24) — parse-fail message is the frozen 422
            // contract, copied verbatim from the deleted PinterestController.
            $r->get('pinterest')->connect(fn () => new PinterestConnect(app(PinterestScraper::class)), 'Enter your Pinterest profile (pinterest.com/yourname).');
            // Deferred-connect seam (Phase 2, W4) — PinterestConnect implements
            // DeferredConnect. Message copied verbatim from resolve()'s
            // fetch-stage failure.
            $r->get('pinterest')->deferredConnect()->connectFetchError('Could not find that Pinterest profile.');
            $r->register(PD::make('bandcamp')->label('Bandcamp')->category(Cat::Music)->resource(BandcampConnectionResource::class)->refreshable()
                ->payload(FeedPayload::class));
            // Attach feed fetch strategy (Plan 3b). Consumed by Plan 6's registry-driven refresher.
            $r->get('bandcamp')->fetch(fn () => new BandcampFetch(
                app(BandcampScraper::class),
            ));
            // Connect + highlights strategies (FOUND-24, Task 9, last picker platform) —
            // moved verbatim from the deleted BandcampController.
            $r->get('bandcamp')->connect(fn () => new BandcampConnect(app(BandcampScraper::class)), 'Enter your Bandcamp page URL (yourname.bandcamp.com).');
            $r->get('bandcamp')->highlights(fn () => new BandcampHighlights(app(BandcampScraper::class)));
            // Deferred-connect seam (Phase 2, W4) — BandcampConnect implements
            // DeferredConnect. Message copied verbatim from resolve()'s
            // fetch-stage failure.
            $r->get('bandcamp')->deferredConnect()->connectFetchError('Could not find releases on that Bandcamp page.');
            $r->register(PD::make('apple-music')->label('Apple Music')->category(Cat::Music)->resource(AppleMusicConnectionResource::class)->refreshable()->coverable()
                ->payload(FeedPayload::class));
            // Attach feed fetch strategy (Plan 3b / Task 8). Consumed by Plan 6's registry-driven refresher.
            $r->get('apple-music')->fetch(fn () => new AppleMusicFetch(
                app(AppleSearch::class),
            ));
            // CA-W3: the message ConnectFetchJob stores on the row when the
            // deferred fetch fails — verbatim from connectFor()'s own synchronous
            // 404 message. Deliberately NOT ->deferredConnect(): that flag means
            // "this descriptor's ConnectStrategy implements DeferredConnect"
            // (RegistryConnectCoverageTest pins flag<=>instanceof for every
            // descriptor), but Apple has no ConnectStrategy at all — its connect
            // is bespoke (AppleController::connectFor(), via DefersBespokeConnect),
            // never routed through ConnectResolver/GenericPlatformController. The
            // rollout flag check (config('partna.connect.deferred')) is read
            // directly by DefersBespokeConnect::shouldDeferConnect(), not via
            // supportsDeferredConnect() — so setting that flag here would just be
            // a false claim that breaks the pinned invariant for no functional gain.
            $r->get('apple-music')->connectFetchError('Could not find that Apple Music artist or an album.');
            $r->register(PD::make('apple-podcast')->label('Apple Podcasts')->category(Cat::Content)->resource(ApplePodcastConnectionResource::class)->refreshable()->coverable()
                ->payload(FeedPayload::class));
            // Attach feed fetch strategy (Plan 3b / Task 8). Consumed by Plan 6's registry-driven refresher.
            $r->get('apple-podcast')->fetch(fn () => new ApplePodcastFetch(
                app(AppleSearch::class),
            ));
            // CA-W3 — see apple-music's identical note above.
            $r->get('apple-podcast')->connectFetchError('Could not find that Apple Podcast or an episode.');
            $r->register(PD::make('google-business')->label('Google Business')->category(Cat::Business)->resource(GoogleBusinessConnectionResource::class)->refreshable()->payload(GoogleBusinessPayload::class));
            // Attach fetch strategy (Plan 3b). GoogleBusinessPayload is verbatim-preserving
            // (variable key set via array_intersect_key) — read paths migrated in Plan 5.
            $r->get('google-business')->fetch(fn () => new GoogleBusinessFetch(
                app(GoogleBusinessService::class),
            ));
            $r->register(PD::make('instagram')->label('Instagram')->category(Cat::Social)->resource(InstagramConnectionResource::class)->payload(InstagramPayload::class)); // refresh = paid Apify, not in cron

            // ── Events (refreshable; organiser accounts + standalone events) ──
            $r->register(PD::make('eventbrite')->label('Eventbrite')->category(Cat::Events)->refreshable()->coverable()->payload(EventsAccountPayload::class));
            $r->register(PD::make('humanitix')->label('Humanitix')->category(Cat::Events)->refreshable()->payload(EventsAccountPayload::class));
            // Attach the live event fetch strategies (Plan 6). Consumed by the registry-driven refresher.
            $r->get('eventbrite')->fetch(fn () => new EventbriteFetch(app(EventbriteScraper::class)));
            $r->get('humanitix')->fetch(fn () => new HumanitixFetch(app(HumanitixScraper::class)));
            // CA-W5 — see apple-music's identical note above: the message
            // ConnectFetchJob stores when the deferred scrape fails, verbatim
            // from addAccount()'s own synchronous 422. Deliberately NOT
            // ->deferredConnect() — neither descriptor has a ConnectStrategy
            // (their connect is bespoke, via DefersBespokeConnect), so that flag
            // would falsely claim one exists (RegistryConnectCoverageTest pins
            // flag<=>instanceof for every descriptor).
            $r->get('eventbrite')->connectFetchError('Could not load that Eventbrite page.');
            $r->get('humanitix')->connectFetchError('Could not load that Humanitix page.');
            $r->register(PD::make('events-custom')->label('Custom Event')->category(Cat::Events)->resource(TileConnectionResource::class)->payload(StandaloneEventPayload::class));

            // ── Picker / booking / reservations (no cron refresh) ──
            $r->register(PD::make('fresha')->label('Fresha')->category(Cat::Booking)->resource(FreshaSelectionResource::class)->refreshable()->payload(SelectionPayload::class));
            // Scheduled service-menu refresh (prices/durations/new services) —
            // re-scrapes the saved selection; 304s when unchanged or unselected.
            $r->get('fresha')->fetch(fn () => new FreshaFetch(
                app(FreshaScraper::class),
                app(FreshaServiceProjector::class),
            ));
            $r->get('fresha')->refreshEvery((int) config('partna.refresh.intervals.fresha', 2 * 86400));
            // CA-W6/CA-W7: the CONNECT path needs a different fetch — FreshaFetch
            // (above) is refresh-only (throws on a pending row with no
            // selection). connectFetchStrategy() defaults to fetchStrategy()
            // for every other platform; fresha is the one override. The
            // projector dependency is CA-W7's: the storewide branch runs
            // FreshaServiceProjector::sync() itself (team mode never touches it).
            $r->get('fresha')->connectFetch(fn () => new FreshaConnectFetch(app(FreshaScraper::class), app(FreshaServiceProjector::class)));
            // The message ConnectFetchJob stores when the deferred team-mode
            // menu fetch fails — verbatim from connect()'s own synchronous 502
            // ('Could not reach Fresha — please try again.' was the old abort()
            // message; this is the poll-shaped equivalent). Deliberately NOT
            // ->deferredConnect(): fresha's connect is bespoke
            // (FreshaController::connect(), via DefersBespokeConnect), never
            // routed through ConnectResolver/GenericPlatformController, so that
            // flag would falsely claim a ConnectStrategy exists
            // (RegistryConnectCoverageTest pins flag<=>instanceof for every
            // descriptor). Mirrors skool/apple-music/eventbrite's identical notes.
            $r->get('fresha')->connectFetchError("We couldn't read that Fresha page just then — please try again.");
            $r->register(PD::make('square')->label('Square')->category(Cat::Booking)->resource(TileConnectionResource::class)->payload(SelectionPayload::class));
            $r->register(PD::make('opentable')->label('OpenTable')->category(Cat::Reservations)->resource(OpenTableConnectionResource::class)->payload(SelectionPayload::class));
            $r->register(PD::make('resdiary')->label('ResDiary')->category(Cat::Reservations)->resource(ResDiaryConnectionResource::class)->payload(SelectionPayload::class));
            $r->register(PD::make('nowbookit')->label('NowBookit')->category(Cat::Reservations)->resource(NowBookitConnectionResource::class)->payload(SelectionPayload::class));
            // Connect strategies (FOUND-24) — parse-fail messages are the frozen
            // 422 contract, copied verbatim from the deleted controllers.
            $r->get('nowbookit')->connect(fn () => new NowBookitConnect(app(NowBookitService::class)), 'Enter a NowBookit booking link (nowbookit.com/...).');
            $r->get('resdiary')->connect(fn () => new ResDiaryConnect(app(ResDiaryService::class)), 'Enter a ResDiary booking link (resdiary.com/...).');
            $r->get('opentable')->connect(fn () => new OpenTableConnect(app(OpenTableService::class)), 'Enter an OpenTable restaurant link (opentable.com.au/...).');
            // Sector-derived gate (2026-07-15): all three reservation-family
            // providers route connect() through GenericPlatformController →
            // IntegrationConnectionPolicy::connect → this predicate, so gating
            // here covers all three in one place (unlike Fresha/Square, which
            // are bespoke and gate themselves inline).
            foreach (['opentable', 'resdiary', 'nowbookit'] as $reservationProvider) {
                $r->get($reservationProvider)->requiresCapability(
                    fn (User $user) => AccountCapabilities::for($user)->can_use_reservations,
                );
            }

            // ── Smart-detect matchers (Plan 6). Registration order = detection priority. ──
            // Booking: fresha host (mirrors the fresha connect regex), then Square (squareup.com / *.square.site).
            $r->get('fresha')->detect(new HostMatch('~(^|\.)fresha\.com$~'));
            $r->get('square')->detect(new HostMatch('~(^|\.)(squareup\.com|square\.site)$~'));
            // Reservations: keyless widgets delegate to their service's isXUrl matcher.
            $openTable = $this->app->make(OpenTableService::class);
            $resDiary = $this->app->make(ResDiaryService::class);
            $nowBookit = $this->app->make(NowBookitService::class);
            $r->get('opentable')->detect(new ServiceMatch(fn (string $u) => $openTable->isOpenTableUrl($u)));
            $r->get('resdiary')->detect(new ServiceMatch(fn (string $u) => $resDiary->isResDiaryUrl($u)));
            $r->get('nowbookit')->detect(new ServiceMatch(fn (string $u) => $nowBookit->isNowBookitUrl($u)));
            // Events: Eventbrite has regional TLDs; Humanitix is single-domain.
            $r->get('eventbrite')->detect(new HostMatch('~(^|\.)eventbrite\.[a-z.]+$~'));
            $r->get('humanitix')->detect(new HostMatch('~(^|\.)humanitix\.com$~'));

            // ── Shop (multi-brand) + smart-detect category pseudo-platforms ──
            $r->register(PD::make('shop')->label('Shop')->category(Cat::Shop)->resource(ShopBrandResource::class)->refreshable()->payload(ShopPayload::class));
            // Latest-mode product sync — auto-tracks the store's newest products
            // for brands with selection_mode='latest'; manual brands 304 inside.
            $r->get('shop')->fetch(fn () => new ShopFetch(
                app(ShopCatalog::class),
                app(IntegrationConnectionCacheRefresher::class),
            ));
            $r->get('shop')->refreshEvery((int) config('partna.refresh.intervals.shop', 6 * 3600));
            $r->register(PD::make('custom')->label('Custom Link')->category(Cat::Content)->resource(LinkConnectionResource::class)->payload(CardPayload::class));
            $r->register(PD::make('booking')->label('Booking')->category(Cat::Booking)->payload(CardPayload::class));
            $r->register(PD::make('reservations')->label('Reservations')->category(Cat::Reservations)->payload(CardPayload::class));
            $r->register(PD::make('online-ordering')->label('Online Ordering')->category(Cat::OnlineOrdering)->payload(CardPayload::class));

            // ── Connect-request validation contract (FOUND-19) ──────────────────
            // The single source of truth for each reducible platform's connect input
            // shape. Read by the shared PlatformConnectRequest via the route's
            // 'platform' default. Field names / maxes / regex / 422 messages are the
            // frozen API contract — reproduce verbatim. GoogleBusiness is irreducible
            // (multi-field) and keeps ConnectGoogleBusinessRequest.

            // url-shaped (17). The max differs per platform — these are NOT uniform.
            $r->get('bandcamp')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('eventbrite')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('fresha')->connectInput('url', ['required', 'string', 'max:500', 'regex:#^https?://(www\.)?fresha\.com/(?:[a-z]{2,3}(-[a-z]{2})?/)?a/[a-z0-9-]+/?$#i'], [], true);
            $r->get('humanitix')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('nowbookit')->connectInput('url', ['required', 'string', 'max:2048']);
            $r->get('opentable')->connectInput('url', ['required', 'string', 'max:2048']);
            $r->get('pinterest')->connectInput('url', ['required', 'string', 'max:200']);
            $r->get('resdiary')->connectInput('url', ['required', 'string', 'max:2048']);
            $r->get('skool')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('soundcloud')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('spotify')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('square')->connectInput('url', ['required', 'string', 'max:1000', 'regex:#^https?://([a-z0-9-]+\.)*(squareup\.com|square\.site)(/[^\s]*)?$#i'], ['url.regex' => 'Enter a valid Square booking link (a squareup.com or square.site URL).'], true);
            $r->get('strava')->connectInput('url', ['required', 'string', 'max:300']);
            $r->get('twitch')->connectInput('url', ['required', 'string', 'max:120']);
            $r->get('vimeo')->connectInput('url', ['required', 'string', 'max:300']);
            $r->get('youtube-music')->connectInput('url', ['required', 'string', 'max:300']);

            // single-named-field (3 distinct + 11 socials share 'username').
            $r->get('apple-music')->connectInput('artist', ['required', 'string', 'max:200']);
            $r->get('apple-podcast')->connectInput('show', ['required', 'string', 'max:200']);
            $r->get('youtube')->connectInput('channel', ['required', 'string', 'max:200']);
            foreach (['x', 'linkedin', 'threads', 'reddit', 'tiktok', 'facebook', 'snapchat', 'discord', 'telegram', 'kick', 'medium'] as $social) {
                $r->get($social)->connectInput('username', ['required', 'string', 'max:200']);
            }

            // ── Public display toggles ───────────────────────────────────────────
            // What parts of a platform's synced content the owner can hide from
            // the sitepage. Read by DisplaySettingsController (settings UI +
            // PATCH validation) and PublicIntegrationConnectionResource /
            // PublicMenuController (payload suppression). Absent key = shown.
            $r->get('google-business')->displayToggles([
                ['key' => 'reviews', 'label' => 'Reviews', 'description' => 'Your Google rating and recent reviews.'],
                ['key' => 'hours', 'label' => 'Opening hours', 'description' => 'Your weekly opening hours.'],
                ['key' => 'photos', 'label' => 'Photos', 'description' => 'Photos from your Google Business profile.'],
                ['key' => 'location', 'label' => 'Location & map', 'description' => 'Your address, map and directions.'],
                ['key' => 'menu', 'label' => 'Menu', 'description' => 'Your food and drink menu.'],
            ]);
            // Instagram's gallery toggle is UNIFIED with the Content/Media
            // "Latest content auto sync" switch: both read/write the site column
            // `content_instagram_auto_enabled`, so the two settings are one value
            // and turning it off hides ALL auto Instagram content (the curated
            // reel/post slots AND this integration card).
            $r->get('instagram')->displayToggles([
                ['key' => 'gallery', 'label' => 'Gallery', 'description' => 'Your latest Instagram photo and reel.', 'siteColumn' => 'content_instagram_auto_enabled'],
            ]);
            // Tickets & Events: per-user "auto sync latest from each organiser"
            // switch. Not a payload-suppression toggle (no DisplaySettingsFilter
            // entry) — the events FETCH strategies read it and 304 an account row
            // when it's off, freezing the stored upcoming list while standalone
            // event rows keep refreshing (sold-out/price freshness is separate).
            // Declared per platform; the dashboard's single Tickets card PATCHes
            // both eventbrite and humanitix together.
            foreach (['eventbrite', 'humanitix'] as $eventsPlatform) {
                $r->get($eventsPlatform)->displayToggles([
                    ['key' => 'auto_sync_latest', 'label' => 'Auto sync latest from each organiser', 'description' => 'Automatically refresh each connected organiser\'s upcoming events.'],
                ]);
            }
            // Bandcamp (Listen section): releases visibility + latest-tile sync.
            // show_all_releases defaults OFF ('default' => false — the one toggle
            // whose absent state means disabled): ON reveals the stored full
            // `releases` list on the public wire (DisplaySettingsFilter stops
            // suppressing it); OFF keeps today's capped latest+highlights
            // selection. auto_sync_latest defaults ON and gates BandcampFetch's
            // scheduled re-pull, mirroring the events toggle semantics.
            $r->get('bandcamp')->displayToggles([
                ['key' => 'show_all_releases', 'label' => 'Show all releases', 'description' => 'Show every release from your Bandcamp page, not just the latest and your highlights.', 'default' => false],
                ['key' => 'auto_sync_latest', 'label' => 'Auto sync latest release', 'description' => 'Automatically refresh your newest release.'],
            ]);

            // ── Refresh cadences ─────────────────────────────────────────────────
            // Per-platform re-fetch intervals for the hourly dispatcher; anything
            // not listed uses the global default (24h). Events move fastest (new
            // listings + sellouts should reach the sitepage same-morning); watch/
            // listen content lands twice daily; Google Business every 2 days —
            // its fetch keeps a 40h internal freshness gate so ratings stay
            // ≤2 days stale instead of the old 6-day drift, while still
            // respecting Google's caching guidance.
            $r->get('eventbrite')->refreshEvery((int) config('partna.refresh.intervals.eventbrite', 6 * 3600));
            $r->get('humanitix')->refreshEvery((int) config('partna.refresh.intervals.humanitix', 6 * 3600));
            $r->get('youtube')->refreshEvery((int) config('partna.refresh.intervals.youtube', 12 * 3600));
            $r->get('vimeo')->refreshEvery((int) config('partna.refresh.intervals.vimeo', 12 * 3600));
            $r->get('twitch')->refreshEvery((int) config('partna.refresh.intervals.twitch', 12 * 3600));
            $r->get('youtube-music')->refreshEvery((int) config('partna.refresh.intervals.youtube-music', 12 * 3600));
            $r->get('spotify')->refreshEvery((int) config('partna.refresh.intervals.spotify', 12 * 3600));
            $r->get('soundcloud')->refreshEvery((int) config('partna.refresh.intervals.soundcloud', 12 * 3600));
            $r->get('bandcamp')->refreshEvery((int) config('partna.refresh.intervals.bandcamp', 12 * 3600));
            $r->get('apple-music')->refreshEvery((int) config('partna.refresh.intervals.apple-music', 12 * 3600));
            $r->get('apple-podcast')->refreshEvery((int) config('partna.refresh.intervals.apple-podcast', 12 * 3600));
            $r->get('google-business')->refreshEvery((int) config('partna.refresh.intervals.google-business', 2 * 86400));

            // ── Route archetypes (FOUND-21) ─────────────────────────────────────
            // Drives the single registry loop in routes/api/platforms.php. Bespoke
            // platforms (the default) keep their standalone groups and are skipped.

            // Link-only socials: connect/selection/forget all via GenericPlatformController.
            foreach (['x', 'linkedin', 'threads', 'reddit', 'tiktok', 'facebook', 'snapchat', 'discord', 'telegram', 'kick', 'medium'] as $social) {
                $r->get($social)->routes(PlatformRouteShape::LinkOnly);
            }

            // Single-selection (connect/selection/forget all on the bespoke controller).
            $r->get('skool')->routes(PlatformRouteShape::SingleSelection, SkoolController::class);
            $r->get('google-business')->routes(PlatformRouteShape::SingleSelection, GoogleBusinessController::class);

            // Migrated reads: bespoke connect + generic reads. multiAccount gates /accounts.
            // spotify/soundcloud/twitch/youtube/pinterest/strava/nowbookit/resdiary/
            // opentable are now fully registry-driven (FOUND-24) — null controller routes
            // connect through GenericPlatformController + the descriptor's ConnectStrategy
            // (registered above). Strava is the one platform whose READS also moved here
            // (Task 4) — its stored payload hydrates through FeedPayload instead of the
            // deleted StravaController. OpenTable keeps its bespoke suggestion() endpoint
            // (routes/api/platforms.php) — it reads across platforms (Google Business),
            // which this generic shape has no seam for. YouTube (Task 7), Vimeo + YouTube
            // Music (Task 8) are the platforms whose HighlightsStrategy activates the
            // loop's /recent + /highlights emission — hasHighlights() was a boot-safe
            // no-op until then.
            $r->get('spotify')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('soundcloud')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('twitch')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('youtube')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('vimeo')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('youtube-music')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('bandcamp')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('pinterest')->routes(PlatformRouteShape::MultiAccount, null, false);
            $r->get('strava')->routes(PlatformRouteShape::MultiAccount, null, false);
            $r->get('nowbookit')->routes(PlatformRouteShape::MultiAccount, null, false);
            $r->get('resdiary')->routes(PlatformRouteShape::MultiAccount, null, false);
            $r->get('opentable')->routes(PlatformRouteShape::MultiAccount, null, false);

            return $r;
        });
    }

    public function boot(): void
    {
        // Per-provider outbound rate limit for the refresh queue. Cache-backed →
        // Redis in prod → shared across ALL workers, so the cap is global, not
        // per-process. Keyed by platform so one provider can't starve the others.
        RateLimiter::for('platform-refresh', function (RefreshConnectionJob $job) {
            $perMinute = (int) config(
                "partna.refresh.rate_limits.{$job->platform}",
                config('partna.refresh.rate_limits.default')
            );

            return Limit::perMinute($perMinute)->by('platform-refresh:'.$job->platform);
        });

        // Per-actor CONNECT-time burst gate (Seam 5). Separate from platform-refresh:
        // connect jobs hit paid Apify actors, refresh jobs hit official APIs — different
        // vendors, different budgets. Keyed by the job's Apify actor so one actor's
        // signup spike can't starve the others. Applied as middleware on the three
        // ThrottledByProvider connect jobs.
        RateLimiter::for('platform-connect', function (ThrottledByProvider $job) {
            $actor = $job->providerRateKey();
            $perMinute = (int) config(
                "partna.connect.rate_limits.{$actor}",
                config('partna.connect.rate_limits.default')
            );

            return Limit::perMinute($perMinute)->by('platform-connect:'.$actor);
        });

        // Per-vendor BURST gate for the pre-account scraping lane
        // (GeneratePreAccountSiteJob, ApproveEarlyAccessBuildJob). The 'instagram'
        // source rides the 'platform-connect' limiter above instead (shared paid-
        // Apify budget — same account as dashboard connects); THIS limiter covers
        // the 'google_business' source, which hits the official Google Places API —
        // a different vendor, so a separate budget. Same cache-backed → Redis-in-
        // prod → global-across-workers shape as the connect/refresh limiters.
        RateLimiter::for('preaccount-places', function (ThrottledByProvider $job) {
            $actor = $job->providerRateKey();
            $perMinute = (int) config(
                "partna.pre_account.rate_limits.{$actor}",
                config('partna.pre_account.rate_limits.default')
            );

            return Limit::perMinute($perMinute)->by('preaccount-places:'.$actor);
        });
    }
}
