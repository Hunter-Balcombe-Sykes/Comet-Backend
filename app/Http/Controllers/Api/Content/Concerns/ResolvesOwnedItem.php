<?php

namespace App\Http\Controllers\Api\Content\Concerns;

use App\Models\Content\Item;
use App\Models\Core\User\User;

/**
 * THE tenant decider for /api/content/items/{item} verbs: the item must be
 * the caller's own and not removed, else 404 — never 403, so an attacker
 * cannot tell an existing foreign id from a missing one. Shared by every
 * content-item controller so the DAST IDOR probe on one covers them all
 * (DastIdorCoverageDriftTest IDOR_VERB_VARIANT_OF).
 */
trait ResolvesOwnedItem
{
    protected function ownedItemOr404(User $user, string $itemId): Item
    {
        $item = Item::query()
            ->where('id', $itemId)
            ->where('user_id', $user->id)
            ->whereNull('removed_at')
            ->first();

        if ($item === null) {
            abort(404, 'Item not found.');
        }

        return $item;
    }
}
