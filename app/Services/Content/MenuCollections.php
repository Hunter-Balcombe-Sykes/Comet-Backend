<?php

namespace App\Services\Content;

use App\Services\Platforms\MenuProjectionMapper;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slice 7 Task 6: the ONE read/write for `content.collections` rows of
 * kind='menu_category' and their `content.collection_items` memberships —
 * `site.menu_categories` after the teardown.
 *
 * A sibling of {@see ServiceCollections} rather than a generalisation of it,
 * for two reasons that are structural, not stylistic:
 *
 *  1. **Membership arity.** A service belongs to at most ONE category
 *     (`ServiceCollections::assign()` replaces the item's membership for a
 *     source); a dish belongs to many. Menus therefore never call an assign()
 *     at all — memberships are written by `ProjectionWriter::replaceCollections()`
 *     from the projection's own `collections` array, so the whole membership
 *     set travels with the dish's `write()`. Sharing assign() would have meant
 *     sharing a method neither lane could use unchanged.
 *  2. **`external_ref` is mandatory here.** A service category minted by hand
 *     carries `external_ref = null` (ServiceCollections rule 3). A menu
 *     category cannot: `MenuProjectionMapper::categoryRef($label)` is the
 *     natural key BOTH the scrape (Task 7) and the scan (Task 8) upsert on, so
 *     an owner-created "Desserts" holding a null ref would be joined by a
 *     SECOND "Desserts" the first time a vendor lists one. Every row this
 *     class writes carries the label-derived ref, and `is_user_created` — not
 *     the ref's presence — is what marks ownership.
 *
 * Ownership marker (Task 6 decision 3): `is_user_created = true` IS the
 * content-lane home of `site.menu_categories.source_platform`'s owner-side
 * values ('manual' / 'scan' / 'website-scan'); `false` is the scraper-owned
 * half ('uber-eats' / 'doordash' / NULL). `content.collections` carries no
 * source column and a migration is out of scope for this phase, so the three
 * owner-side strings collapse to one bit — which is exactly the question every
 * reader asked of them (`EDITABLE_SOURCES`, `rebuildableCategoryIds()`,
 * `hasOwnerContent()`). `ProjectionWriter::upsertCollections()` never writes
 * `is_user_created` on the UPDATE arm, so a scrape re-listing an owner's
 * category cannot take it back.
 *
 * Every method is scoped by user_id: a cross-tenant id is a silent no-op, never
 * a cross-tenant mutation. Authorization is NOT re-implemented here, and cache
 * invalidation is deliberately absent — the controller owns both, exactly as
 * ServiceCollections' docblock explains for its own half.
 */
// RECONCILED 2026-08-17. Tasks 6 and 8 landed two ref conventions. Task 8
// namespaces SCAN categories (`menu:scan:x`, `menu:website-scan:x`) so a scan
// can never reuse a scraped category — that separation is kept, and it is
// structural because Str::slug() emits [a-z0-9-] only, so a colon form is
// unreachable from any vendor label.
//
// MANUAL categories deliberately do NOT get a namespace: they share the bare
// `menu:<slug>` key with the scraped row of the same label, which is what lets
// an owner ADOPT a scraped category (ensure(..., ownerCreated: true) flips
// is_user_created on the existing row) and thereby edit or delete it at all.
// Namespacing manual too was tried and reverted — it splits the adopted row in
// two, and deleteCategory() then suppresses nothing.
//
// The residual hazard, recorded rather than fixed: is_user_created is written
// by upsertCollections()'s INSERT arm only, so if a SCRAPE lands a label before
// the owner ever adopts it, the flag stays false and the category is
// uneditable under EDITABLE_SOURCES until adopted. Adoption is the escape
// hatch, which is why it must keep working.
class MenuCollections
{
    public const KIND = 'menu_category';

    private const CONNECTION = 'pgsql';

    /**
     * The owner's live menu categories, in the read order
     * `ManualMenuItems::categories()` uses (position seed, label tie-break).
     *
     * @return Collection<int, \stdClass> id, label, position, external_ref, is_user_created
     */
    public function list(string $userId): Collection
    {
        return $this->baseQuery($userId)
            ->whereNull('removed_at')
            ->orderBy('position')
            ->orderBy('label')
            ->get()
            ->map(fn (\stdClass $row) => $this->normalizeRow($row));
    }

    /** A single LIVE category, scoped to its owner. Null for a miss, a foreign row, or a removed one. */
    public function find(string $userId, string $id): ?\stdClass
    {
        $row = $this->baseQuery($userId)->whereNull('removed_at')->where('id', $id)->first();

        return $row === null ? null : $this->normalizeRow($row);
    }

    /**
     * The row holding a label's natural key, INCLUDING a removed one — the
     * `collections_user_kind_external_ref_uq` index is not partial, so a
     * soft-removed category still squats its ref and a blind insert would 23505.
     */
    public function findByLabel(string $userId, string $label): ?\stdClass
    {
        $row = $this->baseQuery($userId)
            ->where('external_ref', MenuProjectionMapper::categoryRef($label))
            ->first();

        return $row === null ? null : $this->normalizeRow($row);
    }

    /**
     * Create the category, or RESTORE the removed row already holding its ref.
     *
     * The restore arm is what keeps "delete Specials, then add Specials again"
     * working: `removed_at` is one-way for the projection (a scrape re-listing a
     * category never resurrects it) but an owner re-creating it by hand is
     * explicit consent, the same distinction `ManualPoolWriter::clearRemoved()`
     * draws for items. It comes back EMPTY — remove() deletes the memberships —
     * which is what the legacy detach-then-delete did too.
     */
    public function ensure(string $userId, string $label, bool $ownerCreated = true): string
    {
        $ref = MenuProjectionMapper::categoryRef($label);
        $existing = $this->baseQuery($userId)->where('external_ref', $ref)->first();
        $now = now();

        if ($existing !== null) {
            DB::connection(self::CONNECTION)->table('content.collections')
                ->where('id', $existing->id)
                ->update([
                    'label' => $label,
                    'removed_at' => null,
                    'is_user_created' => $ownerCreated,
                    'updated_at' => $now,
                ]);

            return (string) $existing->id;
        }

        $id = (string) Str::uuid();
        DB::connection(self::CONNECTION)->table('content.collections')->insert([
            'id' => $id,
            'user_id' => $userId,
            'parent_id' => null,
            'label' => $label,
            'kind' => self::KIND,
            'external_ref' => $ref,
            'removed_at' => null,
            // Appends past every row for this user/kind, removed ones included,
            // so a later restore cannot collide with a live position.
            'position' => 1 + (int) (DB::connection(self::CONNECTION)->table('content.collections')
                ->where('user_id', $userId)->where('kind', self::KIND)->max('position') ?? -1),
            'is_user_created' => $ownerCreated,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * Rename, re-deriving the natural key from the new label.
     *
     * The ref MUST move with the label: it is `Str::slug($label)`, and leaving a
     * stale one behind would let the next scrape's "Mains" upsert miss the row
     * the owner renamed to "Mains" and mint a duplicate. The mirror cost —
     * a scrape still emitting the OLD label mints a fresh category — is the
     * behaviour the legacy lane already had (MenuFetchJob reuses category ids by
     * NORMALISED NAME, so a rename churned the id there too).
     *
     * Callers must reject a ref that another row already holds; this method
     * would otherwise raise 23505 rather than a 422.
     */
    public function rename(string $userId, string $id, string $label): void
    {
        DB::connection(self::CONNECTION)->table('content.collections')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('kind', self::KIND)
            ->update([
                'label' => $label,
                'external_ref' => MenuProjectionMapper::categoryRef($label),
                'updated_at' => now(),
            ]);
    }

    /**
     * Soft-remove the category AND drop its memberships.
     *
     * Both halves, in one transaction: `removed_at` is what stops a re-listing
     * scrape resurrecting the category, and the membership delete is the legacy
     * `detach()` — a dish must stop reporting a category the owner deleted even
     * while the collection row lingers as a tombstone. Deleting the memberships
     * also means the tombstone can be restored later without silently re-filing
     * dishes that were moved on in the meantime.
     */
    public function remove(string $userId, string $id): void
    {
        $owned = $this->baseQuery($userId)->where('id', $id)->exists();
        if (! $owned) {
            return;
        }

        DB::connection(self::CONNECTION)->transaction(function () use ($userId, $id) {
            DB::connection(self::CONNECTION)->table('content.collection_items')
                ->where('collection_id', $id)->delete();

            DB::connection(self::CONNECTION)->table('content.collections')
                ->where('id', $id)
                ->where('user_id', $userId)
                ->where('kind', self::KIND)
                ->whereNull('removed_at')
                ->update(['removed_at' => now(), 'updated_at' => now()]);
        });
    }

    /**
     * The supplied ids take positions 0..n-1; every other menu category this
     * user owns trails in its own current relative order. Same contract, and
     * the same reasoning, as `ServiceCollections::reposition()` — `position` is
     * not unique, so renumbering only the named ids would silently collide.
     *
     * @param  list<string>  $orderedIds
     */
    public function reposition(string $userId, array $orderedIds): void
    {
        DB::connection(self::CONNECTION)->transaction(function () use ($userId, $orderedIds) {
            $owned = array_flip($this->baseQuery($userId)->pluck('id')->map(fn ($id) => (string) $id)->all());

            $supplied = [];
            foreach ($orderedIds as $id) {
                $id = (string) $id;
                if (isset($owned[$id]) && ! in_array($id, $supplied, true)) {
                    $supplied[] = $id;
                }
            }

            $now = now();
            $next = 0;
            foreach ($supplied as $id) {
                DB::connection(self::CONNECTION)->table('content.collections')
                    ->where('id', $id)->where('user_id', $userId)->where('kind', self::KIND)
                    ->update(['position' => $next++, 'updated_at' => $now]);
            }

            $omitted = $this->baseQuery($userId)
                ->whereNotIn('id', $supplied)
                ->orderBy('position')
                ->orderBy('label')
                ->pluck('id');

            foreach ($omitted as $id) {
                DB::connection(self::CONNECTION)->table('content.collections')
                    ->where('id', $id)->where('user_id', $userId)->where('kind', self::KIND)
                    ->update(['position' => $next++, 'updated_at' => $now]);
            }
        });
    }

    /**
     * The LIVE items filed under a category. Ordered by nothing meaningful —
     * `collection_items.position` is ProjectionWriter's per-item counter, not a
     * display rank (see ManualMenuItems::positionsBy) — so callers that need the
     * owner's arrangement read the `pool:menus` pins instead.
     *
     * @return list<string>
     */
    public function memberIds(string $userId, string $collectionId): array
    {
        return DB::connection(self::CONNECTION)->table('content.collection_items as ci')
            ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
            ->join('content.items as i', 'i.id', '=', 'ci.item_id')
            ->where('c.id', $collectionId)
            ->where('c.user_id', $userId)
            ->where('c.kind', self::KIND)
            ->whereNull('i.removed_at')
            ->distinct()
            ->pluck('ci.item_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * How many LIVE menu categories each of these items still belongs to —
     * the orphan test a category delete runs ("a dish always belongs to ≥1
     * category; one left with none goes with it").
     *
     * @param  list<string>  $itemIds
     * @return array<string, int>
     */
    public function liveCategoryCounts(string $userId, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::connection(self::CONNECTION)->table('content.collection_items as ci')
            ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
            ->whereIn('ci.item_id', $itemIds)
            ->where('c.user_id', $userId)
            ->where('c.kind', self::KIND)
            ->whereNull('c.removed_at')
            ->groupBy('ci.item_id')
            ->selectRaw('ci.item_id, count(*) as membership_count')
            ->pluck('membership_count', 'item_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** @return Builder */
    private function baseQuery(string $userId)
    {
        return DB::connection(self::CONNECTION)->table('content.collections')
            ->where('user_id', $userId)
            ->where('kind', self::KIND)
            ->select(['id', 'label', 'position', 'external_ref', 'is_user_created', 'removed_at']);
    }

    /**
     * Normalises every driver-dependent column on the way out, for the same
     * reason ServiceCollections does: PDO_PGSQL hands a boolean back as 't'/'f'
     * and an integer as a numeric string, so `is_user_created === false` — the
     * exact comparison the editable-category gate makes — would misread every
     * scraper-owned category as owner-owned on real Postgres, invisibly to the
     * SQLite lane.
     */
    private function normalizeRow(\stdClass $row): \stdClass
    {
        $value = $row->is_user_created;
        $row->is_user_created = is_bool($value)
            ? $value
            : (is_int($value) ? $value === 1 : in_array(strtolower((string) $value), ['1', 't', 'true'], true));
        $row->position = (int) $row->position;

        return $row;
    }
}
