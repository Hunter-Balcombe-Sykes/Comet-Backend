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
use App\Routing\SecretParams;
use App\Site\Documents\BuildState;
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
        'f_catalog' => ['release_type', 'track_number', 'disc_number', 'isrc', 'gtin', 'sku'],
        'f_place' => ['venue_name', 'address', 'locality', 'region', 'country_code', 'latitude', 'longitude'],
        'f_rated' => ['rating', 'rating_max', 'ratings_count'],
        'f_review' => ['author_name', 'author_photo_url', 'rating', 'text', 'reviewed_at'],
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

    public function __construct(
        private readonly Resolver $resolver,
        private readonly ValueResolver $values,
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

        foreach ($records as $record) {
            $doc = is_string($record->doc) ? (array) json_decode($record->doc, true) : (array) $record->doc;
            $projection = $projector->project(new RecordView($doc, (string) $record->key));

            if ($projection === null) {
                $projectsToNothing[] = (string) $record->key;

                continue;
            }

            $coord = "{$sourceKey}:{$accountRef}:{$record->key}";
            $sourceItemId = $this->upsertSourceItem(
                contentSourceId: $contentSourceId,
                coord: $coord,
                streamId: $streamId,
                recordKey: (string) $record->key,
                kind: (string) $projection['kind'],
                projectorVersion: $projector::version(),
            );
            $this->writeIdentityKeys($sourceItemId, $coord, $projection);

            $projections[$coord] = $projection;
        }

        $removed = $this->retireAbsentSourceItems($contentSourceId, $streamId, $projectsToNothing);

        $itemByCoord = [];
        if ($projections !== []) {
            $itemByCoord = $this->resolveItems($userId, $projector::kind());
            $this->writeFacets($contentSourceId, $projections, $itemByCoord);
            $this->refreshItemCaches($userId, array_values(array_unique(array_values($itemByCoord))));
        }

        if ($projections !== [] || $removed > 0) {
            $this->bumpSite($userId);
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
        string $streamId,
        string $recordKey,
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

        $id = (string) Str::uuid();
        DB::table('content.source_items')->insert([
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

        return $id;
    }

    /**
     * Replace-set of the evidence this record carries. PlatformObject is the
     * record's own coord (joining within the platform); a link URL doubles as
     * CanonicalUrl so the same thing pasted manually or synced from a second
     * platform can union (§5).
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

        $winner = $effective->first() ?? $this->createItem($userId, $kind);

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

        foreach ($effective->slice(1) as $loser) {
            $this->mergeInto($userId, keptItemId: $winner, discardedItemId: (string) $loser);
        }

        return $winner;
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

        $hasCuration = DB::table('site.section_items')->where('item_id', $discardedItemId)->exists()
            || DB::table('content.manual_overrides')->where('item_id', $discardedItemId)->exists();

        if (! $hasCuration) {
            DB::table('content.items')->where('id', $discardedItemId)->delete();
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
    private function writeFacets(string $contentSourceId, array $projections, array $itemByCoord): void
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

        foreach ($byItem as $itemId => $group) {
            $this->replaceCollections($itemId, $contentSourceId, $group);

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
     * Collection facets are a set per (item, source): replace wholesale.
     *
     * @param  list<array<string, mixed>>  $projections
     */
    private function replaceCollections(string $itemId, string $contentSourceId, array $projections): void
    {
        $media = [];
        $offers = [];
        $tags = [];
        foreach ($projections as $projection) {
            $media = array_merge($media, array_values((array) ($projection['media'] ?? [])));
            $offers = array_merge($offers, array_values((array) ($projection['offers'] ?? [])));
            $tags = array_merge($tags, array_values((array) ($projection['tags'] ?? [])));
        }

        DB::table('content.item_media')->where('item_id', $itemId)->where('source_id', $contentSourceId)->delete();
        foreach ($media as $position => $entry) {
            $assetId = $this->ensureMediaAsset($itemId, (array) $entry);
            DB::table('content.item_media')->insert([
                'id' => (string) Str::uuid(),
                'item_id' => $itemId,
                'source_id' => $contentSourceId,
                'asset_id' => $assetId,
                'role' => (string) (((array) $entry)['role'] ?? 'gallery'),
                'position' => $position,
                'alt_text' => ((array) $entry)['alt'] ?? null,
                'created_at' => now(),
            ]);
        }

        DB::table('content.offers')->where('item_id', $itemId)->where('source_id', $contentSourceId)->delete();
        foreach ($offers as $offer) {
            $offer = (array) $offer;
            DB::table('content.offers')->insert([
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
                'updated_at' => now(),
            ]);
        }

        DB::table('content.item_tags')->where('item_id', $itemId)->where('source_id', $contentSourceId)->delete();
        foreach ($tags as $tag) {
            $tag = (array) $tag;
            if (! isset($tag['tag']) || $tag['tag'] === '') {
                continue;
            }
            DB::table('content.item_tags')->insert([
                'id' => (string) Str::uuid(),
                'item_id' => $itemId,
                'source_id' => $contentSourceId,
                'tag' => (string) $tag['tag'],
                'tag_type' => $tag['tag_type'] ?? null,
            ]);
        }
    }

    /**
     * One asset per distinct image, fingerprinted by its URL (or the vendor's
     * stable ref when no fetchable URL exists — GBP photo resource names).
     *
     * @param  array<string, mixed>  $entry
     */
    private function ensureMediaAsset(string $itemId, array $entry): ?string
    {
        $userId = DB::table('content.items')->where('id', $itemId)->value('user_id');
        // #PRIV-5: minimise BEFORE the fingerprint is computed, so the
        // fingerprint and the stored source_url derive from the same string
        // — the fingerprint is the UNIQUE (user_id, fingerprint) dedupe key,
        // and a fingerprint computed from the raw URL while a minimised URL
        // is stored would let a re-run mint a second row for the same image.
        $url = isset($entry['url']) && is_string($entry['url']) && $entry['url'] !== ''
            ? SecretParams::minimiseUrl($entry['url'])
            : null;
        $url = ($url === '' ? null : $url); // minimiseUrl fails closed to ''
        $ref = isset($entry['ref']) && is_string($entry['ref']) && $entry['ref'] !== '' ? $entry['ref'] : null;

        $fingerprint = $url ?? $ref;
        if ($fingerprint === null || $userId === null) {
            return null;
        }
        $fingerprint = 'url-'.sha1($fingerprint);

        $existing = DB::table('content.media_assets')
            ->where('user_id', $userId)
            ->where('fingerprint', $fingerprint)
            ->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Str::uuid();
        DB::table('content.media_assets')->insert([
            'id' => $id,
            'user_id' => $userId,
            'fingerprint' => $fingerprint,
            'source_url' => $url,
            'width' => $entry['width'] ?? null,
            'height' => $entry['height'] ?? null,
            'dims_confidence' => isset($entry['width']) ? 'declared' : null,
            'created_at' => now(),
        ]);

        return $id;
    }

    /** Per-chunk batch size for refreshItemCaches()/resolveItems(). Bind lists stay far under Postgres' 65,535 limit at this size. */
    private const BATCH_SIZE = 500;

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
            foreach (['item_media', 'offers', 'item_tags', 'f_action'] as $collection) {
                $ids = DB::table("content.{$collection}")->whereIn('item_id', $batch)->distinct()->pluck('item_id');
                foreach ($ids as $id) {
                    $presentByItem[(string) $id][] = $collection;
                }
            }

            $rowsById = DB::table('content.items')->whereIn('id', $batch)
                ->get(['id', 'headline_cache', 'facets_cache'])->keyBy('id');

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
            }
        }
    }

    private function bumpSite(string $userId): void
    {
        // site.sites has no deleted_at — sites die by cascade, not soft delete.
        $siteId = DB::table('site.sites')->where('user_id', $userId)->value('id');
        if ($siteId !== null) {
            BuildState::bump((string) $siteId);
        }
    }
}
