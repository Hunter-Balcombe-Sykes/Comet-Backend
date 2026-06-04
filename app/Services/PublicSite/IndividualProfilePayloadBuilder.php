<?php

namespace App\Services\PublicSite;

use App\Http\Controllers\Api\PublicSite\IndividualProfileController;
use App\Http\Resources\PublicSite\IndividualProfileResource;
use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SmartLink;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\SmartLinks\SmartLinkVisitorUrl;
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
 *       bio: BioData | null,
 *       gallery: GalleryImage[],
 *       links: ProfileLink[],
 *       services: ProfileService[],
 *       document: DocumentData | null,
 *       newsletter: NewsletterData | null,
 *       workplace: WorkplaceData | null,
 *     },
 *     designKit: { colors: {...}, typography: {...}, ... },
 *     designMedia: DesignMediaItem[],
 *     skeletonId: 'skeleton-1' | ... | 'skeleton-4',
 *     publicConfig: { analyticsEndpoint, ... },
 *   }
 *
 * Each engine field falls back to a stable empty state so skeletons never
 * have to guard on `undefined`:
 *   - object engines (bio, document, newsletter) → null when nothing authored
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
    ) {}

    /**
     * Build the §28.8 resolved payload. Reads:
     *   - the user's content sections via SitepageDataResolverService
     *   - the per-user design_kit row (partial — only stored non-null cols)
     *   - the site's skeleton_id (TEXT enum)
     *   - platform-wide publicConfig fields (analytics endpoint, etc.)
     *
     * @return array<string, mixed>
     */
    public function build(User $pro, ?Site $site): array
    {
        $sections = $this->resolver->loadSections($site);
        $booking = $this->resolver->getBooking($site, $sections);

        return (new IndividualProfileResource($pro, [
            'site_id' => $site?->id,
            'design_kit' => $this->loadDesignKit($site),
            'design_media' => $this->buildDesignMedia($site),
            'site_images' => $this->buildSiteImages($site),
            'skeleton_id' => $site?->skeleton_id ?? Site::DEFAULT_SKELETON_ID,
            'public_config' => $this->buildPublicConfig(),
            // Engine outputs — flat, camelCase, no envelope wrapper.
            'bio' => $this->buildBio($pro, $sections),
            'gallery' => $this->buildGallery($site, $sections),
            'links' => $this->buildLinks($site, $booking),
            'services' => $this->buildServices($site, $pro->id, $sections),
            'document' => $this->buildDocument($site),
            'newsletter' => $this->buildNewsletter($sections),
            'workplace' => $this->buildWorkplace($site, $sections),
            'smart_links' => $this->buildSmartLinks($site),
        ]))->resolve();
    }

    /**
     * Bio engine — BioData | null.
     *
     * Returns null when the bio section is not live (block missing, or
     * is_active/is_enabled false) so skeletons can short-circuit cleanly.
     * Internal shape uses camelCase keys per spec §5 wire convention.
     *
     * @param  Collection<string, Block>  $sections
     * @return array{text: string, credentials: list<array{title: string, issuer: string, year: string|null}>, experience: list<array{title: string, organisation: string, period: string|null}>}|null
     */
    private function buildBio(User $pro, Collection $sections): ?array
    {
        $envelope = $this->resolver->getBio($pro, $sections);
        $data = $envelope['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        // Resolver returns the bio shape we want; we just remap the
        // public_contact snake_case to the wire's camelCase publicContact.
        $publicContact = is_array($data['public_contact'] ?? null) ? [
            'email' => $data['public_contact']['email'] ?? null,
            'phone' => $data['public_contact']['phone'] ?? null,
        ] : null;

        return [
            'text' => (string) ($data['text'] ?? ''),
            'credentials' => array_values($data['credentials'] ?? []),
            'experience' => array_values($data['experience'] ?? []),
            'publicContact' => $publicContact,
        ];
    }

    /**
     * Smart links engine — list<SmartLink> in camelCase wire shape, active
     * only, ordered family → sort_order. visitorUrl is computed (tracking +
     * discount code baked in, where supported).
     *
     * @return list<array<string, mixed>>
     */
    private function buildSmartLinks(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        $links = SmartLink::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderBy('family')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        $visitorUrl = app(SmartLinkVisitorUrl::class);

        return $links->map(fn (SmartLink $l) => $this->shapeSmartLink($l, $visitorUrl))->values()->all();
    }

    /** @return array<string, mixed> */
    private function shapeSmartLink(SmartLink $l, SmartLinkVisitorUrl $visitorUrl): array
    {
        $meta = is_array($l->metadata) ? $l->metadata : [];
        $base = [
            'id' => (string) $l->id,
            'type' => $l->type,
            'family' => $l->family,
            'platform' => $l->platform,
            'visitorUrl' => $visitorUrl->build($l),
            'title' => $l->title,
            'imageUrl' => $l->image_url,
            'faviconUrl' => $l->favicon_url,
            'brandName' => $l->brand_name,
            'brandLogoUrl' => $l->brand_logo_url,
        ];

        return match ($l->type) {
            'commerce.product' => $base + [
                'price' => $this->smartLinkPrice($meta),
                'stockStatus' => $meta['stockStatus'] ?? 'unknown',
            ],
            'commerce.collection' => $base + ['collectionName' => $meta['collectionName'] ?? $l->title],
            'commerce.brand' => $base + ['heroImageUrl' => $meta['heroImageUrl'] ?? null],
            'commerce.event' => $base + [
                'startsAt' => $meta['startsAt'] ?? null,
                'endsAt' => $meta['endsAt'] ?? null,
                'location' => $meta['location'] ?? null,
            ],
            'content.podcast.episode' => $base + [
                'showName' => $meta['showName'] ?? null,
                'releaseDate' => $meta['releaseDate'] ?? null,
            ],
            'content.video' => $base + ['channelName' => $meta['channelName'] ?? null],
            // content.music.{track,album} — artist/album for the `Artist | Album | Track` line.
            default => $base + [
                'artist' => $meta['artist'] ?? null,
                'album' => $meta['album'] ?? null,
            ],
        };
    }

    /** @param array<string,mixed> $meta @return array{amount:float,currency:string}|null */
    private function smartLinkPrice(array $meta): ?array
    {
        if (! isset($meta['price']) || ! is_numeric($meta['price'])) {
            return null;
        }

        return ['amount' => (float) $meta['price'], 'currency' => (string) ($meta['currency'] ?? 'USD')];
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
     * @return array{name: string, address: string|null, addressLine1: string|null, city: string|null, state: string|null, postcode: string|null, country: string|null, latitude: float|null, longitude: float|null, phone: string|null, website: string|null}|null
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
            'address' => $data['address'] ?? null,
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
     * @param  Collection<string, Block>  $sections
     * @return list<array{url: string, urlHd: string|null, alt: string|null, caption: string|null, kind: string, poster: string|null, durationMs: int|null}>
     */
    private function buildGallery(?Site $site, Collection $sections): array
    {
        $envelope = $this->resolver->getGallery($site, $sections);
        $items = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        return array_values(array_map(static fn (array $item): array => [
            'url' => (string) ($item['url'] ?? ''),
            'urlHd' => $item['url_hd'] ?? null,
            'alt' => $item['alt_text'] ?? null,
            'caption' => $item['caption'] ?? null,
            'kind' => (string) ($item['kind'] ?? 'image'),
            'poster' => $item['poster'] ?? null,
            'durationMs' => $item['duration_ms'] ?? null,
        ], $items));
    }

    /**
     * Design media engine — DesignMediaItem[] (empty array when nothing in pool).
     *
     * Remaps the resolver's snake_case keys (sort_order, alt_text, url_hd,
     * duration_ms) to the camelCase wire shape per the §5 wire convention.
     * Mirrors buildGallery's pattern — same projection style across the two
     * polymorphic-media surfaces.
     *
     * @return list<array{id: string, sortOrder: int, kind: string, url: string, urlHd: string|null, alt: string|null, caption: string|null, poster: string|null, durationMs: int|null}>
     */
    private function buildDesignMedia(?Site $site): array
    {
        $items = $this->resolver->getContentMedia($site);

        return array_values(array_map(static fn (array $item): array => [
            'id' => (string) ($item['id'] ?? ''),
            'sortOrder' => (int) ($item['sort_order'] ?? 0),
            'kind' => (string) ($item['kind'] ?? 'image'),
            'url' => (string) ($item['url'] ?? ''),
            'urlHd' => $item['url_hd'] ?? null,
            'alt' => $item['alt_text'] ?? null,
            'caption' => $item['caption'] ?? null,
            'poster' => $item['poster'] ?? null,
            'durationMs' => $item['duration_ms'] ?? null,
        ], $items));
    }

    /**
     * Site image singletons — brand logos + per-integration cover images, keyed
     * by camelCase purpose (logoFull, logoSquare, coverFresha, coverYoutube,
     * coverAppleMusic, coverApplePodcast, coverEventbrite). Each value is
     * {url, urlHd}; absent purposes have no uploaded/ready image. Empty object
     * when nothing is set. partna-pages reads logos for the profile and covers
     * per integration; the theme decides how (if at all) to render them.
     *
     * @return array<string, array{url: string, urlHd: string|null}>
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
     * @param  array{state: string, data: array|null}  $bookingEnvelope
     * @return list<array{id: string|null, title: string, url: string, category: string, platform: string|null}>
     */
    private function buildLinks(?Site $site, array $bookingEnvelope): array
    {
        $links = $this->resolver->getLinks($site, $bookingEnvelope);

        return array_values(array_map(static function (array $link): array {
            $id = (string) ($link['id'] ?? '');

            return [
                'id' => $id !== '' ? $id : null,
                'title' => (string) ($link['title'] ?? ''),
                'url' => (string) ($link['url'] ?? ''),
                'category' => (string) ($link['category'] ?? 'custom'),
                'platform' => $link['platform'] ?? null,
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
     * @param  Collection<string, Block>  $sections
     * @return list<array{id: string|int, title: string, description: string|null, priceCents: int|null, currencyCode: string|null, durationMinutes: int|null, category: string}>
     */
    private function buildServices(?Site $site, string $proId, Collection $sections): array
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
    private function loadDesignKit(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        $row = DB::connection('pgsql')
            ->table('site.design_kits')
            ->where('site_id', $site->id)
            ->first();

        if (! $row) {
            return [];
        }

        // Convert stdClass → array, drop the FK column + any null values
        // (partna-pages fills nulls from code-side defaults).
        $cols = (array) $row;
        unset($cols['site_id']);

        $stored = array_filter($cols, fn ($v) => $v !== null);
        if ($stored === []) {
            return [];
        }

        return $this->groupKitColumns($stored);
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
        // Responsive companion groups use a two-token prefix (e.g.
        // `space_desktop_regular` → spaceDesktop.regular). Match these
        // BEFORE the single-token prefixes so the longer match wins.
        $twoTokenPrefixes = [
            'space_desktop' => 'spaceDesktop',
            'sizing_desktop' => 'sizingDesktop',
            'typography_desktop' => 'typographyDesktop',
        ];

        // Single-token prefix → wire group key. Pluralisation isn't
        // mechanical (typography stays singular), so the map is explicit.
        $singleTokenPrefixes = [
            'color' => 'colors',
            'typography' => 'typography',
            'border' => 'borders',
            'space' => 'space',
            'motion' => 'motion',
            'icon' => 'icons',   // singular prefix: icon_size, icon_color (reserved)
            'icons' => 'icons',  // plural prefix:   icons_xl_size, icons_stroke_width, etc.
            'effect' => 'effects',
            'sizing' => 'sizing',
            'button' => 'buttons',
        ];

        $out = [];
        foreach ($cols as $column => $value) {
            $group = null;
            $rest = null;

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
     * at render time. Currently only analyticsEndpoint; other fields will be
     * added as features need them.
     *
     * @return array<string, mixed>
     */
    private function buildPublicConfig(): array
    {
        return [
            'analyticsEndpoint' => config('partna.public_profile.analytics_endpoint'),
        ];
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
}
