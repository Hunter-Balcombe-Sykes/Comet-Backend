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
     * @return Collection<int, \stdClass> id, label, position, external_ref, is_user_created, removed_at, created_at, updated_at, item_count
     */
    public function list(string $userId, bool $includeRemoved = false): Collection
    {
        return $this->baseQuery($userId, $includeRemoved)
            ->get()
            ->map(fn ($row) => $this->normalizeRow($row))
            ->reject(fn ($row) => ! $row->is_user_created && $row->item_count === 0)
            ->values();
    }

    /** Single collection, scoped to its owner. A miss (wrong id, wrong owner, removed and not asked for) returns null, never another user's row. */
    public function find(string $userId, string $id, bool $includeRemoved = false): ?object
    {
        $row = $this->baseQuery($userId, $includeRemoved)
            ->where('c.id', $id)
            ->first();

        return $row === null ? null : $this->normalizeRow($row);
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

    /**
     * Fix round 1, Finding 2: `idx_collections_user` on (user_id, position)
     * is NOT unique, so numbering only the supplied ids from 0..n-1 and
     * leaving every omitted id at its stale position is a silent slot
     * collision — nothing in the DB rejects two categories claiming the
     * same position, it just renders in an arbitrary order.
     *
     * Contract chosen: a partial list is authoritative for the ids it
     * names, not a request to reject. The supplied ids take positions
     * 0..n-1 in the given order; every other kind='service_category'
     * collection this user owns (an id the caller never mentioned) is
     * appended afterward, in its own current relative order — so a caller
     * that reorders only the categories it actually changed doesn't
     * scramble the ones it left alone, and no two rows ever end up sharing
     * a position. Rejecting an incomplete list was the other option, but it
     * would force every caller (including a future drag-one-row UI) to
     * always resend the full set just to move one item, for no benefit —
     * this method already runs the whole update inside one transaction.
     *
     * $orderedIds entries that don't exist or don't belong to $userId's own
     * kind='service_category' collections are dropped before renumbering
     * anything — they consume no slot and cannot bump a real row out of
     * place (this is what makes "handle ids that don't exist or belong to
     * another user" true, not just the WHERE-clause-matches-nothing
     * no-op the single-id write methods rely on).
     *
     * @param  list<string>  $orderedIds
     */
    public function reposition(string $userId, array $orderedIds): void
    {
        DB::connection(self::CONNECTION)->transaction(function () use ($userId, $orderedIds) {
            $owned = DB::connection(self::CONNECTION)->table('content.collections')
                ->where('user_id', $userId)
                ->where('kind', self::KIND)
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();
            $ownedSet = array_flip($owned);

            $supplied = [];
            foreach ($orderedIds as $id) {
                $id = (string) $id;
                if (isset($ownedSet[$id]) && ! in_array($id, $supplied, true)) {
                    $supplied[] = $id;
                }
            }

            $now = now();
            foreach ($supplied as $index => $id) {
                DB::connection(self::CONNECTION)->table('content.collections')
                    ->where('id', $id)
                    ->where('user_id', $userId)
                    ->where('kind', self::KIND)
                    ->update(['position' => $index, 'updated_at' => $now]);
            }

            $next = count($supplied);
            $omitted = DB::connection(self::CONNECTION)->table('content.collections')
                ->where('user_id', $userId)
                ->where('kind', self::KIND)
                ->whereNotIn('id', $supplied)
                ->orderBy('position')
                ->pluck('id');

            foreach ($omitted as $id) {
                DB::connection(self::CONNECTION)->table('content.collections')
                    ->where('id', $id)
                    ->update(['position' => $next, 'updated_at' => $now]);
                $next++;
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
     *
     * C2 (final review): "replaces the item's memberships" means the ones
     * THIS class owns — kind='service_category'. The delete is scoped by
     * kind as well as by source, because content.collection_items is a
     * SHARED table: slices 5a/5b file products into kind='storefront'
     * collections with source_id NULL, which is byte-identical to the
     * owner-authored service lane on the two columns the delete would
     * otherwise match on. Nothing crosses today (a service item id is never
     * a product item id), so this is not a live bug — it is the difference
     * between "safe" and "safe as long as no caller ever mis-resolves an
     * id", and one mis-resolved id would silently unfile a product from its
     * storefront on a surface this class has no business touching.
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
                ->where('item_id', $itemId)
                // Scoped to this class's own kind — see the docblock. A join
                // is not usable on a DELETE across drivers, so the membership
                // is narrowed by an EXISTS against its parent collection.
                ->whereExists(fn ($query) => $query->selectRaw('1')
                    ->from('content.collections as col')
                    ->whereColumn('col.id', 'content.collection_items.collection_id')
                    ->where('col.kind', self::KIND));
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
     * promises, plus a correlated item_count subquery.
     *
     * Fix round 1, Finding 3: item_count now joins content.items and
     * excludes removed_at rows. Without this, a machine-derived category
     * whose every member had since been individually removed (owner
     * deletion, or ManualServiceWriter::markRemoved()) still counted as
     * non-empty — content.collection_items itself carries no removed_at of
     * its own (a membership either exists or Task 5's replace-by-source
     * already deleted it), so "still has membership rows" and "still has
     * LIVE members" are different questions, and rule 1 needs the second
     * one. This is the same emptiness rule as the "vendor dropped the
     * category" case, just reached via item-level removal instead of
     * category-level replace.
     *
     * Task 9 fix round 1, Finding 1: created_at/updated_at are selected too.
     * They are not used by anything in this class — they are here because the
     * HTTP layer's wire shape (ServiceCategoryResource) has always carried
     * both, and a row that omits them serialises two silent nulls rather than
     * a missing key, which is the same invisible-regression shape the `source`
     * mapping was caught by. Removing either column from this SELECT breaks
     * the wire without breaking a query: pinned by
     * tests/Feature/Api/User/ServiceCategoryEndpointCutoverTest.php's
     * "emits real created_at/updated_at timestamps, not two silent nulls".
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
            ->select(['c.id', 'c.label', 'c.position', 'c.external_ref', 'c.is_user_created', 'c.removed_at', 'c.created_at', 'c.updated_at'])
            ->selectSub(
                fn ($sub) => $sub->from('content.collection_items as ci')
                    ->join('content.items as it', 'it.id', '=', 'ci.item_id')
                    ->selectRaw('count(*)')
                    ->whereColumn('ci.collection_id', 'c.id')
                    ->whereNull('it.removed_at'),
                'item_count'
            )
            ->orderBy('c.position');
    }

    /**
     * Fix round 1, Finding 1: normalises every driver-dependent column on
     * the way OUT, for every method that surfaces a row — not just as a
     * private detail of list()'s filter decision. The original version
     * normalised `is_user_created` only for its own internal `reject()`
     * check and handed callers the raw, still driver-dependent value on the
     * row itself: PDO_PGSQL returns a boolean column as the strings "t"/"f",
     * not PHP bool, so a caller (Task 9's wire mapping is written against
     * `is_user_created === false`) would silently misclassify every
     * Fresha-derived category as user-created on real Postgres, invisibly
     * to the SQLite test lane. Fixing the read in one place — inside this
     * class, not at each call site — is the entire reason ServiceCollections
     * exists as "the one" read/write.
     *
     * `item_count` gets the same treatment: Postgres's COUNT(*) is a
     * bigint, which PDO_PGSQL also hands back as a numeric string, not
     * PHP int — silently correct under loose `==` comparisons but wrong
     * under `===` or arithmetic that assumes int. `position` is cast for
     * the same reason (a plain `integer` column, same PDO string-return
     * behaviour) even though nothing in this class's own tests currently
     * depends on its type — a caller doing `$row->position === 0` would hit
     * the identical class of bug this whole fix round is about.
     * `id`/`label`/`external_ref`/`removed_at` are left alone: they're text
     * or null either way, with no PHP-type distinction a driver could get
     * wrong.
     *
     * Task 9 fix round 2: typed \stdClass, not `object`. The query builder
     * already hands back \stdClass rows, and `object` here was WIDENING them
     * — it made list()'s `->map()` produce a Collection<int, object>, which
     * contradicts the declared Collection<int, \stdClass> and was the one
     * PHPStan error in this file. Widening list()'s own return type instead
     * would have "fixed" it by throwing away the type Tasks 10 and 11 consume
     * these rows under; the narrowing is the actual cause. Do not widen back.
     */
    private function normalizeRow(\stdClass $row): \stdClass
    {
        $row->is_user_created = $this->isUserCreated($row);
        $row->item_count = (int) $row->item_count;
        $row->position = (int) $row->position;

        return $row;
    }

    /** Reads the RAW is_user_created value off the row — called by normalizeRow() before that field is overwritten with the normalised bool. */
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
