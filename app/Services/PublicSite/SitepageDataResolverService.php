<?php

namespace App\Services\PublicSite;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use Illuminate\Support\Collection;

/**
 * Shared sitepage-data projection logic.
 *
 * Two consumers exist for the same underlying shape:
 *
 *  1. `HydrogenAffiliateController` — internal-auth endpoint that serves
 *     brand-partner storefronts on Hydrogen. Brand-fallback content
 *     (placeholders, fallback gallery, brand logo, brand slogan) is layered
 *     in by that controller AFTER calling these methods.
 *
 *  2. `IndividualProfileController` — public §28.8 endpoint that serves
 *     individual sitepages on partna-pages. Brand-fallback content is
 *     intentionally NOT layered (individuals have no brand).
 *
 * Both go through these helpers so the section envelope shape, the gallery
 * URL resolution, the booking link synthesis, the link-row platform
 * normalisation, and the document download URL never drift apart.
 *
 * Every method is side-effect free — read-only queries and pure projections.
 * The two controllers own their own cache + auth concerns.
 */
class SitepageDataResolverService
{
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
            ->get()
            ->keyBy('block_type');
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
                $platform = is_string($settings['platform'] ?? null)
                    ? strtolower(trim((string) $settings['platform']))
                    : null;
                $platform = $platform !== '' ? $platform : null;
                $category = is_string($settings['category'] ?? null)
                    ? strtolower(trim((string) $settings['category']))
                    : 'custom';

                $title = is_string($block->title) ? trim($block->title) : '';
                $url = is_string($block->url) ? trim($block->url) : '';

                // Older rows can have empty title/url at rest — settings.platform
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

    // ── Bio + credentials + experience ──────────────────────────────────

    /**
     * @param  Collection<string, Block>  $sections
     * @return array{state: string, data: array|null, block_id?: string}
     */
    public function getBio(User $pro, Collection $sections): array
    {
        return $this->sectionEnvelope($sections, 'bio', function () use ($pro): array {
            $about = is_array($pro->about) ? $pro->about : [];
            $credentials = is_array($about['credentials'] ?? null) ? $about['credentials'] : [];
            $experience = is_array($about['experience'] ?? null) ? $about['experience'] : [];

            // Public contact opt-in — render the blob only when at
            // least one of the two fields is set; collapse to null
            // otherwise so the engine's hasAboutMeContent gate can
            // treat absence as a single null check.
            $publicEmail = $this->trimToNull($pro->public_contact_email ?? null);
            $publicPhone = $this->trimToNull($pro->public_contact_number ?? null);
            $publicContact = ($publicEmail !== null || $publicPhone !== null)
                ? ['email' => $publicEmail, 'phone' => $publicPhone]
                : null;

            return [
                'text' => (string) ($pro->bio ?? ''),
                'credentials' => array_values(array_filter(array_map(
                    fn ($row) => $this->normaliseCredential($row),
                    $credentials,
                ))),
                'experience' => array_values(array_filter(array_map(
                    fn ($row) => $this->normaliseExperience($row),
                    $experience,
                ))),
                'public_contact' => $publicContact,
            ];
        });
    }

    /**
     * Workplace — Google Business Profile data stored on
     * site.sites.settings.google_business_profile by the dashboard.
     * Both Google-anchored (place_id set) and manual (place_id null)
     * entries flow through the same blob shape. Gated by the
     * 'workplace' section block so a designer can hide the workplace
     * card without deleting the underlying data.
     */
    public function getWorkplace(?Site $site, Collection $sections): array
    {
        return $this->sectionEnvelope($sections, 'workplace', function () use ($site): ?array {
            if (! $site) {
                return null;
            }
            $settings = is_array($site->settings) ? $site->settings : [];
            $profile = $settings['google_business_profile'] ?? null;
            if (! is_array($profile)) {
                return null;
            }
            $name = trim((string) ($profile['name'] ?? ''));
            if ($name === '') {
                return null;
            }

            return [
                'place_id' => $this->trimToNull($profile['place_id'] ?? null),
                'name' => $name,
                'address' => $this->trimToNull($profile['address'] ?? null),
                'address_line1' => $this->trimToNull($profile['address_line1'] ?? null),
                'city' => $this->trimToNull($profile['city'] ?? null),
                'state' => $this->trimToNull($profile['state'] ?? null),
                'postcode' => $this->trimToNull($profile['postcode'] ?? null),
                'country' => $this->trimToNull($profile['country'] ?? null),
                'latitude' => isset($profile['latitude']) ? (float) $profile['latitude'] : null,
                'longitude' => isset($profile['longitude']) ? (float) $profile['longitude'] : null,
                'phone' => $this->trimToNull($profile['phone'] ?? null),
                'website' => $this->trimToNull($profile['website'] ?? null),
            ];
        });
    }

    /** Trim a possibly-non-string value to a non-empty string, or null. */
    private function trimToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normaliseCredential($row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $description = isset($row['description']) && is_string($row['description'])
            ? trim($row['description'])
            : '';

        return [
            'title' => $title,
            'issuer' => trim((string) ($row['issuer'] ?? '')),
            'year' => isset($row['year']) && $row['year'] !== '' ? (string) $row['year'] : null,
            'description' => $description !== '' ? $description : null,
        ];
    }

    private function normaliseExperience($row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $title = trim((string) ($row['role'] ?? $row['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $organisation = trim((string) (
            $row['place']
            ?? $row['organisation']
            ?? $row['organization']
            ?? ''
        ));

        $start = isset($row['start']) && $row['start'] !== '' ? (string) $row['start'] : null;
        $rawEnd = $row['end'] ?? null;
        $end = is_string($rawEnd) && $rawEnd !== '' ? $rawEnd : null;

        if ($start !== null) {
            $period = $start.' – '.($end ?? 'Current');
        } elseif (isset($row['period']) && $row['period'] !== '') {
            $period = (string) $row['period'];
        } else {
            $period = null;
        }

        $description = isset($row['description']) && is_string($row['description'])
            ? trim($row['description'])
            : '';

        return [
            'title' => $title,
            'organisation' => $organisation,
            'period' => $period,
            'description' => $description !== '' ? $description : null,
        ];
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
        $bookingMode = strtolower((string) ($settings['booking_mode'] ?? 'manual'));
        $manualBookingUrl = trim((string) ($settings['manual_booking_url'] ?? ''));

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
