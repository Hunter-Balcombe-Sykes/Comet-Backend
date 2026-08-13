<?php

namespace App\Services\PublicSite;

use App\Http\Controllers\Api\PublicSite\IndividualProfileController;
use App\Http\Resources\PublicSite\IndividualProfileResource;
use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\ContentSelection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Design\ProfileDesignPresets;
use App\Services\Site\ContentSelectionService;
use App\Services\Site\SitePolicyResolver;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pure projection helper — assembles the §28.8 public profile payload from a
 * User + Site without owning the cache. The controller and the warm
 * job both consume this so the two paths can't drift on field shape.
 *
 * Cache wrapper (CacheLockService::rememberLocked) is the caller's concern;
 * this class exposes the canonical cache key + TTL so both call sites stay
 * aligned on key shape.
 *
 * Payload shape (skeleton system, spec §3.4 + phase-8 engines):
 *   {
 *     profile: {
 *       handle, displayName, site_id,
 *       gallery: GalleryImage[],
 *       links: ProfileLink[],
 *       services: ProfileService[],
 *       document: DocumentData | null,
 *       newsletter: NewsletterData | null,
 *       contact: ContactData | null,
 *       publicContact: { email, phone } | null,
 *       workplace: WorkplaceData | null,
 *     },
 *     designKit: { colors: {...}, typography: {...}, ... },
 *     designMedia: DesignMediaItem[],
 *     architectureId: 'staple',
 *     publicConfig: { analyticsEndpoint, ... },
 *   }
 *
 * Each engine field falls back to a stable empty state so the architecture
 * never has to guard on `undefined`:
 *   - object engines (document, newsletter) → null when nothing authored
 *   - list engines (gallery, links, services) → empty array
 *
 * Booking is a link-engine category (`ProfileLink.category === 'booking'`),
 * not a separate engine field — `bucketLinks` in @partnaau/design-system
 * splits the list at render time.
 *
 * @see IndividualProfileController
 * @see WarmPublicSiteCacheJob
 */
class IndividualProfilePayloadBuilder
{
    public function __construct(
        private readonly SitepageDataResolverService $resolver,
        private readonly ContentPopularityReader $popularity,
        private readonly SiteActionsService $actions,
        private readonly ContentSelectionService $selection,
        private readonly SitePolicyResolver $policies,
        private readonly PoolResolver $pools,
    ) {}

    /**
     * Build the §28.8 resolved payload. Reads:
     *   - the user's content sections via SitepageDataResolverService
     *   - the per-user design_kit row (partial — only stored non-null cols)
     *   - the site's architecture_id (TEXT enum)
     *   - platform-wide publicConfig fields (analytics endpoint, etc.)
     *
     * @return array<string, mixed>
     */
    public function build(User $pro, ?Site $site): array
    {
        $sections = $this->resolver->loadSections($site);
        $booking = $this->resolver->getBooking($site, $sections);
        $caps = AccountCapabilities::for($pro);

        // One indexed read of content_popularity_scores per build (behind the 60s
        // public-profile cache). Ranks ANNOTATE the content arrays + drive
        // pageOrder — arrays are NEVER reordered (live architectures read them
        // positionally). Empty maps when scoring hasn't run for the site.
        $ranks = $this->popularity->forSite($site?->id);

        // Ordering preferences (smart vs manual, defaults = smart) + the
        // unified ranked-action list. Manual overrides are applied HERE so
        // pageOrder / rankedActions on the wire are always the final,
        // render-ready values — consumers never re-derive.
        $ordering = $this->actions->orderingSettings($site);

        $pageOrder = $ordering['smart_page_order']
            ? $this->resolver->buildPageOrder($site, $caps, $sections, $ranks['page'] ?? [])
            : $this->actions->applyManualPageOrder(
                $this->resolver->presentPageIds($site, $caps, $sections),
                $ordering['manual_page_order'],
            );

        $rankedActions = $this->actions->resolveRankedActions(
            $this->actions->pool($pro, $site, $sections, $booking),
            $this->popularity->rankedActionsForSite($site?->id),
            $ordering['smart_actions'],
            $ordering['manual_actions'],
        );

        // Contact + workplace resolved once — the wire keys below reuse them,
        // and the policy resolver personalizes its generated texts from them
        // (business name, public contact email).
        $publicContact = $this->buildPublicContact($pro, $sections);
        $workplace = $this->buildWorkplace($site, $sections);

        return (new IndividualProfileResource($pro, [
            'site_id' => $site?->id,
            'design_kit' => $this->loadDesignKit($site, $pro),
            'design_media' => $this->buildDesignMedia($site),
            'site_images' => $this->buildSiteImages($site),
            'architecture_id' => $site?->architecture_id ?? Site::DEFAULT_ARCHITECTURE_ID,
            'public_config' => $this->buildPublicConfig($pro),
            // Taxonomy page order for the ONE architecture — presence + business
            // gated, popularity-ranked (or the owner's manual order when
            // smart_page_order is off), canonical fallback. Top-level key.
            'page_order' => $pageOrder,
            // Full popularity map (content_type => content_key => rank) so the ONE
            // theme can order ANY item type uniformly, without per-platform payload
            // surgery. Same $ranks the per-item annotations below already use.
            'popularity' => $ranks,
            // Unified ranked actions (page|item|button|custom) — the lander
            // renders the top 6. Override-applied (manual list when
            // smart_actions is off); ordering carries the raw preferences.
            'ranked_actions' => $rankedActions,
            'ordering' => $this->actions->orderingWire($ordering),
            // Engine outputs — flat, camelCase, no envelope wrapper.
            'gallery' => $this->buildGallery($site, $sections, $ranks['gallery_item'] ?? []),
            'curatedGallery' => $this->resolver->buildCuratedGalleryData($site),
            'pools' => $this->buildPools($site),
            'links' => $this->buildLinks($site, $booking, $ranks['block'] ?? []),
            'services' => $this->buildServices($site, $pro->id, $sections, $ranks['service'] ?? []),
            'document' => $this->buildDocument($site),
            'newsletter' => $this->buildNewsletter($sections),
            'contact' => $this->buildContact($sections),
            'publicContact' => $publicContact,
            'workplace' => $workplace,
            // Resolved site policies (Privacy / Terms) — auto-generated by
            // default, owner's manual text when they opted out of automated.
            // The sitepage renders these at /privacy and /terms.
            'policies' => $this->policies->resolve(
                $pro,
                $site,
                $workplace['name'] ?? null,
                $publicContact['email'] ?? null,
            ),
        ]))->resolve();
    }

    /**
     * Public-contact engine — {email, phone} | null. Own top-level wire key;
     * no longer nested under the (removed) bio engine.
     *
     * @param  Collection<string, Block>  $sections
     * @return array{email: string|null, phone: string|null}|null
     */
    private function buildPublicContact(User $pro, Collection $sections): ?array
    {
        $data = $this->resolver->getPublicContact($pro, $sections)['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        return [
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ];
    }

    /**
     * Workplace engine — WorkplaceData | null.
     *
     * Returns null when the workplace section is not live (block
     * missing / is_active or is_enabled false) OR when no workplace
     * data has been stored. Resolver hands back snake_case keys
     * mirroring the JSONB shape on site.settings.workplace; we remap
     * to the wire's camelCase here.
     *
     * @param  Collection<string, Block>  $sections
     * @return array{name: string, description: string|null, addressLine1: string|null, city: string|null, state: string|null, postcode: string|null, country: string|null, latitude: float|null, longitude: float|null, phone: string|null, website: string|null}|null
     */
    private function buildWorkplace(?Site $site, Collection $sections): ?array
    {
        $envelope = $this->resolver->getWorkplace($site, $sections);
        $data = $envelope['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        return [
            'name' => (string) ($data['name'] ?? ''),
            'description' => $data['description'] ?? null,
            'addressLine1' => $data['address_line1'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postcode' => $data['postcode'] ?? null,
            'country' => $data['country'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'phone' => $data['phone'] ?? null,
            'website' => $data['website'] ?? null,
        ];
    }

    /**
     * Gallery engine — GalleryImage[] (empty array when nothing live).
     *
     * Remaps the resolver's snake_case keys (alt_text, duration_ms) to the
     * camelCase wire shape (alt, durationMs).
     *
     * `popularityRank` is an inert annotation (nullable; V1/ONE consumes it) keyed
     * by the media id — the array ORDER is untouched (skeletons read positionally).
     *
     * @param  Collection<string, Block>  $sections
     * @param  array<string, int>  $ranks  gallery_item content_key (media id) → rank
     * @return list<array{id: string, url: string, urlHd: string|null, alt: string|null, caption: string|null, kind: string, poster: string|null, durationMs: int|null, popularityRank: int|null}>
     */
    private function buildGallery(?Site $site, Collection $sections, array $ranks = []): array
    {
        $envelope = $this->resolver->getGallery($site, $sections);
        $items = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        return array_values(array_map(static fn (array $item): array => [
            // The SiteMedia UUID — gallery_item scores + item beacons key by it;
            // without it on the wire the sitepage can never emit scoreable
            // gallery impressions/clicks.
            'id' => (string) ($item['id'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'urlHd' => $item['url_hd'] ?? null,
            'alt' => $item['alt_text'] ?? null,
            'caption' => $item['caption'] ?? null,
            'kind' => (string) ($item['kind'] ?? 'image'),
            'poster' => $item['poster'] ?? null,
            'durationMs' => $item['duration_ms'] ?? null,
            'popularityRank' => $ranks[(string) ($item['id'] ?? '')] ?? null,
        ], $items));
    }

    /**
     * The sitepage background media = the owner's curated content SELECTION
     * (ordered by position on /account/content), NOT the raw content-media
     * library. ContentSelectionService::resolve returns the servable entries in
     * order — uploads, Google Business photos, and (when Instagram-auto is on)
     * the reserved ig-reel (slot 1) + ig-post (slot 2) with the reel carrying a
     * poster. Unservable rows (disconnected IG, missing/soft-deleted upload,
     * dangling Google ref) are already dropped by resolve(). Projected into the
     * same camelCase DesignMediaItem shape the sitepage consumes. `origin`
     * carries the selection entry_type (upload | google-photo | ig-reel |
     * ig-post) so the sitepage can tell the reserved Instagram slots from the
     * owner's own picks (the backdrop's reel → post → rotation ladder).
     *
     * @return list<array{id: string, sortOrder: int, kind: string, origin: string, url: string, urlHd: null, alt: null, caption: null, poster: string|null, durationMs: null}>
     */
    private function buildDesignMedia(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        $out = [];
        foreach ($this->selection->resolve($site) as $i => $entry) {
            $url = (string) ($entry['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $out[] = [
                'id' => (string) ($entry['id'] ?? ''),
                'sortOrder' => (int) ($entry['position'] ?? $i),
                'kind' => (string) ($entry['kind'] ?? 'image'),
                'origin' => (string) ($entry['type'] ?? ContentSelection::TYPE_UPLOAD),
                'url' => $url,
                'urlHd' => null,
                'alt' => null,
                'caption' => null,
                'poster' => $entry['poster'] ?? null,
                'durationMs' => null,
            ];
        }

        return $out;
    }

    /**
     * The content pools (platforms-as-sources, 2026-08-05): each pool's
     * public SELECTION — pins + every auto-source's rolling latest, minus
     * removals — resolved LIVE by the same PoolResolver the dashboard reads,
     * so what the owner curates and what a visitor sees cannot diverge. The
     * library never ships publicly; a pool with nothing selected is simply
     * absent. Wire: {watch|listen|media: {items, latestItemId}}; shop adds a
     * sibling `collections` map of the store cards its items belong to.
     *
     * @return array<string, array{items: list<array<string, mixed>>, latestItemId: string|null, collections?: array<string, array<string, mixed>>}>
     */
    private function buildPools(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        $out = [];
        foreach (array_keys(PoolRegistry::POOLS) as $pool) {
            try {
                $resolved = $this->pools->resolve($site, $pool);
            } catch (QueryException) {
                // Partial test envs may not provision the content/sections
                // tables (the getContentMedia precedent); in production they
                // always exist. A missing lane yields no pools, never a 500.
                return [];
            }
            if ($resolved['selection'] === []) {
                continue;
            }
            $out[$pool] = [
                'items' => array_map(
                    // The dashboard-only flags stay off the public wire.
                    static function (array $item): array {
                        unset($item['selected']);

                        return $item;
                    },
                    $resolved['selection'],
                ),
                'latestItemId' => $resolved['latestItemId'],
                // Shop groups its items into store cards; every other pool
                // returns [] and the key is simply absent from its payload.
                ...($resolved['collections'] === [] ? [] : ['collections' => $resolved['collections']]),
                // Slice 6 §5.4: reviews carries its source's aggregates — the
                // star average, review count and Google's review summary. This
                // is where `rating`, `reviewCount` and `reviewSummary` went
                // when they left PublicIntegrationConnectionResource; without
                // it that retirement drops three published fields on the floor.
                // Absent when null, the same contract `collections` keeps.
                ...($resolved['stats'] === null ? [] : ['stats' => $resolved['stats']]),
            ];
        }

        return $out;
    }

    /**
     * Site image singletons — brand logos + the brand placeholder image, keyed
     * by camelCase purpose (logoFull, logoSquare, placeholder). The cover*
     * keys left the wire 2026-08-05 when the owner retired per-integration
     * covers. Each value is {url, urlHd, urlSvg, urlIcon} (urlSvg only for
     * vectorized logos; urlIcon only for square logos — the sitepage favicon
     * source); absent purposes have no uploaded/ready image. Empty object when
     * nothing is set. The theme decides how (if at all) to render them.
     *
     * @return array<string, array{url: string, urlHd: string|null, urlSvg: string|null, urlIcon: string|null}>
     */
    private function buildSiteImages(?Site $site): array
    {
        $singletons = $this->resolver->getDesignSingletons($site);

        $out = [];
        foreach ($singletons as $purpose => $urls) {
            // snake_case purpose → camelCase wire key (cover_apple_music → coverAppleMusic).
            $key = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $purpose))));
            $out[$key] = [
                'url' => (string) ($urls['url'] ?? ''),
                'urlHd' => $urls['url_hd'] ?? null,
                'urlSvg' => $urls['url_svg'] ?? null,
                'urlIcon' => $urls['url_icon'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Links engine — ProfileLink[] (empty array when nothing live).
     *
     * The resolver already returns the right shape (id/title/url/category/
     * platform) plus a synthesised booking row when the booking envelope is
     * live. We just normalise `id` to `string|null` (resolver uses '' for
     * synthesised rows) so the wire matches `ProfileLink.id: string | null`.
     *
     * `popularityRank` is an inert annotation (nullable; V1/ONE consumes it) keyed
     * by the block id — synthesised booking rows (id '') carry null. Array ORDER
     * is untouched (skeletons read positionally).
     *
     * @param  array{state: string, data: array|null}  $bookingEnvelope
     * @param  array<string, int>  $ranks  block content_key (block id) → rank
     * @return list<array{id: string|null, title: string, url: string, category: string, platform: string|null, popularityRank: int|null}>
     */
    private function buildLinks(?Site $site, array $bookingEnvelope, array $ranks = []): array
    {
        $links = $this->resolver->getLinks($site, $bookingEnvelope);

        return array_values(array_map(static function (array $link) use ($ranks): array {
            $id = (string) ($link['id'] ?? '');

            return [
                'id' => $id !== '' ? $id : null,
                'title' => (string) ($link['title'] ?? ''),
                'url' => (string) ($link['url'] ?? ''),
                'category' => (string) ($link['category'] ?? 'custom'),
                'platform' => $link['platform'] ?? null,
                'popularityRank' => $id !== '' ? ($ranks[$id] ?? null) : null,
            ];
        }, $links));
    }

    /**
     * Services engine — ProfileService[] (empty array when nothing live).
     *
     * Per user direction, the wire shape drops bookingMode + manualBookingUrl
     * (booking is now a link-engine category). Remaps snake_case keys
     * (price_cents, currency_code, duration_minutes) to camelCase.
     *
     * Services have NO per-service booking URL — booking is site-level only
     * (Site.manual_booking_url, surfaced as the synthesised `booking` link).
     * `popularityRank` is an inert annotation (nullable; V1/ONE consumes it)
     * keyed by service id. Array ORDER is untouched (skeletons read positionally).
     *
     * @param  Collection<string, Block>  $sections
     * @param  array<string, int>  $ranks  service content_key (service id) → rank
     * @return list<array{id: string|int, title: string, description: string|null, priceCents: int|null, currencyCode: string|null, durationMinutes: int|null, category: string, popularityRank: int|null}>
     */
    private function buildServices(?Site $site, string $proId, Collection $sections, array $ranks = []): array
    {
        $envelope = $this->resolver->getServices($site, $proId, $sections);
        $data = $envelope['data'] ?? null;
        if (! is_array($data)) {
            return [];
        }

        $services = is_array($data['services'] ?? null) ? $data['services'] : [];

        return array_values(array_map(static fn (array $service): array => [
            'id' => $service['id'],
            'title' => (string) ($service['title'] ?? ''),
            'description' => $service['description'] ?? null,
            'priceCents' => $service['price_cents'] ?? null,
            'currencyCode' => $service['currency_code'] ?? null,
            'durationMinutes' => $service['duration_minutes'] ?? null,
            'category' => (string) ($service['category'] ?? 'Services'),
            'popularityRank' => $ranks[(string) ($service['id'] ?? '')] ?? null,
        ], $services));
    }

    /**
     * Document engine — DocumentData | null.
     *
     * Returns null when no ready+active document media row exists. Remaps
     * snake_case (download_url, size_bytes) to camelCase.
     *
     * @return array{id: string, title: string|null, caption: string|null, downloadUrl: string, mime: string|null, sizeBytes: int|null}|null
     */
    private function buildDocument(?Site $site): ?array
    {
        $envelope = $this->resolver->getDocument($site);
        $data = $envelope['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        return [
            'id' => (string) ($data['id'] ?? ''),
            'title' => $data['title'] ?? null,
            'caption' => $data['caption'] ?? null,
            'downloadUrl' => (string) ($data['download_url'] ?? ''),
            'mime' => $data['mime'] ?? null,
            'sizeBytes' => $data['size_bytes'] ?? null,
        ];
    }

    /**
     * Newsletter engine — NewsletterData | null.
     *
     * Reads the newsletter section block directly so we surface the single
     * `input_placeholder` field per spec §3.4. When the block is missing,
     * not-live, or has no input_placeholder set, returns null so skeletons
     * hide the signup form entirely.
     *
     * @param  Collection<string, Block>  $sections
     * @return array{inputPlaceholder: string}|null
     */
    private function buildNewsletter(Collection $sections): ?array
    {
        $section = $sections->get('newsletter');
        if (! $section instanceof Block) {
            return null;
        }
        $isLive = (bool) $section->is_active && (bool) $section->is_enabled;
        if (! $isLive) {
            return null;
        }

        $settings = is_array($section->settings) ? $section->settings : [];
        $placeholder = is_string($settings['input_placeholder'] ?? null)
            ? trim((string) $settings['input_placeholder'])
            : '';

        if ($placeholder === '') {
            return null;
        }

        return ['inputPlaceholder' => $placeholder];
    }

    /**
     * Contact engine — ContactData | null.
     *
     * Mirrors buildNewsletter: reads the contact section block directly and
     * surfaces only the public-safe form props. Gated on the SAME live test
     * the newsletter uses (is_active && is_enabled) so the form only appears
     * when the block is published.
     *
     * subjectOptions is the merged dropdown list — platform defaults
     * (config('partna.contact_subject_defaults')) followed by the block's
     * custom settings.subject_options, de-duplicated in that order. This MUST
     * match the controller's submission allowlist (PublicEnquiryController
     * step 4b) so every choice the form offers passes validation.
     *
     * Private owner settings (notification_email, notification_channels) are
     * intentionally NOT surfaced — they never belong in the public payload.
     *
     * @param  Collection<string, Block>  $sections
     * @return array{subjectOptions: list<string>, headline?: string, description?: string}|null
     */
    private function buildContact(Collection $sections): ?array
    {
        $section = $sections->get('contact');
        if (! $section instanceof Block) {
            return null;
        }
        $isLive = (bool) $section->is_active && (bool) $section->is_enabled;
        if (! $isLive) {
            return null;
        }

        $settings = is_array($section->settings) ? $section->settings : [];

        // Merge platform defaults + the block's custom additions, de-duped,
        // defaults first — same order + dedupe the submission validator uses.
        $defaults = (array) config('partna.contact_subject_defaults', []);
        $custom = is_array($settings['subject_options'] ?? null) ? $settings['subject_options'] : [];
        $subjectOptions = array_values(array_unique(array_merge($defaults, $custom)));

        $out = ['subjectOptions' => $subjectOptions];

        // headline / description are optional — omit the key entirely when
        // unset/blank so the wire carries null only via absence (skeleton
        // falls back to its own copy).
        $headline = is_string($settings['headline'] ?? null) ? trim((string) $settings['headline']) : '';
        if ($headline !== '') {
            $out['headline'] = $headline;
        }
        $description = is_string($settings['description'] ?? null) ? trim((string) $settings['description']) : '';
        if ($description !== '') {
            $out['description'] = $description;
        }

        return $out;
    }

    /**
     * Read the user's design_kit row and project the stored (non-null) columns
     * into the nested camelCase wire shape (spec §5). DB columns are flat
     * snake_case with a group prefix (e.g. `color_accent`,
     * `typography_font_heading`); we group by prefix and camelCase the
     * remainder of the key.
     *
     * Returns an empty array if the site is missing, the kit row doesn't
     * exist (trigger should auto-insert one but the belt is cheap), or no
     * columns have been stored yet. partna-pages fills the gaps from its
     * code-side DESIGN_KIT_DEFAULTS via mergeDesignKit().
     *
     * Example output:
     *   { colors: { accent: '#ff0000' }, typography: { fontHeading: 'inter' } }
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadDesignKit(?Site $site, ?User $pro): array
    {
        if (! $site) {
            return [];
        }

        $row = DB::connection('pgsql')
            ->table('site.design_kits')
            ->where('site_id', $site->id)
            ->first();

        // Manual layer: the user's stored (non-null) columns. partna-pages fills
        // the remaining nulls from code-side defaults via mergeDesignKit().
        $manual = [];
        if ($row) {
            $cols = (array) $row;
            unset($cols['site_id'], $cols['created_at'], $cols['updated_at']);
            $manual = array_filter($cols, fn ($v) => $v !== null);
        }

        // Overlay manual on the profile-derived preset layer:
        //   defaults <- profile presets <- manual   (manual non-null wins per column).
        $merged = array_merge(ProfileDesignPresets::forUser($pro), $manual);
        if ($merged === []) {
            return [];
        }

        return $this->groupKitColumns($merged);
    }

    /**
     * Take a flat snake_case → value map (e.g. ['color_accent' => '#fff',
     * 'typography_font_heading' => 'inter']) and return the nested camelCase
     * wire shape (e.g. ['colors' => ['accent' => '#fff'], 'typography' =>
     * ['fontHeading' => 'inter']]).
     *
     * Group name is the snake_case prefix before the first underscore,
     * pluralised to match the spec §5 group keys (color → colors,
     * typography → typography, border → borders, etc.). The remainder is
     * camelCased.
     *
     * @param  array<string, mixed>  $cols
     * @return array<string, array<string, mixed>>
     */
    private function groupKitColumns(array $cols): array
    {
        // Prefix maps live in config/partna.php under design_kit.column_groups.
        // Adding a new column family = one entry in that config key (+ the
        // matching Supabase migration) — no code change here.

        // Whole-column overrides, matched FIRST. A prefix map can only route a
        // column that HAS a prefix; the preset-only schema (2026-08-09) added
        // `spacing` and `corners`, which carry no underscore, so the split
        // below would `continue` past them and drop them from the payload with
        // no error at all. Exact matches also let a family that isn't a
        // snake_case prefix group — the four selections — travel together.
        $exactColumns = config('partna.design_kit.column_groups.exact_columns', []);

        // Responsive companion groups use a two-token prefix (e.g.
        // `space_desktop_regular` → spaceDesktop.regular). Match these
        // BEFORE the single-token prefixes so the longer match wins.
        $twoTokenPrefixes = config('partna.design_kit.column_groups.two_token_prefixes', []);

        // Single-token prefix → wire group key. Pluralisation isn't
        // mechanical (typography stays singular), so the map is explicit.
        $singleTokenPrefixes = config('partna.design_kit.column_groups.single_token_prefixes', []);

        $out = [];
        foreach ($cols as $column => $value) {
            $group = null;
            $rest = null;

            // Exact whole-column match wins over every prefix.
            if (isset($exactColumns[$column]) && is_array($exactColumns[$column])) {
                [$exactGroup, $exactKey] = $exactColumns[$column];
                $out[$exactGroup][$exactKey] = $value;

                continue;
            }

            // Try two-token prefix first.
            foreach ($twoTokenPrefixes as $prefix => $candidateGroup) {
                if (str_starts_with($column, $prefix.'_')) {
                    $group = $candidateGroup;
                    $rest = substr($column, strlen($prefix) + 1);
                    break;
                }
            }

            // Fall back to single-token prefix.
            if ($group === null) {
                $underscorePos = strpos($column, '_');
                if ($underscorePos === false) {
                    continue;
                }
                $singleToken = substr($column, 0, $underscorePos);
                $rest = substr($column, $underscorePos + 1);
                $group = $singleTokenPrefixes[$singleToken] ?? null;
            }

            if ($group === null || $rest === null) {
                continue;
            }

            // snake_case → camelCase for the remainder.
            $key = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $rest))));
            $out[$group][$key] = $value;
        }

        return $out;
    }

    /**
     * Build the publicConfig object — platform-wide knobs the skeleton needs
     * at render time.
     *
     * `claim.unclaimed` (2026-07-22, signup-flows frontend handoff): the
     * pages app has no other way to know a rendered site is a pre-account
     * build (site-first signup / staff / early-access) — the publish flag
     * alone already gates visibility (PublicSiteResolver), so an unclaimed
     * site renders here exactly like a claimed one unless we say otherwise.
     * Omitted (not sent false) when claimed, so LKG snapshots and existing
     * consumers predating this field see no change.
     *
     * @return array<string, mixed>
     */
    private function buildPublicConfig(User $pro): array
    {
        $config = [
            'analyticsEndpoint' => config('partna.public_profile.analytics_endpoint'),
        ];

        if ($pro->isUnclaimed()) {
            $config['claim'] = ['unclaimed' => true];
        }

        return $config;
    }

    /**
     * Canonical cache key — includes the site's updated_at so any mutation
     * naturally rolls the key forward. Falls back to the pro's updated_at
     * for early-setup individuals without a Site row.
     */
    public function cacheKey(string $handleLc, ?Site $site, User $pro): string
    {
        $stamp = $site?->updated_at?->timestamp
            ?? $pro->updated_at?->timestamp
            ?? 0;

        return CacheKeyGenerator::publicProfile($handleLc, $stamp);
    }

    public function cacheTtl(): int
    {
        return max(1, (int) config('partna.public_profile.cache_ttl_seconds', 60));
    }

    /**
     * Did the build that just ran answer a presence probe from a QueryException
     * instead of from the database? (CCH-5.)
     *
     * False when nothing was built — a cache hit never runs the resolver, so a
     * served payload is only ever shortened by the request that actually
     * produced the degraded copy.
     */
    public function lastBuildDegraded(): bool
    {
        return $this->resolver->hasDegraded();
    }

    /**
     * TTL for a payload built while a probe was failing. Short on purpose: it
     * still gives the single-flight lock something to serve, so a DB wobble
     * does not turn every in-flight request into its own rebuild, but it lets
     * the page heal seconds after the database does rather than riding the
     * full primary+stale window.
     */
    public function degradedCacheTtl(): int
    {
        return max(1, (int) config('partna.public_profile.degraded_cache_ttl_seconds', 10));
    }
}
