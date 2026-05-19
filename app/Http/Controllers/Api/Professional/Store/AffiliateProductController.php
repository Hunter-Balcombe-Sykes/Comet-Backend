<?php

namespace App\Http\Controllers\Api\Professional\Store;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\Store\ReorderSelectionsRequest;
use App\Http\Requests\Api\Professional\Store\UpdateSelectionVariantsRequest;
use App\Http\Resources\AffiliateProductResource;
use App\Http\Resources\AffiliateProductSelectionResource;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Media\ImageVariantService;
use App\Services\Store\AffiliateProductCatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AffiliateProductController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly AffiliateProductCatalogService $catalogService
    ) {}

    /**
     * GET /affiliate/products
     *
     * Returns the brand's Shopify catalog with the affiliate's selection state merged in.
     */
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        try {
            $result = $this->catalogService->getCatalogWithSelections($pro);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Unable to reach product catalog. Please try again.', 502);
        }

        return $this->success([
            'products' => AffiliateProductResource::collection(collect($result['products'])),
            'brand_professional_id' => $result['brand_professional_id'],
            'default_commission_rate' => $result['default_commission_rate'] ?? 15,
            'custom_photos_enabled' => $result['custom_photos_enabled'] ?? false,
            'product_image_ratio' => $result['product_image_ratio'] ?? null,
        ]);
    }

    /**
     * GET /affiliate/selections/stale
     *
     * Returns selections whose product GIDs are no longer in the brand's active catalog.
     */
    public function stale(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        try {
            $stale = $this->catalogService->getStaleSelections($pro);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 500);
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Unable to reach product catalog. Please try again.', 502);
        }

        return $this->success([
            'stale' => AffiliateProductSelectionResource::collection($stale),
        ]);
    }

    /**
     * POST /affiliate/selections
     *
     * Add a product to the affiliate's selections. brand_professional_id is derived
     * server-side from the affiliate's BrandPartnerLink — not accepted from the client.
     */
    public function store(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $validated = $request->validate([
            'shopify_product_gid' => ['required', 'string', 'max:100', 'regex:/^gid:\/\/shopify\/Product\/\d+$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            // Optional on create — most affiliates start with all variants shown
            // and narrow later via the PATCH variants endpoint. Null/empty stores
            // as NULL (show every brand-enabled variant).
            'selected_variant_gids' => ['sometimes', 'nullable', 'array'],
            'selected_variant_gids.*' => ['string', 'regex:/^gid:\/\/shopify\/ProductVariant\/\d+$/'],
        ]);

        // Derive brand from the affiliate's established link — not from the client request.
        $brandProfessionalId = (string) BrandPartnerLink::where('affiliate_professional_id', $pro->id)
            ->value('brand_professional_id');

        if ($brandProfessionalId === '') {
            return $this->error('You are not linked to any brand.', 422);
        }

        $skeleton = new AffiliateProductSelection([
            'affiliate_professional_id' => $pro->id,
            'brand_professional_id' => $brandProfessionalId,
        ]);
        $this->authorizeForUser($pro, 'create', $skeleton);

        // Verify product exists in the brand's active catalog
        try {
            if (! $this->catalogService->isProductInCatalog($brandProfessionalId, $validated['shopify_product_gid'])) {
                return $this->error('This product is not available for selection.', 422);
            }
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Unable to reach product catalog. Please try again.', 502);
        }

        // If the affiliate supplied a variant subset, intersect it with the brand's
        // currently-enabled variants. An empty or null array is treated as "no
        // override" and stored as NULL so a future brand-side re-enable is picked
        // up automatically.
        $selectedVariantGids = $this->resolveSelectedVariantGidsOrFail(
            $brandProfessionalId,
            $validated['shopify_product_gid'],
            $validated['selected_variant_gids'] ?? null,
            $errorResponse
        );

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $max = (int) config('partna.store.max_featured_products', 10);

        try {
            $selection = DB::transaction(function () use ($pro, $validated, $brandProfessionalId, $selectedVariantGids, $max) {
                // Advisory lock serializes concurrent selections per affiliate. Postgres only;
                // SQLite (tests) skips this — the UNIQUE constraint still prevents duplicates.
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["aff-sel:{$pro->id}"]);
                }

                // Count check INSIDE the lock — authoritative against concurrent inserts.
                $currentCount = AffiliateProductSelection::query()
                    ->where('affiliate_professional_id', $pro->id)
                    ->where('brand_professional_id', $brandProfessionalId)
                    ->count();

                if ($currentCount >= $max) {
                    throw new \DomainException("Maximum of {$max} selections allowed.");
                }

                return AffiliateProductSelection::create([
                    'affiliate_professional_id' => $pro->id,
                    'brand_professional_id' => $brandProfessionalId,
                    'shopify_product_gid' => $validated['shopify_product_gid'],
                    'sort_order' => $validated['sort_order'] ?? 0,
                    'selected_variant_gids' => $selectedVariantGids,
                ]);
            });
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return $this->error('This product is already selected.', 409);
            }
            throw $e;
        }

        return $this->success([
            'selection' => new AffiliateProductSelectionResource($selection),
        ], 201);
    }

    /**
     * PATCH /affiliate/selections/{productGid}/variants
     *
     * Update the affiliate's per-selection variant subset. Pass null or an empty
     * array in variant_gids to reset back to "show every brand-enabled variant"
     * (default). Pass a populated array to narrow the storefront to exactly those
     * variants — each one must currently be brand-enabled or the request 422s.
     */
    public function updateVariants(UpdateSelectionVariantsRequest $request, string $productGid): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        if (! preg_match('/^gid:\/\/shopify\/Product\/\d+$/', $productGid)) {
            return $this->error('Invalid product GID format.', 422);
        }

        $validated = $request->validated();

        $selection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('shopify_product_gid', $productGid)
            ->first();

        if (! $selection) {
            return $this->error('Selection not found.', 404);
        }

        $this->authorizeForUser($pro, 'update', $selection);

        // Brand is read from the persisted selection — not from the client request.
        $brandId = (string) $selection->brand_professional_id;

        $selectedVariantGids = $this->resolveSelectedVariantGidsOrFail(
            $brandId,
            $productGid,
            $validated['variant_gids'] ?? null,
            $errorResponse
        );

        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $selection->selected_variant_gids = $selectedVariantGids;
        $selection->save();

        return $this->success([
            'selection' => new AffiliateProductSelectionResource($selection),
        ]);
    }

    /**
     * Validate + normalise a submitted variant_gids list against the brand's
     * currently-enabled variants for a product. Returns the array to persist, or
     * null if the client passed null/empty (reset to default). On validation
     * failure, writes a JsonResponse to $errorResponse and returns null — callers
     * must check $errorResponse before using the return value.
     *
     * @param  array<int, string>|null  $submitted
     * @return array<int, string>|null
     */
    private function resolveSelectedVariantGidsOrFail(
        string $brandId,
        string $productGid,
        ?array $submitted,
        ?JsonResponse &$errorResponse
    ): ?array {
        $errorResponse = null;

        // Null or empty array = reset to default-all, stored as NULL.
        if ($submitted === null || $submitted === []) {
            return null;
        }

        try {
            $enabled = $this->catalogService->getEnabledVariantGidsForProduct($brandId, $productGid);
        } catch (\Throwable $e) {
            report($e);
            $errorResponse = $this->error('Unable to reach product catalog. Please try again.', 502);

            return null;
        }

        if (empty($enabled)) {
            // Either product missing from catalog or brand has disabled every variant —
            // nothing to narrow against. Reject rather than silently resetting so the
            // UI can show a clear message.
            $errorResponse = $this->error('This product has no variants available for selection.', 422);

            return null;
        }

        $allowed = array_flip($enabled);
        $invalid = array_values(array_filter($submitted, fn (string $gid) => ! isset($allowed[$gid])));

        if (! empty($invalid)) {
            $errorResponse = $this->error('One or more variants are not available on this product.', 422);

            return null;
        }

        // Deduplicate + reindex; the order the affiliate submits doesn't matter
        // because the frontend re-sorts by Shopify's variant order at render time.
        return array_values(array_unique($submitted));
    }

    /**
     * DELETE /affiliate/selections/{gid}
     *
     * Remove a selection by Shopify product GID.
     */
    public function destroy(Request $request, string $gid): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        // Validate GID format
        if (! preg_match('/^gid:\/\/shopify\/Product\/\d+$/', $gid)) {
            return $this->error('Invalid product GID format.', 422);
        }

        $selection = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $pro->id)
            ->where('shopify_product_gid', $gid)
            ->first();

        if (! $selection) {
            return $this->error('Selection not found.', 404);
        }

        $selection->delete();

        // Clean up custom product photos for the deselected product
        $site = Site::where('professional_id', $pro->id)->first();
        if ($site) {
            $orphanedPhotos = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_PRODUCT)
                ->where('product_gid', $gid)
                ->get();

            if ($orphanedPhotos->isNotEmpty()) {
                $imageService = app(ImageVariantService::class);
                foreach ($orphanedPhotos as $photo) {
                    $imageService->deleteVariants($photo->id, $photo->path);
                    $photo->delete();
                }
            }
        }

        return $this->success(['deleted' => true]);
    }

    /**
     * PATCH /affiliate/selections/reorder
     *
     * Bulk-update sort_order for the affiliate's selections.
     */
    public function reorder(ReorderSelectionsRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $items = $request->validated()['items'];
        $gids = array_column($items, 'product_gid');

        DB::transaction(function () use ($pro, $items, $gids) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["aff-sel:{$pro->id}"]);

            // Verify all GIDs belong to this affiliate
            $existingCount = AffiliateProductSelection::query()
                ->where('affiliate_professional_id', $pro->id)
                ->whereIn('shopify_product_gid', $gids)
                ->count();

            if ($existingCount !== count($gids)) {
                abort(422, 'One or more product GIDs do not belong to your selections.');
            }

            foreach ($items as $item) {
                AffiliateProductSelection::query()
                    ->where('affiliate_professional_id', $pro->id)
                    ->where('shopify_product_gid', $item['product_gid'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        return $this->success(['ok' => true]);
    }

    /**
     * POST /affiliate/selections/reset-to-defaults
     *
     * Clears the affiliate's current selections and reseeds from the brand's default collection.
     * Pass brand_professional_id to reset a single brand; omit to reset all linked brands.
     */
    public function resetToDefaults(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $data = $request->validate([
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if (isset($data['brand_professional_id'])) {
            try {
                $this->catalogService->seedDefaultSelections($pro, $data['brand_professional_id'], clearExisting: true);
            } catch (\Throwable $e) {
                report($e);

                return $this->error('Unable to reset selections. Please try again.', 502);
            }

            return $this->success(['reset' => true, 'brand_professional_id' => $data['brand_professional_id']]);
        }

        // No brand specified — reset across all currently-linked brands.
        // Tombstoned (ex-partner) links are excluded.
        $brandIds = DB::table('brand.brand_partner_links')
            ->where('affiliate_professional_id', $pro->id)
            ->whereNull('deleted_at')
            ->pluck('brand_professional_id');

        foreach ($brandIds as $brandId) {
            try {
                $this->catalogService->seedDefaultSelections($pro, (string) $brandId, clearExisting: true);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Failed to reset default selections for brand', [
                    'affiliate_professional_id' => $pro->id,
                    'brand_professional_id' => $brandId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->success(['reset' => true, 'brand_count' => $brandIds->count()]);
    }
}
