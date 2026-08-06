<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Content\Item;
use App\Site\Documents\BuildState;
use App\Site\Pools\ItemLinkRules;
use App\Site\Pools\PoolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Hand-saved cross-platform links on a pool item (owner, 2026-08-05): "this
// same release on Spotify". Embed-only platforms can never be item sources,
// so the item carries these itself. Alternates only — a platform already
// contributing a SYNCED link is refused, so the synced link can't drift.
class ItemLinkController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    /** PUT /api/content/items/{item}/links/{platform}  { url } */
    public function upsert(Request $request, string $itemId, string $platform): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $item = $this->findItem((string) $user->id, $itemId);

        $pool = PoolRegistry::poolForKind($item->kind);
        if ($pool === null || ! ItemLinkRules::allowsPlatform($pool, $platform)) {
            return $this->error('That platform cannot carry a link for this item.', 422);
        }

        if (in_array($platform, ItemLinkRules::syncedPlatformsFor((string) $item->id), true)) {
            return $this->error('That platform already syncs this item — its link follows the sync.', 422);
        }

        $data = $request->validate(['url' => ['required', 'string', 'max:2048', 'url:https']]);

        if (! ItemLinkRules::urlBelongsTo($platform, $data['url'])) {
            return $this->error('That link does not look like a '.str_replace('-', ' ', $platform).' address.', 422);
        }

        $updated = DB::connection('pgsql')->table('content.item_links')
            ->where('item_id', (string) $item->id)
            ->where('platform', $platform)
            ->update(['url' => $data['url'], 'updated_at' => now()]);

        if ($updated === 0) {
            DB::connection('pgsql')->table('content.item_links')->insert([
                'id' => (string) Str::uuid(),
                'item_id' => (string) $item->id,
                'platform' => $platform,
                'url' => $data['url'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        BuildState::bump((string) $site->id);
        if ($site->subdomain !== '') {
            // The pools serve LIVE (Option B) but the sitepage edge cache
            // does not — purge so the visitor page follows the edit.
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }

        return $this->success(['platform' => $platform, 'url' => $data['url']]);
    }

    /** DELETE /api/content/items/{item}/links/{platform} */
    public function destroy(Request $request, string $itemId, string $platform): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);
        $item = $this->findItem((string) $user->id, $itemId);

        $deleted = DB::connection('pgsql')->table('content.item_links')
            ->where('item_id', (string) $item->id)
            ->where('platform', $platform)
            ->delete();

        if ($deleted === 0) {
            abort(404, 'No saved link for that platform.');
        }

        BuildState::bump((string) $site->id);
        if ($site->subdomain !== '') {
            // The pools serve LIVE (Option B) but the sitepage edge cache
            // does not — purge so the visitor page follows the edit.
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }

        return $this->success(['deleted' => true]);
    }

    private function findItem(string $userId, string $itemId): Item
    {
        $item = Item::query()
            ->where('id', $itemId)
            ->where('user_id', $userId)
            ->whereNull('removed_at')
            ->first();

        if ($item === null) {
            abort(404, 'Item not found.');
        }

        return $item;
    }
}
