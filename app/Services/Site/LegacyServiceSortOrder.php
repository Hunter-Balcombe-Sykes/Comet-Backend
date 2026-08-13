<?php

namespace App\Services\Site;

use App\Models\Core\User\Service;
use App\Services\Content\ManualServiceWriter;

/**
 * Renumber `site.services.sort_order` for a user's WHOLE live legacy set —
 * Fresha rows AND the still-present legacy owner-authored rows
 * `ServiceBackfiller` never deletes — never just one source's subset.
 *
 * `services_user_sort_order_uq` (baseline migration) is
 * `UNIQUE (user_id, sort_order) WHERE deleted_at IS NULL`: **global per user
 * and NOT scoped by source.** Renumbering only the Fresha rows to a dense
 * 0..N-1 lands on slots the legacy manual rows still occupy (they were never
 * deleted at backfill, only superseded), and every reorder 500s for a user
 * holding both halves. That is why this starts from every live row.
 *
 * `$order`'s own array index — NOT a per-half recompacted counter — is the
 * target rank, with gaps allowed (the index only requires distinctness among
 * live rows, never contiguity). That is what keeps this on exactly the same
 * numbering scale as the manual half's `section_items.sort_key` pins
 * (§NEW-I1), so sorting the merged dashboard/public read by that shared rank
 * reconstructs the caller's actual combined order instead of grouping every
 * manual service ahead of every Fresha one regardless of what was submitted.
 *
 * **Deliberately NOT `ReorderService`.** Reusing it here was tried and
 * reverted: its internal two-pass recomputes a DENSE 0..N-1 over only the
 * legacy-resolvable subset, discarding the gaps left by manual ids with no
 * legacy row. That silently breaks §NEW-I1 the moment an unresolvable
 * (newly-created) manual id sits ahead of a Fresha id in `$order` — the
 * Fresha id compacts into a slot that no longer matches its position, and the
 * merged read puts it in the wrong place. Keeping the raw, gapped index is
 * what makes the two domains' numbers comparable. Do not "simplify" onto it.
 *
 * **WRITE-ONLY BOOKKEEPING for owner-authored rows:** nothing reads
 * `site.services.sort_order` for them post-cutover — the public path reads
 * `content.*`, the dashboard list reads `ManualServiceItems` ordered by
 * `section_items.sort_key`. These writes exist SOLELY to keep the shared
 * unique index satisfiable while both halves still share one table, and they
 * die with `site.services` in slice 7. That is NOT a second source of truth
 * for those rows — do not "fix" this back.
 *
 * Lives here rather than on `ManualServiceWriter` because that class writes
 * `content.*` and this writes the legacy table the cutover is retiring;
 * folding legacy bookkeeping into the content writer would blur exactly the
 * boundary slice 3a/3b drew. It sits beside `ReorderService` — the thing it
 * deliberately is not — so the contrast is visible at the point of choosing.
 */
class LegacyServiceSortOrder
{
    public function __construct(
        private readonly ManualServiceWriter $writer,
    ) {}

    /**
     * @param  list<string>  $order  ids in desired order (array position = rank)
     *                               — a mix of content.items (manual) and
     *                               site.services (Fresha) ids
     */
    public function renumber(string $userId, array $order): void
    {
        // Eloquent's SoftDeletes global scope already excludes trashed rows —
        // this IS "every live row the partial unique index spans" for this
        // user. Ordered so the unaddressed tail below appends in the user's
        // CURRENT order; an unordered pluck would leave that at the planner's
        // discretion while the docblock promised otherwise.
        $legacyIds = Service::query()
            ->where('user_id', $userId)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($legacyIds === []) {
            return;
        }

        $legacyIdSet = array_flip($legacyIds);

        // legacy row id => target sort_order. A submitted id is either a
        // legacy row itself (Fresha) or a content item whose legacy
        // counterpart is still live (a backfilled owner service) — both must
        // land, or the two surfaces disagree about the shared scale.
        $targets = [];
        foreach ($order as $rank => $id) {
            $legacyId = isset($legacyIdSet[$id]) ? $id : $this->legacyIdForManualItem($userId, $id);
            if ($legacyId === null || isset($targets[$legacyId])) {
                continue;
            }
            $targets[$legacyId] = $rank;
        }

        // A live row this request never addressed at all — a legacy manual row
        // whose content item wasn't in $order (e.g. already superseded), or a
        // Fresha row the caller didn't mention — keeps its current relative
        // order, appended after every resolved id.
        $next = count($order);
        foreach ($legacyIds as $id) {
            if (! isset($targets[$id])) {
                $targets[$id] = $next++;
            }
        }

        // Two-pass parking-value renumber: the unique index is checked per
        // statement, not deferred, so a single pass can transiently collide
        // with a row that hasn't been updated yet but still holds the target.
        $offset = (int) Service::query()->where('user_id', $userId)->max('sort_order') + 1000;
        foreach ($targets as $id => $rank) {
            Service::query()->where('user_id', $userId)->where('id', $id)->update(['sort_order' => $offset + $rank]);
        }
        foreach ($targets as $id => $rank) {
            Service::query()->where('user_id', $userId)->where('id', $id)->update(['sort_order' => $rank]);
        }
    }

    /**
     * A manual (content.*) item's legacy `site.services` counterpart, if any is
     * still live. `ServiceBackfiller` coords are `manual:{legacy_uuid}` — the
     * legacy identifier survives the table's eventual drop (slice 7). A service
     * created through the cut-over endpoints mints a fresh random uuid coord
     * instead (`'manual:'.Str::uuid()`) and was never derived from a legacy row
     * — null, correctly, since there is nothing to renumber.
     */
    private function legacyIdForManualItem(string $userId, string $itemId): ?string
    {
        $coord = $this->writer->coordFor($userId, $itemId);
        if ($coord === null || ! str_starts_with($coord, 'manual:')) {
            return null;
        }

        $legacyId = substr($coord, strlen('manual:'));

        // Default Eloquent scope excludes soft-deleted rows — "still live" is
        // exactly what this needs (a trashed legacy row is outside the partial
        // unique index and needs no protecting).
        return Service::query()->where('id', $legacyId)->where('user_id', $userId)->exists() ? $legacyId : null;
    }
}
