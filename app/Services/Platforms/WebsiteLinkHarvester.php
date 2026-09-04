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

    /*
     * ── THE FOUR HOST TABLES ARE A DELIBERATE, PERMANENT SPLIT ──────────────
     *
     * SOCIAL_HOSTS / RESERVATION_HOSTS / ORDERING_HOSTS / BOOKING_HOSTS are
     * hand-maintained and answer BEFORE the compiled catalog. That is policy,
     * not debt. Read this before "fixing" it a fifth time.
     *
     * WHY THEY ARE NOT COLLAPSED INTO THE CATALOG. `routing_class` is a
     * PLACEMENT vocabulary (7 values); classify() returns a CATEGORY (9). They
     * do not line up, and three of the gaps are structural:
     *
     *   • routing_class 'content' has no category, no gateAllows() arm and no
     *     LinkRouter seeder. Seven content-class surfaces — youtube,
     *     youtube_music, spotify, soundcloud, twitch, vimeo, deezer — are
     *     'social' HERE and are among the platform's most-connected brands. A
     *     collapse silently stops connecting all seven.
     *   • 'event' vs 'event-organiser' is a PATH distinction. No *.event
     *     surface exists; the catalog carries only organiser/ticketing pages.
     *     LinkRouter::seedEvent() branches on exactly that string.
     *   • LINK_ONLY_HOSTS is 4/5 absent from the catalog entirely (amazon, ltk,
     *     poshmark, shopmy). Its probe-starvation protection has no catalog
     *     expression at all.
     *
     * And no catalog field reproduces the boundary: 20 of the detect-only
     * surfaces are is_connectable=true, while 12 connectable-today surfaces are
     * is_connectable=false.
     *
     * WHAT THE CATALOG DOES OWN. classifyFromCatalog() backstops these tables
     * and PROMOTES a surface to its real category for exactly
     * routing_class ∈ {booking, reservations, ordering} AND is_connectable —
     * the three classes whose vocabulary is 1:1 with a category that has a real
     * gate arm and a real seeder. A CONNECTABLE new brand in one of those
     * classes therefore needs no row here. Everything else answers 'link'.
     *
     * MIND THE is_connectable HALF OF THAT CONDITION. It is not decoration: a
     * DETECT-ONLY brand in those same three classes is NOT promoted, so it
     * does still need a row here, and without one its booking or ordering link
     * renders as a generic card. Four live brands sit in exactly that gap and
     * are carried by rows below — venue.ink, youcanbook.me, obeeapp.com and
     * abacus.co (2026-08-29 cold-build round). The gate is a proxy for the
     * real hazard, which is promoting a surface whose HOST does not uniquely
     * name the brand: direct.book is any domain at all, and
     * microsoft_bookings/wix_bookings are path-identified on shared
     * registrable domains (office365.com, wixapps.net). Those three are right
     * to exclude. The four above are dedicated hosts and are excluded only as
     * collateral — if a catalog field ever expresses "dedicated host", that is
     * the better predicate and those rows can go.
     *
     * WHAT TO DO WHEN YOU ADD A BRAND. Nothing, if it is booking /
     * reservations / ordering AND connectable — the promotion covers it.
     * Otherwise CatalogClassificationSweepTest fails and tells you to either
     * add a row here or record the detect-only decision in
     * tests/fixtures/catalog/known-link-only.php.
     *
     * Full reasoning with the measured numbers:
     * docs/superpowers/specs/2026-08-28-link-classifier-seam-design.md
     */

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
        // Wave 2 (2026-08-28): Deezer joined with a real content connector.
        'deezer' => '~(^|\.)deezer\.com$~',
        // 2026-09-03: Skool gained a URL detector (see its definition), so a
        // skool.com link found on someone's site is now a platform we can name
        // rather than a generic link card. Sits here beside Discord — the
        // other Community-shelf brand — because this map is keyed by the
        // legacy platform key, not by shelf.
        'skool' => '~(^|\.)skool\.com$~',
        // 2026-09-04: brands the catalog already carried a real host detector
        // for but this hand-maintained table never learned — each host below
        // is the brand's own Detector::url() call in app/Catalog/Definitions,
        // not a guess.
        //
        // SIX of the twelve originally added this day were REMOVED again the
        // same night (F8): cameo, cash_app, paypal, tumblr, venmo and vsco are
        // all `->notConnectable()` catalog surfaces, and this map is only for
        // brands that can actually become a connection. Bucketing a detect-only
        // surface here is the exact mistake the yelp.listing test above warns
        // about in its own words ("bucketing it would silently reverse that
        // policy — the single most likely way to get this change wrong"): it
        // sends the link to LinkRouter::seedSocial(), which resolves the bare
        // brand key to no surface, trips IntegrationConnection::booted()'s
        // isKnownSurface() guard, reports an UnregisteredPlatformException, and
        // degrades to the same plain card the fall-through would have produced
        // anyway — but with two Nightwatch reports per occurrence. Those six
        // reach the card cleanly via classifyFromCatalog() instead. Making them
        // connectable is a product decision (they need connect cards, and half
        // are payment links rather than profiles), not a bug fix.
        'bluesky' => '~(^|\.)bsky\.app$~',
        'buymeacoffee' => '~(^|\.)buymeacoffee\.com$~',
        'codepen' => '~(^|\.)codepen\.io$~',
        'gitlab' => '~(^|\.)gitlab\.com$~',
        'kick' => '~(^|\.)kick\.com$~',
        // Key is 'ko-fi' (hyphen), NOT the catalog brand_key 'ko_fi'
        // (underscore) — critic-caught 2026-09-04: LegacyPlatformMap's
        // inverse lookup is keyed by legacy_platform ('ko-fi', KoFi.php's
        // own ->legacyPlatform() override, the one brand of these 12 whose
        // legacy slug diverges from its brand key). An underscore key here
        // resolved to no real surface downstream, throwing
        // UnregisteredPlatformException on every live Ko-fi link.
        'ko-fi' => '~(^|\.)ko-fi\.com$~',
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
        // Obee (2026-08-28): found on Bar Liberty's scan as
        // vouchers.obeeapp.com/<venue>/…; the product also serves obee.com.au.
        'Obee' => '~(^|\.)(obeeapp\.com|obee\.com\.au)$~',
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
        'Obee' => 'obee.reserve',
    ];

    /** Online-ordering provider hosts (AU market set + expanded 2026-07-25). */
    private const ORDERING_HOSTS = [
        'Uber Eats' => '~(^|\.)ubereats\.com$~',
        'DoorDash' => '~(^|\.)doordash\.com$~',
        'Menulog' => '~(^|\.)menulog\.com\.au$~',
        'Deliveroo' => '~(^|\.)deliveroo\.(com|co\.uk|fr|ie|it|be|nl|sg|hk|ae|com\.kw|qa)$~',
        'Order Online' => '~(^|\.)order\.online$~',
        'OrderMate' => '~(^|\.)ordermate\.online$~',
        // Abacus (2026-08-28): hospitality POS ordering and gift cards, found
        // on Bar Liberty as w.abacus.co/store/<id>/giftcards/…
        'Abacus' => '~(^|\.)abacus\.co$~',
        // 2026-08-30: both surfaced as `ordering_unroutable` on real venues —
        // the ordering lane naming hosts it could not place.
        'Hey You' => '~(^|\.)heyyou\.com\.au$~',
        'Postmates' => '~(^|\.)postmates\.com$~',
        // Expanded 2026-07-25
        'SkipTheDishes' => '~(^|\.)skipthedishes\.com$~',
        'Just Eat' => '~(^|\.)just-?eat\.(co\.uk|com|fr|ie|es|it|ch|dk|no|lu)$~',
        'Grubhub' => '~(^|\.)grubhub\.com$~',
        'Slice' => '~(^|\.)slicelife\.com$~',
        'ChowNow' => '~(^|\.)chownow\.com$~',
        'Toast Takeout' => '~(^|\.)toasttab\.com$~',
        'Wolt' => '~(^|\.)wolt\.com$~',
        'Zomato' => '~(^|\.)zomato\.com$~',
        // T27a (2026-08-28): AU table ordering — mryum.com + meandu.com post-merge.
        'Mr Yum' => '~(^|\.)(mryum\.com|meandu\.com)$~',
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
        'Mr Yum' => 'mr_yum.order',
        'Abacus' => 'abacus.order',
        'Hey You' => 'hey_you.order', 'Postmates' => 'postmates.order',
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
        // Wave 2 (2026-08-28)
        'Cal.com' => '~(^|\.)cal\.com$~',
        'ClassPass' => '~(^|\.)classpass\.com$~',
        'Timely' => '~(^|\.)gettimely\.com$~',
        'Calendly' => '~(^|\.)calendly\.com$~',
        'Vagaro' => '~(^|\.)vagaro\.com$~',
        'Mindbody' => '~(^|\.)mindbodyonline\.com$~',
        // as.me is the short booking host Acuity itself hands out, and the one
        // people paste. Without it a <tenant>.as.me link fell through to
        // classifyFromCatalog's flat 'link' answer and seeded a plain link
        // card instead of a booking provider card — the Tock lesson above,
        // repeated. Found live on theyogapeoplesydney 2026-08-28.
        'Acuity' => '~(^|\.)(acuityscheduling\.com|as\.me)$~',
        'Setmore' => '~(^|\.)setmore\.com$~',
        // Cold-build round (2026-08-28): catalog brands this table never
        // learned, same class as the four found in plan-03 batch 5/6.
        'YouCanBook.me' => '~(^|\.)youcanbook\.me$~',
        'Venue Ink' => '~(^|\.)venue\.ink$~',
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
        // T27a (2026-08-28) — the new wave, catalog-only slugs below. The
        // calendar.app.google host is EXACT: other app.google subdomains are
        // not booking pages. Wix/Microsoft Bookings are deliberately absent —
        // their registrable domains are shared (wixsite/office365) and only a
        // PATH identifies a booking page, which this host-keyed map cannot
        // express; they stay catalog-detector territory.
        'Jane' => '~(^|\.)janeapp\.com$~',
        'Cliniko' => '~(^|\.)cliniko\.com$~',
        'Halaxy' => '~(^|\.)halaxy\.com$~',
        'HotDoc' => '~(^|\.)hotdoc\.com\.au$~',
        'Bookwell' => '~(^|\.)bookwell\.com\.au$~',
        'StyleSeat' => '~(^|\.)styleseat\.com$~',
        'Rezdy' => '~(^|\.)rezdy\.com$~',
        'FareHarbor' => '~(^|\.)fareharbor\.com$~',
        'Google Calendar' => '~^calendar\.app\.google$~',
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
        // T27a (2026-08-28) — catalog-only, full surface keys.
        'Cal.com' => 'cal_com.book', 'ClassPass' => 'classpass.book',
        'Jane' => 'jane_app.book', 'Cliniko' => 'cliniko.book',
        'Halaxy' => 'halaxy.book', 'HotDoc' => 'hotdoc.book',
        'Bookwell' => 'bookwell.book', 'StyleSeat' => 'styleseat.book',
        'Rezdy' => 'rezdy.book', 'FareHarbor' => 'fareharbor.book',
        'Google Calendar' => 'google_appointments.book',
        'YouCanBook.me' => 'youcanbookme.book', 'Venue Ink' => 'venue_ink.book',
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
     * Catalog routing_class => classify() category, for the classes where the
     * two vocabularies are 1:1 AND LinkRouter has BOTH a gateAllows() arm and a
     * real seeder. A brand in one of these needs no host-table row above.
     *
     * Deliberately three entries, and the omissions are the design:
     *   • 'social' — a catalog social surface is not thereby an account the
     *     owner controls (paypal.me, trustpilot.listing, a Pinterest board).
     *   • 'content' — no category, no gate arm, no seeder anywhere.
     *   • 'events' — cannot express event vs event-organiser; no *.event
     *     surface exists and seedEvent() branches on exactly that.
     *   • 'shop' — must keep its commerce probe (the guard in
     *     classifyFromCatalog() below reads the actual product).
     *
     * See the policy block above SOCIAL_HOSTS.
     *
     * @var array<string, string>
     */
    private const PROMOTABLE_ROUTING_CLASS = [
        'booking' => 'booking',
        'reservations' => 'reservations',
        'ordering' => 'online-ordering',
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
        // Surface key, not brand key — Deezer.php declares no
        // ->legacyPlatform(), so a bare 'deezer' resolved to no surface and
        // threw on every Deezer link found on a scanned site. Broken since
        // this row landed (wave 2, 2026-08-28); found by the F8 sweep.
        'deezer' => ['deezer.artist', 'Deezer'],
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
        'skool' => ['skool', 'Skool'],
        'twitch' => ['twitch', 'Twitch'],
        // 2026-09-04 — paired with the SOCIAL_HOSTS rows added the same day.
        // The six detect-only brands were removed from both maps the same
        // night (F8) — see the SOCIAL_HOSTS block for why.
        //
        // bluesky names its SURFACE KEY, not its brand key. Bluesky.php
        // declares no ->legacyPlatform(), so LegacyPlatformMap::surfaceFor()
        // cannot resolve a bare 'bluesky' and IntegrationConnection::booted()
        // rejected it — the ko-fi failure below, reached by a different route
        // (no legacy slug at all, rather than a diverging one). Naming the
        // surface directly is the same thing ORDERING_PLATFORM and
        // RESERVATION_PLATFORM already do for all 45 of their entries
        // ('uber_eats.order', 'thefork.reserve'), and it keeps the fix inside
        // this map instead of recompiling the catalog for one alias.
        'bluesky' => ['bluesky.profile', 'Bluesky'],
        'buymeacoffee' => ['buymeacoffee', 'Buy Me a Coffee'],
        'codepen' => ['codepen', 'CodePen'],
        'gitlab' => ['gitlab', 'GitLab'],
        'kick' => ['kick', 'Kick'],
        'ko-fi' => ['ko-fi', 'Ko-fi'],
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

                continue;
            }

            // The four constants are host-only; the catalog names booking /
            // reservations / ordering brands they have never heard of, and until
            // 2026-08-30 this loop stopped here — so such a brand on a scraped
            // homepage was not mis-bucketed, it was ABSENT (shortcuts.book,
            // easi.order, and every future brand in those three classes).
            //
            // classify(), not classifyFromCatalog(): the same promotion the
            // single-URL entry point applies, so the two cannot drift apart
            // again. Only the three promotable categories bucket — a detect-only
            // surface answers 'link' and stays out, which is what keeps the
            // remaining detect-only socials (yelp.listing, trustpilot.listing…
            // — anything not hand-added to SOCIAL_HOSTS above) out of
            // $socials. Do NOT add a socials arm; that reverses the decision.
            // Cost is one pure projector call per otherwise-unmatched link:
            // 7.3ms -> 22.4ms on a 1000-link page, 1.32ms on a realistic 60-link
            // one (measured 2026-08-30). Both callers are queued jobs.
            $classified = $this->classify($url);
            match ($classified['category'] ?? null) {
                'booking' => $booking[] = $url,
                'reservations' => $reservationLinks[] = ['url' => $url],
                'online-ordering' => $orderProviders[] = ['name' => $classified['label'], 'url' => $url],
                default => null,
            };
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

        // Spotify Podcasts BEFORE the generic social loop: SOCIAL_HOSTS['spotify']
        // is host-only ('~(^|\.)spotify\.com$~') and cannot express the /show/
        // path, so without this carve-out a podcast show page falls into the
        // generic 'spotify' answer and LinkRouter::routeClassified ->
        // seedSocial -> resolveSocialLink writes an IntegrationConnection on
        // spotify.player — the music-player surface a show was deliberately
        // moved OFF (Spotify.php's detector comment: "show left for
        // spotify_podcasts.show ... only new routing/connects move"). The
        // 'platform' value here is the FULL dotted surface key, not a bare
        // brand slug — the same convention SOCIAL_PLATFORM already uses for
        // deezer.artist and bluesky.profile (see those entries' own
        // comments): those two brands' write() call has no legacy alias to
        // resolve through either, so surfaceFor($platform) ?? $platform must
        // already receive the real surface key to fall back to. A bare
        // 'spotify_podcasts' would self-resolve to a malformed surface_key
        // instead. Same host+path predicate as MediaPageReader::
        // accountPlatform()'s own /show/ arm, kept in sync by hand rather
        // than shared because this class already duplicates none of that
        // method's other arms and a shared call would also change category
        // ('social' here vs an account answer there) for the other 20
        // platforms it names. Found by the 2026-09-04 overnight W9
        // completeness critic re-checking F9 (which fixed only the
        // item-paste lane, MediaPageReader, not this one) against the
        // auto-sync lane.
        if ($host === 'open.spotify.com'
            && preg_match('~^(?:/intl-[a-z]{2,5})?/show/~', (string) parse_url($url, PHP_URL_PATH))
            && $this->looksLikeProfile($url)) {
            return ['platform' => 'spotify_podcasts.show', 'category' => 'social', 'label' => 'Spotify Podcasts'];
        }

        foreach (self::SOCIAL_HOSTS as $key => $pattern) {
            // No isset() guard needed: SOCIAL_PLATFORM is hand-maintained with
            // the exact same keys as SOCIAL_HOSTS, so the lookup below can
            // never miss for a $key drawn from this loop. ADD TO BOTH MAPS —
            // a row in only one is an undefined-key ErrorException here, not a
            // missed classification (it happened adding Skool, 2026-09-03).
            // The count was written as "7 keys" when there were 7; it drifted
            // to 24 without the sentence being corrected, which is exactly the
            // reading that makes someone add to one map and stop.
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

        // 2026-09-04 — fourteen brands added from live-researched path
        // grammars (research phase this same run): each catalog entry above
        // was a MarketplaceListing host-only detect-only surface (or, for
        // bandsintown/dice/songkick, an existing artist/venue path capture
        // this reclassifies as event-organiser and pairs with a new event
        // shape), and none of them could tell a single event apart from an
        // organiser/venue listing page — this block is what makes that
        // distinction, same job Eventbrite/Humanitix do above.
        if (preg_match('~(^|\.)(admitone\.com|admitonelive\.com)$~', $host)) {
            // Two hosts, NOT one shape — critic-caught 2026-09-04: the
            // original comment here assumed admitonelive.com shared
            // admitone.com's grammar since it wasn't independently curled;
            // corpus-real.php's actual recorded URLs
            // (tickets.admitonelive.com/event/dropout-improv-vancouver-9812776)
            // use a singular /event/<slug>-<numericId> path, not the plural
            // /events/.../<24-hex-ObjectId> shape below. Both branches kept,
            // now genuinely per-host. /organizer/ first: it is the venue's
            // own multi-event listing page, the same hazard Eventbrite's /o/
            // and Humanitix's /host/ guard against.
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~^/organizer/~i', $path)) {
                return ['platform' => 'admitone', 'category' => 'event-organiser', 'label' => 'AdmitONE'];
            }
            // The trailing 24-char hex Mongo ObjectId is load-bearing: a
            // bare /events/<city> path is a city LISTING page (confirmed
            // live, title "Events in Vancouver"), not an event.
            if (preg_match('~^/events/.+/[0-9a-f]{24}(?:[/?#]|$)~i', $path)) {
                return ['platform' => 'admitone', 'category' => 'event', 'label' => 'AdmitONE'];
            }
            // tickets.admitonelive.com's real shape: singular /event/, a
            // slug, then a trailing numeric id (not hex, not 24-char).
            if (preg_match('~^/event/[a-z0-9-]+-\d+/?$~i', $path)) {
                return ['platform' => 'admitone', 'category' => 'event', 'label' => 'AdmitONE'];
            }
        }
        if (preg_match('~(^|\.)etix\.com$~', $host)) {
            // Live content could not be fetched (AWS WAF JS challenge on
            // every /ticket/... path) — shape rests on a followed 301
            // (viewPerformanceGroup.jsp -> /ticket/o/og/<id>/<slug>) and
            // indexed page titles, not a curled body. /v/<venueId> and
            // /o/og/<groupId> are both multi-date listings (a venue's own
            // page, or a tour/performance-group spanning many dates) —
            // event-organiser, checked before the single-performance /p/.
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~^/ticket/(?:v/\d+|o/og/\d+)~i', $path)) {
                return ['platform' => 'etix', 'category' => 'event-organiser', 'label' => 'Etix'];
            }
            if (preg_match('~^/ticket/p/\d+~i', $path)) {
                return ['platform' => 'etix', 'category' => 'event', 'label' => 'Etix'];
            }
        }
        if (preg_match('~(^|\.)eventfinda\.(?:com\.au|co\.nz|com)$~', $host)) {
            // Event shape is a bare year-prefixed path: /{YYYY}/{slug}/{city}
            // [/{suburb}]. No promoter/organiser page shape exists on this
            // brand (checked) — /venue/<slug> is a multi-year listing but
            // simply fails this regex and falls through, same as
            // Ticketmaster's artist pages.
            if (preg_match('~^/(?:19|20)\d{2}/[a-z0-9-]+/[a-z0-9-]+(?:/[a-z0-9-]+)?/?$~i', (string) parse_url($url, PHP_URL_PATH))) {
                return ['platform' => 'eventfinda', 'category' => 'event', 'label' => 'Eventfinda'];
            }
        }
        if (preg_match('~(^|\.)eventim\.(?:de|com|co\.uk|fr|nl|pl)$~', $host)) {
            // No TLDS const on Eventim.php to drive brandTldRegex() — its
            // six catalog hosts are inlined here instead. Every regional TLD
            // tested (de, co.uk; fr/nl/pl/com inferred, same CTS Eventim
            // codebase) shares one /event/<slug>-<id>/ shape, including
            // under a locale prefix (/en/event/...) — Ticketmaster-style
            // substring match is robust to that. /artist/<slug> is a
            // tour-wide browse page (all dates), not a seller account —
            // deliberately not given an event-organiser arm, it just fails
            // to contain '/event/' and falls through.
            if (str_contains(strtolower((string) parse_url($url, PHP_URL_PATH)), '/event/')) {
                return ['platform' => 'eventim', 'category' => 'event', 'label' => 'Eventim'];
            }
        }
        if (preg_match('~(^|\.)megatix\.com\.au$~', $host)) {
            // Path-gated (unlike Ticketek's unconditional host match) because
            // this host does carry real non-event marketing pages
            // (/sell-tickets, /orders). Two confirmed single-event shapes:
            // /events/<slug> and /white-label/<slug> (a rebranded
            // single-promoter checkout). No organiser/venue listing shape
            // exists anywhere on the site (catalog docblock's own claim,
            // independently reconfirmed).
            $megatixPath = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~^/(?:events|white-label)/[a-z0-9-]+~i', $megatixPath)) {
                return ['platform' => 'megatix', 'category' => 'event', 'label' => 'Megatix'];
            }
            // Critic-caught 2026-09-04: corpus-real.php also has a
            // bare-root-slug shape with neither prefix
            // (megatix.com.au/SnowMachineQueenstownAud) — the site mints
            // some event slugs straight off the root.
            //
            // That branch first shipped with a hand-enumerated denylist, which
            // was written from imagination rather than from the site: it named
            // paths megatix does not serve ('cart', 'checkout', 'register')
            // while missing three it does — /privacy-policy, /terms-conditions
            // and /support all classified as EVENTS, so pasting a legal page
            // was told "that belongs on your Events page" and could then be
            // added to the events pool, since this grammar answer is the only
            // server-side gate there. An enumeration cannot win this: the next
            // -policy/-conditions/-centre variant is always unlisted.
            //
            // So: SHAPE first, words only for what shape cannot see. Megatix's
            // real root slugs are minted from event titles and are CamelCase
            // or a single lowercase word (SnowMachineQueenstownAud, miniraves,
            // Restricted — all three of the confirmed genuine ones); its
            // chrome is kebab-case. An all-lowercase slug containing a hyphen
            // is therefore chrome, whatever it is called. A genuine
            // lowercase-hyphenated event would fall through to a link card,
            // which is this file's stated safe default (see the skiddle arm).
            //
            // The word list stays for single lowercase words, where shape says
            // nothing. It is NOT shared with HumanitixScraper::NON_EVENT_SLUGS
            // or PastedLinkClassifier::PROFILE_CHROME even though all three
            // look alike: those answer different questions, and the latter
            // holds 'events' and 'video', which are not chrome on a ticketing
            // root.
            if (preg_match('~^/([A-Za-z0-9-]{4,})/?$~', $megatixPath, $m)) {
                $slug = $m[1];
                $lower = strtolower($slug);
                $kebabChrome = $slug === $lower && str_contains($slug, '-');
                $wordChrome = in_array($lower, [
                    'about', 'account', 'blog', 'careers', 'cart', 'checkout',
                    'contact', 'cookies', 'events', 'faq', 'faqs', 'help',
                    'legal', 'login', 'orders', 'press', 'pricing', 'privacy',
                    'refunds', 'register', 'search', 'sell', 'signin', 'signup',
                    'support', 'terms',
                ], true);

                if (! $kebabChrome && ! $wordChrome) {
                    return ['platform' => 'megatix', 'category' => 'event', 'label' => 'Megatix'];
                }
            }
        }
        if (preg_match('~(^|\.)moshtix\.com\.au$~', $host)) {
            // /v2/venues/<slug>/<id> is a venue's own rolling listing of 90+
            // gigs (confirmed live) — the Humanitix /host/ hazard, checked
            // first. /v2/event/<slug>/<id> is the single-ticket page. No
            // artist entity page shape exists (catalog docblock).
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~^/v2/venues/[a-z0-9-]+/\d+~i', $path)) {
                return ['platform' => 'moshtix', 'category' => 'event-organiser', 'label' => 'Moshtix'];
            }
            if (preg_match('~^/v2/event/[a-z0-9-]+/\d+~i', $path)) {
                return ['platform' => 'moshtix', 'category' => 'event', 'label' => 'Moshtix'];
            }
        }
        if (preg_match('~(^|\.)tickethype\.com\.mt$~', $host)) {
            // Every event lives at a bare single-segment slug under the
            // root — no /events/ prefix at all on this brand. The domain
            // root itself is a host-wide marketplace index (not one
            // promoter's page), excluded by requiring a non-empty path
            // segment rather than a bespoke event-organiser arm. Same
            // unconditional-host-family risk Ticketek/Oztix/TryBooking
            // already accept below.
            if (preg_match('~^/[a-z0-9][a-z0-9-]{2,}~i', (string) parse_url($url, PHP_URL_PATH))) {
                return ['platform' => 'tickethype', 'category' => 'event', 'label' => 'TicketHype'];
            }
        }
        if (preg_match('~(^|\.)tixr\.com$~', $host)) {
            // /groups/<org>/events/<slug> is the single-event page;
            // /groups/<org> alone (no /events/ suffix) is the promoter/venue
            // landing page — directly analogous to Eventbrite's /o/<slug>.
            // The two shapes never overlap (the event shape always has a
            // further /events/ segment), so order is not load-bearing, but
            // organiser is checked first to match the Eventbrite/Humanitix
            // precedence style. NOT requiring a literal "--" before the
            // trailing id: the finding's own confirmed example
            // (.../events/riot-fest-2026-158068) uses a single dash, so a
            // double-dash requirement would have missed its own evidence.
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~^/groups/[a-z0-9-]+/?$~i', $path)) {
                return ['platform' => 'tixr', 'category' => 'event-organiser', 'label' => 'Tixr'];
            }
            if (preg_match('~^/groups/[a-z0-9-]+/events/[a-z0-9-]+~i', $path)) {
                return ['platform' => 'tixr', 'category' => 'event', 'label' => 'Tixr'];
            }
        }
        if (preg_match('~(^|\.)seetickets\.com$~', $host)) {
            // (^|\.) also covers the white-label promoter subdomains this
            // brand runs on the same path shape (e.g.
            // mutations.seetickets.com/event/...). /tour/<artist> is a
            // multi-date tour listing — it simply does not contain '/event/'
            // and falls through, same as a Ticketmaster artist page; no
            // bespoke event-organiser arm needed.
            if (str_contains(strtolower((string) parse_url($url, PHP_URL_PATH)), '/event/')) {
                return ['platform' => 'see_tickets', 'category' => 'event', 'label' => 'See Tickets'];
            }
        }
        if (preg_match('~(^|\.)skiddle\.com$~', $host)) {
            // /g/<slug> is a promoter/brand "group" page (curl-confirmed,
            // og:type="group") — the clearest organiser shape found, checked
            // first. Event shape requires the trailing numeric id under
            // /whats-on/ (a bare City/ or City/Venue/ path is a browse page,
            // not an event). A secondary venue-listing organiser shape was
            // also found (/whats-on/City/Venue/, no trailing id) but with
            // only one example verified — left unclassified rather than
            // guessed; it already fails the event regex below and falls
            // through to a link card, which is the safe default.
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~^/g/[^/]+/?$~i', $path)) {
                return ['platform' => 'skiddle', 'category' => 'event-organiser', 'label' => 'Skiddle'];
            }
            if (preg_match('~^/whats-on/(?:[^/]+/){2,}\d+/?$~i', $path)) {
                return ['platform' => 'skiddle', 'category' => 'event', 'label' => 'Skiddle'];
            }
        }
        if (preg_match('~(^|\.)ticketweb\.(?:com|co\.uk|ca)$~', $host)) {
            // No TLDS const on Ticketweb.php — its three catalog hosts are
            // inlined here. Same '/event/' / '/venue/' substring style as
            // the Ticketmaster arm above, which is expected: TicketWeb is
            // Ticketmaster's independent-venue brand on the same software.
            $path = strtolower((string) parse_url($url, PHP_URL_PATH));
            if (str_contains($path, '/venue/')) {
                return ['platform' => 'ticketweb', 'category' => 'event-organiser', 'label' => 'TicketWeb'];
            }
            if (str_contains($path, '/event/')) {
                return ['platform' => 'ticketweb', 'category' => 'event', 'label' => 'TicketWeb'];
            }
        }
        if (preg_match('~(^|\.)bandsintown\.com$~', $host)) {
            // The catalog's own /a/{id} capture is a multi-show artist
            // listing ("34 upcoming shows"), not a single event — reclassed
            // event-organiser here, analogous to Eventbrite's /o/<org>.
            // /e/{id}-{slug} (new shape, no catalog detector yet) is the
            // single dated show. The /e/ slug can carry periods/apostrophes
            // (venue names like "crypto.com-arena"), hence the permissive
            // [^/?#]+ instead of the stricter charset used for /a/.
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~^/a/\d{1,12}(?:-[a-z0-9-]+)?/?$~i', $path)) {
                return ['platform' => 'bandsintown', 'category' => 'event-organiser', 'label' => 'Bandsintown'];
            }
            if (preg_match('~^/e/\d{1,12}(?:-[^/?#]+)?/?$~i', $path)) {
                return ['platform' => 'bandsintown', 'category' => 'event', 'label' => 'Bandsintown'];
            }
        }
        if (preg_match('~(^|\.)dice\.fm$~', $host)) {
            // The catalog's own capture (all four entity kinds share one
            // grammar) is a multi-show profile page — reclassed
            // event-organiser. /event/{slug} (new shape) embeds a
            // schema.org MusicEvent with one startDate/endDate/location: a
            // genuine single show. The two prefixes never collide.
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~^/(?:artist|venue|promoter|partner)/[a-z0-9-]+/?$~i', $path)) {
                return ['platform' => 'dice', 'category' => 'event-organiser', 'label' => 'DICE'];
            }
            if (preg_match('~^/event/[a-z0-9-]+/?$~i', $path)) {
                return ['platform' => 'dice', 'category' => 'event', 'label' => 'DICE'];
            }
        }
        if (preg_match('~(^|\.)songkick\.com$~', $host)) {
            // The catalog's own /artists/{id} capture is a tour-schedule
            // listing ("AC/DC Full Tour Schedule") — reclassed
            // event-organiser. /concerts/{id}-{slug} (new shape) is one
            // dated show. The regex requires digits immediately after
            // /concerts/, which excludes both /concerts/similar-to/{id}-…
            // and the bare /concerts discovery page.
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~^/artists/\d{1,12}(?:-[a-z0-9-]+)?/?$~i', $path)) {
                return ['platform' => 'songkick', 'category' => 'event-organiser', 'label' => 'Songkick'];
            }
            if (preg_match('~^/concerts/\d{1,12}(?:-[a-z0-9-]+)?/?$~i', $path)) {
                return ['platform' => 'songkick', 'category' => 'event', 'label' => 'Songkick'];
            }
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
     * ordering is the whole design. They are host-only by construction and
     * answer correctly for the catalog hosts they cover.
     *
     * (Historical note: the original argument for running SECOND was that
     * LinkProjector::FLOOR was 35, above what a bare host-only detector scores,
     * so a catalog-first order would have downgraded Booksy, GitHub, DoorDash,
     * Resy and Treatwell to link cards. FLOOR has been 25 since the N1 fix —
     * LinkProjector.php:26-44 — and Projection::matched() has no confidence
     * test, so identification is no longer the reason. The ordering survives
     * because these tables carry CATEGORY decisions the catalog cannot make:
     * see the policy block above SOCIAL_HOSTS.)
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

            $routingClass = LegacyPlatformMap::routingClassFor($projection->surfaceKey);

            if ($routingClass === 'shop') {
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

            // is_connectable is the second condition, not a nicety: promoting a
            // surface the catalog itself refuses to connect would hand
            // seedBooking() / seedOnlineOrdering() a write they must never make.
            // It is what holds microsoft_bookings.book and wix_bookings.book at
            // 'link' — both are the right class, both are path-identified
            // brands on shared registrable domains, neither is connectable.
            $category = ($surface['is_connectable'] ?? false) === true
                ? (self::PROMOTABLE_ROUTING_CLASS[$routingClass] ?? 'link')
                : 'link';

            return [
                'platform' => LegacyPlatformMap::legacyFor($projection->surfaceKey),
                'category' => $category,
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
    /**
     * Anchor text per outbound URL — the tile TITLES a link page read straight
     * from its HTML carries ("Gamma+ - CODE: TEEGAN10"), for the same sniffs
     * the vendor-API rows get (2026-09-02). Same anchors, same visibility
     * rule and URL resolution as allOutboundLinks(); first non-empty text per
     * URL wins, whitespace collapsed, capped at 200 characters.
     *
     * @return array<string, string> absolute url => title
     */
    public function anchorTitles(string $html, string $baseUrl): array
    {
        $doc = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $loaded) {
            return [];
        }
        $titles = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || $this->isHiddenAnchor($a)) {
                continue;
            }
            $abs = $this->absolutize($href, $baseUrl);
            if ($abs === null || isset($titles[$abs])) {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $a->textContent));
            if ($text === '') {
                continue;
            }
            $titles[$abs] = mb_substr($text, 0, 200);
            if (count($titles) >= 1000) {
                break;
            }
        }

        return $titles;
    }

    private function extractLinks(string $html, string $baseUrl): array
    {
        $doc = new \DOMDocument;
        // Suppress libxml warnings for real-world HTML.
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET (#W1-SEC-13/#W2-SEC-16): $html is a third party's page,
        // fetched through SafeUrlFetcher — but that guard covers the FETCH, not
        // the PARSE. NONET blocks every network access libxml would make while
        // parsing (external entity/DTD/stylesheet), so the parser cannot become
        // a second egress point the SSRF guard never sees. Same flag, same
        // reasoning as MetadataParser and AboutProseExtractor. The seven other
        // app/Services/WebsiteScan/ callers noted here 2026-08-31 got the same
        // fix under #FU-1 (VisibleTextExtractor, PdfLinkDetector,
        // FaviconFetcher, SquarespaceMenuExtractor, WebsiteLogoCandidateExtractor,
        // ContactEmailExtractor, WebsiteGalleryCandidateExtractor).
        $loaded = $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
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
