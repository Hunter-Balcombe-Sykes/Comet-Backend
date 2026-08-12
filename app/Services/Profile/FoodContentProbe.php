<?php

namespace App\Services\Profile;

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

/**
 * Does this user have live food content that a sector demotion would strand?
 *
 * Paired with SectorProvenance::isFoodDemotion — that predicate is pure and
 * short-circuits, so this query runs ONLY on an actual business food ->
 * non-food attempt, not on every identity fold.
 *
 * One query on purpose: it runs inside IdentitySync's lockForUpdate
 * transaction, so four round-trips would be four times the lock hold.
 */
class FoodContentProbe
{
    public function existsFor(User $user): bool
    {
        $connection = $user->getConnectionName();
        $userId = (string) $user->id;

        // Explicit sub-selects, never $user->site: preventLazyLoading is on
        // outside production (AppServiceProvider:372) and would throw.
        $siteIds = DB::connection($connection)
            ->table('site.sites')
            ->select('id')
            ->where('user_id', $userId);

        // SLICE 4 SWAP POINT — the content-pool convergence retires
        // site.menus/site.menu_items onto content.items where kind='menu_item'.
        // Isolated here so that migration replaces one expression.
        $hasMenuItems = DB::connection($connection)
            ->table('site.menu_items')
            ->join('site.menus', 'site.menus.id', '=', 'site.menu_items.menu_id')
            ->where('site.menus.user_id', $userId)
            ->whereNull('site.menus.deleted_at');

        $hasOrderingConnection = DB::connection($connection)
            ->table('site.platform_connections')
            ->where('user_id', $userId)
            ->where('platform', 'online-ordering')
            // Raw builder, not IntegrationConnection::query() — it doesn't get
            // the model's SoftDeletes global scope, so both filters are explicit.
            ->where('is_active', true)
            ->whereNull('deleted_at');

        // A Menu PAGE with no dishes is still live on the public site, and a
        // demotion would 403 the owner out of editing it — the symptom
        // PageCapabilities' docblock names.
        $hasFoodPage = DB::connection($connection)
            ->table('site.pages')
            ->whereIn('site_id', $siteIds)
            ->whereIn('capability', ['menu', 'online_ordering', 'reservations']);

        // menu_item is a CONTENT ITEM kind, not a section kind —
        // site.sections.kind is CHECKed to collection/richtext/contact_form/
        // newsletter/map/document/policy. PageCapabilities::GATED_KINDS gates
        // 'menu_item' because a section rule saying `kind_is menu_item` is a
        // menu page wearing a different hat. This clause also happens to read
        // the table slice 4 migrates site.menu_items INTO.
        $hasFoodItems = DB::connection($connection)
            ->table('content.items')
            ->where('user_id', $userId)
            ->where('kind', 'menu_item')
            ->whereNull('removed_at');

        return DB::connection($connection)
            ->query()
            ->selectRaw('1')
            ->whereExists($hasMenuItems)
            ->orWhereExists($hasOrderingConnection)
            ->orWhereExists($hasFoodPage)
            ->orWhereExists($hasFoodItems)
            ->exists();
    }
}
