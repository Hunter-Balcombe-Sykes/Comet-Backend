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
    /**
     * Per-block-type allow-list of safe `settings.*` keys (audit PROF-1).
     *
     * `Block::$settings` is open-ended JSONB. Returning it verbatim risks
     * exposing whatever any future block type stores there — admin keys,
     * customer-form output, staging flags. The fix is: only pass through
     * keys explicitly listed for that block_type. An unknown block_type
     * gets an EMPTY settings bag, not the full JSONB — strict default.
     *
     * Adding a new public block setting requires an explicit entry here.
     *
     * @var array<string, list<string>>
     */
    private const PUBLIC_BLOCK_SETTINGS = [
        'link' => ['title', 'url', 'icon_key', 'icon_url', 'description'],
        'social' => ['title', 'url', 'platform', 'handle', 'icon_key'],
        'streaming' => ['title', 'url', 'platform', 'handle', 'is_live'],
        'video' => ['title', 'url', 'thumbnail_url', 'aspect_ratio'],
        'gallery' => ['title', 'media_ids', 'layout', 'aspect_ratio'],
        'bio' => ['title', 'body'],
        'experience' => ['title', 'items'],
        'credentials' => ['title', 'items'],
        'contact' => ['title', 'message', 'submit_label'],
        'contacts_collection' => ['title', 'message', 'submit_label'],
        'newsletter' => ['title', 'message', 'submit_label'],
        'documents' => ['title', 'document_ids'],
        'countdown' => ['title', 'ends_at', 'expired_message'],
        'barbershop_info' => ['title', 'hours', 'phone_public', 'address_public'],
        'sitepage_analytics' => ['title'],
    ];

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
        // PROF-3: when no Site row exists yet (early-setup pros) fall back to
        // the Professional's updated_at so block mutations roll the cache key
        // forward instead of being stuck at a permanent stamp=0.
        $stamp = $site?->updated_at?->timestamp
            ?? $pro->updated_at?->timestamp
            ?? 0;
        $key = "public.profile:{$handleLc}:{$stamp}";

        $payload = $this->cache->rememberLocked($key, $ttl, function () use ($pro, $site) {
            // PROF-2: filter `settings.design` through the Resource's published
            // DESIGN_KEYS allow-list so any non-display key that drifts into the
            // design bag (admin tooling, future settings service) doesn't leak.
            $rawDesign = (array) ($site?->settings['design'] ?? []);
            $design = array_intersect_key($rawDesign, array_flip(IndividualProfileResource::DESIGN_KEYS));

            $blocks = Block::query()
                ->where('professional_id', $pro->id)
                ->when(method_exists(Block::class, 'scopeVisible'), fn ($q) => $q->visible())
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Block $b) => [
                    'id' => $b->id,
                    'block_type' => $b->block_type,
                    'sort_order' => (int) $b->sort_order,
                    // PROF-1: per-block-type settings allow-list. Unknown types
                    // get an empty settings bag — strict default.
                    'settings' => $this->filterBlockSettings(
                        (string) ($b->block_type ?? ''),
                        (array) ($b->settings ?? [])
                    ),
                ])
                ->all();

            return (new IndividualProfileResource($pro, $design, $blocks))->resolve();
        });

        return response()->json(['data' => $payload]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function filterBlockSettings(string $blockType, array $settings): array
    {
        $allowed = self::PUBLIC_BLOCK_SETTINGS[$blockType] ?? [];
        if ($allowed === []) {
            return [];
        }

        return array_intersect_key($settings, array_flip($allowed));
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
