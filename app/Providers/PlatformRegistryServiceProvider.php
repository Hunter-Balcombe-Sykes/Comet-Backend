<?php

namespace App\Providers;

use App\Http\Controllers\Api\Platforms\GoogleBusinessController;
use App\Http\Resources\Platforms\FreshaSelectionResource;
use App\Http\Resources\Platforms\GoogleBusinessConnectionResource;
use App\Http\Resources\Platforms\InstagramConnectionResource;
use App\Http\Resources\Platforms\LinkConnectionResource;
use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Http\Resources\Platforms\NowBookitConnectionResource;
use App\Http\Resources\Platforms\OpenTableConnectionResource;
use App\Http\Resources\Platforms\ResDiaryConnectionResource;
use App\Http\Resources\Platforms\ShopBrandResource;
use App\Http\Resources\Platforms\TileConnectionResource;
use App\Jobs\Platforms\RefreshConnectionJob;
use App\Jobs\Platforms\ThrottledByProvider;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\FreshaAutoSelector;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
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
use App\Services\Platforms\Registry\DerivedDescriptorFactory;
use App\Services\Platforms\Registry\PlatformCategory as Cat;
use App\Services\Platforms\Registry\PlatformDescriptor as PD;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\ResDiaryService;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\Strategies\Connect\BrandLinkConnect;
use App\Services\Platforms\Strategies\Connect\NowBookitConnect;
use App\Services\Platforms\Strategies\Connect\OpenTableConnect;
use App\Services\Platforms\Strategies\Connect\ResDiaryConnect;
use App\Services\Platforms\Strategies\Connect\SoundcloudConnect;
use App\Services\Platforms\Strategies\Connect\SpotifyConnect;
use App\Services\Platforms\Strategies\Connect\UrlConnect;
use App\Services\Platforms\Strategies\Detect\HostMatch;
use App\Services\Platforms\Strategies\Detect\ServiceMatch;
use App\Services\Platforms\Strategies\Fetch\EventbriteFetch;
use App\Services\Platforms\Strategies\Fetch\FreshaConnectFetch;
use App\Services\Platforms\Strategies\Fetch\FreshaFetch;
use App\Services\Platforms\Strategies\Fetch\GoogleBusinessFetch;
use App\Services\Platforms\Strategies\Fetch\HumanitixFetch;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;
use App\Services\Platforms\Strategies\Fetch\ShopFetch;
use App\Services\Shop\ShopConnections;
use App\Site\Pools\PoolResolver;
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

            // ── PD-retirement P2 (2026-08-27): the 25 link-only platforms
            // (the 14 normalizer socials, skool/strava/twitch with their kept
            // dashboard categories, and the 11 strategy-less link cards) are
            // retired to catalog-derived descriptors. Their FULL original
            // contract — LinkOnly shape, username/url field, UrlConnect + the
            // exact 422 copy, LinkPayload, LinkConnectionResource — derives
            // through LinkOnlyBindings, which carries the retired
            // registration data verbatim. The upgrades() pass still retro-fits
            // Brand connect onto the strategy-less ones exactly as it did
            // when they were hand-written.
            // ── oEmbed music (MusicEmbedConnectionResource, refreshable) ──
            foreach (['spotify' => 'Spotify', 'soundcloud' => 'SoundCloud'] as $key => $label) {
                $r->register(PD::oEmbed($key, $label, MusicEmbedConnectionResource::class));
            }
            // mixcloud + tidal: retired to catalog-derived descriptors (P3,
            // 2026-08-27) — the factory's embed overrides keep their
            // EmbedPayload + MusicEmbedConnectionResource contract.

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
            // youtube: retired to a catalog-derived descriptor (P4,
            // 2026-08-27) — its full behavioural contract attaches from
            // Registry\Bindings\YoutubeBinding.
            // youtube-music: retired to a catalog-derived descriptor (P4,
            // 2026-08-27) — its full behavioural contract attaches from
            // Registry\Bindings\YoutubeMusicBinding.
            // vimeo: retired to a catalog-derived descriptor (P4 canary,
            // 2026-08-27) — its full behavioural contract attaches from
            // Registry\Bindings\VimeoBinding.
            // bandcamp: retired to a catalog-derived descriptor (P4,
            // 2026-08-27) — its full behavioural contract attaches from
            // Registry\Bindings\BandcampBinding.
            // apple-music: retired to a catalog-derived descriptor (P4,
            // 2026-08-27) — its full behavioural contract (including the
            // CA-W3 no-deferredConnect ruling) attaches from
            // Registry\Bindings\AppleMusicBinding.
            // apple-podcast: retired to a catalog-derived descriptor (P4,
            // 2026-08-27) — its full behavioural contract (including the
            // CA-W3 no-deferredConnect ruling) attaches from
            // Registry\Bindings\ApplePodcastBinding.
            $r->register(PD::make('google-business')->label('Google Business')->category(Cat::Business)->resource(GoogleBusinessConnectionResource::class)->refreshable()->payload(GoogleBusinessPayload::class));
            // Attach fetch strategy (Plan 3b). GoogleBusinessPayload is verbatim-preserving
            // (variable key set via array_intersect_key) — read paths migrated in Plan 5.
            $r->get('google-business')->fetch(fn () => new GoogleBusinessFetch(
                app(GoogleBusinessService::class),
            ));
            $r->register(PD::make('instagram')->label('Instagram')->category(Cat::Social)->resource(InstagramConnectionResource::class)->payload(InstagramPayload::class)); // refresh = paid Apify, not in cron

            // ── Events (refreshable; organiser accounts + standalone events) ──
            $r->register(PD::make('eventbrite')->label('Eventbrite')->category(Cat::Events)->refreshable()->payload(EventsAccountPayload::class));
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
            // 'events-custom' left the registry 2026-08-19 with the
            // pseudo-platform retirement: a standalone event is an events-pool
            // item (ManualEventWriter), never a connection row.

            // ── Picker / booking / reservations (no cron refresh) ──
            $r->register(PD::make('fresha')->label('Fresha')->category(Cat::Booking)->resource(FreshaSelectionResource::class)->refreshable()->payload(SelectionPayload::class));
            // Scheduled service-menu refresh (prices/durations/new services) —
            // re-scrapes the saved selection; 304s when unchanged or unselected.
            $r->get('fresha')->fetch(fn () => new FreshaFetch(
                app(FreshaScraper::class),
                app(FreshaServiceProjector::class),
                app(FreshaAutoSelector::class),
            ));
            $r->get('fresha')->refreshEvery((int) config('partna.refresh.intervals.fresha', 2 * 86400));
            // CA-W6/CA-W7: the CONNECT path needs a different fetch — FreshaFetch
            // (above) is refresh-only (throws on a pending row with no
            // selection). connectFetchStrategy() defaults to fetchStrategy()
            // for every other platform; fresha is the one override. The
            // projector dependency is CA-W7's: the storewide branch runs
            // FreshaServiceProjector::sync() itself (team mode never touches it).
            // app() rather than `new` so a constructor gaining a dependency (as
            // it did on 2026-07-25 with FreshaStaffMatcher) can't leave this
            // call site silently short an argument.
            $r->get('fresha')->connectFetch(fn () => app(FreshaConnectFetch::class));
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
            // An auto-harvested fresha row (Instagram bio / Google Business) is
            // {url, selection: null} — connected, but with no service menu to
            // render. FreshaFetch 304s it forever (:36-39), so it can never
            // self-heal; only the owner picking a team member completes it.
            // is_array (not !== null) mirrors FreshaFetch's own guard exactly so
            // the two predicates cannot drift.
            $r->get('fresha')->complete(fn (IntegrationConnection $c): bool => is_array($c->payload['selection'] ?? null));
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
            $r->get('eventbrite')->detect(new HostMatch('~(^|\.)eventbrite\.(com|com\.au|co\.uk|co\.nz|ca|de|fr|es|it|nl|pt|ie|at|ch|dk|fi|se|be|sg|hk|com\.br|com\.mx|com\.ar|com\.pe|cl)$~'));
            $r->get('humanitix')->detect(new HostMatch('~(^|\.)humanitix\.com$~'));

            // ── 2026-07-26 Platform expansion: Booking detect-only ──
            // ── PD-retirement P1 (2026-08-27): the 23 detect-only card
            // entries (booking: booksy, vagaro, timely, kitomba, phorest,
            // shortcuts, bella-booking, boulevard, glossgenius, mangomint,
            // zenoti, mindbody, ovatu; reservations: resy, quandoo,
            // sevenrooms, tock, tablecheck; events: ticketek, oztix,
            // trybooking, resident-advisor, ticketmaster) are retired to
            // catalog-derived descriptors — each brand's catalog definition
            // is the single source, and the derivation loop below fills the
            // registry slot with CardPayload parity plus the routing-class
            // category. Their PD HostMatch detectors were load-bearing only
            // for ProviderDetector's fresha/square special-cases, which stay
            // hand-written; every other brand already classifies through the
            // catalog (WebsiteLinkHarvester + LegacyPlatformMap).
            // bopple + square-ordering + hungrypanda + easi went first
            // (menu deep-links plan Part C, 2026-08-26).
            // hungrypanda + easi: retired to catalog-derived descriptors —
            // same ruling as bopple/square-ordering above.

            // ── Shop (multi-brand) + smart-detect category pseudo-platforms ──
            $r->register(PD::make('shop')->label('Shop')->category(Cat::Shop)->resource(ShopBrandResource::class)->refreshable()->payload(ShopPayload::class));
            // Latest-mode product sync — auto-tracks every non-individual
            // store's newest products when the site's global shop_auto_latest
            // is on, EXCEPT a store the user hand-curated (#SEM-1:
            // content.storefronts.products_curated_at IS NOT NULL) — see
            // ShopFetch's docblock; when there is nothing left to sync it 304s
            // inside.
            $r->get('shop')->fetch(fn () => new ShopFetch(
                app(ShopCatalog::class),
                app(IntegrationConnectionCacheRefresher::class),
                app(ShopConnections::class),
            ));
            $r->get('shop')->refreshEvery((int) config('partna.refresh.intervals.shop', 6 * 3600));
            // FOUND-25 + W9: a shop connection's payload is a static lifecycle
            // marker — brands/products live relationally and are decoupled from
            // connect (addBrand stores a brand with zero products). An active
            // connection alone isn't real content, so shop keeps a completeness
            // predicate; only the QUESTION it asks changed.
            //
            // Slice 5b: page presence is POOL-derived, exactly as events became
            // in slice 2. The previous closure counted content.collection_items
            // and deliberately did NOT filter items.removed_at, to stay in
            // lockstep with a payload that did not filter it either. The pool
            // read DOES filter it, so asking the pool the question directly is
            // what keeps presence and payload from disagreeing — lockstep by
            // construction rather than by two queries agreeing to be wrong in
            // the same way.
            //
            // The connect_status='pending' exclusion went with it: the pool has
            // no notion of connect_status, so a pending store's products both
            // render AND count. That is a real semantics change, and the right
            // one — W9's exclusion existed to stop presence advertising a page
            // whose payload was empty, and the payload is no longer empty.
            $r->get('shop')->complete(function (IntegrationConnection $c): bool {
                $site = Site::query()->where('user_id', (string) $c->user_id)->first();

                return $site !== null && app(PoolResolver::class)->hasSelection($site, 'shop');
            });
            // The custom / booking / reservations / online-ordering pseudo
            // descriptors left the registry 2026-08-19 (pseudo-platform
            // retirement): zero rows carry those platform keys and every
            // routed link lives on its real brand surface.

            // ── Connect-request validation contract (FOUND-19) ──────────────────
            // The single source of truth for each reducible platform's connect input
            // shape. Read by the shared PlatformConnectRequest via the route's
            // 'platform' default. Field names / maxes / regex / 422 messages are the
            // frozen API contract — reproduce verbatim. GoogleBusiness is irreducible
            // (multi-field) and keeps ConnectGoogleBusinessRequest.

            // url-shaped (17). The max differs per platform — these are NOT uniform.
            $r->get('eventbrite')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('fresha')->connectInput('url', ['required', 'string', 'max:500', 'regex:#^https?://(www\.)?fresha\.com/(?:[a-z]{2,3}(-[a-z]{2})?/)?a/[a-z0-9-]+/?$#i'], [], true);
            $r->get('humanitix')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('nowbookit')->connectInput('url', ['required', 'string', 'max:2048']);
            $r->get('opentable')->connectInput('url', ['required', 'string', 'max:2048']);
            $r->get('resdiary')->connectInput('url', ['required', 'string', 'max:2048']);
            $r->get('soundcloud')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('spotify')->connectInput('url', ['required', 'string', 'max:500']);
            $r->get('square')->connectInput('url', ['required', 'string', 'max:1000', 'regex:#^https?://([a-z0-9-]+\.)*(squareup\.com|square\.site)(/[^\s]*)?$#i'], ['url.regex' => 'Enter a valid Square booking link (a squareup.com or square.site URL).'], true);

            // single-named-field (3 distinct + 11 socials share 'username').
            // P2: the retired link-only platforms' connect inputs (username
            // for the 11 socials; url for skool/strava/twitch with their
            // historical maxes) ride LinkOnlyBindings into the derived
            // descriptors — see linkOnlyDescriptor().

            // ── Public display toggles ───────────────────────────────────────────
            // What parts of a platform's synced content the owner can hide from
            // the sitepage. Read by DisplaySettingsController (settings UI +
            // PATCH validation) and PublicIntegrationConnectionResource /
            // PoolResolver's menus pool (payload suppression). Absent key = shown.
            $r->get('google-business')->displayToggles([
                ['key' => 'reviews', 'label' => 'Reviews', 'description' => 'Your Google rating and recent reviews.'],
                ['key' => 'hours', 'label' => 'Opening hours', 'description' => 'Your weekly opening hours.'],
                ['key' => 'photos', 'label' => 'Photos', 'description' => 'Photos from your Google Business profile.'],
                ['key' => 'location', 'label' => 'Location & map', 'description' => 'Your address, map and directions.'],
                ['key' => 'menu', 'label' => 'Menu', 'description' => 'Your food and drink menu.'],
            ]);
            // Instagram (2026-08-05, platforms-as-sources): the old site-column
            // gallery toggle (content_instagram_auto_enabled) migrated into the
            // connection's own display_settings under the ONE auto-sync key.
            // Turning it off still hides ALL auto Instagram content — the
            // curated reel/post slots and the integration card read the same
            // value through AutoSyncSetting.
            $r->get('instagram')->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Latest post', 'description' => 'Your newest post joins your site automatically.'],
            ]);

            // The pools' auto half (2026-08-05): every source with a
            // time-ordered item stream carries the SAME auto_sync toggle. Read
            // at pool resolve time (latest_per_auto_source), NOT as a fetch
            // gate — syncing keeps filling the library either way; the switch
            // only decides whether the newest item auto-joins the site.
            // Shop: the old site-wide shop_auto_latest column, same key, same
            // site-wide effect (AutoSyncSetting writes every store connection).
            $r->get('shop')->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Latest products', 'description' => 'Each store keeps showing its newest products automatically.'],
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
            // Spotify + SoundCloud source `track` items into the listen pool
            // since convergence Phase 4, so their connections take the same
            // sparse auto_sync_latest toggle every other sourcing platform
            // has — without it PlatformSheet showed no Rules and the pool's
            // latest_per_auto_source could never be switched off per source
            // (overnight 2026-08-18, W2).
            // Spotify also sources RELEASES (discography actor, listen
            // restructure 2026-08-18), so it carries the same two switches
            // Apple Music does — without the release key exposed the release
            // arm was un-switchable and "Newest release" stayed on the site
            // with Apple's + Bandcamp's switches both off (session 3, F27).
            $r->get('spotify')->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Newest release', 'description' => 'Your newest album, EP or single joins your site automatically.'],
                ['key' => 'auto_sync_latest_track', 'label' => 'Newest track', 'description' => 'Your newest track joins your site automatically.'],
            ]);
            $r->get('soundcloud')->displayToggles([
                ['key' => 'auto_sync_latest_track', 'label' => 'Newest track', 'description' => 'Your newest track joins your site automatically.'],
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
            $r->get('spotify')->refreshEvery((int) config('partna.refresh.intervals.spotify', 12 * 3600));
            $r->get('soundcloud')->refreshEvery((int) config('partna.refresh.intervals.soundcloud', 12 * 3600));
            $r->get('google-business')->refreshEvery((int) config('partna.refresh.intervals.google-business', 2 * 86400));

            // ── Route archetypes (FOUND-21) ─────────────────────────────────────
            // Drives the single registry loop in routes/api/platforms.php. Bespoke
            // platforms (the default) keep their standalone groups and are skipped.

            // Link-only socials: retired to derived descriptors (P2) — their
            // LinkOnly route shape rides LinkOnlyBindings into the factory.

            // Single-selection (connect/selection/forget all on the bespoke controller).
            $r->get('google-business')->routes(PlatformRouteShape::SingleSelection, GoogleBusinessController::class);

            // Migrated reads: bespoke connect + generic reads. multiAccount gates /accounts.
            // spotify/soundcloud/twitch/youtube/strava/nowbookit/resdiary/
            // opentable are now fully registry-driven (FOUND-24) — null controller routes
            // connect through GenericPlatformController + the descriptor's ConnectStrategy
            // (registered above). Strava is the one platform whose READS also moved here
            // (Task 4) — its stored payload hydrates through FeedPayload instead of the
            // deleted StravaController. OpenTable keeps its bespoke suggestion() endpoint
            // (routes/api/platforms.php) — it reads across platforms (Google Business),
            // which this generic shape has no seam for.
            $r->get('spotify')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('soundcloud')->routes(PlatformRouteShape::MultiAccount, null, true);
            $r->get('nowbookit')->routes(PlatformRouteShape::MultiAccount, null, false);
            $r->get('resdiary')->routes(PlatformRouteShape::MultiAccount, null, false);
            $r->get('opentable')->routes(PlatformRouteShape::MultiAccount, null, false);

            // Derived LAST, and only into free slugs. Everything above is
            // hand-written and authoritative: it carries connect strategies,
            // resources and refresh wiring that a derived link-only stub does not.
            // register() throws on a duplicate, so the has() check is what keeps
            // this a no-op rather than a boot failure when a brand graduates to a
            // hand-written descriptor. Pinned by RegistryCoverageTest's shadow test.
            $factory = app(DerivedDescriptorFactory::class);

            foreach ($factory->build($r->keys()) as $slug => $derived) {
                if (! $r->has($slug)) {
                    $r->register($derived);
                }
            }

            // Then UPGRADE the hand-written descriptors that were declared but
            // routeless. ~50 slugs are shaped Bespoke, which the route loop reads
            // as "keeps its own standalone group" — for most of them no group was
            // ever written, so booksy, resy, vagaro, ticketek and their kind have
            // no connect or per-brand disconnect at all. That is the defect this
            // shape exists to fix, and skipping them as "already registered" would
            // have left it exactly where it was.
            //
            // Mutated in place, not re-registered: the existing descriptor keeps
            // its label, category and resource, and register() would throw anyway.
            foreach ($factory->upgrades($r) as $slug => $spec) {
                $descriptor = $r->get($slug);
                if ($descriptor === null) {
                    continue;
                }

                // Most of these were detect-only cards and carry no Resource,
                // which connectBrand() needs to shape its response. Only fill the
                // gap — never overwrite one a hand-written descriptor already set.
                if ($descriptor->resourceClass() === null) {
                    $descriptor->resource(LinkConnectionResource::class);
                }

                $descriptor
                    ->surfaceKey($spec['surface'])
                    ->connect(
                        fn () => new BrandLinkConnect($slug, $spec['label'], $spec['surface']),
                        'Enter a valid '.$spec['label'].' link.'
                    )
                    ->connectInput('url', ['required', 'string', 'max:2048'])
                    ->routes(PlatformRouteShape::Brand, null, $spec['multi']);

                if ($spec['capability'] !== null) {
                    $capability = $spec['capability'];
                    $descriptor->requiresCapability(
                        static fn (User $user): bool => (bool) AccountCapabilities::for($user)->{$capability}
                    );
                }
            }

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
