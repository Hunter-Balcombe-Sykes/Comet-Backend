<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Media\MirrorMediaAssetJob;
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

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($message, $context) => $message === 'media_mirror.dispatch'
            && $context['dispatched'] === 2)
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
