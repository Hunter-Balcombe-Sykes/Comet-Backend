<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Media\MirrorMediaAssetJob;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Slice 1b D1/D8. Owned-class media gets its bytes mirrored after projection;
// borrowed media must never be, because storing a Google photo is a licence
// violation rather than merely wasted work.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();

    // QUEUE_CONNECTION is `sync` under phpunit.xml, so an unfaked dispatch runs
    // MediaMirror INLINE — which sends SafeUrlFetcher at a real CDN host, and
    // assertSafe() performs a genuine DNS lookup even under Http::fake(). Faked
    // for the whole file rather than per test: forgetting it in one case takes
    // the runner down with no output at all, not a red test.
    Bus::fake();
});

function projectIgMedia(string $userId, string $shortcode, array $images, string $coordSuffix = ''): void
{
    $media = [];
    foreach ($images as $i => $url) {
        $media[] = [
            'role' => $i === 0 ? 'cover' : 'gallery',
            'url' => $url,
            'ref' => "instagram:{$shortcode}:{$i}",
        ];
    }

    app(ProjectionWriter::class)->writeManualItem($userId, "manual:ig-{$shortcode}{$coordSuffix}", [
        'kind' => 'media',
        'headline' => null,
        'media' => $media,
    ]);
}

it('dispatches a mirror job for a newly minted instagram asset', function () {
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;

    projectIgMedia($userId, 'ABC123', ['https://scontent.cdninstagram.com/v/one.jpg']);

    Bus::assertDispatched(MirrorMediaAssetJob::class);
});

it('does not dispatch a mirror for borrowed google media', function () {
    // D1: Google photos are never stored. A mirror dispatch here would be a
    // terms violation, not just wasted work.
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:goog-1', [
        'kind' => 'media',
        'headline' => null,
        'media' => [[
            'role' => 'gallery',
            'ref' => 'places/ChIJtest/photos/AWCwydtoken',
            'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest',
        ]],
    ]);

    Bus::assertNotDispatched(MirrorMediaAssetJob::class);
});

it('does not dispatch for an upload — it already owns its bytes', function () {
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:upl-1', [
        'kind' => 'media',
        'headline' => null,
        'media' => [[
            'role' => 'gallery',
            'site_media_id' => (string) Str::uuid(),
            'width' => 100, 'height' => 100, 'mime_type' => 'image/webp',
        ]],
    ]);

    Bus::assertNotDispatched(MirrorMediaAssetJob::class);
});

it('does not dispatch again for an asset that already has storage_path', function () {
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;

    projectIgMedia($userId, 'ABC123', ['https://scontent.cdninstagram.com/v/one.jpg']);
    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 1);

    DB::table('content.media_assets')->where('user_id', $userId)
        ->update(['storage_path' => 'content-media/x/abc.webp']);

    projectIgMedia($userId, 'ABC123', ['https://scontent.cdninstagram.com/v/one.jpg'], '-again');

    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 1);
});

it('produces no duplicate assets across two consecutive syncs', function () {
    // The parent spec's headline proof, and the property that justifies D1's
    // split: instagram's ref is shortcode-stable, so a re-sync recognises the
    // asset it already minted. Google cannot satisfy this — its refs rotate.
    //
    // The differing oh= is deliberate: SecretParams::minimiseUrl() strips
    // _nc_sid but NOT oh/oe/_nc_ohc, so an Instagram url genuinely re-signs
    // between syncs. Before 1a's fingerprint inversion that changed identity;
    // this is what proves it no longer does.
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;

    projectIgMedia($userId, 'ABC123', ['https://scontent.cdninstagram.com/v/one.jpg?oh=sig1']);
    $first = DB::table('content.media_assets')->where('user_id', $userId)->count();

    projectIgMedia($userId, 'ABC123', ['https://scontent.cdninstagram.com/v/one.jpg?oh=sig2'], '-2');

    expect(DB::table('content.media_assets')->where('user_id', $userId)->count())->toBe($first)
        ->and($first)->toBe(1);
});

it('carries the unminimised url so the CDN signature survives to the fetch', function () {
    // source_url on the row is the minimised RECORD; the job needs a url the
    // CDN will still honour. Different purposes, deliberately different values.
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    $signed = 'https://scontent.cdninstagram.com/v/one.jpg?_nc_sid=abc123&oh=sig1';

    projectIgMedia($userId, 'ABC123', [$signed]);

    Bus::assertDispatched(MirrorMediaAssetJob::class, fn ($job) => $job->sourceUrl === $signed);
});

it('is unique per asset so a retried run cannot pile up mirrors', function () {
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    projectIgMedia($userId, 'ABC123', ['https://scontent.cdninstagram.com/v/one.jpg']);

    $assetId = (string) DB::table('content.media_assets')->where('user_id', $userId)->value('id');
    $job = new MirrorMediaAssetJob($userId, $assetId, 'https://scontent.cdninstagram.com/v/one.jpg');

    // afterCommit must be set on the INSTANCE, never redeclared as a typed
    // property: Queueable declares `public $afterCommit` untyped, and
    // redeclaring it as `public bool` is a fatal incompatible-composition
    // error at CLASS-LOAD time — which kills the runner with zero output
    // rather than failing a test, so it is worth pinning.
    expect($job->uniqueId())->toBe($assetId)
        ->and((new ReflectionProperty($job, 'afterCommit'))->hasType())->toBeFalse()
        ->and($job->afterCommit)->toBeTrue();
});

it('dispatches a mirror for a PRE-EXISTING unmirrored asset on a later sync (F14)', function () {
    // The legacy Instagram seeder mints the same fingerprints before the
    // ingest projection runs, so on the first projection every asset already
    // exists and `missing` is empty — the mirror pass used to be skipped
    // entirely (86 of 88 frames stayed on hotlinked CDN urls).
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg']);
    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 1);

    // Same fingerprints again, still unmirrored → dispatched again (unique lock
    // is the queue-level guard, not the writer's).
    // Release the ShouldBeUnique lock the first (faked, never-run) dispatch
    // still holds — in production the job's clean finish releases it.
    $assetId = (string) DB::table('content.media_assets')->where('user_id', $userId)->value('id');
    Cache::lock('laravel_unique_job:'.MirrorMediaAssetJob::class.':'.$assetId)->forceRelease();
    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg'], '-again');
    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 2);
});

// ── R8: the dispatch pass stops guessing and starts saying what it did ───────

it('stops dispatching once an asset has exhausted its mirror attempts', function () {
    // Before this, a permanently dead CDN link was re-fetched on every sync
    // forever — storage_path IS NULL was the only terminator, and it never
    // becomes non-null for a link that cannot be fetched.
    config()->set('partna.media_mirror_max_attempts', 3);
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg']);
    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 1);

    $assetId = (string) DB::table('content.media_assets')->where('user_id', $userId)->value('id');
    DB::table('content.media_assets')->where('id', $assetId)->update(['mirror_attempts' => 3]);
    Cache::lock('laravel_unique_job:'.MirrorMediaAssetJob::class.':'.$assetId)->forceRelease();

    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg'], '-again');

    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 1);
});

it('keeps dispatching while the asset is still under the attempt cap', function () {
    // The cap must bite at the boundary and not one attempt early, or a link
    // that recovers on its last try never gets it.
    config()->set('partna.media_mirror_max_attempts', 3);
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg']);

    $assetId = (string) DB::table('content.media_assets')->where('user_id', $userId)->value('id');
    DB::table('content.media_assets')->where('id', $assetId)->update(['mirror_attempts' => 2]);
    Cache::lock('laravel_unique_job:'.MirrorMediaAssetJob::class.':'.$assetId)->forceRelease();

    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg'], '-again');

    Bus::assertDispatchedTimes(MirrorMediaAssetJob::class, 2);
});

it('logs how many mirror candidates it dispatched', function () {
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    Log::spy();

    projectIgMedia($userId, 'ABC', [
        'https://scontent.cdninstagram.com/v/a.jpg',
        'https://scontent.cdninstagram.com/v/b.jpg',
    ]);

    // One post, two frames: the cover dispatches, the carousel frame is
    // budgeted out (partna.media.pull_budget, 2026-09-04) — and COUNTED.
    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'media_mirror.dispatch'
            && $context['dispatched'] === 1
            && ($context['skipped']['budget'] ?? 0) === 1)
        ->once();
});

it('names the reason a candidate was skipped rather than dropping it silently', function () {
    // The skip path logged NOTHING: a borrowed asset, an unresolved
    // fingerprint and an already-capped asset all left the same trace as an
    // asset that was never a candidate at all.
    config()->set('partna.media_mirror_max_attempts', 3);
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg']);

    $assetId = (string) DB::table('content.media_assets')->where('user_id', $userId)->value('id');
    DB::table('content.media_assets')->where('id', $assetId)->update(['mirror_attempts' => 3]);
    Log::spy();

    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg'], '-again');

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'media_mirror.dispatch'
            && $context['dispatched'] === 0
            && ($context['skipped']['capped'] ?? 0) === 1)
        ->once();
});

it('throttles the zero-dispatch aggregate line to once per user per window', function () {
    // Wave 3 triage (2026-09-01): steady state re-projects the same skips on
    // every document rebuild, so the unthrottled dispatched=0 line was one
    // flood per rebuild saying nothing new. Two zero-dispatch passes inside
    // one window emit ONE line; a dispatching pass still always logs (the
    // `logs how many mirror candidates it dispatched` case above).
    config()->set('partna.media_mirror_max_attempts', 3);
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg']);

    DB::table('content.media_assets')->where('user_id', $userId)->update(['mirror_attempts' => 3]);
    Log::spy();

    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg'], '-again');
    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg'], '-thrice');

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'media_mirror.dispatch'
            && $context['dispatched'] === 0)
        ->once();
});

it('reports a borrowed asset as skipped for not being owned', function () {
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    Log::spy();

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:goog-1', [
        'kind' => 'media',
        'headline' => null,
        'media' => [[
            'role' => 'gallery',
            'ref' => 'places/ChIJtest/photos/AWCwydtoken',
            'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest',
        ]],
    ]);

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'media_mirror.dispatch'
            && $context['dispatched'] === 0
            && ($context['skipped']['not_owned'] ?? 0) === 1)
        ->once();
});

// ── mirror_eligible: "should this row EVER have been mirrored?" ──────────────
//
// Measured on dev 2026-08-18: the tail query shipped with the R8 columns
// returned 2589 rows, of which ZERO were mirror candidates — the rest were
// Apple Music, Shopify, Uber Eats, SoundCloud and Google artwork that is
// correctly never mirrored. Owned-ness lived only in the projection entry's
// `ref`, and the row stores `'url-'.sha1($ref)`, a one-way hash. So the table
// could not answer the one question R8 existed to make answerable.

it('marks a newly minted instagram asset as mirror-eligible', function () {
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;

    projectIgMedia($userId, 'ABC123', ['https://scontent.cdninstagram.com/v/one.jpg']);

    expect((bool) DB::table('content.media_assets')->where('user_id', $userId)->value('mirror_eligible'))->toBeTrue();
});

it('marks borrowed google media as NOT mirror-eligible', function () {
    // The distinction that matters: this row is unmirrored FOREVER and that is
    // correct — storing a Places photo is a licence violation. It must never
    // read as a backlog item.
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:goog-1', [
        'kind' => 'media',
        'headline' => null,
        'media' => [[
            'role' => 'gallery',
            'ref' => 'places/ChIJtest/photos/AWCwydtoken',
            'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest',
        ]],
    ]);

    // not->toBeNull() FIRST: NULL casts to false, so a bare toBeFalse() would
    // pass against an unpopulated column and prove nothing.
    $row = DB::table('content.media_assets')->where('user_id', $userId)->first();
    expect($row->mirror_eligible)->not->toBeNull()
        ->and((bool) $row->mirror_eligible)->toBeFalse();
});

it('heals a pre-existing NULL flag on the next projection pass', function () {
    // Every row minted before this column exists reads NULL. Re-deriving the
    // flag on the next sync is what makes the backlog query converge on the
    // truth instead of permanently under-counting the rows that predate it.
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg']);

    DB::table('content.media_assets')->where('user_id', $userId)->update(['mirror_eligible' => null]);

    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg'], '-again');

    expect((bool) DB::table('content.media_assets')->where('user_id', $userId)->value('mirror_eligible'))->toBeTrue();
});

it('heals a pre-existing NULL flag on a borrowed asset too', function () {
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    $google = [
        'kind' => 'media',
        'headline' => null,
        'media' => [[
            'role' => 'gallery',
            'ref' => 'places/ChIJtest/photos/AWCwydtoken',
            'url' => 'https://lh3.googleusercontent.com/place-photos/AG9NLjtest',
        ]],
    ];
    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:goog-1', $google);

    DB::table('content.media_assets')->where('user_id', $userId)->update(['mirror_eligible' => null]);

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:goog-2', $google);

    $row = DB::table('content.media_assets')->where('user_id', $userId)->first();
    expect($row->mirror_eligible)->not->toBeNull()
        ->and((bool) $row->mirror_eligible)->toBeFalse();
});

it('leaves an already-set flag alone rather than rewriting it every sync', function () {
    // The heal is a one-time repair, not a per-sync write. Left unguarded it
    // would UPDATE every asset on every projection pass forever.
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg']);

    // A deliberately WRONG value: if the heal is unguarded it will correct
    // this back to true, and the test fails — which is exactly the write we
    // do not want happening on every sync.
    DB::table('content.media_assets')->where('user_id', $userId)->update(['mirror_eligible' => false]);

    projectIgMedia($userId, 'ABC', ['https://scontent.cdninstagram.com/v/a.jpg'], '-again');

    expect((bool) DB::table('content.media_assets')->where('user_id', $userId)->value('mirror_eligible'))->toBeFalse();
});

// Item 9f (2026-09-01): videos dispatch before images. An unmirrored image
// renders from source_url; an unmirrored video renders NOT AT ALL, and its
// signed URL is the one racing expiry — so the video bytes lead the wave.
/** The dispatch order of one projection pass, as source urls. */
function mirrorDispatchOrder(): array
{
    $order = [];
    Bus::assertDispatched(MirrorMediaAssetJob::class, function (MirrorMediaAssetJob $job) use (&$order) {
        $order[] = $job->sourceUrl;

        return true;
    });

    return $order;
}

function setSitePublished(string $userId, bool $published): void
{
    $updated = DB::table('site.sites')->where('user_id', $userId)->update(['is_published' => $published ? 1 : 0]);
    if ($updated === 0) {
        DB::table('site.sites')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'subdomain' => 'igm-'.Str::lower(Str::random(8)),
            'is_published' => $published ? 1 : 0,
        ]);
    }
}

/**
 * A real Instagram ingest source + its `media` stream, so a test can drive
 * the CONNECTOR projection path (projectStream) rather than one manual item
 * per call — the mirror budget is per projection pass.
 *
 * @return array{0: array<string, mixed>, 1: string} [source row, stream id]
 */
function instagramSourceForMirror(string $userId): array
{
    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'instagram',
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => ['username' => Str::lower(Str::random(8))],
        'is_active' => true,
    ]);

    $source = (array) DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    $streamId = (string) Str::uuid();
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'media',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$source, $streamId];
}

/**
 * Upsert one record per shortcode (doc keys: taken_at, images, video_url) and
 * project the stream once — ONE pass over every post, like a connector run.
 *
 * @param  array<string, array<string, mixed>>  $docsByShortcode
 */
function writeInstagramRecords(array $source, string $streamId, array $docsByShortcode): void
{
    foreach ($docsByShortcode as $shortcode => $doc) {
        $doc += ['shortcode' => $shortcode, 'url' => "https://www.instagram.com/p/{$shortcode}/"];
        $exists = DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $shortcode)->exists();
        if (! $exists) {
            DB::table('ingest.record_versions')->insert([
                'stream_id' => $streamId, 'key' => $shortcode, 'doc_hash' => sha1(json_encode($doc)),
                'doc' => json_encode($doc), 'first_seen_at' => now(), 'is_current' => 1,
            ]);
            $versionId = DB::table('ingest.record_versions')->where('stream_id', $streamId)->where('key', $shortcode)->value('id');
            DB::table('ingest.record_state')->insert([
                'stream_id' => $streamId, 'key' => $shortcode, 'current_version_id' => $versionId, 'last_seen_at' => now(),
            ]);
        }
    }

    app(ProjectionWriter::class)->projectStream($source, $streamId, 'media');
}

it('mirrors one asset per post — the video plus its poster for a reel, the cover for a carousel — and budgets the rest out', function () {
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    setSitePublished($userId, true);
    Log::spy();

    $media = [
        ['role' => 'cover', 'url' => 'https://cdn.example/a.jpg', 'ref' => 'instagram:ord1:0'],
        ['role' => 'gallery', 'url' => 'https://cdn.example/b.jpg', 'ref' => 'instagram:ord1:1'],
        ['role' => 'video', 'url' => 'https://cdn.example/c.mp4', 'ref' => 'instagram:ord1:2'],
        ['role' => 'gallery', 'url' => 'https://cdn.example/d.jpg', 'ref' => 'instagram:ord1:3'],
        ['role' => 'video', 'url' => 'https://cdn.example/e.mp4', 'ref' => 'instagram:ord1:4'],
    ];

    app(ProjectionWriter::class)->writeManualItem($userId, 'manual:ig-ord1', [
        'kind' => 'media',
        'headline' => 'ordering pass',
        'media' => $media,
    ]);

    // Published site: the video leads (Item 9f), its poster follows; every
    // other frame keeps its asset row but never costs a byte copy.
    expect(mirrorDispatchOrder())->toBe(['https://cdn.example/c.mp4', 'https://cdn.example/a.jpg']);
    expect(DB::table('content.media_assets')->where('user_id', $userId)->count())->toBe(5);
    Bus::assertDispatched(MirrorMediaAssetJob::class, fn (MirrorMediaAssetJob $job) => $job->video && str_ends_with($job->sourceUrl, 'c.mp4'));
    Bus::assertDispatched(MirrorMediaAssetJob::class, fn (MirrorMediaAssetJob $job) => ! $job->video && str_ends_with($job->sourceUrl, 'a.jpg'));
    Log::shouldHaveReceived('info')->withArgs(fn (string $msg, array $ctx) => $msg === 'media_mirror.dispatch'
        && $ctx['dispatched'] === 2 && ($ctx['skipped']['budget'] ?? null) === 3)->once();
});

it('applies the budget across the posts of ONE projection pass, newest first', function () {
    config(['partna.media.pull_budget.images' => 2, 'partna.media.pull_budget.videos' => 1]);
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    setSitePublished($userId, true);

    [$source, $streamId] = instagramSourceForMirror($userId);
    writeInstagramRecords($source, $streamId, [
        'old' => ['taken_at' => '2026-09-01T00:00:00Z', 'images' => ['https://cdn.example/old.jpg']],
        'new' => ['taken_at' => '2026-09-04T00:00:00Z', 'images' => ['https://cdn.example/new.jpg', 'https://cdn.example/new-2.jpg']],
        'mid' => ['taken_at' => '2026-09-03T00:00:00Z', 'images' => ['https://cdn.example/mid.jpg']],
        'r1' => ['taken_at' => '2026-09-02T00:00:00Z', 'images' => ['https://cdn.example/r1.jpg'], 'video_url' => 'https://cdn.example/r1.mp4'],
        'r2' => ['taken_at' => '2026-08-30T00:00:00Z', 'images' => ['https://cdn.example/r2.jpg'], 'video_url' => 'https://cdn.example/r2.mp4'],
    ]);

    // Published → videos first: the newest reel (r1), then the two newest
    // image posts (new, mid). `old`, `new-2` and reel r2 are budgeted out.
    //
    // r1's POSTER is budgeted out too, and that is the point of the
    // arithmetic: a poster spends an image slot like any other picture, so
    // two image budget slots buy exactly two pictures. `new` and `mid` are
    // both newer than r1, and newest-first is the tiebreak. Exempting the
    // poster would make this pull copy three images against a cap of two.
    expect(mirrorDispatchOrder())->toBe([
        'https://cdn.example/r1.mp4',
        'https://cdn.example/new.jpg',
        'https://cdn.example/mid.jpg',
    ]);
});

it('a poster spends an image slot, so a video post cannot exceed the image cap', function () {
    config(['partna.media.pull_budget.images' => 1, 'partna.media.pull_budget.videos' => 2]);
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    setSitePublished($userId, true);

    [$source, $streamId] = instagramSourceForMirror($userId);
    writeInstagramRecords($source, $streamId, [
        'r1' => ['taken_at' => '2026-09-04T00:00:00Z', 'images' => ['https://cdn.example/p1.jpg'], 'video_url' => 'https://cdn.example/v1.mp4'],
        'r2' => ['taken_at' => '2026-09-03T00:00:00Z', 'images' => ['https://cdn.example/p2.jpg'], 'video_url' => 'https://cdn.example/v2.mp4'],
    ]);

    // Two video slots, one image slot: both videos mirror, and only the
    // NEWER post's poster does. The second reel keeps its asset row and
    // renders from source until a later pass has budget for it.
    expect(mirrorDispatchOrder())->toBe([
        'https://cdn.example/v1.mp4',
        'https://cdn.example/v2.mp4',
        'https://cdn.example/p1.jpg',
    ]);
});

it('dispatches images newest-first ahead of videos while the site is still in setup', function () {
    config(['partna.media.pull_budget.images' => 10, 'partna.media.pull_budget.videos' => 6]);
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    setSitePublished($userId, false);

    [$source, $streamId] = instagramSourceForMirror($userId);
    writeInstagramRecords($source, $streamId, [
        'old' => ['taken_at' => '2026-09-01T00:00:00Z', 'images' => ['https://cdn.example/old.jpg']],
        'r1' => ['taken_at' => '2026-09-02T00:00:00Z', 'images' => ['https://cdn.example/r1.jpg'], 'video_url' => 'https://cdn.example/r1.mp4'],
        'new' => ['taken_at' => '2026-09-04T00:00:00Z', 'images' => ['https://cdn.example/new.jpg']],
    ]);

    expect(mirrorDispatchOrder())->toBe([
        'https://cdn.example/new.jpg',
        'https://cdn.example/r1.jpg',
        'https://cdn.example/old.jpg',
        'https://cdn.example/r1.mp4',
    ]);
});

it('counts an already-mirrored post against the window so a refresh cannot creep down the backlog', function () {
    config(['partna.media.pull_budget.images' => 1, 'partna.media.pull_budget.videos' => 1]);
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    setSitePublished($userId, true);

    [$source, $streamId] = instagramSourceForMirror($userId);
    $records = [
        'new' => ['taken_at' => '2026-09-04T00:00:00Z', 'images' => ['https://cdn.example/new.jpg']],
        'old' => ['taken_at' => '2026-09-01T00:00:00Z', 'images' => ['https://cdn.example/old.jpg']],
    ];
    writeInstagramRecords($source, $streamId, $records);
    expect(mirrorDispatchOrder())->toBe(['https://cdn.example/new.jpg']);

    // The newest post's bytes land; the refresh re-projects the same posts.
    DB::table('content.media_assets')->where('user_id', $userId)
        ->where('fingerprint', 'url-'.sha1('instagram:new:0'))
        ->update(['storage_path' => 'content-media/x/new.webp']);
    Bus::fake();
    writeInstagramRecords($source, $streamId, $records);

    Bus::assertNotDispatched(MirrorMediaAssetJob::class);
});

it('in setup, mirrors EVERY cover — a poster no longer needs its video to make the cut (2026-09-05)', function () {
    config(['partna.media.pull_budget.images' => 1, 'partna.media.pull_budget.videos' => 1]);
    $userId = createTenant('igm-'.Str::lower(Str::random(6)))->id;
    setSitePublished($userId, false);
    [$source, $streamId] = instagramSourceForMirror($userId);
    writeInstagramRecords($source, $streamId, [
        'r1' => ['taken_at' => '2026-09-04T00:00:00Z', 'images' => ['https://cdn.example/p1.jpg'], 'video_url' => 'https://cdn.example/v1.mp4'],
        'r2' => ['taken_at' => '2026-09-03T00:00:00Z', 'images' => ['https://cdn.example/p2.jpg'], 'video_url' => 'https://cdn.example/v2.mp4'],
        'still' => ['taken_at' => '2026-09-02T00:00:00Z', 'images' => ['https://cdn.example/s.jpg', 'https://cdn.example/s-2.jpg']],
    ]);
    // st_ali's walk: 30 TikTok cards, 10 image slots, 20 covers left on
    // signed URLs that had expired. In setup the cover of every post is
    // what the card shows, so the image cap is lifted; the video cap holds
    // (one video), and a second carousel frame is still budgeted out.
    expect(mirrorDispatchOrder())->toBe([
        'https://cdn.example/p1.jpg',
        'https://cdn.example/p2.jpg',
        'https://cdn.example/s.jpg',
        'https://cdn.example/v1.mp4',
    ]);
});
