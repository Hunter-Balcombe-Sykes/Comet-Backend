<?php

namespace App\Services\Cache;

use App\Jobs\Cache\InvalidateBrandAffiliatesCacheJob;
use App\Models\Core\MediaVariant;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\ProfessionalHandleAlias;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Models\Views\PublicSitePayload;
use App\Services\Cache\Concerns\JitteredTtl;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

// V2: Public site payload caching with single-flight locking (prevents thundering herd). Handles 95% of traffic. Simplified in V2 — no more product payload caching.
class SiteCacheService
{
    use JitteredTtl;

    private const MISS_SENTINEL = '__MISS__';

    /**
     * Stale-extension multiplier — matches CacheLockService::STALE_TTL_MULTIPLIER.
     * Primary TTL 15m → :stale TTL 150m (2.5h last-good window) on the public payload.
     */
    private const PAYLOAD_STALE_TTL_MULTIPLIER = 10;

    /**
     * Short negative-cache window for "no published site for this subdomain". 30s on the
     * primary key keeps bot scans off the DB; the longer :stale window survives a primary
     * eviction so a parallel bot-burst still hits cache, not the view.
     */
    private const MISS_PRIMARY_TTL_SECONDS = 30;

    /** @var array<string, array<string, string|null>|null> */
    private array $brandPartnerEnrichmentCache = [];

    public function __construct(private readonly CacheLockService $cacheLock) {}

    /**
     * Query the DB view and build the public site payload, then cache and return it.
     * Returns null (with a short-lived sentinel cached) when the subdomain has no
     * published site in the view.
     *
     * @return array<string, mixed>|null
     */
    protected function buildPayloadFromDb(string $subdomain, string $key): ?array
    {
        $staleKey = $key.':stale';
        $row = PublicSitePayload::query()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        // View only contains published sites; if not found, treat as 404.
        if (! $row) {
            // Negative-cache briefly to reduce DB load from bot scans.
            // :stale gets a longer window so the next bot-burst still hits cache
            // even if the primary just evicted.
            Cache::put($key, self::MISS_SENTINEL, now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS));
            Cache::put($staleKey, self::MISS_SENTINEL, now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS * self::PAYLOAD_STALE_TTL_MULTIPLIER));

            return null;
        }

        $payload = $row->payload ?? [];

        // The view's COALESCE guarantees services is always a jsonb array,
        // so a missing key is the only thing we have to defend against.
        $services = $payload['services'] ?? [];

        $site = $payload['site'] ?? null;
        if (is_array($site)) {
            $site = $this->safeHydrateSitePayload(
                $site,
                (string) ($row->professional_id ?? ''),
                (string) ($row->site_id ?? ''),
                $subdomain
            );
        }

        // Must match the controller response shape exactly.
        $links = is_array($payload['links'] ?? null) ? array_values($payload['links']) : [];
        $sections = is_array($payload['sections'] ?? null) ? array_values($payload['sections']) : [];
        $existingBlocks = is_array($payload['blocks'] ?? null) ? array_values($payload['blocks']) : [];

        $data = [
            'published' => true,
            'site' => $site,
            'professional' => $payload['professional'] ?? null,
            'theme' => $payload['theme'] ?? null,
            'services' => $services,
            'links' => $links,
            'sections' => $sections,
            'blocks' => $this->buildCombinedBlocksPayload($links, $sections, $existingBlocks),
            'legal' => $payload['legal'] ?? null,
            // Preserve the store sub-object from the source payload so that
            // withStorePayload() below can lift selected_products + commission
            // settings out of payload.store. Without this, the rebuilt $data
            // has no 'store' key and withStorePayload falls through to empty
            // defaults — the front-end would see no featured products even
            // though the view row contains them.
            'store' => $payload['store'] ?? null,
        ];
        $data = $this->ensureProfessionalType($data, (string) ($row->professional_id ?? ''));
        $data = $this->safeWithStorePayload($data, (string) ($row->professional_id ?? ''), $subdomain);
        $data = $this->safeApplyBrandImageFallbacks($data, $subdomain);

        $this->writePayloadWithStale($key, $data);

        return $data;
    }

    /**
     * Write the payload to both the primary (jittered TTL) and :stale (×10 window) keys.
     *
     * Mirrors CacheLockService::writeWithJitter's contract so a primary expiry inside
     * the SWR fast path in getPublicSitePayload() can return last-good immediately while
     * one worker recomputes. busts must clear both keys (see invalidateSite() — keys are
     * routed through bustWithStale()).
     */
    private function writePayloadWithStale(string $key, mixed $value): void
    {
        $base = (int) config('partna.cache.ttls.public_payload');

        // Independent jitter draws so primary and stale copies expire at different seconds.
        Cache::put($key, $value, self::applyJitter($base));
        Cache::put($key.':stale', $value, self::applyJitter($base * self::PAYLOAD_STALE_TTL_MULTIPLIER));
    }

    /**
     * Get public site payload (MOST CRITICAL - 95% of traffic)
     *
     * IMPORTANT: This must match the PublicSiteController response shape exactly.
     */
    public function getPublicSitePayload(string $subdomain): ?array
    {
        $subdomain = strtolower($subdomain);

        $key = CacheKeyGenerator::publicSitePayload($subdomain);
        $staleKey = $key.':stale';
        $cached = Cache::get($key);

        if ($cached === self::MISS_SENTINEL) {
            return null;
        }
        if (is_array($cached)) {
            // Backward-compatible cache healing for payload shape changes.
            // Older cache entries may not include `services`.
            if (array_key_exists('services', $cached)) {
                $cached = $this->ensureBlockCollections($cached);
                $cached = $this->ensureProfessionalType(
                    $cached,
                    (string) data_get($cached, 'professional.id', '')
                );
                $cached = $this->safeWithStorePayload(
                    $cached,
                    (string) data_get($cached, 'professional.id', ''),
                    $subdomain
                );

                if (! array_key_exists('legal', $cached)) {
                    $cached['legal'] = null;
                }

                // Always resolve image variant paths to URLs (handles pre-URL-resolution cache entries)
                $site = $cached['site'] ?? null;
                if (is_array($site)) {
                    $professionalId = (string) data_get($cached, 'professional.id', '');
                    $cached['site'] = $this->safeHydrateSitePayload(
                        $site,
                        $professionalId,
                        '',
                        $subdomain
                    );
                }

                $this->writePayloadWithStale($key, $cached);

                return $cached;
            }
            Cache::forget($key);
            Cache::forget($staleKey);
        }

        // SWR fast path: primary expired but :stale still live — return last-good
        // immediately while one worker (lock winner) recomputes silently.
        $stale = Cache::get($staleKey);
        if ($stale !== null) {
            $fillLock = Cache::lock('site:fill:'.$subdomain, 10);
            // Non-blocking attempt: if another worker is already recomputing, fall
            // through and return the stale value — that's the whole point of SWR.
            if ($fillLock->get()) {
                try {
                    // Re-check primary: another process may have filled it while we raced.
                    $rechecked = Cache::get($key);
                    if ($rechecked === self::MISS_SENTINEL) {
                        return null;
                    }
                    if (is_array($rechecked) && array_key_exists('services', $rechecked)) {
                        return $rechecked;
                    }

                    return $this->buildPayloadFromDb($subdomain, $key);
                } finally {
                    $this->releaseLockQuiet($fillLock);
                }
            }

            // Another worker holds the fill lock — serve the last-good copy without
            // blocking. Sentinel preserves the "no site found" 404 contract.
            return $stale === self::MISS_SENTINEL ? null : (is_array($stale) ? $stale : null);
        }

        // Cold miss (no primary, no stale) — acquire a per-subdomain fill lock so
        // only one process rebuilds the payload from the DB view while concurrent
        // requests wait (single-flight).
        $fillLock = Cache::lock('site:fill:'.$subdomain, 10);

        try {
            // Block up to 5 s for the lock; raises LockTimeoutException if it can't.
            $fillLock->block(5);
        } catch (LockTimeoutException) {
            // Another process is (or was) filling the cache.
            $warm = Cache::get($key);
            if ($warm === self::MISS_SENTINEL) {
                return null;
            }
            if (is_array($warm)) {
                return $warm;
            }

            // Cache still empty — compute directly rather than flash-404.
            // Bounded stampede risk: only requests in the lock-timeout window run this path.
            return $this->buildPayloadFromDb($subdomain, $key);
        }

        try {
            // Double-check: the lock winner may have filled the cache while we waited.
            $rechecked = Cache::get($key);
            if ($rechecked === self::MISS_SENTINEL) {
                return null;
            }
            if (is_array($rechecked) && array_key_exists('services', $rechecked)) {
                return $rechecked;
            }

            return $this->buildPayloadFromDb($subdomain, $key);
        } finally {
            $this->releaseLockQuiet($fillLock);
        }
    }

    /**
     * Release a lock without letting a double-release (or driver quirk) bubble up.
     * Mirrors CacheLockService — the finally block must never throw on top of an
     * earlier exception from the closure.
     */
    private function releaseLockQuiet(\Illuminate\Contracts\Cache\Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // ignore — lock may have auto-expired or been released elsewhere
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyBrandImageFallbacks(array $payload): array
    {
        $professional = $payload['professional'] ?? null;
        if (! is_array($professional) || ($professional['professional_type'] ?? null) === 'brand') {
            return $payload;
        }

        $affiliateId = (string) ($professional['id'] ?? '');
        if (empty($affiliateId)) {
            return $payload;
        }

        // JSON column is affiliate-controlled; use it only as a hint for which brand is configured.
        $brandPartner = $payload['site']['settings']['brand_partner'] ?? null;
        if (! is_array($brandPartner) || empty($brandPartner['professional_id'])) {
            return $payload;
        }

        $claimedBrandId = (string) $brandPartner['professional_id'];

        // BrandPartnerLink is the consent record — verify before using any brand data.
        $link = BrandPartnerLink::where('affiliate_professional_id', $affiliateId)
            ->where('brand_professional_id', $claimedBrandId)
            ->first();

        if (! $link) {
            Log::warning('Brand-partner enrichment skipped: no verified link in consent table.', [
                'affiliate_id' => $affiliateId,
                'brand_id' => $claimedBrandId,
            ]);

            return $payload;
        }

        // Resolve brand_id from the verified link record, never from the mutable JSON.
        $brandId = (string) $link->brand_professional_id;
        $brandSite = Site::query()
            ->where('professional_id', $brandId)
            ->first();

        if (! $brandSite) {
            return $payload;
        }

        // Brand placeholders now live in site_media (pool=design, purpose=placeholder).
        // The service resolves variant URLs; we project them to { url, alt_text }
        // to match the new Hydrogen brand-design response shape.
        $designMedia = app(\App\Services\Media\BrandDesignMediaService::class)
            ->listDesignMedia((string) $brandSite->id);

        if (empty($designMedia['placeholders'])) {
            return $payload;
        }

        $placeholderImages = array_map(
            fn (array $p) => [
                'url' => $p['url'],
                'alt_text' => $p['alt_text'],
            ],
            $designMedia['placeholders']
        );

        $imageKeys = ['gallery', 'content_images'];

        foreach ($imageKeys as $key) {
            if (! isset($payload['site'][$key]) || ! is_array($payload['site'][$key])) {
                continue;
            }

            if (empty($payload['site'][$key])) {
                $payload['site'][$key] = $placeholderImages;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    private function safeHydrateSitePayload(array $site, string $professionalId, string $siteId, string $subdomain): array
    {
        try {
            $site = $this->resolveImageVariantUrlsInSite($site, $siteId);

            return $this->enrichSiteWithBrandPartnerRadius($site);
        } catch (\Throwable $e) {
            Log::warning('Public site payload hydration failed; returning base site payload.', [
                'subdomain' => $subdomain,
                'professional_id' => $professionalId,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);

            return $site;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safeWithStorePayload(array $payload, string $professionalId, string $subdomain): array
    {
        try {
            return $this->withStorePayload($payload, $professionalId);
        } catch (\Throwable $e) {
            Log::warning('Public site store payload build failed; returning payload without refreshed store data.', [
                'subdomain' => $subdomain,
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
            ]);

            return $payload;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safeApplyBrandImageFallbacks(array $payload, string $subdomain): array
    {
        try {
            return $this->applyBrandImageFallbacks($payload);
        } catch (\Throwable $e) {
            Log::warning('Brand image fallback enrichment failed; returning payload unchanged.', [
                'subdomain' => $subdomain,
                'professional_id' => (string) data_get($payload, 'professional.id', ''),
                'error' => $e->getMessage(),
            ]);

            return $payload;
        }
    }

    /**
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    public function enrichSiteWithBrandPartnerRadius(array $site): array
    {
        $settings = is_array($site['settings'] ?? null) ? $site['settings'] : [];
        $brandPartner = is_array($settings['brand_partner'] ?? null)
            ? $settings['brand_partner']
            : (is_array($settings['brandPartner'] ?? null) ? $settings['brandPartner'] : []);

        $professionalId = $brandPartner['professional_id'] ?? $brandPartner['professionalId'] ?? null;
        if (! is_string($professionalId) || trim($professionalId) === '') {
            return $site;
        }

        $enrichment = $this->resolveBrandPartnerEnrichmentData(trim($professionalId));
        if (! is_array($enrichment)) {
            return $site;
        }

        if ($this->isMissingBrandPartnerField($brandPartner, 'border_radius', 'borderRadius') && $this->isFilledString($enrichment['border_radius'] ?? null)) {
            $brandPartner['border_radius'] = $enrichment['border_radius'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'font_file_url', 'fontFileUrl') && $this->isFilledString($enrichment['font_file_url'] ?? null)) {
            $brandPartner['font_file_url'] = $enrichment['font_file_url'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'username', 'handle') && $this->isFilledString($enrichment['username'] ?? null)) {
            $brandPartner['username'] = $enrichment['username'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'first_name', 'firstName') && $this->isFilledString($enrichment['first_name'] ?? null)) {
            $brandPartner['first_name'] = $enrichment['first_name'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'last_name', 'lastName') && $this->isFilledString($enrichment['last_name'] ?? null)) {
            $brandPartner['last_name'] = $enrichment['last_name'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'border_color', 'borderColor') && $this->isFilledString($enrichment['border_color'] ?? null)) {
            $brandPartner['border_color'] = $enrichment['border_color'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'logo_letter_spacing', 'logoLetterSpacing') && $this->isFilledString($enrichment['logo_letter_spacing'] ?? null)) {
            $brandPartner['logo_letter_spacing'] = $enrichment['logo_letter_spacing'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'logo_font_size', 'logoFontSize') && $this->isFilledString($enrichment['logo_font_size'] ?? null)) {
            $brandPartner['logo_font_size'] = $enrichment['logo_font_size'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'border_width', 'borderWidth') && $this->isFilledString($enrichment['border_width'] ?? null)) {
            $brandPartner['border_width'] = $enrichment['border_width'];
        }
        if ($this->isMissingBrandPartnerField($brandPartner, 'general_spacing_padding', 'generalSpacingPadding') && $this->isFilledString($enrichment['general_spacing_padding'] ?? null)) {
            $brandPartner['general_spacing_padding'] = $enrichment['general_spacing_padding'];
        }

        $settings['brand_partner'] = $brandPartner;
        $site['settings'] = $settings;

        return $site;
    }

    /**
     * @return array<string, string|null>|null
     */
    private function resolveBrandPartnerEnrichmentData(string $professionalId): ?array
    {
        if (array_key_exists($professionalId, $this->brandPartnerEnrichmentCache)) {
            return $this->brandPartnerEnrichmentCache[$professionalId];
        }

        $partnerSite = Site::query()
            ->where('professional_id', $professionalId)
            ->first(['settings']);

        $partnerProfessional = Professional::query()
            ->whereKey($professionalId)
            ->first(['handle', 'first_name', 'last_name']);

        if (! $partnerSite && ! $partnerProfessional) {
            $this->brandPartnerEnrichmentCache[$professionalId] = null;

            return null;
        }

        $partnerSettings = is_array($partnerSite?->settings ?? null) ? $partnerSite->settings : [];
        $design = is_array($partnerSettings['design'] ?? null) ? $partnerSettings['design'] : [];
        $typography = is_array($design['typography'] ?? null) ? $design['typography'] : [];
        $resolved = [
            'username' => $this->normalizeString($partnerProfessional?->handle ?? null),
            'first_name' => $this->normalizeString($partnerProfessional?->first_name ?? null),
            'last_name' => $this->normalizeString($partnerProfessional?->last_name ?? null),
            'border_color' => $this->normalizeString($design['border_color'] ?? $design['borderColor'] ?? null),
            'border_radius' => $this->normalizeString($design['border_radius'] ?? $design['borderRadius'] ?? null),
            'border_width' => $this->normalizeString($design['border_width'] ?? $design['borderWidth'] ?? null),
            'general_spacing_padding' => $this->normalizeString($design['general_spacing_padding'] ?? $design['generalSpacingPadding'] ?? null),
            'font_file_url' => null,
            'logo_letter_spacing' => $this->normalizeString($typography['logo_letter_spacing'] ?? $typography['logoLetterSpacing'] ?? null),
            'logo_font_size' => $this->normalizeString($typography['logo_font_size'] ?? $typography['logoFontSize'] ?? null),
        ];

        $this->brandPartnerEnrichmentCache[$professionalId] = $resolved;

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $brandPartner
     */
    private function isMissingBrandPartnerField(array $brandPartner, string $snakeKey, string $camelKey): bool
    {
        return ! $this->isFilledString($brandPartner[$snakeKey] ?? null)
            && ! $this->isFilledString($brandPartner[$camelKey] ?? null);
    }

    private function isFilledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ensureBlockCollections(array $payload): array
    {
        $links = is_array($payload['links'] ?? null) ? array_values($payload['links']) : [];
        $sections = is_array($payload['sections'] ?? null) ? array_values($payload['sections']) : [];
        $existingBlocks = is_array($payload['blocks'] ?? null) ? array_values($payload['blocks']) : [];

        if ($links === [] && $existingBlocks !== []) {
            $links = array_values(array_filter($existingBlocks, function ($block): bool {
                return is_array($block)
                    && strtolower((string) ($block['block_group'] ?? '')) === 'links';
            }));
        }

        if ($sections === [] && $existingBlocks !== []) {
            $sections = array_values(array_filter($existingBlocks, function ($block): bool {
                return is_array($block)
                    && strtolower((string) ($block['block_group'] ?? '')) === 'sections';
            }));
        }

        $payload['links'] = $links;
        $payload['sections'] = $sections;
        $payload['blocks'] = $this->buildCombinedBlocksPayload($links, $sections, $existingBlocks);

        return $payload;
    }

    /**
     * Ensure professional payload includes professional_type.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ensureProfessionalType(array $payload, string $professionalId): array
    {
        $professional = $payload['professional'] ?? null;
        if (! is_array($professional)) {
            return $payload;
        }

        $existing = $professional['professional_type'] ?? null;
        if (is_string($existing) && trim($existing) !== '') {
            return $payload;
        }

        if ($professionalId === '') {
            $professionalId = (string) ($professional['id'] ?? '');
        }

        $resolved = null;
        if ($professionalId !== '') {
            $resolved = Professional::query()
                ->where('id', $professionalId)
                ->value('professional_type');
        }

        $professional['professional_type'] = is_string($resolved) && trim($resolved) !== ''
            ? $resolved
            : 'professional';
        $payload['professional'] = $professional;

        return $payload;
    }

    /**
     * @param  array<int, mixed>  $links
     * @param  array<int, mixed>  $sections
     * @param  array<int, mixed>  $existingBlocks
     * @return array<int, array<string, mixed>>
     */
    private function buildCombinedBlocksPayload(array $links, array $sections, array $existingBlocks = []): array
    {
        if ($links === [] && $sections === []) {
            return array_values(array_filter($existingBlocks, fn ($block): bool => is_array($block)));
        }

        $normalizedLinks = array_map(function ($block): array {
            $data = is_array($block) ? $block : [];
            $data['block_group'] = 'links';

            return $data;
        }, $links);

        $normalizedSections = array_map(function ($block): array {
            $data = is_array($block) ? $block : [];
            $data['block_group'] = 'sections';

            return $data;
        }, $sections);

        $combined = array_merge($normalizedLinks, $normalizedSections);

        usort($combined, function (array $a, array $b): int {
            $aSort = (int) ($a['sort_order'] ?? PHP_INT_MAX);
            $bSort = (int) ($b['sort_order'] ?? PHP_INT_MAX);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }

            $aId = (string) ($a['id'] ?? '');
            $bId = (string) ($b['id'] ?? '');

            return $aId <=> $bId;
        });

        return array_values($combined);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withStorePayload(array $payload, string $professionalId): array
    {
        $store = null;
        $existingStore = $payload['store'] ?? null;

        if (
            is_array($existingStore)
            && is_array($existingStore['selected_products'] ?? null)
            && array_key_exists('default_commission_rate', $existingStore)
            && array_key_exists('max_featured_products', $existingStore)
        ) {
            $store = [
                'selected_products' => array_values($existingStore['selected_products']),
                'default_commission_rate' => (float) $existingStore['default_commission_rate'],
                'max_featured_products' => (int) $existingStore['max_featured_products'],
                'checkout_mode' => in_array(($existingStore['checkout_mode'] ?? null), ['shopify', 'stripe'], true)
                    ? $existingStore['checkout_mode']
                    : 'shopify',
            ];
        }

        if (
            $store === null
            && is_array($payload['selected_products'] ?? null)
            && array_key_exists('default_commission_rate', $payload)
            && array_key_exists('max_featured_products', $payload)
        ) {
            $store = [
                'selected_products' => array_values($payload['selected_products']),
                'default_commission_rate' => (float) $payload['default_commission_rate'],
                'max_featured_products' => (int) $payload['max_featured_products'],
                'checkout_mode' => in_array(($payload['checkout_mode'] ?? null), ['shopify', 'stripe'], true)
                    ? $payload['checkout_mode']
                    : 'shopify',
            ];
        }

        if ($store === null) {
            $store = [
                'selected_products' => [],
                'default_commission_rate' => (float) config('partna.store.default_commission_rate', 15),
                'max_featured_products' => (int) config('partna.store.max_featured_products', 12),
                'checkout_mode' => 'shopify',
            ];
        }

        $payload['store'] = $store;
        $payload['selected_products'] = $store['selected_products'];
        $payload['default_commission_rate'] = $store['default_commission_rate'];
        $payload['max_featured_products'] = $store['max_featured_products'];
        $payload['checkout_mode'] = $store['checkout_mode'] ?? 'shopify';

        return $payload;
    }

    /**
     * Resolve image variant paths to public URLs in the site payload.
     * The view returns storage paths; we need full URLs for the frontend.
     * Also resolves video variant/stream/poster paths in gallery_videos and content_videos.
     *
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    private function resolveImageVariantUrlsInSite(array $site, string $siteId): array
    {
        // --- Collect all media IDs (images + videos) in one pass ---
        $allMediaIds = [];
        foreach (['gallery', 'content_images', 'gallery_videos', 'content_videos'] as $key) {
            foreach ($site[$key] ?? [] as $item) {
                if (is_array($item) && ! empty($item['id'])) {
                    $allMediaIds[] = $item['id'];
                }
            }
        }

        // Single query: one DB round-trip for all variant rows across all media types.
        // Index as: media_id → artifact_type → variant_key → URL
        $mvByMedia = [];
        if ($allMediaIds !== []) {
            $allVariants = MediaVariant::query()
                ->whereIn('media_id', array_unique($allMediaIds))
                ->get();

            foreach ($allVariants as $mv) {
                $mvByMedia[$mv->media_id][$mv->artifact_type][$mv->variant_key] = $mv->url;
            }
        }

        // --- Images: resolve variant URLs ---
        foreach (['gallery', 'content_images'] as $key) {
            $items = $site[$key] ?? [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $i => $item) {
                if (! is_array($item) || empty($item['id'])) {
                    continue;
                }
                $mediaId = $item['id'];
                $pathVariants = $item['variants'] ?? [];
                if (! is_array($pathVariants)) {
                    continue;
                }
                $urlVariants = [];
                foreach ($pathVariants as $variantKey => $path) {
                    $url = $mvByMedia[$mediaId]['webp'][$variantKey] ?? null;
                    if ($url !== null) {
                        $urlVariants[$variantKey] = $url;
                    }
                }
                $site[$key][$i]['variants'] = $urlVariants;
            }

            usort($site[$key], function ($a, $b) {
                $aSort = is_array($a) ? (int) ($a['sort_order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
                $bSort = is_array($b) ? (int) ($b['sort_order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
                if ($aSort !== $bSort) {
                    return $aSort <=> $bSort;
                }
                $aId = is_array($a) ? (string) ($a['id'] ?? '') : '';
                $bId = is_array($b) ? (string) ($b['id'] ?? '') : '';

                return $aId <=> $bId;
            });
        }

        // --- Videos: resolve variant/stream/poster URLs ---
        foreach (['gallery_videos', 'content_videos'] as $key) {
            $items = $site[$key] ?? [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $i => $item) {
                if (! is_array($item) || empty($item['id'])) {
                    continue;
                }
                $mediaId = $item['id'];
                $byType = $mvByMedia[$mediaId] ?? [];

                $site[$key][$i]['variants'] = $byType['mp4'] ?? [];
                $site[$key][$i]['streams'] = $byType['hls_playlist'] ?? [];
                $site[$key][$i]['poster'] = ($byType['poster']['poster'] ?? null);
            }

            usort($site[$key], function ($a, $b) {
                $aSort = is_array($a) ? (int) ($a['sort_order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
                $bSort = is_array($b) ? (int) ($b['sort_order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
                if ($aSort !== $bSort) {
                    return $aSort <=> $bSort;
                }
                $aId = is_array($a) ? (string) ($a['id'] ?? '') : '';
                $bId = is_array($b) ? (string) ($b['id'] ?? '') : '';

                return $aId <=> $bId;
            });
        }

        // Ensure video keys always exist even when there are no videos (backward-compat).
        $site['gallery_videos'] = $site['gallery_videos'] ?? [];
        $site['content_videos'] = $site['content_videos'] ?? [];

        // --- Document: resolve preview_url from storage path to full CDN URL ---
        if (isset($site['document']) && is_array($site['document']) && ! empty($site['document']['preview_url'])) {
            $rawPath = (string) $site['document']['preview_url'];
            $site['document']['preview_url'] = Storage::disk(config('partna.media_disk'))->url($rawPath);
        }

        return $site;
    }

    /**
     * Bust the Hydrogen brand-design cache for a site. Call from every write
     * path that touches design tokens or design media (logo, placeholders).
     *
     * Why explicit per-action: the stale window is 5s, so a forgotten bust
     * isn't catastrophic — but the user sees their own saves on Hydrogen
     * immediately only when every write path fires this.
     */
    public function forgetBrandDesign(string $siteId): void
    {
        // hydrogenBrandDesign is wrapped in CacheLockService::rememberLocked,
        // which writes a :stale twin. Clear both or the SWR fast path keeps
        // serving the pre-write value for up to 10× the 5s base TTL.
        $key = CacheKeyGenerator::hydrogenBrandDesign($siteId);
        Cache::forget($key);
        Cache::forget($key.':stale');
    }

    /**
     * Bust the Hydrogen affiliate-page cache for the affiliate that owns $siteId.
     *
     * The cache key is (brand_professional_id, slug). An affiliate may have
     * multiple slugs in play (current handle + historical aliases) and may be
     * linked to multiple brands, so this walks the full (brand × slug) matrix
     * and clears every entry — plus the :stale companion that CacheLockService
     * writes alongside the primary.
     *
     * Idempotent and best-effort: missing rows simply yield zero keys to delete.
     */
    public function forgetHydrogenAffiliate(string $siteId): void
    {
        // Cheapest exit first: an affiliate with no brand link has no cache
        // entry to bust. Site.professional_id is needed for the link query,
        // so we resolve it via a single value() rather than a full model
        // load — saves materialising the Site row when we'll only exit.
        $affiliateId = (string) Site::query()
            ->whereKey($siteId)
            ->value('professional_id');
        if ($affiliateId === '') {
            return;
        }

        $brandIds = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliateId)
            ->pluck('brand_professional_id')
            ->all();
        if ($brandIds === []) {
            return;
        }

        // Now resolve slugs — current handle + historical aliases — so we can
        // walk the full (brand × slug) matrix.
        $affiliate = Professional::query()
            ->whereKey($affiliateId)
            ->first(['handle']);

        $slugs = [];
        if ($affiliate && is_string($affiliate->handle) && $affiliate->handle !== '') {
            $slugs[] = strtolower($affiliate->handle);
        }

        $aliases = ProfessionalHandleAlias::query()
            ->where('professional_id', $affiliateId)
            ->pluck('handle')
            ->all();
        foreach ($aliases as $alias) {
            if (is_string($alias) && $alias !== '') {
                $slugs[] = strtolower($alias);
            }
        }
        $slugs = array_values(array_unique($slugs));

        if ($slugs === []) {
            return;
        }

        $keys = [];
        foreach ($brandIds as $brandId) {
            foreach ($slugs as $slug) {
                $primary = CacheKeyGenerator::hydrogenAffiliate((string) $brandId, $slug);
                $keys[] = $primary;
                $keys[] = $primary.':stale';
            }
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
    }

    /**
     * Bust Hydrogen affiliate-page cache keys for every active affiliate linked to $brandId.
     *
     * Mirrors the (brand × slug) matrix that forgetHydrogenAffiliate() builds for a single
     * affiliate, but inverts the query: given the brand, enumerate all linked affiliates,
     * resolve each affiliate's current handle + aliases, then delete the primary
     * hydrogenAffiliate(brandId, slug) key for each (the :stale twin is intentionally
     * left intact so SWR absorbs the fan-out rebuild — see the loop comment below).
     *
     * Called from the brand branch of invalidateSite() so a brand design/config change
     * propagates to every affiliate's Hydrogen page cache.
     */
    public function forgetHydrogenAffiliatesByBrand(string $brandId): void
    {
        if ($brandId === '') {
            return;
        }

        $affiliateIds = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->pluck('affiliate_professional_id')
            ->filter(fn ($id): bool => is_string($id) && $id !== '')
            ->all();

        if (empty($affiliateIds)) {
            return;
        }

        // Resolve current handles for all affiliates in one query.
        $handlesByAffiliate = Professional::query()
            ->whereIn('id', $affiliateIds)
            ->pluck('handle', 'id')
            ->all();

        // Resolve active aliases for all affiliates in one query.
        $aliasesByAffiliate = ProfessionalHandleAlias::query()
            ->whereIn('professional_id', $affiliateIds)
            ->get(['professional_id', 'handle'])
            ->groupBy('professional_id')
            ->map(fn ($rows) => $rows->pluck('handle')->all())
            ->all();

        $keys = [];
        foreach ($affiliateIds as $affiliateId) {
            $affiliateId = (string) $affiliateId;
            $slugs = [];

            $handle = $handlesByAffiliate[$affiliateId] ?? null;
            if (is_string($handle) && $handle !== '') {
                $slugs[] = strtolower($handle);
            }

            foreach ($aliasesByAffiliate[$affiliateId] ?? [] as $alias) {
                if (is_string($alias) && $alias !== '') {
                    $slugs[] = strtolower($alias);
                }
            }

            $slugs = array_values(array_unique($slugs));

            // Delete ONLY the primary key, not the :stale twin — a brand change
            // can fan out to hundreds of affiliate pages; keeping :stale lets the
            // SWR fast path serve last-good while one worker rebuilds, avoiding a
            // synchronised cold-rebuild stampede across the affiliate roster.
            foreach ($slugs as $slug) {
                $keys[] = CacheKeyGenerator::hydrogenAffiliate($brandId, $slug);
            }
        }

        if (! empty($keys)) {
            Cache::deleteMultiple(array_values(array_unique($keys)));
        }
    }

    /**
     * Bust the Hydrogen brand-config cache for a brand professional.
     *
     * The cache key is keyed by shop_domain (1:1 with brand professional via
     * the Shopify ProfessionalIntegration row), so this resolves the domain
     * once and clears both the primary key and its :stale twin.
     */
    public function forgetHydrogenBrandConfig(string $professionalId): void
    {
        if ($professionalId === '') {
            return;
        }

        $shopDomain = ProfessionalIntegration::query()
            ->where('professional_id', $professionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->value('shopify_shop_domain');

        if (! is_string($shopDomain) || trim($shopDomain) === '') {
            return;
        }

        $primary = CacheKeyGenerator::hydrogenBrandConfig(strtolower(trim($shopDomain)));
        Cache::deleteMultiple([$primary, $primary.':stale']);
    }

    /**
     * Bust the Hydrogen affiliate-products cache for an affiliate professional.
     *
     * Direct key (no enumeration) — clears the primary and its :stale twin.
     */
    public function forgetHydrogenAffiliateProducts(string $affiliateId): void
    {
        if ($affiliateId === '') {
            return;
        }

        $primary = CacheKeyGenerator::hydrogenAffiliateProducts($affiliateId);
        Cache::deleteMultiple([$primary, $primary.':stale']);
    }

    /**
     * Returns both the primary key and its SWR stale copy so invalidation is
     * always symmetric — prevents stale copies surviving after a primary bust.
     *
     * @return array{string, string}
     */
    private static function bustWithStale(string $key): array
    {
        return [$key, $key.':stale'];
    }

    /**
     * Invalidate all cache keys for a site
     */
    public function invalidateSite(Site $site): void
    {
        $professionalId = (string) ($site->professional_id ?? '');

        $keys = [
            // busts: site:payload:{subdomain} + :stale (CACHE-2 — SWR fast path
            // serves :stale on primary expiry, so invalidation must clear both)
            ...self::bustWithStale(CacheKeyGenerator::publicSitePayload($site->subdomain)),
            ...self::bustWithStale(CacheKeyGenerator::siteBlocks($site->id, 'links')),
            ...self::bustWithStale(CacheKeyGenerator::siteBlocks($site->id, 'sections')),
            CacheKeyGenerator::siteImages($site->id),
        ];

        // CACHE-1: every (pool, media_type) variant of /api/images. The polling
        // path (?ids[]) uses unbounded fingerprint keys and relies on its own 5s
        // TTL — not enumerated here.
        foreach (CacheKeyGenerator::siteImagesViewVariants() as [$pool, $mediaType]) {
            $variantKey = CacheKeyGenerator::siteImagesView($site->id, $pool, $mediaType);
            $keys[] = $variantKey;
            $keys[] = $variantKey.':stale';
        }

        // The auth-path Professional model cache (AUTH-1) holds the site relation
        // preloaded — site writes must bust it or the next 60s of authenticated
        // requests would see a stale subdomain / settings on $pro->site.
        if ($professionalId !== '') {
            $modelKey = CacheKeyGenerator::professionalModel($professionalId);
            $keys[] = $modelKey;
            $keys[] = $modelKey.':stale';
        }

        // If subdomain changed, kill the OLD cache key too.
        // This is critical so old URLs redirect (via alias) instead of returning cached payload.
        if ($site->wasChanged('subdomain')) {
            $old = strtolower((string) $site->getOriginal('subdomain'));
            if ($old !== '') {
                // busts: site:payload:{old} + :stale — same SWR contract as the new key.
                foreach (self::bustWithStale(CacheKeyGenerator::publicSitePayload($old)) as $oldKey) {
                    $keys[] = $oldKey;
                }
            }
        }

        // Invalidate all alias cache keys (your Site model has no aliases() relation)
        $aliasSubdomains = SiteSubdomainAlias::query()
            ->where('site_id', $site->id)
            ->pluck('subdomain')
            ->all();

        foreach ($aliasSubdomains as $aliasSubdomain) {
            // busts: site:payload:{alias} + :stale (CACHE-2 SWR symmetry)
            foreach (self::bustWithStale(CacheKeyGenerator::publicSitePayload(strtolower($aliasSubdomain))) as $aliasKey) {
                $keys[] = $aliasKey;
            }
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));

        // Master Pattern 15: bust the Hydrogen response caches keyed off this
        // site. The two cache families are mutually exclusive — hydrogen-affiliate
        // is keyed by (brand, affiliate-handle) and only meaningful for affiliate
        // sites; hydrogen-brand-config is keyed by shop_domain and only meaningful
        // for brand sites. Determine the site owner's role and bust the
        // appropriate cache. Wrapped in try-catch so a hydrogen-bust failure
        // (e.g. DB hiccup resolving handle aliases) never blocks the primary
        // public-payload invalidation above.
        if ($professionalId !== '') {
            try {
                $owner = Professional::query()
                    ->whereKey($professionalId)
                    ->first(['professional_type']);
                if ($owner?->isBrand()) {
                    $this->forgetHydrogenBrandConfig($professionalId);

                    // CACHE-6: also clear Hydrogen affiliate-page caches for every
                    // active partner linked to this brand. A brand design/config change
                    // is reflected on each affiliate's Hydrogen page, so those keys must
                    // be invalidated alongside the brand-config key.
                    // Walk BrandPartnerLink rows and clear hydrogenAffiliate keys using
                    // the same (brand, slug) matrix that forgetHydrogenAffiliate() builds.
                    $this->forgetHydrogenAffiliatesByBrand($professionalId);
                } else {
                    $this->forgetHydrogenAffiliate((string) $site->id);
                }
            } catch (\Throwable $e) {
                Log::warning('Hydrogen cache bust failed during invalidateSite', [
                    'site_id' => $site->id,
                    'professional_id' => $professionalId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // SCALE-3: replace the inline O(N) per-subdomain dispatch loop with a single
        // job that chunks BrandPartnerLink rows internally (500 per chunk). This avoids
        // dispatching potentially thousands of individual jobs into the queue when a
        // brand edit invalidates a large affiliate roster.
        if ($professionalId !== '') {
            InvalidateBrandAffiliatesCacheJob::dispatch($professionalId);
        }
    }

    public function getSiteLinkBlocks(string $siteId): array
    {
        // Single-flight + ±20% jitter + SWR via CacheLockService. Without the
        // lock, a cold cache after a flush lets every concurrent visitor on a
        // popular site rebuild this list in parallel; with int TTL the helper
        // also spreads expiry across the fleet.
        return $this->cacheLock->rememberLocked(
            CacheKeyGenerator::siteBlocks($siteId, 'links'),
            (int) config('partna.cache.ttls.public_payload'),
            fn () => Block::query()
                ->where('site_id', $siteId)
                ->where('block_group', 'links')
                ->active()
                ->orderBy('sort_order')
                ->get()
                ->toArray()
        );
    }

    /**
     * Warm cache for a site (call after updates)
     */
    public function warmSiteCache(string $subdomain): void
    {
        $this->getPublicSitePayload($subdomain);
    }
}
