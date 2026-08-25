<?php

namespace App\Services\PublicSite;

use App\Enums\SitepageId;
use App\Http\Controllers\Api\PublicSite\IndividualProfileController;
use App\Http\Resources\PublicSite\IndividualProfileResource;
use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Accounts\AccountCapabilitySet;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Design\ProfileDesignPresets;
use App\Services\Site\SitePolicyResolver;
use App\Site\Actions\ActionCandidates;
use App\Site\Actions\ActionSettings;
use App\Site\Actions\ActionSlots;
use App\Site\Pools\PoolWire;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 *       pools: { watch|listen|media|…: {items, latestItemId} },
 *       links: ProfileLink[],
 *       services: ProfileService[],
 *       document: DocumentData | null,
 *       newsletter: NewsletterData | null,
 *       contact: ContactData | null,
 *       publicContact: { email, phone } | null,
 *       workplace: WorkplaceData | null,
 *     },
 *     designKit: { colors: {...}, typography: {...}, ... },
 *     architectureId: 'staple',
 *     publicConfig: { analyticsEndpoint, ... },
 *   }
 *
 * Slice 7 unit E deleted `profile.gallery`, `profile.curatedGallery`,
 * `designMedia` and `siteImages` outright (owner ruling 2026-08-14 — apps/pages
 * is rebuilt, not repaired). Curated imagery is the `media` pool now.
 *
 * Each engine field falls back to a stable empty state so the architecture
 * never has to guard on `undefined`:
 *   - object engines (document, newsletter) → null when nothing authored
 *   - list engines (links, services) → empty array
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
        private readonly SitePolicyResolver $policies,
        private readonly PoolWire $poolWire,
        private readonly ActionCandidates $candidates,
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
        $caps = AccountCapabilities::for($pro);

        // Page order: presence-gated, ranked by the page:* action scores when
        // smart_page_order is on (the page-score family retired 2026-08-23),
        // else the owner's manual order — canonical fallback either way.
        $pageOrder = $this->pageOrder($site, $caps, $sections);

        // Contact + workplace resolved once — the wire keys below reuse them,
        // and the policy resolver personalizes its generated texts from them
        // (business name, public contact email).
        $publicContact = $this->buildPublicContact($pro, $sections);
        $workplace = $this->buildWorkplace($site, $sections);

        // ONE pool hydration feeds both the wire pools and the action
        // candidate set, so every action ref resolves against what is served.
        $pools = $this->buildPools($site);
        $actions = $this->buildActions($pro, $site, $sections, $pools);

        return (new IndividualProfileResource($pro, [
            'site_id' => $site?->id,
            'design_kit' => $this->loadDesignKit($site, $pro),
            'architecture_id' => $site?->architecture_id ?? Site::DEFAULT_ARCHITECTURE_ID,
            'public_config' => $this->buildPublicConfig($pro),
            // Taxonomy page order for the ONE architecture — presence + business
            // gated, popularity-ranked (or the owner's manual order when
            // smart_page_order is off), canonical fallback. Top-level key.
            'page_order' => $pageOrder,
            // The unified action list (spec §3): top-N of pages + destination
            // platforms + served items + categories, in the owner's mode with
            // their locks applied. Always present; entries [] when nothing
            // resolves.
            'actions' => $actions,
            // Engine outputs — flat, camelCase, no envelope wrapper.
            'pools' => $pools,
            // Brand logos (owner ruling 2026-08-17): the design singletons
            // regain a public projection — slice 7 unit E deleted `siteImages`
            // wholesale, which took the logos down with the noise. Just the
            // two logo slots; the placeholder singleton stays private.
            'brand' => $this->buildBrand($site),
            'document' => $this->buildDocument($site),
            'newsletter' => $this->buildNewsletter($sections),
            'contact' => $this->buildContact($sections),
            'publicContact' => $publicContact,
            // The owner's About Me paragraph (users.bio) — plumbed, not
            // mounted: the renderer owns whether/where it appears.
            'bio' => $this->resolver->getBio($pro),
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
            // The WORKPLACE's own public email — independent of the person's
            // publicContact pair for partna (2026-08-19 identity plan).
            'contactEmail' => $data['contact_email'] ?? null,
            'website' => $data['website'] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private function pageOrder(?Site $site, AccountCapabilitySet $caps, Collection $sections): array
    {
        $settings = is_array($site?->settings) ? $site->settings : [];
        $smart = filter_var($settings['smart_page_order'] ?? true, FILTER_VALIDATE_BOOL);
        if ($smart) {
            $pageRanks = $this->popularity->pageRanksFromActions($site?->id);
            if ($this->popularity->lastReadFailed()) {
                // CCH-11: pageRanksFromActions() delegates to actionRanksForSite(),
                // which fails open to [] on a DB fault — identical to "no page
                // ranked yet". Mark the build degraded so the controller
                // rewrites its cache entry at the short degraded TTL (same
                // markDegraded()/hasDegraded() seam #LIFE-6 wired for PoolWire)
                // instead of the fault's empty ranking riding the full payload
                // TTL.
                $this->resolver->markDegraded();
            }

            return $this->resolver->buildPageOrder($site, $caps, $sections, $pageRanks);
        }
        $manual = array_values(array_map(
            static fn (string $id): string => SitepageId::normalizePageId($id),
            array_filter(is_array($settings['manual_page_order'] ?? null) ? $settings['manual_page_order'] : [], 'is_string'),
        ));

        return self::applyManualPageOrder($this->resolver->presentPageIds($site, $caps, $sections), $manual);
    }

    /**
     * Manual page order applied against the LIVE present pages: manual ∩
     * present in manual order (unknown/absent ids dropped), then present
     * pages missing from the manual list appended in canonical order — every
     * present page stays reachable.
     *
     * @param  list<string>  $present  presence-gated canonical page-ids
     * @param  list<string>  $manual  the stored manual_page_order
     * @return list<string>
     */
    public static function applyManualPageOrder(array $present, array $manual): array
    {
        $presentSet = array_flip($present);
        $ordered = [];
        foreach ($manual as $id) {
            if (isset($presentSet[$id]) && ! in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }
        foreach ($present as $id) {
            if (! in_array($id, $ordered, true)) {
                $ordered[] = $id;
            }
        }

        return $ordered;
    }

    /**
     * `actions` — ActionCandidates (over the already-built pools) → stored
     * hysteresis ranks (RANK-1) → ActionSlots. Fail-open like every other
     * section: a candidate/rank fault yields an empty list, never a 500.
     *
     * @param  array<string, array<string, mixed>>  $pools
     * @return array{mode: string, entries: list<array<string, mixed>>}
     */
    private function buildActions(User $pro, ?Site $site, Collection $sections, array $pools): array
    {
        $settings = ActionSettings::fromSite($site);
        if ($site === null) {
            return ['mode' => $settings->mode, 'entries' => []];
        }
        try {
            $candidates = $this->candidates->forSite($pro, $site, $sections, $pools);
        } catch (QueryException $e) {
            Log::warning('sitepage.actions_candidates_failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            $candidates = [];
        }
        $ranks = [];
        if ($settings->mode === 'smart') {
            $ranks = $this->popularity->actionRanksForSite($site->id);
            if ($this->popularity->lastReadFailed()) {
                // CCH-11: same fail-open ambiguity as pageOrder() above, for the
                // action-slot ranking instead of page order.
                $this->resolver->markDegraded();
            }
        }
        $resolved = ActionSlots::resolve($candidates, $ranks, $settings, (int) config('partna.actions.slots', 10));

        return ['mode' => $settings->mode, 'entries' => $resolved['entries']];
    }

    /**
     * The content pools — see App\Site\Pools\PoolWire (the hydration moved
     * there 2026-08-23 so actions and scoring read the same map).
     *
     * @return array<string, array{items: list<array<string, mixed>>, latestItemId: string|null, collections?: array<string, array<string, mixed>>}>
     */
    private function buildPools(?Site $site): array
    {
        return $this->poolWire->forSite($site, $this->resolver);
    }

    /**
     * Brand logos — {logoFull, logoSquare}, each {url, urlHd, urlSvg,
     * urlIcon} | null (owner ruling 2026-08-17). Reads the design-singleton
     * projection getDesignSingletons() kept alive for exactly this moment,
     * remapping its snake_case url keys to the wire's camelCase. The
     * `placeholder` singleton is deliberately not published.
     *
     * @return array{logoFull: array{url: string, urlHd: string|null, urlSvg: string|null, urlIcon: string|null}|null, logoSquare: array{url: string, urlHd: string|null, urlSvg: string|null, urlIcon: string|null}|null}
     */
    private function buildBrand(?Site $site): array
    {
        $singletons = $this->resolver->getDesignSingletons($site);

        $slot = function (string $purpose) use ($singletons): ?array {
            $row = $singletons[$purpose] ?? null;
            if (! is_array($row)) {
                return null;
            }

            return [
                'url' => (string) $row['url'],
                'urlHd' => $row['url_hd'] ?? null,
                'urlSvg' => $row['url_svg'] ?? null,
                'urlIcon' => $row['url_icon'] ?? null,
            ];
        };

        return [
            'logoFull' => $slot(SiteMedia::PURPOSE_LOGO_FULL),
            'logoSquare' => $slot(SiteMedia::PURPOSE_LOGO_SQUARE),
        ];
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

        // A LIVE newsletter section publishes even with no authored
        // placeholder (owner, 2026-08-19): the sitepage falls back to its own
        // copy. Empty → '' on the wire, never null — null means "not live".
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

        // shopLinkMode (2026-08-24): 'checkout' | 'product' — the owner's
        // Shop → link-mode choice, site-wide. The COMPOSED url already bakes
        // this in (ShopOutboundUrl), and that stays true; what the renderer
        // could not know is which SHAPE to draw — a "View product" link or a
        // variant-select + Checkout bar. That is a presentation question, so
        // the flag rides publicConfig (site-level, like the setting itself)
        // rather than the pool wire, whose STORE_KEYS allowlist withholds
        // linkMode on purpose so the affiliate suffix stays unreadable.
        // Nothing about the affiliate composition becomes public here.
        $config['shopLinkMode'] = $pro->site->shop_link_mode ?? Site::DEFAULT_SHOP_LINK_MODE;

        // displayGalleryPage (2026-08-24): the owner's Site → "Display a
        // gallery page?" switch. It has been accepted and stored since
        // UpdateSiteRequest grew the rule ("Stored only for now — the
        // resolver gate lands separately"); this is that gate, published
        // rather than applied here.
        //
        // It has to reach the RENDERER rather than just drop 'gallery' from
        // pageOrder, because the sitepage re-adds Gallery on its own when the
        // media pool is stocked (PLACEHOLDER_PAGE_IDS) — a pageOrder-only
        // removal would be silently overridden and the switch would look
        // broken. Absent = true, matching the request rule's
        // permissive-on-absent default.
        $config['displayGalleryPage'] = ($pro->site?->settings['display_gallery_page'] ?? true) !== false;

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
