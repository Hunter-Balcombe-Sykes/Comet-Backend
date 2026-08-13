<?php

namespace App\Services\Content;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slice 3b Task 8: the ONE read/write for `content.collections` rows of
 * kind='service_category' and their `content.collection_items` memberships.
 * Tasks 9 (HTTP layer), 10 (ManualServiceItems' category wiring) and 11
 * (the Fresha connector) all call this instead of touching either table
 * directly — a fourth hand-rolled copy of these predicates is exactly what
 * three of slice 3a's final-review blockers were.
 *
 * Every method is scoped by user_id (rule 5): a cross-tenant find() returns
 * null, and every write's WHERE clause carries user_id so a foreign id is a
 * silent no-op, never a cross-tenant mutation. This mirrors the 404-not-403
 * rule for resources that don't exist or don't belong to the caller — the
 * HTTP layer (Task 9) is expected to route the visible 404 through
 * ContentCollectionPolicy; this class just refuses to touch the row.
 *
 * Authorization is NOT re-implemented here: no abort_unless/403 anywhere in
 * this file. Policies live in app/Policies/ContentCollectionPolicy.php.
 *
 * Cache invalidation (BuildState::bump / site.sites.updated_at / edge purge)
 * is deliberately NOT wired into this class, matching ManualServiceWriter's
 * own raw-write-seam split: the writer's private mutators don't invalidate
 * either, only its explicit invalidate() does, called once per request by
 * the controller that knows which site(s) were touched. This class doesn't
 * receive a site id at all.
 */
class ServiceCollections
{
    private const KIND = 'service_category';

    private const CONNECTION = 'pgsql';

    /**
     * Rule 1: filters empty machine-derived collections — is_user_created=false
     * AND no live memberships. When a category vanishes from the vendor's
     * menu, ProjectionWriter replaces its memberships away (Task 5); that is
     * NOT a deletion, so the collection keeps no removed_at and must not
     * render as an empty group on the page. A user-created collection with
     * no items DOES stay visible — the owner made it deliberately, so an
     * empty "Add your first service here" group is the correct render, not
     * a bug to hide.
     *
     * @return Collection<int, \stdClass> id, label, position, external_ref, is_user_created, removed_at, item_count
     */
    public function list(string $userId, bool $includeRemoved = false): Collection
    {
        return $this->baseQuery($userId, $includeRemoved)
            ->get()
            ->reject(fn ($row) => ! $this->isUserCreated($row) && (int) $row->item_count === 0)
            ->values();
    }

    /** Single collection, scoped to its owner. A miss (wrong id, wrong owner, removed and not asked for) returns null, never another user's row. */
    public function find(string $userId, string $id, bool $includeRemoved = false): ?object
    {
        return $this->baseQuery($userId, $includeRemoved)
            ->where('c.id', $id)
            ->first();
    }

    /**
     * Rule 3: user-created collections carry is_user_created=true and
     * external_ref=null — external_ref is the machine natural key
     * (collections_user_kind_external_ref_uq), never set by a human create.
     * position appends after every existing collection for this user/kind
     * (including removed ones, so a later restore doesn't collide).
     */
    public function create(string $userId, string $label): string
    {
        $id = (string) Str::uuid();
        $now = now();

        $position = 1 + (int) (DB::connection(self::CONNECTION)->table('content.collections')
            ->where('user_id', $userId)
            ->where('kind', self::KIND)
            ->max('position') ?? -1);

        DB::connection(self::CONNECTION)->table('content.collections')->insert([
            'id' => $id,
            'user_id' => $userId,
            'parent_id' => null,
            'label' => $label,
            'kind' => self::KIND,
            'external_ref' => null,
            'removed_at' => null,
            'position' => $position,
            'is_user_created' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    public function rename(string $userId, string $id, string $label): void
    {
        DB::connection(self::CONNECTION)->table('content.collections')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('kind', self::KIND)
            ->update(['label' => $label, 'updated_at' => now()]);
    }

    /** @param  list<string>  $orderedIds  Foreign/other-user ids are silently skipped — the WHERE user_id clause matches nothing for them. */
    public function reposition(string $userId, array $orderedIds): void
    {
        DB::connection(self::CONNECTION)->transaction(function () use ($userId, $orderedIds) {
            foreach ($orderedIds as $index => $id) {
                DB::connection(self::CONNECTION)->table('content.collections')
                    ->where('id', $id)
                    ->where('user_id', $userId)
                    ->where('kind', self::KIND)
                    ->update(['position' => $index, 'updated_at' => now()]);
            }
        });
    }

    /**
     * Rule 2: remove()/restore() are the ONLY writers of removed_at. The
     * projection path (ProjectionWriter/Task 5) never touches it. Idempotent
     * — a row already removed is left with its original timestamp.
     */
    public function remove(string $userId, string $id): void
    {
        DB::connection(self::CONNECTION)->table('content.collections')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('kind', self::KIND)
            ->whereNull('removed_at')
            ->update(['removed_at' => now(), 'updated_at' => now()]);
    }

    /** Rule 2 continued — the only clearer of removed_at. */
    public function restore(string $userId, string $id): void
    {
        DB::connection(self::CONNECTION)->table('content.collections')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('kind', self::KIND)
            ->update(['removed_at' => null, 'updated_at' => now()]);
    }

    /**
     * Rule 4: replaces the item's memberships for THIS source
     * (source_id = ?, null for owner-authored) — matching Task 5's
     * replace-by-source semantics (ProjectionWriter::replaceCollections()'s
     * delete-then-insert pattern for the other content.* collection
     * tables). $collectionId=null clears the item's membership for this
     * source entirely (no replacement row).
     *
     * Ownership-guarded on both sides: the item must belong to $userId, and
     * a non-null $collectionId must be one of $userId's own
     * kind='service_category' collections — either mismatch is a silent
     * no-op, never a cross-tenant write.
     */
    public function assign(string $userId, string $itemId, ?string $collectionId, ?string $sourceId): void
    {
        $ownsItem = DB::connection(self::CONNECTION)->table('content.items')
            ->where('id', $itemId)
            ->where('user_id', $userId)
            ->exists();

        if (! $ownsItem) {
            return;
        }

        if ($collectionId !== null) {
            $ownsCollection = DB::connection(self::CONNECTION)->table('content.collections')
                ->where('id', $collectionId)
                ->where('user_id', $userId)
                ->where('kind', self::KIND)
                ->exists();

            if (! $ownsCollection) {
                return;
            }
        }

        DB::connection(self::CONNECTION)->transaction(function () use ($itemId, $collectionId, $sourceId) {
            $existing = DB::connection(self::CONNECTION)->table('content.collection_items')
                ->where('item_id', $itemId);
            $sourceId === null ? $existing->whereNull('source_id') : $existing->where('source_id', $sourceId);
            $existing->delete();

            if ($collectionId === null) {
                return;
            }

            $position = 1 + (int) (DB::connection(self::CONNECTION)->table('content.collection_items')
                ->where('collection_id', $collectionId)
                ->max('position') ?? -1);

            DB::connection(self::CONNECTION)->table('content.collection_items')->insert([
                'collection_id' => $collectionId,
                'item_id' => $itemId,
                'source_id' => $sourceId,
                'position' => $position,
            ]);
        });
    }

    /**
     * Shared shape for list()/find(): every column the Interfaces block
     * promises, plus a correlated item_count subquery (live memberships —
     * content.collection_items carries no removed_at of its own, a
     * membership either exists or it was already replaced away).
     */
    private function baseQuery(string $userId, bool $includeRemoved): Builder
    {
        $query = DB::connection(self::CONNECTION)->table('content.collections as c')
            ->where('c.user_id', $userId)
            ->where('c.kind', self::KIND);

        if (! $includeRemoved) {
            $query->whereNull('c.removed_at');
        }

        return $query
            ->select(['c.id', 'c.label', 'c.position', 'c.external_ref', 'c.is_user_created', 'c.removed_at'])
            ->selectSub(
                fn ($sub) => $sub->from('content.collection_items as ci')
                    ->selectRaw('count(*)')
                    ->whereColumn('ci.collection_id', 'c.id'),
                'item_count'
            )
            ->orderBy('c.position');
    }

    /**
     * PDO_PGSQL hands raw boolean columns back as the strings "t"/"f", not
     * PHP bool — a bare `(bool) $row->is_user_created` would treat "f" as
     * truthy and silently invert rule 1's filter on real Postgres while
     * looking correct against SQLite's 0/1 integers. Normalise explicitly
     * rather than trust either driver's native representation.
     */
    private function isUserCreated(object $row): bool
    {
        $value = $row->is_user_created;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return in_array(strtolower((string) $value), ['1', 't', 'true'], true);
    }
}
