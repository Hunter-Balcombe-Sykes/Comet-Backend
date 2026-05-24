<?php

namespace App\Http\Controllers\Api\Professional\SiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Site\DestroyLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\IndexLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\ReorderBlocksRequest;
use App\Http\Requests\Api\Professional\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\Professional\Site\UpdateLinkBlockRequest;
use App\Http\Resources\LinkBlockResource;
use App\Models\Core\Professional\User;
use App\Models\Core\Site\Block;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\SocialLinkNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * V2: CRUD + reorder for link blocks on the professional's mini-site.
 *
 * Supports two write modes (see docs/social-links.md):
 *   - **Social mode**: client sends `platform` + (`handle` OR `url`). The
 *     SocialLinkNormalizer validates and rebuilds a canonical https URL; the
 *     controller stores `settings.platform` and `settings.handle` as soft tags.
 *   - **Custom mode**: client sends `title` + `url` (legacy contract preserved).
 *     No platform binding, free-form icon_key.
 *
 * Authorization: ownership on write actions is enforced via SitePolicy (authorizeForUser).
 * A type constraint abort_unless guards that only link-type blocks reach the policy check.
 */
class ProfessionalLinkBlockController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function __construct(
        private readonly SocialLinkNormalizer $normalizer
    ) {}

    private function authorizeCustomLinks(User $pro): void
    {
        // All individual users can manage custom links — no capability gate needed.
    }

    public function index(IndexLinkBlockRequest $request)
    {
        $pro = $this->currentProfessional($request);

        // Served from SiteCacheService::getSiteLinkBlocks (15-min TTL, single-flight,
        // SWR + jitter). Same helper that powers /api/me's `blocks` payload, so the
        // two endpoints stay consistent. Returns active link blocks only — inactive
        // (is_active=false) blocks aren't surfaced here. BlockObserver / SiteObserver
        // bust the key on every link write through SiteCacheService::invalidateSite.
        // $pro->site rides along on the AUTH-1 cached Professional model, so reading
        // $pro->site->id costs nothing.
        if ($pro->site) {
            return $this->success([
                'blocks' => app(SiteCacheService::class)->getSiteLinkBlocks($pro->site->id),
            ]);
        }

        return $this->success(['blocks' => []]);
    }

    public function store(StoreLinkBlockRequest $request)
    {
        $pro = $this->currentProfessional($request);
        $this->authorizeCustomLinks($pro);
        $site = $this->currentSite($pro);

        $data = $request->validated();

        // Social vs custom mode discriminator. Social mode delegates to the
        // normalizer to rebuild a canonical URL and tag settings.platform/handle.
        // Custom mode preserves the legacy field-by-field contract.
        try {
            $blockFields = $this->buildBlockFields($data);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $linkBlock = DB::transaction(function () use ($pro, $site, $blockFields, $data) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-links:{$site->id}"]);

            $maxSort = Block::query()
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->max('sort_order');

            $maxSort = is_null($maxSort) ? -1 : (int) $maxSort;

            $linkBlock = new Block(array_merge($blockFields, [
                'block_group' => 'links',
                'block_type' => 'link',
                'sort_order' => $maxSort + 1,
                'is_active' => $data['is_active'] ?? true,
            ]));

            $linkBlock->professional_id = $pro->id;
            $linkBlock->site_id = $site->id;
            $linkBlock->save();

            return $linkBlock->fresh();
        });

        return $this->success(['block' => new LinkBlockResource($linkBlock)], 201);
    }

    public function update(UpdateLinkBlockRequest $request, Block $linkBlock)
    {
        $pro = $this->currentProfessional($request);
        $this->authorizeCustomLinks($pro);

        // Type constraint: this endpoint only handles link-type blocks.
        abort_unless($linkBlock->block_group === 'links' && $linkBlock->block_type === 'link', 404);
        $this->authorizeForUser($pro, 'update', $linkBlock);

        $data = $request->validated();
        unset($data['id']);

        // If the request switches into social mode (or stays in social mode with
        // a new handle/url), re-normalize. Otherwise fall through to the legacy
        // partial-update path that just fills whatever fields were sent.
        if (! empty($data['platform'])) {
            try {
                $normalized = $this->buildBlockFields($data);
            } catch (InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 422);
            }

            // Merge normalized social fields, preserving any other fields the
            // user happened to send (e.g. is_active toggle alongside).
            $linkBlock->fill(array_merge(
                array_intersect_key($data, array_flip(['is_active'])),
                $normalized
            ));
        } else {
            // Strip the social-mode-only keys before fill — they're not Block columns.
            unset($data['platform'], $data['handle']);

            // Category lives in settings JSONB, not as a column. If the client
            // supplied a new category in isolation, merge it into existing settings.
            if (array_key_exists('category', $data)) {
                $existingSettings = is_array($linkBlock->settings) ? $linkBlock->settings : [];
                $existingSettings['category'] = $data['category'];
                $data['settings'] = array_merge($existingSettings, $data['settings'] ?? []);
                unset($data['category']);
            }

            $linkBlock->fill($data);
        }

        $linkBlock->save();

        return $this->success(['block' => new LinkBlockResource($linkBlock->fresh())]);
    }

    /**
     * Translate a validated request payload into the Block column values to
     * persist. Handles the social/custom mode split centrally so store() and
     * update() share one source of truth.
     *
     * Social mode produces:
     *   - url       = canonical https URL from the normalizer
     *   - icon_key  = registry's icon_key for the platform
     *   - title     = user-supplied OR the platform's display_name
     *   - settings  = user settings + {platform, handle, category} soft tags
     *
     * Custom mode produces:
     *   - url       = as supplied
     *   - icon_key  = as supplied
     *   - title     = as supplied
     *   - settings  = user settings + {category} (required in request)
     *
     * Category resolution order:
     *   1. Request-provided `category` wins (validated against the enum in the Form Request).
     *   2. Else fall back to the platform's default_category (platform-link case).
     *   3. Else a 422-level guard (validation layer should have caught a missing category on custom links).
     *
     * @param  array<string, mixed>  $data  Validated request payload
     * @return array<string, mixed> Block fillable fields
     *
     * @throws InvalidArgumentException When social-mode normalization fails (caller maps to 422)
     */
    private function buildBlockFields(array $data): array
    {
        $platform = $data['platform'] ?? null;
        $requestedCategory = $data['category'] ?? null;

        if ($platform !== null && $platform !== '') {
            $normalized = $this->normalizer->normalize(
                $platform,
                $data['handle'] ?? null,
                $data['url'] ?? null
            );

            // Tag settings.platform + settings.handle so the frontend can
            // re-render the edit form in social mode and so analytics can
            // group by platform later (slow but works without a column).
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $settings['platform'] = $normalized['platform_key'];
            if ($normalized['handle'] !== null) {
                $settings['handle'] = $normalized['handle'];
            }

            // Category: explicit override wins, else platform default.
            $registry = config("partna.social_platforms.{$normalized['platform_key']}", []);
            $settings['category'] = $requestedCategory ?: ($registry['default_category'] ?? 'other');

            return [
                'title' => ($data['title'] ?? '') !== '' ? $data['title'] : $normalized['display_name'],
                'url' => $normalized['url'],
                'icon_key' => $normalized['icon_key'],
                'settings' => $settings,
            ];
        }

        // Custom mode: category is required by the Form Request. Defensive
        // default here in case a future code path calls buildBlockFields
        // directly with incomplete data.
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        if ($requestedCategory === null || $requestedCategory === '') {
            throw new InvalidArgumentException('A category is required for custom links.');
        }
        $settings['category'] = $requestedCategory;

        return [
            'title' => $data['title'] ?? null,
            'url' => $data['url'] ?? null,
            'icon_key' => $data['icon_key'] ?? null,
            'settings' => $settings,
        ];
    }

    public function destroy(DestroyLinkBlockRequest $request, Block $linkBlock)
    {
        $request->validated();

        $pro = $this->currentProfessional($request);

        // Type constraint: this endpoint only handles link-type blocks.
        abort_unless($linkBlock->block_group === 'links' && $linkBlock->block_type === 'link', 404);
        $this->authorizeForUser($pro, 'delete', $linkBlock);

        $linkBlock->delete();

        return $this->success(['deleted' => true]);
    }

    public function reorder(ReorderBlocksRequest $request)
    {
        $pro = $this->currentProfessional($request);
        $this->authorizeCustomLinks($pro);
        $site = $this->currentSite($pro);

        $ids = array_values(array_unique($request->validated()['ids'] ?? []));

        DB::transaction(function () use ($pro, $site, $ids) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-links:{$site->id}"]);

            $allIds = Block::query()
                ->where('professional_id', $pro->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->where('block_type', 'link')
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);

            foreach ($ids as $id) {
                if (! isset($allSet[$id])) {
                    abort(422, 'One or more blocks are invalid');
                }
            }

            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder = array_merge($ids, $remaining);
            $offset = (int) Block::query()
                ->where('professional_id', $pro->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->max('sort_order') + 1000;

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('professional_id', $pro->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', 'links')
                    ->where('block_type', 'link')
                    ->where('id', $id)
                    ->update(['sort_order' => $offset + $i]);
            }

            foreach ($newOrder as $i => $id) {
                Block::query()
                    ->where('professional_id', $pro->id)
                    ->where('site_id', $site->id)
                    ->where('block_group', 'links')
                    ->where('block_type', 'link')
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        // Mass-update via the query builder bypasses Eloquent's `updated`
        // event on Block — so BlockObserver's touch-the-Site chain never
        // fires, and the §28.8 backend cache key (`public.profile:{handle}:
        // {site.updated_at}`) wouldn't rotate, and CloudflareCachePurgeJob
        // wouldn't dispatch. Explicit Site touch closes the gap.
        $site->touch();

        return $this->success(['ok' => true]);
    }
}
