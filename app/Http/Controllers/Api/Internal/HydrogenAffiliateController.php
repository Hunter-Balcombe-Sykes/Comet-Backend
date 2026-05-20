<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Site\Site;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// V2: Internal endpoint for Hydrogen loaders. Validates an affiliate slug belongs to
// a brand and returns the affiliate's full sitepage payload: gallery, content images,
// every link category (social + content + education + events + custom + synthesized
// booking), bio + about, document, newsletter, services, and booking.
//
// Every section that has a Draft/Live toggle in the dashboard is returned as an
// envelope `{state: "draft"|"live", data: <payload>|null}`. State is `live` only
// when BOTH is_enabled (data requirements met — e.g. gallery has images) AND
// is_active (the pro chose Live) are true; either being false drops the section
// to draft, even mid-session if a pro deletes the underlying data. Hydrogen reads
// `state` to decide whether to mount the section; `data` is always null when draft
// so draft content never leaks publicly even if Hydrogen has a bug. Per-row
// Draft/Live (links) is handled server-side: is_active=false rows are filtered out.
class HydrogenAffiliateController extends ApiController
{
    // 60s primary TTL; CacheLockService writes a :stale twin at 10× this (10 min
    // last-good window) so the cache survives a brief origin hiccup. Push
    // invalidation via SiteCacheService::forgetHydrogenAffiliate keeps the
    // visible-to-user lag at ~zero on dashboard edits.
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly CacheLockService $cacheLock,
        private readonly SitepageDataResolverService $resolver,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'shop_domain' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $shopDomain = strtolower(trim($validated['shop_domain']));
        $slug = strtolower(trim($validated['slug']));

        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('Brand not found.', 404);
        }

        $affiliate = $this->findAffiliateBySlug($slug);

        if (! $affiliate || $affiliate->status !== 'active') {
            return $this->error('Affiliate not found.', 404);
        }

        $linked = BrandPartnerLink::query()
            ->where('brand_professional_id', $integration->professional_id)
            ->where('affiliate_professional_id', $affiliate->id)
            ->exists();

        if (! $linked) {
            return $this->error('Affiliate not found.', 404);
        }

        // Gate checks (3 cheap indexed lookups) run on every request; the
        // expensive 13+ query payload assembly is cached. Cache key includes
        // brand_id so two brands viewing the same affiliate get separate
        // entries — aligns with the per-brand authorization gate above.
        $cacheKey = CacheKeyGenerator::hydrogenAffiliate(
            (string) $integration->professional_id,
            $slug,
        );

        $payload = $this->cacheLock->rememberLocked(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildAffiliatePayload($affiliate),
        );

        // no-store: payload shape has evolved (e.g. links.id added in b9de807).
        // Prevent Oxygen/CDN from caching a stale shape across deploys. The
        // backend's own rememberLocked still protects the origin from stampedes.
        return $this->success($payload)->header('Cache-Control', 'no-store');
    }

    /**
     * Assemble the full affiliate-page payload. Extracted from show() so the
     * 13+ DB queries here run inside the rememberLocked closure.
     *
     * @return array<string, mixed>
     */
    private function buildAffiliatePayload(Professional $affiliate): array
    {
        $site = Site::where('professional_id', $affiliate->id)->first();

        // Preload the site's section blocks once (keyed by block_type). The
        // shared resolver uses the same pre-load helper internally; we read
        // from `$site->sectionBlocks()` here instead of the resolver's
        // generic `loadSections()` to preserve the existing eager-load
        // wiring (sectionBlocks() carries the relation's `->whereNull` on
        // deleted_at scope).
        $sections = $site
            ? $site->sectionBlocks()->whereNull('deleted_at')->get()->keyBy('block_type')
            : collect();

        $booking = $this->resolver->getBooking($site, $sections);

        return [
            'affiliate_id' => (string) $affiliate->id,
            'name' => $affiliate->display_name,
            'slug' => $affiliate->handle,
            'gallery' => $this->resolver->getGallery($site, $sections),
            // Content pool — no section-level gate; Hydrogen merges these
            // over brand defaults.
            'content_images' => $this->resolver->getContentImages($site),
            'links' => $this->resolver->getLinks($site, $booking),
            'bio' => $this->resolver->getBio($affiliate, $sections),
            'document' => $this->resolver->getDocument($site),
            'newsletter' => $this->resolver->getNewsletter($sections),
            'services' => $this->resolver->getServices($site, $affiliate->id, $sections),
            'booking' => $booking,
            // Shop has no content envelope (products come from Shopify), but
            // the block_id is needed so Hydrogen can fire click tracking when
            // the visitor opens the shop card.
            'shop' => $this->resolver->sectionEnvelope($sections, 'shop', fn () => null),
        ];
    }

    /**
     * Returns affiliate services for the Hydrogen "Services & Pricing" section.
     * Standalone endpoint kept for back-compat / lazy fetches; the same shape
     * also appears inside show() under the `services` envelope's `data`.
     */
    public function services(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'shop_domain' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $shopDomain = strtolower(trim($validated['shop_domain']));
        $slug = strtolower(trim($validated['slug']));

        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();

        if (! $integration) {
            return $this->error('Brand not found.', 404);
        }

        $affiliate = $this->findAffiliateBySlug($slug);

        if (! $affiliate || $affiliate->status !== 'active') {
            return $this->error('Affiliate not found.', 404);
        }

        $linked = BrandPartnerLink::query()
            ->where('brand_professional_id', $integration->professional_id)
            ->where('affiliate_professional_id', $affiliate->id)
            ->exists();

        if (! $linked) {
            return $this->error('Affiliate not found.', 404);
        }

        $site = Site::where('professional_id', $affiliate->id)->first();

        return $this->success($this->resolver->buildServicesData($site, $affiliate->id));
    }


    // ── Affiliate slug lookup (alias-aware) ──────────────────────────────────

    /**
     * Resolves an affiliate by current handle OR historical handle alias.
     *
     * Without alias fallback, renaming an affiliate's handle/subdomain would
     * immediately break every shared `<brand>.partna.au/<old-handle>` link
     * (Hydrogen would 404 → redirect to brand's Shopify store). The aliases
     * row is created by UpdateSiteAction on rename so old URLs keep resolving
     * to the same person under their new identity.
     */
    private function findAffiliateBySlug(string $slug): ?Professional
    {
        return Professional::query()
            ->where(function ($q) use ($slug) {
                $q->where('handle_lc', $slug)
                    ->orWhereExists(function ($sub) use ($slug) {
                        $sub->select(DB::raw(1))
                            ->from('site.professional_handle_aliases')
                            ->whereColumn('site.professional_handle_aliases.professional_id', 'core.professionals.id')
                            ->whereRaw('lower(handle) = ?', [$slug]);
                    });
            })
            ->whereNull('deleted_at')
            ->first();
    }

}
