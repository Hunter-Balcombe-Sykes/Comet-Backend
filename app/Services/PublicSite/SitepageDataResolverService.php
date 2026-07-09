<?php

namespace App\Services\PublicSite;

use App\Enums\SitepageId;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilitySet;
use App\Services\Site\ContentSelectionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Shared sitepage-data projection logic for `IndividualProfileController` —
 * the public endpoint that serves individual sitepages on partna-pages.
 * (The old second consumer, the brand-partner Hydrogen controller, was
 * removed with the brand/commerce system.)
 *
 * These helpers own the section envelope shape, the gallery URL resolution,
 * the booking link synthesis, the link-row platform normalisation, and the
 * document download URL, so they can't drift from the controller.
 *
 * Every method is side-effect free — read-only queries and pure projections.
 * The controller owns its own cache + auth concerns.
 */
class SitepageDataResolverService
{
    /**
     * Section block_type → sitepage page-id. The section block_group covers a
     * subset of the taxonomy; the platform-backed pages route via
     * PLATFORM_TO_PAGE below. Backend presence heuristic only — NOT the analytics
     * SitepageId::SECTION_KEY_TO_PAGE lockstep (that maps analytics section_keys,
     * a different namespace: e.g. block_type 'documents' vs section_key 'document').
     *
     * @var array<string, string>
     */
    private const SECTION_BLOCK_TO_PAGE = [
        'gallery' => 'gallery',
        'services' => 'book',
        'booking' => 'book',
        'documents' => 'documents',
        'newsletter' => 'contact',
        'contact' => 'contact',
        'public_contact' => 'contact',
        'workplace' => 'contact',
        'contacts_collection' => 'contact',
        'barbershop_info' => 'contact', // PROVISIONAL — business-info block → Contact
    ];

    /**
     * Integration / link platform → sitepage page-id. Drives presence for the
     * platform-backed pages (listen/watch/shop/events/reservations/…). Backend
     * presence heuristic only — the ONE frontend derives its own presence from
     * the resolved payload, so this map carries no TS-lockstep obligation. Menu
     * (Google-Business-sourced) and Reviews are detected separately below.
     *
     * @var array<string, string>
     */
    private const PLATFORM_TO_PAGE = [
        // Listen — music/audio platforms.
        'spotify' => 'listen', 'soundcloud' => 'listen', 'apple-music' => 'listen',
        'apple-podcast' => 'listen', 'youtube-music' => 'listen', 'tidal' => 'listen',
        'mixcloud' => 'listen',
        // Watch — video/streaming.
        'youtube' => 'watch', 'twitch' => 'watch', 'vimeo' => 'watch',
        // Shop — storefronts + Bandcamp (sells via Shop per the taxonomy).
        'shop' => 'shop', 'bandcamp' => 'shop',
        // Events — ticketing + standalone events.
        'eventbrite' => 'events', 'humanitix' => 'events', 'events-custom' => 'events',
        // Book — booking links.
        'fresha' => 'book', 'square' => 'book', 'booking' => 'book',
        // Reservations — keyless reservation widgets (Business-only page).
        'opentable' => 'reservations', 'resdiary' => 'reservations',
        'nowbookit' => 'reservations', 'reservations' => 'reservations',
        // Google Business feeds the Contact page (Reviews is detected separately).
        'google-business' => 'contact',
        // Standalone social pages.
        'pinterest' => 'pinterest', 'strava' => 'strava', 'skool' => 'skool',
        // Custom links → the Links page. Link items are sourced from custom
        // connections, so a live custom connection means the Links page exists
        // (previously Links presence relied only on link Blocks, which missed
        // custom-connection-only link sets).
        'custom' => 'links',
    ];

    /**
     * Build the section envelope `{state, data, block_id?}`.
     *
     * Live = the section row exists, the pro chose Live (is_active), AND
     * requirements are currently met (is_enabled, written by the visibility
     * observers). All three must hold; any one being false drops the section
     * to draft on the public site even if previously published.
     *
     * @param  Collection<string, Block>  $sections  Pre-loaded blocks keyed by block_type
     * @return array{state: string, data: mixed, block_id?: string}
     */
    public function sectionEnvelope(Collection $sections, string $blockType, callable $buildData): array
    {
        $section = $sections->get($blockType);
        $isLive = $section !== null
            && (bool) $section->is_enabled
            && (bool) $section->is_active;

        $envelope = [
            'state' => $isLive ? 'live' : 'draft',
            'data' => $isLive ? $buildData($section) : null,
        ];

        if ($section !== null) {
            $envelope['block_id'] = (string) $section->id;
        }

        return $envelope;
    }

    /**
     * Pre-load the section blocks (one row per block_type, group=sections) for
     * a site. Returns a keyed collection so each helper can look up its section
     * without re-querying. Empty collection when site is null.
     *
     * Ordered by sort_order so the keyed collection preserves the user's manual
     * section order (keyBy keeps insertion order) — presentPageIds()/buildPageOrder()
     * rely on this rather than the previous unordered fetch.
     *
     * @return Collection<string, Block>
     */
    public function loadSections(?Site $site): Collection
    {
        if (! $site) {
            return collect();
        }

        return Block::query()
            ->where('site_id', $site->id)
            ->where('block_group', 'sections')
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get()
            ->keyBy('block_type');
    }

    // ── Page presence + ordering (ONE taxonomy) ─────────────────────────

    /**
     * The taxonomy page-ids this site has content for, in canonical order, with
     * Business-only pages dropped for accounts lacking the capability. Defined
     * ONCE here so the read path (buildPageOrder) and the write path
     * (analytics:compute-popularity) share an identical presence gate.
     *
     * Presence signals (any positive hit includes the page):
     *   home     — always (every published profile has one)
     *   sections — live section blocks (SECTION_BLOCK_TO_PAGE)
     *   platforms— active integration connections + link blocks (PLATFORM_TO_PAGE)
     *   book     — active services
     *   gallery  — ready gallery media
     *   menu     — a fetched Menu (Business)
     *   reviews  — active Google Business (Business)
     *   links    — any live link block
     *
     * @param  Collection<string, Block>  $sections  pre-loaded section blocks
     * @return list<string>
     */
    public function presentPageIds(?Site $site, AccountCapabilitySet $caps, Collection $sections): array
    {
        $present = ['home' => true];

        if ($site !== null) {
            // Live section blocks → their pages.
            foreach ($sections as $blockType => $block) {
                $isLive = (bool) $block->is_active && (bool) $block->is_enabled;
                $page = self::SECTION_BLOCK_TO_PAGE[(string) $blockType] ?? null;
                if ($isLive && $page !== null) {
                    $present[$page] = true;
                }
            }

            $userId = $site->user_id;
            if ($userId !== null) {
                // Active integration connections → platform pages. Each optional
                // signal is read defensively (safeQuery) so a missing table in a
                // partial test env / a DB blip degrades to "signal absent" rather
                // than a 500 — same resilience posture as AnalyticsQueryService.
                $platforms = $this->safeQuery(
                    fn () => IntegrationConnection::query()
                        ->where('user_id', $userId)
                        ->where('is_active', true)
                        ->distinct()
                        ->pluck('platform')
                        ->all(),
                    [],
                );
                foreach ($platforms as $platform) {
                    $page = self::PLATFORM_TO_PAGE[strtolower((string) $platform)] ?? null;
                    if ($page !== null) {
                        $present[$page] = true;
                    }
                }

                // A fetched Menu (Google-Business-sourced) → the Menu page.
                if ($this->safeQuery(fn () => Menu::query()->where('user_id', $userId)->whereNotNull('last_fetched_at')->exists(), false)) {
                    $present['menu'] = true;
                }

                // Active services → the Book page.
                if ($this->safeQuery(fn () => Service::query()->where('user_id', $userId)->where('is_active', true)->whereNull('deleted_at')->exists(), false)) {
                    $present['book'] = true;
                }

                // Active Google Business → the Reviews page (Business-only, gated below).
                if ($this->safeQuery(fn () => IntegrationConnection::query()->where('user_id', $userId)->where('platform', 'google-business')->where('is_active', true)->exists(), false)) {
                    $present['reviews'] = true;
                }
            }

            // Any live link block → the Links page.
            if ($this->safeQuery(fn () => Block::query()
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->exists(), false)) {
                $present['links'] = true;
            }

            // Ready gallery media → the Gallery page.
            if ($this->safeQuery(fn () => SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_GALLERY)
                ->where('is_active', true)
                ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
                ->exists(), false)) {
                $present['gallery'] = true;
            }
        }

        // Business-gate: drop Business-only pages for accounts without the capability.
        if (! $caps->can_use_multipage_site) {
            foreach (SitepageId::BUSINESS_ONLY as $bizPage) {
                unset($present[$bizPage]);
            }
        }

        // Canonical order, presence-filtered.
        return array_values(array_filter(
            SitepageId::canonicalOrder(),
            static fn (string $page): bool => isset($present[$page]),
        ));
    }

    /**
     * Ordered page-ids for the payload's top-level `pageOrder`. Present pages
     * sorted by their content_popularity_scores rank (content_type='page') where
     * a rank exists, then the remaining present pages in canonical order. With no
     * score rows the result is simply the presence-filtered canonical order.
     *
     * @param  Collection<string, Block>  $sections
     * @param  array<string, int>  $pageRanks  content_key (page-id) → rank (1 = most popular)
     * @return list<string>
     */
    public function buildPageOrder(?Site $site, AccountCapabilitySet $caps, Collection $sections, array $pageRanks): array
    {
        $present = $this->presentPageIds($site, $caps, $sections);

        // Present pages that carry a stored rank, ordered by rank ascending.
        $ranked = [];
        foreach ($present as $page) {
            if (isset($pageRanks[$page])) {
                $ranked[$page] = $pageRanks[$page];
            }
        }
        asort($ranked);
        $rankedPages = array_keys($ranked);

        // Remaining present pages keep canonical order after the ranked block.
        $rest = array_values(array_filter(
            $present,
            static fn (string $page): bool => ! isset($ranked[$page]),
        ));

        return array_values(array_merge($rankedPages, $rest));
    }

    /**
     * Run an optional presence probe, returning $default on any query fault
     * (missing table in a partial test env, transient DB error) so presence
     * detection degrades gracefully instead of 500ing the public payload.
     *
     * @template T
     *
     * @param  \Closure(): T  $query
     * @param  T  $default
     * @return T
     */
    private function safeQuery(\Closure $query, mixed $default): mixed
    {
        try {
            return $query();
        } catch (QueryException $e) {
            Log::warning('sitepage.presence_probe_failed', ['error' => $e->getMessage()]);

            return $default;
        }
    }

    // ── Curated gallery (Content Selection) ─────────────────────────────

    /**
     * Curated-gallery engine — the site's Content Selection (≤15 ordered picks)
     * projected for the public payload's `profile.curatedGallery`. Delegates to
     * ContentSelectionService::resolve(), which returns a PROJECTED DTO per row
     * (id/position/kind/type/url/badge, + poster for reels) — never the raw
     * content_selection columns (risk #7). Live-resolves google-photo / ig-*
     * references and drops any that no longer resolve. Empty array when nothing
     * is curated.
     *
     * Distinct from `profile.gallery` (SiteMedia POOL_GALLERY): this is the
     * user-curated background/content set, a separate surface — the two never
     * collide on the wire.
     *
     * @return list<array<string, mixed>>
     */
    public function buildCuratedGalleryData(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        // Resilient: a missing content_selection table (partial test env) / a
        // platform-payload read fault degrades to an empty curated gallery rather
        // than failing the whole public payload.
        return $this->safeQuery(fn () => app(ContentSelectionService::class)->resolve($site), []);
    }

    // ── Gallery ──────────────────────────────────────────────────────────

    /**
     * @param  Collection<string, Block>  $sections
     * @return array{state: string, data: array|null, block_id?: string}
     */
    public function getGallery(?Site $site, Collection $sections): array
    {
        return $this->sectionEnvelope($sections, 'gallery', function () use ($site): array {
            if (! $site) {
                return [];
            }

            return SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_GALLERY)
                ->where('is_active', true)
                ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
                ->with('mediaVariants')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (SiteMedia $media) => $this->buildGalleryItem($media))
                ->filter(fn (?array $item) => $item !== null && $item['url'] !== '')
                ->values()
                ->all();
        });
    }

    /**
     * Project a SiteMedia row to the resolver-internal polymorphic envelope
     * used by both the gallery engine and the new design media field. Videos
     * emit two plain MP4 tiers — `url` (optimized 720p, autoplay default) and
     * `url_hd` (maximized 1080p, on-demand/fullscreen); images use the
     * optimised WebP and carry `url_hd => null` for a uniform shape.
     *
     * NOTE: keys are snake_case — this is the resolver's internal shape.
     * IndividualProfilePayloadBuilder remaps to camelCase for the wire
     * (e.g. url_hd → urlHd, duration_ms → durationMs, alt_text → alt).
     *
     * Returns null when the media has no resolvable primary URL (e.g. variants
     * row missing post-failure). Callers must filter those out.
     *
     * @return array{id: string, sort_order: int, url: string, url_hd: string|null, alt_text: string|null, caption: string|null, kind: string, poster: string|null, duration_ms: int|null}|null
     */
    private function buildMediaItem(SiteMedia $media): ?array
    {
        $isVideo = $media->media_type === SiteMedia::MEDIA_TYPE_VIDEO;

        if ($isVideo) {
            // Two MP4 tiers, delivered directly (no HLS): optimized 720p is the
            // default/autoplay source, maximized 1080p is loaded on demand
            // (tap / fullscreen). The frontend picks; we expose both URLs.
            $variants = [];
            $poster = null;
            foreach ($media->mediaVariants as $mv) {
                if ($mv->artifact_type === 'mp4') {
                    $variants[$mv->variant_key] = $mv->url;
                } elseif ($mv->artifact_type === 'poster') {
                    $poster = $mv->url;
                }
            }
            // optimized is the contract default; fall back to maximized then
            // original so a partially-processed item still renders something.
            $url = $variants['optimized'] ?? $variants['maximized'] ?? $variants['original'] ?? '';
            $urlHd = $variants['maximized'] ?? null;

            return [
                'id' => (string) $media->id,
                'sort_order' => (int) $media->sort_order,
                'url' => $url,
                'url_hd' => $urlHd,
                'alt_text' => $media->alt_text,
                'caption' => $media->caption,
                'kind' => 'video',
                'poster' => $poster,
                'duration_ms' => $media->duration_ms,
            ];
        }

        $variantUrls = $media->variantUrls();
        $url = $variantUrls['optimized'] ?? $variantUrls['original'] ?? '';
        $urlHd = $variantUrls['maximized'] ?? null;

        return [
            'id' => (string) $media->id,
            'sort_order' => (int) $media->sort_order,
            'url' => $url,
            'url_hd' => $urlHd,
            'alt_text' => $media->alt_text,
            'caption' => $media->caption,
            'kind' => 'image',
            'poster' => null,
            'duration_ms' => null,
        ];
    }

    /**
     * Backwards-compatible gallery item projection. Delegates to the shared
     * polymorphic helper. Kept as a thin alias so the gallery engine's call
     * site reads naturally; future callers should use buildMediaItem directly.
     *
     * @return array{id: string, sort_order: int, url: string, url_hd: string|null, alt_text: string|null, caption: string|null, kind: string, poster: string|null, duration_ms: int|null}|null
     */
    public function buildGalleryItem(SiteMedia $media): ?array
    {
        return $this->buildMediaItem($media);
    }

    // ── Content media (polymorphic — design layer) ──────────────────────

    /**
     * Content-pool media — design-layer assets the skeleton paints with
     * (backgrounds, section covers, decorative imagery). Polymorphic: images
     * and videos in a single sort-ordered list, projected through the shared
     * buildMediaItem helper so the shape matches gallery items exactly.
     *
     * Unlike the phase-8 engines, this is not gated by a Block row — it's
     * design infrastructure, not user content. The skeleton consumes whatever
     * is in the pool in order.
     *
     * Returns snake_case keys (id, sort_order, url, url_hd, alt_text,
     * caption, kind, poster, duration_ms) — the resolver-internal shape.
     * IndividualProfilePayloadBuilder::buildDesignMedia remaps to camelCase
     * for the wire.
     *
     * @return list<array{id: string, sort_order: int, url: string, url_hd: string|null, alt_text: string|null, caption: string|null, kind: string, poster: string|null, duration_ms: int|null}>
     */
    public function getContentMedia(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        return SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_CONTENT)
            ->where('is_active', true)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SiteMedia $media) => $this->buildMediaItem($media))
            ->filter(fn (?array $item) => $item !== null && $item['url'] !== '')
            ->values()
            ->all();
    }

    // ── Design singletons (logos + integration covers) ──────────────────

    /**
     * Design-pool singleton images keyed by purpose — the brand logos
     * (logo_full / logo_square) and the per-integration cover images
     * (cover_youtube, cover_apple_music, ...; registry-derived). Each maps to {url, url_hd, url_svg}
     * from the ready WebP variants (url_svg only for vectorized logos); purposes
     * with no uploaded/ready image are absent.
     *
     * Consumed by the public profile payload's siteImages map: partna-pages
     * reads the logos for the profile and the covers per integration. Rendering
     * is the theme's concern — this only makes the URLs available.
     *
     * @return array<string, array{url: string, url_hd: string|null, url_svg: string|null, url_icon: string|null}>
     */
    public function getDesignSingletons(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        return SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->whereIn('purpose', SiteMedia::designSingletonPurposes())
            ->where('is_active', true)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->with('mediaVariants')
            ->get()
            ->mapWithKeys(function (SiteMedia $media): array {
                $urls = $media->variantUrls();
                $url = $urls['optimized'] ?? $urls['original'] ?? '';
                if ($url === '') {
                    return [];
                }

                return [(string) $media->purpose => [
                    'url' => $url,
                    'url_hd' => $urls['maximized'] ?? null,
                    'url_svg' => $media->svgVariantUrl(),
                    // 192px PNG (square logos) — the sitepage favicon source.
                    'url_icon' => $media->iconVariantUrl(),
                ]];
            })
            ->all();
    }

    // ── Links (every category + synthesised booking) ────────────────────

    /**
     * Returns every live link block across every category (social, content,
     * education, events, custom). Each link is tagged with `category` and,
     * for platform-tagged rows, `platform`. When a booking envelope is live
     * + resolved, a `{category: 'booking'}` row gets synthesised so themes
     * have a single list to render.
     *
     * @param  array{state: string, data: array|null}  $bookingEnvelope
     * @return list<array{id: string, title: string, url: string, category: string, platform: string|null}>
     */
    public function getLinks(?Site $site, array $bookingEnvelope): array
    {
        if (! $site) {
            return [];
        }

        $rows = Block::query()
            ->where('site_id', $site->id)
            ->where('block_group', 'links')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get()
            ->map(function (Block $block): array {
                $settings = is_array($block->settings) ? $block->settings : [];
                // Phase 2: platform + category read from promoted columns.
                $platform = is_string($block->platform)
                    ? strtolower(trim($block->platform))
                    : null;
                $platform = ($platform !== null && $platform !== '') ? $platform : null;
                $category = is_string($block->category)
                    ? strtolower(trim($block->category))
                    : 'custom';

                $title = is_string($block->title) ? trim($block->title) : '';
                $url = is_string($block->url) ? trim($block->url) : '';

                // Older rows can have empty title/url at rest — platform (column)
                // + settings.handle are the source of truth there. Rebuild both
                // from the platform config so the row still renders.
                if (($title === '' || $url === '') && $platform !== null) {
                    $config = config("partna.social_platforms.{$platform}");
                    $handle = is_string($settings['handle'] ?? null)
                        ? trim((string) $settings['handle'])
                        : '';
                    if (is_array($config)) {
                        if ($title === '' && is_string($config['display_name'] ?? null)) {
                            $title = (string) $config['display_name'];
                        }
                        if ($url === '' && $handle !== '' && is_string($config['url_template'] ?? null)) {
                            $url = str_replace('{handle}', $handle, (string) $config['url_template']);
                        }
                    }
                }

                return [
                    'id' => (string) $block->id,
                    'title' => $title,
                    'url' => $url,
                    'category' => $category !== '' ? $category : 'custom',
                    'platform' => $platform,
                ];
            })
            ->filter(fn (array $item) => $item['title'] !== '' && $item['url'] !== '')
            ->values()
            ->all();

        if ($bookingEnvelope['state'] === 'live' && is_array($bookingEnvelope['data'])) {
            $rows[] = [
                'id' => '',
                'title' => (string) ($bookingEnvelope['data']['title'] ?? 'Book now'),
                'url' => (string) ($bookingEnvelope['data']['resolved_url'] ?? ''),
                'category' => 'booking',
                'platform' => is_string($bookingEnvelope['data']['platform'] ?? null)
                    ? $bookingEnvelope['data']['platform']
                    : null,
            ];
            $rows = array_values(array_filter(
                $rows,
                fn (array $item) => $item['url'] !== '',
            ));
        }

        return $rows;
    }

    // ── Public contact ──────────────────────────────────────────────────

    /**
     * Public-contact engine — {email, phone} | null.
     *
     * Public contact was previously computed inside the bio engine; it now has
     * its own section gate — the `public_contact` block type +
     * PublicContactVisibility rule, NOT `bio`.
     *
     * @param  Collection<string, Block>  $sections
     * @return array{state: string, data: array{email: string|null, phone: string|null}|null, block_id?: string}
     */
    public function getPublicContact(User $pro, Collection $sections): array
    {
        return $this->sectionEnvelope($sections, 'public_contact', function () use ($pro): array {
            return [
                'email' => trim_or_null($pro->public_contact_email ?? null),
                'phone' => trim_or_null($pro->public_contact_number ?? null),
            ];
        });
    }

    /**
     * Workplace — business-location card data from site.workplaces (FOUND-4).
     * Promoted from site.sites.settings.workplace JSONB so the table is
     * indexable and the visibility check avoids JSON arrow operators.
     * Gated by the 'workplace' section block — hide card without deleting data.
     */
    public function getWorkplace(?Site $site, Collection $sections): array
    {
        return $this->sectionEnvelope($sections, 'workplace', function () use ($site): ?array {
            if (! $site) {
                return null;
            }

            // loadMissing avoids N+1 when the calling controller already eager-loaded.
            $site->loadMissing('workplace');
            $workplace = $site->workplace;

            if (! $workplace) {
                return null;
            }

            // A row with a null name renders as null on the public wire —
            // setPreviousWebsite and GoogleBusinessAutoSync may write other fields
            // without a name, but the card only goes live once a name is present.
            $name = trim_or_null($workplace->name);
            if ($name === null) {
                return null;
            }

            return [
                'name' => $name,
                'address' => trim_or_null($workplace->address),
                'address_line1' => trim_or_null($workplace->address_line1),
                'city' => trim_or_null($workplace->city),
                'state' => trim_or_null($workplace->state),
                'postcode' => trim_or_null($workplace->postcode),
                'country' => trim_or_null($workplace->country),
                'latitude' => $workplace->latitude !== null ? (float) $workplace->latitude : null,
                'longitude' => $workplace->longitude !== null ? (float) $workplace->longitude : null,
                'phone' => trim_or_null($workplace->phone),
                'website' => trim_or_null($workplace->website),
            ];
        });
    }

    // ── Document ────────────────────────────────────────────────────────

    /**
     * Document is gated per-row (SiteMedia.is_active) not at the section
     * level — there's no separate "documents" section block to also flip.
     * If a ready + active row exists the envelope is 'live'; otherwise draft.
     *
     * @return array{state: string, data: array|null}
     */
    public function getDocument(?Site $site): array
    {
        $media = $site
            ? SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DOCUMENTS)
                ->where('is_active', true)
                ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
                ->orderByDesc('created_at')
                ->first()
            : null;

        if (! $media) {
            return ['state' => 'draft', 'data' => null];
        }

        return [
            'state' => 'live',
            'data' => [
                'id' => (string) $media->id,
                'title' => $media->alt_text,
                'caption' => $media->caption,
                // Absolute URL to the backend signed-download redirect so the
                // public payload never carries storage credentials.
                'download_url' => url('/api/public/documents/'.$media->id.'/download'),
                'mime' => $media->original_mime,
                'size_bytes' => $media->original_size_bytes,
            ],
        ];
    }

    // ── Newsletter ──────────────────────────────────────────────────────

    /**
     * @param  Collection<string, Block>  $sections
     * @return array{state: string, data: array|null, block_id?: string}
     */
    public function getNewsletter(Collection $sections): array
    {
        return $this->sectionEnvelope($sections, 'newsletter', function (Block $section): array {
            $settings = is_array($section->settings) ? $section->settings : [];

            return [
                'headline' => (string) ($settings['headline'] ?? ''),
                'description' => (string) ($settings['description'] ?? ''),
                'cta_label' => (string) ($settings['cta_label'] ?? ''),
            ];
        });
    }

    // ── Services ────────────────────────────────────────────────────────

    /**
     * @param  Collection<string, Block>  $sections
     * @return array{state: string, data: array|null, block_id?: string}
     */
    public function getServices(?Site $site, string $proId, Collection $sections): array
    {
        return $this->sectionEnvelope($sections, 'services', function () use ($site, $proId) {
            return $this->buildServicesData($site, $proId);
        });
    }

    public function buildServicesData(?Site $site, string $proId): array
    {
        $settings = is_array($site?->settings) ? $site->settings : [];
        // FOUND-16: booking_mode / manual_booking_url are promoted columns.
        // Column-first with a settings fallback (identical during dual-write,
        // column-authoritative after the strip).
        $bookingMode = strtolower((string) ($site?->booking_mode ?? $settings['booking_mode'] ?? 'manual'));
        $manualBookingUrl = trim((string) ($site?->manual_booking_url ?? $settings['manual_booking_url'] ?? ''));

        $services = Service::query()
            ->with('category:id,title')
            ->where('user_id', $proId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'title' => $service->title,
                'description' => $service->description,
                'price_cents' => $service->price_cents,
                'currency_code' => $service->currency_code,
                'duration_minutes' => $service->duration_minutes,
                'category' => $service->category?->title ?? 'Services',
            ])
            ->values()
            ->all();

        return [
            'booking_mode' => $bookingMode,
            'manual_booking_url' => $manualBookingUrl !== '' ? $manualBookingUrl : null,
            'services' => $services,
        ];
    }

    // ── Booking ─────────────────────────────────────────────────────────

    /**
     * @param  Collection<string, Block>  $sections
     * @return array{state: string, data: array|null, block_id?: string}
     */
    public function getBooking(?Site $site, Collection $sections): array
    {
        return $this->sectionEnvelope($sections, 'booking', function (Block $section): ?array {
            $settings = is_array($section->settings) ? $section->settings : [];
            $bookingUrl = trim((string) ($settings['booking_url'] ?? ''));

            // FOUND-49 (documented-defer): settings.platform read here ONLY — one reader,
            // no validation rule, no query need. Promote to a column if a second reader appears.
            $platform = is_string($settings['platform'] ?? null)
                ? strtolower(trim((string) $settings['platform']))
                : null;

            $title = is_string($settings['title'] ?? null)
                ? trim((string) $settings['title'])
                : '';

            if ($bookingUrl === '') {
                return null;
            }

            return [
                'platform' => $platform !== '' ? $platform : null,
                'path' => $bookingUrl,
                'resolved_url' => $bookingUrl,
                'title' => $title !== '' ? $title : 'Book now',
            ];
        });
    }
}
