<?php

namespace App\Http\Controllers\Api\Content;

use App\Content\Values\ValueResolver;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\ContentLibrary\UpsertManualOverrideRequest;
use App\Http\Resources\Content\ManualOverrideResource;
use App\Models\Content\Item;
use App\Models\Content\ManualOverride;
use App\Models\Core\User\User;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manual overrides — the C2-compliant lock that replaces `is_manual` (plan §6).
 *
 * One row per (item, facet, column). Three properties follow from that shape,
 * and all three are the point:
 *
 *   - editing one field freezes THAT field, not its siblings, so a user who
 *     fixes a typo in a title does not stop the price ever updating again;
 *   - reverting is DELETING the row, so "reset to source" needs no stored
 *     original and cannot drift from one;
 *   - a NULL value is an explicit CLEAR, not "unset" — which is why the write
 *     path uses `present` rather than `required`.
 *
 * {@see ValueResolver} honours an override absolutely and
 * forever. That is exactly why the (facet, column) pair is validated against
 * {@see FacetRegistry} on the way in.
 */
class ManualOverrideController extends ApiController
{
    use ResolveCurrentUser;

    public function upsert(UpsertManualOverrideRequest $request, string $itemId): JsonResponse
    {
        $user = $this->currentUser($request);
        $item = $this->findItem($user, $itemId);

        $this->authorizeForUser($user, 'update', $item);

        $data = $request->validated();

        $override = ManualOverride::query()
            ->where('item_id', $item->id)
            ->where('facet', $data['facet'])
            ->where('column_name', $data['column'])
            ->first() ?? new ManualOverride;

        $override->item_id = (string) $item->id;
        $override->facet = $data['facet'];
        $override->column_name = $data['column'];
        // Assigned directly rather than through fill(): a null here is the
        // user clearing the field, and fill() with a missing key would look
        // identical to it.
        $override->value = $data['value'];
        $override->save();

        $this->bumpSites($user);

        return $this->success(['override' => new ManualOverrideResource($override)]);
    }

    /** "Reset to source": delete the row and the sources speak again. */
    public function destroy(Request $request, string $itemId, string $facet, string $column): JsonResponse
    {
        $user = $this->currentUser($request);
        $item = $this->findItem($user, $itemId);

        $this->authorizeForUser($user, 'update', $item);

        $deleted = ManualOverride::query()
            ->where('item_id', $item->id)
            ->where('facet', $facet)
            ->where('column_name', $column)
            ->delete();

        if ($deleted === 0) {
            abort(404, 'That field has no manual edit to reset.');
        }

        $this->bumpSites($user);

        return $this->success(['reset' => true]);
    }

    private function findItem(User $user, string $itemId): Item
    {
        $item = Item::query()->where('id', $itemId)->where('user_id', $user->id)->first();

        if ($item === null) {
            abort(404, 'Item not found.');
        }

        return $item;
    }

    /**
     * An override changes what the page says, so it must discharge all three
     * cache lanes ({@see SiteCacheLanes}), not just build
     * state. Lane 2 (`site.sites.updated_at`) is load-bearing here
     * specifically: {@see PoolResolver::resolve()} reads
     * `content.manual_overrides` LIVE (`PoolResolver.php:502`) whenever it
     * runs, and `IndividualProfilePayloadBuilder` calls that resolver while
     * building the public payload it then caches under a key derived from
     * `site.sites.updated_at` (60s TTL). Skip lane 2 and the override IS
     * applied the next time the payload happens to rebuild, but the origin
     * keeps serving the pre-override cached payload — with a correctly-purged
     * CDN in front of it — for up to the rest of that TTL.
     */
    private function bumpSites(User $user): void
    {
        $siteIds = DB::table('site.sites')->where('user_id', $user->id)->pluck('id')
            ->map(fn ($id) => (string) $id)->all();

        SiteCacheLanes::bust($siteIds);
    }
}
