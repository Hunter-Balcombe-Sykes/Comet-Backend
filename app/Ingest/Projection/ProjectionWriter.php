<?php

namespace App\Ingest\Projection;

use App\Content\Identity\Candidate;
use App\Content\Identity\Decision;
use App\Content\Identity\IdentityKey;
use App\Content\Identity\KeyClass;
use App\Content\Identity\Resolver;
use App\Content\Identity\SourceItem;
use App\Content\Values\Contribution;
use App\Content\Values\ValueResolver;
use App\Jobs\Media\MirrorMediaAssetJob;
use App\Routing\SecretParams;
use App\Services\Content\ContentItemSlugAllocator;
use App\Services\Media\MediaMirror;
use App\Site\Documents\BuildState;
use App\Site\Documents\SiteCacheLanes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The projection stage's ONE writer (plan §4 Landing → Projection, §5/§6
 * storage): turns a stream's current landed records into content rows —
 * source_items, identity keys, items (via the pure Resolver), and typed
 * facets — idempotently. Re-running over unchanged records writes the same
 * rows again and creates nothing new; that property is what makes
 * `ingest:project --rebuild` safe to run at any time.
 *
 * Identity is COMPUTED here, never accumulated (C7): every pass re-runs the
 * pure Resolver over the user's live source-items for the kind, then binds
 * groups to stable item ids through item_anchors — oldest binding wins, a
 * losing item's anchors are redirected via superseded_by, and reappearance
 * of a user-removed item folds back into the removed item rather than
 * resurrecting as a fresh visible one (C8).
 */
class ProjectionWriter
{
    /** @var array<string, list<string>> singleton facet => writable columns */
    private const SINGLETON_FACETS = [
        'f_text' => ['headline', 'body', 'summary'],
        'f_link' => ['url', 'canonical_url'],
        'f_duration' => ['seconds'],
        'f_published' => ['published_from', 'published_to', 'verbatim', 'precision'],
        'f_occurrence' => ['starts_at_local', 'ends_at_local', 'timezone', 'zone_confidence', 'starts_at_utc', 'is_all_day'],
        'f_embed' => ['provider', 'embed_key', 'variant'],
        'f_playable' => ['stream_url', 'preview_url', 'is_explicit'],
        'f_authored' => ['creator', 'creator_url', 'collaborators'],
        // handle/vendor/variant_ref: slice 5a Task 7 fix round 1, Finding 3
        // (migration 20260813100002) — shop-specific product fields, on the
        // generic catalogue-identity facet like sku/gtin already were.
        'f_catalog' => ['release_type', 'track_number', 'disc_number', 'isrc', 'gtin', 'sku', 'handle', 'vendor', 'variant_ref'],
        'f_place' => ['venue_name', 'address', 'locality', 'region', 'country_code', 'latitude', 'longitude'],
        'f_rated' => ['rating', 'rating_max', 'ratings_count'],
        // author_uri: slice 6 Task 1 (migration 20260813110000). upsertSingletonFacet
        // filters against this list, so an unlisted column is dropped silently —
        // no error, no row.
        'f_review' => ['author_name', 'author_photo_url', 'author_uri', 'rating', 'text', 'reviewed_at'],
        'f_channel' => ['handle', 'followers', 'avatar_url', 'is_live', 'verified'],
        'f_file' => ['file_url', 'mime_type', 'size_bytes', 'page_count'],
    ];

    /**
     * #PRIV-5: columns whose value is a URL and must be minimised
     * (SecretParams::minimiseUrl()) before it is persisted. Facet-qualified
     * (not bare column names) because `url` means different things on
     * different facets. Anything not listed here is written verbatim — a
     * denylist of URL-bearing columns, deliberately, so a new non-URL column
     * cannot be silently mangled by it.
     */
    private const URL_COLUMNS = [
        'f_link' => ['url', 'canonical_url'],
        'f_playable' => ['stream_url', 'preview_url'],
        'f_authored' => ['creator_url'],
        'f_file' => ['file_url'],
        'f_review' => ['author_photo_url'],
        'f_channel' => ['avatar_url'],
    ];

    /**
     * The owner's own channel outranks every connection. ValueResolver sorts
     * source contributions by priority DESC for f_text.headline and
     * f_link.url, so this constant is what makes "the user outranks the
     * machine" (C8) a data fact rather than a branch in code — exactly as
     * content.sources' own DDL comment claims. Connections sit at 100.
     */
    public const MANUAL_SOURCE_PRIORITY = 200;

    public function __construct(
        private readonly Resolver $resolver,
        private readonly ValueResolver $values,
        private readonly ContentItemSlugAllocator $slugs,
    ) {}

    /**
     * Project one stream's current records end to end.
     *
     * @param  array<string, mixed>  $source  row from ingest.sources
     * @return array{status: string, projected?: int, removed?: int, items?: int, reason?: string}
     */
    public function projectStream(array $source, string $streamId, string $streamName): array
    {
        $sourceKey = (string) $source['source_key'];
        $projector = ProjectorRegistry::for($sourceKey, $streamName);
        if ($projector === null) {
            return ['status' => 'skipped', 'reason' => 'no_projector'];
        }

        $connectionId = $source['connection_id'] ?? null;
        if ($connectionId === null) {
            return ['status' => 'skipped', 'reason' => 'no_connection'];
        }

        $userId = (string) $source['user_id'];
        $contentSourceId = $this->ensureContentSource($userId, (string) $connectionId, $sourceKey);
        $accountRef = $this->accountRef((string) $connectionId, (string) $source['identifier']);

        // SCALE-6: ->cursor() does not bound memory under pdo_pgsql (libpq
        // buffers the whole result set client-side regardless of PHP-level
        // iteration) — lazy(500) pages with real LIMIT/OFFSET round-trips.
        // I1 hazard: orderBy('rv.first_seen_at') ALONE is not a unique sort
        // key — several records in one run can share a timestamp, and
        // LIMIT/OFFSET paging over a non-unique order silently skips and
        // duplicates rows. The rs.key tiebreak is mandatory, not cosmetic,
        // and must not be reordered ahead of first_seen_at: records are
        // processed oldest-first so upsertSingletonFacet()'s upsert-on-
        // (item_id, source_id) lets the LAST-processed record win each
        // column (I1) — reordering the read silently changes which record's
        // headline/facets a page shows.
        $records = DB::table('ingest.record_state as rs')
            ->join('ingest.record_versions as rv', function ($join) {
                $join->on('rv.stream_id', '=', 'rs.stream_id')->on('rv.id', '=', 'rs.current_version_id');
            })
            ->where('rs.stream_id', $streamId)
            ->whereNull('rs.tombstoned_at')
            ->select(['rs.key', 'rv.doc', 'rv.first_seen_at'])
            ->orderBy('rv.first_seen_at')
            ->orderBy('rs.key')
            ->lazy(500);

        $projections = [];
        $projectsToNothing = [];
        $sourceStats = null;

        foreach ($records as $record) {
            $doc = is_string($record->doc) ? (array) json_decode($record->doc, true) : (array) $record->doc;
            $projection = $projector->project(new RecordView($doc, (string) $record->key));

            if ($projection === null) {
                $projectsToNothing[] = (string) $record->key;

                continue;
            }

            if (isset($projection['source_stats']) && is_array($projection['source_stats'])) {
                $sourceStats = $projection['source_stats'];
            }

            $coord = "{$sourceKey}:{$accountRef}:{$record->key}";

            // ONE transaction per record, spanning the source-item upsert AND
            // its identity-key replace-set (#CACHE-3 brief, 2026-07-31).
            //
            // resolveItems() below reads content.identity_keys scoped by
            // user_id + kind, NOT stream_id — that cross-source union is the
            // whole point of the identity system, and it is what makes this
            // window reachable: projecting a SECOND stream, or a concurrent
            // `ingest:project` (which bypasses SourceScheduler's claim
            // entirely), reads these very rows while this loop rewrites them.
            //
            // Unwrapped there are TWO windows in which a live source item is
            // visible carrying ZERO identity keys: between the DELETE and the
            // INSERT in writeIdentityKeys(), and — for a first-sight record —
            // between the content.source_items INSERT and the first key. The
            // second is the damaging one, and wrapping only writeIdentityKeys()
            // would leave it open: a keyless new item resolves as an unrelated
            // singleton, so createItem() mints a spurious content.items row and
            // anchors the coord to it, which the next pass then has to merge
            // away. The user sees a duplicate in the meantime — and if they
            // curate the loser, mergeInto()'s curation check keeps it forever.
            //
            // Per RECORD, not per loop, for the same reason replaceCollections()
            // wraps per CHUNK: bounded lock duration. lazy(500) pages with
            // discrete LIMIT/OFFSET queries (BuildsQueries::lazy), not a
            // server-side cursor, so a transaction opened and committed inside
            // the body closes before the next page is fetched — but wrapping
            // the whole loop would pull every page query inside it and pin an
            // old xmin for the length of the stream.
            //
            // $projector->project() stays OUTSIDE (line 126): nothing in here
            // may be long-running or non-transactional. Two concurrent
            // `ingest:project` runs on one stream could in principle deadlock
            // ($attempts defaults to 1), but each transaction locks a single
            // source item and its keys in a fixed order, so a cycle needs two
            // writers on the SAME row — accepted, not retried.
            $sourceItemId = DB::transaction(function () use ($contentSourceId, $coord, $streamId, $record, $projection, $projector) {
                $id = $this->upsertSourceItem(
                    contentSourceId: $contentSourceId,
                    coord: $coord,
                    streamId: $streamId,
                    recordKey: (string) $record->key,
                    kind: (string) $projection['kind'],
                    projectorVersion: $projector::version(),
                );
                $this->writeIdentityKeys($id, $coord, $projection);

                return $id;
            });

            $projections[$coord] = $projection;
        }

        // Slice 6 §5.2: source-level aggregates. Last record wins — they are
        // identical across a run, mirroring upsertSingletonFacet's
        // last-processed-record-wins column semantics.
        if ($sourceStats !== null) {
            DB::table('content.source_stats')->upsert(
                [$sourceStats + ['source_id' => $contentSourceId, 'updated_at' => now()]],
                ['source_id'],
                array_merge(array_keys($sourceStats), ['updated_at']),
            );
        }

        $removed = $this->retireAbsentSourceItems($contentSourceId, $streamId, $projectsToNothing);

        $itemByCoord = [];
        if ($projections !== []) {
            $itemByCoord = $this->resolveItems($userId, $projector::kind());
            $this->writeFacets($contentSourceId, $userId, $projections, $itemByCoord);
            $this->refreshItemCaches($userId, array_values(array_unique(array_values($itemByCoord))));
        }

        if ($projections !== [] || $removed > 0) {
            // All THREE lanes here, not just build state (parent spec §4). A
            // connector run lands items into content.*, and buildPools() renders
            // every pool in PoolRegistry::POOLS with no source-kind filter — so a
            // scheduled YouTube, Instagram, Google Business, Eventbrite or Gumroad
            // run changes payload.data.pools.* on the public profile. Bumping only
            // build state leaves the ORIGIN serving its previous payload for the
            // full 60s TTL (the key derives from site.sites.updated_at) and leaves
            // the edge copy alone entirely. Precedent, same surface, same missing
            // lane: 6ab3028e8.
            $this->invalidateSiteLanes($userId);
        }

        return [
            'status' => 'ok',
            'projected' => count($projections),
            'removed' => $removed,
            'items' => count(array_unique(array_values($itemByCoord))),
        ];
    }

    /**
     * The connection's contribution channel (plan §5: content.sources is the
     * CHANNEL grain — one row per connection, whatever it streams).
     */
    private function ensureContentSource(string $userId, string $connectionId, string $label): string
    {
        $existing = DB::table('content.sources')->where('connection_id', $connectionId)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid();
        DB::table('content.sources')->insert([
            'id' => $id,
            'user_id' => $userId,
            'kind' => 'connection',
            'connection_id' => $connectionId,
            'label' => $label,
            'priority' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * The owner's contribution channel — one per user, find-or-create.
     *
     * Deliberately NOT folded into ensureContentSource(): that method is
     * keyed on connection_id, and a manual source has none. The uniqueness
     * that matters here is idx_content_sources_manual, a PARTIAL unique index
     * on (user_id) WHERE kind = 'manual'.
     */
    public function ensureManualSource(string $userId): string
    {
        $existing = DB::table('content.sources')
            ->where('user_id', $userId)
            ->where('kind', 'manual')
            ->first(['id', 'priority']);

        if ($existing !== null) {
            // The writer this method replaces created manual sources at 100,
            // the same as a connection. Find-or-create alone would carry that
            // forward forever and the C8 guarantee would quietly not hold for
            // anyone who had already hand-added.
            if ((int) $existing->priority !== self::MANUAL_SOURCE_PRIORITY) {
                DB::table('content.sources')->where('id', $existing->id)->update([
                    'priority' => self::MANUAL_SOURCE_PRIORITY,
                    'updated_at' => now(),
                ]);
            }

            return (string) $existing->id;
        }

        DB::table('content.sources')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'kind' => 'manual',
            'connection_id' => null,
            'label' => 'manual',
            'priority' => self::MANUAL_SOURCE_PRIORITY,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Re-read rather than assume our row landed — same reasoning as
        // resolveMediaAssets(): insertOrIgnore returns no id, and a concurrent
        // caller may have won the partial-unique race.
        $id = DB::table('content.sources')
            ->where('user_id', $userId)
            ->where('kind', 'manual')
            ->value('id');

        if ($id === null) {
            // Loud rather than a (string) cast of null to '', which would go
            // on to become the source_id on every facet row this call writes.
            throw new \RuntimeException("Could not resolve a manual content source for user {$userId}.");
        }

        return (string) $id;
    }

    /**
     * Land ONE owner-authored item through the same spine a connector record
     * travels: source item, identity keys, resolved item, typed facets.
     *
     * The alternative — each backfiller and each hand-add writing content.*
     * directly — was measured and rejected in the slice 0b design: it
     * duplicates this class's semantics per call site, and any divergence
     * produces items the identity resolver treats inconsistently. The live
     * proof is the hand-rolled writer this method replaces, which skipped
     * identity_keys and item_anchors and so had every hand-added item
     * detached from its own source row by the next connector run.
     *
     * The returned id is the RESOLVED item, not necessarily a new one: a
     * hand-typed URL that matches a synced item folds into it, which is the
     * convergence the content schema exists for.
     *
     * CALLER CONSTRAINT: at most ONE coord per canonical URL per user.
     * Resolver::poisonedKeys() poisons a key value that a SINGLE source
     * contributes twice, and there is exactly one manual source per user — so
     * two manual coords carrying the same URL do not merge, they disable that
     * URL as a joining key for the whole run, taking any connector item
     * carrying it down with them.
     *
     * MUST NOT be called inside a transaction — replaceCollections() documents
     * its own as the outermost one.
     *
     * @param  string  $coord  stable and caller-owned: 'manual:{sha1(canonical url)}'
     *                         for a hand-add, 'manual:{legacy_uuid}' for a backfill
     *                         (spec §8.1), so a re-run updates rather than
     *                         duplicates and the legacy identifier survives its
     *                         table being dropped
     * @param  array<string, mixed>  $projection  the shape Projector::project() returns
     */
    public function writeManualItem(string $userId, string $coord, array $projection): string
    {
        $contentSourceId = $this->ensureManualSource($userId);
        $kind = (string) $projection['kind'];

        // Same one-transaction-per-record boundary the connector path uses,
        // and for the same reason: a committed source item visible with zero
        // identity keys resolves as an unrelated singleton and mints a
        // spurious item. See the long note at the projectStream() call site.
        DB::transaction(function () use ($contentSourceId, $coord, $kind, $projection) {
            $sourceItemId = $this->upsertSourceItem(
                contentSourceId: $contentSourceId,
                coord: $coord,
                streamId: null,
                recordKey: null,
                kind: $kind,
                // 0 = no projector governs this row. Nothing branches on the
                // value (its only reader is the DSAR export), and a real
                // version number would imply a rebuild could re-derive it.
                projectorVersion: 0,
            );
            $this->writeIdentityKeys($sourceItemId, $coord, $projection);
        });

        $itemByCoord = $this->resolveItems($userId, $kind);

        if (! isset($itemByCoord[$coord])) {
            // Unreachable: the row was just written live and resolveItems()
            // reads every live source item for (user, kind). Loud rather than
            // a null return, because a silent miss here would hand a caller
            // an id for the wrong item.
            throw new \RuntimeException("Manual coord {$coord} did not resolve to an item.");
        }

        $this->writeFacets($contentSourceId, $userId, [$coord => $projection], $itemByCoord);
        $this->refreshItemCaches($userId, array_values(array_unique(array_values($itemByCoord))));
        $this->bumpSite($userId);

        return $itemByCoord[$coord];
    }

    /**
     * The reconnect-stable half of a coord. Connections minted by the newer
     * flows already carry the deterministic sha16 in resource_id; legacy rows
     * derive the same shape from the identifier, which survives reconnect for
     * the same remote account by construction.
     */
    private function accountRef(string $connectionId, string $identifier): string
    {
        $resourceId = DB::table('site.platform_connections')->where('id', $connectionId)->value('resource_id');

        if (is_string($resourceId) && preg_match('/^acct-[0-9a-f]{16}$/', $resourceId)) {
            return $resourceId;
        }

        return 'acct-'.substr(sha1(strtolower(trim($identifier))), 0, 16);
    }

    private function upsertSourceItem(
        string $contentSourceId,
        string $coord,
        ?string $streamId,
        ?string $recordKey,
        string $kind,
        int $projectorVersion,
    ): string {
        $existing = DB::table('content.source_items')
            ->where('source_id', $contentSourceId)
            ->where('coord', $coord)
            ->first(['id']);

        if ($existing !== null) {
            DB::table('content.source_items')->where('id', $existing->id)->update([
                'stream_id' => $streamId,
                'record_key' => $recordKey,
                'kind' => $kind,
                'projector_version' => $projectorVersion,
                'last_seen_at' => now(),
                // Reappearance clears PROJECTION-level absence. The user-level
                // delete lives on items.removed_at and is never touched here.
                'removed_at' => null,
            ]);

            return (string) $existing->id;
        }

        // insertOrIgnore + re-read, not insert: the SELECT above and this write
        // are not atomic, and source_items_coord_unique (source_id, coord)
        // turns the loser of that race into a 23505. Unreachable while every
        // coord was minted per-run, but PoolItemCreateController now derives
        // the coord from the URL, so a double-clicked "Add" is two concurrent
        // requests writing the SAME coord — the loser would have surfaced as a
        // 500. Same reasoning as ensureManualSource().
        $id = (string) Str::uuid();
        DB::table('content.source_items')->insertOrIgnore([
            'id' => $id,
            'source_id' => $contentSourceId,
            'coord' => $coord,
            'stream_id' => $streamId,
            'record_key' => $recordKey,
            'kind' => $kind,
            'projector_version' => $projectorVersion,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $landed = DB::table('content.source_items')
            ->where('source_id', $contentSourceId)
            ->where('coord', $coord)
            ->value('id');

        if ($landed === null) {
            throw new \RuntimeException("Could not resolve a source item for coord {$coord}.");
        }

        return (string) $landed;
    }

    /**
     * Replace-set of the evidence this record carries. PlatformObject is the
     * record's own coord (joining within the platform); a link URL doubles as
     * CanonicalUrl so the same thing pasted manually or synced from a second
     * platform can union (§5).
     *
     * MUST be called inside a transaction that ALSO covers the source item's
     * own upsert — the DELETE/INSERT pair below is not atomic on its own, and
     * covering only this method still leaves a first-sight source item visible
     * with zero keys (see the long note at the call site). projectStream() and
     * writeManualItem() are the only callers; keep it that way. Both wrap it in
     * a transaction that ALSO covers the source item's own upsert, which is the
     * property this method depends on. Deliberately NOT self-wrapping: a
     * nested DB::transaction here would add a SAVEPOINT round trip per record
     * and would silently re-narrow the scope to the window that does not
     * matter.
     *
     * @param  array<string, mixed>  $projection
     */
    private function writeIdentityKeys(string $sourceItemId, string $coord, array $projection): void
    {
        DB::table('content.identity_keys')->where('source_item_id', $sourceItemId)->delete();

        $rows = [[
            'source_item_id' => $sourceItemId,
            'key_class' => KeyClass::PlatformObject->value,
            'key_value' => $coord,
            'tier' => KeyClass::PlatformObject->tier()->value,
            'created_at' => now(),
        ]];

        $url = $projection['facets']['f_link']['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $rows[] = [
                'source_item_id' => $sourceItemId,
                'key_class' => KeyClass::CanonicalUrl->value,
                'key_value' => KeyClass::CanonicalUrl->canonicalise($url),
                'tier' => KeyClass::CanonicalUrl->tier()->value,
                'created_at' => now(),
            ];
        }

        DB::table('content.identity_keys')->insert($rows);
    }

    /**
     * Projection-level absence: tombstoned records and records that project
     * to nothing stop contributing. Distinct from items.removed_at (the user
     * delete) on purpose — this one clears when the record comes back.
     *
     * @param  list<string>  $projectsToNothing
     */
    private function retireAbsentSourceItems(string $contentSourceId, string $streamId, array $projectsToNothing): int
    {
        // Adjacent defect (found in the same pass as SCALE-7): tombstones are
        // never removed from ingest.record_state, so plucking every
        // tombstoned key for the stream into PHP grows monotonically forever
        // — worse than SCALE-7's bind list, which at least shrinks when
        // items are removed. A correlated subquery keeps the tombstone
        // check server-side instead of round-tripping the whole list.
        // $projectsToNothing stays a literal list — it's bounded by this
        // run's record count, not the stream's whole history.
        //
        // Preserve the early return when there is nothing to retire (a cheap
        // exists() check rather than a full pluck) — it is what keeps the
        // 'removed' counter honest without issuing the update at all.
        $hasTombstones = DB::table('ingest.record_state')
            ->where('stream_id', $streamId)
            ->whereNotNull('tombstoned_at')
            ->exists();
        if (! $hasTombstones && $projectsToNothing === []) {
            return 0;
        }

        return DB::table('content.source_items')
            ->where('source_id', $contentSourceId)
            ->where('stream_id', $streamId)
            ->whereNull('removed_at')
            ->where(function ($query) use ($streamId, $projectsToNothing) {
                $query->whereIn('record_key', function ($sub) use ($streamId) {
                    $sub->select('key')->from('ingest.record_state')
                        ->where('stream_id', $streamId)
                        ->whereNotNull('tombstoned_at');
                });
                if ($projectsToNothing !== []) {
                    $query->orWhereIn('record_key', $projectsToNothing);
                }
            })
            ->update(['removed_at' => now(), 'last_seen_at' => now()]);
    }

    /**
     * Run the pure resolver over the user's live source-items of this kind
     * and bind every group to a stable item id.
     *
     * @return array<string, string> coord => item id
     */
    private function resolveItems(string $userId, string $kind): array
    {
        // Deterministic group order (recommended, plan §b.3): no user-visible
        // change on its own (bindGroup() picks the winner by bound_at), but
        // makes fresh-item UUID mint order reproducible instead of
        // heap-order dependent — covered by the golden test above.
        $rows = DB::table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('cs.user_id', $userId)
            ->where('si.kind', $kind)
            ->whereNull('si.removed_at')
            ->orderBy('si.first_seen_at')
            ->orderBy('si.id')
            ->get(['si.id', 'si.coord', 'si.source_id', 'si.kind', 'si.first_seen_at']);

        if ($rows->isEmpty()) {
            return [];
        }

        // SCALE-7: the audit's "chunk the reads" remedy is wrong here — the
        // resolver is a global union-find (App\Content\Identity\Resolver),
        // and chunking the ROW SET would silently stop merging a union that
        // spans chunks. The legitimate reduction is the BIND LIST, not the
        // row set: a subquery on the same si/cs predicate instead of
        // materialising every source_item id into a whereIn(...) array.
        $keysBySourceItem = DB::table('content.identity_keys')
            ->whereIn('source_item_id', function ($sub) use ($userId, $kind) {
                $sub->select('si.id')->from('content.source_items as si')
                    ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
                    ->where('cs.user_id', $userId)
                    ->where('si.kind', $kind)
                    ->whereNull('si.removed_at');
            })
            ->get(['source_item_id', 'key_class', 'key_value'])
            ->groupBy('source_item_id');

        $sourceItems = $rows->map(function (object $row) use ($keysBySourceItem) {
            $keys = [];
            foreach ($keysBySourceItem->get($row->id, collect()) as $key) {
                $class = KeyClass::tryFrom((string) $key->key_class);
                if ($class !== null) {
                    $keys[] = new IdentityKey($class, (string) $key->key_value);
                }
            }

            return new SourceItem(
                coord: (string) $row->coord,
                sourceId: (string) $row->source_id,
                kind: (string) $row->kind,
                keys: $keys,
                firstSeenAt: $row->first_seen_at === null ? null : (string) $row->first_seen_at,
            );
        })->all();

        $decisions = DB::table('content.identity_decisions')
            ->where('user_id', $userId)
            ->get(['left_coord', 'right_coord', 'verdict'])
            ->map(fn (object $d) => new Decision((string) $d->left_coord, (string) $d->right_coord, (string) $d->verdict))
            ->all();

        $resolution = $this->resolver->resolve($sourceItems, $decisions);

        $itemByCoord = [];
        foreach ($resolution->groups as $group) {
            $itemId = $this->bindGroup($userId, $kind, $group);
            foreach ($group as $coord) {
                $itemByCoord[$coord] = $itemId;
            }
        }

        $this->recordCandidates($userId, $resolution->candidates, $itemByCoord);

        // One re-read is necessary for parity: mergeInto() (called from
        // bindGroup() above, in this same pass) mutates item_id on rows that
        // belong to a just-discarded loser item, so reading the PRE-bindGroup
        // $rows collection here would write stale values. It is NOT a
        // redundant read to eliminate. What the audit missed is the tail:
        // the old code issued one UPDATE per row whose item_id moved — a
        // second, unnamed N+1. Replaced with one UPDATE per distinct TARGET
        // item id (typically 0 in steady state, since most rows already
        // point at their resolved item).
        $bySourceItemId = DB::table('content.source_items')
            ->whereIn('id', function ($sub) use ($userId, $kind) {
                $sub->select('si.id')->from('content.source_items as si')
                    ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
                    ->where('cs.user_id', $userId)
                    ->where('si.kind', $kind)
                    ->whereNull('si.removed_at');
            })
            ->get(['id', 'coord', 'item_id']);

        $idsByTarget = [];
        foreach ($bySourceItemId as $row) {
            $target = $itemByCoord[$row->coord] ?? null;
            if ($target !== null && $row->item_id !== $target) {
                $idsByTarget[$target][] = $row->id;
            }
        }
        foreach ($idsByTarget as $target => $ids) {
            DB::table('content.source_items')->whereIn('id', $ids)->update(['item_id' => $target]);
        }

        return $itemByCoord;
    }

    /**
     * Anchor a resolved group to ONE item id: oldest binding wins, losers'
     * anchors redirect via superseded_by, and a merged-away item that carries
     * no curation is deleted (a bare duplicate row would keep rendering).
     *
     * @param  list<string>  $group
     */
    private function bindGroup(string $userId, string $kind, array $group): string
    {
        $anchors = DB::table('content.item_anchors')
            ->where('user_id', $userId)
            ->whereIn('coord', $group)
            ->orderBy('bound_at')
            ->get(['coord', 'item_id', 'superseded_by', 'bound_at']);

        $effective = $anchors
            ->map(fn (object $a) => (string) ($a->superseded_by ?? $a->item_id))
            ->unique()
            ->values();

        $winner = $this->preferOwnerAnchored($effective)
            ?? $effective->first()
            ?? $this->createItem($userId, $kind);

        foreach ($group as $coord) {
            $existing = $anchors->firstWhere('coord', $coord);
            if ($existing === null) {
                DB::table('content.item_anchors')->insert([
                    'coord' => $coord,
                    'user_id' => $userId,
                    'item_id' => $winner,
                    'bound_at' => now(),
                ]);
            }
        }

        // Losers are "everything that is not the winner", NOT slice(1). Those
        // were the same thing while the winner was always $effective->first();
        // preferOwnerAnchored() can pick a later element, and slice(1) would
        // then hand mergeInto() the winner as its own discarded item — whose
        // hard DELETE would destroy the row this preference exists to protect
        // — while leaving the real loser at index 0 unmerged. $effective is
        // unique(), so this is identical to slice(1) whenever the winner is
        // first, which is every connection-only group.
        foreach ($effective->reject(fn (string $itemId) => $itemId === $winner) as $loser) {
            $this->mergeInto($userId, keptItemId: $winner, discardedItemId: (string) $loser);
        }

        return $winner;
    }

    /**
     * The owner outranks the machine (C8) at merge time: when a group already
     * spans more than one item, an item the user authored through their
     * manual source is the one that survives.
     *
     * Not a preference — a correctness requirement. mergeInto() below hard-
     * DELETEs a discarded item that carries no pin and no override, and every
     * content.f_* / item_media / offers / item_tags / item_links / item_slugs
     * table cascades on items.id, so that DELETE takes those rows with it. A
     * connection survives because its next projection rewrites them; a manual
     * source has no next projection, so the owner's words would be gone.
     *
     * Suppressing the DELETE instead would be worse: it leaves a row with no
     * source items that PoolResolver still returns in `library`, since that
     * query filters on user_id + kind + removed_at and nothing else.
     *
     * This DOES invert which side is destroyed in a manual/connection merge —
     * the connection's item row is now the discarded one, and its facets are
     * restored on that connection's next run. That is the same restoration
     * ItemMerger already relies on, and it is the trade the C8 rule requires.
     *
     * Consulted ONLY when a merge is actually about to happen. In steady state
     * every group is a singleton, so this costs zero queries on the hot path.
     *
     * @param  Collection<int, string>  $effective  candidate item ids, oldest binding first
     */
    private function preferOwnerAnchored(Collection $effective): ?string
    {
        if ($effective->count() < 2) {
            return null;
        }

        $ownerAuthored = DB::table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->join('content.items as ci', 'ci.id', '=', 'si.item_id')
            ->whereIn('si.item_id', $effective->all())
            ->where('cs.kind', 'manual')
            ->whereNull('si.removed_at')
            // A user-REMOVED item must never win. It is invisible to
            // PoolResolver, so preferring it would delete the visible
            // connector item (no curation spares it) and take the content out
            // of the library altogether — strictly worse than the
            // oldest-binding rule this preference overrides.
            ->whereNull('ci.removed_at')
            ->distinct()
            ->pluck('si.item_id')
            ->map(fn ($id) => (string) $id)
            ->flip();

        // Oldest-binding-wins still applies WITHIN the owner-authored set:
        // $effective arrives in bound_at order, so the first match is oldest.
        return $effective->first(fn (string $itemId) => $ownerAuthored->has($itemId));
    }

    private function createItem(string $userId, string $kind): string
    {
        $id = (string) Str::uuid();
        DB::table('content.items')->insert([
            'id' => $id,
            'user_id' => $userId,
            'kind' => $kind,
            'facets_cache' => json_encode([]),
            'eligible_cache' => json_encode([]),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * A cross-source union discovered AFTER both sides already had items:
     * redirect the loser's anchors, repoint its rows, log the merge, and
     * delete the loser only when it carries no curation of its own.
     */
    private function mergeInto(string $userId, string $keptItemId, string $discardedItemId): void
    {
        DB::table('content.item_anchors')
            ->where('user_id', $userId)
            ->where(function ($query) use ($discardedItemId) {
                $query->where('item_id', $discardedItemId)->orWhere('superseded_by', $discardedItemId);
            })
            ->update(['superseded_by' => $keptItemId]);

        DB::table('content.source_items')->where('item_id', $discardedItemId)->update(['item_id' => $keptItemId]);

        DB::table('content.item_merges')->insert([
            'user_id' => $userId,
            'kept_item_id' => $keptItemId,
            'discarded_item_id' => $discardedItemId,
            'reason' => 'identity_union',
            'detail' => json_encode([]),
            'merged_at' => now(),
        ]);

        $this->moveLinks($keptItemId, $discardedItemId);
        $this->moveSlugs($userId, $keptItemId, $discardedItemId);

        $hasCuration = DB::table('site.section_items')->where('item_id', $discardedItemId)->exists()
            || DB::table('content.manual_overrides')->where('item_id', $discardedItemId)->exists();

        if (! $hasCuration) {
            DB::table('content.items')->where('id', $discardedItemId)->delete();
        }
    }

    /**
     * The owner's hand-saved cross-platform links follow the survivor.
     *
     * They are typed by a person and no projection ever rewrites them, so the
     * cascade on items.id would lose them for good. Sparing the row instead —
     * adding item_links to $hasCuration — would be worse: it manufactures
     * exactly the source-item-less ghost preferOwnerAnchored()'s docblock
     * calls out, which PoolResolver still lists in `library` forever because
     * that query filters on user_id + kind + removed_at and nothing else.
     *
     * item_links_unique (item_id, platform) means a platform the survivor
     * already carries cannot move; the survivor's own link is the better one
     * to keep, and the loser's goes with the cascade.
     */
    private function moveLinks(string $keptItemId, string $discardedItemId): void
    {
        $keptPlatforms = DB::table('content.item_links')
            ->where('item_id', $keptItemId)
            ->pluck('platform')
            ->all();

        $movable = DB::table('content.item_links')->where('item_id', $discardedItemId);
        if ($keptPlatforms !== []) {
            $movable->whereNotIn('platform', $keptPlatforms);
        }

        $movable->update(['item_id' => $keptItemId, 'updated_at' => now()]);
    }

    /**
     * Published URLs follow the survivor, as RETIRED aliases that still 301.
     *
     * content.item_slugs cascades on items.id, and since bindGroup() may now
     * discard a CONNECTOR item in favour of an owner-authored one, the
     * discarded side is often the one whose slug is already public — deleting
     * it would break a live URL and the retired-slug history behind it.
     *
     * Mirrors ItemMerger::moveSlugs() deliberately, including its ordering
     * hazard: stamp every moved row retired FIRST, then clear the stamp on the
     * one being promoted. Reversing that order re-stamps the promoted row and
     * the nightly slugs:prune-retired sweep hard-deletes a slug that is
     * currently serving as the item's URL.
     */
    private function moveSlugs(string $userId, string $keptItemId, string $discardedItemId): void
    {
        $keptHasCurrent = DB::table('content.item_slugs')
            ->where('item_id', $keptItemId)
            ->where('is_current', true)
            ->exists();

        $promote = $keptHasCurrent
            ? null
            : DB::table('content.item_slugs')
                ->where('item_id', $discardedItemId)
                ->where('is_current', true)
                ->orderByDesc('created_at')
                ->value('id');

        DB::table('content.item_slugs')
            ->where('user_id', $userId)
            ->where('item_id', $discardedItemId)
            ->update(['item_id' => $keptItemId, 'is_current' => false, 'retired_at' => now()]);

        if ($promote !== null) {
            DB::table('content.item_slugs')->where('id', $promote)->update(['is_current' => true, 'retired_at' => null]);
        }
    }

    /**
     * @param  list<Candidate>  $candidates
     * @param  array<string, string>  $itemByCoord
     */
    private function recordCandidates(string $userId, array $candidates, array $itemByCoord): void
    {
        foreach ($candidates as $candidate) {
            $left = $itemByCoord[$candidate->left] ?? null;
            $right = $itemByCoord[$candidate->right] ?? null;
            if ($left === null || $right === null || $left === $right) {
                continue;
            }

            DB::table('content.identity_candidates')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'left_item_id' => $left,
                'right_item_id' => $right,
                'score' => 50,
                'evidence' => json_encode(['key' => $candidate->evidence]),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Typed facet rows, per (item, source) — what each source SAID, kept
     * per-source so conflicts resolve at read/serve time instead of being
     * overwritten (plan §6).
     *
     * @param  array<string, array<string, mixed>>  $projections  coord => projection
     * @param  array<string, string>  $itemByCoord
     */
    private function writeFacets(string $contentSourceId, string $userId, array $projections, array $itemByCoord): void
    {
        // Group per item so collection facets (media/offers/tags) REPLACE the
        // pair's rows once, then insert every record's contribution — two
        // records on one item (a same-source merge) must not wipe each other.
        $byItem = [];
        foreach ($projections as $coord => $projection) {
            $itemId = $itemByCoord[$coord] ?? null;
            if ($itemId !== null) {
                $byItem[$itemId][] = $projection;
            }
        }

        // SCALE-17/#CACHE-2: the batching boundary is HERE, not inside
        // replaceCollections() — $byItem is the batch. Called once for the
        // whole run's items; the singleton-facet upserts below stay per
        // (item, record) because each one targets a different row.
        $this->replaceCollections($contentSourceId, $userId, $byItem);

        foreach ($byItem as $itemId => $group) {
            foreach ($group as $projection) {
                $facets = (array) ($projection['facets'] ?? []);

                // The projection's top-level headline IS this source's f_text
                // contribution — folding it here is what lets ValueResolver
                // pick one headline across sources later.
                $headline = $projection['headline'] ?? null;
                if (is_string($headline) && $headline !== '') {
                    $facets['f_text'] = ((array) ($facets['f_text'] ?? [])) + ['headline' => $headline];
                }

                foreach ($facets as $facet => $columns) {
                    $this->upsertSingletonFacet($itemId, $contentSourceId, (string) $facet, (array) $columns);
                }
            }
        }
    }

    /** @param array<string, mixed> $columns */
    private function upsertSingletonFacet(string $itemId, string $contentSourceId, string $facet, array $columns): void
    {
        $allowed = self::SINGLETON_FACETS[$facet] ?? null;
        if ($allowed === null) {
            return;
        }

        $row = [];
        foreach ($columns as $column => $value) {
            if (in_array($column, $allowed, true)) {
                if (is_string($value) && in_array($column, self::URL_COLUMNS[$facet] ?? [], true)) {
                    $value = SecretParams::minimiseUrl($value);
                }
                $row[$column] = is_array($value) ? json_encode($value) : $value;
            }
        }
        if ($row === []) {
            return;
        }

        DB::table("content.{$facet}")->upsert(
            [$row + ['item_id' => $itemId, 'source_id' => $contentSourceId, 'updated_at' => now()]],
            ['item_id', 'source_id'],
            array_merge(array_keys($row), ['updated_at']),
        );
    }

    /**
     * Collection facets are a set per (item, source): replace wholesale —
     * for the WHOLE batch of items at once (SCALE-17/#CACHE-2).
     *
     * The old shape was three DELETEs plus one INSERT per row, per item: an
     * item with 10 images, 2 offers and 3 tags cost 18 statements on its own,
     * multiplied by every item in the run. Now it is three chunked DELETEs
     * plus three chunked multi-row INSERTs for the entire batch, and the
     * media-asset lookup is one bulk resolve instead of two queries per image.
     *
     * The DELETE widens from `item_id = ?` to `item_id IN (batch)` on the same
     * `source_id` predicate — exactly the same row set, issued once.
     *
     * @param  array<string, list<array<string, mixed>>>  $byItem  item id => that item's projections
     */
    private function replaceCollections(string $contentSourceId, string $userId, array $byItem): void
    {
        if ($byItem === []) {
            return;
        }

        $chunk = $this->writeChunk();

        // Per-item flatten, preserving the old array_merge order. Positions
        // are assigned from these list indices below, so they stay 0..n-1
        // WITHIN an item — a counter running across the batch would silently
        // reorder every gallery after the first item.
        $mediaByItem = [];
        $offersByItem = [];
        $tagsByItem = [];
        $variantsByItem = [];
        $collectionsByItem = [];
        foreach ($byItem as $itemId => $projections) {
            $media = [];
            $offers = [];
            $tags = [];
            $variants = [];
            $collections = [];
            foreach ($projections as $projection) {
                $media = array_merge($media, array_values((array) ($projection['media'] ?? [])));
                $offers = array_merge($offers, array_values((array) ($projection['offers'] ?? [])));
                $tags = array_merge($tags, array_values((array) ($projection['tags'] ?? [])));
                $variants = array_merge($variants, array_values((array) ($projection['variants'] ?? [])));
                $collections = array_merge($collections, array_values((array) ($projection['collections'] ?? [])));
            }
            $mediaByItem[(string) $itemId] = $media;
            $offersByItem[(string) $itemId] = $offers;
            $tagsByItem[(string) $itemId] = $tags;
            $variantsByItem[(string) $itemId] = $variants;
            $collectionsByItem[(string) $itemId] = $collections;
        }

        $assetIdByFingerprint = $this->resolveMediaAssets($userId, $mediaByItem, $chunk);

        $mediaRows = [];
        foreach ($mediaByItem as $itemId => $entries) {
            foreach ($entries as $position => $entry) {
                $entry = (array) $entry;
                [$fingerprint] = $this->mediaFingerprint($entry);
                $mediaRows[$itemId][] = [
                    'id' => (string) Str::uuid(),
                    'item_id' => $itemId,
                    'source_id' => $contentSourceId,
                    // A fingerprint-less entry still gets its item_media row,
                    // just with no asset behind it — unchanged behaviour.
                    'asset_id' => $fingerprint === null ? null : ($assetIdByFingerprint[$fingerprint] ?? null),
                    'role' => (string) ($entry['role'] ?? 'gallery'),
                    'position' => $position,
                    'alt_text' => $entry['alt'] ?? null,
                    'created_at' => now(),
                ];
            }
        }

        $offerRows = [];
        foreach ($offersByItem as $itemId => $entries) {
            foreach ($entries as $offer) {
                $offer = (array) $offer;
                $offerRows[$itemId][] = [
                    'id' => (string) Str::uuid(),
                    'item_id' => $itemId,
                    'source_id' => $contentSourceId,
                    'channel' => $offer['channel'] ?? null,
                    'variant_label' => $offer['variant_label'] ?? null,
                    'amount_minor' => $offer['amount_minor'] ?? null,
                    'currency' => $offer['currency'] ?? null,
                    'qualifier' => (string) ($offer['qualifier'] ?? 'exact'),
                    'amount_max_minor' => $offer['amount_max_minor'] ?? null,
                    'url' => SecretParams::minimiseUrl($offer['url'] ?? null),
                    // Slice 5a Task 7 fix round 1, Finding 2: the column has
                    // existed since the schema's own migration
                    // (20260727140000) but was never populated by any
                    // projector — additive, same discipline as every other
                    // key here (?? null): a projection that never sets
                    // 'availability' (every projector but Shop's, today)
                    // writes null, byte-identical to before this fix.
                    'availability' => $offer['availability'] ?? null,
                    'updated_at' => now(),
                ];
            }
        }

        $tagRows = [];
        foreach ($tagsByItem as $itemId => $entries) {
            foreach ($entries as $tag) {
                $tag = (array) $tag;
                if (! isset($tag['tag']) || $tag['tag'] === '') {
                    continue;
                }
                $tagRows[$itemId][] = [
                    'id' => (string) Str::uuid(),
                    'item_id' => $itemId,
                    'source_id' => $contentSourceId,
                    'tag' => (string) $tag['tag'],
                    'tag_type' => $tag['tag_type'] ?? null,
                ];
            }
        }

        // content.item_variants: label is NOT NULL, so a nameless entry is
        // dropped rather than written with an empty label — the table exists
        // to name a choice.
        $variantRows = [];
        foreach ($variantsByItem as $itemId => $entries) {
            foreach ($entries as $position => $entry) {
                $entry = (array) $entry;
                $label = trim((string) ($entry['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $variantRows[$itemId][] = [
                    'id' => (string) Str::uuid(),
                    'item_id' => $itemId,
                    'source_id' => $contentSourceId,
                    'label' => $label,
                    'sku' => $entry['sku'] ?? null,
                    // Additive, exactly like sku: a projection that omits the
                    // key writes null, so no existing projector changes
                    // behaviour (migration 20260813100003).
                    'image_url' => $entry['image_url'] ?? null,
                    'position' => $position,
                ];
            }
        }

        // Before the chunk loop, so every membership row below already has a
        // collection to point at.
        $collectionIds = $this->upsertCollections($userId, $collectionsByItem);

        $collectionItemRows = [];
        foreach ($collectionsByItem as $itemId => $entries) {
            $position = 0;
            foreach ($entries as $entry) {
                $entry = (array) $entry;
                $key = ((string) ($entry['kind'] ?? 'collection'))."\0".((string) ($entry['external_ref'] ?? ''));
                $collectionId = $collectionIds[$key] ?? null;
                if ($collectionId === null) {
                    continue;
                }
                $collectionItemRows[$itemId][] = [
                    'collection_id' => $collectionId,
                    'item_id' => $itemId,
                    'source_id' => $contentSourceId,
                    'position' => $position++,
                ];
            }
        }

        foreach (array_chunk(array_keys($mediaByItem), $chunk) as $itemIds) {
            $tables = [
                'item_media' => $this->rowsFor($mediaRows, $itemIds),
                'offers' => $this->rowsFor($offerRows, $itemIds),
                'item_tags' => $this->rowsFor($tagRows, $itemIds),
                'item_variants' => $this->rowsFor($variantRows, $itemIds),
                // The shared DELETE below is `item_id IN (batch) AND source_id
                // = ours` — exactly the replace-by-source semantics this table
                // needs. collection_items' PK is (collection_id, item_id) with
                // source_id OUTSIDE it, so scoping by source is the only thing
                // stopping two sources deleting each other's memberships.
                'collection_items' => $this->rowsFor($collectionItemRows, $itemIds),
            ];

            // Batching widens the window in which an item has no collection
            // rows from one item to a whole chunk, and serving reads these
            // tables live — so wrap it. Per CHUNK, not per batch, to keep lock
            // duration bounded. No caller holds an outer transaction over
            // projectStream() (RunExecutor and IngestProjectCommand both call
            // it bare; Lander's transactions close before projection starts),
            // so this is the outermost one.
            DB::transaction(function () use ($tables, $itemIds, $contentSourceId, $chunk) {
                foreach ($tables as $table => $rows) {
                    DB::table("content.{$table}")
                        ->whereIn('item_id', $itemIds)
                        ->where('source_id', $contentSourceId)
                        ->delete();

                    foreach (array_chunk($rows, $chunk) as $rowChunk) {
                        DB::table("content.{$table}")->insert($rowChunk);
                    }
                }
            });
        }
    }

    /**
     * The content.collections rows a batch's projections name, upserted on
     * their natural key (user_id, kind, external_ref) and returned keyed by
     * "kind\0external_ref" — NOT by external_ref alone, which is only unique
     * within a kind.
     *
     * Three columns are deliberately treated as owner-owned, i.e. a scrape may
     * seed them but never overwrite them:
     *
     * - removed_at: absent from BOTH the insert and the update list. It means
     *   "the owner deleted this collection" and is one-way, the same rule
     *   content.items.removed_at follows. A scrape re-listing a category is not
     *   consent to resurrect it.
     * - position: INSERT-ONLY. The vendor's ordering is a reasonable initial
     *   value, but ServiceCollections::reposition() (the owner-facing reorder
     *   endpoint) does not filter is_user_created, so leaving position in the
     *   update list would let the next scheduled run snap a reorder the owner
     *   just made back to the vendor's order — silently, and on a schedule.
     *
     * label is the deliberate exception and DOES stay in the update list: a
     * vendor-side rename must be followed rather than mint a duplicate, which
     * is the whole reason external_ref rather than label is the natural key.
     *
     * @param  array<string, list<array<string, mixed>>>  $byItem
     * @return array<string, string> "kind\0external_ref" => collection id
     */
    private function upsertCollections(string $userId, array $byItem): array
    {
        $wanted = [];
        foreach ($byItem as $entries) {
            foreach ($entries as $entry) {
                $entry = (array) $entry;
                $externalRef = isset($entry['external_ref']) && is_scalar($entry['external_ref'])
                    ? (string) $entry['external_ref']
                    : null;
                $label = trim((string) ($entry['label'] ?? ''));
                // The natural key IS the external ref; a machine-derived
                // collection without one cannot be reconciled across runs and
                // would insert a fresh row every time. Labels are mutable on
                // the vendor's side and are never a key (slice 5a's incident).
                if ($externalRef === null || $label === '') {
                    continue;
                }
                $kind = (string) ($entry['kind'] ?? 'collection');
                $wanted[$kind."\0".$externalRef] = [
                    'kind' => $kind,
                    'external_ref' => $externalRef,
                    'label' => $label,
                    'position' => (int) ($entry['position'] ?? 0),
                ];
            }
        }

        if ($wanted === []) {
            return [];
        }

        $rows = [];
        foreach ($wanted as $entry) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'parent_id' => null,
                'label' => $entry['label'],
                'kind' => $entry['kind'],
                'external_ref' => $entry['external_ref'],
                'position' => $entry['position'],
                'is_user_created' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Plain column names in the update list -- Laravel renders these as
        // `set "label" = "excluded"."label"`, which is unambiguous. A DB::raw
        // with a BARE column here is SQLSTATE 42702 on Postgres and silently
        // fine on SQLite (slice 5a, 2026-08-12).
        //
        // 'position' is NOT here on purpose -- see the docblock. It is written
        // by the INSERT branch only, so the vendor seeds an order and the owner
        // owns it from then on.
        DB::table('content.collections')->upsert(
            $rows,
            ['user_id', 'kind', 'external_ref'],
            ['label', 'updated_at'],
        );

        // The read-back deliberately does NOT filter on kind, and that is
        // safe for a stronger reason than "the refs are unlikely to collide":
        // the returned map is keyed by each found row's OWN kind, so a row of
        // another kind that happens to share an external_ref lands under a
        // DIFFERENT map key and can never be handed back as this entry's id.
        // Adding `whereIn('kind', …)` would narrow the read but change no
        // outcome — a wrong id is structurally impossible here, not merely
        // improbable.
        $ids = [];
        foreach (array_chunk(array_values($wanted), $this->writeChunk()) as $chunk) {
            $refs = array_column($chunk, 'external_ref');
            $found = DB::table('content.collections')
                ->where('user_id', $userId)
                ->whereIn('external_ref', $refs)
                ->get(['id', 'kind', 'external_ref']);
            foreach ($found as $row) {
                $ids[$row->kind."\0".$row->external_ref] = (string) $row->id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $rowsByItem
     * @param  list<string>  $itemIds
     * @return list<array<string, mixed>>
     */
    private function rowsFor(array $rowsByItem, array $itemIds): array
    {
        $rows = [];
        foreach ($itemIds as $itemId) {
            foreach ($rowsByItem[$itemId] ?? [] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * #PRIV-5: minimise BEFORE the fingerprint is computed, so the fingerprint
     * and the stored source_url derive from the same string — the fingerprint
     * is the UNIQUE (user_id, fingerprint) dedupe key, and a fingerprint
     * computed from the raw URL while a minimised URL is stored would let a
     * re-run mint a second row for the same image. The vendor's stable ref is
     * PREFERRED over the URL (slice 1a §3.1): Instagram URLs re-sign on every
     * sync (`oh`/`oe` params survive minimisation), so a URL-keyed asset
     * re-mints per sync the moment a projector emits url beside ref. The URL
     * is the fallback for entries with no ref.
     *
     * ONE implementation, called from both the bulk resolve and the row build
     * — the two must never disagree about what an entry's fingerprint is.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: ?string, 1: ?string} [fingerprint, minimised source url]
     */
    private function mediaFingerprint(array $entry): array
    {
        // Upload shape (slice 1a §3.4): the stable ref IS the site_media id.
        // Inside the url- namespace by construction — only this method mints
        // 'upload:' fingerprints, so no existing row can collide.
        $siteMediaId = $this->uploadSiteMediaId($entry);
        if ($siteMediaId !== null) {
            return ['url-'.sha1('upload:'.$siteMediaId), null];
        }

        $url = isset($entry['url']) && is_string($entry['url']) && $entry['url'] !== ''
            ? SecretParams::minimiseUrl($entry['url'])
            : null;
        $url = ($url === '' ? null : $url); // minimiseUrl fails closed to ''
        $ref = isset($entry['ref']) && is_string($entry['ref']) && $entry['ref'] !== '' ? $entry['ref'] : null;

        $fingerprint = $ref ?? $url;

        return [$fingerprint === null ? null : 'url-'.sha1($fingerprint), $url];
    }

    /** The upload shape: a non-empty site_media_id IS the discriminator (slice 1a §3.4). */
    private function uploadSiteMediaId(array $entry): ?string
    {
        $id = $entry['site_media_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * One asset per distinct image, for the whole batch: bulk-resolve the
     * fingerprints that already exist, mint the rest in one insertOrIgnore,
     * then re-read the minted set.
     *
     * The re-read is not belt-and-braces: insertOrIgnore returns no ids, and
     * a concurrent projection run for the same user may have won the
     * UNIQUE (user_id, fingerprint) race — assuming our rows landed would
     * hand back ids that were never written.
     *
     * @param  array<string, list<mixed>>  $mediaByItem
     * @return array<string, string> fingerprint => media_assets.id
     */
    private function resolveMediaAssets(string $userId, array $mediaByItem, int $chunk): array
    {
        // Dedupe by fingerprint in PHP first: two carousel frames — or two
        // items — carrying the same image must mint ONE asset, which the
        // old SELECT-then-INSERT-per-row path got for free.
        $entryByFingerprint = [];
        foreach ($mediaByItem as $entries) {
            foreach ($entries as $entry) {
                $entry = (array) $entry;
                [$fingerprint, $url] = $this->mediaFingerprint($entry);
                if ($fingerprint !== null && ! isset($entryByFingerprint[$fingerprint])) {
                    $entryByFingerprint[$fingerprint] = [$entry, $url];
                }
            }
        }
        if ($entryByFingerprint === []) {
            return [];
        }

        $byFingerprint = $this->lookupMediaAssets($userId, array_keys($entryByFingerprint), $chunk);

        $missing = array_diff_key($entryByFingerprint, $byFingerprint);
        if ($missing === []) {
            return $byFingerprint;
        }

        $rows = [];
        foreach ($missing as $fingerprint => [$entry, $url]) {
            $uploadSiteMediaId = $this->uploadSiteMediaId($entry);
            $isUpload = $uploadSiteMediaId !== null;
            $rows[] = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'fingerprint' => $fingerprint,
                'source_url' => $url,
                'site_media_id' => $uploadSiteMediaId,
                'mime_type' => $isUpload ? ($entry['mime_type'] ?? null) : null,
                'width' => $entry['width'] ?? null,
                'height' => $entry['height'] ?? null,
                // 'measured' for uploads: the variant pipeline decoded the
                // image. 'declared' when a connector claimed dims. Unchanged
                // for the connector shapes.
                'dims_confidence' => $isUpload ? 'measured' : (isset($entry['width']) ? 'declared' : null),
                // Slice 1b D6. Mint-only, like every other column here: a
                // Google photo ref rotates every fetch, so a rotated photo
                // arrives as a NEW row carrying its own credit. There is no
                // update path to keep in sync. An empty block is stored as
                // NULL — "Google returned no attribution" has to stay
                // distinguishable from "a credit with no name in it".
                'attribution' => isset($entry['attribution']) && is_array($entry['attribution']) && $entry['attribution'] !== []
                    ? json_encode($entry['attribution'])
                    : null,
                'created_at' => now(),
            ];
        }
        foreach (array_chunk($rows, $chunk) as $rowChunk) {
            DB::table('content.media_assets')->insertOrIgnore($rowChunk);
        }

        $resolved = $byFingerprint + $this->lookupMediaAssets($userId, array_keys($missing), $chunk);

        $this->dispatchMirrors($userId, $entryByFingerprint, $resolved, $chunk);

        return $resolved;
    }

    /**
     * Slice 1b D1/D8 — queue a byte mirror for OWNED-class media only.
     *
     * Passes the entry's raw url rather than the minimised one stored in
     * source_url: those serve different purposes. source_url is the record;
     * the job needs a url the CDN will still honour, and minimiseUrl() strips
     * query params on a denylist it was never asked to keep fetchable.
     *
     * The whole candidate set is re-checked against the DB rather than just the
     * freshly minted rows, so a mirror that failed on a previous run is retried
     * on the next sync. storage_path IS NULL is what makes that terminate, and
     * the job's ShouldBeUnique keyed on asset id is what stops a retried run
     * piling up duplicates in the queue.
     *
     * @param  array<string, array{0: array<string, mixed>, 1: string|null}>  $entryByFingerprint
     * @param  array<string, string>  $assetIdByFingerprint
     */
    private function dispatchMirrors(string $userId, array $entryByFingerprint, array $assetIdByFingerprint, int $chunk): void
    {
        $candidates = [];
        foreach ($entryByFingerprint as $fingerprint => [$entry, $_minimisedUrl]) {
            $rawUrl = $entry['url'] ?? null;
            if (! is_string($rawUrl) || $rawUrl === '' || ! MediaMirror::isOwnedEntry($entry)) {
                continue;
            }
            $assetId = $assetIdByFingerprint[$fingerprint] ?? null;
            if ($assetId !== null) {
                $candidates[(string) $assetId] = $rawUrl;
            }
        }
        if ($candidates === []) {
            return;
        }

        foreach (array_chunk($candidates, $chunk, true) as $slice) {
            $needing = DB::table('content.media_assets')
                ->whereIn('id', array_keys($slice))
                ->whereNull('storage_path')
                ->whereNull('site_media_id')
                ->whereNotNull('source_url')
                ->pluck('id');

            foreach ($needing as $assetId) {
                // ::dispatch(), never Bus::dispatch(new ...) — the latter
                // silently drops ShouldBeUnique.
                MirrorMediaAssetJob::dispatch($userId, (string) $assetId, $slice[(string) $assetId]);
            }
        }
    }

    /**
     * @param  list<string>  $fingerprints
     * @return array<string, string> fingerprint => media_assets.id
     */
    private function lookupMediaAssets(string $userId, array $fingerprints, int $chunk): array
    {
        $found = [];
        foreach (array_chunk($fingerprints, $chunk) as $batch) {
            $ids = DB::table('content.media_assets')
                ->where('user_id', $userId)
                ->whereIn('fingerprint', $batch)
                ->pluck('id', 'fingerprint');

            foreach ($ids as $fingerprint => $id) {
                $found[(string) $fingerprint] = (string) $id;
            }
        }

        return $found;
    }

    /** Per-chunk batch size for refreshItemCaches()/resolveItems(). Bind lists stay far under Postgres' 65,535 limit at this size. */
    private const BATCH_SIZE = 500;

    /**
     * SCALE-17/#CACHE-2 write chunk for replaceCollections(). Config-driven so
     * a test can shrink it to exercise chunk boundaries; falls back to
     * BATCH_SIZE if the value is missing or nonsensical.
     */
    private function writeChunk(): int
    {
        $chunk = (int) config('partna.ingest.projection_write_chunk', self::BATCH_SIZE);

        return $chunk > 0 ? $chunk : self::BATCH_SIZE;
    }

    /**
     * Dashboard-only caches (§5): headline via the real per-column resolution
     * over every source's f_text row, facet list for filtering. Serving joins
     * live tables; these never feed the document.
     *
     * SCALE-8: this used to be 20 queries PER ITEM (1 f_text join + 14
     * singleton exists() + 4 collection exists() + 1 update), i.e. 20 x the
     * user's whole live item count for the kind on every run with any
     * change — not 20 x changed items. Rewritten to batch across chunks of
     * BATCH_SIZE items: each of the 14 singleton / 4 collection existence
     * checks becomes one `WHERE item_id IN (batch)` query total (not one per
     * table per item), and the f_text fetch keeps its `content.sources` join
     * (I8 — ValueResolver needs cs.priority to weigh across sources; losing
     * it would silently change every headline). last_seen_at/updated_at are
     * bumped for the WHOLE batch in one UPDATE (I10 — accepted, documented
     * nondeterminism on same-run ties, see plan); only items whose resolved
     * headline or facet list actually changed get a second, narrow UPDATE.
     *
     * @param  list<string>  $itemIds
     */
    private function refreshItemCaches(string $userId, array $itemIds): void
    {
        foreach (array_chunk($itemIds, self::BATCH_SIZE) as $batch) {
            $contributionsByItem = DB::table('content.f_text as ft')
                ->join('content.sources as cs', 'cs.id', '=', 'ft.source_id')
                ->whereIn('ft.item_id', $batch)
                ->get(['ft.item_id', 'ft.source_id', 'ft.headline', 'cs.priority', 'ft.updated_at'])
                ->groupBy('item_id');

            // 14 singleton facets + 4 collection tables: one `DISTINCT
            // item_id` query per table, PER BATCH — not per item, and not
            // per table per item. Declaration order is part of the cached
            // value (I9), so it is preserved exactly below.
            $presentByItem = array_fill_keys($batch, []);
            foreach (array_keys(self::SINGLETON_FACETS) as $facet) {
                $ids = DB::table("content.{$facet}")->whereIn('item_id', $batch)->distinct()->pluck('item_id');
                foreach ($ids as $id) {
                    $presentByItem[(string) $id][] = $facet;
                }
            }
            // item_variants appended LAST on purpose: the comment above states
            // declaration order is part of the cached eligible_cache value
            // (I9). Inserting it among the existing entries would reshape the
            // cached value for every item in the database.
            //
            // collection_items is deliberately NOT in this list (slice 3b Task
            // 5). facets_cache has no correctness reader: nothing in app/
            // filters or branches on it — its only consumers are this method's
            // own change-detection and the DSAR export's column select
            // (DataExportPayloadBuilder). Serving reads collection membership
            // LIVE (SectionCandidates joins content.collection_items), so
            // adding it would buy no behaviour and would reshape the cached
            // value for every already-projected shop item, which
            // ShopContentWriter has been writing collection_items for since
            // slice 5a.
            foreach (['item_media', 'offers', 'item_tags', 'f_action', 'item_variants'] as $collection) {
                $ids = DB::table("content.{$collection}")->whereIn('item_id', $batch)->distinct()->pluck('item_id');
                foreach ($ids as $id) {
                    $presentByItem[(string) $id][] = $collection;
                }
            }

            $rowsById = DB::table('content.items')->whereIn('id', $batch)
                ->get(['id', 'user_id', 'kind', 'headline_cache', 'facets_cache'])->keyBy('id');

            // Every item in the batch is "seen" this run regardless of
            // whether its cache changed — one UPDATE for the whole batch.
            DB::table('content.items')->whereIn('id', $batch)->update([
                'last_seen_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($batch as $itemId) {
                $contributions = $contributionsByItem->get($itemId, collect())
                    ->map(fn (object $row) => new Contribution(
                        sourceId: (string) $row->source_id,
                        value: $row->headline,
                        sourcePriority: (int) $row->priority,
                        changedAt: $row->updated_at === null ? null : strtotime((string) $row->updated_at),
                    ))
                    ->all();

                $headline = $this->values->resolve('f_text', 'headline', $contributions);
                $present = $presentByItem[$itemId] ?? [];

                $row = $rowsById->get($itemId);
                if ($row === null) {
                    continue;
                }

                // PARITY TRAP: compare the DECODED array, never the raw
                // string. Postgres re-serialises jsonb with its own spacing
                // on every read, so a string compare would mismatch on
                // every run under Postgres while passing under SQLite (the
                // CLAUDE.md "tests run SQLite, prod is Postgres" hazard) —
                // silently defeating this skip in the environment it exists
                // to protect.
                $changed = $headline !== $row->headline_cache
                    || json_decode((string) $row->facets_cache, true) !== $present;

                if ($changed) {
                    DB::table('content.items')->where('id', $itemId)->update([
                        'headline_cache' => $headline,
                        'facets_cache' => json_encode($present),
                    ]);
                }

                // Mint / refresh the public URL slug whenever the headline
                // that names it is set or changes. THIS is the ongoing minter
                // for content.item_slugs: content:backfill-item-slugs seeds
                // history once, and without this every item landed afterwards
                // would serve a null slug forever. The retired lane had the
                // same continuous duty (IntegrationConnectionObserver →
                // EventSlugSync::syncEvents on every connect and refresh).
                //
                // Best-effort by design: slug bookkeeping must never fail a
                // projection run, and ensureCurrent() is a no-op when the live
                // slug already matches, so the common path costs one SELECT.
                if ($headline !== null && $headline !== ''
                    && in_array((string) $row->kind, ContentItemSlugAllocator::SLUGGED_KINDS, true)) {
                    try {
                        $this->slugs->ensureCurrent((string) $row->user_id, (string) $itemId, (string) $headline);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }
        }
    }

    /**
     * Build state ONLY — one lane, deliberately.
     *
     * Used by writeManualItem(), whose callers all batch the other two lanes
     * themselves via ManualServiceWriter::invalidate() (see its docblock: "the
     * two lanes it deliberately does not own"). Firing an edge purge per item
     * from here would issue one purge per written item instead of one per
     * request. The connector path has no such batching caller and uses
     * invalidateSiteLanes() below.
     */
    private function bumpSite(string $userId): void
    {
        // site.sites has no deleted_at — sites die by cascade, not soft delete.
        $siteId = DB::table('site.sites')->where('user_id', $userId)->value('id');
        if ($siteId !== null) {
            BuildState::bump((string) $siteId);
        }
    }

    /**
     * All three lanes, once per projected stream.
     *
     * projectStream() is the connector seam: nothing downstream of it batches
     * invalidation (RunExecutor and IngestProjectCommand touch ingest.* only),
     * so whatever it fires is the whole cache story for a scheduled run.
     */
    private function invalidateSiteLanes(string $userId): void
    {
        $siteId = DB::table('site.sites')->where('user_id', $userId)->value('id');
        if ($siteId !== null) {
            SiteCacheLanes::bust([(string) $siteId]);
        }
    }
}
