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
use App\Services\Site\AdvisoryLock;
use App\Services\Site\AdvisoryLockTimeoutException;
use App\Site\Documents\BuildState;
use App\Site\Documents\SiteCacheLanes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        'f_catalog' => ['release_type', 'track_number', 'disc_number', 'isrc', 'gtin', 'sku', 'handle', 'vendor', 'variant_ref', 'collection_title'],
        'f_place' => ['venue_name', 'address', 'locality', 'region', 'country_code', 'latitude', 'longitude'],
        'f_rated' => ['rating', 'rating_max', 'ratings_count'],
        // author_uri: slice 6 Task 1 (migration 20260813110000). singletonFacetRow
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

    /**
     * Bound for the "identity:{user_id}:{kind}" advisory lock (#LIFE-1). Matches
     * AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS: both bound an interactive dashboard write, and a
     * caller that waits longer than this is better off failing loudly (423 / a failed run the
     * scheduler retries) than holding a PHP-FPM worker open.
     *
     * MEASURED against a real Postgres on 2026-08-19, 2,000 live source items of one kind for
     * one user: 120ms for a steady-state pass (every coord already anchored — the normal case,
     * every scheduled run after the first), and 2,267ms for the cold pass that mints an item and
     * an anchor for all 2,000 at once. The cold figure is the one to watch: it is roughly linear
     * in unbound coords, so a first-ever projection of a ~4,500-item catalogue would sit on this
     * bound, and a concurrent caller would take the 423 rather than wait. That is the intended
     * failure — it happens once per catalogue, it retries, and the alternative is an unbounded
     * wait on a pooled Supavisor connection. If it becomes a real complaint, the fix is to batch
     * createItem()/the anchor insert across groups, not to widen this.
     *
     * NOTE the bound also applies to every subsequent row lock in the transaction: SET LOCAL
     * lock_timeout persists for the rest of it (see AdvisoryLockTimeoutException's docblock).
     */
    private const IDENTITY_LOCK_TIMEOUT_MS = 5000;

    public function __construct(
        private readonly Resolver $resolver,
        private readonly ValueResolver $values,
        private readonly ContentItemSlugAllocator $slugs,
        private readonly IdentityKeyDeriver $identityKeys,
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
        // processed oldest-first, and writeFacets()'s per-column array_replace
        // fold (#SCALE-5) lets the LAST-processed record win each column (I1)
        // — reordering the read silently changes which record's
        // headline/facets a page shows. NOTE the fold is where that invariant
        // lives NOW: it used to be a property of one upsert per record landing
        // in order, and batching moved it into writeFacets(). Ordering here is
        // still load-bearing; the code that depends on it has moved.
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
                [$id] = $this->upsertSourceItem(
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
        // identical across a run, mirroring the last-processed-record-wins
        // column semantics writeFacets()'s fold gives the singleton facets.
        //
        // EVERY column is written, including ones this run did not carry:
        // building the update list from the present keys alone would leave an
        // aggregate Google has stopped sending in place forever, because
        // nothing else clears this table (content:prune-orphaned-review-pii
        // touches f_review only, and the row goes only when content.sources
        // cascades). summary_text is Google's prose about the business, so
        // that is a retention question rather than a cosmetic one.
        //
        // A run carrying NO aggregates at all leaves $sourceStats null and
        // skips this entirely — the §5.2 zero-reviews gap, not a clear.
        if ($sourceStats !== null) {
            $columns = ['rating_avg' => null, 'rating_count' => null, 'summary_text' => null];

            DB::table('content.source_stats')->upsert(
                [$sourceStats + $columns + ['source_id' => $contentSourceId, 'updated_at' => now()]],
                ['source_id'],
                [...array_keys($columns), 'updated_at'],
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
        [$sourceItemId, $unbound] = DB::transaction(function () use ($contentSourceId, $coord, $kind, $projection) {
            [$sourceItemId, $unbound] = $this->upsertSourceItem(
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

            return [$sourceItemId, $unbound];
        });

        try {
            $itemByCoord = $this->resolveItems($userId, $kind);
        } catch (AdvisoryLockTimeoutException $e) {
            // #LIFE-1 made a lock timeout reachable HERE, between two committed states: the
            // transaction above has landed a live source item with its identity keys, and
            // resolveItems() rolled its own back without binding it to anything. Left alone that
            // row is picked up by the NEXT resolve for this (user, kind) and bound to a freshly
            // minted item — but writeFacets() never ran for it, so what the owner eventually
            // sees is a blank card they were told had failed to save.
            //
            // Only an UNBOUND row is retired — one carrying no item_id, so retiring it
            // destroys nothing. That covers both a row this call minted and one an earlier
            // failed resolve left behind, which matters because upsertSourceItem()'s update
            // branch clears removed_at: keyed on "did I create it", a second consecutive
            // timeout for one coord would un-retire the row and then walk straight past this.
            // A row that already carries an item_id is the owner's real content — an idempotent
            // re-add (MenuScanApplier, ShopContentWriter, every backfiller) must never have it
            // retired out from under them.
            //
            // Catching here is safe: the transaction above has COMMITTED and resolveItems()'
            // own has rolled back, so this runs outside every transaction and cannot poison one
            // (25P02). It rethrows — the caller still learns the write failed.
            if ($unbound) {
                DB::table('content.source_items')->where('id', $sourceItemId)->update(['removed_at' => now()]);
            }

            throw $e;
        }

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

    /**
     * @return array{0: string, 1: bool} [source item id, whether the row is bound to NO item]
     *
     * The flag exists for writeManualItem()'s compensation, and it is deliberately "unbound"
     * rather than "we created it". A row this call minted is unbound, but so is one an EARLIER
     * failed resolve left behind — including one the compensation itself retired, because the
     * update branch below clears removed_at on the retry. Keying on "created" therefore leaks
     * exactly the residue the compensation exists to remove, on the second consecutive failure
     * for one coord. A row that already carries an item_id is the owner's real content and is
     * never touched.
     */
    private function upsertSourceItem(
        string $contentSourceId,
        string $coord,
        ?string $streamId,
        ?string $recordKey,
        string $kind,
        int $projectorVersion,
    ): array {
        $existing = DB::table('content.source_items')
            ->where('source_id', $contentSourceId)
            ->where('coord', $coord)
            ->first(['id', 'item_id']);

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

            return [(string) $existing->id, $existing->item_id === null];
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
            ->first(['id', 'item_id']);

        if ($landed === null) {
            throw new \RuntimeException("Could not resolve a source item for coord {$coord}.");
        }

        // Reads item_id off the SAME re-read rather than a second query: insertOrIgnore may have
        // lost to a concurrent writer, and it is the PERSISTED row's binding that decides
        // whether retiring it would destroy anything.
        return [(string) $landed->id, $landed->item_id === null];
    }

    /**
     * Replace-set of the evidence this record carries (§5). What counts as
     * evidence lives in IdentityKeyDeriver — pure, so it is unit-testable
     * without a database; this method only persists what it returns.
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

        $rows = [];
        foreach ($this->identityKeys->derive($coord, $projection) as $key) {
            $rows[] = [
                'source_item_id' => $sourceItemId,
                'key_class' => $key->class->value,
                'key_value' => $key->value,
                'tier' => $key->class->tier()->value,
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
        // #LIFE-1: everything below is read -> compute -> write, and until this lock existed a
        // second caller could commit between the two ends of it. The damage is not theoretical:
        // mergeInto() hard-DELETEs the discarded item, so the loser's own final
        // `source_items.item_id` UPDATE either takes a 23503 on a row that no longer exists, or
        // — when curation spared the loser from the delete — silently reverts the merge and
        // leaves a coord pointing at an item nothing else agrees with. Both are reproduced in
        // tests/Postgres/ProjectionWriterIdentityRaceTest.php.
        //
        // Advisory rather than lockForUpdate(): the protected set is "every live source item of
        // this (user, kind)", which the racing writer may GROW mid-computation, and you cannot
        // lock rows that do not exist yet. It is also three tables (item_anchors, items,
        // source_items), so one key beats an ordering discipline across all three.
        //
        // _xact_ rather than the session variant: it releases on COMMIT/ROLLBACK, so a killed
        // worker cannot wedge a user's identity resolution until Supavisor reaps the connection.
        //
        // The transaction spans the WHOLE body — the reads, the resolve, bindGroup(),
        // recordCandidates() AND the final per-target UPDATE loop. A lock that covers the read
        // but not the write looks right, tests green, and fixes nothing.
        //
        // Callers must NOT wrap this: DB::transaction() would degrade to a SAVEPOINT and the
        // lock would silently take the OUTER transaction's lifetime. Neither call site does
        // (projectStream()'s transactions are per-record and closed; writeManualItem()'s closes
        // before this), and every caller of writeManualItem() is already forbidden from nesting
        // one by that method's own docblock.
        //
        // No try/catch inside: a catch that RECOVERS in here poisons the transaction with 25P02
        // (this repo has shipped that three times — ItemSlugAllocatorSavepointTest). The
        // lock-timeout path throws out through the transaction, as Lander::land() does, and
        // surfaces as 423 via AdvisoryLockTimeoutException's HttpStatusCodeInterface contract.
        //
        // SQLite has neither pg_advisory_xact_lock nor hashtext, so the Feature lane cannot
        // exercise this at all — a green `composer test` says nothing about it. tests/Postgres/
        // is where it is proven.
        $connection = DB::connection();
        $key = "identity:{$userId}:{$kind}";

        try {
            return $connection->transaction(function () use ($connection, $key, $userId, $kind) {
                if ($connection->getDriverName() === 'pgsql') {
                    AdvisoryLock::acquire($key, self::IDENTITY_LOCK_TIMEOUT_MS, $connection->getName());
                }

                return $this->resolveItemsLocked($userId, $kind);
            });
        } catch (QueryException $e) {
            // OUTSIDE the transaction — DB::transaction() has already rolled back, so this
            // cannot poison anything (25P02). It converts, it does not recover.
            //
            // SET LOCAL lock_timeout bounds every lock the transaction takes, not just the
            // advisory one, and a ROW lock aborting raises the same SQLSTATE 55P03 as a bare
            // QueryException rather than as AdvisoryLockTimeoutException — the closing
            // `UPDATE content.source_items` waiting on a concurrent writeManualItem()'s own
            // transaction is the reachable case. Undistinguished, that path skipped
            // writeManualItem()'s compensation and surfaced as a 500 for what is ordinary
            // contention. ReorderService::reorder() already reclassifies its row-lock timeout
            // through this same helper for the same reason.
            if (AdvisoryLock::isLockTimeout($e)) {
                throw new AdvisoryLockTimeoutException($key, $e);
            }

            throw $e;
        }
    }

    /**
     * The body of resolveItems(), which must only ever run under its lock and transaction.
     *
     * @return array<string, string> coord => item id
     */
    private function resolveItemsLocked(string $userId, string $kind): array
    {
        // Deterministic group order (recommended, plan §b.3): no user-visible
        // change on its own (bindGroup() picks the winner by bound_at), but
        // makes fresh-item UUID mint order reproducible instead of
        // heap-order dependent — covered by the golden test above.
        // A source whose connection the owner removed is history (disconnect
        // = hide): its listings must not vote on identity — the old Apple
        // connection's five compilation copies of one song poisoned the
        // title|artist key and kept the live Spotify ↔ Apple pair apart
        // (listen restructure 2026-08-18, caught live). Manual sources have
        // no connection and always take part.
        $liveSource = fn ($q) => $q->where(fn ($w) => $w->whereNull('cs.connection_id')
            ->orWhereExists(fn ($e) => $e->from('site.platform_connections as lpc')
                ->whereColumn('lpc.id', 'cs.connection_id')
                ->whereNull('lpc.deleted_at')));

        $rows = DB::table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('cs.user_id', $userId)
            ->where('si.kind', $kind)
            ->whereNull('si.removed_at')
            ->tap($liveSource)
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
            ->whereIn('source_item_id', function ($sub) use ($userId, $kind, $liveSource) {
                $sub->select('si.id')->from('content.source_items as si')
                    ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
                    ->where('cs.user_id', $userId)
                    ->where('si.kind', $kind)
                    ->tap($liveSource)
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

        // #SCALE-4: bindGroup() used to read content.item_anchors once PER GROUP, and in steady
        // state every group is a singleton — one round trip per item, on every run. The read is
        // hoisted here instead.
        //
        // It is only safe because of the lock above, and only THEN with the guard below. Under
        // the lock no other writer can cross the loop, but the loop stales its own snapshot:
        // mergeInto() runs
        //   UPDATE item_anchors SET superseded_by = kept WHERE item_id = ? OR superseded_by = ?
        // which is keyed on the ITEM, not on the current group's coords, so it can rewrite
        // anchors that a LATER group is about to read. Groups are disjoint by coord, but the
        // items they point at are not. So: any merge invalidates the snapshot outright and every
        // remaining group re-reads exactly as it used to. Steady state is all-singleton groups
        // with no merges at all, which is where the N+1 actually hurt.
        //
        // Mirroring that UPDATE's semantics onto the in-memory snapshot instead would keep the
        // saving through a merge — and would mean reimplementing a WHERE clause in PHP against
        // the identity spine, where mergeInto()'s hard delete makes a wrong answer only
        // partially reversible. Not worth it for a case that barely happens.
        $snapshot = $this->anchorSnapshot($userId, $resolution->groups);

        $itemByCoord = [];
        foreach ($resolution->groups as $group) {
            [$itemId, $merged] = $this->bindGroup($userId, $kind, $group, $snapshot);
            if ($merged) {
                $snapshot = null;
            }
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
            ->whereIn('id', function ($sub) use ($userId, $kind, $liveSource) {
                $sub->select('si.id')->from('content.source_items as si')
                    ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
                    ->where('cs.user_id', $userId)
                    ->where('si.kind', $kind)
                    ->tap($liveSource)
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
     * @param  array<string, object>|null  $snapshot  the run-wide anchor
     *                                                prefetch (#SCALE-4),
     *                                                or null to read fresh
     * @return array{0: string, 1: bool} [item id, whether this call merged and so staled $snapshot]
     */
    private function bindGroup(string $userId, string $kind, array $group, ?array $snapshot = null): array
    {
        // Unordered on purpose — sortAnchors() below is the ONE ordering, shared with the
        // #SCALE-4 prefetch path. An `ORDER BY coord` here would sort under the DATABASE
        // COLLATION while the snapshot path sorts byte-wise in PHP, so on a bound_at tie the two
        // paths can rank the same pair differently ('yt:acct:AB-1' vs 'yt:acct:ab_1' invert
        // between en_US.utf8 and byte order). Which path serves a group depends on whether an
        // unrelated earlier group merged — so that would make the identity of the item
        // mergeInto() HARD-DELETES depend on something the group has nothing to do with.
        $anchors = $this->sortAnchors($snapshot === null
            ? DB::table('content.item_anchors')
                ->where('user_id', $userId)
                ->whereIn('coord', $group)
                ->get(['coord', 'item_id', 'superseded_by', 'bound_at'])
            : $this->anchorsFromSnapshot($snapshot, $group));

        // Set by every path that calls mergeInto() (or deletes an item): those rewrite anchors
        // this call does not own, so the caller's prefetch cannot be trusted afterwards.
        $merged = false;

        $effective = $anchors
            ->map(fn (object $a) => (string) ($a->superseded_by ?? $a->item_id))
            ->unique()
            ->values();

        $winner = $this->preferOwnerAnchored($effective) ?? $effective->first();

        $mintedOwnItem = false;
        if ($winner === null) {
            $winner = $this->createItem($userId, $kind);
            $mintedOwnItem = true;
        }

        // #PGR-7: this insert used to be bare. A concurrent bindGroup() call for the SAME coord
        // (writeManualItem() double-submit — two tabs, or a double-clicked "Add" in
        // PoolItemCreateController, both deriving the identical deterministic coord) can race
        // it: both callers read an empty $anchors above, both mint their OWN $winner via
        // createItem(), and both attempt to bind the same coord — content.item_anchors' PK is
        // (user_id, coord), so at most one insert survives and the loser used to surface an
        // uncaught 23505 plus an orphaned content.items row. insertOrIgnore turns the loser's
        // insert into a no-op; $boundHere tracks which of THIS call's own coords actually landed
        // under $winner, so a conflict elsewhere in the same multi-coord group can redirect them
        // too, not just discard an item other rows already point at.
        // #CACHE-5: one multi-row insertOrIgnore for the group, not one statement per coord.
        // The reconciliation below is unchanged in outcome — it just learns WHICH coords lost
        // from a single re-read instead of from the return value of each individual insert.
        $missing = array_values(array_filter(
            $group,
            fn (string $coord) => $anchors->firstWhere('coord', $coord) === null,
        ));

        $boundHere = [];
        $lostTo = null;
        if ($missing !== []) {
            // One timestamp for the whole group rather than one per row: they all bind the same
            // winner, so bound_at cannot order them against each other in any way that matters.
            $boundAt = now();
            $inserted = DB::table('content.item_anchors')->insertOrIgnore(array_map(fn (string $coord) => [
                'coord' => $coord,
                'user_id' => $userId,
                'item_id' => $winner,
                'bound_at' => $boundAt,
            ], $missing));

            if ($inserted === count($missing)) {
                $boundHere = $missing;
            } else {
                // At least one coord lost. Re-read what actually persisted and adopt ITS item id
                // — never the locally-computed $winner, which by definition lost. Getting this
                // backwards would leave this caller returning an item id nothing else agrees
                // with, which is worse than the 500 it replaces. Iterating $missing in order
                // keeps last-loss-wins, exactly as the per-coord loop did. (A second coord in
                // the same group losing to a THIRD, different item is still not reconciled —
                // one conflicting winner per call is the shape #PGR-7 fixes; that deeper case is
                // not known to be reachable.)
                $persisted = DB::table('content.item_anchors')
                    ->where('user_id', $userId)
                    ->whereIn('coord', $missing)
                    ->pluck('item_id', 'coord');

                foreach ($missing as $coord) {
                    $persistedId = (string) ($persisted[$coord] ?? '');
                    if ($persistedId === $winner) {
                        $boundHere[] = $coord;

                        continue;
                    }
                    $lostTo = $persistedId;
                }
            }
        }

        if ($lostTo !== null && $lostTo !== $winner) {
            if ($boundHere !== []) {
                // Some of this group's OTHER coords already landed under our own (now-losing)
                // $winner earlier in this same loop — redirect them to the agreed winner first,
                // or the cascade delete below would take their anchor rows down with it.
                DB::table('content.item_anchors')
                    ->where('user_id', $userId)
                    ->whereIn('coord', $boundHere)
                    ->update(['item_id' => $lostTo]);
            }

            if ($mintedOwnItem) {
                // $winner was minted fresh in this call. Nothing referenced it outside the
                // item_anchors rows this loop just redirected away — no facets, no
                // source_items.item_id, no curation exist yet — so it is safe to discard
                // outright rather than route through the full mergeInto() ceremony.
                DB::table('content.items')->where('id', $winner)->delete();
            } else {
                $this->mergeInto($userId, keptItemId: $lostTo, discardedItemId: $winner);
            }

            // Both branches touch anchors outside this group's coords (the delete cascades),
            // so the caller's #SCALE-4 prefetch is stale from here on.
            $merged = true;
            $winner = $lostTo;
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
            $merged = true;
        }

        return [$winner, $merged];
    }

    /**
     * Every anchor for every coord this pass will bind, read once (#SCALE-4).
     *
     * Keyed by coord, which is exact rather than convenient: content.item_anchors' PK is
     * (user_id, coord), so there is at most one row per coord. Ordering is applied per group in
     * anchorsFromSnapshot() instead of here, because chunking would split a single ORDER BY.
     *
     * @param  list<list<string>>  $groups
     * @return array<string, object>
     */
    private function anchorSnapshot(string $userId, array $groups): array
    {
        $coords = $groups === [] ? [] : array_values(array_unique(array_merge(...$groups)));

        $snapshot = [];

        // Chunked for the same reason every other bind list in this file is: BATCH_SIZE keeps the
        // parameter count far under Postgres' 65,535 ceiling on a large catalogue.
        foreach (array_chunk($coords, self::BATCH_SIZE) as $chunk) {
            $rows = DB::table('content.item_anchors')
                ->where('user_id', $userId)
                ->whereIn('coord', $chunk)
                ->get(['coord', 'item_id', 'superseded_by', 'bound_at']);

            foreach ($rows as $row) {
                $snapshot[(string) $row->coord] = $row;
            }
        }

        return $snapshot;
    }

    /**
     * This group's slice of the prefetch, unordered — bindGroup() sorts.
     *
     * @param  array<string, object>  $snapshot
     * @param  list<string>  $group
     * @return Collection<int, object>
     */
    private function anchorsFromSnapshot(array $snapshot, array $group): Collection
    {
        $rows = [];
        foreach ($group as $coord) {
            if (isset($snapshot[$coord])) {
                $rows[] = $snapshot[$coord];
            }
        }

        return collect($rows);
    }

    /**
     * Oldest binding first — the order that decides which item survives a merge.
     *
     * This is load-bearing, not cosmetic: $effective's first element is the winner whenever
     * preferOwnerAnchored() abstains, and mergeInto() HARD-DELETES every other item in the
     * group. It lives in PHP, applied identically to the prefetched slice and to the fresh
     * per-group read, because those two are served by different code paths within one pass and
     * an ordering that differed between them would make a destructive choice depend on which
     * path happened to run.
     *
     * TIES ARE ROUTINE. Laravel's query grammar formats every timestamp binding as
     * 'Y-m-d H:i:s' (Grammar::getDateFormat(), not overridden by PostgresGrammar), so bound_at
     * is stored truncated to WHOLE SECONDS whatever Carbon held — two anchors written a
     * millisecond apart, by different calls, to different items, compare equal. coord breaks
     * that tie: arbitrary, but the same arbitrary answer every time and on every path, where
     * a bare `ORDER BY bound_at` left it to whatever the planner returned.
     *
     * So "oldest binding wins" is only true to the second. That is a property of the column
     * rather than of this method, and it is written down because the hard delete rests on it.
     *
     * @param  Collection<int, object>  $anchors
     * @return Collection<int, object>
     */
    private function sortAnchors(Collection $anchors): Collection
    {
        return $anchors
            ->sort(fn (object $a, object $b) => [(string) $a->bound_at, (string) $a->coord]
                <=> [(string) $b->bound_at, (string) $b->coord])
            ->values();
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
        // whole run's items. The singleton facets below are batched too, on
        // their own boundary (#SCALE-5, next block).
        $this->replaceCollections($contentSourceId, $userId, $byItem);

        // #SCALE-5: collect first, write once per (facet, column-signature).
        // This used to fire one upsert per facet per record, every run — the
        // comment above conceded it, in those words: "the singleton-facet
        // upserts below stay per (item, record)". For a catalogue sync that is
        // one round trip per facet per item, and it dominated the run.
        //
        // The FOLD is per COLUMN, not per row. Sequentially, a second record for
        // the same (item, source) overwrote only the columns it named, so the
        // stored row ended up a per-column union with later values winning.
        // Last-row-wins would silently drop columns only the earlier record
        // carried; array_replace reproduces the old result exactly. It also
        // removes the batching hazard: two rows with the same conflict target in
        // ONE upsert payload raise Postgres 21000, which is precisely what
        // LanderBatchLandingTest already pins for ingest.record_state.
        $pending = [];
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
                    $row = $this->singletonFacetRow((string) $facet, (array) $columns);
                    if ($row === []) {
                        continue;
                    }

                    $pending[(string) $facet][(string) $itemId] = array_replace(
                        $pending[(string) $facet][(string) $itemId] ?? [],
                        $row,
                    );
                }
            }
        }

        $this->flushSingletonFacets($contentSourceId, $pending);
    }

    /**
     * One upsert per (facet, column-signature) — #SCALE-5.
     *
     * Bucketed by signature and NOT unioned into one wide row, deliberately.
     * Laravel's upsert() takes its column list from the FIRST row, so a union
     * with null-fill would put columns a given record never mentioned into the
     * update list and NULL them on conflict — clobbering a value another source
     * legitimately wrote. Same-shaped rows batch; differently-shaped ones get
     * their own statement, which is still a handful instead of one per record.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $pending  facet => itemId => row
     */
    private function flushSingletonFacets(string $contentSourceId, array $pending): void
    {
        // ONE timestamp for the whole flush, mirroring the run-wide stamping the
        // rest of this class uses — a row's updated_at should say "this run",
        // not which microsecond inside it.
        $now = now();

        foreach ($pending as $facet => $rowsByItem) {
            $buckets = [];
            foreach ($rowsByItem as $itemId => $row) {
                // ksort so the signature is order-independent: two records that
                // carry the same columns in a different order belong in the same
                // batch, not in two.
                ksort($row);
                $buckets[implode(',', array_keys($row))][] = $row + [
                    'item_id' => (string) $itemId,
                    'source_id' => $contentSourceId,
                    'updated_at' => $now,
                ];
            }

            foreach ($buckets as $rows) {
                DB::table("content.{$facet}")->upsert(
                    $rows,
                    ['item_id', 'source_id'],
                    array_values(array_diff(array_keys($rows[0]), ['item_id', 'source_id'])),
                );
            }
        }
    }

    /**
     * The storable row for one facet contribution: allowlisted columns only,
     * URLs minimised, arrays encoded. Extracted from singletonFacetRow() by
     * #SCALE-5 so the batching path and the single-row path cannot disagree
     * about what a facet row IS — the allowlist and the URL denylist are
     * security-relevant and a second copy of them is a liability.
     *
     * @param  array<string, mixed>  $columns
     * @return array<string, mixed>
     */
    private function singletonFacetRow(string $facet, array $columns): array
    {
        $allowed = self::SINGLETON_FACETS[$facet] ?? null;
        if ($allowed === null) {
            return [];
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

        return $row;
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

        // Membership positions (F30/F31, 2026-08-18): `position` here is the
        // item's rank INSIDE the category — what the wire's collectionPositions
        // and the Categories sheet read. Rules, in order:
        //   1. an existing membership keeps its position — the owner may have
        //      dragged it (this table is delete+reinsert per source per run,
        //      so without carrying it over every sync snapped the owner's
        //      arrangement back);
        //   2. a NEW membership in a CURATED category (any non-zero position
        //      already there) appends at the end — its vendor index would
        //      interleave with the owner's order;
        //   3. otherwise the vendor's `item_position` seeds it (falling back to
        //      the old per-item counter for projectors that carry none);
        //   4. an UNCURATED category (duplicate positions — every row 0 was the
        //      pre-fix state on every live category; a half-seeded one has 0s
        //      beside a run of seeds) is re-seeded from the vendor order so it
        //      heals on the next run. "Curated" = distinct positions, not all
        //      zero — what an owner's drag writes (0..n-1).
        $collectionItemRows = [];
        $memberships = [];
        foreach ($collectionsByItem as $itemId => $entries) {
            $position = 0;
            foreach ($entries as $entry) {
                $entry = (array) $entry;
                $key = ((string) ($entry['kind'] ?? 'collection'))."\0".((string) ($entry['external_ref'] ?? ''));
                $collectionId = $collectionIds[$key] ?? null;
                if ($collectionId === null) {
                    continue;
                }
                $memberships[] = [
                    'collection_id' => (string) $collectionId,
                    'item_id' => (string) $itemId,
                    'seed' => isset($entry['item_position']) && is_numeric($entry['item_position']) ? (int) $entry['item_position'] : $position,
                ];
                $position++;
            }
        }
        [$collectionItemRows, $reseeds] = $this->positionMemberships($memberships, $contentSourceId);

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
            $itemIdSet = array_fill_keys($itemIds, true);
            $chunkReseeds = array_values(array_filter($reseeds, fn ($r) => isset($itemIdSet[$r['item_id']])));
            DB::transaction(function () use ($tables, $itemIds, $contentSourceId, $chunk, $chunkReseeds) {
                foreach ($tables as $table => $rows) {
                    DB::table("content.{$table}")
                        ->whereIn('item_id', $itemIds)
                        ->where('source_id', $contentSourceId)
                        ->delete();

                    foreach (array_chunk($rows, $chunk) as $rowChunk) {
                        if ($table === 'collection_items') {
                            // Membership is per (collection, item) — the PK.
                            // Another source (the legacy menu scrape's manual
                            // source, an ordering platform's own run) may
                            // already list this dish in this category; that
                            // row is the same fact, so keep it rather than
                            // fail the whole chunk (overnight 2026-08-18: RÜH's
                            // Uber Eats run projected 137 dishes and landed 0
                            // memberships on a duplicate-key error).
                            DB::table("content.{$table}")->insertOrIgnore($rowChunk);

                            continue;
                        }
                        DB::table("content.{$table}")->insert($rowChunk);
                    }
                }
                // Uncurated re-seed (F30): rows another source owns are not
                // ours to reinsert, but their in-category order is still the
                // vendor's to set until an owner touches it.
                foreach ($chunkReseeds as $r) {
                    DB::table('content.collection_items')
                        ->where('collection_id', $r['collection_id'])
                        ->where('item_id', $r['item_id'])
                        ->update(['position' => $r['position']]);
                }
            });
        }
    }

    /**
     * Resolve each membership's `position` per the rules in replaceCollections
     * (existing kept, curated appends, vendor seed, uncurated re-seed) and
     * return the rows grouped by item id, ready to insert. One read of the
     * touched categories' current rows, no per-row queries.
     *
     * Also returns the re-seed updates for uncurated categories: a membership
     * row may live under ANOTHER source (the legacy menu lane's manual source
     * lists the same dish in the same category), so the insertOrIgnore below
     * cannot move it — an explicit UPDATE can.
     *
     * @param  list<array{collection_id: string, item_id: string, seed: int}>  $memberships
     * @return array{array<string, list<array<string, mixed>>>, list<array{collection_id: string, item_id: string, position: int}>}
     */
    private function positionMemberships(array $memberships, string $contentSourceId): array
    {
        if ($memberships === []) {
            return [[], []];
        }
        $collectionIds = array_values(array_unique(array_column($memberships, 'collection_id')));
        $existing = [];   // collection_id => [item_id => position]
        foreach (array_chunk($collectionIds, $this->writeChunk()) as $chunk) {
            foreach (DB::table('content.collection_items')->whereIn('collection_id', $chunk)->get(['collection_id', 'item_id', 'position']) as $row) {
                $existing[(string) $row->collection_id][(string) $row->item_id] = (int) $row->position;
            }
        }

        $rows = [];
        $reseeds = [];
        $appendNext = [];
        foreach ($memberships as $m) {
            $current = $existing[$m['collection_id']] ?? [];
            // "Curated" = every position distinct and not all zero — what an
            // owner's drag writes (0..n-1) and what a full vendor seed leaves.
            // Duplicates (seven 0s beside 5..10 after one of two lanes seeded)
            // mean nobody has arranged this category yet.
            $curated = count($current) > 1 && max($current) > 0 && count(array_unique($current)) === count($current);
            $uncurated = count($current) > 1 && ! $curated;
            if (isset($current[$m['item_id']]) && ! $uncurated) {
                $position = $current[$m['item_id']];
            } elseif ($curated && ! isset($current[$m['item_id']])) {
                $appendNext[$m['collection_id']] ??= max($current) + 1;
                $position = $appendNext[$m['collection_id']]++;
            } else {
                $position = $m['seed'];
                if ($uncurated && isset($current[$m['item_id']]) && $position !== 0) {
                    $reseeds[] = ['collection_id' => $m['collection_id'], 'item_id' => $m['item_id'], 'position' => $position];
                }
            }
            $rows[$m['item_id']][] = [
                'collection_id' => $m['collection_id'],
                'item_id' => $m['item_id'],
                'source_id' => $contentSourceId,
                'position' => $position,
            ];
        }

        return [$rows, $reseeds];
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

        // Uncurated re-seed (F30, 2026-08-18): a kind whose collections ALL
        // sit at position 0 (every Fresha / menu category before the seeds
        // existed) has never been arranged by anyone — take the vendor's
        // order now. Once any position is non-zero the owner (or a seed) owns
        // the order and this never runs again. Same rule as memberships.
        foreach (array_unique(array_column(array_values($wanted), 'kind')) as $kind) {
            $seeded = array_filter($wanted, fn ($w) => $w['kind'] === $kind && $w['position'] > 0);
            if ($seeded === []) {
                continue;
            }
            // Machine-derived, live rows only: an owner-created category is
            // appended at position n by the sheet and says nothing about
            // whether the vendor's own categories were ever arranged.
            $positions = DB::table('content.collections')
                ->where('user_id', $userId)->where('kind', $kind)
                ->where('is_user_created', false)->whereNull('removed_at')
                ->pluck('position');
            if ($positions->count() < 2 || $positions->max() > 0) {
                continue;
            }
            foreach ($seeded as $w) {
                DB::table('content.collections')
                    ->where('user_id', $userId)->where('kind', $kind)->where('external_ref', $w['external_ref'])
                    ->update(['position' => $w['position']]);
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
     * ONE implementation, now in MediaFingerprint: the bulk resolve, the row
     * build and IdentityKeyDeriver's ContentDigest must never disagree about
     * what an entry's fingerprint is.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: ?string, 1: ?string} [fingerprint, minimised source url]
     */
    private function mediaFingerprint(array $entry): array
    {
        return MediaFingerprint::for($entry);
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
            // Nothing to mint — but the mirror pass still runs: an asset can
            // pre-exist unmirrored (the legacy Instagram seeder mints the same
            // fingerprints first), and returning here left 86 of 88 Instagram
            // frames on hotlinked CDN urls forever (overnight 2026-08-18 F14).
            // dispatchMirrors() re-checks storage_path IS NULL itself.
            $this->dispatchMirrors($userId, $entryByFingerprint, $byFingerprint, $chunk);

            return $byFingerprint;
        }

        $rows = [];
        foreach ($missing as $fingerprint => [$entry, $url]) {
            $uploadSiteMediaId = MediaFingerprint::uploadSiteMediaId($entry);
            $isUpload = $uploadSiteMediaId !== null;
            // A music-CDN url that encodes its size (Apple 1200x1200bb, Spotify
            // b273 = 640, Bandcamp _10 = 1200…) is a declared dimension too:
            // without it every cover was "unknown" and best-cover fell back to
            // source priority (session 3, 2026-08-18).
            if (! $isUpload && ! isset($entry['width']) && is_string($url) && ($dims = ArtworkDims::infer($url)) !== null) {
                [$entry['width'], $entry['height']] = $dims;
            }
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
                // Owned-ness has to be recorded HERE because this is the last
                // place it is knowable: the fingerprint stored beside it is
                // 'url-'||sha1(ref), and sha1 does not run backwards. Without
                // it "no bytes" cannot be told apart from "no bytes, correctly"
                // — which made the R8 tail query over-report ~10x on dev.
                'mirror_eligible' => MediaMirror::isOwnedEntry($entry),
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
        // R8. Every exclusion below is COUNTED, not merely skipped. A silent
        // `continue` here was indistinguishable downstream from a job that had
        // been queued and not yet run — which is how a build wave finished with
        // 32 unmirrored assets and one warning line to explain them.
        $skipped = [];
        $candidates = [];
        $ownedAssetIds = [];
        $borrowedAssetIds = [];
        foreach ($entryByFingerprint as $fingerprint => [$entry, $_minimisedUrl]) {
            $assetId = $assetIdByFingerprint[$fingerprint] ?? null;
            $owned = MediaMirror::isOwnedEntry($entry);
            // Collected for BOTH classes, before any of the skips below, so a
            // borrowed row minted before mirror_eligible existed still gets its
            // false — otherwise the column would only ever heal one way and the
            // NULLs that remained would be indistinguishable from unknowns.
            if ($assetId !== null) {
                if ($owned) {
                    $ownedAssetIds[] = (string) $assetId;
                } else {
                    $borrowedAssetIds[] = (string) $assetId;
                }
            }

            $rawUrl = $entry['url'] ?? null;
            if (! is_string($rawUrl) || $rawUrl === '') {
                $skipped['no_url'] = ($skipped['no_url'] ?? 0) + 1;

                continue;
            }
            // Borrowed media (a Google Places photo) is CORRECT to skip — the
            // licence forbids storing it. Counted so "correctly skipped" and
            // "wrongly dropped" stop looking the same from outside.
            if (! $owned) {
                $skipped['not_owned'] = ($skipped['not_owned'] ?? 0) + 1;

                continue;
            }
            if ($assetId === null) {
                $skipped['unresolved_asset'] = ($skipped['unresolved_asset'] ?? 0) + 1;

                continue;
            }
            $candidates[(string) $assetId] = $rawUrl;
        }

        $this->healMirrorEligible($ownedAssetIds, $borrowedAssetIds, $chunk);

        $dispatched = 0;
        $max = MediaMirror::maxAttempts();

        foreach (array_chunk($candidates, $chunk, true) as $slice) {
            // Reading the discriminating columns rather than filtering them
            // away in SQL: the same query then answers "which of these needs a
            // mirror" AND "why not" for the rest, at no extra round-trip.
            $rows = DB::table('content.media_assets')
                ->whereIn('id', array_keys($slice))
                ->get(['id', 'storage_path', 'site_media_id', 'source_url', 'mirror_attempts']);

            foreach ($rows as $row) {
                $assetId = (string) $row->id;
                if ($row->storage_path !== null) {
                    $skipped['already_mirrored'] = ($skipped['already_mirrored'] ?? 0) + 1;

                    continue;
                }
                if ($row->site_media_id !== null) {
                    $skipped['upload'] = ($skipped['upload'] ?? 0) + 1;

                    continue;
                }
                if ($row->source_url === null) {
                    $skipped['no_source_url'] = ($skipped['no_source_url'] ?? 0) + 1;

                    continue;
                }
                // The retry terminator. storage_path IS NULL never becomes
                // non-null for a link that cannot be fetched, so without this
                // a dead CDN url is re-fetched on every sync forever. Any
                // success resets the counter, so this only ends a RUN of
                // consecutive failures.
                if ((int) $row->mirror_attempts >= $max) {
                    $skipped['capped'] = ($skipped['capped'] ?? 0) + 1;

                    continue;
                }

                // ::dispatch(), never Bus::dispatch(new ...) — the latter
                // silently drops ShouldBeUnique.
                MirrorMediaAssetJob::dispatch($userId, $assetId, $slice[$assetId]);
                $dispatched++;
            }
        }

        if ($dispatched === 0 && $skipped === []) {
            return;
        }

        // info, not debug: this is the line that makes the unmirrored tail
        // readable in a log capture. It is one line per projection pass, not
        // per asset. Note prod's LOG_LEVEL defaults to `warning` and would drop
        // it — which is why the durable record is the row, not this.
        Log::info('media_mirror.dispatch', [
            'user_id' => $userId,
            'dispatched' => $dispatched,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Re-derive mirror_eligible for rows that predate the column (20260819004000).
     *
     * `WHERE mirror_eligible IS NULL` is what makes this a one-time repair per
     * row rather than an UPDATE of every asset on every projection pass — and
     * it also means a value already written at mint is never second-guessed
     * here, where the entry is the only evidence available.
     *
     * @param  list<string>  $ownedAssetIds
     * @param  list<string>  $borrowedAssetIds
     */
    private function healMirrorEligible(array $ownedAssetIds, array $borrowedAssetIds, int $chunk): void
    {
        foreach ([[true, $ownedAssetIds], [false, $borrowedAssetIds]] as [$eligible, $ids]) {
            foreach (array_chunk($ids, $chunk) as $batch) {
                DB::table('content.media_assets')
                    ->whereIn('id', $batch)
                    ->whereNull('mirror_eligible')
                    ->update(['mirror_eligible' => $eligible]);
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
    /**
     * Public repair entry (X4, overnight 2026-08-18): one dish of 42 had
     * f_text.headline but a NULL headline_cache — the caches are only rebuilt
     * inside a projection run, so a row that missed its refresh stayed
     * "Untitled" forever. `content:refresh-item-caches` finds and heals such
     * rows through this.
     *
     * @param  list<string>  $itemIds
     */
    public function refreshCachesFor(string $userId, array $itemIds): void
    {
        $this->refreshItemCaches($userId, $itemIds);
    }

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
     *
     * Owner ruling (2026-08-17, #PGR-6): the three-lane contract IS required
     * for owner-initiated pool mutations, and this method's single-lane split
     * SURVIVES that ruling rather than being folded into it. bumpSite() is a
     * per-item primitive — its batch callers (MenuScanApplier,
     * ShopContentWriter::syncProducts, the backfillers) call writeManualItem()
     * once PER ROW, so firing lanes 2+3 here would issue N sites.updated_at
     * writes and N edge purges for one request where one of each is correct.
     * The lanes are discharged once, at the request boundary, by the caller.
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
