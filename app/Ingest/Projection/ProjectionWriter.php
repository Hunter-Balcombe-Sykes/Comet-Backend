<?php

namespace App\Ingest\Projection;

use App\Content\Identity\Candidate;
use App\Content\Identity\Decision;
use App\Content\Identity\IdentityKey;
use App\Content\Identity\IdentityScope;
use App\Content\Identity\KeyClass;
use App\Content\Identity\Resolver;
use App\Content\Identity\SourceItem;
use App\Content\Values\Contribution;
use App\Content\Values\ValueResolver;
use App\Exceptions\Ingest\FacetTargetLineageLostException;
use App\Exceptions\Ingest\FacetTargetMergedAwayException;
use App\Exceptions\Ingest\MergeFoldMediaDroppedException;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
        'f_review' => ['author_name', 'author_photo_url', 'author_uri', 'rating', 'text', 'reviewed_at', 'staff_name'],
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
        // author_uri is the reviewer's permanent Google contributor-profile
        // link — the same class of permanent identifier as author_photo_url,
        // and withheld from DSAR export for exactly that reason
        // (DataExportPayloadBuilder::streamContentFReview). No dev row carries
        // a query string today; this is the guard for the day one does.
        'f_review' => ['author_photo_url', 'author_uri'],
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
     *
     * WHAT A TIMEOUT COSTS, per path. writeManualItem() runs its upsert, keys and resolve as ONE
     * transaction under this lock, so a timeout there rolls everything back and the owner simply
     * sees a 423 to retry. projectStream() cannot: it commits per record across a lazy(500) loop
     * before it resolves, so a timeout there leaves live source items bound to nothing until the
     * next scheduled run re-projects them — and RunExecutor catches the throw and writes an
     * ingest.anomalies row with severity 'critical', which per that file's own comment PAGES once
     * per failure. So on the connector lane this is not the quiet retry the paragraph above
     * describes. Left that way deliberately: a connector run repeats on a schedule, and
     * suppressing the page would hide genuine contention on the identity spine.
     */
    private const IDENTITY_LOCK_TIMEOUT_MS = 5000;

    /**
     * Postgres foreign_key_violation. The retarget path below is Postgres-ONLY by construction:
     * SQLite reports 23000/HY000 for the same violation and has neither advisory locks nor the
     * concurrency to reach the race, so a green `composer test` says nothing about any of it.
     * tests/Postgres/ProjectionWriterIdentityRaceTest.php is where it is proven.
     */
    private const FK_VIOLATION_SQLSTATE = '23503';

    /**
     * Merge lineage hop bound. Chains are real — mergeInto(kept: Y, discarded: X) leaves Y an
     * ordinary item a later pass may itself discard into Z — but they are short. Past this,
     * something wrote the ledger that should not have.
     */
    private const MERGE_LINEAGE_MAX_HOPS = 8;

    /**
     * #SCALE-8: the ONLY keys writeFacets()/replaceCollections() ever read off a
     * projection — see writeFacets() (facets, headline) and replaceCollections()
     * (media, offers, tags, variants, collections). 'kind' and 'source_stats' are
     * both consumed earlier in the loop (upsertSourceItem()/writeIdentityKeys(),
     * and the $sourceStats capture) and never read again, so they are dropped
     * before the projection joins $projections. See forAccumulator()'s docblock
     * for why this — not a count-bounded flush — is the fix.
     */
    private const PROJECTION_ACCUMULATOR_KEYS = ['facets', 'headline', 'media', 'offers', 'tags', 'variants', 'collections'];

    /**
     * Test-visible high-water mark (bytes) of a single stored projection, across
     * the most recent projectStream() call. #SCALE-8's structural proof: NOT a
     * process-wide memory reading (vacuous — see the skipped test above this
     * property's consumer), and NOT a count bound (rejected — see
     * forAccumulator()'s docblock). What the fix actually changes is the
     * per-entry footprint, so that is what this measures.
     */
    private ?int $peakProjectionEntryBytes = null;

    public function __construct(
        private readonly Resolver $resolver,
        private readonly ValueResolver $values,
        private readonly ContentItemSlugAllocator $slugs,
        private readonly IdentityKeyDeriver $identityKeys,
        private readonly IdentityScope $scope,
    ) {}

    /**
     * Project one stream's current records end to end.
     *
     * $fetchedInRunId is the ingest run whose FETCH produced what is being
     * projected, or null when this pass fetched nothing. RunExecutor is the
     * only caller that can name one: it lands a stream and projects it in the
     * same pass, so `$source`'s selection_ref is the selection that run's
     * records arrived under. `ingest:project` re-derives content from the
     * landed record log without fetching a byte and passes null.
     *
     * A RUN ID, not a boolean, and that is the whole repair (2026-09-01,
     * second pass). This method projects the stream's ENTIRE live record log,
     * not the slice a run just landed — absence is never deletion here, so
     * every record the narrowed feed stopped returning is still live and still
     * swept. A per-RUN "yes, this run fetched" therefore restamped the whole
     * salon's storewide corpus with the employee selection the moment ONE new
     * review arrived under it: the blocker's exact effect, reached through the
     * guard that was supposed to close it. Provenance is per RECORD, so the
     * question has to be asked per record — `ingest.record_state.last_seen_run`
     * already answers it, because Lander writes this run's id onto exactly the
     * keys this run's fetch returned and leaves every other key's alone.
     *
     * @param  array<string, mixed>  $source  row from ingest.sources
     * @return array{status: string, projected?: int, removed?: int, items?: int, reason?: string}
     */
    public function projectStream(array $source, string $streamId, string $streamName, ?string $fetchedInRunId = null): array
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
        // Read ONCE, here, from the source row this run was dispatched with —
        // and written only onto the source items whose RECORD this run's fetch
        // actually returned (the last_seen_run comparison below). That way
        // "what was this source scoped to when it landed that review" stops
        // being a question anyone has to answer from the source's current
        // state.
        $selectionRef = isset($source['selection_ref']) ? (string) $source['selection_ref'] : null;
        $contentSourceId = $this->ensureContentSource($userId, (string) $connectionId, $sourceKey);
        $accountRef = $this->accountRef($userId, (string) $connectionId, (string) $source['identifier']);

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
            // rs.last_seen_run is this stream's per-record provenance: the run
            // whose fetch last RETURNED this key. It is what makes the ingest
            // scope stamped below a fact about one record's ingestion rather
            // than about whatever the sweeping run happened to be doing.
            ->select(['rs.key', 'rs.last_seen_run', 'rv.doc', 'rv.first_seen_at'])
            ->orderBy('rv.first_seen_at')
            ->orderBy('rs.key')
            ->lazy(500);

        $projections = [];
        $touchedCoords = [];
        $projectsToNothing = [];
        $sourceStats = null;
        $this->peakProjectionEntryBytes = 0;

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

            // Did THIS run's fetch return THIS record? Only then may its
            // stored ingest scope be restated. The loop above walks every live
            // record in the stream, including the ones a narrowed feed stopped
            // returning months ago — those keys still carry an older
            // last_seen_run, so they fall out here and keep the selection they
            // genuinely arrived under.
            //
            // The null check is not redundant with the comparison and must not
            // be folded into a (string) cast: a record whose provenance was
            // never recorded carries last_seen_run NULL, a pass that fetched
            // nothing carries $fetchedInRunId null, and `null === null` is the
            // one way a re-projection could stamp a row it knows nothing about
            // with the source's present-tense selection — the blocker, once
            // more, through the last door left open. Casting would answer that
            // case by accident ('' never equals a run id) and hide the reason.
            $recordFetchedThisRun = $fetchedInRunId !== null
                && $record->last_seen_run === $fetchedInRunId;

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
            $sourceItemId = DB::transaction(function () use ($contentSourceId, $coord, $streamId, $record, $projection, $projector, $recordFetchedThisRun, $selectionRef) {
                $id = $this->upsertSourceItem(
                    contentSourceId: $contentSourceId,
                    coord: $coord,
                    streamId: $streamId,
                    recordKey: (string) $record->key,
                    kind: (string) $projection['kind'],
                    projectorVersion: $projector::version(),
                    // Stamped from THIS run's source row, and only onto a
                    // record THIS run's fetch returned — never read back later
                    // from the source's present tense, and never spread from
                    // one fetched record to the rest of the sweep. See
                    // upsertSourceItem().
                    stampIngestScope: $recordFetchedThisRun,
                    ingestSelectionRef: $selectionRef,
                );
                $this->writeIdentityKeys($id, $coord, $projection);

                return $id;
            });

            // #SCALE-8: lazy(500) bounds the READ, but $projections used to hold
            // the whole stream's RAW projection payload in PHP for the whole
            // loop — writeFacets() only runs after the single resolve below, so
            // the array cannot be discarded mid-loop. $touchedCoords (short
            // strings) is kept for the whole run regardless, because
            // resolveItems() needs the FULL touched set in one call — see its
            // docblock — so narrowing $projections can never narrow that.
            //
            // Bounding $projections by FLUSHING it in count-based slices and
            // replaying those slices through writeFacets() after the resolve
            // was considered and rejected: replaceCollections() REPLACES a
            // (item, source)'s media/offers/tags/variants wholesale per call
            // (SCALE-17/#CACHE-2), keyed on the group of projections passed to
            // THAT call. Two records in ONE stream legitimately resolve to the
            // SAME item (a same-source merge — see the 21000 test below), and
            // if their records land in different slices, the later slice's
            // REPLACE would wipe the earlier slice's contribution instead of
            // merging with it — a silent data-loss regression the singleton
            // facet columns do NOT share (an upsert only touches the columns it
            // names, so column-wise "last write wins" survives being split
            // across ordered calls; a wholesale replace does not). Replaying
            // without that hazard would need either a second read of the
            // records (ruled out — lazy(500) already bounds that read; see
            // :193-202) or grouping slices by resolved item, which needs the
            // resolve, which needs the loop to finish first — circular.
            //
            // So the accumulator stays whole-run, and the fix is per-entry: only
            // the columns writeFacets()/replaceCollections() ever read
            // (PROJECTION_ACCUMULATOR_KEYS) survive into $projections. 'kind'
            // and 'source_stats' are dropped here — both are already consumed
            // above — and any future projector key nobody downstream reads is
            // dropped for free. forAccumulator() also records the high-water
            // mark peakProjectionEntryBytes() exposes for the test below.
            $projections[$coord] = $this->forAccumulator($projection);
            $touchedCoords[] = $coord;
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
            $itemByCoord = $this->resolveItems($userId, $projector::kind(), $touchedCoords);
            // Reassigned, not discarded: the lock released at the resolve's COMMIT, so a
            // concurrent merge can have deleted a target before the facets land. The retarget
            // corrects the map, and $touchedItemIds below must refresh the SURVIVORS.
            $itemByCoord = $this->writeFacetsRetargeting($contentSourceId, $userId, $projections, $itemByCoord);

            // Touched items only (#CACHE-4). $itemByCoord is now the component,
            // but even within it only the coords this run wrote can have new
            // facets — writeFacets() above is already scoped to $projections,
            // so anything else would be a no-op refresh at ~18 queries per 500.
            $touchedItemIds = array_values(array_unique(array_filter(array_map(
                fn (string $coord): ?string => $itemByCoord[$coord] ?? null,
                $touchedCoords,
            ))));
            $this->refreshItemCaches($userId, $touchedItemIds);
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
        // #W2-SEC-13: user_id is redundant with idx_content_sources_connection
        // (globally UNIQUE on connection_id) and $connectionId is never
        // request-sourced — scoped anyway, so a wrong-tenant row is a loud
        // RuntimeException below rather than a silently adopted source.
        $existing = DB::table('content.sources')
            ->where('connection_id', $connectionId)
            ->where('user_id', $userId)
            ->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        // idx_content_sources_connection is a PARTIAL unique index on
        // (connection_id) WHERE connection_id IS NOT NULL — two concurrent
        // callers for the same connection can both miss the SELECT above and
        // both attempt an insert. insertOrIgnore lets the loser's insert be
        // silently suppressed by that index (losing the race is normal, not
        // an error) instead of throwing; the loser then re-reads to find out
        // who actually won. Returning the locally-minted uuid here instead of
        // the re-read value would be the naive half-fix: every facet row this
        // run writes would carry a source_id with no backing content.sources
        // row. Same shape as ensureManualSource() below, which solved the
        // identical race first.
        DB::table('content.sources')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'kind' => 'connection',
            'connection_id' => $connectionId,
            'label' => $label,
            'priority' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Scoped for the same reason as the read above, and mandatorily so:
        // this is the loser-of-the-race path, and an unscoped re-read would
        // hand back the very row the first read just refused.
        $id = DB::table('content.sources')
            ->where('connection_id', $connectionId)
            ->where('user_id', $userId)
            ->value('id');

        if ($id === null) {
            throw new \RuntimeException("Could not resolve a content source for connection {$connectionId}.");
        }

        return (string) $id;
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

        // ONE transaction, opened with the identity lock, spanning the source-item upsert, its
        // identity keys AND the resolve. The connector path splits those (a transaction per
        // record, then one resolve) because it is a LOOP and must not hold a write transaction
        // open across every page of it. There is no loop here — one record — and joining them is
        // what makes a lock timeout leave nothing behind at all.
        //
        // It has to be all-or-nothing, and an earlier version of this that committed the source
        // item first and compensated afterwards could not be made correct. The compensation has
        // to decide whether retiring the row destroys anything, and by the time it runs the
        // answer has changed: before 2026-08-25, resolveItemsLocked() bound EVERY live source
        // item of the (user, kind) on every call, so the lock holder that made this caller time
        // out was guaranteed to have already bound this row — while writeFacets() only ever
        // covers the caller's OWN projection. "Is it bound" therefore stopped distinguishing
        // "someone else finished my write" from "someone else bound my row and wrote no facets
        // for it", and the second was the common case. Narrowing the resolve to IdentityScope's
        // connected component of the TOUCHED coords (below) makes that guarantee WEAKER, not
        // stronger: a concurrent run sweeps this row in only if it shares a key signature with
        // THAT run's own touched coords, OR unconditionally via seed source 3 (§A.4) for as long
        // as this row's own item_id is still NULL — which is exactly the window this method is
        // in before its own resolve commits one. Once that resolve has run, neither seed reaches
        // this row again without a shared signature, so "is it bound" can still be false. Rolling
        // the upsert back removes the question either way.
        //
        // The keys must still land in the same transaction as the source item, for the original
        // reason: a committed source item visible with ZERO identity keys resolves as an
        // unrelated singleton, so createItem() mints a spurious content.items row and anchors the
        // coord to it. See the long note at the projectStream() call site.
        $itemByCoord = $this->withIdentityLock($userId, $kind, function () use ($contentSourceId, $coord, $kind, $projection, $userId) {
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
                // A real ingestion (the owner added this by hand) that had no
                // vendor selection, because the manual lane has no vendor.
                // Null is not employee scope, so a hand-added review is
                // admitted to a person's page on its own name evidence like
                // any other.
                stampIngestScope: true,
                ingestSelectionRef: null,
            );
            $this->writeIdentityKeys($sourceItemId, $coord, $projection);

            return $this->resolveItemsLocked($userId, $kind, [$coord]);
        });

        if (! isset($itemByCoord[$coord])) {
            // Unreachable: the row was written live inside the lock above, and
            // IdentityScope::component() seeds its walk FROM $coord, so the
            // component always contains it. (Before 2026-08-25 the reason was
            // that the resolve covered every live source item of the kind —
            // that is no longer true, but the guarantee is stronger, not
            // weaker: it now holds by construction rather than by breadth.)
            // Loud rather than a null return, because a silent miss here would
            // hand a caller an id for the wrong item.
            throw new \RuntimeException("Manual coord {$coord} did not resolve to an item.");
        }

        // Reassigned: this method's RETURN value is an item id a caller pins on
        // (PoolItemCreateController), and before the retarget it could be an id a concurrent
        // merge had already deleted.
        $itemByCoord = $this->writeFacetsRetargeting($contentSourceId, $userId, [$coord => $projection], $itemByCoord);

        // The one item the caller wrote, not every item of the kind (#CACHE-2).
        $this->refreshItemCaches($userId, [$itemByCoord[$coord]]);
        $this->bumpSite($userId);

        return $itemByCoord[$coord];
    }

    /**
     * The reconnect-stable half of a coord. Connections minted by the newer
     * flows already carry the deterministic sha16 in resource_id; legacy rows
     * derive the same shape from the identifier, which survives reconnect for
     * the same remote account by construction.
     *
     * #W2-SEC-13: the connection read is tenant-scoped. Note the asymmetry with
     * ensureContentSource(), which throws on a scope miss — here a miss falls
     * through to the sha1(identifier) derivation, the intended path for legacy
     * connections with no `acct-` resource_id, so a wrong-tenant row and a
     * legacy row are indistinguishable and both mint a derived ref. Accepted
     * knowingly: a mismatch means ingest.sources.user_id and
     * site.platform_connections.user_id already disagree (both FK core.users
     * and are set together at provision), i.e. the data is already broken. No
     * warning log and no second unscoped probe — this is a hot path.
     */
    private function accountRef(string $userId, string $connectionId, string $identifier): string
    {
        $resourceId = DB::table('site.platform_connections')
            ->where('id', $connectionId)
            ->where('user_id', $userId)
            ->value('resource_id');

        if (is_string($resourceId) && preg_match('/^acct-[0-9a-f]{16}$/', $resourceId)) {
            return $resourceId;
        }

        return 'acct-'.substr(sha1(strtolower(trim($identifier))), 0, 16);
    }

    /**
     * $stampIngestScope says whether THIS RECORD was ingested by the run
     * making this call (projectStream() decides it per record, from
     * ingest.record_state.last_seen_run — a sweeping run is not an ingestion
     * of everything it sweeps), and
     * $ingestSelectionRef is the vendor-side selection that ingestion ran under
     * — Fresha's employee id, 'storewide', or null for a lane that has no
     * vendor selection whatsoever (the manual path, every connector without a
     * picker). The two are separate because "no ingestion happened, leave the
     * stored answer alone" and "an ingestion happened and it had no selection"
     * are different facts, and collapsing them into one nullable string lets a
     * repair run silently inherit a stale employee id.
     *
     * BLOCKER it closes (2026-09-01). The reviews person-scope has an
     * employee-scoped tier: a source the vendor already narrowed to one team
     * member may publish an UNATTRIBUTED review on that person's page, on the
     * vendor's word alone. That gate read `ingest.sources.selection_ref` — the
     * source's CURRENT selection — while the reviews themselves carried no
     * record of the selection they arrived under. So a Fresha connection that
     * harvested a whole salon STOREWIDE and was later narrowed to one employee
     * retroactively re-labelled the entire storewide corpus as that employee's,
     * and published all of it on their page forever. Scope is a fact about an
     * ingestion, so it is stamped here, on the row the ingestion writes, and
     * never re-derived from the source's present tense.
     *
     * Restated whenever a fetch RE-LANDS the record: a connection genuinely
     * narrowed to an employee re-harvests, the reviews that come back come back
     * under the new selection, and their rows say so from then on. The reviews
     * that do NOT come back keep saying 'storewide'.
     *
     * "A row nobody re-landed is a row nobody rewrote" is what this docblock
     * used to claim, and it was false — which is how the blocker above
     * survived its own fix. projectStream() sweeps the stream's whole live
     * record log, so with a per-RUN stamp flag every storewide row was rewritten
     * by the first narrowed harvest that returned a single new review. The
     * claim is true now because the caller checks each record's
     * last_seen_run before setting $stampIngestScope, which is a per-record
     * fact and not a property of the run.
     */
    private function upsertSourceItem(
        string $contentSourceId,
        string $coord,
        ?string $streamId,
        ?string $recordKey,
        string $kind,
        int $projectorVersion,
        bool $stampIngestScope = false,
        ?string $ingestSelectionRef = null,
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
                // Only a run that FETCHED may restate the scope of a row that
                // already exists — and when it does it writes the answer
                // WHOLE, null included, so a source whose picker was cleared
                // stops claiming the employee scope it used to have. A
                // re-projection ingested nothing and so says nothing: erasing
                // the stored answer with its silence would destroy the only
                // record of what the source was scoped to when the review
                // actually arrived, which is the whole point of the column.
                ...($stampIngestScope ? ['ingest_selection_ref' => $ingestSelectionRef] : []),
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
            // A first-sight row from a re-projection records NULL: it is a row
            // we have no ingestion for, and unknown scope is not employee
            // scope.
            'ingest_selection_ref' => $stampIngestScope ? $ingestSelectionRef : null,
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
     * Apply the identity spine to one (user, kind) with no source-item write
     * of its own — the owner-ruling counterpart of a connector run.
     *
     * IdentityDecisionController reprojects the ingest sources feeding a ruled
     * pair, and `ingest:project` resolves as part of that replay. A MANUAL
     * content source has no connection_id (see ensureManualSource: "a manual
     * source has none") and therefore no ingest.sources row, so a ruling on
     * two hand-added items had nothing to ride in on: the verdict sat in
     * content.identity_decisions until the owner next happened to edit that
     * kind, which for a kind fed only by hand-adds may be never. Plan
     * 2026-08-25 §A.4 recorded the hole; this is the seam that closes it.
     *
     * $coords is passed as the TOUCHED set, so IdentityScope narrows to their
     * connected component rather than the whole kind. Belt-and-braces rather
     * than load-bearing — component() already seeds every coord a live `same`
     * ruling names — but it keeps this method meaningful for a `different`
     * verdict, which is not seeded, and keeps it correct if that seeding is
     * ever narrowed.
     *
     * Idempotent: the resolve reads its entire input from the database and
     * writes only what the resolution implies, so a retry is a no-op. Callers
     * are responsible for cache lanes — this writes the spine, not the payload.
     *
     * @param  list<string>  $coords
     * @return array<string, string> coord => item id
     */
    public function resolveIdentityFor(string $userId, string $kind, array $coords): array
    {
        return $this->resolveItems($userId, $kind, $coords);
    }

    /**
     * Run the pure resolver over the user's live source-items of this kind
     * and bind every group to a stable item id.
     *
     * $touchedCoords is what this run WROTE. The resolve narrows to the
     * connected component of those coords (plan 2026-08-25 §A.1); the LOCK
     * stays (user, kind) regardless — see withIdentityLock()'s docblock for
     * why the protected set cannot be narrowed even when the computation is.
     *
     * @param  list<string>  $touchedCoords
     * @return array<string, string> coord => item id
     */
    private function resolveItems(string $userId, string $kind, array $touchedCoords): array
    {
        return $this->withIdentityLock($userId, $kind, fn (): array => $this->resolveItemsLocked($userId, $kind, $touchedCoords));
    }

    /**
     * Run $work as the whole of one transaction, holding identity:{user_id}:{kind} (#LIFE-1).
     *
     * Until this existed, resolveItems() was read -> compute -> write with nothing around it, and
     * a second caller could commit between the two ends. The damage is not theoretical:
     * mergeInto() hard-DELETEs the discarded item, so the loser's own closing
     * `source_items.item_id` UPDATE either takes a 23503 on a row that no longer exists, or —
     * when curation spared the loser from the delete — silently reverts the merge and leaves a
     * coord pointing at an item nothing else agrees with. Both are reproduced in
     * tests/Postgres/ProjectionWriterIdentityRaceTest.php.
     *
     * Advisory rather than lockForUpdate(): the protected set is "every live source item of this
     * (user, kind)", which the racing writer may GROW mid-computation, and you cannot lock rows
     * that do not exist yet. It is also three tables (item_anchors, items, source_items), so one
     * key beats an ordering discipline across all three.
     *
     * _xact_ rather than the session variant: it releases on COMMIT/ROLLBACK, so a killed worker
     * cannot wedge a user's identity resolution until Supavisor reaps the connection.
     *
     * $work must span the WHOLE cycle — the reads, the resolve, bindGroup(), recordCandidates()
     * AND the closing per-target UPDATE. A lock that covers the read but not the write looks
     * right, tests green, and fixes nothing.
     *
     * Callers must NOT wrap this: DB::transaction() would degrade to a SAVEPOINT and the lock
     * would silently take the OUTER transaction's lifetime. No caller does — projectStream()'s
     * transactions are per-record and closed before it resolves, and every caller of
     * writeManualItem() is forbidden from nesting one by that method's own docblock.
     *
     * No try/catch inside $work: a catch that RECOVERS in there poisons the transaction with
     * 25P02 (this repo has shipped that three times — ItemSlugAllocatorSavepointTest). The
     * lock-timeout path throws out through the transaction and surfaces as 423 via
     * AdvisoryLockTimeoutException's HttpStatusCodeInterface contract.
     *
     * SQLite has neither pg_advisory_xact_lock nor hashtext, so the Feature lane cannot exercise
     * the lock at all — a green `composer test` says nothing about it. tests/Postgres/ is where
     * it is proven.
     *
     * WHAT THIS DOES NOT COVER. An advisory lock only serialises writers that take it, and four
     * identity mutators still do not:
     *   - writeFacets() and refreshItemCaches() still run AFTER this commits, against item ids a
     *     later resolve may already have merged away. That gap is no longer silent: the facet
     *     write goes through writeFacetsRetargeting(), which catches the 23503, walks
     *     content.item_merges to the survivor and replays once, and hands the CORRECTED map back
     *     — so refreshItemCaches() and writeManualItem()'s return value inherit it too. What the
     *     retarget does NOT do is close the window: the write still leaves the lock, it just no
     *     longer loses the owner's save when it does. Only a merge that wrote a ledger row can be
     *     followed, which covers mergeInto() and ItemMerger::merge() (both insert into
     *     content.item_merges in the SAME transaction as their delete, so a reader never sees one
     *     without the other) but NOT the two writers below;
     *   - ItemMerger::merge() repoints source_items, rewrites anchors and hard-deletes an item in
     *     a plain transaction — currently unreachable, nothing in app/ or routes/ constructs it;
     *   - StaffServiceManagementController::forceDestroy() (routed:
     *     DELETE /professionals/{professional}/services/{service}/hard) deletes the source_items
     *     and the content.items row outright. A resolve that has already computed a target on
     *     that id takes a 23503 on its closing UPDATE;
     *   - ContentRetireChannelKindCommand hard-deletes source_items and items for kind='channel'
     *     in a plain transaction — inert in practice (dry-run by default, one-shot, and nothing
     *     emits that kind any more), listed for completeness.
     * ItemMerger::merge() is covered by the retarget above for free. The last two write NO ledger
     * row at all, so racing either surfaces as FacetTargetLineageLostException('no
     * content.item_merges row explains it') — the correct loud failure, but a report rather than a
     * recovery. They belong in their own unit under fix-flow.md's
     * "Standalone — do NOT bundle" rule, not in this one. That list of four is the complete set
     * of unlocked writers that can leave a DANGLING reference — every hard delete of, or repoint
     * across, content.{source_items,items,item_anchors} in app/. Several others (FreshaController,
     * ContentRepairEventItemsCommand, RetireLegacyGooglePhotoRecordsCommand,
     * PurgeReviewHeadlinePiiCommand) only set removed_at or clear a cache column: they change the
     * resolve's INPUT SET and can make a resolution stale, which is a different and much smaller
     * problem, and they are not enumerated exhaustively here.
     *
     * A 40P01 deadlock is NOT reclassified below, only 55P03. Deadlock detection fires at 1s,
     * ahead of this 5s bound, and the manual path now holds its coord's source_items row lock
     * for the whole resolve rather than a few milliseconds — so the window for a cycle with an
     * unlocked bulk writer is wider than it was. Left as a 500 deliberately: a deadlock is a
     * lock-ordering bug worth seeing, not contention worth retrying.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    private function withIdentityLock(string $userId, string $kind, callable $work): mixed
    {
        $connection = DB::connection();
        $key = "identity:{$userId}:{$kind}";

        // The no-nesting rule above is load-bearing and, until this line, had nothing enforcing
        // it: inside an outer transaction DB::transaction() degrades to a SAVEPOINT, the advisory
        // lock silently takes the OUTER transaction's lifetime, and SET LOCAL lock_timeout
        // stretches over it too. All of that is invisible — the tests stay green and the lock
        // stops meaning what the docblock says. Loud beats silent on a path whose merges hard-
        // delete. (Nothing nests today; verified across every call site of writeManualItem() and
        // projectStream(). The test suites do not wrap either — RefreshDatabase is deliberately
        // off in tests/Pest.php, and tests/TestCase.php forces database.default to 'pgsql' in
        // every lane, so this and DB::connection('pgsql') are always the same instance.)
        //
        // How loud depends on the lane: on the HTTP paths this surfaces as a 500 and a Nightwatch
        // exception, but RunExecutor catches \Throwable around projectStream() and converts it
        // into a report() plus a critical ingest.anomalies row and a 'degraded' run — attributable
        // and paged, but a demoted alert rather than a crash. Guarded by
        // tests/Postgres/ProjectionWriterIdentityRaceTest.php.
        if ($connection->transactionLevel() > 0) {
            throw new \LogicException(
                "resolveItems()/writeManualItem() must not run inside a transaction: the advisory lock {$key} would take the outer transaction's lifetime.",
            );
        }

        try {
            return $connection->transaction(function () use ($connection, $key, $work) {
                if ($connection->getDriverName() === 'pgsql') {
                    AdvisoryLock::acquire($key, self::IDENTITY_LOCK_TIMEOUT_MS, $connection->getName());
                }

                return $work();
            });
        } catch (QueryException $e) {
            // OUTSIDE the transaction — DB::transaction() has already rolled back, so this cannot
            // poison anything (25P02). It converts, it does not recover.
            //
            // SET LOCAL lock_timeout bounds every lock the transaction takes, not just the
            // advisory one, and a ROW lock aborting raises the same SQLSTATE 55P03 as a bare
            // QueryException rather than as AdvisoryLockTimeoutException — the closing
            // `UPDATE content.source_items` waiting on a concurrent writer is the reachable case.
            // Undistinguished, that path surfaces as a 500 for what is ordinary contention.
            // ReorderService::reorder() already reclassifies its row-lock timeout through the
            // same SQLSTATE for the same reason.
            //
            // SQLSTATE only, NOT AdvisoryLock::isLockTimeout(), whose second branch matches the
            // substring 'lock timeout' anywhere in the message. QueryException interpolates
            // bindings into that message, and the bindings here include coord — built from
            // platform-supplied record keys (see projectStream()). A coord carrying that literal
            // would turn any error on any statement in the resolve (a 23505, a 23503, a
            // statement_timeout) into a false 423. The substring branch buys nothing here:
            // Postgres reports 55P03 through getCode() for both the advisory lock and a row lock,
            // which is the whole reachable set.
            if ((string) $e->getCode() === AdvisoryLock::LOCK_NOT_AVAILABLE_SQLSTATE) {
                throw new AdvisoryLockTimeoutException($key, $e);
            }

            throw $e;
        }
    }

    /**
     * The body of resolveItems(), which must only ever run under its lock and transaction.
     *
     * @param  list<string>  $touchedCoords
     * @return array<string, string> coord => item id
     */
    private function resolveItemsLocked(string $userId, string $kind, array $touchedCoords): array
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
            ->get(['si.id', 'si.coord', 'si.source_id', 'si.kind', 'si.first_seen_at', 'si.item_id']);

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

        // Seed source 3 (plan §A.4): rows that are not resolved AT ALL. The
        // whole-kind resolve rebound every live source item on every run, which
        // silently repaired an `item_id IS NULL` row; a touched-only seed would
        // never revisit one, leaving it unbound forever. Taken from $rows, which
        // is already read in full, so this costs no extra query — and in steady
        // state the set is EMPTY, so the narrowing's saving is unchanged.
        $unboundCoords = $rows
            ->filter(fn (object $row) => $row->item_id === null)
            ->pluck('coord')
            ->map(fn ($coord) => (string) $coord)
            ->all();

        // Narrow to what can actually change (#CACHE-2/#CACHE-4, plan
        // 2026-08-25 §A.1). Everything above this line still reads the whole
        // (user, kind): $sourceItems is the graph the closure walks, and the
        // identity_keys read is a subquery on the same predicate, so the DB
        // touches source_items either way. The saving is everything BELOW —
        // the O(n^2) resolve, the anchor snapshot, the bind loop, the
        // candidate writes and the closing re-read.
        //
        // The lock is NOT narrowed with it: the protected set is still every
        // live source item of this (user, kind), which a racing writer may
        // GROW mid-computation. See withIdentityLock().
        $capped = false;
        $narrowed = false;
        if (config('partna.content.identity_scope') && $touchedCoords !== []) {
            $component = $this->scope->component(
                $sourceItems,
                $decisions,
                array_values(array_unique([...$touchedCoords, ...$unboundCoords])),
                (int) config('partna.content.identity_scope_max', IdentityScope::MAX_COMPONENT),
            );
            $capped = $component['capped'];

            if (! $capped) {
                $narrowed = true;
                $inComponent = array_flip($component['coords']);
                $sourceItems = array_values(array_filter(
                    $sourceItems,
                    fn (SourceItem $item) => isset($inComponent[$item->coord]),
                ));
                $decisions = array_values(array_filter(
                    $decisions,
                    fn (Decision $d) => isset($inComponent[$d->left]) || isset($inComponent[$d->right]),
                ));
            }
        }

        if ($capped) {
            // NOT silent: an invisible fallback reads as "narrowing works" while
            // every run pays the full cost. user + kind so it is attributable.
            Log::warning('identity scope cap hit — resolving whole kind', [
                'user_id' => $userId,
                'kind' => $kind,
                'touched_count' => count($touchedCoords),
                // NOT 'component_size' (controller ruling, pre-flight scan): the
                // walk ABORTED at the cap, so the true component size is unknown.
                // What this counts is the whole-kind set we fell back to. Naming
                // it component_size would log a number that is not the thing its
                // key claims.
                'resolving_count' => count($sourceItems),
            ]);
        }

        // #SCALE-10/#CACHE-6: the caps are read HERE and passed IN. The resolver
        // is f(items, decisions, caps) -> Resolution with no I/O, and that
        // purity is what makes a resolve reproducible from its arguments alone
        // — the same rule LinkProjector follows for detector suspensions.
        $resolution = $this->resolver->resolve(
            $sourceItems,
            $decisions,
            $this->identityCap('max_members_per_key', 100),
            $this->identityCap('max_candidates_per_key', 200),
        );

        if ($resolution->cappedKeys !== []) {
            // #SCALE-10/#CACHE-6: a SILENT cap on candidate pairing means items
            // quietly stop being offered for merge — no error, no failed
            // assertion, indistinguishable on a green run from "there was
            // nothing to suggest". So it is surfaced, here rather than in the
            // resolver, because user + kind live at this seam and the resolver
            // is pure.
            //
            // ONE line per RUN with a bounded sample, never one per key and
            // never one per pair: a log flood is the same failure the cap
            // exists to prevent, moved to a different subsystem.
            Log::warning('identity candidate cap hit — some duplicate suggestions were not recorded', [
                'user_id' => $userId,
                'kind' => $kind,
                'capped_key_count' => count($resolution->cappedKeys),
                'sample' => array_slice($resolution->cappedKeys, 0, 5, preserve_keys: true),
            ]);
        }

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
            [$itemId, $merged] = $this->bindGroup($userId, $kind, $group, $snapshot, $narrowed);
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
        //
        // Narrowed INSIDE the subquery, deliberately: the outer statement's
        // shape is unchanged (`... from "content"."source_items" where "id" in
        // (select ...)`) because ProjectionWriterIdentityRaceTest hooks this
        // exact text through DB::listen to widen its read->write window, and
        // that hook's position is what makes the file prove the advisory lock
        // rather than an item_anchors unique-index collision (see that file's
        // header). Rewriting this into a join breaks four race tests for a
        // reason unrelated to identity.
        //
        // Rows this resolve did not consider cannot have a new target, so
        // re-reading them only to compare equal is pure cost. When the scope is
        // off or capped, $componentCoords is null and the predicate is
        // byte-for-byte what it was before this branch.
        //
        // Not chunked: the bind list is a server-side subquery, not a
        // materialised array, so there is no 65,535-parameter limit to chunk
        // around (#SCALE-7's original fix). Chunking would also emit N
        // statements and fire the fire-once hook above on the first,
        // changing the timing the race test depends on.
        $componentCoords = $narrowed ? array_keys($itemByCoord) : null;
        $bySourceItemId = DB::table('content.source_items')
            ->whereIn('id', function ($sub) use ($userId, $kind, $liveSource, $componentCoords) {
                $sub->select('si.id')->from('content.source_items as si')
                    ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
                    ->where('cs.user_id', $userId)
                    ->where('si.kind', $kind)
                    ->tap($liveSource)
                    ->whereNull('si.removed_at');

                if ($componentCoords !== null) {
                    $sub->whereIn('si.coord', $componentCoords);
                }
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
    private function bindGroup(string $userId, string $kind, array $group, ?array $snapshot = null, bool $narrowed = false): array
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
                $this->mergeInto($userId, keptItemId: $lostTo, discardedItemId: $winner, groupCoords: $group, narrowed: $narrowed);
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
            $this->mergeInto($userId, keptItemId: $winner, discardedItemId: (string) $loser, groupCoords: $group, narrowed: $narrowed);
            $merged = true;
        }

        return [$winner, $merged];
    }

    /**
     * Every anchor for every coord this pass will bind, read once (#SCALE-4).
     *
     * Keyed by coord, which is exact rather than convenient: content.item_anchors' PK is
     * (user_id, coord), so there is at most one row per coord. Unordered — chunking would split
     * a single ORDER BY anyway, and bindGroup() applies sortAnchors() per group to whichever
     * source served it.
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
     * Comparing the timestamps AS STRINGS is chronological only while the session emits a single
     * format with a constant offset. config/database.php sets no `timezone` for the pgsql
     * connection, so that is the server default — UTC on Supabase and on the test container. A
     * DST-observing session timezone would invert the local wall-clock strings for one hour a
     * year during the fall-back overlap; if that ever changes, this comparison has to parse
     * rather than compare.
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
    private function mergeInto(string $userId, string $keptItemId, string $discardedItemId, array $groupCoords = [], bool $narrowed = false): void
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
            // BEFORE the delete: every facet table FKs content.items(id) ON
            // DELETE CASCADE, so afterwards there is nothing left to carry.
            // Inside this branch ONLY — a curated loser survives and keeps its
            // own facets (spec §5.2).
            $this->foldCollections($userId, $keptItemId, $discardedItemId);

            DB::table('content.items')->where('id', $discardedItemId)->delete();
        }

        // WHEN the discarded item is actually deleted (the !$hasCuration branch four lines up),
        // its anchors are GONE too: item_anchors.item_id FKs to content.items ON DELETE CASCADE,
        // so the delete takes every anchor that still pointed at it — including anchors for
        // coords the unscoped source_items repoint above just moved onto the kept item. When
        // $hasCuration is true the item survives and nothing cascades, so there is nothing to
        // repair; the block below still runs unconditionally on $narrowed in that case, but
        // insertOrIgnore makes it a no-op against anchors that were never touched.
        //
        // Gated on $narrowed, not merely on the config flag: this repair
        // compensates for coords the narrowing left OUT of this pass, and with
        // narrowing off (or capped back to whole-kind) there are none — every
        // live source item of the (user, kind) was already in $sourceItems, so
        // bindGroup() re-mints any cascade-orphaned anchor through its own
        // insertOrIgnore before the run ends, exactly as it always did. Running
        // this unconditionally would not just be redundant: it would issue a
        // SELECT + insertOrIgnore the pre-narrowing code never ran, and it can
        // change outcomes even then — a coord on the loser item but in a LATER
        // group used to be left anchor-less until that group's own bindGroup()
        // call re-minted it fresh; pre-emptively repairing it here would make
        // it survive under the wrong (soon-to-be-superseded) identity instead.
        // Leaving it ungated would make PARTNA_CONTENT_IDENTITY_SCOPE=false a
        // false rollback claim.
        //
        // $groupCoords EXCLUDES the current bindGroup() call's own coords —
        // deliberately, not an oversight. A coord already IN the resolved
        // group (e.g. a tied pair's loser) is bindGroup()'s own business: it
        // was already read into $anchors up top, so it is NOT in $missing and
        // gets no fresh insertOrIgnore there BY DESIGN — that is how exactly
        // one of a merged pair keeps an anchor row, which
        // ProjectionWriterIdentityRaceTest's collation test asserts on
        // directly. Sweeping the group's own coords back in here would give
        // BOTH sides of that pair an anchor and silently defeat that
        // assertion. This block exists for coords OUTSIDE any group this pass
        // even considered — the ones nothing else will ever re-mint.
        //
        // Bounded by the KEPT item's source items minus the current group — a
        // handful — and only runs when a merge actually happens. insertOrIgnore
        // makes it a no-op for coords that still have their anchor.
        //
        // removed_at IS NULL, same as every other live-row read in this class
        // (resolveItemsLocked()'s $liveSource / whereNull('si.removed_at')):
        // without it a retired coord — one whose source_items row still points
        // at $keptItemId but is no longer live — would mint a fresh anchor it
        // has no business holding, a flag-on-only divergence from the
        // whole-kind path, which never re-anchors retired rows either.
        if ($narrowed) {
            $keptCoords = DB::table('content.source_items')
                ->where('item_id', $keptItemId)
                ->whereNull('removed_at')
                ->whereNotIn('coord', $groupCoords)
                ->pluck('coord');

            if ($keptCoords->isNotEmpty()) {
                DB::table('content.item_anchors')->insertOrIgnore($keptCoords->map(fn (string $coord) => [
                    'coord' => $coord,
                    'user_id' => $userId,
                    'item_id' => $keptItemId,
                    'bound_at' => now(),
                ])->all());
            }
        }
    }

    /**
     * Carry the discarded item's collection facets onto the survivor before the
     * delete cascades them away.
     *
     * Only the MANUAL lane actually loses data here — a connector coord's
     * facets are re-derived by the next reprojection from
     * ingest.record_versions — but the fold is unconditional because it is
     * correct for both and a lane branch inside a merge is a trap.
     * moveLinks()/moveSlugs() below already do exactly this for the two tables
     * no projection rewrites.
     *
     * The media CAP is the one place the lane distinction is honoured, and it
     * has to be: a cap that drops a manual photo destroys it for good, while a
     * dropped connector photo comes back on the next reprojection.
     *
     * Callers MUST invoke this only where the discarded item is actually
     * deleted. A loser spared by $hasCuration is still rendered wherever it is
     * pinned, and emptying it would be a fresh data-loss bug on exactly the
     * items the owner cared about most.
     *
     * Runs inside mergeInto(), so it is already under the identity advisory
     * lock and inside resolveItemsLocked()'s transaction. No new lock, no new
     * transaction, and no try/catch that RECOVERS — this repo has shipped
     * 25P02 that way three times.
     */
    private function foldCollections(string $userId, string $keptItemId, string $discardedItemId): void
    {
        // The cap bounds what ONE fold may ADD, and applies to media only:
        // normal projection is untouched, because a connector legitimately
        // returning 50 images must not be truncated.
        $cap = max(1, (int) config('partna.content.merge_media_cap', 8));
        $mediaHeld = DB::table('content.item_media')->where('item_id', $keptItemId)->count();
        $mediaDropped = 0;

        // The cap may only drop what a reprojection can put back. A connector
        // coord's media is re-derived from ingest.record_versions through the
        // UNCAPPED replaceCollections() path (see its docblock and this
        // method's), so dropping one is transient. A MANUAL coord's facets were
        // written once from an HTTP payload persisted nowhere — there is
        // nothing to replay, so dropping one is terminal. Exempt them.
        //
        // Keyed off source_item_id, NOT item_id: mergeInto() has already
        // repointed the loser's source_items onto the survivor by the time this
        // runs, so the item_id on those rows no longer distinguishes the lanes.
        // Resolved ONCE, outside the loop — this is inside the identity
        // advisory lock and resolveItemsLocked()'s transaction, and a per-row
        // query would hold both open for a round-trip per photo.
        //
        // A NULL-origin row keeps today's behaviour (capped): it is
        // unattributable — pre-backfill, or a row whose origin was never
        // stamped — and guessing "manual" for it would relax the bound on
        // exactly the rows we know least about.
        $manualOrigins = DB::table('content.item_media as im')
            ->join('content.source_items as si', 'si.id', '=', 'im.source_item_id')
            ->join('content.sources as s', 's.id', '=', 'si.source_id')
            ->where('im.item_id', $discardedItemId)
            ->where('s.kind', 'manual')
            ->pluck('im.source_item_id')
            ->flip();

        // table => the value tuple that decides whether the survivor already
        // carries this contribution. Single-value facets (f_text, f_link, …)
        // are absent deliberately: their PK is (item_id, source_id), so only
        // one row can exist per source and a winner is inherent to merging.
        // `url` is in the offers tuple because PoolResolver turns it into one of
        // the item's LINKS (PoolResolver::itemPayloads()'s offer-links query).
        // Two hand-added items priced identically on one channel but pointing at
        // different booking URLs are not the same fact, and deduping them on
        // price alone silently drops a live link.
        $dedupe = [
            'item_media' => ['asset_id', 'role'],
            // platform/item_url/external_ref joined 2026-08-26: menu offers
            // now write url as NULL uniformly (D1), so without them two
            // platforms' offers at one price — or two identity-only ghost
            // rows — would fold to one key and silently drop a platform's
            // deep link.
            'offers' => ['channel', 'variant_label', 'amount_minor', 'currency', 'qualifier', 'url', 'platform', 'item_url', 'external_ref'],
            'item_tags' => ['tag', 'tag_type'],
            'item_variants' => ['label', 'sku'],
        ];

        // The column that has to be PRESENT for the tuple to identify anything.
        // A media entry whose URL yields no fingerprint gets a null asset_id
        // (replaceCollections() documents that as unchanged behaviour), and
        // every such row on one side would otherwise collapse into a single
        // "|role" key and be discarded as a duplicate of a different photo.
        // The other three tables have NOT NULL identity columns (tag, label) or
        // no single one, so they are absent here and dedupe as before.
        $identity = ['item_media' => 'asset_id'];

        // Tables whose `position` must be renumbered on the way in, and the
        // column that groups the numbering. item_media's index is
        // (item_id, role, position), so it renumbers PER ROLE; item_variants is
        // ordered per item (ShopContentWriter reads it with orderBy('position')).
        //
        // Both sides number from their own projection's list index, so a merge
        // otherwise leaves the survivor holding two role='cover' rows at
        // position 0. That index is NOT unique, so the collision is legal and
        // silent — and PoolResolver::itemPayloads() orders item_media by
        // position alone while cover() breaks ties by arrival order, which makes
        // WHICH photo renders planner-dependent.
        $positioned = ['item_media' => 'role', 'item_variants' => null];

        // A live origin of the survivor PER SOURCE, to stamp a moved row that
        // has no origin of its own.
        //
        // Per source, not "any live origin", and that is load-bearing.
        // mergeInto() repoints the loser's source_items onto the survivor
        // BEFORE this runs, so by now the survivor's live coords span every
        // source either side touched. replaceCollections() deletes
        // `source_id = ours AND (source_item_id IS NULL OR IN ours-origins)`,
        // so an origin belonging to ANOTHER source satisfies neither branch and
        // the row becomes undeletable — the data-DUPLICATION failure the IS NULL
        // half exists to prevent, reintroduced from the other end.
        //
        // A source with no live coord on the survivor yields nothing, and the
        // row stays NULL: replaceable exactly as today, which is the safe
        // reading. orderBy() only so a multi-coord source picks deterministically.
        $keptOriginBySource = DB::table('content.source_items')
            ->where('item_id', $keptItemId)
            ->whereNull('removed_at')
            ->orderBy('id')
            ->pluck('id', 'source_id');

        $chunk = $this->writeChunk();

        foreach ($dedupe as $table => $keys) {
            $identityColumn = $identity[$table] ?? null;

            $seen = [];
            $highWater = [];
            foreach (DB::table("content.{$table}")->where('item_id', $keptItemId)->get() as $row) {
                if (array_key_exists($table, $positioned)) {
                    $group = $positioned[$table] === null ? '' : (string) $row->{$positioned[$table]};
                    $highWater[$group] = max($highWater[$group] ?? -1, (int) $row->position);
                }
                if ($identityColumn !== null && $row->{$identityColumn} === null) {
                    continue;
                }
                $seen[$this->foldKey($row, $keys)] = true;
            }

            // origin to stamp => the row ids taking it. Collected rather than
            // updated per row: this runs inside the identity advisory lock AND
            // resolveItemsLocked()'s transaction, and a shop product carrying a
            // few hundred variants would otherwise hold both open for a few
            // hundred sequential round-trips on a path that previously did no
            // per-row work at all.
            $moves = [];
            foreach (DB::table("content.{$table}")->where('item_id', $discardedItemId)->get() as $row) {
                $identified = $identityColumn === null || $row->{$identityColumn} !== null;
                $key = $this->foldKey($row, $keys);

                if ($identified && isset($seen[$key])) {
                    // The survivor already says this. Let the cascade take it —
                    // image FILES are deduped by media_assets' UNIQUE
                    // (user_id, fingerprint), so a duplicate row is a second
                    // reference to one asset and would render twice.
                    continue;
                }

                if ($table === 'item_media') {
                    // Exempt above the cap, never below it — a manual row still
                    // counts towards $mediaHeld, so it is the connector rows
                    // behind it that give way, not the guard that disappears.
                    $manual = $row->source_item_id !== null && isset($manualOrigins[$row->source_item_id]);

                    if (! $manual && $mediaHeld >= $cap) {
                        // Only ever drops INCOMING rows. The survivor's own are
                        // never removed: a connector item legitimately carrying
                        // 20 images must not be truncated the moment anything
                        // merges into it. Not marked $seen — it was not moved,
                        // so it must not mask a later identical row.
                        $mediaDropped++;

                        continue;
                    }
                    $mediaHeld++;
                }

                if ($identified) {
                    $seen[$key] = true;
                }

                // Stamp origin if it has none: an un-attributed moved row would
                // be clobbered by the survivor's next save, which is exactly the
                // failure this whole change exists to prevent. '' is the
                // no-origin bucket — array keys cannot be null.
                $origin = $row->source_item_id ?? ($keptOriginBySource[$row->source_id] ?? null);

                // Renumbering is an OFFSET, not a fresh 0..n. That is what lets
                // the whole group go out in one statement below AND preserves
                // the incomer's own order: rows at 0..k land at off..off+k,
                // clear of the survivor's 0..high-water.
                $offset = 0;
                if (array_key_exists($table, $positioned)) {
                    $group = $positioned[$table] === null ? '' : (string) $row->{$positioned[$table]};
                    $offset = ($highWater[$group] ?? -1) + 1;
                }

                $moves[((string) $origin)."\0".$offset][] = $row->id;
            }

            foreach ($moves as $bucket => $ids) {
                [$origin, $offset] = explode("\0", $bucket, 2);
                $update = [
                    'item_id' => $keptItemId,
                    'source_item_id' => $origin === '' ? null : $origin,
                ];
                if (array_key_exists($table, $positioned) && (int) $offset > 0) {
                    $update['position'] = DB::raw('position + '.(int) $offset);
                }

                foreach (array_chunk($ids, $chunk) as $slice) {
                    DB::table("content.{$table}")->whereIn('id', $slice)->update($update);
                }
            }
        }

        if ($mediaDropped > 0) {
            // A silent cap is a defect in this codebase. user + item so it is
            // attributable.
            Log::warning('content.merge_fold.media_capped', [
                'user_id' => $userId,
                'item_id' => $keptItemId,
                'kept' => $mediaHeld,
                'dropped' => $mediaDropped,
            ]);

            // Log::warning does not reach Nightwatch (CLAUDE.md), and this
            // class's escalation idiom is a bare report(). Everything that can
            // reach here is RECOVERABLE — manual origins are exempt above, so
            // only connector rows the next reprojection re-derives are dropped
            // — but a sustained run means the cap is mis-set, and that is an
            // operator event rather than a line nobody greps for.
            report(new MergeFoldMediaDroppedException($userId, $keptItemId, $mediaDropped));
        }
    }

    /** @param  list<string>  $keys */
    private function foldKey(object $row, array $keys): string
    {
        return implode('|', array_map(fn (string $k) => (string) ($row->{$k} ?? ''), $keys));
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
     * #CACHE-1/#SCALE-11: this was one insertOrIgnore PER CANDIDATE — a write
     * N+1 sitting directly downstream of the resolver's O(m^2) pairing, so a
     * single over-shared key value turned into thousands of round trips.
     * Collected and written in chunks of writeChunk() (7 columns, so 500 rows
     * is 3,500 binds — an order of magnitude under both engines' limits).
     *
     * Three of today's semantics are preserved DELIBERATELY, and each is easy
     * to lose in a batch rewrite:
     *   - the same per-candidate guards, unchanged;
     *   - dedupe within the batch on (left_item_id, right_item_id), FIRST
     *     WINS. The same pair can arise from two different key values; the old
     *     loop's second insertOrIgnore was silently swallowed by
     *     idx_identity_candidates_pair, so the FIRST candidate's `evidence` is
     *     what persisted. Keeping the last would change stored evidence, and
     *     two conflicting rows in ONE statement is not an ignore.
     *   - NO (left, right) normalisation. That unique index is DIRECTIONAL, so
     *     (a,b) and (b,a) are two legitimate rows that coexist today and both
     *     readers match either ordering. Ordering the pair would change which
     *     rows exist.
     *
     * content.identity_candidates has no `updated_at` column (DDL:
     * supabase/migrations/20260727140000_content_schema.sql:151-166), so the
     * conflict path bumps no timestamp — and no reader keys off one.
     *
     * @param  list<Candidate>  $candidates
     * @param  array<string, string>  $itemByCoord
     */
    private function recordCandidates(string $userId, array $candidates, array $itemByCoord): void
    {
        $rows = [];

        foreach ($candidates as $candidate) {
            $left = $itemByCoord[$candidate->left] ?? null;
            $right = $itemByCoord[$candidate->right] ?? null;
            if ($left === null || $right === null || $left === $right) {
                continue;
            }

            $pair = $left.'|'.$right;
            if (isset($rows[$pair])) {
                continue;
            }

            $rows[$pair] = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'left_item_id' => $left,
                'right_item_id' => $right,
                'score' => 50,
                'evidence' => json_encode(['key' => $candidate->evidence]),
                'created_at' => now(),
            ];
        }

        foreach (array_chunk(array_values($rows), $this->writeChunk()) as $chunk) {
            DB::table('content.identity_candidates')->insertOrIgnore($chunk);
        }
    }

    /**
     * #SCALE-8: the accumulator entry, narrowed to what writeFacets() and
     * replaceCollections() actually read (PROJECTION_ACCUMULATOR_KEYS) —
     * everything else a projector returns (currently just 'kind' and
     * 'source_stats', both already consumed by the time this runs) is dropped
     * rather than carried for the rest of the run. See the call site's
     * docblock for why a count-bounded flush was rejected in favour of this.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function forAccumulator(array $projection): array
    {
        $slim = array_intersect_key($projection, array_flip(self::PROJECTION_ACCUMULATOR_KEYS));

        // Test-visible high-water mark only — serialize() is a cheap, honest
        // proxy for "how big is this entry" that does not depend on the PHP
        // process's OTHER allocations the way memory_get_peak_usage() does
        // (see the skipped test above). Not on the hot path's correctness, and
        // gated on runningUnitTests() so a real projection run — which can
        // walk thousands of records per stream — never pays a
        // strlen(serialize()) per record just to feed a seam nothing in
        // production reads.
        if (app()->runningUnitTests()) {
            $bytes = strlen(serialize($slim));
            if ($bytes > ($this->peakProjectionEntryBytes ?? 0)) {
                $this->peakProjectionEntryBytes = $bytes;
            }
        }

        return $slim;
    }

    /**
     * The largest single accumulated projection entry's serialized size, from
     * the most recently completed projectStream() call. Test seam for
     * #SCALE-8's structural proof — null before any run.
     */
    public function peakProjectionEntryBytes(): ?int
    {
        return $this->peakProjectionEntryBytes;
    }

    /**
     * writeFacets(), plus the recovery for the one thing the identity lock cannot cover.
     *
     * THE GAP. withIdentityLock() holds pg_advisory_xact_lock for exactly one transaction and
     * releases it at COMMIT, so this call — the next statement at both call sites — runs
     * unprotected. A concurrent resolve of the same (user, kind) can mergeInto() the item this
     * one just resolved, and mergeInto() HARD-DELETEs an uncurated loser. Every facet table FKs
     * content.items(id) ON DELETE CASCADE, so the write then takes a 23503 and the owner loses
     * their save to an unattributable foreign-key violation.
     *
     * WHY REACTIVE, NOT A PRE-CHECK. A pre-check is TOCTOU by construction: it commits and
     * releases before the write, so it cannot see the delete that happens after it. The 23503 IS
     * the check, taken by the database at write time — and it costs the happy path nothing, which
     * is the design constraint (a recovery path, not a new lookup on every write). Pinned by
     * ProjectionWriterIdentityRaceTest's 'adds no query to the happy path'.
     *
     * WHY NOT EXTEND THE LOCK. The other way to close the gap is to hold withIdentityLock()'s
     * transaction over writeFacets() as well. IDENTITY_LOCK_TIMEOUT_MS bounds that lock at 5s,
     * sized — like AdvisoryLock::SERVICES_LOCK_TIMEOUT_MS it matches — for an interactive
     * dashboard write. On projectStream() writeFacets() is not one row but the WHOLE run's batch
     * write, the accumulated $projections for every record the lazy(500) loop touched, so
     * extending the lock over it would trade this rare 23503 for a routine 423 on every hand-add
     * landing on the same (user, kind) while a sync is in flight. It would also nest
     * replaceCollections()' per-chunk DB::transaction(), which states it is the outermost one and
     * is chunked precisely to keep lock duration bounded — nesting demotes it to a SAVEPOINT and
     * that bound disappears. And it would hold a user-scoped advisory lock across
     * replaceCollections()' deliberately unwrapped resolveMediaAssets() call, whose
     * dispatchMirrors() queues a MirrorMediaAssetJob per owned asset.
     *
     * WHY THE LEDGER CAN BE TRUSTED HERE. mergeInto() inserts into content.item_merges and
     * deletes content.items with NO transaction boundary between them, inside resolveItemsLocked()
     * — which is the whole body of withIdentityLock()'s single transaction. ItemMerger::merge() has
     * the same shape. So a later reader sees BOTH the delete and the ledger row, or neither; there
     * is no window in which the parent is gone and the lineage is not yet visible. That is what
     * separates this from the pre-check. content.item_merges is also deliberately FK-free and
     * append-only (migration 20260729150019, audit DINT-3: "may name a row that no longer exists —
     * that is the point"), which is why it, and NOT item_anchors.superseded_by, is the lineage:
     * mergeInto() sets superseded_by but never repoints item_id, so the delete cascades those
     * anchor rows away with the item.
     *
     * WHY THE CATCH CANNOT POISON A TRANSACTION (25P02). Recovering inside an open transaction is
     * a hazard this repo has shipped three times. It does not apply here, for three reasons:
     * withIdentityLock() throws if any caller has a transaction open; it returns only after its own
     * has committed; and the only transaction inside writeFacets() is replaceCollections()' per-
     * chunk DB::transaction(), which rolls back and rethrows before the exception reaches us. The
     * catch re-asserts transactionLevel() === 0 anyway, as code rather than as a comment.
     *
     * WHY THE REPLAY IS SAFE. replaceCollections() is delete-then-insert scoped by
     * (item_id IN batch, source_id[, origin]) and flushSingletonFacets() upserts on
     * (item_id, source_id) — replaying rewrites the same rows. A chunk that committed before the
     * failing one is simply re-written.
     *
     * @param  array<string, array<string, mixed>>  $projections  coord => projection
     * @param  array<string, string>  $itemByCoord
     * @return array<string, string> the map actually written — retargeted where a concurrent merge
     *                               deleted a parent between the resolve and here
     */
    private function writeFacetsRetargeting(string $contentSourceId, string $userId, array $projections, array $itemByCoord): array
    {
        try {
            $this->writeFacets($contentSourceId, $userId, $projections, $itemByCoord);

            return $itemByCoord;
        } catch (QueryException $e) {
            // The 25P02 argument above, stated as code so a future caller that breaks the
            // no-open-transaction invariant rethrows instead of recovering into a poisoned one.
            if (DB::connection()->transactionLevel() > 0 || (string) $e->getCode() !== self::FK_VIOLATION_SQLSTATE) {
                throw $e;
            }

            $retargeted = $this->retargetToMergeSurvivors($userId, $itemByCoord, $e);

            try {
                $this->writeFacets($contentSourceId, $userId, $projections, $retargeted);
            } catch (QueryException $retry) {
                throw new FacetTargetLineageLostException(
                    $userId,
                    implode(',', array_values(array_diff($itemByCoord, $retargeted))),
                    'the replay onto the merge survivor still violated a foreign key',
                    $retry,
                );
            }

            // report(), not throw: the facets DID land, on the survivor content.source_items was
            // already repointed at. Same escalation idiom as MergeFoldMediaDroppedException — a
            // Log::warning would not reach Nightwatch, and a race this class cannot prevent must
            // at least be visible.
            report(new FacetTargetMergedAwayException($userId, $itemByCoord, $retargeted));

            return $retargeted;
        }
    }

    /**
     * Rewrite a coord => item map so every target that no longer exists points at the item its
     * merge kept instead.
     *
     * @param  array<string, string>  $itemByCoord
     * @return array<string, string>
     */
    private function retargetToMergeSurvivors(string $userId, array $itemByCoord, QueryException $cause): array
    {
        $targets = array_values(array_unique(array_map(strval(...), $itemByCoord)));

        /** @var list<string> $live */
        $live = DB::table('content.items')
            ->where('user_id', $userId)
            ->whereIn('id', $targets)
            ->pluck('id')
            ->map(strval(...))
            ->all();

        $alive = array_flip($live);
        $missing = array_values(array_filter($targets, fn (string $id): bool => ! isset($alive[$id])));

        if ($missing === []) {
            // A 23503 that is NOT a missing item parent — a bad source_id, a bad asset_id, a
            // source row deleted underneath us. Not ours to recover: rethrowing keeps the real
            // violation visible instead of masking it behind a replay that would fail the same way.
            throw $cause;
        }

        $winnerFor = [];
        foreach ($missing as $dead) {
            $winnerFor[$dead] = $this->mergeSurvivorOf($userId, $dead, $cause);
        }

        // Two coords may now name ONE item (a dead X and a live Y both landing on Y). That is the
        // same-source-merge shape writeFacets() is already built for — it groups per item and
        // folds per column, so one upsert payload never carries two rows with the same conflict
        // target. Pinned by ProjectionWriterBatchingTest's 'folds two records for one (item,
        // source) into a single row rather than raising 21000'.
        return array_map(fn (string $id): string => $winnerFor[$id] ?? $id, $itemByCoord);
    }

    /**
     * Walk content.item_merges from a deleted item to the live item that absorbed it.
     *
     * Iterative, not a single SELECT: mergeInto(kept: Y, discarded: X) leaves Y an ordinary item,
     * and nothing stops a later pass discarding Y into Z — a reader holding X must walk X -> Y -> Z.
     * bindGroup() calls mergeInto() twice per pass (once with the arguments swapped) and
     * ItemMerger::merge() writes the same ledger from the dashboard, so chains have three sources.
     *
     * Bounded and cycle-guarded. A cycle is not reachable through mergeInto() alone (a hard-deleted
     * uuid is never re-minted), but content.item_merges carries no FK and no uniqueness and a
     * repair script could write anything — the visited set costs one array and turns a hang into a
     * named exception.
     */
    private function mergeSurvivorOf(string $userId, string $deadItemId, QueryException $cause): string
    {
        $seen = [$deadItemId => true];
        $current = $deadItemId;

        for ($hop = 1; $hop <= self::MERGE_LINEAGE_MAX_HOPS; $hop++) {
            // user-scoped on both the ledger read and the liveness re-check below: item ids are
            // user-scoped, and this is what makes a cross-tenant retarget impossible even if a
            // repair script wrote a bogus row. Newest first because the ledger has no uniqueness.
            //
            // Served by idx_item_merges_user_discarded (user_id, discarded_item_id, merged_at, id)
            // — 20260831120000_item_merges_discarded_idx.sql — instead of the bigserial PK seq scan.
            $kept = DB::table('content.item_merges')
                ->where('user_id', $userId)
                ->where('discarded_item_id', $current)
                ->orderByDesc('merged_at')
                ->orderByDesc('id')
                ->value('kept_item_id');

            if ($kept === null) {
                throw new FacetTargetLineageLostException($userId, $deadItemId, 'no content.item_merges row explains it', $cause);
            }

            $kept = (string) $kept;

            if (isset($seen[$kept])) {
                throw new FacetTargetLineageLostException($userId, $deadItemId, "the merge lineage cycles at {$kept}", $cause);
            }
            $seen[$kept] = true;

            if (DB::table('content.items')->where('user_id', $userId)->where('id', $kept)->exists()) {
                return $kept;
            }

            $current = $kept;
        }

        throw new FacetTargetLineageLostException(
            $userId,
            $deadItemId,
            'the merge lineage exceeded '.self::MERGE_LINEAGE_MAX_HOPS.' hops',
            $cause,
        );
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
                // The COORD travels with its projection now: replaceCollections()
                // stamps each collection row with the source item that
                // contributed it, and a projection stripped of its coord cannot
                // be attributed. Both consumers below read the tuple.
                $byItem[$itemId][] = ['coord' => (string) $coord, 'projection' => $projection];
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
            foreach ($group as $entry) {
                // Singleton facets have no origin column — their PK is
                // (item_id, source_id), so only one row can exist per source
                // and there is no per-coord set to keep apart. Unwrap and
                // carry on exactly as before.
                $projection = $entry['projection'];
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
     * ORIGIN SCOPING (spec 2026-08-26 §5.1). Every collection row is stamped
     * with the source item that contributed it, and the DELETE is narrowed to
     * the origins this write actually covers. Without it the delete could only
     * scope to (item_id, source_id) — and there is exactly ONE manual
     * content.sources row per user, so two hand-added items bound to one item
     * are indistinguishable and each save wiped the other's rows.
     *
     * @param  array<string, list<array{coord: string, projection: array<string, mixed>}>>  $byItem  item id => that item's (coord, projection) pairs
     */
    private function replaceCollections(string $contentSourceId, string $userId, array $byItem): void
    {
        if ($byItem === []) {
            return;
        }

        $chunk = $this->writeChunk();

        // The manual source carries the live data-loss bug and no comparable
        // traffic, so it is scoped unconditionally. The connector lane waits
        // for the flag: there, a cascade is only a cache invalidation, because
        // the next reprojection re-derives its facets from
        // ingest.record_versions.
        $isManualSource = DB::table('content.sources')->where('id', $contentSourceId)->value('kind') === 'manual';
        $originScoped = $isManualSource || (bool) config('partna.content.facet_origin_scope');

        // One indexed read for the whole batch: content.source_items is UNIQUE
        // on (source_id, coord), so this is exact.
        $coords = [];
        foreach ($byItem as $entries) {
            foreach ($entries as $entry) {
                $coords[$entry['coord']] = true;
            }
        }
        $originByCoord = $coords === [] ? collect() : DB::table('content.source_items')
            ->where('source_id', $contentSourceId)
            ->whereIn('coord', array_keys($coords))
            ->pluck('id', 'coord');

        // The origins this write covers. A coord with no source_items row
        // (unreachable — the caller upserts it first) contributes nothing here
        // and its rows are stamped null, which the IS NULL half still replaces.
        $originIds = array_values(array_filter($originByCoord->all()));

        // The only rows worth PROTECTING: those contributed by a coord of this
        // source that is still LIVE on one of these items and that this write
        // does not cover. Everything else the delete must still reclaim.
        //
        // Expressed as "protect these" rather than "delete these" deliberately.
        // Scoping the delete to the covered origins alone would strand two
        // classes of row forever, because neither matches `IS NULL` nor
        // `IN (covered)`:
        //   - a RETIRED origin. retireAbsentSourceItems() soft-retires a coord
        //     whose upstream record was tombstoned, and its facet rows outlive
        //     it. The unscoped delete used to sweep them; PoolResolver reads
        //     these tables by item_id with no source-item liveness filter, so
        //     the photo/price/tag would render forever.
        //   - an origin belonging to ANOTHER source, which the fold can no
        //     longer create but older rows may carry.
        // Both are reclaimed here, so this predicate is self-healing.
        $preserveIds = [];
        if ($originScoped) {
            $covered = array_flip(array_map(strval(...), $originIds));
            $preserveIds = DB::table('content.source_items')
                ->where('source_id', $contentSourceId)
                ->whereIn('item_id', array_keys($byItem))
                ->whereNull('removed_at')
                ->pluck('id')
                ->map(strval(...))
                ->reject(fn (string $id) => isset($covered[$id]))
                ->values()
                ->all();
        }

        // Per-item flatten, preserving the old array_merge order. Positions
        // are assigned from these list indices below, so they stay 0..n-1
        // WITHIN an item — a counter running across the batch would silently
        // reorder every gallery after the first item.
        $mediaByItem = [];
        $offersByItem = [];
        $tagsByItem = [];
        $variantsByItem = [];
        $collectionsByItem = [];
        foreach ($byItem as $itemId => $entries) {
            $media = [];
            $offers = [];
            $tags = [];
            $variants = [];
            $collections = [];
            foreach ($entries as $entry) {
                $origin = $originByCoord[$entry['coord']] ?? null;
                $projection = $entry['projection'];
                $tag = fn (array $rows): array => array_map(
                    fn ($row) => ['row' => $row, 'origin' => $origin],
                    array_values($rows),
                );
                $media = array_merge($media, $tag((array) ($projection['media'] ?? [])));
                $offers = array_merge($offers, $tag((array) ($projection['offers'] ?? [])));
                $tags = array_merge($tags, $tag((array) ($projection['tags'] ?? [])));
                $variants = array_merge($variants, $tag((array) ($projection['variants'] ?? [])));
                // collection_items has no origin column — membership is
                // per-item by design (PK is (collection_id, item_id)).
                $collections = array_merge($collections, array_values((array) ($projection['collections'] ?? [])));
            }
            $mediaByItem[(string) $itemId] = $media;
            $offersByItem[(string) $itemId] = $offers;
            $tagsByItem[(string) $itemId] = $tags;
            $variantsByItem[(string) $itemId] = $variants;
            $collectionsByItem[(string) $itemId] = $collections;
        }

        // Unwrapped: resolveMediaAssets() fingerprints the media ENTRY, and the
        // origin tuple above is a wrapper this side of the call only. Handing
        // it the wrapper would fingerprint an array with no 'url' — no asset
        // minted, no mirror dispatched, and every item_media row left with a
        // null asset_id. Silent, so it is unwrapped here rather than there.
        $assetIdByFingerprint = $this->resolveMediaAssets($userId, array_map(
            fn (array $entries): array => array_column($entries, 'row'),
            $mediaByItem,
        ), $chunk);

        $mediaRows = [];
        foreach ($mediaByItem as $itemId => $entries) {
            foreach ($entries as $position => $wrapped) {
                $entry = (array) $wrapped['row'];
                [$fingerprint] = $this->mediaFingerprint($entry);
                $mediaRows[$itemId][] = [
                    'id' => (string) Str::uuid(),
                    'item_id' => $itemId,
                    'source_id' => $contentSourceId,
                    'source_item_id' => $wrapped['origin'],
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
            foreach ($entries as $wrapped) {
                $offer = (array) $wrapped['row'];
                $offerRows[$itemId][] = [
                    'id' => (string) Str::uuid(),
                    'item_id' => $itemId,
                    'source_id' => $contentSourceId,
                    'source_item_id' => $wrapped['origin'],
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
                    // Per-item ordering identity (2026-08-26): platform slug,
                    // the dish's own deep link on that platform, and the
                    // platform's item id. Set only by MenuProjectionMapper's
                    // per-platform offers; every other projector writes null
                    // (?? null discipline, additive as with availability).
                    'platform' => $offer['platform'] ?? null,
                    'item_url' => SecretParams::minimiseUrl($offer['item_url'] ?? null),
                    'external_ref' => $offer['external_ref'] ?? null,
                    'updated_at' => now(),
                ];
            }
        }

        $tagRows = [];
        foreach ($tagsByItem as $itemId => $entries) {
            foreach ($entries as $wrapped) {
                $tag = (array) $wrapped['row'];
                if (! isset($tag['tag']) || $tag['tag'] === '') {
                    continue;
                }
                $tagRows[$itemId][] = [
                    'id' => (string) Str::uuid(),
                    'item_id' => $itemId,
                    'source_id' => $contentSourceId,
                    'source_item_id' => $wrapped['origin'],
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
            foreach ($entries as $position => $wrapped) {
                $entry = (array) $wrapped['row'];
                $label = trim((string) ($entry['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $variantRows[$itemId][] = [
                    'id' => (string) Str::uuid(),
                    'item_id' => $itemId,
                    'source_id' => $contentSourceId,
                    'source_item_id' => $wrapped['origin'],
                    'label' => $label,
                    'sku' => $entry['sku'] ?? null,
                    // Additive, exactly like sku: a projection that omits the
                    // key writes null, so no existing projector changes
                    // behaviour (migration 20260813100003).
                    // #W1-DINT-5: same treatment as 'url'/'item_url' twenty
                    // lines up — the column was added later (20260813100003)
                    // and missed the denylist. minimiseUrl() redacts values in
                    // place, so a Shopify ?v=<epoch> cache-buster (every
                    // populated dev row) survives untouched.
                    'image_url' => SecretParams::minimiseUrl($entry['image_url'] ?? null),
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
            DB::transaction(function () use ($tables, $itemIds, $contentSourceId, $chunk, $chunkReseeds, $originScoped, $preserveIds) {
                foreach ($tables as $table => $rows) {
                    $delete = DB::table("content.{$table}")
                        ->whereIn('item_id', $itemIds)
                        ->where('source_id', $contentSourceId);

                    // collection_items has no origin column, and the manual
                    // source is scoped unconditionally; the connector lane
                    // waits for the flag. Flag off MUST produce the original
                    // statement unchanged — the identity-scope work learned
                    // that the hard way when a rewritten query shape broke
                    // PG-lane tests that hook statement text through DB::listen.
                    //
                    // Nothing to protect also means no predicate at all, which
                    // keeps the single-coord steady state byte-for-byte.
                    if ($table !== 'collection_items' && $originScoped && $preserveIds !== []) {
                        $delete->where(function ($q) use ($preserveIds) {
                            // The IS NULL half is LOAD-BEARING, not redundant.
                            // `NULL NOT IN (…)` is NULL, not true, so without
                            // it un-attributed rows would never be deleted by
                            // anything and would survive forever as orphans
                            // nothing replaces — turning a data-loss bug into a
                            // data-duplication one. NULL must keep meaning
                            // "unscoped, replaced exactly as today".
                            $q->whereNull('source_item_id')
                                ->orWhereNotIn('source_item_id', $preserveIds);
                        });
                    }

                    $delete->delete();

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
        // Item 9f (2026-09-01): videos dispatch FIRST. An unmirrored image
        // still renders from source_url; an unmirrored video renders NOT AT
        // ALL (PoolResolver's video gate) — so in a build wave the video
        // bytes are the ones a visitor is actually waiting on, and they are
        // also the ones racing signed-URL expiry hardest. Two buckets merged
        // video-first below; within a bucket, projection order is preserved.
        $videoCandidates = [];
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
            if ((string) ($entry['role'] ?? '') === 'video') {
                $videoCandidates[(string) $assetId] = $rawUrl;
            } else {
                $candidates[(string) $assetId] = $rawUrl;
            }
        }
        // Union, not merge: keys are asset ids and must not renumber; a
        // fingerprint can only land in one bucket, so no key collides.
        $candidates = $videoCandidates + $candidates;

        $this->healMirrorEligible($ownedAssetIds, $borrowedAssetIds, $chunk);

        $dispatched = 0;
        $max = MediaMirror::maxAttempts();

        foreach (array_chunk($candidates, $chunk, true) as $slice) {
            // Reading the discriminating columns rather than filtering them
            // away in SQL: the same query then answers "which of these needs a
            // mirror" AND "why not" for the rest, at no extra round-trip.
            $rows = DB::table('content.media_assets')
                ->whereIn('id', array_keys($slice))
                ->get(['id', 'storage_path', 'site_media_id', 'source_url', 'mirror_attempts'])
                ->keyBy('id');

            // Iterate the SLICE, not the DB result: whereIn() returns rows in
            // storage order, which silently discards the video-first ordering
            // (Item 9f) the candidate map was built to carry.
            foreach (array_keys($slice) as $assetId) {
                $row = $rows->get($assetId);
                if ($row === null) {
                    continue;
                }
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

        // Zero-dispatch passes are throttled, not silenced (Wave 3 triage,
        // 2026-09-01): steady state re-projects the same already-mirrored /
        // not_owned skips on EVERY document rebuild, so the unthrottled line
        // was one flood per rebuild saying nothing new — while R8 still needs
        // the capped/unresolved tail to stay explainable, so it is once per
        // user per hour rather than gone. Cache::add is an atomic SETNX
        // (idiom: LinkProjector::reportMalformedPattern). A dispatching pass
        // always logs — that line is new information each time.
        if ($dispatched === 0) {
            try {
                if (! Cache::add("media_mirror:zero-dispatch:{$userId}", 1, 3600)) {
                    return;
                }
            } catch (Throwable) {
                // Observability must never become the fault — a cache outage
                // costs the throttle, never the line or the projection.
            }
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
     * One of Resolver step 5's two caps (#SCALE-10/#CACHE-6). Same fallback
     * idiom as writeChunk(): a missing or nonsensical value degrades to the
     * documented default rather than being passed through, because a 0 here
     * would silently stop offering duplicate candidates altogether.
     */
    private function identityCap(string $key, int $default): int
    {
        $cap = (int) config('partna.ingest.'.$key, $default);

        return $cap > 0 ? $cap : $default;
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
            // declaration order is part of the cached facets_cache value
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

            // One read for the batch instead of one per item (#SCALE-9/#API-7).
            // ensureCurrent() is still the writer — it owns collision
            // suffixing, rename-back and retirement — it just no longer has to
            // ask whether there is anything to do.
            //
            // Only the slugged kinds ever enter the ensureCurrent() branch below,
            // so a batch of tracks/releases/products has nothing to look up. Before
            // the batched read this path issued ZERO slug queries for those kinds;
            // keep it that way. $rowsById is already built above, so the check
            // costs nothing.
            $needsSlugs = $rowsById->contains(
                fn (object $row) => in_array((string) $row->kind, ContentItemSlugAllocator::SLUGGED_KINDS, true)
            );

            $liveSlugs = $needsSlugs ? $this->slugs->currentSlugs($userId, $batch) : [];

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
                // slug already matches. The gate below skips ONLY that exact
                // live === base case using the batched read above — an item
                // legitimately holding a collision suffix (e.g. `-2`) does not
                // match, so ensureCurrent() is still called and returns early
                // via its own collision-suffix arm. Do not widen this into
                // reimplementing collisionSuffix()/canonicalSlug() out here:
                // ensureCurrent()'s docblock warns that re-minting on a
                // collided item walks -3, -4, … and changes the public URL
                // every run.
                if ($headline !== null && $headline !== ''
                    && in_array((string) $row->kind, ContentItemSlugAllocator::SLUGGED_KINDS, true)
                    && ($liveSlugs[$itemId] ?? null) !== $this->slugs->baseSlug((string) $headline, (string) $itemId)) {
                    try {
                        $this->slugs->ensureCurrent((string) $row->user_id, (string) $itemId, (string) $headline);
                    } catch (Throwable $e) {
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
     *
     * AMENDED (2026-09-02, setup-dialog run A.11, owner plan): lane 2 —
     * sites.updated_at — now rides here too. The setup dialog surfaces
     * scan/sweep writes moments after they land, and the public payload cache
     * keys off updated_at, so a batch that skipped it served stale for the
     * TTL whenever a caller forgot the request-boundary bust (the forgotten
     * lane the hub doc warns about). N timestamp writes per batch is the
     * accepted cost — it is one indexed UPDATE per row against the same
     * SQL round trip bumpSite already makes. Lane 3 (edge purge) stays at the
     * request boundary; N purges per batch remains wrong.
     */
    private function bumpSite(string $userId): void
    {
        // site.sites has no deleted_at — sites die by cascade, not soft delete.
        $siteId = DB::table('site.sites')->where('user_id', $userId)->value('id');
        if ($siteId !== null) {
            BuildState::bump((string) $siteId);
            DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()]);
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
