<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicSite\IndividualProfileResource;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §28.8 — Public profile API for individual professionals.
 *
 *   GET /api/public/profiles/{handle}
 *
 * Authentication: NONE — public. Consumed by the Astro Worker subrequest.
 *
 * 404 rules (audit: avoid existence leak on public endpoints):
 *   - Handle not found
 *   - Professional is a brand   (served by Hydrogen at <handle>.partna.au)
 *   - Professional is a partner (Worker 301s to <brand>.partna.au/<handle>)
 *
 * Caching: 60s TTL (configurable) via CacheLockService::rememberLocked.
 * Cache key includes handle + site's updated_at so any SiteObserver-fired
 * mutation rolls the key forward; the CloudflareCachePurgeJob (§28.7)
 * separately drops the edge cache so the next request rebuilds from here.
 */
class IndividualProfileController extends Controller
{
    public function __construct(private readonly CacheLockService $cache) {}

    public function show(Request $request, string $handle): JsonResponse
    {
        $handleLc = strtolower(trim($handle));
        if ($handleLc === '') {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $ttl = max(1, (int) config('partna.public_profile.cache_ttl_seconds', 60));

        // Cheap pre-resolve so cache-key versioning sees the latest site updated_at
        // without paying full payload assembly on every hit.
        $pro = Professional::query()->where('handle_lc', $handleLc)->first();
        if (! $pro || ! $this->isIndividualLike($pro)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $site = Site::query()->where('professional_id', $pro->id)->first();
        $stamp = $site?->updated_at?->timestamp ?? 0;
        $key = "public.profile:{$handleLc}:{$stamp}";

        $payload = $this->cache->rememberLocked($key, $ttl, function () use ($pro, $site) {
            $design = (array) ($site?->settings['design'] ?? []);
            $blocks = Block::query()
                ->where('professional_id', $pro->id)
                ->when(method_exists(Block::class, 'scopeVisible'), fn ($q) => $q->visible())
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Block $b) => [
                    'id' => $b->id,
                    'block_type' => $b->block_type,
                    'sort_order' => (int) $b->sort_order,
                    'settings' => $b->settings ?? [],
                ])
                ->all();

            return (new IndividualProfileResource($pro, $design, $blocks))->resolve();
        });

        return response()->json(['data' => $payload]);
    }

    /**
     * An "individual-like" pro is anyone whose account_type is Individual, or who
     * has no account_type set (transition window) and is not a brand.
     */
    private function isIndividualLike(Professional $pro): bool
    {
        if ($pro->isBrand() || $pro->isPartner()) {
            return false;
        }

        // After §28.1 backfill, account_type should always be set. Defensive accept of
        // Individual specifically (or no enum) — never brand/partner.
        return ! ($pro->account_type instanceof AccountType) || $pro->account_type === AccountType::Individual;
    }
}
