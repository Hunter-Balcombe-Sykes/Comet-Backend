<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Content\Item;
use App\Site\Documents\BuildState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The library delete (C8): removed_at is THE user-delete mechanism and is
// never cleared by reappearance — a re-scrape can bring the record back to
// source_items, but the item stays gone. Curation rows are left in place:
// the resolver drops a removed item without spending a slot, and the trace
// explains "you deleted this" rather than losing the history.
class ItemController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    /** DELETE /api/content/items/{item} */
    public function destroy(Request $request, string $itemId): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        $item = Item::query()
            ->where('id', $itemId)
            ->where('user_id', $user->id)
            ->whereNull('removed_at')
            ->first();

        if ($item === null) {
            abort(404, 'Item not found.');
        }

        $item->removed_at = now();
        $item->save();

        BuildState::bump((string) $site->id);
        if ($site->subdomain !== '') {
            // The pools serve LIVE (Option B) but the sitepage edge cache
            // does not — purge so the visitor page follows the edit.
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }

        return $this->success(['removed' => true]);
    }
}
