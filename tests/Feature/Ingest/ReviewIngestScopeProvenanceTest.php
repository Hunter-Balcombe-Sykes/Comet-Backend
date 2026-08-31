<?php

use App\Ingest\Landing\Lander;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Record;
use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Ingest\RunSourceJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// THE PROPERTY, not another call-site guard (2026-09-01, second pass).
//
// A review's scope is decided by what was true WHEN IT WAS INGESTED, and no
// later run under a different selection can rewrite it. Three fixes have now
// been written against this one sentence and the first two were both call-site
// guards — the person-scope gate stopped reading ingest.sources.selection_ref,
// then projectStream() gained a "did this run fetch?" flag — and the defect
// walked around both, because each guard was placed where the wrong answer was
// READ rather than where the wrong answer was WRITTEN. The auditor's verdict on
// the second: "moved the guard, did not make the defect impossible."
//
// So this file asserts no call site. It drives the real Lander and the real
// ProjectionWriter through arbitrary sequences of runs — each with its own
// vendor selection and its own arbitrary slice of the corpus returned — and
// after every single run checks the whole stream against an independent model:
//
//     a source item's ingest_selection_ref == the selection carried by the LAST
//     run whose FETCH RETURNED that record, and null when no run ever did.
//
// Nothing in that sentence mentions a boolean, a command, or a caller, so a
// future refactor that moves the guard somewhere else has to keep the property
// or fail here. The corpus deliberately includes records that no later run ever
// returns again: they are the salon's storewide reviews after the connection is
// narrowed to one stylist, and re-labelling them is the incident.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Bus::fake([RunSourceJob::class]);
    Queue::fake();
});

/**
 * The selections a Fresha connection can be carrying. null is a cleared picker
 * — a real state, and one whose stored form (null) collides with "never
 * fetched", so the model has to get it right rather than assert non-null.
 */
const RISP_SELECTIONS = ['storewide', '5035183', '7781902', null];

/** The stream's whole possible corpus. Runs return arbitrary subsets of it. */
const RISP_KEYS = ['r:1', 'r:2', 'r:3', 'r:4', 'r:5'];

/**
 * A Fresha reviews source + stream with nothing landed yet.
 *
 * @return array{string, string} [source id, stream id]
 */
function rispSource(): array
{
    $userId = (string) createTenant('risp-'.Str::lower(Str::random(6)))->id;
    $connectionId = poolConnection($userId, 'fresha.book');

    $sourceId = (string) Str::uuid();
    DB::table('ingest.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'connection_id' => $connectionId,
        'source_key' => 'fresha', 'surface_key' => 'fresha.book',
        'identifier' => 'vision-hair-studio-melbourne-tzo6gxk0',
        'selection_ref' => 'storewide', 'cost_units' => 1,
        'min_interval_secs' => 3600, 'max_interval_secs' => 604800,
        'auto_sync' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $sourceId, 'stream_name' => 'reviews',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$sourceId, $streamId];
}

/**
 * One whole run, exactly as RunExecutor performs it: put the source on
 * $selection, fetch $keys, land them under $runId, project the stream in the
 * same pass. $nonce varies the doc bodies so some re-fetches move the record's
 * current version and some do not — both are re-fetches and the property may
 * not distinguish them.
 *
 * @param  list<string>  $keys  what the vendor feed returned this time
 */
function rispRun(string $sourceId, string $streamId, string $runId, ?string $selection, array $keys, int $nonce): void
{
    DB::table('ingest.sources')->where('id', $sourceId)->update(['selection_ref' => $selection]);
    $source = (array) DB::table('ingest.sources')->where('id', $sourceId)->first();

    if ($keys !== []) {
        app(Lander::class)->land(
            streamId: $streamId,
            runId: $runId,
            // Sample: absence never concludes deletion, so the keys this run
            // did NOT return stay live and stay inside projectStream()'s sweep.
            // That is the situation the whole property is about — a corpus the
            // narrowed feed no longer mentions but the projector still walks.
            spec: new StreamSpec(name: 'reviews', target: 'review', profile: SourceProfile::Sample),
            records: array_map(static fn (string $key): Record => new Record('reviews', $key, [
                'rating' => 5,
                'text' => "Review {$key}, take {$nonce}.",
                'author' => 'A Guest',
                'publish_time' => '2026-08-01T10:00:00Z',
            ]), $keys),
            covered: null,
        );
    }

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'reviews', fetchedInRunId: $runId);
}

/**
 * What the writer actually stored for ONE stream, keyed by record key. Scoped
 * to the stream on purpose: each seed below is a different salon in the same
 * database, and an unscoped read would compare one salon's history against
 * another's model.
 *
 * @return array<string, ?string>
 */
function rispStored(string $streamId): array
{
    $stored = [];
    foreach (DB::table('content.source_items')->where('kind', 'review')->where('stream_id', $streamId)->get(['record_key', 'ingest_selection_ref']) as $row) {
        $stored[(string) $row->record_key] = $row->ingest_selection_ref === null ? null : (string) $row->ingest_selection_ref;
    }

    return $stored;
}

it('stores every review the scope of the run that last fetched it, over arbitrary run sequences', function () {
    // Fixed seeds, not a live random source: a property test that fails on
    // Tuesdays teaches nobody anything. Each seed is one independent history of
    // the same salon.
    foreach ([20260901, 4242, 7, 1337, 98765] as $seed) {
        mt_srand($seed);
        [$sourceId, $streamId] = rispSource();

        /** @var array<string, ?string> $model key => selection of the last run that returned it */
        $model = [];
        $previous = [];

        for ($runNo = 1; $runNo <= 8; $runNo++) {
            $runId = "run-{$seed}-{$runNo}";
            $selection = RISP_SELECTIONS[mt_rand(0, count(RISP_SELECTIONS) - 1)];

            // An arbitrary slice of the corpus, empty runs included — a run
            // that fetched and got nothing back is still a run that fetched.
            $returned = array_values(array_filter(RISP_KEYS, static fn (): bool => mt_rand(0, 1) === 1));

            rispRun($sourceId, $streamId, $runId, $selection, $returned, $runNo);

            foreach ($returned as $key) {
                $model[$key] = $selection;
            }

            $stored = rispStored($streamId);

            // 1. The property itself.
            expect($stored)->toEqual($model, "seed {$seed}, run {$runNo}: stored scopes diverged from the run that fetched each record");

            // 2. Stated the other way round, because equality alone would also
            //    hold if the writer stopped writing at all: nothing this run did
            //    NOT return may have moved. This is the assertion the first fix
            //    failed — the sweep restamped every live record in the stream.
            foreach ($previous as $key => $wasStored) {
                if (! in_array($key, $returned, true)) {
                    expect($stored[$key] ?? null)->toBe($wasStored, "seed {$seed}, run {$runNo}: record {$key} was not fetched by this run and its ingest scope changed anyway");
                }
            }

            $previous = $stored;
        }
    }
});

it('never lets a narrowed harvest claim a storewide review it did not re-fetch', function () {
    // The incident, stated as the property's worst case and driven to the end
    // of an entire year of runs. Five reviews land storewide; the connection is
    // then narrowed to one stylist forever, and every subsequent harvest returns
    // only that stylist's one review. The other four are never mentioned by the
    // vendor again — and are swept by every projection pass for the rest of the
    // source's life.
    [$sourceId, $streamId] = rispSource();

    rispRun($sourceId, $streamId, 'run-0', 'storewide', RISP_KEYS, 0);
    expect(rispStored($streamId))->toEqual(array_fill_keys(RISP_KEYS, 'storewide'));

    for ($runNo = 1; $runNo <= 52; $runNo++) {
        rispRun($sourceId, $streamId, "run-{$runNo}", '5035183', ['r:1'], $runNo);
    }

    expect(rispStored($streamId))->toEqual([
        'r:1' => '5035183',
        'r:2' => 'storewide',
        'r:3' => 'storewide',
        'r:4' => 'storewide',
        'r:5' => 'storewide',
    ]);
});
