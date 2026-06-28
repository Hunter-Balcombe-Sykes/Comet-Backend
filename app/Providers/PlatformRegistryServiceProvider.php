<?php

namespace App\Providers;

use App\Http\Resources\Platforms\AppleMusicConnectionResource;
use App\Http\Resources\Platforms\ApplePodcastConnectionResource;
use App\Http\Resources\Platforms\BandcampConnectionResource;
use App\Http\Resources\Platforms\EventbriteConnectionResource;
use App\Http\Resources\Platforms\FreshaSelectionResource;
use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Http\Resources\Platforms\HumanitixConnectionResource;
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
use App\Services\Platforms\Normalizers\FacebookNormalizer;
use App\Services\Platforms\Normalizers\LinkedinNormalizer;
use App\Services\Platforms\Normalizers\RedditNormalizer;
use App\Services\Platforms\Normalizers\ThreadsNormalizer;
use App\Services\Platforms\Normalizers\TiktokNormalizer;
use App\Services\Platforms\Normalizers\XNormalizer;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\Registry\PlatformCategory as Cat;
use App\Services\Platforms\Registry\PlatformDescriptor as PD;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Connect\UrlConnect;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;
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

            // Skool + Strava are link/card style under their own resources.
            $r->register(PD::make('skool')->label('Skool')->category(Cat::Education)->resource(SkoolConnectionResource::class));
            $r->register(PD::make('strava')->label('Strava')->category(Cat::Content)->resource(StravaConnectionResource::class)->refreshable());

            // ── oEmbed music (MusicEmbedConnectionResource, refreshable) ──
            foreach (['spotify' => 'Spotify', 'soundcloud' => 'SoundCloud', 'deezer' => 'Deezer'] as $key => $label) {
                $r->register(PD::oEmbed($key, $label, MusicEmbedConnectionResource::class));
            }
            // mixcloud + tidal are keyless embeds sharing the music-embed resource.
            $r->register(PD::oEmbed('mixcloud', 'Mixcloud', MusicEmbedConnectionResource::class)->refreshable(false));
            $r->register(PD::oEmbed('tidal', 'Tidal', MusicEmbedConnectionResource::class)->refreshable(false));

            // Attach the live fetch strategies (Plan 3a). Consumed by Plan 6's
            // registry-driven refresher; built eagerly here like the link-only
            // UrlConnect strategies above.
            $oembed = $this->app->make(OEmbedService::class);
            $r->get('spotify')->fetch(new OEmbedFetch(
                $oembed, fn (string $link) => 'https://open.spotify.com/oembed?url='.rawurlencode($link), 'spotify',
            ));
            $r->get('soundcloud')->fetch(new OEmbedFetch(
                $oembed, fn (string $link) => 'https://soundcloud.com/oembed?format=json&url='.rawurlencode($link), 'soundcloud',
            ));

            // ── Scraped / API feed (per-platform resources, refreshable) ──
            $r->register(PD::make('youtube')->label('YouTube')->category(Cat::Content)->resource(YoutubeConnectionResource::class)->refreshable());
            $r->register(PD::make('youtube-music')->label('YouTube Music')->category(Cat::Music)->resource(YoutubeMusicConnectionResource::class)->refreshable());
            $r->register(PD::make('vimeo')->label('Vimeo')->category(Cat::Content)->resource(VimeoConnectionResource::class)->refreshable());
            $r->register(PD::make('twitch')->label('Twitch')->category(Cat::Streaming)->resource(TwitchConnectionResource::class)->refreshable());
            $r->register(PD::make('pinterest')->label('Pinterest')->category(Cat::Content)->resource(PinterestConnectionResource::class)->refreshable());
            $r->register(PD::make('bandcamp')->label('Bandcamp')->category(Cat::Music)->resource(BandcampConnectionResource::class)->refreshable());
            $r->register(PD::make('apple-music')->label('Apple Music')->category(Cat::Music)->resource(AppleMusicConnectionResource::class)->refreshable());
            $r->register(PD::make('apple-podcast')->label('Apple Podcasts')->category(Cat::Content)->resource(ApplePodcastConnectionResource::class)->refreshable());
            $r->register(PD::make('google-business')->label('Google Business')->category(Cat::Business)->resource(GoogleBusinessConnectionResource::class)->refreshable());
            $r->register(PD::make('instagram')->label('Instagram')->category(Cat::Social)->resource(InstagramConnectionResource::class)); // refresh = paid Apify, not in cron

            // ── Events (refreshable; organiser accounts + standalone events) ──
            $r->register(PD::make('eventbrite')->label('Eventbrite')->category(Cat::Events)->resource(EventbriteConnectionResource::class)->refreshable());
            $r->register(PD::make('humanitix')->label('Humanitix')->category(Cat::Events)->resource(HumanitixConnectionResource::class)->refreshable());
            $r->register(PD::make('events-custom')->label('Custom Event')->category(Cat::Events)->resource(TileConnectionResource::class));

            // ── Picker / booking / reservations (no cron refresh) ──
            $r->register(PD::make('fresha')->label('Fresha')->category(Cat::Booking)->resource(FreshaSelectionResource::class));
            $r->register(PD::make('square')->label('Square')->category(Cat::Booking)->resource(TileConnectionResource::class));
            $r->register(PD::make('opentable')->label('OpenTable')->category(Cat::Reservations)->resource(OpenTableConnectionResource::class));
            $r->register(PD::make('resdiary')->label('ResDiary')->category(Cat::Reservations)->resource(ResDiaryConnectionResource::class));
            $r->register(PD::make('nowbookit')->label('NowBookit')->category(Cat::Reservations)->resource(NowBookitConnectionResource::class));

            // ── Shop (multi-brand) + smart-detect category pseudo-platforms ──
            $r->register(PD::make('shop')->label('Shop')->category(Cat::Shop)->resource(ShopBrandResource::class));
            $r->register(PD::make('custom')->label('Custom Link')->category(Cat::Content)->resource(LinkConnectionResource::class));
            $r->register(PD::make('booking')->label('Booking')->category(Cat::Booking));
            $r->register(PD::make('reservations')->label('Reservations')->category(Cat::Reservations));
            $r->register(PD::make('online-ordering')->label('Online Ordering')->category(Cat::OnlineOrdering));

            return $r;
        });
    }
}
