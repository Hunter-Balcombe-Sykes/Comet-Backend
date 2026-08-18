<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Content\Concerns\ResolvesOwnedItem;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Content\Item;
use App\Services\Content\ManualServiceWriter;
use App\Site\Documents\SiteCacheLanes;
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
    use ResolvesOwnedItem;

    /** DELETE /api/content/items/{item} */
    public function destroy(Request $request, string $itemId, ManualServiceWriter $writer): JsonResponse
    {
        $user = $this->currentUser($request);
        $site = $this->currentSite($user);

        $item = $this->ownedItemOr404($user, $itemId);

        // Through markRemoved() rather than setting the column here, so this
        // path and the five service ones share ONE removal seam. Slice 4 hung
        // slug-freeing off that seam; a hand-written update here would have
        // silently skipped it and squatted the dish's URL forever. The class
        // is service-flavoured by name only — markRemoved() is kind-agnostic.
        $writer->markRemoved((string) $item->id);

        SiteCacheLanes::bust([(string) $site->id]);

        return $this->success(['removed' => true]);
    }
}
