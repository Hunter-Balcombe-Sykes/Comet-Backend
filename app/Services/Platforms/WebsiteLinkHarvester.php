<?php

namespace App\Services\Platforms;

use App\Catalog\CatalogNotCompiled;
use App\Catalog\CompiledCatalog;
use App\Catalog\Definitions\Eventbrite;
use App\Catalog\Definitions\Ticketek;
use App\Catalog\Definitions\Ticketmaster;
use App\Catalog\LegacyPlatformMap;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\PublicSuffixList;
use App\Routing\Rulepack;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Shop\ShopConnections;

// In-house replacement for the slice of the Google Business Apify enrichment
// that never needed Google at all: businesses put their own social /
// reservation / ordering / booking links on their own website, and Place
// Details already hands us that website URL. One SSRF-guarded fetch of the
// homepage + anchor classification recovers those links instantly and free —
// the paid Apify run stays only for what genuinely lives on the Maps listing
// (menu action link, google reserve/food URLs), see GoogleBusinessEnrichJob's
// needsApify rule.
//
// Output is shaped EXACTLY like GoogleBusinessApifyScraper::map()'s subset so
// GoogleBusinessAutoSync::seed() consumes either source unchanged:
//   { reservation: {url, links:[{url}]}, order: {providers:[{name,url}]},
//     booking: [url,…], socials: {instagram,facebook,…} }
// Keys are present only when something was found.
class WebsiteLinkHarvester
{
    /** Utility classes that mean display:none on their own — Bootstrap, Tailwind. */
    private const HIDDEN_CLASSES = ['d-none', 'hidden'];

    /**
     * A breakpoint utility that re-shows what HIDDEN_CLASSES hid.
     *
     * Deliberately generous on both arms, and matches any breakpoint name so a
     * project's custom `tablet:`/`3xl:` screens count. An earlier version
     * enumerated seven Tailwind display values and so read `hidden
     * md:table-cell` as hidden — dropping a link most visitors CAN see. That is
     * the expensive direction: harvest() feeds GoogleBusinessAutoSync::seed(),
     * so a lost anchor is a business's missing social account, whereas an
     * over-generous match merely keeps a link we might have dropped.
     */
    private const RESHOWN_CLASS = '~^(d-[a-z0-9]+-(?!none$)[a-z0-9-]+|[a-z0-9]+:(?!hidden$)[a-z0-9!:._/-]+)$~';

    /** Host-pattern → socials key. First match per key wins (homepage order). */
    private const SOCIAL_HOSTS = [
        'instagram' => '~(^|\.)instagram\.com$~',
        'facebook' => '~(^|\.)facebook\.com$~',
        'tiktok' => '~(^|\.)tiktok\.com$~',
        'twitter' => '~(^|\.)(twitter\.com|x\.com)$~',
        'linkedin' => '~(^|\.)linkedin\.com$~',
        'youtube' => '~(^|\.)(youtube\.com|youtu\.be)$~',
        // Expanded 2026-07-25 — link classification consolidation
        'spotify' => '~(^|\.)spotify\.com$~',
        'soundcloud' => '~(^|\.)soundcloud\.com$~',
        'snapchat' => '~(^|\.)snapchat\.com$~',
        'threads' => '~(^|\.)threads\.net$~',
        'discord' => '~(^|\.)(discord\.gg|discord\.com)$~',
        'reddit' => '~(^|\.)reddit\.com$~',
        'telegram' => '~(^|\.)(t\.me|telegram\.me)$~',
        'whatsapp' => '~(^|\.)(wa\.me|whatsapp\.com)$~',
        'substack' => '~(^|\.)substack\.com$~',
        'patreon' => '~(^|\.)patreon\.com$~',
        'github' => '~(^|\.)github\.com$~',
        'behance' => '~(^|\.)behance\.net$~',
        'dribbble' => '~(^|\.)dribbble\.com$~',
        'vimeo' => '~(^|\.)vimeo\.com$~',
        'twitch' => '~(^|\.)twitch\.tv$~',
    ];

    /**
     * Reservation provider hosts, keyed by label — the seeder's own provider
     * services re-validate. Kept per-provider (not one joined regex) so
     * classify() can report WHICH provider matched, not just "reservation-y".
     */
    private const RESERVATION_HOSTS = [
        'OpenTable' => '~(^|\.)opentable\.(com|com\.au|com\.mx|co\.uk|co\.th|ca|de|jp|ie|sg|hk|ae|it|es|nl|at)$~',
        'ResDiary' => '~(^|\.)resdiary\.com$~',
        'NowBookit' => '~(^|\.)nowbookit\.com$~',
        // Expanded 2026-07-25
        'SevenRooms' => '~(^|\.)sevenrooms\.com$~',
        // Both hosts: Tock migrated exploretock.com -> tock.com and serves both.
        // The catalog detector has carried the pair since Phase 6; this table had
        // only the old one, so a tock.com paste fell through to
        // classifyFromCatalog() and came back category 'link' — enough for the
        // per-brand route, but the reservations FAMILY endpoint rejects anything
        // whose category is not 'reservations', so that path refused a valid link.
        'Tock' => '~(^|\.)(exploretock\.com|tock\.com)$~',
        'TheFork' => '~(^|\.)thefork\.(com|com\.au|com\.br|com\.ar|co\.uk|fr|es|it|pt|nl|be|ch|at|de|dk|se|cl)$~',
        'Quandoo' => '~(^|\.)quandoo\.(com|com\.au|de|at|ch|it|co\.uk|sg|hk|nl|fi)$~',
        'Resy' => '~(^|\.)resy\.com$~',
        'Chope' => '~(^|\.)chope\.co$~',
        'Tablein' => '~(^|\.)tablein\.com$~',
        'Eat App' => '~(^|\.)eatapp\.co$~',
        'TableCheck' => '~(^|\.)tablecheck\.com$~',
        // NB Yelp Reservations is deliberately absent. Every pattern in this
        // constant is matched against the HOST alone (classify() and harvest()
        // both pass $host), so a path-bearing pattern like
        // `~yelp\.com/reservations~` can never match — it was added on
        // 2026-07-25 and was dead on arrival. Widening it to bare `yelp.com`
        // is NOT the fix: that would classify every Yelp business-profile link
        // as a reservation. It needs path-aware matching, which this table
        // does not do.
    ];

    /** Label => platform key for RESERVATION_HOSTS — see BOOKING_PLATFORM on the two shapes. */
    private const RESERVATION_PLATFORM = [
        'OpenTable' => 'opentable', 'ResDiary' => 'resdiary', 'NowBookit' => 'nowbookit',
        'SevenRooms' => 'sevenrooms', 'Tock' => 'tock', 'Quandoo' => 'quandoo',
        'Resy' => 'resy', 'TableCheck' => 'tablecheck',
        // Catalog-only.
        'TheFork' => 'thefork.reserve', 'Chope' => 'chope.reserve',
        'Tablein' => 'tablein.reserve', 'Eat App' => 'eat_app.reserve',
    ];

    /** Online-ordering provider hosts (AU market set + expanded 2026-07-25). */
    private const ORDERING_HOSTS = [
        'Uber Eats' => '~(^|\.)ubereats\.com$~',
        'DoorDash' => '~(^|\.)doordash\.com$~',
        'Menulog' => '~(^|\.)menulog\.com\.au$~',
        'Deliveroo' => '~(^|\.)deliveroo\.(com|co\.uk|fr|ie|it|be|nl|sg|hk|ae|com\.kw|qa)$~',
        'Order Online' => '~(^|\.)order\.online$~',
        'OrderMate' => '~(^|\.)ordermate\.online$~',
        // Expanded 2026-07-25
        'SkipTheDishes' => '~(^|\.)skipthedishes\.com$~',
        'Just Eat' => '~(^|\.)just-?eat\.(co\.uk|com|fr|ie|es|it|ch|dk|no|lu)$~',
        'Grubhub' => '~(^|\.)grubhub\.com$~',
        'Slice' => '~(^|\.)slicelife\.com$~',
        'ChowNow' => '~(^|\.)chownow\.com$~',
        'Toast Takeout' => '~(^|\.)toasttab\.com$~',
        'Wolt' => '~(^|\.)wolt\.com$~',
        'Zomato' => '~(^|\.)zomato\.com$~',
        // Phase 6: bopple.app was never listed, so a real ollies ordering link
        // on that host classified as nothing and spent a commerce probe. The
        // catalog's own Bopple detector covers bopple.app too since 2026-08-27
        // (plan-03 batch 9) — it
        // is a separate table and is deliberately not widened here.
        'Bopple' => '~(^|\.)bopple\.(com|me|app)$~',
        // HungryPanda: real regional ordering hosts are subdomains of
        // hungrypanda.co (aus.hungrypanda.co etc.) — never listed here, so a
        // pasted shop link fell to classifyFromCatalog's flat 'link' answer
        // and became a custom card (plan-03 batch 9, live find). .co ONLY:
        // hungrypanda.com is a PARKED, unrelated domain (critic-verified) —
        // classifying it would present a lander page as a live connection.
        'HungryPanda' => '~(^|\.)hungrypanda\.co$~',
    ];

    /**
     * Label => platform key for ORDERING_HOSTS — see BOOKING_PLATFORM on the two
     * shapes. Before Phase 6 every ordering host collapsed to the single
     * 'online-ordering' pseudo-platform, which is why ollies' Uber Eats and
     * DoorDash links were indistinguishable to ingest (scope §1.6).
     */
    private const ORDERING_PLATFORM = [
        'Bopple' => 'bopple',
        // Path-qualified, matched by isSquareOrderingUrl() rather than a
        // host row in ORDERING_HOSTS (a bare square.site host is booking's).
        'Square Online' => 'square.order',
        // Catalog-only.
        'Uber Eats' => 'uber_eats.order', 'DoorDash' => 'doordash.order',
        'Menulog' => 'menulog.order', 'Deliveroo' => 'deliveroo.order',
        'Order Online' => 'order_online.order', 'OrderMate' => 'ordermate.order',
        'SkipTheDishes' => 'skipthedishes.order', 'Just Eat' => 'just_eat.order',
        'Grubhub' => 'grubhub.order', 'Slice' => 'slice.order',
        'ChowNow' => 'chownow.order', 'Toast Takeout' => 'toast.order',
        'Wolt' => 'wolt.order', 'Zomato' => 'zomato.order',
        'HungryPanda' => 'hungrypanda',
    ];

    /** square.site + the /s/order ordering path (A0.2, live-verified). */
    private function isSquareOrderingUrl(string $host, string $url): bool
    {
        return preg_match('~(^|\.)square\.site$~', $host) === 1
            && preg_match('~^/s/order(?:/|$|\?)~', (string) (parse_url($url, PHP_URL_PATH) ?? '')) === 1;
    }

    /** Booking provider hosts, keyed by label. Expanded 2026-07-25. */
    private const BOOKING_HOSTS = [
        'Fresha' => '~(^|\.)fresha\.com$~',
        'Square' => '~(^|\.)(squareup\.com|square\.site)$~',
        // Expanded 2026-07-25 — stored under shared 'booking' key (Decision 10)
        'Booksy' => '~(^|\.)booksy\.com$~',
        'Timely' => '~(^|\.)gettimely\.com$~',
        'Calendly' => '~(^|\.)calendly\.com$~',
        'Vagaro' => '~(^|\.)vagaro\.com$~',
        'Mindbody' => '~(^|\.)mindbodyonline\.com$~',
        'Acuity' => '~(^|\.)acuityscheduling\.com$~',
        'Setmore' => '~(^|\.)setmore\.com$~',
        'Genbook' => '~(^|\.)genbook\.com$~',
        'GlossGenius' => '~(^|\.)glossgenius\.com$~',
        'Mangomint' => '~(^|\.)mangomint\.com$~',
        // joinblvd.com: Boulevard's live booking-widget host (real customer
        // links use it; dashboard.boulevard.io 301s there — plan-03 batch 5).
        'Boulevard' => '~(^|\.)(boulevard\.io|joinblvd\.com)$~',
        // book.app: Ovatu's customer mini-site domain (their docs; plan-03).
        'Ovatu' => '~(^|\.)(ovatu\.com|book\.app)$~',
        'Treatwell' => '~(^|\.)treatwell\.(com|co\.uk|de|fr|nl|es|it|be|at|ch|ie|pt|lt|lv|gr)$~',
        'Noterro' => '~(^|\.)noterro\.com$~',
        'Schedulicity' => '~(^|\.)schedulicity\.com$~',
        // simplybook.it: SimplyBook issues country-TLD mirrors and the .me
        // host 302s straight to them (plan-03 batch 6, live redirect).
        'SimplyBook.me' => '~(^|\.)simplybook\.(me|it)$~',
        // The four catalog booking brands this list never learned (plan-03
        // batch 5/6 find): their pasted links fell through to
        // classifyFromCatalog's flat 'link' answer and seeded CUSTOM cards
        // instead of booking provider cards.
        'Bella Booking' => '~(^|\.)bellabooking\.com$~',
        'Kitomba' => '~(^|\.)kitomba\.com$~',
        'Phorest' => '~(^|\.)phorest\.com$~',
        'Zenoti' => '~(^|\.)zenoti\.com$~',
    ];

    /**
     * Label => platform key for BOOKING_HOSTS, used only by classify().
     *
     * Convergence Phase 6 retired the shared 'booking' pseudo-platform, so every
     * brand names itself. Two shapes appear here and the difference is not
     * cosmetic: a REGISTERED brand (booksy, vagaro, …) uses its legacy slug,
     * while a CATALOG-ONLY brand uses its full surface key. LegacyPlatformMap is
     * frozen to the 20260727110001 backfill CASE pair-for-pair, so a brand added
     * after P1 can never get a legacy slug — its surface key IS its only name.
     * IntegrationConnection::setPlatformAttribute accepts either verbatim.
     */
    private const BOOKING_PLATFORM = [
        'Fresha' => 'fresha', 'Square' => 'square',
        'Booksy' => 'booksy', 'Timely' => 'timely', 'Vagaro' => 'vagaro',
        'Mindbody' => 'mindbody', 'GlossGenius' => 'glossgenius',
        'Mangomint' => 'mangomint', 'Boulevard' => 'boulevard', 'Ovatu' => 'ovatu',
        'Bella Booking' => 'bella-booking', 'Kitomba' => 'kitomba',
        'Phorest' => 'phorest', 'Zenoti' => 'zenoti',
        // Catalog-only — no legacy slug exists or can exist.
        'Calendly' => 'calendly.book', 'Acuity' => 'acuity.book',
        'Setmore' => 'setmore.book', 'Genbook' => 'genbook.book',
        'Treatwell' => 'treatwell.book', 'Noterro' => 'noterro.book',
        'Schedulicity' => 'schedulicity.book', 'SimplyBook.me' => 'simplybook_me.book',
    ];

    /**
     * Decisive store hosts (signup-v2 C1) — a URL on these IS a storefront, no
     * probe needed. Generic store detection (a business's own domain) is the
     * probe job's business, never classify()'s. squareup.com/square.site stays
     * classified 'booking' above — Square Online stores share those hosts and
     * a host pattern can't disambiguate; flipping it would regress booking.
     */
    private const SHOP_HOSTS = [
        'Shopify' => '~(^|\.)myshopify\.com$~',
        'Big Cartel' => '~(^|\.)bigcartel\.com$~',
    ];

    /**
     * Hosts we recognise but never connect: marketplaces, affiliate networks and
     * social boards. Category 'link' means "this IS a link card, and asking the
     * question costs nothing".
     *
     * They belong here rather than in SHOP_HOSTS because a 'shop' classification
     * still SPENDS A PROBE (LinkRouter::seedShop) — putting Amazon there would
     * cost exactly what it costs today.
     *
     * The user this protects is the creator whose page is mostly affiliate
     * links: before this, five of a run's six probes went on discovering that
     * amazon.com is amazon.com, and the links behind them were starved.
     *
     * THE TRADE, stated so the next person does not rediscover it as a bug:
     * these hosts previously took the UNCLASSIFIED arm, which runs
     * GenericShopScraper::readProductPage() — a schema.org/OpenGraph read that
     * works on any page, not just a self-hosted storefront. So a marketplace
     * LISTING url could become a real product card, and now cannot. Accepted
     * for this list: Amazon bot-blocks the scrape outright, LTK/ShopMy are
     * affiliate redirectors with no product markup of their own, and Pinterest
     * is a board. Deliberately NOT extended to maker marketplaces (Etsy, Depop,
     * Folksy) where a listing IS the most valuable thing on a creator's page and
     * the product read genuinely lands — those keep their probe.
     */
    private const LINK_ONLY_HOSTS = [
        'LTK' => '~(^|\.)(liketoknow\.it|shopltk\.com)$~',
        'Amazon' => '~(^|\.)(amazon\.[a-z]{2,3}(\.[a-z]{2})?|amzn\.(to|eu|asia))$~',
        'Poshmark' => '~(^|\.)poshmark\.[a-z]{2,3}(\.[a-z]{2})?$~',
        'ShopMy' => '~(^|\.)shopmy\.us$~',
        'Pinterest' => '~(^|\.)(pinterest\.[a-z]{2,3}(\.[a-z]{2})?|pin\.it)$~',
    ];

    /**
     * LINK_ONLY_HOSTS label => platform slug. The slug only ever reaches
     * $classified['platform'] for logging and suggestion labels — these routes
     * return RouteResult::custom() with handled:false, so the slug never
     * consumes a per-run platform slot and a creator's five Amazon links all
     * land.
     *
     * @var array<string, string>
     */
    private const LINK_ONLY_PLATFORM = [
        'LTK' => 'ltk',
        'Amazon' => 'amazon',
        'Poshmark' => 'poshmark',
        'ShopMy' => 'shopmy',
        'Pinterest' => 'pinterest',
    ];

    /**
     * SOCIAL_HOSTS key => [platform slug, display label], used only by classify().
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SOCIAL_PLATFORM = [
        'instagram' => ['instagram', 'Instagram'],
        'facebook' => ['facebook', 'Facebook'],
        'tiktok' => ['tiktok', 'TikTok'],
        'twitter' => ['x', 'X'],
        'linkedin' => ['linkedin', 'LinkedIn'],
        'youtube' => ['youtube', 'YouTube'],
        // Expanded 2026-07-25
        'spotify' => ['spotify', 'Spotify'],
        'soundcloud' => ['soundcloud', 'SoundCloud'],
        'snapchat' => ['snapchat', 'Snapchat'],
        'threads' => ['threads', 'Threads'],
        'discord' => ['discord', 'Discord'],
        'reddit' => ['reddit', 'Reddit'],
        'telegram' => ['telegram', 'Telegram'],
        'whatsapp' => ['whatsapp', 'WhatsApp'],
        'substack' => ['substack', 'Substack'],
        'patreon' => ['patreon', 'Patreon'],
        'github' => ['github', 'GitHub'],
        'behance' => ['behance', 'Behance'],
        'dribbble' => ['dribbble', 'Dribbble'],
        'vimeo' => ['vimeo', 'Vimeo'],
        'twitch' => ['twitch', 'Twitch'],
    ];

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // Lazily built from our own fetcher (both scrapers take exactly a
    // SafeUrlFetcher) so classify() gains event patterns without changing this
    // class's construction contract — the normalize methods used here are pure
    // regex and never actually fetch.
    private ?EventbriteScraper $eventbriteScraper = null;

    private ?HumanitixScraper $humanitixScraper = null;

    private function eventbrite(): EventbriteScraper
    {
        return $this->eventbriteScraper ??= new EventbriteScraper($this->fetcher);
    }

    private function humanitix(): HumanitixScraper
    {
        return $this->humanitixScraper ??= new HumanitixScraper($this->fetcher);
    }

    private ?MediaPageReader $mediaPageReader = null;

    /** Same lazy-direct construction as the two scrapers above (and for the
     * same no-booted-app reason); classifyItem() is pure and never fetches. */
    private function mediaReader(): MediaPageReader
    {
        return $this->mediaPageReader ??= new MediaPageReader($this->fetcher);
    }

    // Built lazily and directly — NOT resolved from the container — for the
    // same reason the two scrapers above are: classify() gains catalog
    // awareness without changing this class's construction contract
    // (`new WebsiteLinkHarvester($fetcher)` is how every test builds it).
    //
    // app() would be wrong here even though both have bindings: Unit tests do
    // not boot the app, so AppServiceProvider never registers the Rulepack
    // singleton and autowiring dies on its `array $byRegistrableKey` primitive
    // — nine WebsiteLinkHarvesterTest cases proved it. Both static factories
    // work without a booted app, which is why RoutingCorpusTest uses exactly
    // this pair. Neither is expensive: CompiledCatalog::load() memoises the
    // artefact statically, so this is array reads, once per instance.
    private ?LinkProjector $projector = null;

    private ?IriCanonicalizer $canonicalizer = null;

    private function projector(): LinkProjector
    {
        return $this->projector ??= new LinkProjector(Rulepack::fromCompiledCatalog());
    }

    private function canonicalizer(): IriCanonicalizer
    {
        return $this->canonicalizer ??= new IriCanonicalizer(PublicSuffixList::instance());
    }

    /**
     * @return array<string, mixed> enrichment-shaped subset; [] when the site
     *                              is missing, unreachable, or linkless.
     */
    public function harvest(?string $websiteUrl): array
    {
        if (! is_string($websiteUrl) || ! preg_match('~^https?://~i', trim($websiteUrl))) {
            return [];
        }
        $websiteUrl = trim($websiteUrl);

        $response = $this->fetcher->tryFetch($websiteUrl);
        $html = is_array($response) && ($response['status'] ?? 0) === 200
            ? (string) ($response['body'] ?? '')
            : '';
        if ($html === '' || strlen($html) > 3_000_000) {
            return [];
        }

        return $this->harvestHtml($html, $response['finalUrl'] ?? $websiteUrl);
    }

    /**
     * Same classification harvest() does, off an already-fetched HTML string —
     * lets a caller that already has the page in hand (previous-website scan,
     * link-in-bio scan) reuse this class's classification without a second fetch.
     *
     * @return array<string, mixed> enrichment-shaped subset; [] when linkless.
     */
    public function harvestHtml(string $html, string $baseUrl): array
    {
        $links = $this->extractLinks($html, $baseUrl);
        if ($links === []) {
            return [];
        }

        $socials = [];
        $reservationLinks = [];
        $orderProviders = [];
        $booking = [];

        foreach ($links as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '') {
                continue;
            }

            foreach (self::SOCIAL_HOSTS as $key => $pattern) {
                if (! isset($socials[$key]) && preg_match($pattern, $host) && $this->looksLikeProfile($url)) {
                    $socials[$key] = $url;

                    continue 2;
                }
            }

            if ($this->matchesAnyHost(self::RESERVATION_HOSTS, $host)) {
                $reservationLinks[] = ['url' => $url];

                continue;
            }

            // Square Online (A4, 2026-08-26): host + /s/order PATH — the one
            // URL shape that is unambiguously ordering. Bare square.site
            // stays with booking (host default); custom domains never reach
            // host matching at all and ride the storefront-marker probe.
            if ($this->isSquareOrderingUrl($host, $url)) {
                $orderProviders[] = ['name' => 'Square Online', 'url' => $url];

                continue;
            }

            foreach (self::ORDERING_HOSTS as $name => $pattern) {
                if (preg_match($pattern, $host)) {
                    $orderProviders[] = ['name' => $name, 'url' => $url];

                    continue 2;
                }
            }

            if ($this->matchesAnyHost(self::BOOKING_HOSTS, $host)) {
                $booking[] = $url;
            }
        }

        // (Outcome logging lives in GoogleBusinessEnrichJob, which knows
        // whether the harvest replaced or merely complemented the Apify run.)
        return array_filter([
            'reservation' => $reservationLinks !== []
                ? ['url' => $reservationLinks[0]['url'], 'links' => $reservationLinks]
                : null,
            'order' => $orderProviders !== [] ? ['providers' => $orderProviders] : null,
            'booking' => $booking !== [] ? $booking : null,
            'socials' => $socials !== [] ? $socials : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Every absolute, deduped, http(s)-only outbound link on the page — not
     * just the ones classify()-able into a known platform. Used by the
     * link-in-bio scan and previous-website general link-harvest, which need
     * to hand EVERY link to classify()/CustomLinkSeeder themselves rather than
     * only the pre-bucketed subset harvestHtml() returns.
     *
     * @return list<string>
     */
    public function allOutboundLinks(string $html, string $baseUrl): array
    {
        return $this->extractLinks($html, $baseUrl);
    }

    /**
     * Classify a single URL by host into {platform, category, label}, or null
     * when neither this class's host patterns nor the compiled catalog can name
     * it. Reuses the SAME SOCIAL_HOSTS / RESERVATION_HOSTS / ORDERING_HOSTS /
     * BOOKING_HOSTS constants harvest() classifies a scraped homepage's anchors
     * with (BE2: InstagramAutoSync classifies Instagram bio links through this,
     * instead of a second table). Category values match GoogleBusinessAutoSync's
     * finding categories ('social'/'booking'/'reservations'/'online-ordering')
     * so a consumer can treat either source's findings identically.
     *
     * These constants are NO LONGER the only host→platform mapping consulted:
     * classifyFromCatalog() backstops them, because being defined in
     * app/Catalog/Definitions did not make a link classify here and 39 catalog
     * hosts were invisible — each one spending a commerce probe to rediscover a
     * host the catalog could already name (N1/N4, 2026-08-11). The constants
     * still answer FIRST and are still hand-maintained; see that method for why
     * the order is not the other way round.
     *
     * @return array{platform:string, category:string, label:string, kind?:string, canonical?:string}|null
     */
    public function classify(string $url): ?array
    {
        $url = trim($url);
        if (! preg_match('~^https?://~i', $url)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        // MEDIA ITEMS FIRST (T6, 2026-08-20): a video/track/release/episode
        // URL is an ITEM, and an item claim always wins over every account
        // answer — the same precedence MediaPageReader::accountPlatformLabel
        // applies internally. The grammar is SHARED with the paste lane
        // (classifyItem), not copied, so the two can never drift. Carries
        // kind + canonical so the seeding lanes don't re-derive them.
        $item = $this->mediaReader()->classifyItem($url);
        if ($item !== null) {
            return [
                'platform' => $item['platform'],
                'category' => 'content-item',
                'label' => $item['platform'],
                'kind' => $item['kind'],
                'canonical' => $item['canonical'],
            ];
        }

        foreach (self::SOCIAL_HOSTS as $key => $pattern) {
            // No isset() guard needed: SOCIAL_PLATFORM is hand-maintained with the
            // exact same 7 keys as SOCIAL_HOSTS, so the lookup below can never
            // miss for a $key drawn from this loop.
            if (preg_match($pattern, $host) && $this->looksLikeProfile($url)) {
                [$platform, $label] = self::SOCIAL_PLATFORM[$key];

                return ['platform' => $platform, 'category' => 'social', 'label' => $label];
            }
        }

        // Square Online: path-qualified and checked BEFORE booking — the
        // /s/order path is unambiguous ordering evidence, while the bare
        // square.site host stays booking's default.
        if ($this->isSquareOrderingUrl($host, $url)) {
            return ['platform' => 'square.order', 'category' => 'online-ordering', 'label' => 'Square Online'];
        }

        foreach (self::BOOKING_HOSTS as $label => $pattern) {
            if (preg_match($pattern, $host)) {
                return ['platform' => self::BOOKING_PLATFORM[$label], 'category' => 'booking', 'label' => $label];
            }
        }

        foreach (self::RESERVATION_HOSTS as $label => $pattern) {
            if (preg_match($pattern, $host)) {
                return ['platform' => self::RESERVATION_PLATFORM[$label], 'category' => 'reservations', 'label' => $label];
            }
        }

        foreach (self::ORDERING_HOSTS as $label => $pattern) {
            if (preg_match($pattern, $host)) {
                return ['platform' => self::ORDERING_PLATFORM[$label], 'category' => 'online-ordering', 'label' => $label];
            }
        }

        // Events (signup-v2 C1; events-parity 2026-08-19): organiser pages vs
        // single events. Pattern authority stays with each scraper's own
        // pure-regex normalizers so classify() can never drift from what the
        // connect flow accepts — HumanitixScraper::resolveHostUrl() is
        // deliberately NOT used (its event-URL branch fetches). Humanitix org
        // runs BEFORE event: the two shapes share a host and only '/host/'
        // discriminates. Platform values are the REAL brand keys (the
        // 'events-custom' pseudo-slug is gone from these arms) so the seeding
        // lanes — EventsSeeder via LinkRouter/LinkInBioImporter — can act on
        // every brand, not just the two bespoke ones. TLD alternations come
        // from each brand's catalog definition, the single source of truth.
        if (preg_match(self::brandTldRegex('eventbrite', Eventbrite::TLDS), $host)) {
            if ($this->eventbrite()->normalizeOrgUrl($url) !== null) {
                return ['platform' => 'eventbrite', 'category' => 'event-organiser', 'label' => 'Eventbrite'];
            }
            if ($this->eventbrite()->normalizeEventUrl($url) !== null) {
                return ['platform' => 'eventbrite', 'category' => 'event', 'label' => 'Eventbrite'];
            }
        }
        if (preg_match('~(^|\.)humanitix\.com$~', $host)) {
            if (preg_match('~^https?://(?:events\.)?humanitix\.com/host/[a-z0-9-]+~i', $url)) {
                return ['platform' => 'humanitix', 'category' => 'event-organiser', 'label' => 'Humanitix'];
            }
            if ($this->humanitix()->normalizeEventUrl($url) !== null) {
                return ['platform' => 'humanitix', 'category' => 'event', 'label' => 'Humanitix'];
            }
        }
        // Expanded 2026-07-25 — link classification consolidation.
        // luma.com added 2026-08-19: Luma rebranded and lu.ma now 301s there.
        if (preg_match('~(^|\.)(lu\.ma|luma\.com)$~', $host)) {
            if (preg_match('~^https?://(?:www\.)?(?:lu\.ma|luma\.com)/user/[a-z0-9-]+~i', $url)) {
                return ['platform' => 'luma', 'category' => 'event-organiser', 'label' => 'Luma'];
            }

            // A bare lu.ma slug is an EVENT by default (calendar slugs share
            // the shape and cannot be told apart statically — the seeding
            // lane's JSON-LD read self-filters a calendar page to a card).
            return ['platform' => 'luma', 'category' => 'event', 'label' => 'Luma'];
        }
        if (preg_match('~(^|\.)partiful\.com$~', $host)) {
            if (preg_match('~^https?://partiful\.com/u/[a-zA-Z0-9-]+~i', $url)) {
                return ['platform' => 'partiful', 'category' => 'event-organiser', 'label' => 'Partiful'];
            }

            return ['platform' => 'partiful', 'category' => 'event', 'label' => 'Partiful'];
        }
        if (preg_match(self::brandTldRegex('ticketmaster', Ticketmaster::TLDS), $host)) {
            // Only real event pages (…/event/<id>) are events; artist and
            // discovery pages also embed Event JSON-LD lists, and seeding an
            // arbitrary first event from those would be wrong — they fall to
            // classifyFromCatalog's link card instead.
            if (str_contains(strtolower((string) parse_url($url, PHP_URL_PATH)), '/event/')) {
                return ['platform' => 'ticketmaster', 'category' => 'event', 'label' => 'Ticketmaster'];
            }
        }
        if (preg_match(self::brandTldRegex('ticketek', Ticketek::TLDS), $host)) {
            return ['platform' => 'ticketek', 'category' => 'event', 'label' => 'Ticketek'];
        }
        if (preg_match('~(^|\.)oztix\.com\.au$~', $host)) {
            return ['platform' => 'oztix', 'category' => 'event', 'label' => 'Oztix'];
        }
        if (preg_match('~(^|\.)trybooking\.com$~', $host)) {
            return ['platform' => 'trybooking', 'category' => 'event', 'label' => 'TryBooking'];
        }
        if (preg_match('~(^|\.)(ra\.co|residentadvisor\.net)$~', $host)) {
            // RA serves DJ/promoter profiles beside event pages on one host;
            // only /events/<id> is an event. Everything else keeps its
            // catalog link card.
            if (preg_match('~^/events/~i', (string) parse_url($url, PHP_URL_PATH))) {
                return ['platform' => 'resident-advisor', 'category' => 'event', 'label' => 'Resident Advisor'];
            }
        }
        if (preg_match('~(^|\.)meetup\.com$~', $host)) {
            // No brand key of its own yet — the generic events-pool reader
            // covers a pasted Meetup event page; scans card it.
            return ['platform' => 'events-custom', 'category' => 'event', 'label' => 'Meetup'];
        }

        foreach (self::SHOP_HOSTS as $label => $pattern) {
            if (preg_match($pattern, $host)) {
                return ['platform' => 'shop', 'category' => 'shop', 'label' => $label];
            }
        }

        // LAST, after every host map that can produce a real connection: a
        // marketplace host must never shadow one, and the only cost of losing a
        // race here is a link card instead of a card, which is no cost at all.
        foreach (self::LINK_ONLY_HOSTS as $label => $pattern) {
            if (preg_match($pattern, $host)) {
                return ['platform' => self::LINK_ONLY_PLATFORM[$label], 'category' => 'link', 'label' => $label];
            }
        }

        return $this->classifyFromCatalog($url);
    }

    /**
     * LAST resort: ask the compiled catalog whether it can name this host.
     *
     * The constants above run FIRST and this never overrides them — that
     * ordering is the whole design. They are host-only by construction and have
     * no confidence floor, so they answer correctly for the 178 catalog hosts
     * they cover, while the projector scores a bare host-only detector in the
     * low 30s and would downgrade Booksy, GitHub, DoorDash, Resy, Treatwell and
     * friends from a real connection to a link card. A union recovers the 39
     * hosts the tables miss and regresses nothing.
     *
     * Category is 'link' — recognised, never auto-connected, and above all
     * costing no commerce probe (LinkRouter::routeUnclassified). Naming the
     * catalog's real routing class here would be a lie: LinkRouter has no
     * 'content' category, and its gateAllows()/seed* arms encode connection
     * semantics this fallback deliberately does not claim. Promoting these to
     * connections is the P8 migration onto LinkRoutingService
     * (docs/plans/2026-07-28-p8-deletion-readiness.md), not this method.
     *
     * EXCEPT the shop routing class, which returns null and keeps its probe.
     * Everywhere else a probe merely rediscovers a host the catalog can already
     * name, which is the waste this method exists to stop — but on a storefront
     * the probe reads the actual PRODUCT, so answering 'link' for gumroad.com
     * or stan.store would trade a product card for a plain link and never look
     * again. Same trade LINK_ONLY_HOSTS documents for Etsy and Depop, and the
     * same answer: where the listing is the valuable thing, keep the probe.
     *
     * @return array{platform:string, category:string, label:string}|null
     */
    private function classifyFromCatalog(string $url): ?array
    {
        try {
            $projection = $this->projector()->project($this->canonicalizer()->canonicalize($url));

            if (! $projection->matched() || $projection->surfaceKey === null) {
                return null;
            }

            $surface = CompiledCatalog::surface($projection->surfaceKey);

            if ($surface === null) {
                return null;
            }

            if (LegacyPlatformMap::routingClassFor($projection->surfaceKey) === 'shop') {
                // A shop-class surface we do NOT sync as a store (Gumroad,
                // stan.store — no product feed) is a link card at its
                // storefront root: "tameimpala.gumroad.com" is the shop, and
                // nothing else can carry it (task #17, 2026-08-18). A deeper
                // path (a product page) keeps the probe, as documented above.
                $isProvider = in_array($projection->surfaceKey, ShopConnections::surfaces(), true);
                $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
                if ($isProvider || $path !== '') {
                    return null;
                }
            }

            return [
                'platform' => LegacyPlatformMap::legacyFor($projection->surfaceKey),
                'category' => 'link',
                'label' => (string) $surface['display_name'],
            ];
        } catch (CatalogNotCompiled) {
            // An environment without the artefact classifies exactly as it did
            // before this fallback existed. Never fatal on a scrape path.
            return null;
        }
    }

    /** Host regex for a brand across its catalog-declared regional TLDs. */
    private static function brandTldRegex(string $brand, array $tlds): string
    {
        $alts = implode('|', array_map(static fn (string $t): string => preg_quote($t, '~'), $tlds));

        return '~(^|\.)'.preg_quote($brand, '~').'\.(?:'.$alts.')$~i';
    }

    /** Whether $host matches ANY pattern in a label => regex map. */
    private function matchesAnyHost(array $hostMap, string $host): bool
    {
        foreach ($hostMap as $pattern) {
            if (preg_match($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    /** Absolute, deduped, http(s)-only hrefs from the page (≤1000 to bound work — T9). */
    private function extractLinks(string $html, string $baseUrl): array
    {
        $doc = new \DOMDocument;
        // Suppress libxml warnings for real-world HTML.
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $loaded) {
            return [];
        }

        $seen = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }
            if ($this->isHiddenAnchor($a)) {
                continue;
            }
            $abs = $this->absolutize($href, $baseUrl);
            if ($abs !== null && ! isset($seen[$abs])) {
                $seen[$abs] = true;
                if (count($seen) >= 1000) {
                    break;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * A link no visitor can see is not a link the owner published. Lnk.Bio
     * ships five display:none backlinks to its own portfolio on every page
     * (measured on clk.bio/TheMetaPunter, 2026-08-24) and all five had already
     * reached catalog.unmatched_domains through a harvest.
     *
     * Reads the anchor's OWN markup only. An ancestor's style would need the
     * computed cascade DOMDocument never builds, and a collapsed mobile nav
     * hides its container while its links are real — so climbing the tree
     * would eat genuine navigation to catch a footer.
     */
    private function isHiddenAnchor(\DOMElement $a): bool
    {
        if ($a->hasAttribute('hidden')) {
            return true;
        }

        $style = $a->getAttribute('style');
        if ($style !== '' && preg_match('~(display\s*:\s*none|visibility\s*:\s*hidden)~i', $style) === 1) {
            return true;
        }

        return $this->hiddenByUtilityClass($a->getAttribute('class'));
    }

    /**
     * Bootstrap's `d-none` and Tailwind's `hidden` mean display:none with no
     * stylesheet needed to read them. Lnk.Bio hides four of its five SEO
     * backlinks with an inline style and the fifth with `d-none` alone, so the
     * inline-style rule on its own leaves exactly one leak.
     *
     * Both frameworks also pair the class with a breakpoint that re-shows the
     * element — "d-none d-md-block" is VISIBLE on desktop — so that
     * combination must not count as hidden. No other class is guessed at: a
     * bare `.promo` says nothing without the CSS we never fetched.
     */
    private function hiddenByUtilityClass(string $class): bool
    {
        $classes = preg_split('~\s+~', trim($class), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (array_intersect($classes, self::HIDDEN_CLASSES) === []) {
            return false;
        }

        foreach ($classes as $one) {
            if (preg_match(self::RESHOWN_CLASS, $one) === 1) {
                return false;
            }
        }

        return true;
    }

    /** Resolve relative hrefs against the page URL; null for non-http(s) schemes. */
    private function absolutize(string $href, string $base): ?string
    {
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) {
            return null; // mailto:, tel:, javascript:, data:…
        }

        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($href, '//')) {
            return $parts['scheme'].':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $dir = isset($parts['path']) ? rtrim(dirname($parts['path']), '/') : '';

        return $origin.$dir.'/'.$href;
    }

    /**
     * A profile link, not a share/intent widget ("facebook.com/sharer",
     * "twitter.com/intent") — the classic false positives on business sites.
     *
     * Host-agnostic since 2026-08-24: the old facebook/twitter allowlist let
     * linkedin.com/sharing/share-offsite and reddit.com/submit through as real
     * profiles on the live clk.bio page, and a per-host list guarantees the
     * next vendor's share button repeats the defect. A share endpoint carries
     * the page it shares in its QUERY, so no owner's handle is lost by reading
     * these paths as non-profiles — the worst case is an inert custom card.
     */
    private function looksLikeProfile(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (preg_match('~^/(sharer|share|sharing|intent|intents|dialog|submit)(/|\.|$)~', $path) === 1) {
            return false;
        }

        // Bare-domain links (e.g. "https://instagram.com") carry no profile.
        return $path !== '' && $path !== '/';
    }
}
